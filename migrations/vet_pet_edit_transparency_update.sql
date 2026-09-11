-- Pet change transparency now uses the existing audit_logs table.
USE vetrix;
ALTER TABLE audit_logs ADD INDEX IF NOT EXISTS idx_audit_entity (entity_type, entity_id, created_at);
