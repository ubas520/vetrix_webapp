<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
$method = mobile_api_require_method(['POST', 'PATCH']);
$auth = mobile_api_authenticate($conn);
$userId = (int) $auth['id'];
$input = mobile_api_input();
$action = strtolower(mobile_api_string($input, 'action', $method === 'PATCH' ? 'cancel' : 'create'));

function mobile_api_appointment_reason_parts(?string $reason): array
{
    $reason = trim((string) $reason);
    $parts = explode(' — ', $reason, 2);
    if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
        return ['service_type' => trim($parts[0]), 'concern' => trim($parts[1])];
    }
    return ['service_type' => 'Clinic Visit', 'concern' => $reason];
}

function mobile_api_appointment_by_id(mysqli $conn, int $appointmentId, int $userId): ?array
{
    $updatedSelect = mobile_api_column_exists($conn, 'appointments', 'updated_at')
        ? 'a.updated_at'
        : 'NULL AS updated_at';
    $durationSelect = mobile_api_column_exists($conn, 'appointments', 'duration_minutes')
        ? 'a.duration_minutes'
        : 'NULL AS duration_minutes';
    $stmt = mobile_api_prepare(
        $conn,
        "SELECT a.id,a.owner_id,a.pet_id,a.requested_date,a.scheduled_date,a.assigned_vet_id,
                a.confirmation_code,a.reason,a.status,a.admin_notes,a.created_at,
                {$updatedSelect},{$durationSelect},p.name AS pet_name,v.full_name AS vet_name
         FROM appointments a
         JOIN pets p ON p.id=a.pet_id
         LEFT JOIN users v ON v.id=a.assigned_vet_id
         WHERE a.id=? AND a.owner_id=? LIMIT 1"
    );
    $stmt->bind_param('ii', $appointmentId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }

    $parts = mobile_api_appointment_reason_parts($row['reason'] ?? '');
    $row['id'] = (int) $row['id'];
    $row['owner_id'] = (int) $row['owner_id'];
    $row['pet_id'] = (int) $row['pet_id'];
    $row['assigned_vet_id'] = $row['assigned_vet_id'] !== null ? (int) $row['assigned_vet_id'] : null;
    $row['service_type'] = $parts['service_type'];
    $row['serviceType'] = $parts['service_type'];
    $row['concern'] = $parts['concern'];
    $row['requestedDateTime'] = !empty($row['requested_date'])
        ? date(DATE_ATOM, strtotime((string) $row['requested_date']))
        : null;
    $row['scheduledDateTime'] = !empty($row['scheduled_date'])
        ? date(DATE_ATOM, strtotime((string) $row['scheduled_date']))
        : null;
    $row['assignedVet'] = $row['vet_name'] ?: 'To be assigned';
    $row['clinicNote'] = $row['admin_notes'] ?? '';
    return $row;
}

