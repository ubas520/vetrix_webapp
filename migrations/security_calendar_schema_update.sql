USE vetrix;
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL AFTER address;
ALTER TABLE users ADD COLUMN IF NOT EXISTS emergency_contact_name VARCHAR(120) NULL AFTER emergency_contact;
ALTER TABLE users ADD COLUMN IF NOT EXISTS emergency_contact_phone VARCHAR(40) NULL AFTER emergency_contact_name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER otp_verified_at;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER password_changed_at;
ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at;
ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL AFTER failed_login_attempts;
ALTER TABLE users ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER locked_until;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER scheduled_date;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL AFTER created_at;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS action_url VARCHAR(255) NULL AFTER status;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER action_url;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL AFTER created_at;
CREATE TABLE IF NOT EXISTS staff_availability (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    event_kind ENUM('available','unavailable','clinic_schedule') NOT NULL DEFAULT 'unavailable',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    block_type ENUM('break','lunch','leave','sick','training','personal','on_duty','consultation','other') NOT NULL DEFAULT 'other',
    reason VARCHAR(255) NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    recurrence_group VARCHAR(64) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_staff_availability_time(user_id,starts_at,ends_at)
) ENGINE=InnoDB;

-- Allow staff-created client accounts to remain distinguishable in audit and approval flows.
ALTER TABLE users MODIFY account_source ENUM('self_registered','admin_created','staff_created','system_seed') DEFAULT 'self_registered';

-- SMS/GSM delivery is intentionally excluded until a gateway is integrated.
