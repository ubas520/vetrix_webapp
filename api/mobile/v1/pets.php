<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
mobile_api_require_method('POST');

$auth = mobile_api_authenticate($conn);
$userId = (int) $auth['id'];
$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (!preg_match('/^multipart\/form-data(?:\s*;|$)/', $contentType)) {
    mobile_api_error(415, 'Pet registration must be sent as multipart form data with a pet photo.');
}

function mobile_api_pet_input_value(array $input, string $snakeKey, ?string $camelKey = null): string
{
    $key = array_key_exists($snakeKey, $input)
        ? $snakeKey
        : (($camelKey !== null && array_key_exists($camelKey, $input)) ? $camelKey : $snakeKey);
    return mobile_api_string($input, $key);
}

function mobile_api_pet_single_line(string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim($value));
    return is_string($normalized) ? $normalized : trim($value);
}

function mobile_api_pet_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function mobile_api_pet_photo_candidate(array &$errors): ?array
{
    $upload = $_FILES['pet_photo'] ?? $_FILES['petPhoto'] ?? null;
    if (!is_array($upload)) {
        $errors['pet_photo'] = 'Choose a clear pet photo in JPG, PNG, or WEBP format.';
        return null;
    }

    foreach (['error', 'tmp_name', 'size'] as $key) {
        if (is_array($upload[$key] ?? null)) {
            $errors['pet_photo'] = 'Upload exactly one pet photo.';
            return null;
        }
    }

    $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $errors['pet_photo'] = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Pet photo must not exceed 3 MB.',
            UPLOAD_ERR_PARTIAL => 'The pet photo upload was interrupted. Choose it again.',
            UPLOAD_ERR_NO_FILE => 'Choose a clear pet photo in JPG, PNG, or WEBP format.',
            default => 'The pet photo could not be uploaded. Choose it again.',
        };
        return null;
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || !is_file($temporaryPath)) {
        $errors['pet_photo'] = 'The uploaded pet photo is invalid. Choose it again.';
        return null;
    }

    $reportedSize = (int) ($upload['size'] ?? 0);
    $actualSize = filesize($temporaryPath);
    if ($actualSize === false || $reportedSize < 1 || $actualSize < 1) {
        $errors['pet_photo'] = 'The pet photo is empty. Choose another image.';
        return null;
    }
    if ($reportedSize > 3 * 1024 * 1024 || $actualSize > 3 * 1024 * 1024) {
        $errors['pet_photo'] = 'Pet photo must not exceed 3 MB.';
        return null;
    }

    $detectedMime = null;
    if (function_exists('finfo_open')) {
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($fileInfo !== false) {
            $mimeResult = finfo_file($fileInfo, $temporaryPath);
            finfo_close($fileInfo);
            if (is_string($mimeResult)) {
                $detectedMime = strtolower(trim($mimeResult));
            }
        }
    }
    if (($detectedMime === null || $detectedMime === '') && function_exists('mime_content_type')) {
        $mimeResult = mime_content_type($temporaryPath);
        if (is_string($mimeResult)) {
            $detectedMime = strtolower(trim($mimeResult));
        }
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $imageInfo = @getimagesize($temporaryPath);
    $imageMime = is_array($imageInfo) ? strtolower((string) ($imageInfo['mime'] ?? '')) : '';
    if (
        $detectedMime === null
        || !isset($allowedMimeTypes[$detectedMime])
        || !isset($allowedMimeTypes[$imageMime])
        || !hash_equals($detectedMime, $imageMime)
    ) {
        $errors['pet_photo'] = 'Pet photo must be a valid JPG, PNG, or WEBP image.';
        return null;
    }

    $imageWidth = (int) ($imageInfo[0] ?? 0);
    $imageHeight = (int) ($imageInfo[1] ?? 0);
    if (
        $imageWidth < 1
        || $imageHeight < 1
        || $imageWidth > 8000
        || $imageHeight > 8000
        || ($imageWidth * $imageHeight) > 25000000
    ) {
        $errors['pet_photo'] = 'Pet photo dimensions must not exceed 8,000 pixels per side or 25 megapixels.';
        return null;
    }

    return [
        'temporary_path' => $temporaryPath,
        'extension' => $allowedMimeTypes[$detectedMime],
    ];
}

