<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
$calendarRole = $calendarRole ?? ($_SESSION['role'] ?? 'staff');
if(!in_array($calendarRole,['admin','veterinarian','staff'],true)) $calendarRole='staff';
require_role($calendarRole);
$uid = (int)current_user_id();
$roleFolder = $calendarRole === 'admin' ? 'admin' : ($calendarRole === 'veterinarian' ? 'vet' : 'staff');
$canViewCalendarPrivate = true;

function calendar_safe_month($value) {
    return preg_match('/^\d{4}-\d{2}$/', (string)$value) ? $value : date('Y-m');
}

function calendar_clock_label($value) {
    $time = is_int($value) ? $value : strtotime((string)$value);
    if (!$time) return '';
    if (date('H:i', $time) === '00:00') return '12:00 midnight';
    if (date('H:i', $time) === '12:00') return '12:00 noon';
    return date('g:i A', $time);
}

function calendar_time_range_label($startsAt, $endsAt) {
    $start = is_int($startsAt) ? $startsAt : strtotime((string)$startsAt);
    $end = is_int($endsAt) ? $endsAt : strtotime((string)$endsAt);
    if (!$start || !$end) return '';
    $label = calendar_clock_label($start) . ' – ' . calendar_clock_label($end);
    if (date('Y-m-d', $start) !== date('Y-m-d', $end)) $label .= ' · next day';
    return $label;
}

