<?php
function ensure_vaccination_completion_schema(mysqli $conn): void {
    foreach (['completed_by_record_id'=>'INT NULL','recorded_by_id'=>'INT NULL','confirmation_notification_id'=>'INT NULL'] as $column=>$definition) {
        $result=$conn->query("SHOW COLUMNS FROM vaccinations LIKE '$column'");
        if (!$result) throw new RuntimeException('Could not inspect vaccination schema.');
        if (!$result->num_rows && !$conn->query("ALTER TABLE vaccinations ADD COLUMN $column $definition")) throw new RuntimeException('Could not update vaccination schema.');
    }
}
function save_vaccination(mysqli $conn, array $input, int $actor): int {
    foreach (['pet_id','vaccine_name','date_given','next_due_date','remarks','previous_vaccination_id','administered_by_id'] as $key) {
        if (isset($input[$key]) && !is_scalar($input[$key])) throw new InvalidArgumentException('Check the vaccination fields and try again.');
    }
    $pet = filter_var($input['pet_id'] ?? null, FILTER_VALIDATE_INT);
    $name = trim((string)($input['vaccine_name'] ?? ''));
    $remarks = trim((string)($input['remarks'] ?? ''));
    $previous=filter_var($input['previous_vaccination_id']??0,FILTER_VALIDATE_INT);
    $administered=filter_var($input['administered_by_id']??$actor,FILTER_VALIDATE_INT);
    if ($previous===false || $previous<0 || !$administered || $administered<1) throw new InvalidArgumentException('Select a valid vaccination and administering veterinarian.');
    if (!$pet || $pet < 1) throw new InvalidArgumentException('Select an approved pet.');
    if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Enter a vaccine name of up to 120 characters.');
    if (strlen($remarks) > 65535) throw new InvalidArgumentException('The remarks are too long. Please shorten them.');
    $dates = [];
    foreach (['date_given','next_due_date'] as $key) {
        $date = trim((string)($input[$key] ?? ''));
        $parsed = $date === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($date !== '' && (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '1000-01-01')) throw new InvalidArgumentException('Enter valid vaccination dates.');
        $dates[$key] = $date === '' ? null : $date;
    }
    if ($dates['date_given'] && $dates['next_due_date'] && $dates['next_due_date'] <= $dates['date_given']) throw new InvalidArgumentException('Next due date must be after the date given.');
    $query = static function(string $sql, string $types, array $values) use ($conn): mysqli_stmt {
        $stmt = $conn->prepare($sql);
        if (!$stmt || !$stmt->bind_param($types, ...$values) || !$stmt->execute()) throw new RuntimeException('Vaccination database operation failed.');
        return $stmt;
    };
    if (!$conn->begin_transaction()) throw new RuntimeException('Could not begin vaccination save.');
    try {
        $user = $query("SELECT full_name FROM users WHERE id=? AND role IN ('admin','veterinarian') AND status='active' AND deleted_at IS NULL FOR UPDATE", 'i', [$actor])->get_result()->fetch_assoc();
        if (!$user) throw new InvalidArgumentException('Only an active veterinarian or administrator can add vaccinations.');
        $clinician=$query("SELECT full_name FROM users WHERE id=? AND role IN ('veterinarian','admin') AND status='active' AND deleted_at IS NULL",'i',[$administered])->get_result()->fetch_assoc();
        if (!$clinician) throw new InvalidArgumentException('Select an active administering clinician.');
        $prior=null;
        if ($previous) {
            $prior=$query('SELECT * FROM vaccinations WHERE id=? FOR UPDATE','i',[$previous])->get_result()->fetch_assoc();
            if (!$prior || (int)$prior['pet_id']!==$pet || $prior['vaccine_name']!==$name) throw new InvalidArgumentException('The follow-up must match the original pet and vaccine.');
            if ($prior['completed_by_record_id']) throw new InvalidArgumentException('This follow-up has already been completed. No new notification was sent.');
            if (!$prior['next_due_date']) throw new InvalidArgumentException('This record has no outstanding follow-up.');
        }
        if ($previous || isset($input['administered_by_id'])) {
            $today=$conn->query('SELECT CURDATE() today')->fetch_assoc()['today'];
            if (!$dates['date_given'] || $dates['date_given']>$today || ($prior && $prior['date_given'] && $dates['date_given']<$prior['date_given'])) throw new InvalidArgumentException('Enter the actual date given, not a future date or a date before the previous vaccination.');
        }
        $patient = $query("SELECT id,name,owner_id FROM pets WHERE id=? AND verification_status='approved' FOR UPDATE", 'i', [$pet])->get_result()->fetch_assoc();
        if (!$patient) throw new InvalidArgumentException('Select an approved pet.');
        $stmt = $query('INSERT INTO vaccinations(pet_id,vaccine_name,date_given,next_due_date,administered_by,administered_by_id,remarks,recorded_by_id) VALUES(?,?,?,?,?,?,?,?)', 'issssisi', [$pet,$name,$dates['date_given'],$dates['next_due_date'],$clinician['full_name'],$administered,$remarks,$actor]);
        $id = (int)$stmt->insert_id;
        $message = $patient['name']."'s vaccination record has been saved: ".$name.'.';
        $message .= $dates['date_given'] ? ' Date given: '.(new DateTimeImmutable($dates['date_given']))->format('F j, Y').'.' : ' Date given has not been recorded.';
        $message .= $dates['next_due_date'] ? ' Next dose or follow-up due: '.(new DateTimeImmutable($dates['next_due_date']))->format('F j, Y').'.' : ' No next due date has been scheduled.';
        // Save the inbox confirmation in the same transaction; do not queue SMS.
        $notificationTitle=($previous || isset($input['administered_by_id']))?'Vaccination completed':'Vaccination recorded';
        $notification=$query("INSERT INTO notifications(user_id,title,message,type,status) VALUES(?,?,?,'vaccine','unread')", 'iss', [(int)$patient['owner_id'],$notificationTitle,$message]);
        $query('UPDATE vaccinations SET confirmation_notification_id=? WHERE id=?','ii',[(int)$notification->insert_id,$id]);
        if ($previous) $query('UPDATE vaccinations SET completed_by_record_id=? WHERE id=?','ii',[$id,$previous]);
        if (!$conn->commit()) throw new RuntimeException('Could not commit vaccination save.');
        return $id;
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
