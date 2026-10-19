-- Completion links preserve the original vaccination and its due date.
ALTER TABLE vaccinations ADD COLUMN IF NOT EXISTS completed_by_record_id INT NULL;
ALTER TABLE vaccinations ADD COLUMN IF NOT EXISTS recorded_by_id INT NULL;
ALTER TABLE vaccinations ADD COLUMN IF NOT EXISTS confirmation_notification_id INT NULL;