function calendar_redirect_path($role, $month, $scope, $status, $workforce) {
    $folder = $role === 'admin' ? 'admin' : ($role === 'veterinarian' ? 'vet' : 'staff');
    return $folder . '/calendar.php?' . http_build_query(['month'=>$month,'scope'=>$scope,'status'=>$status,'workforce'=>$workforce]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $action = $_POST['action'] ?? '';
    $monthRedirect = calendar_safe_month($_POST['month'] ?? date('Y-m'));
    $scopeRedirect = $_POST['scope'] ?? ($calendarRole === 'admin' ? 'appointments' : 'clinic');
    $statusRedirect = $_POST['status_filter'] ?? 'all';
    $workforceRedirect = $_POST['workforce_filter'] ?? 'unavailable';

    if ($action === 'add_schedule') {
        $requestedKind = $_POST['event_kind'] ?? 'unavailable';
        $eventKind = $workforceRedirect === 'unavailable' ? 'unavailable' : 'clinic_schedule';
        if (!in_array($workforceRedirect, ['available','unavailable','clinic_schedule'], true)) $eventKind = $requestedKind === 'unavailable' ? 'unavailable' : 'clinic_schedule';
        if ($calendarRole !== 'admin' && $eventKind !== 'unavailable') {
            flash('error', 'Clinic schedule blocks can only be managed by an administrator.');
            redirect_to(calendar_redirect_path($calendarRole, $monthRedirect, $scopeRedirect, $statusRedirect, $workforceRedirect));
        }
        $allowedTypes = ['break','lunch','leave','sick','training','personal','on_duty','consultation','other'];
        $inserted = 0; $skipped = 0;

        if ($eventKind === 'clinic_schedule') {
            $scheduleDate = trim($_POST['schedule_date'] ?? '');
            $repeatUntil = trim($_POST['clinic_repeat_until'] ?? '');
            $weekdays = array_values(array_filter(array_map('intval', $_POST['clinic_weekdays'] ?? []), fn($day)=>$day>=1&&$day<=7));
            $vetShiftTypes = $_POST['vet_shift_block_type'] ?? [];
            $vetShiftVets = $_POST['vet_shift_vets'] ?? [];
            $vetShiftNotes = $_POST['vet_shift_reason'] ?? [];
            $vetShiftStarts = $_POST['vet_shift_start'] ?? [];
            $vetShiftEnds = $_POST['vet_shift_end'] ?? [];
            $staffShiftTypes = $_POST['staff_shift_block_type'] ?? [];
            $staffShiftStaff = $_POST['staff_shift_staff'] ?? [];
            $staffShiftNotes = $_POST['staff_shift_reason'] ?? [];
            $staffShiftStarts = $_POST['staff_shift_start'] ?? [];
            $staffShiftEnds = $_POST['staff_shift_end'] ?? [];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduleDate)) {
                flash('error', 'Choose a valid start date for the clinic schedule.');
            } elseif ($repeatUntil !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $repeatUntil) || strtotime($repeatUntil) < strtotime($scheduleDate))) {
                flash('error', 'Choose a valid clinic schedule repeat end date.');
            } elseif ($repeatUntil !== '' && !$weekdays) {
                flash('error', 'Select at least one weekday when adding a multi-day clinic schedule.');
            } else {
                $dates=[];
                if ($repeatUntil !== '') {
                    $cursor = new DateTime($scheduleDate);
                    $until = new DateTime($repeatUntil);
                    while ($cursor <= $until && count($dates) < 180) {
                        if (in_array((int)$cursor->format('N'), $weekdays, true)) $dates[] = $cursor->format('Y-m-d');
                        $cursor->modify('+1 day');
                    }
                } else {
                    $dates[] = $scheduleDate;
                }
                if (!$dates) $dates[] = $scheduleDate;
                $allowedScheduleTypes = ['on_duty','consultation','training','other'];
                $group = count($dates) > 1 ? bin2hex(random_bytes(12)) : null;
                $shiftDefinitions = [];
                foreach ([
                    ['role'=>'veterinarian','starts'=>$vetShiftStarts,'ends'=>$vetShiftEnds,'types'=>$vetShiftTypes,'people'=>$vetShiftVets,'notes'=>$vetShiftNotes],
                    ['role'=>'staff','starts'=>$staffShiftStarts,'ends'=>$staffShiftEnds,'types'=>$staffShiftTypes,'people'=>$staffShiftStaff,'notes'=>$staffShiftNotes],
                ] as $roleSchedule) {
                    $count = max(count($roleSchedule['starts']), count($roleSchedule['ends']), count($roleSchedule['types']), count($roleSchedule['people']));
                    for ($shift=0; $shift<$count; $shift++) {
                        $start = trim((string)($roleSchedule['starts'][$shift] ?? ''));
                        $end = trim((string)($roleSchedule['ends'][$shift] ?? ''));
                        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $start === $end) { $skipped++; continue; }
                        $shiftDefinitions[] = [
                            'role'=>$roleSchedule['role'], 'index'=>$shift,
                            'start'=>$start.':00', 'end'=>$end.':00',
                            'overnight'=>strcmp($end,$start) < 0,
                            'types'=>$roleSchedule['types'], 'people'=>$roleSchedule['people'], 'notes'=>$roleSchedule['notes']
                        ];
                    }
                }
                foreach ($dates as $dateForSchedule) {
                    foreach ($shiftDefinitions as $definition) {
                        $shift = (int)$definition['index'];
                        $blockType = $definition['types'][$shift] ?? 'on_duty';
                        if ($blockType === 'closed') continue;
                        if (!in_array($blockType, $allowedScheduleTypes, true)) $blockType = 'on_duty';
                        $selectedIds = array_values(array_unique(array_filter(array_map('intval', is_array($definition['people'][$shift] ?? null) ? $definition['people'][$shift] : []))));
                        if (!$selectedIds) { $skipped++; continue; }
                        $reasonText = trim((string)($definition['notes'][$shift] ?? ''));
                        $slotStart = new DateTimeImmutable($dateForSchedule . ' ' . $definition['start']);
                        $slotEnd = new DateTimeImmutable($dateForSchedule . ' ' . $definition['end']);
                        if ($definition['overnight']) $slotEnd = $slotEnd->modify('+1 day');
                        $dayStart = $slotStart->format('Y-m-d H:i:s');
                        $dayEnd = $slotEnd->format('Y-m-d H:i:s');
                        foreach ($selectedIds as $targetUser) {
                            $expectedRole = $definition['role'];
                            $targetStmt = $conn->prepare("SELECT id,role,full_name FROM users WHERE id=? AND role=? AND status='active' AND deleted_at IS NULL LIMIT 1");
                            $targetStmt->bind_param('is', $targetUser, $expectedRole); $targetStmt->execute(); $target = $targetStmt->get_result()->fetch_assoc();
                            if (!$target) { $skipped++; continue; }
                            $overlapStmt = $conn->prepare("SELECT COUNT(*) c FROM staff_availability WHERE user_id=? AND starts_at < ? AND ends_at > ? AND reason NOT LIKE '[Removed]%' AND reason NOT LIKE '[Denied]%'");
                            $overlapStmt->bind_param('iss', $targetUser, $dayEnd, $dayStart); $overlapStmt->execute();
                            if ((int)$overlapStmt->get_result()->fetch_assoc()['c'] > 0) { $skipped++; continue; }
                            $storedReason = '[Approved] ' . $reasonText;
                            $recurring = count($dates) > 1 ? 1 : 0;
                            $eventKindValue = 'clinic_schedule';
                            $stmt = $conn->prepare("INSERT INTO staff_availability(user_id,event_kind,starts_at,ends_at,block_type,reason,is_recurring,recurrence_group,created_by) VALUES(?,?,?,?,?,?,?,?,?)");
                            if (!$stmt) { $skipped++; continue; }
                            $stmt->bind_param('isssssisi', $targetUser, $eventKindValue, $dayStart, $dayEnd, $blockType, $storedReason, $recurring, $group, $uid);
                            if ($stmt->execute()) $inserted++; else $skipped++;
                        }
                    }
                }
                if ($inserted) {
                    log_action($conn, 'Added clinic schedule', 'staff_availability', null, $inserted . ' shift assignment(s) saved starting ' . $scheduleDate . '.');
                    flash('success', $inserted . ' clinic shift assignment' . ($inserted === 1 ? '' : 's') . ' saved' . ($skipped ? '; ' . $skipped . ' empty, invalid, or conflicting assignment(s) skipped.' : '.'));
                } else flash('error', 'No clinic schedule was saved. Assign at least one active veterinarian or staff member to a shift.');
            }
        } else {
            $targetUser = $calendarRole === 'admin' ? (int)($_POST['user_id'] ?? 0) : $uid;
            $startsAt = trim($_POST['starts_at'] ?? ''); $endsAt = trim($_POST['ends_at'] ?? '');
            $blockType = $_POST['block_type'] ?? 'leave'; if (!in_array($blockType, ['leave','sick','training','personal','other'], true)) $blockType = 'other';
            $reasonText = trim($_POST['reason'] ?? '');
            $repeat = !empty($_POST['repeat_schedule']); $repeatUntil = trim($_POST['repeat_until'] ?? '');
            $weekdays = array_values(array_filter(array_map('intval', $_POST['weekdays'] ?? []), fn($day)=>$day>=1&&$day<=7));
            $targetStmt = $conn->prepare("SELECT id,role,full_name FROM users WHERE id=? AND role IN ('veterinarian','staff') AND status='active' AND deleted_at IS NULL LIMIT 1");
            $targetStmt->bind_param('i', $targetUser); $targetStmt->execute(); $target = $targetStmt->get_result()->fetch_assoc();
            $startTs = strtotime($startsAt); $endTs = strtotime($endsAt);
            if (!$target || !$startTs || !$endTs || $endTs <= $startTs) flash('error', 'Choose a valid staff member and time range.');
            elseif ($repeat && (!$repeatUntil || strtotime($repeatUntil) < strtotime(date('Y-m-d', $startTs)))) flash('error', 'Choose a valid repeat end date.');
            elseif ($repeat && !$weekdays) flash('error', 'Select at least one weekday for the recurring schedule.');
            else {
                $dates=[]; if($repeat){$cursor=new DateTime(date('Y-m-d',$startTs));$until=new DateTime($repeatUntil);while($cursor<=$until&&count($dates)<180){if(in_array((int)$cursor->format('N'),$weekdays,true))$dates[]=$cursor->format('Y-m-d');$cursor->modify('+1 day');}}else $dates[]=date('Y-m-d',$startTs);
                $startTime=date('H:i:s',$startTs);$endTime=date('H:i:s',$endTs);$group=$repeat?bin2hex(random_bytes(12)):null;
                $approvalState = $calendarRole === 'admin' ? 'Approved' : 'Pending';
                $storedReason = '['.$approvalState.'] ' . $reasonText;
                foreach($dates as $date){$dayStart=$date.' '.$startTime;$dayEnd=$date.' '.$endTime;if(strtotime($dayEnd)<=strtotime($dayStart))$dayEnd=date('Y-m-d H:i:s',strtotime($dayEnd.' +1 day'));
                    $overlapStmt=$conn->prepare("SELECT COUNT(*) c FROM staff_availability WHERE user_id=? AND starts_at<? AND ends_at>? AND reason NOT LIKE '[Removed]%' AND reason NOT LIKE '[Denied]%'");$overlapStmt->bind_param('iss',$targetUser,$dayEnd,$dayStart);$overlapStmt->execute();if((int)$overlapStmt->get_result()->fetch_assoc()['c']){$skipped++;continue;}
                    $recurring=$repeat?1:0;$stmt=$conn->prepare("INSERT INTO staff_availability(user_id,event_kind,starts_at,ends_at,block_type,reason,is_recurring,recurrence_group,created_by) VALUES(?,?,?,?,?,?,?,?,?)");$stmt->bind_param('isssssisi',$targetUser,$eventKind,$dayStart,$dayEnd,$blockType,$storedReason,$recurring,$group,$uid);if($stmt->execute())$inserted++;
                }
                if($inserted){
                    if($calendarRole==='admin'){$leaveLabel=ucwords(str_replace('_',' ',$blockType));$targetFolder=$target['role']==='veterinarian'?'vet':'staff';notify_user($conn,$targetUser,$leaveLabel.' approved','The clinic added approved '.$leaveLabel.' from '.date('M d, Y h:i A',$startTs).' to '.date('M d, Y h:i A',$endTs).'.','system',$targetFolder.'/calendar.php?scope=workforce&workforce=unavailable');}
                    else{$admins=$conn->query("SELECT id FROM users WHERE role='admin' AND status='active' AND deleted_at IS NULL");while($admin=$admins->fetch_assoc())notify_user($conn,(int)$admin['id'],'Unavailability request',$target['full_name'].' requested '.ucwords(str_replace('_',' ',$blockType)).' from '.date('M d, Y h:i A',$startTs).'.','system','admin/calendar.php?scope=workforce&workforce=unavailable');}
                    log_action($conn,$calendarRole==='admin'?'Added approved unavailability':'Requested unavailability','staff_availability',null,$target['full_name'].' · '.$inserted.' occurrence(s).');
                    flash('success',$calendarRole==='admin'?'Unavailability saved and the user was notified.':'Unavailability request submitted for administrator approval.');
                }else flash('error','No unavailability was saved because the selected time conflicts with an existing entry.');
            }
        }
    } elseif ($action === 'approve_schedule' && $calendarRole === 'admin') {
        $id=(int)($_POST['id']??0);$stmt=$conn->prepare("SELECT sa.*,u.full_name,u.role FROM staff_availability sa JOIN users u ON sa.user_id=u.id WHERE sa.id=? AND sa.reason LIKE '[Pending]%' LIMIT 1");$stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();
        if($row){$reason='[Approved] '.workforce_reason_text($row['reason']);$update=$conn->prepare("UPDATE staff_availability SET reason=? WHERE id=?");$update->bind_param('si',$reason,$id);$update->execute();$leaveLabel=ucwords(str_replace('_',' ',$row['block_type']));$targetFolder=$row['role']==='veterinarian'?'vet':'staff';notify_user($conn,(int)$row['user_id'],$leaveLabel.' approved','Your '.$leaveLabel.' from '.date('M d, Y h:i A',strtotime($row['starts_at'])).' to '.date('M d, Y h:i A',strtotime($row['ends_at'])).' was approved.','system',$targetFolder.'/calendar.php?scope=workforce&workforce=unavailable');log_action($conn,'Approved unavailability request','staff_availability',$id,$row['full_name']);flash('success','Unavailability request approved.');}
    } elseif ($action === 'deny_schedule' && $calendarRole === 'admin') {
        $id=(int)($_POST['id']??0);$stmt=$conn->prepare("SELECT sa.*,u.full_name,u.role FROM staff_availability sa JOIN users u ON sa.user_id=u.id WHERE sa.id=? AND sa.reason LIKE '[Pending]%' LIMIT 1");$stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();
        if($row){$leaveEnd=$row['ends_at'];$reason='[Denied] '.workforce_reason_text($row['reason']);$update=$conn->prepare("UPDATE staff_availability SET reason=?,ends_at=starts_at WHERE id=?");$update->bind_param('si',$reason,$id);$update->execute();$leaveLabel=ucwords(str_replace('_',' ',$row['block_type']));$targetFolder=$row['role']==='veterinarian'?'vet':'staff';notify_user($conn,(int)$row['user_id'],$leaveLabel.' denied','Your '.$leaveLabel.' request from '.date('M d, Y h:i A',strtotime($row['starts_at'])).' to '.date('M d, Y h:i A',strtotime($leaveEnd)).' was not approved.','system',$targetFolder.'/calendar.php?scope=workforce&workforce=unavailable');log_action($conn,'Denied unavailability request','staff_availability',$id,$row['full_name']);flash('success','Unavailability request denied and the user was notified.');}
    } elseif ($action === 'create_appointment' && in_array($calendarRole, ['admin','staff'], true)) {
        $ownerId = (int)($_POST['owner_id'] ?? 0);
        $petId = (int)($_POST['pet_id'] ?? 0);
        $requestedDate = trim($_POST['requested_date'] ?? '');
        $scheduledDate = trim($_POST['scheduled_date'] ?? '');
        $duration = max(15, min(180, (int)($_POST['duration_minutes'] ?? 30)));
        $vetId = (int)($_POST['assigned_vet_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $petCheck = $conn->prepare("SELECT p.id,p.name FROM pets p JOIN users u ON p.owner_id=u.id WHERE p.id=? AND p.owner_id=? AND p.verification_status='approved' AND u.role='client' AND u.status='active' AND u.deleted_at IS NULL LIMIT 1");
        $petCheck->bind_param('ii', $petId, $ownerId);
        $petCheck->execute();
        $validPet = $petCheck->get_result()->fetch_assoc();
        $validVet = null;
        if ($vetId) {
            $vetStmt = $conn->prepare("SELECT id FROM users WHERE id=? AND role='veterinarian' AND status='active' AND deleted_at IS NULL LIMIT 1");
            $vetStmt->bind_param('i', $vetId);
            $vetStmt->execute();
            $validVet = $vetStmt->get_result()->fetch_assoc();
        }
        if (!$validPet || !$requestedDate || !$reason || ($vetId && !$validVet)) {
            flash('error', 'Choose an approved client pet, a valid date, and enter the appointment reason.');
        } else {
            $status = ($calendarRole === 'admin' && $scheduledDate && $vetId) ? 'approved' : 'pending';
            $finalSchedule = $status === 'approved' ? $scheduledDate : null;
            $conflict = $status === 'approved' ? appointment_slot_conflict($conn, 0, $vetId, $finalSchedule, $duration) : ['conflict'=>false,'message'=>''];
            if ($conflict['conflict']) {
                flash('error', $conflict['message']);
            } else {
                $code = $status === 'approved' ? 'VX-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)) : null;
                $note = $calendarRole === 'admin' ? 'Created from the calendar by an administrator.' : 'Created from the calendar by clinic staff. Administrator verification required.';
                $insert = $conn->prepare("INSERT INTO appointments(owner_id,pet_id,requested_date,scheduled_date,duration_minutes,assigned_vet_id,confirmation_code,reason,status,admin_notes) VALUES(?,?,?,?,?,?,?,?,?,?)");
                $insert->bind_param('iissiissss', $ownerId, $petId, $requestedDate, $finalSchedule, $duration, $vetId, $code, $reason, $status, $note);
                $insert->execute();
                $appointmentId = (int)$insert->insert_id;
                notify_user($conn, $ownerId, $status === 'approved' ? 'Appointment scheduled' : 'Appointment requested', $validPet['name'] . ' appointment was ' . ($status === 'approved' ? 'scheduled for ' . date('M d, Y h:i A', strtotime($finalSchedule)) : 'submitted for review') . '.', 'appointment', null);
                log_action($conn, 'Created appointment from calendar', 'appointment', $appointmentId, 'Status: ' . $status);
                flash('success', $status === 'approved' ? 'Appointment scheduled.' : 'Appointment submitted for administrator review.');
            }
        }
    } elseif ($action === 'delete_schedule') {
        $id = (int)($_POST['id'] ?? 0);
        $scopeSql = $calendarRole === 'admin' ? '' : " AND user_id=$uid AND event_kind='unavailable' AND reason LIKE '[Pending]%'";
        $marker='[Removed] '.date('Y-m-d H:i').' by user '.$uid.'. ';
        $stmt=$conn->prepare("UPDATE staff_availability SET reason=CONCAT(?,COALESCE(reason,'')),ends_at=starts_at WHERE id=? $scopeSql AND reason NOT LIKE '[Removed]%'");
        $stmt->bind_param('si',$marker,$id);$stmt->execute();
        log_action($conn, 'Removed workforce schedule', 'staff_availability', $id, 'Calendar schedule entry hidden while its stored details were retained.');
        flash('success', 'Schedule entry removed from the active calendar.');
    } elseif ($action === 'delete_schedule_series') {
        $group = preg_replace('/[^a-f0-9]/i', '', $_POST['recurrence_group'] ?? '');
        if ($group !== '') {
            $scopeSql = $calendarRole === 'admin' ? '' : " AND user_id=$uid AND event_kind='unavailable' AND reason LIKE '[Pending]%'";
            $marker='[Removed] '.date('Y-m-d H:i').' by user '.$uid.'. ';
            $stmt = $conn->prepare("UPDATE staff_availability SET reason=CONCAT(?,COALESCE(reason,'')),ends_at=starts_at WHERE recurrence_group=? $scopeSql AND reason NOT LIKE '[Removed]%'");
            $stmt->bind_param('ss', $marker,$group);
            $stmt->execute();
            log_action($conn, 'Removed recurring workforce schedule', 'staff_availability', null, 'Recurrence group ' . $group . ' hidden while its stored details were retained.');
            flash('success', 'Recurring schedule series removed from the active calendar.');
        }
    }
    redirect_to(calendar_redirect_path($calendarRole, $monthRedirect, $scopeRedirect, $statusRedirect, $workforceRedirect));
}

$availabilityFocusId = max(0, (int)($_GET['availability_id'] ?? 0));
$availabilityFocusMonth = '';
if ($availabilityFocusId > 0 && $calendarRole === 'admin') {
    $focusStmt = $conn->prepare("SELECT starts_at FROM staff_availability WHERE id=? AND reason NOT LIKE '[Removed]%' LIMIT 1");
    $focusStmt->bind_param('i', $availabilityFocusId);
    $focusStmt->execute();
    if ($focusRow = $focusStmt->get_result()->fetch_assoc()) $availabilityFocusMonth = date('Y-m', strtotime($focusRow['starts_at']));
}
$month = $_GET['month'] ?? ($availabilityFocusMonth ?: date('Y-m'));
if (isset($_GET['jump_year'], $_GET['jump_month']) && preg_match('/^\d{4}$/', (string)$_GET['jump_year']) && preg_match('/^(0?[1-9]|1[0-2])$/', (string)$_GET['jump_month'])) {
    $month = sprintf('%04d-%02d', (int)$_GET['jump_year'], (int)$_GET['jump_month']);
}
$month = calendar_safe_month($month);
$monthStart = $month . '-01 00:00:00';
$monthEnd = date('Y-m-d H:i:s', strtotime($monthStart . ' +1 month'));
$monthTitle = date('F Y', strtotime($monthStart));
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));

