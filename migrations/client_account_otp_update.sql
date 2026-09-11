-- Client Account Verification + One-Time OTP Update
-- Run this on an existing vetrix database if you do not want to re-import database.sql.
-- Fresh installs already have these tables/columns in database.sql and vetrix.sql.

USE vetrix;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS account_source ENUM('self_registered','admin_created','system_seed') DEFAULT 'self_registered' AFTER role,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER account_source,
    ADD COLUMN IF NOT EXISTS approved_by INT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN IF NOT EXISTS rejected_at DATETIME NULL AFTER approved_at,
    ADD COLUMN IF NOT EXISTS otp_verified_at DATETIME NULL AFTER rejected_at;

CREATE TABLE IF NOT EXISTS account_verification_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    purpose ENUM('account_activation','password_reset') DEFAULT 'account_activation',
    status ENUM('active','used','expired') DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account_verification_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS email_outbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(160) NOT NULL,
    to_name VARCHAR(160),
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    email_type VARCHAR(80) DEFAULT 'system',
    status ENUM('queued','sent','failed') DEFAULT 'queued',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Existing demo/client accounts are marked as already verified so they can still log in.
UPDATE users
SET account_source='system_seed',
    approved_at=COALESCE(approved_at, NOW()),
    otp_verified_at=CASE WHEN role='client' THEN COALESCE(otp_verified_at, NOW()) ELSE otp_verified_at END,
    status=CASE
        WHEN role='client' THEN 'approved'
        WHEN role IN ('admin','veterinarian','staff') THEN 'active'
        ELSE status
    END
WHERE email IN ('admin@vetclinic.test','vet@vetclinic.test','staff@vetclinic.test','client@vetclinic.test','rafael.delacruz@vetclinic.test')
  AND (account_source IS NULL OR account_source='self_registered' OR status IS NULL OR status='');

UPDATE users
SET status='approved', approved_at=COALESCE(approved_at, NOW()), otp_verified_at=COALESCE(otp_verified_at, NOW())
WHERE role='client' AND account_source='system_seed' AND (status IS NULL OR status='' OR status='pending');

