<?php
// Each count and its detail list come from the same result set.
$dashboardAppointmentTaskDefinitions = [
    'past_requests'=>['Requested time has passed',"a.status='pending' AND a.requested_date<NOW()",'Reschedule or decline these pending requests.','danger',96],
    'unassigned'=>['Veterinarian not assigned',"a.status='approved' AND a.assigned_vet_id IS NULL",'Assign a veterinarian to these approved visits.','danger',95],
    'unscheduled'=>['Confirmed visits without a time',"a.status='approved' AND a.scheduled_date IS NULL",'Set a schedule for these approved visits.','danger',94],
    'visit_updates'=>['Past visits needing a status update',"a.status='approved' AND a.scheduled_date<NOW()",'Confirm whether these visits are completed or need rescheduling.','warning',93],
    'consultations'=>['Completed visits without a record',"a.status='completed' AND NOT EXISTS (SELECT 1 FROM medical_records m WHERE m.appointment_id=a.id AND m.pet_id=a.pet_id)",'Review completed visits with no linked consultation record.','warning',85],
];
$dashboardAppointmentTaskQueues=[];
foreach($dashboardAppointmentTaskDefinitions as $taskKey=>[$taskTitle,$taskCondition,$taskNote,$taskTone,$taskPriority]){
    $taskRows=dashboard_rows($conn,"SELECT a.id,a.pet_id,COALESCE(a.scheduled_date,a.requested_date) visit_time,p.name pet_name,u.full_name client_name,v.full_name vet_name FROM appointments a JOIN pets p ON p.id=a.pet_id JOIN users u ON u.id=a.owner_id LEFT JOIN users v ON v.id=a.assigned_vet_id WHERE $taskCondition ORDER BY visit_time,a.id");
    $taskHtml=dashboard_list_html($taskRows,static function(array $row)use($taskKey,$taskTitle):string{
        $path=$taskKey==='consultations'?'vet/appointments.php':'admin/appointments.php';
        $return=rawurlencode('admin/dashboard.php?queue='.$taskKey.'#actionQueue');
        $url=app_url($path.'?appointment_id='.(int)$row['id'].($taskKey==='consultations'?'':'&return_to='.$return));
        $date=$row['visit_time']?date('M j, Y, g:i A',strtotime($row['visit_time'])):'No visit time recorded';
        return '<a class="dashboard-detail-entry" href="'.e($url).'"><span><b>'.e($row['pet_name']).' &middot; '.e($row['client_name']).'</b><small>'.e($date).' &middot; '.e($row['vet_name']?:'Veterinarian not assigned').'</small><em>'.e($taskTitle).'</em></span><strong>Review visit</strong></a>';
    },'No visits currently need this action.');
    $dashboardAppointmentTaskQueues[]=[$taskTitle,count($taskRows),$taskNote,'admin/appointments.php','clipboard',$taskTone,$taskPriority,['title'=>$taskTitle,'eyebrow'=>'Action queue · All dates','html'=>$taskHtml,'queue_key'=>$taskKey]];
}
