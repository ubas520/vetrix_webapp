-- Vetrix complete workflow update for existing installations
USE vetrix;

ALTER TABLE inventory_items
    ADD COLUMN IF NOT EXISTS product_photo VARCHAR(255) NULL AFTER category;

ALTER TABLE edit_requests
    ADD COLUMN IF NOT EXISTS proof_path VARCHAR(255) NULL AFTER new_value,
    ADD COLUMN IF NOT EXISTS proof_note TEXT NULL AFTER proof_path,
    ADD COLUMN IF NOT EXISTS vet_approval_status VARCHAR(30) NOT NULL DEFAULT 'not_required' AFTER proof_note,
    ADD COLUMN IF NOT EXISTS vet_approved_by INT NULL AFTER vet_approval_status,
    ADD COLUMN IF NOT EXISTS vet_approved_at DATETIME NULL AFTER vet_approved_by,
    ADD COLUMN IF NOT EXISTS appeal_status VARCHAR(30) NOT NULL DEFAULT 'none' AFTER vet_approved_at,
    ADD COLUMN IF NOT EXISTS appeal_note TEXT NULL AFTER appeal_status,
    ADD COLUMN IF NOT EXISTS appeal_proof_path VARCHAR(255) NULL AFTER appeal_note,
    ADD COLUMN IF NOT EXISTS appealed_at DATETIME NULL AFTER appeal_proof_path;

SET @vet_edit_fk_exists=(SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='edit_requests' AND CONSTRAINT_NAME='edit_requests_ibfk_3' AND CONSTRAINT_TYPE='FOREIGN KEY');
SET @vet_edit_fk_sql=IF(@vet_edit_fk_exists=0,'ALTER TABLE edit_requests ADD CONSTRAINT edit_requests_ibfk_3 FOREIGN KEY (vet_approved_by) REFERENCES users(id) ON DELETE SET NULL','SELECT 1');
PREPARE vet_edit_stmt FROM @vet_edit_fk_sql; EXECUTE vet_edit_stmt; DEALLOCATE PREPARE vet_edit_stmt;

-- Vaccination reminder history is recorded in audit_logs; no extra reminder table is required.

UPDATE edit_requests SET vet_approval_status='pending' WHERE field_name='allergies' AND status='pending' AND vet_approval_status='not_required';
