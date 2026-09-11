-- Pet admin verification update
-- Run this on existing databases if you already imported database.sql before this update.

USE vetrix;

ALTER TABLE pets
    ADD COLUMN IF NOT EXISTS pet_photo VARCHAR(255) NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS verification_status ENUM('pending','approved','rejected','in_person_confirmation') DEFAULT 'pending' AFTER pet_photo,
    ADD COLUMN IF NOT EXISTS verification_notes TEXT NULL AFTER verification_status,
    ADD COLUMN IF NOT EXISTS verified_by INT NULL AFTER verification_notes,
    ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP NULL DEFAULT NULL AFTER verified_by;

-- Existing pet records are treated as already approved so old system data still works.
UPDATE pets
SET verification_status='approved', verified_at=COALESCE(updated_at, created_at)
WHERE verification_status IS NULL OR verification_status='pending';