$defaultScope = $calendarRole === 'admin' ? 'appointments' : ($calendarRole === 'client' ? 'mine' : 'clinic');
$scope = $_GET['scope'] ?? $defaultScope;
$scopeOptions = $calendarRole === 'admin' ? ['appointments','workforce'] : ($calendarRole === 'veterinarian' ? ['clinic','mine','workforce'] : ($calendarRole === 'staff' ? ['clinic','workforce'] : ['mine']));
if (!in_array($scope, $scopeOptions, true)) $scope = $defaultScope;
$statusFilter = strtolower($_GET['status'] ?? 'all');
$allowedStatuses = ['all','pending','approved','completed','rejected','cancelled','attention'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'all';
$workforceFilter = strtolower($_GET['workforce'] ?? 'unavailable');
if (!in_array($workforceFilter, ['all','available','unavailable','clinic_schedule'], true)) $workforceFilter = 'unavailable';

$events = [];
$statusCounts = array_fill_keys(['pending','approved','completed','rejected','cancelled','attention'], 0);
$workforceCounts = array_fill_keys(['available','unavailable','clinic_schedule'], 0);
$workforceTotal = 0;
$pendingUnavailableCount = 0;

$calendarPrivateFields=$canViewCalendarPrivate?'u.phone owner_phone,u.email owner_email':'NULL AS owner_phone,NULL AS owner_email';
$appointmentSql = "SELECT a.*,u.full_name owner_name,$calendarPrivateFields,p.name pet_name,p.species,p.breed,p.pet_photo,v.full_name vet_name FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id WHERE COALESCE(a.scheduled_date,a.requested_date)>=? AND COALESCE(a.scheduled_date,a.requested_date)<?";
$appointmentParams = [$monthStart,$monthEnd];
$appointmentTypes = 'ss';
if ($calendarRole === 'veterinarian' && $scope === 'mine') { $appointmentSql .= ' AND a.assigned_vet_id=?'; $appointmentParams[]=$uid; $appointmentTypes.='i'; }
if ($calendarRole === 'client') { $appointmentSql .= ' AND a.owner_id=?'; $appointmentParams[]=$uid; $appointmentTypes.='i'; }
$appointmentSql .= ' ORDER BY COALESCE(a.scheduled_date,a.requested_date),a.id';
$stmt = $conn->prepare($appointmentSql);
$stmt->bind_param($appointmentTypes, ...$appointmentParams);
$stmt->execute();
$appointmentRows = $stmt->get_result();
while ($row = $appointmentRows->fetch_assoc()) {
    $eventTime = $row['scheduled_date'] ?: $row['requested_date'];
    $attention = appointment_attention_details($row, $calendarRole);
    $displayStatus = $attention['needs_attention'] ? 'attention' : $row['status'];
    $statusCounts[$row['status']] = ($statusCounts[$row['status']] ?? 0) + 1;
    if ($attention['needs_attention']) $statusCounts['attention']++;
    if ($statusFilter !== 'all' && $displayStatus !== $statusFilter && $row['status'] !== $statusFilter) continue;
    if (($calendarRole === 'admin' && $scope === 'workforce') || (in_array($calendarRole,['staff','veterinarian'],true) && $scope === 'workforce')) continue;
    $dateKey = date('Y-m-d', strtotime($eventTime));
    $privateClientEvent = $calendarRole === 'client';
    $returnTo = $roleFolder . '/calendar.php?' . http_build_query(['month'=>$month,'scope'=>$scope,'status'=>$statusFilter,'workforce'=>$workforceFilter]);
    $url = app_url(($calendarRole === 'admin' ? 'admin' : ($calendarRole === 'veterinarian' ? 'vet' : 'staff')) . '/appointments.php?appointment_id=' . (int)$row['id'] . '&return_to=' . rawurlencode($returnTo));
    $priority = $displayStatus === 'attention' ? 0 : ($row['status'] === 'pending' ? 1 : ($row['status'] === 'approved' ? 3 : 5));
    $events[$dateKey][] = [
        'kind'=>'appointment','identifier'=>'','status'=>$displayStatus,'raw_status'=>$row['status'],'title'=>$row['pet_name'],'time'=>calendar_clock_label($eventTime),'sort_time'=>strtotime($eventTime),'priority'=>$priority,
        'client'=>$privateClientEvent ? 'Your appointment' : $row['owner_name'],'veterinarian'=>$row['vet_name'] ?: 'Not assigned','details'=>$row['reason'] ?: 'No reason provided.','attention'=>$attention['message'],'resolution'=>$attention['resolution'],
        'url'=>$url,'can_delete'=>false,'is_past'=>strtotime($eventTime) < time(),'appointment_id'=>(int)$row['id']
    ];
}

$showWorkforce = $calendarRole !== 'client' && (($calendarRole === 'admin' && $scope==='workforce') || (in_array($calendarRole,['staff','veterinarian'],true) && $scope === 'workforce'));
$isUnavailableView=$workforceFilter==='unavailable'||$workforceFilter==='available';
$isAvailableView=$workforceFilter==='available';
$isClinicScheduleView=$workforceFilter==='clinic_schedule';
$canAddAppointment=in_array($calendarRole,['admin','staff'],true)&&$scope!=='workforce';
$canAddWorkforce=$calendarRole!=='client'&&$scope==='workforce'&&($isUnavailableView||($calendarRole==='admin'&&$isClinicScheduleView));
if ($showWorkforce) {
    $approvedUnavailableWindows = [];
    $unavailableStmt = $conn->prepare("SELECT user_id,starts_at,ends_at,reason FROM staff_availability WHERE event_kind='unavailable' AND starts_at<? AND ends_at>? AND reason NOT LIKE '[Removed]%' AND reason NOT LIKE '[Denied]%'");
    $unavailableStmt->bind_param('ss', $monthEnd, $monthStart);
    $unavailableStmt->execute();
    $unavailableRows = $unavailableStmt->get_result();
    while ($unavailableRow = $unavailableRows->fetch_assoc()) {
        if (workforce_reason_state($unavailableRow['reason']) !== 'approved') continue;
        $approvedUnavailableWindows[(int)$unavailableRow['user_id']][] = [strtotime($unavailableRow['starts_at']), strtotime($unavailableRow['ends_at'])];
    }
    $availabilitySql = "SELECT sa.*,u.full_name,u.role FROM staff_availability sa JOIN users u ON sa.user_id=u.id WHERE sa.starts_at<? AND sa.ends_at>? AND sa.reason NOT LIKE '[Removed]%' AND sa.reason NOT LIKE '[Denied]%'";
    $availabilityParams = [$monthEnd,$monthStart];
    $availabilityTypes = 'ss';
    $availabilitySql .= ' ORDER BY sa.starts_at,sa.id';
    $availabilityStmt = $conn->prepare($availabilitySql);
    $availabilityStmt->bind_param($availabilityTypes, ...$availabilityParams);
    $availabilityStmt->execute();
    $availabilityRows = $availabilityStmt->get_result();
    while ($row=$availabilityRows->fetch_assoc()) {
        $kind = $row['event_kind'] ?: 'unavailable';
        if ($kind === 'available') continue;
        // Older rows were created before event_kind was populated. Treat duty and break blocks as clinic schedule entries.
        if ($kind === 'unavailable' && in_array($row['block_type'], ['break','lunch','on_duty','consultation'], true) && workforce_reason_state($row['reason']) === 'approved') $kind = 'clinic_schedule';
        $approvalState = workforce_reason_state($row['reason']);
        if ($kind === 'unavailable' && $approvalState === 'pending' && ($calendarRole === 'admin' || (int)$row['user_id'] === $uid)) $pendingUnavailableCount++;
        if ($kind === 'unavailable' && $approvalState === 'pending' && $calendarRole !== 'admin' && (int)$row['user_id'] !== $uid) continue;
        $isAvailableSegment = $kind === 'clinic_schedule' && $row['role'] === 'veterinarian' && in_array($row['block_type'], ['on_duty','consultation'], true);
        if ($isAvailableSegment) {
            $segmentStart = strtotime($row['starts_at']);
            $segmentEnd = strtotime($row['ends_at']);
            foreach ($approvedUnavailableWindows[(int)$row['user_id']] ?? [] as [$unavailableStart,$unavailableEnd]) {
                if ($unavailableStart < $segmentEnd && $unavailableEnd > $segmentStart) { $isAvailableSegment = false; break; }
            }
        }
        $workforceTotal++;
        if ($kind === 'unavailable') $workforceCounts['unavailable']++;
        if ($kind === 'clinic_schedule') { $workforceCounts['clinic_schedule']++; if ($isAvailableSegment) $workforceCounts['available']++; }
        $matches = $workforceFilter === 'all' || ($workforceFilter === 'available' && $isAvailableSegment) || ($workforceFilter === 'unavailable' && $kind === 'unavailable') || ($workforceFilter === 'clinic_schedule' && $kind === 'clinic_schedule');
        if (!$matches) continue;
        $displayKind = $workforceFilter === 'available' ? 'available' : $kind;
        $dateKey = date('Y-m-d',strtotime($row['starts_at']));
        $identifier = $row['role'] === 'veterinarian' ? 'Vet' : 'Staff';
        $priority = $kind === 'unavailable' ? 2 : ($displayKind === 'available' ? 3 : 4);
        $reasonText = workforce_reason_text($row['reason']);
        $events[$dateKey][] = [
            'kind'=>'workforce','identifier'=>$identifier,'status'=>$displayKind,'raw_status'=>$displayKind,'title'=>$row['full_name'],'time'=>calendar_time_range_label($row['starts_at'],$row['ends_at']),'sort_time'=>strtotime($row['starts_at']),'priority'=>$priority,
            'client'=>'','veterinarian'=>'','details'=>'','attention'=>'','resolution'=>[],'approval_status'=>$kind==='unavailable'?$approvalState:'','url'=>'','can_delete'=>$calendarRole==='admin'||((int)$row['user_id']===$uid&&$kind==='unavailable'&&$approvalState==='pending'),'can_approve'=>$calendarRole==='admin'&&$kind==='unavailable'&&$approvalState==='pending','can_convert_unavailable'=>$displayKind==='available'&&($calendarRole==='admin'||(int)$row['user_id']===$uid),'is_past'=>strtotime($row['ends_at'])<time(),'block_id'=>(int)$row['id'],'user_id'=>(int)$row['user_id'],'starts_at'=>date('Y-m-d\TH:i',strtotime($row['starts_at'])),'ends_at'=>date('Y-m-d\TH:i',strtotime($row['ends_at'])),'recurrence_group'=>$row['recurrence_group'] ?? '','is_recurring'=>(int)($row['is_recurring'] ?? 0)
        ];
    }
}
foreach ($events as &$dayEvents) {
    usort($dayEvents, function($a,$b) use ($availabilityFocusId) {
        $aFocused = $availabilityFocusId > 0 && (int)($a['block_id'] ?? 0) === $availabilityFocusId;
        $bFocused = $availabilityFocusId > 0 && (int)($b['block_id'] ?? 0) === $availabilityFocusId;
        if ($aFocused !== $bFocused) return $aFocused ? -1 : 1;
        return [$a['priority'],$a['sort_time']] <=> [$b['priority'],$b['sort_time']];
    });
}
unset($dayEvents);

$firstWeekday = (int)date('w', strtotime($monthStart)) + 1;
$daysInMonth = (int)date('t', strtotime($monthStart));
$today = date('Y-m-d');
$title = 'Clinic Calendar';
include __DIR__ . '/header.php';
include __DIR__ . '/navbar.php';
$sidebar = $calendarRole === 'admin' ? 'admin_sidebar.php' : ($calendarRole === 'veterinarian' ? 'vet_sidebar.php' : 'staff_sidebar.php');
?>
<div class="layout"><?php include __DIR__ . '/' . $sidebar; ?><main class="content calendar-page calendar-role-<?=e($calendarRole)?>" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Schedule overview</span><h1>Clinic Calendar</h1></div></header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?>
<?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>

<section class="surface-card calendar-shell">
<div class="calendar-toolbar">
<div class="calendar-month-nav"><a class="icon-button" href="?<?=e(http_build_query(['month'=>$prevMonth,'scope'=>$scope,'status'=>$statusFilter,'workforce'=>$workforceFilter]))?>" aria-label="Previous month"><?=ui_icon('chevron-left')?></a><div><span class="eyebrow">Month view</span><h2><?=$monthTitle?></h2></div><a class="icon-button" href="?<?=e(http_build_query(['month'=>$nextMonth,'scope'=>$scope,'status'=>$statusFilter,'workforce'=>$workforceFilter]))?>" aria-label="Next month"><?=ui_icon('chevron-right')?></a></div>
<div class="calendar-toolbar-actions"><a class="button-secondary" href="?<?=e(http_build_query(['month'=>date('Y-m'),'scope'=>$scope,'status'=>$statusFilter,'workforce'=>$workforceFilter]))?>">Today</a><form method="GET" class="calendar-jump-form"><input type="hidden" name="scope" value="<?=e($scope)?>"><input type="hidden" name="status" value="<?=e($statusFilter)?>"><input type="hidden" name="workforce" value="<?=e($workforceFilter)?>"><select class="form-select" name="jump_month" aria-label="Month"><?php for($m=1;$m<=12;$m++):?><option value="<?=$m?>" <?=$m===(int)date('n',strtotime($monthStart))?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor;?></select><select class="form-select" name="jump_year" aria-label="Year"><?php for($y=(int)date('Y')-2;$y<=(int)date('Y')+4;$y++):?><option value="<?=$y?>" <?=$y===(int)date('Y',strtotime($monthStart))?'selected':''?>><?=$y?></option><?php endfor;?></select><button class="button-secondary" type="submit">Go</button></form></div>
</div>

<div class="calendar-filter-panel">
<?php if(count($scopeOptions)>1):?><div class="calendar-filter-row"><span>View</span><div class="filter-tabs compact"><?php $scopeLabels=['appointments'=>'Client appointments','workforce'=>'Staff and vets','clinic'=>'Clinic appointments','mine'=>'My Appointments'];foreach($scopeOptions as $option):?><a class="filter-tab scope-<?=e($option)?> <?=$scope===$option?'active':''?>" href="?<?=e(http_build_query(['month'=>$month,'scope'=>$option,'status'=>$statusFilter,'workforce'=>$workforceFilter]))?>"><?=e($scopeLabels[$option]??ucfirst($option))?></a><?php endforeach;?></div></div><?php endif;?>
<?php if($scope!=='workforce'):?><div class="calendar-filter-row"><span>Client appointments</span><div class="filter-tabs compact status-filter-tabs"><?php foreach(['all'=>'All','pending'=>'Pending','approved'=>'Approved','completed'=>'Completed','rejected'=>'Rejected','cancelled'=>'Cancelled','attention'=>'Needs attention'] as $key=>$label):?><a class="filter-tab status-<?=$key?> <?=$statusFilter===$key?'active':''?>" href="?<?=e(http_build_query(['month'=>$month,'scope'=>$scope,'status'=>$key,'workforce'=>$workforceFilter]))?>"><?=e($label)?><b><?=$key==='all'?array_sum(array_intersect_key($statusCounts,array_flip(['pending','approved','completed','rejected','cancelled']))):(int)$statusCounts[$key]?></b></a><?php endforeach;?></div></div><?php endif;?>
<?php if($showWorkforce):?><div class="calendar-filter-row"><span>Staff and vets</span><div class="filter-tabs compact workforce-filter-tabs"><?php foreach(['all'=>'All','available'=>'Available','unavailable'=>'Unavailable','clinic_schedule'=>'Clinic schedule'] as $key=>$label):?><a class="filter-tab workforce-<?=$key?> <?=$workforceFilter===$key?'active':''?>" href="?<?=e(http_build_query(['month'=>$month,'scope'=>$scope,'status'=>$statusFilter,'workforce'=>$key]))?>"><?=e($label)?><b><?=$key==='all'?$workforceTotal:(int)$workforceCounts[$key]?></b><?php if($key==='unavailable'&&$pendingUnavailableCount):?><i class="calendar-pending-badge" aria-label="<?=intval($pendingUnavailableCount)?> pending request<?= $pendingUnavailableCount===1?'':'s' ?>"><?=intval($pendingUnavailableCount)?></i><?php endif;?></a><?php endforeach;?></div></div><?php endif;?>
<?php if($showWorkforce && $isClinicScheduleView):?><div class="dashboard-ui-view-schedule-bar"><button class="button-secondary" type="button" data-open-clinic-schedule><?=ui_icon('eye')?>View clinic schedule</button></div><?php endif;?>
</div>

<div class="calendar-weekdays" aria-hidden="true"><?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day):?><span><?=$day?></span><?php endforeach;?></div>
<div class="calendar-grid current-month-only" style="--calendar-start-column:<?=$firstWeekday?>">
<?php for($dayNumber=1;$dayNumber<=$daysInMonth;$dayNumber++):$date=sprintf('%s-%02d',$month,$dayNumber);$isToday=$date===$today;$isPast=$date<$today;$dayEvents=$events[$date]??[];$visible=array_slice($dayEvents,0,3);$remaining=max(0,count($dayEvents)-count($visible));?>
<article class="calendar-day <?=$isToday?'is-today':''?> <?=$isPast?'is-past':''?>" data-calendar-date="<?=$date?>" tabindex="0">
<header><button type="button" class="calendar-date-button" data-day-action-date="<?=$date?>" aria-label="Open actions for <?=date('F j, Y',strtotime($date))?>"><span><?=date('j',strtotime($date))?></span><?php if($isToday):?><small>Today</small><?php endif;?></button></header>
<div class="calendar-events"><?php foreach($visible as $event):$payload=$event;$payload['date']=$date;$visualStatus=$event['raw_status']??$event['status'];?><button type="button" class="calendar-event event-<?=e($visualStatus)?> <?=$event['status']==='attention'?'has-attention':''?> <?=$event['is_past']?'event-past':''?>" data-calendar-payload='<?=e(json_encode($payload))?>'><?php if(($event['identifier']??'')!==''):?><span class="event-identifier"><?=e($event['identifier'])?></span><?php endif;?><b><?=e($event['time'])?></b><span><?=e($event['title'])?></span><?php if(($event['approval_status']??'')==='pending'):?><em class="calendar-event-pending-badge" aria-label="Pending unavailability request"><span aria-hidden="true">!</span></em><?php endif;?><?php if($event['status']==='attention'):?><i aria-label="Needs attention"><span aria-hidden="true">!</span></i><?php endif;?></button><?php endforeach;?><?php if($remaining):?><button type="button" class="calendar-more" data-day-label="<?=e(date('F j, Y',strtotime($date)))?>" data-day-items='<?=e(json_encode($dayEvents))?>'>+<?=$remaining?> more</button><?php endif;?></div>
</article>
<?php endfor;?>
</div>
<?php
$legendItems = $scope==='workforce'
 ? [
   ['available','Available','Veterinarian is scheduled for appointment or consultation coverage and is not marked unavailable.'],
   ['unavailable','Unavailable','Approved or pending time when a veterinarian or staff member cannot be scheduled.'],
   ['clinic_schedule','Clinic schedule','Clinic duty, consultation, training, break, lunch, or other planned coverage.'],
   ['red_indicator','Red indicator','Marks a pending unavailability request that still needs review.']
 ]
 : [
   ['pending','Pending','Appointment request is waiting for clinic review.'],
   ['approved','Approved','Appointment is confirmed and scheduled.'],
   ['completed','Completed','Visit has been finished.'],
   ['rejected','Rejected','Request was declined by the clinic.'],
   ['cancelled','Cancelled','Appointment was cancelled.'],
   ['attention','Needs attention','Schedule, assignment, reschedule, or completion still needs action.'],
   ['red_indicator','Red indicator','Marks a pending unavailability request or an appointment that still needs attention.']
 ];
