USE vetrix;

ALTER TABLE appointments
    MODIFY COLUMN status ENUM('pending','approved','completed','rejected','cancelled') NOT NULL DEFAULT 'pending';

ALTER TABLE email_outbox
    ADD COLUMN IF NOT EXISTS user_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS related_type VARCHAR(80) NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS related_id INT NULL AFTER related_type;

SET @email_fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'email_outbox'
      AND CONSTRAINT_NAME = 'fk_email_outbox_user'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @email_fk_sql = IF(
    @email_fk_exists = 0,
    'ALTER TABLE email_outbox ADD CONSTRAINT fk_email_outbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE email_fk_stmt FROM @email_fk_sql;
EXECUTE email_fk_stmt;
DEALLOCATE PREPARE email_fk_stmt;
