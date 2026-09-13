<?php
declare(strict_types=1);

function appointment_feedback_for_user(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare('SELECT id,appointment_id,rating,comment,created_at FROM feedback WHERE user_id=? AND appointment_id IS NOT NULL');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $feedback = [];
    foreach ($stmt->get_result() as $row) {
        foreach (['id', 'appointment_id', 'rating'] as $key) $row[$key] = (int) $row[$key];
        $feedback[$row['appointment_id']] = $row;
    }
    return $feedback;
}

function appointment_feedback_submit(mysqli $conn, int $userId, array $input): array
{
    $appointmentId = $input['appointment_id'] ?? null;
    $rating = $input['rating'] ?? null;
    $comment = $input['comment'] ?? '';
    if (!is_int($appointmentId) || $appointmentId < 1) {
        throw new DomainException('Choose a valid appointment.', 422);
    }
    if (!is_int($rating) || $rating < 1 || $rating > 5) {
        throw new DomainException('Choose a rating from 1 to 5 stars.', 422);
    }
    if (!is_string($comment) || !preg_match('//u', $comment)) {
        throw new DomainException('Enter a valid comment.', 422);
    }
    $comment = trim($comment);
    $length = function_exists('mb_strlen') ? mb_strlen($comment, 'UTF-8') : preg_match_all('/./us', $comment);
    if ($length > 1000) throw new DomainException('Keep your comment to 1,000 characters or fewer.', 422);

    $conn->begin_transaction();
    try {
        // Serialize submissions for this visit, including concurrent retries.
        $stmt = $conn->prepare('SELECT status FROM appointments WHERE id=? AND owner_id=? FOR UPDATE');
        $stmt->bind_param('ii', $appointmentId, $userId);
        $stmt->execute();
        $appointment = $stmt->get_result()->fetch_assoc();
        if (!$appointment) throw new DomainException('The appointment was not found.', 404);
        if ($appointment['status'] !== 'completed') {
            throw new DomainException('Only completed appointments can be rated.', 409);
        }
        $stmt = $conn->prepare('SELECT id,appointment_id,rating,comment,created_at FROM feedback WHERE appointment_id=? AND user_id=?');
        $stmt->bind_param('ii', $appointmentId, $userId);
        $stmt->execute();
        $feedback = $stmt->get_result()->fetch_assoc();
        if ($feedback) {
            // A retry after a lost response returns the saved rating without another insert.
            if ((int) $feedback['rating'] !== $rating || $feedback['comment'] !== $comment) {
                throw new DomainException('You have already rated this visit. Refresh your appointments to see your rating.', 409);
            }
        } else {
            $stmt = $conn->prepare('INSERT INTO feedback(user_id,appointment_id,rating,comment) VALUES(?,?,?,?)');
            $stmt->bind_param('iiis', $userId, $appointmentId, $rating, $comment);
            $stmt->execute();
            $feedback = appointment_feedback_for_user($conn, $userId)[$appointmentId];
        }
        foreach (['id', 'appointment_id', 'rating'] as $key) $feedback[$key] = (int) $feedback[$key];
        $conn->commit();
        return $feedback;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