?>
<div class="calendar-instructions calendar-legend"><div><strong>Legend</strong><div class="calendar-legend-grid"><?php foreach($legendItems as [$statusKey,$heading,$copy]):?><article class="calendar-legend-item legend-<?=e($statusKey)?>"><span class="calendar-legend-swatch status-<?=e($statusKey)?>" aria-hidden="true"></span><div><b><?=e($heading)?></b><p><?=e($copy)?></p></div></article><?php endforeach;?></div><?php if($calendarRole==='client'):?><a class="button-secondary" href="<?=app_url('client/appointments.php')?>">Open My Appointments</a><?php endif;?></div></div>
</section>

<?php if($canAddAppointment):
$calendarClientPrivateField=$canViewCalendarPrivate?'email':'NULL AS email';
$calendarClients=$conn->query("SELECT id,full_name,$calendarClientPrivateField FROM users WHERE role='client' AND status='active' AND deleted_at IS NULL ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$calendarPets=$conn->query("SELECT p.id,p.owner_id,p.name,p.species FROM pets p JOIN users u ON p.owner_id=u.id WHERE p.verification_status='approved' AND u.role='client' AND u.status='active' AND u.deleted_at IS NULL ORDER BY p.name")->fetch_all(MYSQLI_ASSOC);
$calendarVets=$conn->query("SELECT id,full_name FROM users WHERE role='veterinarian' AND status='active' AND deleted_at IS NULL ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
?>
<section class="calendar-dialog" id="calendarAppointmentDialog" aria-hidden="true"><div class="dialog-scrim" data-close-calendar-appointment></div><div class="dialog-card"><header><div><span class="eyebrow">Client appointment</span><h2>Add appointment</h2><p><?=$calendarRole==='admin'?'Complete the schedule and veterinarian to approve immediately, or leave both blank to keep it pending.':'Staff-created appointments remain pending for administrator review.'?></p></div><button class="icon-button" type="button" data-close-calendar-appointment><?=ui_icon('x')?></button></header><form method="POST" class="form-stack"><?=csrf_field()?><input type="hidden" name="action" value="create_appointment"><input type="hidden" name="month" value="<?=e($month)?>"><input type="hidden" name="scope" value="<?=e($scope)?>"><input type="hidden" name="status_filter" value="<?=e($statusFilter)?>"><input type="hidden" name="workforce_filter" value="<?=e($workforceFilter)?>"><label>Client<select class="form-select" name="owner_id" id="calendarOwner" required><option value="">Select active client</option><?php foreach($calendarClients as $person):?><option value="<?=$person['id']?>"><?=e($person['full_name'].($canViewCalendarPrivate?' · '.$person['email']:''))?></option><?php endforeach;?></select></label><label>Approved pet<select class="form-select" name="pet_id" id="calendarPet" required><option value="">Select approved pet</option><?php foreach($calendarPets as $pet):?><option value="<?=$pet['id']?>" data-owner="<?=$pet['owner_id']?>"><?=e($pet['name'].' · '.$pet['species'])?></option><?php endforeach;?></select></label><div class="form-grid-two"><label>Requested date and time<input class="form-control" type="datetime-local" name="requested_date" id="calendarRequestedDate" required></label><label>Duration<select class="form-select" name="duration_minutes"><option value="15">15 minutes</option><option value="30" selected>30 minutes</option><option value="45">45 minutes</option><option value="60">60 minutes</option><option value="90">90 minutes</option></select></label></div><?php if($calendarRole==='admin'):?><div class="form-grid-two"><label>Scheduled date and time<input class="form-control" type="datetime-local" name="scheduled_date" id="calendarScheduledDate"></label><label>Veterinarian<select class="form-select" name="assigned_vet_id"><option value="">Leave pending</option><?php foreach($calendarVets as $vet):?><option value="<?=$vet['id']?>"><?=e($vet['full_name'])?></option><?php endforeach;?></select></label></div><?php endif;?><label>Reason<textarea class="form-control" name="reason" rows="4" required placeholder="Reason for consultation"></textarea></label><div class="form-actions"><button class="button-secondary" type="button" data-close-calendar-appointment>Cancel</button><button class="button-primary" type="submit">Create appointment</button></div></form></div></section>
<?php endif;?>

<?php if($canAddWorkforce):
$people=$calendarRole==='admin'?$conn->query("SELECT id,full_name,role FROM users WHERE role IN ('veterinarian','staff') AND status='active' AND deleted_at IS NULL ORDER BY role,full_name")->fetch_all(MYSQLI_ASSOC):[];
$calendarVets=array_values(array_filter($people,fn($person)=>$person['role']==='veterinarian'));
$calendarStaff=array_values(array_filter($people,fn($person)=>$person['role']==='staff'));
?>
<section class="calendar-dialog" id="scheduleDialog" aria-hidden="true"><div class="dialog-scrim" data-close-schedule></div><div class="dialog-card workforce-schedule-dialog <?=$isClinicScheduleView?'control-ui-day-schedule-dialog clinic-schedule-editor-dialog':''?>"><header><div><span class="eyebrow">Staff and vets</span><h2><?=$isUnavailableView?'Add unavailability':'Build clinic day schedule'?></h2><?php if($isUnavailableView): ?><p><?=$calendarRole==='admin'?'Administrator-created unavailability is approved immediately and the user is notified.':'Your request remains pending until an administrator reviews it.'?></p><?php endif; ?></div><button class="icon-button" type="button" data-close-schedule><?=ui_icon('x')?></button></header><form method="POST" class="form-stack" id="scheduleForm"><?=csrf_field()?><input type="hidden" name="action" value="add_schedule"><input type="hidden" name="event_kind" value="<?=$isUnavailableView?'unavailable':'clinic_schedule'?>"><input type="hidden" name="month" value="<?=e($month)?>"><input type="hidden" name="scope" value="<?=e($scope)?>"><input type="hidden" name="status_filter" value="<?=e($statusFilter)?>"><input type="hidden" name="workforce_filter" value="<?=e($workforceFilter)?>">
<?php if($isUnavailableView):?>
  <?php if($calendarRole==='admin'):?><label>Staff member or veterinarian<select class="form-select" name="user_id" required><option value="">Select account</option><?php foreach($people as $person):?><option value="<?=$person['id']?>"><?=e($person['full_name'].' · '.role_label($person['role']))?></option><?php endforeach;?></select></label><?php endif;?>
  <div class="form-grid-two"><label>Reason type<select class="form-select" name="block_type"><option value="leave">Leave</option><option value="sick">Sick</option><option value="training">Training</option><option value="personal">Personal</option><option value="other">Other</option></select></label><label>Note<input class="form-control" name="reason" placeholder="Reason or supporting note"></label></div>
  <div class="form-grid-two"><label>Starts<input class="form-control" type="datetime-local" name="starts_at" id="scheduleStarts" required></label><label>Ends<input class="form-control" type="datetime-local" name="ends_at" id="scheduleEnds" required></label></div>
  <label class="checkbox-line"><input type="checkbox" name="repeat_schedule" value="1" id="repeatSchedule">Repeat this unavailability</label><div class="recurrence-panel" id="recurrencePanel" hidden><label>Repeat until<input class="form-control" type="date" name="repeat_until" id="repeatUntil"></label><fieldset><legend>Weekdays</legend><div class="weekday-checks"><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $number=>$label):?><label><input type="checkbox" name="weekdays[]" value="<?=$number?>"> <?=$label?></label><?php endforeach;?></div></fieldset><small>Conflicting occurrences are skipped.</small></div>
<?php else:?>
  <div class="form-grid-two"><label>Start date<input class="form-control" type="date" name="schedule_date" id="scheduleDate" required></label><label>Repeat until <span class="optional-label">optional</span><input class="form-control" type="date" name="clinic_repeat_until"></label></div>
  <fieldset class="recurrence-panel"><legend>Clinic schedule days</legend><div class="weekday-checks"><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $number=>$label):?><label><input type="checkbox" name="clinic_weekdays[]" value="<?=$number?>"> <?=$label?></label><?php endforeach;?></div><small>Leave repeat until blank to save only the selected start date.</small></fieldset>
  <div class="clinic-shift-builder dynamic-clinic-shift-builder">
    <section class="clinic-shift-group"><div class="clinic-shift-group-head"><div><h3>Vet time ranges</h3></div><button class="button-secondary" type="button" data-add-clinic-segment="vet"><?=ui_icon('plus')?>Add time range</button></div>
      <div class="clinic-time-segments" data-clinic-segments="vet">
        <div class="clinic-time-segment" data-clinic-segment data-role="vet" data-index="0"><div class="clinic-segment-fields"><label>Start<input class="form-control" type="time" name="vet_shift_start[0]" value="07:00" required></label><label>End<input class="form-control" type="time" name="vet_shift_end[0]" value="12:00" required></label><label>Schedule type<select class="form-select" name="vet_shift_block_type[0]"><option value="on_duty" selected>Available</option><option value="consultation">Consultation</option><option value="training">Training</option><option value="other">Other</option></select></label></div><div class="clinic-segment-people"><span class="form-label">Veterinarians <small><?=count($calendarVets)?> active</small></span><div class="dashboard-ui-people-picker compact-people-picker"><?php foreach($calendarVets as $person):?><label><input type="checkbox" name="vet_shift_vets[0][]" value="<?=$person['id']?>"> <span><?=e($person['full_name'])?></span></label><?php endforeach;?></div></div><label class="clinic-segment-note">Note <span class="optional-label">optional</span><input class="form-control" name="vet_shift_reason[0]" placeholder="Optional note"></label><button class="icon-button clinic-segment-remove" type="button" data-remove-clinic-segment aria-label="Remove time range"><?=ui_icon('x')?></button></div>
      </div>
    </section>
    <section class="clinic-shift-group"><div class="clinic-shift-group-head"><div><h3>Staff time ranges</h3></div><button class="button-secondary" type="button" data-add-clinic-segment="staff"><?=ui_icon('plus')?>Add time range</button></div>
      <div class="clinic-time-segments" data-clinic-segments="staff">
        <div class="clinic-time-segment" data-clinic-segment data-role="staff" data-index="0"><div class="clinic-segment-fields"><label>Start<input class="form-control" type="time" name="staff_shift_start[0]" value="07:00" required></label><label>End<input class="form-control" type="time" name="staff_shift_end[0]" value="12:00" required></label><label>Schedule type<select class="form-select" name="staff_shift_block_type[0]"><option value="on_duty" selected>Available</option><option value="training">Training</option><option value="other">Other</option></select></label></div><div class="clinic-segment-people"><span class="form-label">Staff <small><?=count($calendarStaff)?> active</small></span><div class="dashboard-ui-people-picker compact-people-picker"><?php foreach($calendarStaff as $person):?><label><input type="checkbox" name="staff_shift_staff[0][]" value="<?=$person['id']?>"> <span><?=e($person['full_name'])?></span></label><?php endforeach;?></div></div><label class="clinic-segment-note">Note <span class="optional-label">optional</span><input class="form-control" name="staff_shift_reason[0]" placeholder="Optional note"></label><button class="icon-button clinic-segment-remove" type="button" data-remove-clinic-segment aria-label="Remove time range"><?=ui_icon('x')?></button></div>
      </div>
    </section>
  </div>
