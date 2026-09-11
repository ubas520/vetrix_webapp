CREATE TABLE IF NOT EXISTS mobile_password_resets (
    user_id INT PRIMARY KEY,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    sent_at DATETIME NOT NULL,
    window_started_at DATETIME NOT NULL,
    send_count INT NOT NULL DEFAULT 1,
    attempts INT NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