function mobile_api_store_pet_photo(array $candidate): array
{
    $appRoot = dirname(__DIR__, 3);
    $uploadDirectory = $appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pets';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('The pet photo directory could not be created.');
    }
    if (!is_writable($uploadDirectory)) {
        throw new RuntimeException('The pet photo directory is not writable.');
    }

    do {
        $filename = 'pet_' . time() . '_' . bin2hex(random_bytes(12)) . '.' . $candidate['extension'];
        $absolutePath = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
    } while (is_file($absolutePath));

    if (!move_uploaded_file($candidate['temporary_path'], $absolutePath)) {
        throw new RuntimeException('The pet photo could not be saved.');
    }

    return [
        'absolute_path' => $absolutePath,
        'relative_path' => 'uploads/pets/' . $filename,
    ];
}

function mobile_api_created_pet(mysqli $conn, int $petId, int $userId): array
{
    $stmt = mobile_api_prepare(
        $conn,
        'SELECT id,owner_id,name,species,breed,sex,birth_date,weight,color,allergies,
                critical_notes,notes,pet_photo,verification_status,verification_notes,
                verified_by,verified_at,last_updated_by,created_at,updated_at
         FROM pets WHERE id=? AND owner_id=? LIMIT 1'
    );
    $stmt->bind_param('ii', $petId, $userId);
    if (!$stmt->execute()) {
        throw new RuntimeException('The created pet profile could not be loaded: ' . $stmt->error);
    }
    $pet = $stmt->get_result()->fetch_assoc();
    if (!$pet) {
        throw new RuntimeException('The created pet profile was not found.');
    }

    return [
        'id' => (int) $pet['id'],
        'owner_id' => (int) $pet['owner_id'],
        'name' => (string) $pet['name'],
        'species' => (string) $pet['species'],
        'breed' => (string) $pet['breed'],
        'sex' => (string) $pet['sex'],
        'birth_date' => (string) $pet['birth_date'],
        'weight' => (float) $pet['weight'],
        'color' => (string) $pet['color'],
        'allergies' => (string) $pet['allergies'],
        'critical_notes' => (string) $pet['critical_notes'],
        'notes' => (string) $pet['notes'],
        'pet_photo' => (string) $pet['pet_photo'],
        'pet_photo_url' => mobile_api_media_url($pet['pet_photo'] ?? null),
        'verification_status' => (string) $pet['verification_status'],
        'verification_notes' => $pet['verification_notes'] !== null ? (string) $pet['verification_notes'] : null,
        'verified_by' => $pet['verified_by'] !== null ? (int) $pet['verified_by'] : null,
        'verified_at' => $pet['verified_at'] !== null ? (string) $pet['verified_at'] : null,
        'last_updated_by' => $pet['last_updated_by'] !== null ? (int) $pet['last_updated_by'] : null,
        'created_at' => $pet['created_at'] !== null ? (string) $pet['created_at'] : null,
        'updated_at' => $pet['updated_at'] !== null ? (string) $pet['updated_at'] : null,
        'can_book_appointments' => false,
        'has_active_qr' => false,
    ];
}

$input = mobile_api_input();
$name = mobile_api_pet_single_line(mobile_api_pet_input_value($input, 'name'));
$speciesInput = mobile_api_pet_single_line(mobile_api_pet_input_value($input, 'species'));
$breed = mobile_api_pet_single_line(mobile_api_pet_input_value($input, 'breed'));
$sexInput = mobile_api_pet_single_line(mobile_api_pet_input_value($input, 'sex'));
$birthDate = mobile_api_pet_input_value($input, 'birth_date', 'birthDate');
$weightInput = mobile_api_pet_input_value($input, 'weight');
$color = mobile_api_pet_single_line(mobile_api_pet_input_value($input, 'color'));
$allergies = mobile_api_pet_input_value($input, 'allergies');
$criticalNotes = mobile_api_pet_input_value($input, 'critical_notes', 'criticalNotes');
$notes = mobile_api_pet_input_value($input, 'notes');

