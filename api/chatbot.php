<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/chatbot_intents.php';
if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['answer' => 'Your session has expired. Sign in again.', 'suggestions' => []]);
    exit;
}
$role = $_SESSION['role'];
if (!in_array($role, ['admin','veterinarian','staff'], true)) { http_response_code(403); echo json_encode(['answer'=>'This portal is not available for this account.','suggestions'=>[]]); exit; }
$uid = (int)$_SESSION['user_id'];

function assistant_suggestions($conn, $role, $uid, $query = '') {
    $suggestions = [];
    if ($role === 'client') {
        $pets = $conn->query("SELECT name FROM pets WHERE owner_id=".(int)$uid." AND verification_status='approved' ORDER BY name LIMIT 5");
        while ($pets && $pet = $pets->fetch_assoc()) {
            $suggestions[] = 'Show the latest information for ' . $pet['name'];
            $suggestions[] = 'Are any vaccinations due for ' . $pet['name'] . '?';
        }
        $suggestions = array_merge($suggestions, [
            'When is my next appointment?',
            'How many unread notifications do I have?',
            'How do I submit a pet edit request?',
            'What should I do if my pet is vomiting?',
        ]);
    } elseif ($role === 'veterinarian') {
        $suggestions = [
            'Which appointments are assigned to me next?',
            'What appointments are assigned to me tomorrow?',
            'What is my schedule tomorrow?',
            'Which vaccinations are due soon?',
            'Show appointments that need attention',
            'How do I add unavailable time?',
        ];
    } elseif ($role === 'staff') {
        $suggestions = [
            'Which appointments are pending?',
            'What appointments are scheduled tomorrow?',
            'Which inventory items are low in stock?',
            'Which vaccinations are due soon?',
            'How do I assist a walk-in client?',
            'How do I retrieve a pet record by QR?',
        ];
    } else {
        $suggestions = [
            'Which appointments are pending?',
            'Which appointments need attention?',
            'Which client accounts need approval?',
            'Which inventory items are low in stock?',
            'Which vaccinations are due soon?',
            'How many unread notifications are there?',
        ];
    }
    $query = mb_strtolower(trim($query));
    if ($query !== '') {
        $tokens = array_values(array_filter(preg_split('/\s+/', $query), fn($t) => mb_strlen($t) > 1));
        usort($suggestions, function($a, $b) use ($tokens) {
            $score = function($text) use ($tokens) {
                $text = mb_strtolower($text); $n = 0;
                foreach ($tokens as $token) if (mb_strpos($text, $token) !== false) $n++;
                return $n;
            };
            return $score($b) <=> $score($a);
        });
        $matched = array_values(array_filter($suggestions, function($item) use ($tokens) {
            $lower = mb_strtolower($item);
            foreach ($tokens as $token) if (mb_strpos($lower, $token) !== false) return true;
            return false;
        }));
        if ($matched) $suggestions = $matched;
    }
    return array_slice(array_values(array_unique($suggestions)), 0, 6);
}

