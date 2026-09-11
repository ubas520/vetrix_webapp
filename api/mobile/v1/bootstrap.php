<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method('GET');

$auth = mobile_api_authenticate($conn);
$userId = (int) $auth['id'];

function mobile_api_all_rows(mysqli_stmt $stmt): array
{
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function mobile_api_iso_datetime(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date(DATE_ATOM, $timestamp);
}

function mobile_api_split_appointment_reason(?string $reason): array
{
    $reason = trim((string) $reason);
    $parts = explode(' — ', $reason, 2);
    if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
        return ['service_type' => trim($parts[0]), 'concern' => trim($parts[1])];
    }
    return ['service_type' => 'Clinic Visit', 'concern' => $reason];
}

$profile = mobile_api_profile_payload($conn, $userId);

$petsStmt = mobile_api_prepare(
    $conn,
    'SELECT p.id,p.owner_id,p.name,p.species,p.breed,p.sex,p.birth_date,p.weight,p.color,
            p.allergies,p.critical_notes,p.notes,p.pet_photo,p.verification_status,
            p.verification_notes,p.created_at,p.updated_at
     FROM pets p WHERE p.owner_id=? ORDER BY p.name ASC,p.id ASC LIMIT 200'
);
$petsStmt->bind_param('i', $userId);
$petsStmt->execute();
$pets = mobile_api_all_rows($petsStmt);
foreach ($pets as &$pet) {
    $pet['id'] = (int) $pet['id'];
    $pet['owner_id'] = (int) $pet['owner_id'];
    $pet['pet_photo_url'] = mobile_api_media_url($pet['pet_photo'] ?? null);
}
unset($pet);

$qrStmt = mobile_api_prepare(
    $conn,
    "SELECT q.*,p.name AS pet_name
     FROM qr_tokens q
     JOIN pets p ON p.id=q.pet_id
     WHERE p.owner_id=? AND p.verification_status='approved' AND q.status='active'
       AND (q.expires_at IS NULL OR q.expires_at>NOW())
     ORDER BY q.created_at DESC,q.id DESC LIMIT 200"
);
$qrStmt->bind_param('i', $userId);
$qrStmt->execute();
$qrTokens = mobile_api_all_rows($qrStmt);
foreach ($qrTokens as &$qrToken) {
    $qrToken['id'] = (int) $qrToken['id'];
    $qrToken['pet_id'] = (int) $qrToken['pet_id'];
}
unset($qrToken);

$durationSelect = mobile_api_column_exists($conn, 'appointments', 'duration_minutes')
    ? 'a.duration_minutes'
    : 'NULL AS duration_minutes';
$appointmentUpdatedSelect = mobile_api_column_exists($conn, 'appointments', 'updated_at')
    ? 'a.updated_at'
    : 'NULL AS updated_at';
$appointmentsStmt = mobile_api_prepare(
    $conn,
    "SELECT a.id,a.owner_id,a.pet_id,a.requested_date,a.scheduled_date,a.assigned_vet_id,
            a.confirmation_code,a.reason,a.status,a.admin_notes,a.created_at,
            {$durationSelect},{$appointmentUpdatedSelect},p.name AS pet_name,v.full_name AS vet_name
     FROM appointments a
     JOIN pets p ON p.id=a.pet_id
     LEFT JOIN users v ON v.id=a.assigned_vet_id
     WHERE a.owner_id=?
     ORDER BY COALESCE(a.scheduled_date,a.requested_date) DESC,a.id DESC LIMIT 200"
);
$appointmentsStmt->bind_param('i', $userId);
$appointmentsStmt->execute();
$appointments = mobile_api_all_rows($appointmentsStmt);
foreach ($appointments as &$appointment) {
    $reasonParts = mobile_api_split_appointment_reason($appointment['reason'] ?? '');
    $appointment['id'] = (int) $appointment['id'];
    $appointment['owner_id'] = (int) $appointment['owner_id'];
    $appointment['pet_id'] = (int) $appointment['pet_id'];
    $appointment['assigned_vet_id'] = $appointment['assigned_vet_id'] !== null
        ? (int) $appointment['assigned_vet_id']
        : null;
    $appointment['service_type'] = $reasonParts['service_type'];
    $appointment['serviceType'] = $reasonParts['service_type'];
    $appointment['concern'] = $reasonParts['concern'];
    $appointment['requestedDateTime'] = mobile_api_iso_datetime($appointment['requested_date'] ?? null);
    $appointment['scheduledDateTime'] = mobile_api_iso_datetime($appointment['scheduled_date'] ?? null);
    $appointment['assignedVet'] = $appointment['vet_name'] ?: 'To be assigned';
    $appointment['clinicNote'] = $appointment['admin_notes'] ?? '';
}
unset($appointment);