<?php endif;?>
<div class="form-actions"><button class="button-secondary" type="button" data-close-schedule>Cancel</button><button class="button-primary" type="submit"><?=$isUnavailableView?'Submit unavailability':'Save clinic schedule'?></button></div></form></div></section>
<?php endif;?>

<?php if($showWorkforce && $isClinicScheduleView):
$clinicScheduleAssignments=[];
foreach($events as $items){
    foreach($items as $item){
        if(($item['kind']??'')!=='workforce' || !in_array(($item['raw_status']??''),['clinic_schedule','available'],true)) continue;
        $startsAt=strtotime((string)($item['starts_at']??''));
        $endsAt=strtotime((string)($item['ends_at']??''));
        if(!$startsAt || !$endsAt || $endsAt <= $startsAt) continue;
        $clinicScheduleAssignments[]=[
            'starts_at'=>$startsAt,
            'ends_at'=>$endsAt,
            'identifier'=>(string)($item['identifier']??''),
            'role_key'=>strtolower((string)($item['identifier']??''))==='vet'?'veterinarians':'staff',
            'name'=>(string)($item['title']??''),
            'block'=>trim((string)($item['details']??'')),
        ];
    }
}
$clinicScheduleOverview=[];
$groupedAssignments=[];
foreach($clinicScheduleAssignments as $assignment){
    $date=date('Y-m-d',$assignment['starts_at']);
    $key=$assignment['starts_at'].'|'.$assignment['ends_at'];
    if(!isset($groupedAssignments[$key])) $groupedAssignments[$key]=['date'=>$date,'sort_time'=>$assignment['starts_at'],'starts_at'=>$assignment['starts_at'],'ends_at'=>$assignment['ends_at'],'raw_status'=>'clinic_schedule','veterinarians'=>[],'staff'=>[]];
    $groupedAssignments[$key][$assignment['role_key']][]=['name'=>$assignment['name'],'block'=>$assignment['block']];
}
foreach($groupedAssignments as $row){
    foreach(['veterinarians','staff'] as $roleKey){
        $unique=[];
        foreach($row[$roleKey] as $person){$name=trim((string)$person['name']);if($name==='')continue;$unique[strtolower($name)]=['name'=>$name,'block'=>$person['block']];}
        $row[$roleKey]=array_values($unique);
        usort($row[$roleKey],fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
    }
    $row['time']=calendar_time_range_label($row['starts_at'],$row['ends_at']);
    unset($row['starts_at'],$row['ends_at']);
    $clinicScheduleOverview[]=$row;
}
usort($clinicScheduleOverview,fn($a,$b)=>[$a['date'],$a['sort_time']]<=>[$b['date'],$b['sort_time']]);
?>
<section class="calendar-dialog" id="clinicScheduleDialog" aria-hidden="true">
  <div class="dialog-scrim" data-close-clinic-schedule></div>
  <div class="dialog-card clinic-schedule-overview-dialog">
    <header>
      <div>
        <span class="eyebrow">Calendar item</span>
        <h2 id="clinicScheduleTitle">Clinic schedule</h2>
      </div>
      <button class="icon-button" type="button" data-close-clinic-schedule><?=ui_icon('x')?></button>
    </header>
    <div class="calendar-detail-content">
      <div class="dashboard-ui-clinic-schedule-filter" data-clinic-schedule-filter-wrap>
        <label>Date filter<input class="form-control" type="date" data-schedule-filter value=""></label>
        <button class="button-secondary" type="button" data-clear-schedule-date>Clear</button>
      </div>
      <div class="calendar-day-list clinic-schedule-overview-list">
        <?php if(!$clinicScheduleOverview):?><div class="empty-state"><p>No clinic schedule entries for this month.</p></div><?php endif;?>
        <?php foreach($clinicScheduleOverview as $item):?>
          <article class="calendar-day-list-item clinic-schedule-time-card status-<?=e($item['raw_status'])?>" data-schedule-row="<?=e($item['date'])?>">
            <div class="clinic-schedule-time">
              <span><?=e(date('D, M d, Y',strtotime($item['date'])))?></span>
              <b><?=e($item['time'])?></b>
            </div>
            <div class="clinic-schedule-role-groups">
              <?php foreach(['veterinarians'=>'Veterinarians','staff'=>'Staff'] as $roleKey=>$roleLabel): if(!$item[$roleKey]) continue;?>
                <section class="clinic-schedule-role-group role-<?=e($roleKey)?>">
                  <b class="clinic-schedule-role-label"><?=e($roleLabel)?></b>
                  <div class="clinic-schedule-people">
                    <?php foreach($item[$roleKey] as $person):?>
                      <span><strong><?=e($person['name'])?></strong></span>
                    <?php endforeach;?>
                  </div>
                </section>
              <?php endforeach;?>
            </div>
          </article>
        <?php endforeach;?>
      </div>
    </div>
  </div>
</section>
<?php endif;?>

<section class="calendar-dialog" id="dayActionDialog" aria-hidden="true"><div class="dialog-scrim" data-close-day-action></div><div class="dialog-card calendar-action-dialog"><header><div><span class="eyebrow">Selected date</span><h2 id="dayActionTitle">Choose an action</h2></div><button class="icon-button" type="button" data-close-day-action><?=ui_icon('x')?></button></header><div class="calendar-action-options"><?php if($canAddAppointment):?><button class="calendar-action-option" type="button" data-action-appointment><span><?=ui_icon('calendar')?></span><div><b>Add client appointment</b><small>Select an approved pet and requested time.</small></div></button><?php endif;?><?php if($canAddWorkforce):?><button class="calendar-action-option" type="button" data-action-schedule><span><?=ui_icon('user-check')?></span><div><b><?=$isUnavailableView?'Add unavailability':'Add clinic day schedule'?></b><small><?=$isUnavailableView?'Only an unavailability request can be added from this view.':'Add time segments for duty, consultation, breaks, lunch, or training.'?></small></div></button><?php endif;?><button class="calendar-action-option" type="button" data-action-view-day><span><?=ui_icon('eye')?></span><div><b>View this day</b><small>Open every visible entry for the selected date.</small></div></button></div></div></section>

<section class="calendar-dialog" id="calendarDetailDialog" aria-hidden="true"><div class="dialog-scrim" data-close-calendar-detail></div><div class="dialog-card"><header><div><span class="eyebrow">Calendar item</span><h2 id="calendarDetailTitle">Details</h2></div><button class="icon-button" type="button" data-close-calendar-detail><?=ui_icon('x')?></button></header><div class="calendar-detail-content" id="calendarDetailContent"></div><div class="calendar-detail-actions"><form method="POST" id="approveScheduleForm" hidden><?=csrf_field()?><input type="hidden" name="action" value="approve_schedule"><input type="hidden" name="month" value="<?=e($month)?>"><input type="hidden" name="scope" value="<?=e($scope)?>"><input type="hidden" name="status_filter" value="<?=e($statusFilter)?>"><input type="hidden" name="workforce_filter" value="<?=e($workforceFilter)?>"><input type="hidden" name="id" id="approveScheduleId"><button class="button-primary" type="submit">Approve request</button></form><form method="POST" id="denyScheduleForm" hidden data-confirm-message="Deny this unavailability request?"><?=csrf_field()?><input type="hidden" name="action" value="deny_schedule"><input type="hidden" name="month" value="<?=e($month)?>"><input type="hidden" name="scope" value="<?=e($scope)?>"><input type="hidden" name="status_filter" value="<?=e($statusFilter)?>"><input type="hidden" name="workforce_filter" value="<?=e($workforceFilter)?>"><input type="hidden" name="id" id="denyScheduleId"><button class="button-danger" type="submit">Deny request</button></form><form method="POST" id="deleteScheduleForm" hidden data-confirm-message="Remove this schedule entry?"><?=csrf_field()?><input type="hidden" name="action" value="delete_schedule"><input type="hidden" name="month" value="<?=e($month)?>"><input type="hidden" name="scope" value="<?=e($scope)?>"><input type="hidden" name="status_filter" value="<?=e($statusFilter)?>"><input type="hidden" name="workforce_filter" value="<?=e($workforceFilter)?>"><input type="hidden" name="id" id="deleteScheduleId"><button class="button-danger" type="submit"><?=ui_icon('trash')?>Remove entry</button></form><form method="POST" id="deleteScheduleSeriesForm" hidden data-confirm-message="Remove every entry in this recurring schedule series?"><?=csrf_field()?><input type="hidden" name="action" value="delete_schedule_series"><input type="hidden" name="month" value="<?=e($month)?>"><input type="hidden" name="scope" value="<?=e($scope)?>"><input type="hidden" name="status_filter" value="<?=e($statusFilter)?>"><input type="hidden" name="workforce_filter" value="<?=e($workforceFilter)?>"><input type="hidden" name="recurrence_group" id="deleteScheduleGroup"><button class="button-danger" type="submit"><?=ui_icon('trash')?>Remove series</button></form></div></div></section>

<script>
(()=>{
 const H=value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
 const dialogs={appointment:document.getElementById('calendarAppointmentDialog'),schedule:document.getElementById('scheduleDialog'),day:document.getElementById('dayActionDialog'),detail:document.getElementById('calendarDetailDialog'),clinic:document.getElementById('clinicScheduleDialog')};
 const open=el=>{if(!el)return;el.classList.add('open');el.setAttribute('aria-hidden','false');document.body.classList.add('overlay-open')};
 const close=el=>{if(!el)return;el.classList.remove('open');el.setAttribute('aria-hidden','true');if(!document.querySelector('.calendar-dialog.open'))document.body.classList.remove('overlay-open')};
 document.querySelectorAll('[data-close-calendar-appointment]').forEach(b=>b.addEventListener('click',()=>close(dialogs.appointment)));document.querySelectorAll('[data-close-schedule]').forEach(b=>b.addEventListener('click',()=>close(dialogs.schedule)));document.querySelectorAll('[data-close-day-action]').forEach(b=>b.addEventListener('click',()=>close(dialogs.day)));document.querySelectorAll('[data-close-calendar-detail]').forEach(b=>b.addEventListener('click',()=>close(dialogs.detail)));document.querySelectorAll('[data-close-clinic-schedule]').forEach(b=>b.addEventListener('click',()=>close(dialogs.clinic)));
 const owner=document.getElementById('calendarOwner'),pet=document.getElementById('calendarPet');owner?.addEventListener('change',()=>{[...pet.options].forEach((option,index)=>{if(index===0)return;option.hidden=option.dataset.owner!==owner.value});pet.value=''});
 const repeat=document.getElementById('repeatSchedule'),repeatPanel=document.getElementById('recurrencePanel');repeat?.addEventListener('change',()=>{repeatPanel.hidden=!repeat.checked;const until=document.getElementById('repeatUntil');if(until)until.required=repeat.checked});
 const segmentContainers={vet:document.querySelector('[data-clinic-segments="vet"]'),staff:document.querySelector('[data-clinic-segments="staff"]')};
 document.querySelectorAll('[data-add-clinic-segment]').forEach(button=>button.addEventListener('click',()=>{const role=button.dataset.addClinicSegment,container=segmentContainers[role],source=container?.querySelector('[data-clinic-segment]');if(!source)return;const clone=source.cloneNode(true),rows=[...container.querySelectorAll('[data-clinic-segment]')],index=Math.max(-1,...rows.map(row=>Number(row.dataset.index)||0))+1,old=clone.dataset.index||'0';clone.dataset.index=String(index);clone.querySelectorAll('[name]').forEach(field=>{field.name=field.name.replace('['+old+']','['+index+']');if(field.type==='checkbox')field.checked=false;else if(field.type==='time')field.value='';else if(field.tagName==='SELECT')field.selectedIndex=0;else field.value='';});container.appendChild(clone)}));
 document.addEventListener('click',event=>{const button=event.target.closest('[data-remove-clinic-segment]');if(!button)return;const row=button.closest('[data-clinic-segment]'),container=row?.parentElement;if(!row||!container)return;if(container.querySelectorAll('[data-clinic-segment]').length===1){row.querySelectorAll('input').forEach(input=>{if(input.type==='checkbox')input.checked=false;else if(input.type!=='time')input.value=''});return;}row.remove();});
 let selectedDate='';const dateTimeValue=(date,hour='09:00')=>`${date}T${hour}`;const dayItemsByDate={};document.querySelectorAll('[data-calendar-date]').forEach(day=>{const date=day.dataset.calendarDate;dayItemsByDate[date]=[...day.querySelectorAll('[data-calendar-payload]')].map(button=>JSON.parse(button.dataset.calendarPayload));const more=day.querySelector('[data-day-items]');if(more){try{dayItemsByDate[date]=JSON.parse(more.dataset.dayItems)}catch(_){}}});
 document.querySelectorAll('[data-day-action-date]').forEach(button=>button.addEventListener('click',event=>{event.stopPropagation();selectedDate=button.dataset.dayActionDate;document.getElementById('dayActionTitle').textContent=new Date(`${selectedDate}T12:00:00`).toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric',year:'numeric'});open(dialogs.day)}));
 document.querySelector('[data-action-appointment]')?.addEventListener('click',()=>{close(dialogs.day);const requested=document.getElementById('calendarRequestedDate'),scheduled=document.getElementById('calendarScheduledDate');if(requested)requested.value=dateTimeValue(selectedDate);if(scheduled)scheduled.value=dateTimeValue(selectedDate);open(dialogs.appointment)});
 document.querySelector('[data-action-schedule]')?.addEventListener('click',()=>{close(dialogs.day);const starts=document.getElementById('scheduleStarts'),ends=document.getElementById('scheduleEnds'),date=document.getElementById('scheduleDate');if(starts)starts.value=dateTimeValue(selectedDate);if(ends)ends.value=dateTimeValue(selectedDate,'17:00');if(date)date.value=selectedDate;open(dialogs.schedule)});
 const detailTitle=document.getElementById('calendarDetailTitle'),detailContent=document.getElementById('calendarDetailContent'),deleteForm=document.getElementById('deleteScheduleForm'),deleteSeries=document.getElementById('deleteScheduleSeriesForm'),approveForm=document.getElementById('approveScheduleForm'),denyForm=document.getElementById('denyScheduleForm');
 const resolution=item=>Array.isArray(item.resolution)&&item.resolution.length?`<div class="calendar-resolution-guide"><b>How to resolve</b><ol>${item.resolution.map(step=>`<li>${H(step)}</li>`).join('')}</ol></div>`:'';
 const resetActions=()=>{[deleteForm,deleteSeries,approveForm,denyForm].forEach(form=>{if(form)form.hidden=true})};
 const showDay=(date,label)=>{const items=dayItemsByDate[date]||[];detailTitle.textContent=label||'Daily schedule';detailContent.innerHTML=items.length?`<div class="calendar-day-list">${items.map(item=>`<article class="calendar-day-list-item status-${H(item.raw_status||item.status)}${item.attention?' has-attention':''}"><div><span>${item.identifier?`${H(item.identifier)} · `:''}${H(item.time)}</span><b>${H(item.title)}</b></div><small>${H(item.kind==='appointment'?'appointment':item.status.replaceAll('_',' '))}${item.approval_status?` · ${H(item.approval_status)}`:''}</small>${item.kind==='workforce'?'':`<p>${H(item.details||'No details.')}</p>`}${item.attention?`<strong>${H(item.attention)}</strong>${resolution(item)}`:''}${item.url?`<a class="button-secondary" href="${H(item.url)}">${item.attention?'Resolve appointment':'Open appointment'}</a>`:''}${item.kind==='workforce'&&item.approval_status==='pending'?`<em class="calendar-event-pending-badge" aria-label="Pending unavailability request"><span aria-hidden="true">!</span></em>`:''}</article>`).join('')}</div>`:'<div class="empty-state"><p>No entries for this date.</p></div>';resetActions();open(dialogs.detail)};
 document.querySelector('[data-action-view-day]')?.addEventListener('click',()=>{close(dialogs.day);if(<?= $isClinicScheduleView ? 'true' : 'false' ?>&&dialogs.clinic){openClinicScheduleDate(selectedDate);return;}showDay(selectedDate,document.getElementById('dayActionTitle').textContent)});document.querySelectorAll('[data-day-items]').forEach(button=>button.addEventListener('click',event=>{event.stopPropagation();let items=[];try{items=JSON.parse(button.dataset.dayItems||'[]')}catch(_){}const date=button.closest('[data-calendar-date]')?.dataset.calendarDate||'';dayItemsByDate[date]=items;if(<?= $isClinicScheduleView ? 'true' : 'false' ?>&&dialogs.clinic){openClinicScheduleDate(date);return;}showDay(date,button.dataset.dayLabel)}));
const markUnavailableButton=item=>item.can_convert_unavailable?`<button class="button-secondary" type="button" data-convert-unavailable='${H(JSON.stringify(item))}'>Mark unavailable</button>`:'';
const unavailableDetails=item=>'';
 document.addEventListener('click',event=>{const trigger=event.target.closest('[data-toggle-unavailability-detail]');if(!trigger)return;const panel=trigger.nextElementSibling;if(!panel)return;panel.hidden=!panel.hidden;trigger.textContent=panel.hidden?'View full unavailability details':'Hide full unavailability details';});
 document.addEventListener('click',event=>{const trigger=event.target.closest('[data-convert-unavailable]');if(!trigger)return;let item={};try{item=JSON.parse(trigger.dataset.convertUnavailable||'{}')}catch(_){}close(dialogs.detail);const starts=document.getElementById('scheduleStarts'),ends=document.getElementById('scheduleEnds'),userSelect=document.querySelector('#scheduleForm select[name="user_id"]');if(starts)starts.value=item.starts_at||'';if(ends)ends.value=item.ends_at||'';if(userSelect&&item.user_id)userSelect.value=String(item.user_id);open(dialogs.schedule);});
 const scheduleFilter=document.querySelector('[data-schedule-filter]'),clinicScheduleTitle=document.getElementById('clinicScheduleTitle'),clinicScheduleFilterWrap=document.querySelector('[data-clinic-schedule-filter-wrap]');
 const formatClinicDate=date=>new Date(`${date}T12:00:00`).toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric',year:'numeric'});
 const applyScheduleFilter=(singleDate=false)=>{const date=scheduleFilter?.value||'';let visible=0;document.querySelectorAll('[data-schedule-row]').forEach(row=>{row.hidden=!!date&&row.dataset.scheduleRow!==date;if(!row.hidden)visible++;});if(clinicScheduleTitle)clinicScheduleTitle.textContent=singleDate&&date?formatClinicDate(date):'Clinic schedule';if(clinicScheduleFilterWrap)clinicScheduleFilterWrap.hidden=!!singleDate;dialogs.clinic?.classList.toggle('clinic-schedule-single-date',!!singleDate);dialogs.clinic?.setAttribute('data-visible-schedule-count',String(visible));};
 const openClinicScheduleDate=date=>{if(scheduleFilter)scheduleFilter.value=date||'';applyScheduleFilter(true);open(dialogs.clinic);};
 scheduleFilter?.addEventListener('change',()=>applyScheduleFilter(false));
 document.querySelector('[data-clear-schedule-date]')?.addEventListener('click',()=>{if(scheduleFilter)scheduleFilter.value='';applyScheduleFilter(false)});
 document.querySelector('[data-open-clinic-schedule]')?.addEventListener('click',()=>{if(scheduleFilter)scheduleFilter.value='';applyScheduleFilter(false);open(dialogs.clinic)});
 document.querySelectorAll('[data-calendar-payload]').forEach(button=>button.addEventListener('click',event=>{event.stopPropagation();const item=JSON.parse(button.dataset.calendarPayload);if(item.kind==='workforce'&&['clinic_schedule','available'].includes(item.raw_status||item.status)&&dialogs.clinic){const date=button.closest('[data-calendar-date]')?.dataset.calendarDate||String(item.starts_at||'').slice(0,10);openClinicScheduleDate(date);return;}detailTitle.textContent=item.title;detailContent.innerHTML=`<dl><div><dt>Type</dt><dd>${item.identifier?`${H(item.identifier)} · `:''}${H((item.kind==='appointment'?'appointment':item.status).replaceAll('_',' '))}</dd></div><div><dt>Time</dt><dd>${H(item.time)}</dd></div><div><dt>Status</dt><dd>${H((item.approval_status||item.status).replaceAll('_',' '))}</dd></div>${item.client?`<div><dt>Client</dt><dd>${H(item.client)}</dd></div>`:''}${item.veterinarian?`<div><dt>Veterinarian</dt><dd>${H(item.veterinarian)}</dd></div>`:''}<div><dt>Details</dt><dd>${H(item.details||'No details.')}</dd></div>${item.attention?`<div class="attention-note"><dt>Needs attention</dt><dd>${H(item.attention)}</dd></div>`:''}</dl>${unavailableDetails(item)}${resolution(item)}${item.url?`<a class="button-primary calendar-record-link" href="${H(item.url)}">${item.attention?'Resolve appointment':'Open appointment record'}</a>`:''}${markUnavailableButton(item)}`;resetActions();if(deleteForm){deleteForm.hidden=!item.can_delete;document.getElementById('deleteScheduleId').value=item.block_id||''}if(deleteSeries){deleteSeries.hidden=!(item.can_delete&&item.is_recurring&&item.recurrence_group);document.getElementById('deleteScheduleGroup').value=item.recurrence_group||''}if(approveForm){approveForm.hidden=!item.can_approve;document.getElementById('approveScheduleId').value=item.block_id||''}if(denyForm){denyForm.hidden=!item.can_approve;document.getElementById('denyScheduleId').value=item.block_id||''}open(dialogs.detail)}));
 const requestedAvailability=Number(new URLSearchParams(location.search).get('availability_id')||0);if(requestedAvailability){const focused=[...document.querySelectorAll('[data-calendar-payload]')].find(button=>{try{return Number(JSON.parse(button.dataset.calendarPayload).block_id||0)===requestedAvailability}catch(_){return false}});if(focused)setTimeout(()=>{focused.click();focused.scrollIntoView({block:'center'})},80)}
})();
</script>
</main></div><?php include __DIR__ . '/footer.php'; ?>