function assistant_faq_answer($role, $message) {
    $faqs = [
        'admin' => [
            ['What should I review first after logging in?', 'Start with Dashboard action items. Clear urgent appointments, pending approvals, edit requests, and stock warnings before reports.'],
            ['How do I approve a new client?', 'Open Clients, choose the pending account, then approve and issue OTP. The client must complete OTP verification before using the account.'],
            ['Where do I fix an appointment?', 'Open Appointments to assign the veterinarian, set the schedule, add notes, and update the appointment status.'],
            ['Why will a schedule not save?', 'The selected time may overlap an appointment, unavailable period, or clinic schedule block. Choose another time or resolve the conflict first.'],
            ['What happens when I deactivate an account?', 'The user cannot sign in, but linked clinic records stay saved. Restore the account later when access should return.'],
            ['What does a notification popup mean?', 'The alert has no exact record attached. Read the popup details instead of opening a broad list with unrelated records.'],
        ],
        'veterinarian' => [
            ['Which appointments can I see?', 'You can see appointments assigned to you and other appointment information allowed by your veterinarian account.'],
            ['How do I add a medical record?', 'Open the completed appointment or the Medical Records page, choose the pet, then enter the diagnosis, treatment, prescription, and notes.'],
            ['How do I mark myself unavailable?', 'Open Calendar, choose Staff and Vets, then choose Unavailable. Select the date and time, add the reason, and submit it.'],
            ['How do I review allergies and critical notes?', 'Open Health Monitoring or a pet profile. Profiles with submitted notes show the latest approved health information and pending edits when review is allowed.'],
            ['What should I do when a case is not assigned to me?', 'Use Appointments or Calendar to confirm assignment first. If the case is unassigned and visible to you, open it from the queue before adding clinical notes.'],
            ['Where do I see my clinic schedule?', 'Open Calendar, choose Staff and Vets, then Clinic Schedule or Available. Your scheduled blocks and approved unavailable periods appear there.'],
        ],
        'staff' => [
            ['Can I approve a client account?', 'No. Staff can help enter client details, but an administrator approves the account and issues the OTP.'],
            ['What can I do with appointments?', 'You can review requests, help set schedules, assign a veterinarian when allowed, and update appointment details available to staff.'],
            ['How do I use a pet QR code?', 'Open QR Token and enter the secure token, then view the pet information allowed for front-desk or clinic assistance.'],
            ['Can I change diagnosis or treatment?', 'No. Diagnosis, treatment, prescription, and vaccination entries are handled by veterinarians.'],
            ['How do I record a stock movement?', 'Open Inventory Updates, choose the item, choose stock in or stock out, enter the quantity, and add a reason or reference.'],
            ['Where do I check today’s schedule?', 'Open Calendar or the Staff Dashboard. The schedule queue shows recent appointments and links to the full appointment list.'],
        ],
        'client' => [
            ['Why can I not book an appointment?', 'Your pet must be approved first. Pending, rejected, or in-person-confirmation pets cannot be used for booking.'],
            ['How do I know my account is ready?', 'After an administrator approves your account, you receive an OTP by email. Sign in and enter the OTP to finish setup.'],
            ['How do I change my pet information?', 'Open My Pets and submit an edit request. The clinic reviews the change before it appears in the pet profile.'],
            ['Where can I see records and the QR code?', 'Open Records for medical and vaccination information. Open Pet QR Code for your approved pet QR code.'],
            ['What should I do in an emergency?', 'Call the clinic or an emergency veterinary clinic right away. Do not wait for a chatbot reply.'],
            ['Why is my appointment still pending?', 'The clinic still needs to review the requested time, assign a veterinarian, or approve the final schedule.'],
            ['Can I update owner contact details?', 'Open your account profile or ask the clinic staff when a field is locked. Pet profile changes should be sent as edit requests.'],
        ],
    ];
    $messageLower = mb_strtolower(trim($message));
    $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/u', $messageLower), fn($t) => mb_strlen($t) >= 3 && !in_array($t, ['how','what','where','when','which','does','should','about','with','from','into','your','have','this','that','there','will','cannot','can'], true)));
    $bestAnswer = null; $bestScore = 0;
    foreach ($faqs[$role] ?? [] as [$question,$answer]) {
        $haystack = mb_strtolower($question.' '.$answer); $score = 0;
        foreach ($tokens as $token) if (mb_strpos($haystack, $token) !== false) $score++;
        if ($score > $bestScore) { $bestScore = $score; $bestAnswer = $answer; }
    }
    return $bestScore >= 2 ? $bestAnswer : null;
}

if (($_GET['action'] ?? '') === 'suggestions') {
    echo json_encode(['suggestions' => assistant_suggestions($conn, $role, $uid, $_GET['q'] ?? '')], JSON_UNESCAPED_UNICODE);
    exit;
}

$headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$headerToken || !hash_equals(csrf_token(), $headerToken)) {
    http_response_code(403);
    echo json_encode(['answer' => 'The request could not be verified. Refresh the page and try again.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['answer' => 'Send a message using POST.']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !is_string($input['message'] ?? null)) {
    http_response_code(422);
    echo json_encode(['answer' => 'Enter a question as text.']);
    exit;
}
$message = trim((string)($input['message'] ?? ''));
if ($message === '') { echo json_encode(['answer' => 'Enter a question.']); exit; }
if (mb_strlen($message) > 1000) { http_response_code(422); echo json_encode(['answer' => 'Keep the message under 1,000 characters.']); exit; }
$now = time();
$_SESSION['chat_requests'] = array_values(array_filter($_SESSION['chat_requests'] ?? [], fn($t) => $t > $now - 60));
if (count($_SESSION['chat_requests']) >= 12) { http_response_code(429); echo json_encode(['answer' => 'Too many requests. Wait a moment and try again.']); exit; }
$_SESSION['chat_requests'][] = $now;
$lower = mb_strtolower($message);
$operationalIntent = assistant_operational_intent($message);

// Role-aware Vetrix workflow guidance. These answers never broaden the user's data access.
if (preg_match('/appointment.*(work|approval)|how\s+(do|does|can)\b.*appointment|book.*appointment|request.*appointment/', $lower)) {
    $answer = match ($role) {
        'client' => "Open Appointments or Calendar, choose an approved pet, enter the requested date and visit details, then submit the request. The clinic reviews it before it becomes approved. Use the attention notice if the clinic asks you to reschedule or provide more information.",
        'veterinarian' => "Open Appointments to review visits assigned to you. Use Calendar for your schedule and unavailable time. Appointment approval and front-desk scheduling remain limited to the controls available to your account.",
        'staff' => "Open Appointments to review pending requests, verify the schedule, and use the available management controls. Open Calendar for the clinic schedule. Staff actions remain limited to front-desk permissions.",
        default => "Open Appointments to review and manage appointment requests. Calendar shows approved and pending schedules by status. Records needing action include a direct link to the relevant appointment.",
    };
    echo json_encode(['answer' => $answer, 'suggestions' => assistant_suggestions($conn, $role, $uid, $message)], JSON_UNESCAPED_UNICODE); exit;
}
if (preg_match('/pet edit request|edit.*pet|change.*pet (profile|information|details)/', $lower)) {
    $answer = $role === 'client'
        ? "Open My Pets, open the pet profile, and submit an edit request for the fields that need correction. Attach clear proof when requested. An administrator or veterinarian reviews the request before approved changes appear in the record."
        : "Pet Edit Requests shows submitted changes within your role's access. Review the current and requested values, verify any proof, then use the available approval or rejection reason before confirming.";
    echo json_encode(['answer' => $answer, 'suggestions' => assistant_suggestions($conn, $role, $uid, $message)], JSON_UNESCAPED_UNICODE); exit;
}
if (preg_match('/qr.*(retrieve|scan|record|work)|retrieve.*qr|view.*qr|pet qr/', $lower)) {
    $answer = match ($role) {
        'client' => "Open Pet QR Code to view or download the QR code for your approved pets. The code only opens the emergency information allowed for that pet and does not expose other clients' records.",
        'staff' => "Open QR Token and enter its unique token, then open the matched pet record. Private owner contact fields stay hidden.",
        'admin' => "Open QR Token and enter its unique token, then review the matched pet record. You can also use Reports and Export for the QR emergency report.",
        default => "QR retrieval is limited to the records available to your account. Use the pet or appointment record links for clinical details assigned to you.",
    };
    echo json_encode(['answer' => $answer, 'suggestions' => assistant_suggestions($conn, $role, $uid, $message)], JSON_UNESCAPED_UNICODE); exit;
}
if (preg_match('/walk[ -]?in client|assist.*walk[ -]?in/', $lower) && in_array($role, ['staff','admin'], true)) {
    $answer = "Open Pet Encoding or Pets, select Add Walk-in Pet, and record the owner and pet details required by the form. Check for an existing client before creating a duplicate. After saving, create the appointment or continue to the permitted clinic workflow.";
    echo json_encode(['answer' => $answer, 'suggestions' => assistant_suggestions($conn, $role, $uid, $message)], JSON_UNESCAPED_UNICODE); exit;
}
if (preg_match('/unavailable time|block.*schedule|time off|leave request/', $lower) && in_array($role, ['veterinarian','staff','admin'], true)) {
    $answer = in_array($role, ['veterinarian','staff'], true)
        ? "Open Calendar, choose Staff and Vets, then choose Unavailable. Select your date and time, add the reason, and submit the request. It stays pending until an administrator reviews it."
        : "Open Calendar, choose Staff and Vets, then choose Unavailable. You can add an unavailable period or review pending requests before approving or denying them.";
    echo json_encode(['answer' => $answer, 'suggestions' => assistant_suggestions($conn, $role, $uid, $message)], JSON_UNESCAPED_UNICODE); exit;
}
if (preg_match('/records can i update|what.*record.*update|record access/', $lower)) {
    $answer = match ($role) {
        'veterinarian' => "You can update clinical records for pets available through your veterinarian pages and assigned work. Records outside your clinic access are not included.",
        'staff' => "You can use the client contact, appointment, pet-encoding, POS, inventory, and QR information shown on Staff pages. Diagnosis, treatment, prescription, and records outside staff work stay restricted.",
        'client' => "You can view your own approved pets, appointments, records, vaccinations, QR codes, and notifications. Pet detail changes are sent to the clinic as edit requests.",
        default => "Administrators can manage clinic accounts, schedules, pets, appointments, inventory, notifications, and reports. Diagnosis and treatment entries remain veterinarian work.",
    };
    echo json_encode(['answer' => $answer, 'suggestions' => assistant_suggestions($conn, $role, $uid, $message)], JSON_UNESCAPED_UNICODE); exit;
}
if (preg_match('/client (information|data).*staff|staff.*client (information|data)|private data|privacy|confidential/', $lower)) {
    $answer = match ($role) {
        'client' => "You can access only your own account, pets, appointments, records, vaccinations, QR codes, and notifications. Other clients' information is not available.",
        'staff' => "Staff assistant answers use only the operational client, appointment, pet, POS, inventory, and QR information available on Staff pages. Clinical findings and records outside staff work are not returned.",
        'veterinarian' => "Veterinarian answers use only the client and pet information needed for the clinical work available to your account.",
        default => "Vetrix limits every answer to the signed-in role. Client information should be used only for authorized clinic work and should not be shared outside the system.",
    };
    echo json_encode(['answer' => $answer, 'suggestions' => assistant_suggestions($conn, $role, $uid, $message)], JSON_UNESCAPED_UNICODE); exit;
}
if (preg_match('/profile.*setting|account setting|change.*password|update.*profile/', $lower)) {
    $answer = $role === 'admin'
        ? "Open the account menu in the top-right corner. Choose Profile for contact details and your photo, Change password for account security, or Logo to update the shared Vetrix logo."
        : "Open the account menu in the top-right corner. Choose Profile for contact details and your photo, or Change password for account security.";
    echo json_encode(['answer' => $answer, 'suggestions' => assistant_suggestions($conn, $role, $uid, $message)], JSON_UNESCAPED_UNICODE); exit;
}

// Database-backed workflow answers.
if ($operationalIntent === 'schedule') {
    $day = assistant_schedule_day($message);
    $where = "a.status IN ('pending','approved') AND ".match ($day) {
        'tomorrow' => "DATE(COALESCE(a.scheduled_date,a.requested_date))=DATE_ADD(CURDATE(), INTERVAL 1 DAY)",
        'today' => "DATE(COALESCE(a.scheduled_date,a.requested_date))=CURDATE()",
        default => "COALESCE(a.scheduled_date,a.requested_date)>=NOW()",
    };
    $scheduleLabel = match ($day) {'today' => "Today's schedule", 'tomorrow' => "Tomorrow's schedule", default => 'Upcoming appointments'};
    if ($role === 'client') $where .= " AND a.owner_id=$uid";
    if ($role === 'veterinarian') $where .= " AND a.assigned_vet_id=$uid";
    $rows = $conn->query("SELECT a.status,COALESCE(a.scheduled_date,a.requested_date) event_time,p.name pet_name,u.full_name owner_name FROM appointments a JOIN pets p ON a.pet_id=p.id JOIN users u ON a.owner_id=u.id WHERE $where ORDER BY event_time LIMIT 5");
    if (!$rows) { http_response_code(503); $answer = 'The schedule could not be loaded. Please try again.'; }
    elseif (!$rows->num_rows) $answer = $scheduleLabel.': no appointments were found within your access.';
    else { $parts=[]; while($r=$rows->fetch_assoc()) $parts[] = date('M d, Y h:i A',strtotime($r['event_time'])).' · '.$r['pet_name'].' · '.ucfirst($r['status']); $answer=$scheduleLabel." (up to 5 appointments):
• ".implode("
• ",$parts); }
    echo json_encode(['answer'=>$answer,'suggestions'=>assistant_suggestions($conn,$role,$uid,$message)], JSON_UNESCAPED_UNICODE); exit;
}
if ($operationalIntent === 'pending' || preg_match('/pending requests?/', $lower)) {
    $where = "a.status='pending'";
    if ($role === 'client') $where .= " AND a.owner_id=$uid";
    if ($role === 'veterinarian') $where .= " AND a.assigned_vet_id=$uid";
    $rows=$conn->query("SELECT a.id,a.requested_date,a.reason,p.name pet_name,u.full_name owner_name,v.full_name vet_name FROM appointments a JOIN pets p ON a.pet_id=p.id JOIN users u ON a.owner_id=u.id LEFT JOIN users v ON a.assigned_vet_id=v.id WHERE $where ORDER BY a.requested_date ASC,a.id ASC LIMIT 8");
    if(!$rows||!$rows->num_rows)$answer='No pending appointments were found within your access.';
    else{$i=1;$parts=[];while($r=$rows->fetch_assoc()){$parts[]=$i.'. '.date('M d, Y h:i A',strtotime($r['requested_date'])).' - '.$r['pet_name'].' for '.$r['owner_name'].' - '.($r['vet_name']?:'No veterinarian assigned').' - '.($r['reason']?:'No reason recorded');$i++;}$answer="Pending appointments:\n".implode("\n",$parts);}
    echo json_encode(['answer'=>$answer,'suggestions'=>assistant_suggestions($conn,$role,$uid,$message)], JSON_UNESCAPED_UNICODE); exit;
}
if ($operationalIntent === 'attention' || preg_match('/^(reschedule|rescheduling)$/', $lower)) {
    $where="a.status IN ('pending','approved') AND COALESCE(a.scheduled_date,a.requested_date)<NOW()";
    if ($role==='client') $where.=" AND a.owner_id=$uid";
    if ($role==='veterinarian') $where.=" AND a.assigned_vet_id=$uid";
    $rows=$conn->query("SELECT a.id,a.status,COALESCE(a.scheduled_date,a.requested_date) event_time,a.reason,a.admin_notes,p.name pet_name,u.full_name owner_name FROM appointments a JOIN pets p ON a.pet_id=p.id JOIN users u ON a.owner_id=u.id WHERE $where ORDER BY event_time ASC,a.id ASC LIMIT 8");
    if(!$rows||!$rows->num_rows)$answer='No past appointment needing a status update was found within your access.';
    else{$i=1;$parts=[];while($r=$rows->fetch_assoc()){$note=trim((string)($r['admin_notes']?:$r['reason']));$parts[]=$i.'. '.date('M d, Y h:i A',strtotime($r['event_time'])).' - '.$r['pet_name'].' for '.$r['owner_name'].' - '.ucfirst($r['status']).($note!==''?' - '.$note:'');$i++;}$answer="Appointments needing attention:\n".implode("\n",$parts);}
    echo json_encode(['answer'=>$answer,'suggestions'=>assistant_suggestions($conn,$role,$uid,$message)], JSON_UNESCAPED_UNICODE); exit;
}
if (preg_match('/vaccination.*due|vaccines.*due|due.*vaccination/', $lower)) {
    $where="v.next_due_date IS NOT NULL AND v.next_due_date<=DATE_ADD(CURDATE(),INTERVAL 60 DAY)";
    if ($role==='client') $where.=" AND p.owner_id=$uid";
    $rows=$conn->query("SELECT p.name pet_name,v.vaccine_name,v.next_due_date FROM vaccinations v JOIN pets p ON v.pet_id=p.id WHERE $where ORDER BY v.next_due_date LIMIT 8");
    if(!$rows||!$rows->num_rows)$answer='No vaccination due within the next 60 days was found for your access.';
    else{$parts=[];while($r=$rows->fetch_assoc())$parts[]=$r['pet_name'].' · '.$r['vaccine_name'].' · '.date('M d, Y',strtotime($r['next_due_date']));$answer="Vaccination follow-ups:\n• ".implode("\n• ",$parts);} echo json_encode(['answer'=>$answer],JSON_UNESCAPED_UNICODE);exit;
}
if ($operationalIntent === 'inventory' && in_array($role,['admin','staff'],true)) {
    $rows=$conn->query("SELECT item_name,stock_qty,unit,status FROM inventory_items WHERE status IN ('low_stock','out_of_stock') OR stock_qty<=reorder_level ORDER BY stock_qty,item_name LIMIT 10");
    if(!$rows||!$rows->num_rows)$answer='No low-stock or out-of-stock item was found.';
    else{$parts=[];while($r=$rows->fetch_assoc())$parts[]=$r['item_name'].' · '.$r['stock_qty'].' '.$r['unit'].' · '.ucwords(str_replace('_',' ',$r['status']));$answer="Inventory attention:\n• ".implode("\n• ",$parts);} echo json_encode(['answer'=>$answer],JSON_UNESCAPED_UNICODE);exit;
}
if (preg_match('/client.*approval|accounts.*approval|pending client/', $lower) && $role==='admin') {
    $rows=$conn->query("SELECT full_name,email,phone,created_at FROM users WHERE role='client' AND status='pending' AND deleted_at IS NULL ORDER BY created_at ASC LIMIT 8");
    if(!$rows||!$rows->num_rows)$answer='No client accounts are currently waiting for administrator approval.';
    else{$i=1;$parts=[];while($r=$rows->fetch_assoc()){$parts[]=$i.'. '.$r['full_name'].' - '.$r['email'].' - '.($r['phone']?:'No phone saved').' - created '.date('M d, Y',strtotime($r['created_at']));$i++;}$answer="Client accounts waiting for approval:\n".implode("\n",$parts);}
    echo json_encode(['answer'=>$answer,'suggestions'=>assistant_suggestions($conn,$role,$uid,$message)], JSON_UNESCAPED_UNICODE);exit;
}
if (preg_match('/unread notification|notifications.*unread/', $lower)) {
    if($role==='admin')$count=(int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE status='unread' AND title NOT LIKE '[Deleted] %'")->fetch_assoc()['c'];
    else{$stmt=$conn->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id=? AND status='unread' AND title NOT LIKE '[Deleted] %'");$stmt->bind_param('i',$uid);$stmt->execute();$count=(int)$stmt->get_result()->fetch_assoc()['c'];}
    echo json_encode(['answer'=>"There are $count unread notification(s) within your access."]);exit;
}

$faqAnswer = assistant_faq_answer($role, $message);
if ($faqAnswer !== null) {
    echo json_encode(['answer' => $faqAnswer, 'suggestions' => assistant_suggestions($conn, $role, $uid, $message)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Authorized pet context and general symptom guidance.
$petContext='';
$sql="SELECT DISTINCT p.* FROM pets p";
if($role==='veterinarian') $sql.=" LEFT JOIN appointments aa ON aa.pet_id=p.id AND aa.assigned_vet_id=$uid";
$sql.=" WHERE p.verification_status='approved'";
if($role==='client') $sql.=" AND p.owner_id=$uid";
if($role==='veterinarian') $sql.=" AND (aa.id IS NOT NULL OR p.last_updated_by=$uid OR p.verified_by=$uid)";
if($role==='staff') $sql.=" AND EXISTS(SELECT 1 FROM appointments sa WHERE sa.pet_id=p.id AND sa.status IN ('pending','approved','completed'))";
$sql.=' ORDER BY p.name';
$pets=$conn->query($sql);
while($pets&&$p=$pets->fetch_assoc()){
    if(stripos($message,(string)$p['name'])!==false){
        $petId=(int)$p['id'];
        $petContext="Authorized pet context:\n• Name: {$p['name']}\n• Species/Breed: {$p['species']} / ".($p['breed']?:'Not specified')."\n• Age: ".pet_age($p['birth_date'])."\n• Weight: ".($p['weight']?:'Not specified')." kg\n• Allergies: ".($p['allergies']?:'None listed')."\n• Critical notes: ".($p['critical_notes']?:'None listed')."\n";
        if(in_array($role,['client','veterinarian','admin'],true)){
            $rec=$conn->query("SELECT visit_date,diagnosis,treatment FROM medical_records WHERE pet_id=$petId ORDER BY visit_date DESC,id DESC LIMIT 2");
            if($rec&&$rec->num_rows){$petContext.="Recent records:\n";while($r=$rec->fetch_assoc())$petContext.='• '.$r['visit_date'].' · '.($r['diagnosis']?:'No diagnosis listed').' · '.($r['treatment']?:'No treatment listed')."\n";}
        } else {
            $petContext.="Clinical findings are restricted from staff assistant responses.\n";
        }
        break;
    }
}
if ($petContext !== '' && preg_match('/latest|information|details|record|history|show/', $lower)) {
    echo json_encode(['answer' => trim($petContext), 'suggestions' => assistant_suggestions($conn, $role, $uid, $message)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$answer = '';
if (preg_match('/blood|seizure|trouble breathing|cannot breathe|poison|collapse|unconscious|blue gum|choking|hit by|severe bleeding/', $lower)) {
    $answer = $petContext."Urgent warning\n\nContact a veterinarian or emergency clinic now. Keep the pet quiet and safe during transport. Do not give food, water, or medicine unless a veterinarian tells you to. Bring the product package if poisoning may be involved.";
} elseif (preg_match('/vomit|throwing up/', $lower)) {
    $answer = $petContext."Vomiting can have many causes. Note how many times it happened, whether there is blood, what the pet last ate, and whether the pet can keep water down. Do not give human medicine. Call the clinic today if vomiting repeats, the pet is weak, the abdomen looks swollen, or water will not stay down.";
} elseif (preg_match('/diarrhea|loose stool|watery stool/', $lower)) {
    $answer = $petContext."For diarrhea, note the start time, frequency, color, and whether there is blood or vomiting. Keep clean water available. Call the clinic if it lasts more than a day, happens often, contains blood, or the pet is very young, old, weak, or not drinking.";
} elseif (preg_match('/not eating|no appetite|loss of appetite/', $lower)) {
    $answer = $petContext."Check when the pet last ate, whether it is drinking, and whether there is vomiting, pain, drooling, or unusual tiredness. Do not force food. Contact the clinic if a full day passes without eating, sooner for a young pet or when other symptoms are present.";
} elseif (preg_match('/letharg|weak|very tired|not active/', $lower)) {
    $answer = $petContext."Unusual weakness or tiredness should be checked with the pet's temperature, appetite, water intake, breathing, and recent activity in mind. Keep the pet resting. Call the clinic today if the change is sudden, marked, or paired with vomiting, diarrhea, pain, pale gums, or breathing trouble.";
} elseif (preg_match('/itch|scratching|rash|skin|hair loss/', $lower)) {
    $answer = $petContext."Check the skin for redness, swelling, wounds, fleas, discharge, or a new food or product. Stop the pet from licking or scratching the area when possible. Do not apply human cream. Arrange a clinic visit if the area spreads, becomes wet or painful, or the face swells.";
} elseif (preg_match('/cough|sneeze|runny nose/', $lower)) {
    $answer = $petContext."Note how often the coughing or sneezing happens and whether there is nasal discharge, fever, low appetite, or breathing effort. Keep the pet away from other animals until the cause is known. Seek urgent care for open-mouth breathing, blue gums, collapse, or severe distress.";
} elseif (preg_match('/limp|limping|leg pain|injury|wound/', $lower)) {
    $answer = $petContext."Limit running and jumping. Check gently for swelling, bleeding, a torn nail, or something stuck in the paw, but do not force a painful joint. Use clean pressure for minor bleeding. Contact the clinic for a deep wound, strong pain, swelling, or refusal to use the leg.";
} elseif (preg_match('/medicine|medication|dose|dosage|paracetamol|ibuprofen/', $lower)) {
    $answer = $petContext."Do not give a human medicine or change a prescribed dose without speaking to a veterinarian. Some common human medicines are dangerous to pets. For a prescribed medicine, use the label instructions and call the clinic if a dose was missed, doubled, vomited, or caused a reaction.";
} elseif (preg_match('/food|feeding|diet|what can.*eat/', $lower)) {
    $answer = $petContext."Use food made for the pet's species, age, size, and health needs. Change diets slowly over several days. Avoid chocolate, grapes or raisins, onions, garlic, xylitol, alcohol, and cooked bones. Ask the clinic for a feeding amount when weight or a medical condition is involved.";
} elseif (preg_match('/vaccine|vaccination|rabies/', $lower)) {
    $answer = $petContext."Open Vaccinations or Records to check the saved vaccine and next due date available to your account. A veterinarian should confirm which vaccine is needed because the schedule depends on age, past doses, health, and local requirements.";
} elseif (preg_match('/groom|bath|nail|ear clean/', $lower)) {
    $answer = $petContext."Use pet-safe products, keep water out of the ears, and trim only the clear tip of a nail. Stop if the pet is frightened or the area is painful. Strong odor, discharge, bleeding, swelling, or repeated head shaking needs a clinic check.";
} elseif (preg_match('/behavior|aggressive|anxious|barking|meowing/', $lower)) {
    $answer = $petContext."Write down when the behavior happens, what happens just before it, and any recent changes at home. Sudden behavior change can be caused by pain or illness, so arrange a clinic check before treating it only as a training problem.";
} elseif (preg_match('/hello|hi\b|good morning|good afternoon|help me|what can you do/', $lower)) {
    $answer = match ($role) {
        'client' => "I can help with your own pets, appointments, records, vaccinations, QR codes, notifications, and basic pet-care questions. I cannot open another client's information.",
        'veterinarian' => "I can help with assigned appointments, allowed pet records, vaccinations, unavailable time, and clinic workflow questions.",
        'staff' => "I can help with appointments, client assistance, pet encoding, POS, inventory, QR retrieval, and staff calendar questions. Private client and clinical information stays restricted.",
        default => "I can help with clients, appointments, schedules, users, inventory, notifications, reports, and other administrator workflows.",
    };
} else {
    $topic = trim(preg_replace('/\s+/', ' ', $message));
    $answer = $petContext."I could not match that question to a specific Vetrix task or pet symptom. Please give one clear detail about \"$topic\", such as the page involved, the pet's symptom, when it started, or the action you are trying to complete. I will keep the answer within your account access.";
}
echo json_encode(['answer'=>$answer,'suggestions'=>assistant_suggestions($conn,$role,$uid,$message)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
