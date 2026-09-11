<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method('POST');

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    mobile_api_error(415, 'Pet change requests must be sent as JSON.');
}

$auth = mobile_api_authenticate($conn);
$userId = (int) $auth['id'];
$input = mobile_api_input();

if (!mobile_api_table_exists($conn, 'edit_requests')) {
    mobile_api_error(503, 'Pet change requests are not initialized in the clinic database.');
}

function mobile_api_pet_edit_fields(): array
{
    return [
        'name' => 'Pet Name',
        'species' => 'Species',
        'breed' => 'Breed',
        'sex' => 'Sex',
        'birth_date' => 'Birth Date',
        'weight' => 'Weight',
        'color' => 'Color / Markings',
        'allergies' => 'Allergies',
        'critical_notes' => 'Critical Notes',
        'notes' => 'Care Notes',
    ];
}

function mobile_api_pet_edit_single_line(string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim($value));
    return is_string($normalized) ? $normalized : trim($value);
}

function mobile_api_pet_edit_scalar(mixed $value, string $field, array &$errors): ?string
{
    if (is_bool($value) || !is_scalar($value)) {
        $errors[$field] = 'Enter a valid value.';
        return null;
    }
    return trim((string) $value);
}

function mobile_api_pet_edit_requested_value(
    string $field,
    mixed $value,
    array &$errors
): ?string {
    $raw = mobile_api_pet_edit_scalar($value, $field, $errors);
    if ($raw === null) {
        return null;
    }

    if (in_array($field, ['name', 'breed', 'color'], true)) {
        $raw = mobile_api_pet_edit_single_line($raw);
    }

    if ($field === 'name') {
        if ($raw === '' || mobile_api_text_length($raw) > 100) {
            $errors[$field] = 'Enter a pet name up to 100 characters.';
            return null;
        }
        return $raw;
    }

    if ($field === 'species') {
        $options = [
            'dog' => 'Dog',
            'cat' => 'Cat',
            'rabbit' => 'Rabbit',
            'bird' => 'Bird',
            'other' => 'Other',
        ];
        $normalized = $options[strtolower(mobile_api_pet_edit_single_line($raw))] ?? null;
        if ($normalized === null) {
            $errors[$field] = 'Choose Dog, Cat, Rabbit, Bird, or Other.';
        }
        return $normalized;
    }

    if ($field === 'breed') {
        if ($raw === '' || mobile_api_text_length($raw) > 80) {
            $errors[$field] = 'Enter a breed or mixed breed up to 80 characters.';
            return null;
        }
        return $raw;
    }

    if ($field === 'sex') {
        $options = [
            'male' => 'Male',
            'female' => 'Female',
            'unknown' => 'Unknown',
        ];
        $normalized = $options[strtolower(mobile_api_pet_edit_single_line($raw))] ?? null;
        if ($normalized === null) {
            $errors[$field] = 'Choose Male, Female, or Unknown.';
        }
        return $normalized;
    }

    if ($field === 'birth_date') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $dateErrors = DateTimeImmutable::getLastErrors();
        $valid = $date !== false
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
            && $date->format('Y-m-d') === $raw
            && $date <= new DateTimeImmutable('today');
        if (!$valid) {
            $errors[$field] = 'Choose a valid birthdate that is not in the future.';
            return null;
        }
        return $raw;
    }

    if ($field === 'weight') {
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $raw)) {
            $errors[$field] = 'Enter a weight from 0.01 to 120 kg using up to two decimal places.';
            return null;
        }
        $weight = (float) $raw;
        if ($weight < 0.01 || $weight > 120) {
            $errors[$field] = 'Enter a weight from 0.01 to 120 kg.';
            return null;
        }
        return number_format($weight, 2, '.', '');
    }

    if ($field === 'color') {
        if ($raw === '' || mobile_api_text_length($raw) > 50) {
            $errors[$field] = 'Describe the color or markings using up to 50 characters.';
            return null;
        }
        return $raw;
    }

    if (in_array($field, ['allergies', 'critical_notes', 'notes'], true)) {
        $length = mobile_api_text_length($raw);
        if ($length < 1) {
            $errors[$field] = match ($field) {
                'allergies' => 'List known allergies, or write none.',
                'critical_notes' => 'Add emergency notes, or write none.',
                default => 'Add a care note, or write none.',
            };
            return null;
        }
        if ($length > 5000) {
            $errors[$field] = 'Keep this field under 5,001 characters.';
            return null;
        }
        return $raw;
    }

    $errors[$field] = 'This pet field cannot be changed.';
    return null;
}