$speciesOptions = [
    'dog' => 'Dog',
    'cat' => 'Cat',
    'rabbit' => 'Rabbit',
    'bird' => 'Bird',
    'other' => 'Other',
];
$sexOptions = [
    'male' => 'Male',
    'female' => 'Female',
    'unknown' => 'Unknown',
];
$species = $speciesOptions[strtolower($speciesInput)] ?? '';
$sex = $sexOptions[strtolower($sexInput)] ?? '';

$errors = [];
if ($name === '' || mobile_api_text_length($name) > 100) {
    $errors['name'] = 'Enter a pet name up to 100 characters.';
}
if ($species === '') {
    $errors['species'] = 'Choose Dog, Cat, Rabbit, Bird, or Other.';
}
if ($breed === '' || mobile_api_text_length($breed) > 80) {
    $errors['breed'] = 'Enter a breed or mixed breed up to 80 characters.';
}
if ($sex === '') {
    $errors['sex'] = 'Choose Male, Female, or Unknown.';
}

$parsedBirthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
$birthDateErrors = DateTimeImmutable::getLastErrors();
$birthDateIsValid = $parsedBirthDate !== false
    && ($birthDateErrors === false || ($birthDateErrors['warning_count'] === 0 && $birthDateErrors['error_count'] === 0))
    && $parsedBirthDate->format('Y-m-d') === $birthDate;
if (!$birthDateIsValid || ($parsedBirthDate instanceof DateTimeImmutable && $parsedBirthDate > new DateTimeImmutable('today'))) {
    $errors['birth_date'] = 'Choose a valid birthdate that is not in the future.';
}

$weight = null;
if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $weightInput)) {
    $errors['weight'] = 'Enter a weight from 0.01 to 120 kg using up to two decimal places.';
} else {
    $weight = (float) $weightInput;
    if ($weight < 0.01 || $weight > 120) {
        $errors['weight'] = 'Enter a weight from 0.01 to 120 kg.';
    }
}
if ($color === '' || mobile_api_text_length($color) > 50) {
    $errors['color'] = 'Describe the color or markings using up to 50 characters.';
}

$requiredTextFields = [
    'allergies' => [$allergies, 'List known allergies, or write none.'],
    'critical_notes' => [$criticalNotes, 'Add emergency notes, or write none.'],
    'notes' => [$notes, 'Add a care note, or write none.'],
];
foreach ($requiredTextFields as $field => [$value, $message]) {
    $length = mobile_api_text_length($value);
    if ($length < 1) {
        $errors[$field] = $message;
    } elseif ($length > 5000) {
        $errors[$field] = 'Keep this field under 5,001 characters.';
    }
}

$photoCandidate = mobile_api_pet_photo_candidate($errors);
if ($errors !== []) {
    mobile_api_error(422, 'Check the pet profile details.', $errors);
}
if ($photoCandidate === null || $weight === null) {
    throw new LogicException('Validated pet profile data is incomplete.');
}

