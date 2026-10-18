<?php
$petPrefill = max(0, (int)($_GET['pet_id'] ?? 0));
$recordFocus = max(0, (int)($_GET['record_id'] ?? 0));
$prescriptionError = '';
$medication = [];
foreach (['medicine', 'dose', 'frequency', 'duration', 'instructions'] as $field) {
    $medication[$field] = trim((string)($_POST[$field] ?? ''));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $recordFocus = max(0, (int)($_POST['record_id'] ?? 0));
    if (!$recordFocus || $medication['medicine'] === '' || $medication['dose'] === '' || $medication['frequency'] === '' || $medication['duration'] === '') {
        $prescriptionError = 'Choose a consultation and complete the medicine, dose, frequency, and duration.';
    } else {
        $check = $conn->prepare("SELECT m.id,m.pet_id,p.owner_id,p.name FROM medical_records m JOIN pets p ON p.id=m.pet_id WHERE m.id=? AND p.verification_status='approved' AND TRIM(COALESCE(m.prescription,''))='' LIMIT 1");
        $check->bind_param('i', $recordFocus);
        $check->execute();
        $consultation = $check->get_result()->fetch_assoc();
        if (!$consultation) {
            $prescriptionError = 'This consultation is unavailable or already has a prescription. Choose another consultation.';
        } else {
            $prescription = 'Medicine: '.$medication['medicine']."\nDose: ".$medication['dose']."\nFrequency: ".$medication['frequency']."\nDuration: ".$medication['duration'];
            if ($medication['instructions'] !== '') $prescription .= "\nInstructions: ".$medication['instructions'];
            $save = $conn->prepare("UPDATE medical_records SET prescription=? WHERE id=? AND TRIM(COALESCE(prescription,''))=''");
            $save->bind_param('si', $prescription, $recordFocus);
            if ($save->execute() && $save->affected_rows === 1) {
                log_action($conn, 'Added prescription to consultation', 'medical_record', $recordFocus, 'Medication instructions added to existing consultation.');
                notify_user($conn, (int)$consultation['owner_id'], 'Prescription Added', 'Medication instructions were added for '.$consultation['name'].'.', 'record', null);
                flash('success', 'Prescription saved to the existing consultation.');
                redirect_to('vet/prescription.php?pet_id='.(int)$consultation['pet_id'].'#medicalRecord'.$recordFocus);
            }
            $prescriptionError = 'The prescription could not be saved. Refresh and check whether it has already been added.';
        }
    }
}
$prescriptionFormOpen = $recordFocus > 0 || $prescriptionError !== '';
$consultationOptions = $conn->query("SELECT m.id,m.visit_date,m.diagnosis,p.name,u.full_name owner_name FROM medical_records m JOIN pets p ON p.id=m.pet_id JOIN users u ON u.id=p.owner_id WHERE p.verification_status='approved' AND TRIM(COALESCE(m.prescription,''))='' ORDER BY m.visit_date DESC,m.id DESC")->fetch_all(MYSQLI_ASSOC);