$vetIdSelect = mobile_api_column_exists($conn, 'medical_records', 'veterinarian_id')
    ? 'mr.veterinarian_id'
    : 'NULL AS veterinarian_id';
$recordAppointmentSelect = mobile_api_column_exists($conn, 'medical_records', 'appointment_id')
    ? 'mr.appointment_id'
    : 'NULL AS appointment_id';
$recordsStmt = mobile_api_prepare(
    $conn,
    "SELECT mr.id,mr.pet_id,mr.veterinarian,mr.visit_date,mr.symptoms,mr.diagnosis,
            mr.treatment,mr.prescription,mr.notes,mr.created_at,
            {$vetIdSelect},{$recordAppointmentSelect},p.name AS pet_name
     FROM medical_records mr
     JOIN pets p ON p.id=mr.pet_id
     WHERE p.owner_id=?
     ORDER BY mr.visit_date DESC,mr.id DESC LIMIT 200"
);
$recordsStmt->bind_param('i', $userId);
$recordsStmt->execute();
$medicalRecords = mobile_api_all_rows($recordsStmt);
foreach ($medicalRecords as &$record) {
    $record['id'] = (int) $record['id'];
    $record['pet_id'] = (int) $record['pet_id'];
    $record['veterinarian_id'] = $record['veterinarian_id'] !== null ? (int) $record['veterinarian_id'] : null;
    $record['appointment_id'] = $record['appointment_id'] !== null ? (int) $record['appointment_id'] : null;
}
unset($record);

$administeredIdSelect = mobile_api_column_exists($conn, 'vaccinations', 'administered_by_id')
    ? 'v.administered_by_id'
    : 'NULL AS administered_by_id';
$vaccinationCreatedSelect = mobile_api_column_exists($conn, 'vaccinations', 'created_at')
    ? 'v.created_at'
    : 'NULL AS created_at';
$vaccinationsStmt = mobile_api_prepare(
    $conn,
    "SELECT v.id,v.pet_id,v.vaccine_name,v.date_given,v.next_due_date,v.administered_by,v.remarks,
            {$administeredIdSelect},{$vaccinationCreatedSelect},p.name AS pet_name
     FROM vaccinations v
     JOIN pets p ON p.id=v.pet_id
     WHERE p.owner_id=?
     ORDER BY COALESCE(v.next_due_date,v.date_given) DESC,v.id DESC LIMIT 200"
);
$vaccinationsStmt->bind_param('i', $userId);
$vaccinationsStmt->execute();
$vaccinationRecords = mobile_api_all_rows($vaccinationsStmt);
foreach ($vaccinationRecords as &$vaccination) {
    $vaccination['id'] = (int) $vaccination['id'];
    $vaccination['pet_id'] = (int) $vaccination['pet_id'];
    $vaccination['administered_by_id'] = $vaccination['administered_by_id'] !== null
        ? (int) $vaccination['administered_by_id']
        : null;
}
unset($vaccination);

require_once __DIR__ . '/../../../includes/product_orders.php';
ensure_product_order_schema($conn);
$products = [];
if (mobile_api_table_exists($conn, 'inventory_items')) {
    $skuSelect = mobile_api_column_exists($conn, 'inventory_items', 'sku')
        ? 'i.sku'
        : 'NULL AS sku';
    $sicknessSelect = mobile_api_column_exists($conn, 'inventory_items', 'sickness')
        ? 'i.sickness'
        : 'NULL AS sickness';
    $photoSelect = mobile_api_column_exists($conn, 'inventory_items', 'product_photo')
        ? 'i.product_photo'
        : 'NULL AS product_photo';
    $priceSelect = mobile_api_column_exists($conn, 'inventory_items', 'sale_price')
        ? 'i.sale_price'
        : '0 AS sale_price';
    $reorderSelect = mobile_api_column_exists($conn, 'inventory_items', 'reorder_level')
        ? 'i.reorder_level'
        : '0 AS reorder_level';
    $updatedSelect = mobile_api_column_exists($conn, 'inventory_items', 'updated_at')
        ? 'i.updated_at'
        : 'NULL AS updated_at';
    $productsStmt = mobile_api_prepare(
        $conn,
        "SELECT i.id,i.item_name,i.category,i.stock_qty,i.unit,i.status,i.created_at,
                {$skuSelect},{$sicknessSelect},{$photoSelect},{$priceSelect},{$reorderSelect},{$updatedSelect}
         FROM inventory_items i
         WHERE i.status<>'inactive'
         ORDER BY i.category ASC,i.item_name ASC,i.id ASC LIMIT 500"
    );
    $productsStmt->execute();
    $products = mobile_api_all_rows($productsStmt);
    foreach ($products as &$product) {
        $product['id'] = (int) $product['id'];
        $product['sale_price'] = (float) $product['sale_price'];
        $product['stock_qty'] = (int) $product['stock_qty'];
        $product['reorder_level'] = (int) $product['reorder_level'];
        $product['product_photo_url'] = mobile_api_media_url($product['product_photo'] ?? null);
    }
    unset($product);
}

