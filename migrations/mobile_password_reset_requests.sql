CREATE TABLE IF NOT EXISTS mobile_password_reset_requests (
    user_id INT PRIMARY KEY,
    request_key CHAR(32) NOT NULL,
    status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    KEY idx_reset_requests_status (status, requested_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