$storedPhoto = null;
$transactionStarted = false;
try {
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('The pet registration transaction could not be started.');
    }
    $transactionStarted = true;

    // Locking the owner serializes mobile submissions and closes the double-tap race
    // between the limit/duplicate checks and the insert.
    $ownerLock = mobile_api_prepare($conn, "SELECT id FROM users WHERE id=? AND role='client' FOR UPDATE");
    $ownerLock->bind_param('i', $userId);
    if (!$ownerLock->execute() || !$ownerLock->get_result()->fetch_assoc()) {
        throw new RuntimeException('The pet owner could not be locked for registration.');
    }

    $awaitingCount = mobile_api_prepare(
        $conn,
        "SELECT COUNT(*) AS total FROM pets
         WHERE owner_id=? AND verification_status IN ('pending','in_person_confirmation')"
    );
    $awaitingCount->bind_param('i', $userId);
    if (!$awaitingCount->execute()) {
        throw new RuntimeException('Pending pet submissions could not be checked: ' . $awaitingCount->error);
    }
    if ((int) ($awaitingCount->get_result()->fetch_assoc()['total'] ?? 0) >= 3) {
        throw new DomainException('pending_pet_limit');
    }

    $duplicateCheck = mobile_api_prepare(
        $conn,
        "SELECT id,name,species,verification_status FROM pets
         WHERE owner_id=? AND birth_date=?
           AND verification_status IN ('pending','approved','in_person_confirmation')
         FOR UPDATE"
    );
    $duplicateCheck->bind_param('is', $userId, $birthDate);
    if (!$duplicateCheck->execute()) {
        throw new RuntimeException('Duplicate pet profiles could not be checked: ' . $duplicateCheck->error);
    }
    $normalizedName = mobile_api_pet_lower($name);
    $duplicateRows = $duplicateCheck->get_result();
    while ($existingPet = $duplicateRows->fetch_assoc()) {
        $existingName = mobile_api_pet_lower(mobile_api_pet_single_line((string) $existingPet['name']));
        $existingSpecies = mobile_api_pet_lower(mobile_api_pet_single_line((string) $existingPet['species']));
        if (hash_equals($normalizedName, $existingName) && hash_equals(strtolower($species), $existingSpecies)) {
            throw new DomainException('duplicate_pet');
        }
    }

    $storedPhoto = mobile_api_store_pet_photo($photoCandidate);

    $status = 'pending';
    $insert = mobile_api_prepare(
        $conn,
        "INSERT INTO pets(
            owner_id,name,species,breed,sex,birth_date,weight,color,allergies,critical_notes,
            notes,pet_photo,verification_status,last_updated_by,updated_at
         ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
    );
    $photoPath = $storedPhoto['relative_path'];
    $insert->bind_param(
        'isssssdssssssi',
        $userId,
        $name,
        $species,
        $breed,
        $sex,
        $birthDate,
        $weight,
        $color,
        $allergies,
        $criticalNotes,
        $notes,
        $photoPath,
        $status,
        $userId
    );
    if (!$insert->execute()) {
        throw new RuntimeException('Pet registration failed: ' . $insert->error);
    }
    $petId = (int) $conn->insert_id;

    mobile_api_audit(
        $conn,
        $userId,
        'Client submitted pet for verification through mobile API',
        'pet',
        $petId,
        'Pet profile is pending administrator approval.'
    );
    mobile_api_notify_user(
        $conn,
        $userId,
        'Pet Profile Submitted',
        $name . ' was submitted for clinic verification. Appointments and QR access will unlock after approval.',
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
            'Pet Verification Pending',
            (string) $auth['full_name'] . ' submitted ' . $name . ' for verification.',
            'record'
        );
    }

    $pet = mobile_api_created_pet($conn, $petId, $userId);
    if (!$conn->commit()) {
        throw new RuntimeException('The pet registration transaction could not be committed.');
    }
    $transactionStarted = false;
} catch (DomainException $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    if (is_array($storedPhoto) && is_file($storedPhoto['absolute_path'])) {
        @unlink($storedPhoto['absolute_path']);
    }
    if ($exception->getMessage() === 'pending_pet_limit') {
        mobile_api_error(
            409,
            'You already have three pet profiles waiting for verification.',
            ['pet' => 'Wait for the clinic to review a pending pet before submitting another.']
        );
    }
    if ($exception->getMessage() === 'duplicate_pet') {
        mobile_api_error(
            409,
            'This pet is already registered or waiting for verification.',
            ['name' => 'A pet with the same name, species, and birthdate already exists on your account.']
        );
    }
    throw $exception;
} catch (Throwable $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    if (is_array($storedPhoto) && is_file($storedPhoto['absolute_path'])) {
        @unlink($storedPhoto['absolute_path']);
    }
    throw $exception;
}

mobile_api_success([
    'pet' => $pet,
    'next_step' => 'Wait for clinic approval before booking appointments or using a QR record.',
], 201, 'Your pet profile was submitted for clinic verification.');