function mobile_api_pet_edit_current_value(string $field, mixed $value): string
{
    $current = trim((string) ($value ?? ''));
    if (in_array($field, ['name', 'species', 'breed', 'sex', 'color'], true)) {
        return mobile_api_pet_edit_single_line($current);
    }
    if ($field === 'weight' && is_numeric($current)) {
        return number_format((float) $current, 2, '.', '');
    }
    return $current;
}

function mobile_api_pet_edit_rows(mysqli $conn, array $requestIds): array
{
    if ($requestIds === []) {
        return [];
    }

    $safeIds = array_map('intval', $requestIds);
    $result = $conn->query(
        'SELECT er.id,er.pet_id,er.field_name,er.old_value,er.new_value,er.status,'
        . 'er.admin_notes,er.created_at,er.reviewed_at,p.name AS pet_name '
        . 'FROM edit_requests er JOIN pets p ON p.id=er.pet_id '
        . 'WHERE er.id IN (' . implode(',', $safeIds) . ') ORDER BY er.id ASC'
    );
    if (!($result instanceof mysqli_result)) {
        throw new RuntimeException('The created pet change requests could not be loaded.');
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['pet_id'] = (int) $row['pet_id'];
        $rows[] = $row;
    }
    return $rows;
}

$petId = (int) ($input['pet_id'] ?? $input['petId'] ?? 0);
$source = isset($input['changes']) && is_array($input['changes']) ? $input['changes'] : $input;
$aliases = [
    'name' => ['name'],
    'species' => ['species'],
    'breed' => ['breed'],
    'sex' => ['sex', 'gender'],
    'birth_date' => ['birth_date', 'birthDate'],
    'weight' => ['weight'],
    'color' => ['color'],
    'allergies' => ['allergies'],
    'critical_notes' => ['critical_notes', 'criticalNotes'],
    'notes' => ['notes'],
];

$errors = [];
if ($petId < 1) {
    $errors['pet_id'] = 'Choose an approved pet on your account.';
}
foreach (['pet_photo', 'petPhoto', 'photo', 'photo_uri', 'photoUri'] as $photoKey) {
    if (array_key_exists($photoKey, $source)) {
        $errors['pet_photo'] = 'Pet photo changes are not supported yet.';
        break;
    }
}

$requested = [];
foreach ($aliases as $field => $keys) {
    $found = false;
    $value = null;
    foreach ($keys as $key) {
        if (array_key_exists($key, $source)) {
            $found = true;
            $value = $source[$key];
            break;
        }
    }
    if (!$found) {
        $errors[$field] = 'This pet detail is required.';
        continue;
    }
    $normalized = mobile_api_pet_edit_requested_value($field, $value, $errors);
    if ($normalized !== null) {
        $requested[$field] = $normalized;
    }
}

if ($errors !== []) {
    mobile_api_error(422, 'Check the requested pet changes.', $errors);
}

$editableFields = mobile_api_pet_edit_fields();
$createdIds = [];
$createdRows = [];
$transactionStarted = false;

