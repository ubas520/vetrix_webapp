-- Apply once to an existing database. Existing general feedback is preserved.
ALTER TABLE feedback
    ADD COLUMN appointment_id INT NULL AFTER user_id,
    ADD UNIQUE KEY feedback_appointment_unique (appointment_id),
    ADD CONSTRAINT feedback_appointment_fk FOREIGN KEY (appointment_id)
        REFERENCES appointments(id) ON DELETE SET NULL;
