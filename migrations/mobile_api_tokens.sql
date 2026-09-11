-- Vetrix Mobile API bearer tokens
-- Select the Vetrix database before running this migration.

CREATE TABLE IF NOT EXISTS mobile_api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_prefix VARCHAR(16) NOT NULL,
    device_name VARCHAR(120) NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mobile_api_tokens_hash (token_hash),
    KEY idx_mobile_api_tokens_user_active (user_id, revoked_at, expires_at),
    CONSTRAINT fk_mobile_api_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

