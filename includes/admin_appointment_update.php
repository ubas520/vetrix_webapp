<?php
// Kept separate so validation can be tested without opening the clinic database.
function admin_appointment_values(array $input): array {
    $fields = ['status','assigned_vet_id','scheduled_date','duration_minutes','confirmation_code','admin_notes'];
    $values = [];
    foreach ($fields as $field) {
        if (isset($input[$field]) && !is_scalar($input[$field])) throw new InvalidArgumentException('Check the appointment fields and try again.');
        $values[$field] = trim((string)($input[$field] ?? ''));
    }
    if (!in_array($values['status'], ['pending','approved','completed','rejected','cancelled'], true)) throw new InvalidArgumentException('Choose a valid appointment status.');
    $vet = $values['assigned_vet_id'] === '' ? null : filter_var($values['assigned_vet_id'], FILTER_VALIDATE_INT);
    if ($vet !== null && (!$vet || $vet < 1)) throw new InvalidArgumentException('Choose an active veterinarian.');
    $duration = filter_var($values['duration_minutes'], FILTER_VALIDATE_INT);
    if ($duration === false || $duration < 15 || $duration > 240) throw new InvalidArgumentException('Duration must be between 15 and 240 minutes.');
    $schedule = null;
    if ($values['scheduled_date'] !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $values['scheduled_date']);
        if (!$date || $date->format('Y-m-d\TH:i') !== $values['scheduled_date']) throw new InvalidArgumentException('Enter a valid clinic date and time.');
        $schedule = $date->format('Y-m-d H:i:s');
    }
    if ($values['status'] === 'approved' && (!$vet || !$schedule)) throw new InvalidArgumentException('Approved appointments require a schedule and assigned veterinarian.');
    if (mb_strlen($values['confirmation_code']) > 40) throw new InvalidArgumentException('Confirmation codes must be 40 characters or fewer.');
    if (strlen($values['admin_notes']) > 65535) throw new InvalidArgumentException('The client notes are too long. Please shorten them.');
    return array_replace($values, ['assigned_vet_id'=>$vet, 'scheduled_date'=>$schedule, 'duration_minutes'=>$duration]);
}

function admin_appointment_version(array $row): string {
    $values = [];
    foreach (['status','scheduled_date','duration_minutes','assigned_vet_id','confirmation_code','admin_notes','updated_at'] as $field) $values[] = (string)($row[$field] ?? '');
    return hash('sha256', json_encode($values));
}

function admin_appointment_query(mysqli $conn, string $sql, string $types, array $params): mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if (!$stmt || !$stmt->bind_param($types, ...$params) || !$stmt->execute()) throw new RuntimeException('Appointment database operation failed.');
    return $stmt;
}

function admin_save_appointment(mysqli $conn, int $id, array $input): array {
    $values = admin_appointment_values($input);
    if (!$conn->begin_transaction()) throw new RuntimeException('Could not start appointment update.');
    try {
        // Serializes admin scheduling for this veterinarian. Other scheduling entry
        // points must adopt the same lock before cross-workflow concurrency is guaranteed.
        if ($values['assigned_vet_id'] !== null) {
            $vet = admin_appointment_query($conn, "SELECT id FROM users WHERE id=? AND role='veterinarian' AND status='active' AND deleted_at IS NULL FOR UPDATE", 'i', [$values['assigned_vet_id']])->get_result()->fetch_assoc();
            if (!$vet) throw new InvalidArgumentException('The selected veterinarian is no longer active. Choose another veterinarian.');
        }
        $current = admin_appointment_query($conn, 'SELECT * FROM appointments WHERE id=? FOR UPDATE', 'i', [$id])->get_result()->fetch_assoc();
        if (!$current) throw new InvalidArgumentException('Appointment not found.');
        if (!is_string($input['appointment_version'] ?? null) || !hash_equals(admin_appointment_version($current), $input['appointment_version'])) throw new InvalidArgumentException('This appointment changed since you opened it. Review the current details before saving again.');
        if ($values['confirmation_code'] === '') $values['confirmation_code'] = trim((string)$current['confirmation_code']);
        $changed = false;
        foreach ($values as $key=>$value) if ((string)$value !== (string)($current[$key] ?? '')) $changed = true;
        if (!$changed) {
            $conn->rollback();
            return ['changed'=>false];
        }
        if ($values['confirmation_code'] === '') $values['confirmation_code'] = 'APT-'.str_pad((string)$id, 5, '0', STR_PAD_LEFT);
        if ($values['status'] === 'approved') {
            $conflict = appointment_slot_conflict($conn, $id, $values['assigned_vet_id'], $values['scheduled_date'], $values['duration_minutes']);
            if ($conflict['conflict']) throw new InvalidArgumentException($conflict['message']);
        }
        admin_appointment_query($conn, 'UPDATE appointments SET status=?,scheduled_date=?,duration_minutes=?,assigned_vet_id=?,confirmation_code=?,admin_notes=?,updated_at=NOW() WHERE id=?', 'ssiissi', [$values['status'],$values['scheduled_date'],$values['duration_minutes'],$values['assigned_vet_id'],$values['confirmation_code'],$values['admin_notes'],$id]);
        if (!$conn->commit()) throw new RuntimeException('Could not commit appointment update.');
        return ['changed'=>true, 'owner_id'=>(int)$current['owner_id'], 'status'=>$values['status']];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
