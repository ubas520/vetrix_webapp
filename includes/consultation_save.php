<?php
function consultation_query(mysqli $conn, string $sql, string $types, array $params): mysqli_stmt {
    $stmt=$conn->prepare($sql);
    if(!$stmt || !$stmt->bind_param($types,...$params) || !$stmt->execute()) throw new RuntimeException('Consultation database operation failed.');
    return $stmt;
}
function consultation_appointment(mysqli $conn, int $id, int $uid, bool $admin, bool $lock=false): array {
    $row=consultation_query($conn,"SELECT a.id,a.pet_id,a.status,a.assigned_vet_id,a.scheduled_date,a.requested_date,p.name pet_name,p.verification_status FROM appointments a JOIN pets p ON p.id=a.pet_id WHERE a.id=?".($lock?' FOR UPDATE':''),'i',[$id])->get_result()->fetch_assoc();
    if(!$row || (!$admin && $row['assigned_vet_id']!==null && (int)$row['assigned_vet_id']!==$uid)) throw new InvalidArgumentException('This appointment is unavailable or is assigned to another veterinarian.');
    if($row['status']!=='completed') throw new InvalidArgumentException('Mark the appointment completed before adding its consultation.');
    if($row['verification_status']!=='approved') throw new InvalidArgumentException('The appointment pet must be approved before adding a consultation.');
    return $row;
}
function save_consultation(mysqli $conn, array $input, int $uid, string $name, bool $admin): array {
    foreach(['pet_id','appointment_id','visit_date','diagnosis','treatment','notes','note_type'] as $key) if(isset($input[$key]) && !is_scalar($input[$key])) throw new InvalidArgumentException('Check the consultation fields and try again.');
    $pet=filter_var($input['pet_id']??null,FILTER_VALIDATE_INT);
    $appointment=filter_var($input['appointment_id']??0,FILTER_VALIDATE_INT);
    if(!$pet || $pet<1 || $appointment===false || $appointment<0) throw new InvalidArgumentException('Select a valid pet and appointment.');
    $date=trim((string)($input['visit_date']??''));
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed || $parsed->format('Y-m-d')!==$date || $date<'1000-01-01') throw new InvalidArgumentException('Enter a valid visit date.');
    $diagnosis=trim((string)($input['diagnosis']??''));
    $treatment=trim((string)($input['treatment']??''));
    $notes=trim((string)($input['notes']??''));
    $type=$input['note_type']??'neutral';
    if(!in_array($type,['neutral','positive','negative','follow_up'],true)) throw new InvalidArgumentException('Choose a valid note type.');
    $notes=medical_note_pack($notes,$type);
    foreach([$diagnosis,$treatment,$notes] as $text) if(strlen($text)>65535) throw new InvalidArgumentException('A consultation field is too long. Please shorten it.');
    if(!$conn->begin_transaction()) throw new RuntimeException('Could not start consultation save.');
    try {
        if($appointment){
            $linked=consultation_appointment($conn,$appointment,$uid,$admin,true);
            if((int)$linked['pet_id']!==$pet) throw new InvalidArgumentException('The selected pet does not match this appointment. Return to the appointment and open Add consultation again.');
        }
        $owner=consultation_query($conn,"SELECT owner_id,name FROM pets WHERE id=? AND verification_status='approved' FOR UPDATE",'i',[$pet])->get_result()->fetch_assoc();
        if(!$owner) throw new InvalidArgumentException('Select an approved pet before saving a consultation.');
        $stmt=consultation_query($conn,'INSERT INTO medical_records(pet_id,veterinarian,veterinarian_id,appointment_id,visit_date,diagnosis,treatment,prescription,notes) VALUES(?,?,?,?,?,?,?,?,?)','isiisssss',[$pet,$name,$uid,$appointment?:null,$date,$diagnosis,$treatment,'',$notes]);
        $id=(int)$stmt->insert_id;
        if(!$conn->commit()) throw new RuntimeException('Could not commit consultation save.');
        return ['id'=>$id,'pet_id'=>$pet,'owner_id'=>(int)$owner['owner_id'],'pet_name'=>$owner['name']];
    }catch(Throwable $error){$conn->rollback();throw $error;}
}