$actionUrlSelect = mobile_api_column_exists($conn, 'notifications', 'action_url')
    ? 'n.action_url'
    : 'NULL AS action_url';
$notificationUpdatedSelect = mobile_api_column_exists($conn, 'notifications', 'updated_at')
    ? 'n.updated_at'
    : 'NULL AS updated_at';
$notificationsStmt = mobile_api_prepare(
    $conn,
    "SELECT n.id,n.title,n.message,n.type,n.status,n.created_at,
            {$actionUrlSelect},{$notificationUpdatedSelect}
     FROM notifications n
     WHERE n.user_id=? AND (n.title IS NULL OR n.title NOT LIKE '[Deleted] %')
     ORDER BY n.created_at DESC,n.id DESC LIMIT 200"
);
$notificationsStmt->bind_param('i', $userId);
$notificationsStmt->execute();
$notifications = mobile_api_all_rows($notificationsStmt);
foreach ($notifications as &$notification) {
    $notification['id'] = (int) $notification['id'];
}
unset($notification);

$proofSelect = mobile_api_column_exists($conn, 'edit_requests', 'proof_path')
    ? 'er.proof_path'
    : 'NULL AS proof_path';
$proofNoteSelect = mobile_api_column_exists($conn, 'edit_requests', 'proof_note')
    ? 'er.proof_note'
    : 'NULL AS proof_note';
$vetApprovalSelect = mobile_api_column_exists($conn, 'edit_requests', 'vet_approval_status')
    ? 'er.vet_approval_status'
    : 'NULL AS vet_approval_status';
$appealSelect = mobile_api_column_exists($conn, 'edit_requests', 'appeal_status')
    ? 'er.appeal_status'
    : 'NULL AS appeal_status';
$appealProofSelect = mobile_api_column_exists($conn, 'edit_requests', 'appeal_proof_path')
    ? 'er.appeal_proof_path'
    : 'NULL AS appeal_proof_path';
$editRequestsStmt = mobile_api_prepare(
    $conn,
    "SELECT er.id,er.pet_id,er.field_name,er.old_value,er.new_value,er.status,
            er.admin_notes,er.created_at,er.reviewed_at,
            {$proofSelect},{$proofNoteSelect},{$vetApprovalSelect},{$appealSelect},{$appealProofSelect},
            p.name AS pet_name
     FROM edit_requests er
     JOIN pets p ON p.id=er.pet_id
     WHERE er.client_id=? AND p.owner_id=?
     ORDER BY er.created_at DESC,er.id DESC LIMIT 200"
);
$editRequestsStmt->bind_param('ii', $userId, $userId);
$editRequestsStmt->execute();
$editRequests = mobile_api_all_rows($editRequestsStmt);
foreach ($editRequests as &$editRequest) {
    $editRequest['id'] = (int) $editRequest['id'];
    $editRequest['pet_id'] = (int) $editRequest['pet_id'];
    $editRequest['proof_url'] = mobile_api_media_url($editRequest['proof_path'] ?? null);
    $editRequest['appeal_proof_url'] = mobile_api_media_url($editRequest['appeal_proof_path'] ?? null);
}
unset($editRequest);

$appointmentCounts = ['pending' => 0, 'approved' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($appointments as $appointment) {
    $key = strtolower((string) ($appointment['status'] ?? ''));
    if (array_key_exists($key, $appointmentCounts)) {
        $appointmentCounts[$key]++;
    }
}

mobile_api_success([
    'api_version' => VETRIX_MOBILE_API_VERSION,
    'server_time' => date(DATE_ATOM),
    'profile' => $profile,
    'summary' => [
        'pets' => count($pets),
        'appointments' => count($appointments),
        'appointment_statuses' => $appointmentCounts,
        'medical_records' => count($medicalRecords),
        'vaccinations' => count($vaccinationRecords),
        'products' => count($products),
        'unread_notifications' => mobile_api_unread_count($conn, $userId),
    ],
    'pets' => $pets,
    'qr_tokens' => $qrTokens,
    'appointments' => $appointments,
    'medical_records' => $medicalRecords,
    'vaccination_records' => $vaccinationRecords,
    'products' => $products,
    'orders' => order_list($conn, $userId),
    'payment_options' => order_payment_options($conn),
    'notifications' => $notifications,
    'edit_requests' => $editRequests,
]);
