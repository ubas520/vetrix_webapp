<?php
declare(strict_types=1);

// Run in the Railway web service. Default: preview and roll back; --apply: save.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Shanghai');
require __DIR__ . '/../config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$counts = [];
function demo_query(mysqli $conn, string $sql, array $values = []): mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if ($values) { $stmt->bind_param(str_repeat('s', count($values)), ...$values); }
    $stmt->execute();
    return $stmt;
}
function demo_insert(mysqli $conn, string $table, array $data, array $key): int {
    global $counts;
    $where = implode(' AND ', array_map(fn($column) => "`$column` <=> ?", array_keys($key)));
    $row = demo_query($conn, "SELECT id FROM `$table` WHERE $where LIMIT 1", array_values($key))->get_result()->fetch_assoc();
    if ($row) { return (int) $row['id']; }
    $columns = '`' . implode('`,`', array_keys($data)) . '`';
    $placeholders = implode(',', array_fill(0, count($data), '?'));
    demo_query($conn, "INSERT INTO `$table` ($columns) VALUES ($placeholders)", array_values($data));
    $counts[$table] = ($counts[$table] ?? 0) + 1;
    return (int) $conn->insert_id;
}
$conn->begin_transaction();
try {
    $client = demo_query($conn, 'SELECT id,role,deleted_at FROM users WHERE email=? FOR UPDATE', ['testaccclient123@gmail.com'])->get_result()->fetch_assoc();
    if (!$client || $client['role'] !== 'client' || $client['deleted_at'] !== null) {
        throw new RuntimeException('Existing client test account not found. No accounts were created or changed.');
    }
    $owner = (int) $client['id'];
    demo_query($conn, "UPDATE users SET address=CASE WHEN address IS NULL OR TRIM(address)='' THEN '123 Demo Street, Sample City (fictional)' ELSE address END, emergency_contact_name=CASE WHEN emergency_contact_name IS NULL OR TRIM(emergency_contact_name)='' THEN 'Alex Santos (demo contact)' ELSE emergency_contact_name END WHERE id=?", [$owner]);
    $vet = demo_query($conn, "SELECT id,full_name FROM users WHERE email=? AND role='veterinarian' AND status='active' AND deleted_at IS NULL", ['testaccvet123@gmail.com'])->get_result()->fetch_assoc();
    $vetId = $vet ? (int) $vet['id'] : null;
    $vetName = $vet ? $vet['full_name'] : 'Demo veterinarian';
    $today = new DateTimeImmutable('today');
    $date = fn(string $offset) => $today->modify($offset)->format('Y-m-d');
    $pets = [];
    foreach ([
        ['Buddy','Dog','Golden Retriever','Male','-3 years','26.50','Golden','approved'],
        ['Luna','Cat','Domestic Shorthair','Female','-2 years','4.20','Gray and white','approved'],
        ['Mochi','Dog','Shih Tzu','Female','-8 months','3.80','Cream','pending'],
    ] as [$name,$species,$breed,$sex,$age,$weight,$color,$status]) {
        $marker = '[DEMO:client-v1:' . strtolower($name) . '] Fictional pet for audience testing.';
        $pets[$name] = demo_insert($conn, 'pets', [
            'owner_id'=>$owner,'name'=>$name,'species'=>$species,'breed'=>$breed,'sex'=>$sex,
            'birth_date'=>$date($age),'weight'=>$weight,'color'=>$color,
            'allergies'=>'None reported (sample data)','critical_notes'=>'Demo record only.',
            'notes'=>$marker,'verification_status'=>$status,'verification_notes'=>'Fictional sample for app demonstration.',
        ], ['owner_id'=>$owner,'notes'=>$marker]);
    }
    $appointments = [];
    foreach ([
        ['buddy-visit','Buddy','-14 days','10:00:00','completed','Wellness Check','Routine wellness examination.'],
        ['luna-visit','Luna','-7 days','14:00:00','completed','Wellness Check','Annual wellness review.'],
        ['buddy-followup','Buddy','+7 days','10:00:00','approved','Follow-up','Sample follow-up visit.'],
        ['mochi-request','Mochi','+10 days','11:00:00','pending','Consultation','First puppy consultation request.'],
    ] as [$key,$name,$offset,$time,$status,$service,$concern]) {
        $when = $date($offset) . ' ' . $time;
        $code = 'DEMO-' . strtoupper($key);
        $appointments[$key] = demo_insert($conn, 'appointments', [
            'owner_id'=>$owner,'pet_id'=>$pets[$name],'requested_date'=>$when,
            'scheduled_date'=>$status === 'pending' ? null : $when,
            'assigned_vet_id'=>$status === 'pending' ? null : $vetId,
            'confirmation_code'=>$code,'reason'=>$service . ' — ' . $concern,'status'=>$status,
            'admin_notes'=>'[DEMO] Fictional appointment; not a real clinic booking.',
        ], ['owner_id'=>$owner,'confirmation_code'=>$code]);
    }
    foreach ([['Buddy','buddy-visit','-14 days'],['Luna','luna-visit','-7 days']] as [$name,$key,$offset]) {
        $marker = '[DEMO:client-v1:' . $key . '] Fictional medical history for app demonstration.';
        demo_insert($conn, 'medical_records', [
            'pet_id'=>$pets[$name],'veterinarian'=>$vetName,'veterinarian_id'=>$vetId,
            'appointment_id'=>$appointments[$key],'visit_date'=>$date($offset),
            'symptoms'=>'No concerns reported at this sample wellness visit.',
            'diagnosis'=>'Routine wellness examination (demo).',
            'treatment'=>'General examination recorded for demonstration.',
            'prescription'=>'None; sample record only.','notes'=>$marker,
        ], ['pet_id'=>$pets[$name],'notes'=>$marker]);
        $marker = '[DEMO:client-v1:' . strtolower($name) . '-vaccine] Fictional entry; not proof of vaccination.';
        demo_insert($conn, 'vaccinations', [
            'pet_id'=>$pets[$name],'vaccine_name'=>'Rabies (demo)','date_given'=>$date('-11 months'),
            'next_due_date'=>$date('+1 month'),'administered_by'=>$vetName,'administered_by_id'=>$vetId,'remarks'=>$marker,
        ], ['pet_id'=>$pets[$name],'remarks'=>$marker]);
    }
    foreach ([
        ['[Demo] Welcome to your sample account','Explore Buddy, Luna, and Mochi, their appointments, and fictional health records.','system'],
        ['[Demo] Buddy follow-up scheduled','Sample follow-up: ' . $date('+7 days') . ' at 10:00 AM. This is not a real booking.','appointment'],
        ['[Demo] Vaccination history available','Buddy and Luna have fictional vaccination entries available for viewing.','vaccine'],
    ] as [$title,$message,$type]) {
        demo_insert($conn, 'notifications', ['user_id'=>$owner,'title'=>$title,'message'=>$message,'type'=>$type,'status'=>'unread'], ['user_id'=>$owner,'title'=>$title]);
    }
    // No email/SMS delivery hooks; no password/session changes or inventory updates.
    $apply = in_array('--apply', $argv, true);
    if ($apply) { $conn->commit(); } else { $conn->rollback(); }
    echo json_encode(['mode'=>$apply ? 'applied' : 'preview_rolled_back','client_id'=>$owner,'new_rows'=>$counts], JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $error) {
    $conn->rollback();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