try {
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('The pet change request transaction could not be started.');
    }
    $transactionStarted = true;

    // Serializing submissions by owner makes the pending-request limit reliable
    // even when the client sends requests for different pets at the same time.
    $ownerLock = mobile_api_prepare($conn, "SELECT id FROM users WHERE id=? AND role='client' FOR UPDATE");
    $ownerLock->bind_param('i', $userId);
    if (!$ownerLock->execute() || !$ownerLock->get_result()->fetch_assoc()) {
        throw new RuntimeException('The pet owner could not be locked for this request.');
    }

    $petStmt = mobile_api_prepare(
        $conn,
        'SELECT id,name,species,breed,sex,birth_date,weight,color,allergies,critical_notes,notes,'
        . 'verification_status FROM pets WHERE id=? AND owner_id=? FOR UPDATE'
    );
    $petStmt->bind_param('ii', $petId, $userId);
    if (!$petStmt->execute()) {
        throw new RuntimeException('The pet profile could not be checked: ' . $petStmt->error);
    }
    $pet = $petStmt->get_result()->fetch_assoc();
    if (!$pet) {
        $conn->rollback();
        $transactionStarted = false;
        mobile_api_error(404, 'The pet profile was not found on your account.');
    }
    if (($pet['verification_status'] ?? '') !== 'approved') {
        $conn->rollback();
        $transactionStarted = false;
        mobile_api_error(
            409,
            'Only an approved pet profile can have changes reviewed.',
            ['pet_id' => 'Wait for the clinic to finish this pet\'s verification first.']
        );
    }

    $actualChanges = [];
    foreach ($requested as $field => $newValue) {
        $oldValue = mobile_api_pet_edit_current_value($field, $pet[$field] ?? null);
        if ($newValue !== $oldValue) {
            $actualChanges[$field] = [
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ];
        }
    }
    if ($actualChanges === []) {
        $conn->rollback();
        $transactionStarted = false;
        mobile_api_error(
            422,
            'No pet changes were found.',
            ['changes' => 'Change at least one pet detail before submitting.']
        );
    }

    $pendingStmt = mobile_api_prepare(
        $conn,
        "SELECT field_name FROM edit_requests WHERE pet_id=? AND client_id=? AND status='pending' FOR UPDATE"
    );
    $pendingStmt->bind_param('ii', $petId, $userId);
    if (!$pendingStmt->execute()) {
        throw new RuntimeException('Pending pet changes could not be checked: ' . $pendingStmt->error);
    }
    $pendingFields = [];
    $pendingResult = $pendingStmt->get_result();
    while ($pending = $pendingResult->fetch_assoc()) {
        $pendingFields[(string) $pending['field_name']] = true;
    }

    $overlapErrors = [];
    foreach (array_keys($actualChanges) as $field) {
        if (isset($pendingFields[$field])) {
            $overlapErrors[$field] = ($editableFields[$field] ?? $field) . ' already has a pending request.';
        }
    }
    if ($overlapErrors !== []) {
        $conn->rollback();
        $transactionStarted = false;
        mobile_api_error(409, 'Some pet details are already waiting for review.', $overlapErrors);
    }

    $pendingCountStmt = mobile_api_prepare(
        $conn,
        "SELECT COUNT(*) AS total FROM edit_requests WHERE client_id=? AND status='pending'"
    );
    $pendingCountStmt->bind_param('i', $userId);
    if (!$pendingCountStmt->execute()) {
        throw new RuntimeException('Pending pet change totals could not be checked: ' . $pendingCountStmt->error);
    }
    $pendingCount = (int) ($pendingCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
    if ($pendingCount + count($actualChanges) > 20) {
        $conn->rollback();
        $transactionStarted = false;
        mobile_api_error(
            409,
            'You have too many pet details waiting for review.',
            ['changes' => 'Wait for the clinic to review an existing request before submitting more changes.']
        );
    }

    $insert = mobile_api_prepare(
        $conn,
        "INSERT INTO edit_requests(pet_id,client_id,field_name,old_value,new_value,status) "
        . "VALUES(?,?,?,?,?,'pending')"
    );
    foreach ($actualChanges as $field => $change) {
        $oldValue = $change['old_value'];
        $newValue = $change['new_value'];
        $insert->bind_param('iisss', $petId, $userId, $field, $oldValue, $newValue);
        if (!$insert->execute()) {
            throw new RuntimeException('A pet change request could not be saved: ' . $insert->error);
        }
        $createdIds[] = (int) $conn->insert_id;
    }

    $fieldLabels = array_map(
        static fn (string $field): string => $editableFields[$field] ?? $field,
        array_keys($actualChanges)
    );
    mobile_api_audit(
        $conn,
        $userId,
        'Client submitted pet edit request through mobile API',
        'pet',
        $petId,
        'Pending fields: ' . implode(', ', $fieldLabels) . '.'
    );
    mobile_api_notify_user(
        $conn,
        $userId,
        'Pet Changes Submitted',
        (string) $pet['name'] . '\'s requested changes are waiting for clinic approval. The current approved profile remains active.',
        'record'
    );

    $adminWhere = "role='admin' AND status IN ('active','approved')";
    if (mobile_api_column_exists($conn, 'users', 'deleted_at')) {
        $adminWhere .= ' AND deleted_at IS NULL';
    }
    $admins = $conn->query("SELECT id FROM users WHERE {$adminWhere}");
    while ($admins instanceof mysqli_result && $admin = $admins->fetch_assoc()) {
        mobile_api_notify_user(
            $conn,
            (int) $admin['id'],
            'Pet Changes Pending',
            (string) $auth['full_name'] . ' requested changes to ' . (string) $pet['name'] . '.',
            'record'
        );
    }

    $createdRows = mobile_api_pet_edit_rows($conn, $createdIds);
    if (!$conn->commit()) {
        throw new RuntimeException('The pet change request transaction could not be committed.');
    }
    $transactionStarted = false;
} catch (Throwable $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    throw $exception;
}

mobile_api_success(
    ['edit_requests' => $createdRows],
    201,
    'Your pet changes were sent to the clinic for approval.'
);
