<?php
require_once __DIR__.'/consultation_save.php';
require_once __DIR__.'/appointment_return.php';
$consultationListReturn=clinical_appointment_return($_GET['return_to']??'');
$uid=(int)current_user_id();
$isAdmin=($_SESSION['role']??'')==='admin';
$vetName=(string)($_SESSION['full_name']??'Veterinarian');
$appointmentValue=filter_var($_GET['appointment_id']??0,FILTER_VALIDATE_INT);
$appointmentFocus=$appointmentValue!==false && $appointmentValue>0?$appointmentValue:0;
$petPrefill=max(0,(int)($_GET['pet_id']??0));
$consultationError='';
$consultationBlocked=false;
$consultationValues=['visit_date'=>date('Y-m-d'),'diagnosis'=>'','treatment'=>'','notes'=>'','note_type'=>'neutral'];
$linkedAppointment=null;
if(isset($_GET['appointment_id']) && ($appointmentValue===false || $appointmentValue<1)){
    $consultationBlocked=true;
    $consultationError='The appointment link is invalid. Open the consultation from the appointment again.';
}
if($appointmentFocus){
    try{
        $linkedAppointment=consultation_appointment($conn,$appointmentFocus,$uid,$isAdmin);
        if($petPrefill && $petPrefill!==(int)$linkedAppointment['pet_id']) throw new InvalidArgumentException('The pet in this link does not match the appointment. Open the consultation from the appointment again.');
        $petPrefill=(int)$linkedAppointment['pet_id'];
        $consultationValues['visit_date']=substr($linkedAppointment['scheduled_date']?:$linkedAppointment['requested_date'],0,10);
    }catch(Throwable $error){
        $consultationBlocked=true;
        $consultationError=$error instanceof InvalidArgumentException?$error->getMessage():'The appointment could not be loaded. Please try again.';
    }
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf_or_fail();
    $saved=null;
    foreach($consultationValues as $key=>$value) $consultationValues[$key]=is_scalar($_POST[$key]??'')?(string)($_POST[$key]??''):'';
    if(!$appointmentFocus) $petPrefill=max(0,(int)filter_var($_POST['pet_id']??0,FILTER_VALIDATE_INT));
    try{
        if($consultationBlocked) throw new InvalidArgumentException($consultationError);
        if(filter_var($_POST['appointment_id']??0,FILTER_VALIDATE_INT)!==$appointmentFocus) throw new InvalidArgumentException('The appointment link changed. Reopen this consultation from the appointment.');
        $saved=save_consultation($conn,$_POST,$uid,$vetName,$isAdmin);
    }catch(Throwable $error){
        $consultationError=$error instanceof InvalidArgumentException?$error->getMessage():'The consultation could not be saved. Your entries are still here; please try again.';
        if(!($error instanceof InvalidArgumentException)) error_log('Vetrix consultation save failed: '.$error->getMessage());
    }
    if(isset($saved)){
        $message='Consultation saved.';
        try{if(!notify_user($conn,$saved['owner_id'],'Medical Record Added','A new medical record was added for '.$saved['pet_name'].'.','record',null)) $message.=' The client notification could not be confirmed.';}
        catch(Throwable $error){$message.=' The client notification could not be confirmed.';error_log('Vetrix consultation notification failed: '.$error->getMessage());}
        try{log_action($conn,'Added consultation','medical_record',$saved['id'],'Consultation entered by '.$vetName.'.');}
        catch(Throwable $error){error_log('Vetrix consultation audit failed: '.$error->getMessage());}
        flash('success',$message);
        redirect_to('vet/medical_records.php?pet_id='.$saved['pet_id'].($consultationListReturn!==''?'&return_to='.rawurlencode($consultationListReturn):'').'#medicalRecord'.$saved['id']);
    }
}