if ($action === 'cancel') {
    $appointmentId = (int) (
        $input['id']
        ?? $input['appointment_id']
        ?? $input['appointmentId']
        ?? $_GET['id']
        ?? 0
    );
    if ($appointmentId < 1) {
        mobile_api_error(422, 'Choose an appointment to cancel.', ['appointment_id' => 'Appointment ID is required.']);
    }

    $appointment = mobile_api_appointment_by_id($conn, $appointmentId, $userId);
    if (!$appointment) {
        mobile_api_error(404, 'The appointment was not found.');
    }
    if (!in_array((string) $appointment['status'], ['pending', 'approved'], true)) {
        mobile_api_error(409, 'Only pending or approved appointments can be cancelled.');
    }

    $set = "status='cancelled'";
    if (mobile_api_column_exists($conn, 'appointments', 'updated_at')) {
        $set .= ',updated_at=NOW()';
    }

    $conn->begin_transaction();
    try {
        $update = mobile_api_prepare(
            $conn,
            "UPDATE appointments SET {$set} WHERE id=? AND owner_id=? AND status IN ('pending','approved')"
        );
        $update->bind_param('ii', $appointmentId, $userId);
        $update->execute();
        if ($update->affected_rows !== 1) {
            throw new RuntimeException('Appointment cancellation was not applied.');
        }
        mobile_api_audit(
            $conn,
            $userId,
            'Client cancelled appointment through mobile API',
            'appointment',
            $appointmentId,
            'Appointment status changed to cancelled.'
        );
        mobile_api_notify_user(
            $conn,
            $userId,
            'Appointment Cancelled',
            'Your appointment request for ' . (string) $appointment['pet_name'] . ' was cancelled.',
            'appointment'
        );
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    mobile_api_success(
        ['appointment' => mobile_api_appointment_by_id($conn, $appointmentId, $userId)],
        200,
        'The appointment was cancelled.'
    );
}

if ($action !== 'create') {
    mobile_api_error(400, 'Unknown appointment action.');
}

$petId = (int) ($input['pet_id'] ?? $input['petId'] ?? 0);
$serviceType = mobile_api_string(
    $input,
    isset($input['service_type']) ? 'service_type' : (isset($input['serviceType']) ? 'serviceType' : 'service'),
    'Clinic Visit'
);
$concern = mobile_api_string($input, isset($input['concern']) ? 'concern' : 'reason');
$requestedRaw = mobile_api_string(
    $input,
    isset($input['requested_date'])
        ? 'requested_date'
        : (isset($input['requestedDateTime']) ? 'requestedDateTime' : 'requested_date_time')
);

if ($requestedRaw === '') {
    $preferredDate = mobile_api_string($input, isset($input['preferred_date']) ? 'preferred_date' : 'preferredDate');
    $preferredTime = mobile_api_string($input, isset($input['preferred_time']) ? 'preferred_time' : 'preferredTime');
    if ($preferredDate !== '' && $preferredTime !== '') {
        try {
            $datePart = (new DateTimeImmutable($preferredDate))->format('Y-m-d');
            $timePart = (new DateTimeImmutable($preferredTime))->format('H:i:s');
            $requestedRaw = $datePart . ' ' . $timePart;
        } catch (Throwable) {
            $requestedRaw = '';
        }
    }
}

$requestedDate = mobile_api_datetime($requestedRaw);
$errors = [];
if ($petId < 1) {
    $errors['pet_id'] = 'Choose one of your approved pets.';
}
if ($serviceType === '' || mobile_api_text_length($serviceType) > 80) {
    $errors['service_type'] = 'Choose a service name up to 80 characters.';
}
if ($concern === '' || mobile_api_text_length($concern) > 1000) {
    $errors['concern'] = 'Describe the concern using 1 to 1,000 characters.';
}
if ($requestedDate === null || strtotime($requestedDate) <= time()) {
    $errors['requested_date'] = 'Choose a valid future date and time.';
}
if ($errors !== []) {
    mobile_api_error(422, 'Check the appointment details.', $errors);
}

$petStmt = mobile_api_prepare(
    $conn,
    "SELECT id,name FROM pets WHERE id=? AND owner_id=? AND verification_status='approved' LIMIT 1"
);
$petStmt->bind_param('ii', $petId, $userId);
$petStmt->execute();
$pet = $petStmt->get_result()->fetch_assoc();
if (!$pet) {
    mobile_api_error(422, 'Only an approved pet on your account can be booked.');
}

$conflict = mobile_api_prepare(
    $conn,
    "SELECT id FROM appointments
     WHERE status IN ('pending','approved')
       AND requested_date BETWEEN DATE_SUB(?,INTERVAL 30 MINUTE) AND DATE_ADD(?,INTERVAL 30 MINUTE)
     LIMIT 1"
);
$conflict->bind_param('ss', $requestedDate, $requestedDate);
$conflict->execute();
if ($conflict->get_result()->fetch_assoc()) {
    mobile_api_error(409, 'That time is close to another appointment. Choose another time.');
}

$storedReason = $serviceType . ' — ' . $concern;
$conn->begin_transaction();
try {
    $insert = mobile_api_prepare(
        $conn,
        "INSERT INTO appointments(owner_id,pet_id,requested_date,reason,status) VALUES(?,?,?,?,'pending')"
    );
    $insert->bind_param('iiss', $userId, $petId, $requestedDate, $storedReason);
    $insert->execute();
    $appointmentId = (int) $conn->insert_id;

    mobile_api_audit(
        $conn,
        $userId,
        'Client requested appointment through mobile API',
        'appointment',
        $appointmentId,
        'Appointment request submitted.'
    );
    mobile_api_notify_user(
        $conn,
        $userId,
        'Appointment Request Sent',
        'Your appointment request for ' . (string) $pet['name'] . ' is waiting for clinic approval.',
        'appointment'
    );
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

mobile_api_success(
    ['appointment' => mobile_api_appointment_by_id($conn, $appointmentId, $userId)],
    201,
    'Your appointment request was sent to the clinic.'
);

