<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role('veterinarian');
require_once __DIR__.'/../includes/consultation_save.php';
require_once __DIR__.'/../includes/appointment_return.php';
$listReturn=clinical_appointment_return($_GET['return_to']??'');
$id = max(0, (int)($_GET['id'] ?? $_POST['appointment_id'] ?? 0));
try {
    $appointment=consultation_appointment($conn,$id,(int)current_user_id(),($_SESSION['role']??'')==='admin');
} catch(Throwable $error) {
    flash('error',$error instanceof InvalidArgumentException?$error->getMessage():'The appointment could not be loaded. Please try again.');
    redirect_to(($_SESSION['role']??'')==='admin'?'admin/appointments.php':'vet/medical_records.php');
}
redirect_to('vet/medical_records.php?action=add&appointment_id='.$id.'&pet_id='.(int)$appointment['pet_id'].($listReturn!==''?'&return_to='.rawurlencode($listReturn):''));
