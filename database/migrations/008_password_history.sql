-- TAG-SEC-002.3: Password Policy Enforcement
-- Migration: Create password_history table
--
-- This table tracks historical passwords to prevent reuse
-- Requirements: REQ-SEC-105, REQ-SEC-204
--
-- @version 1.0.0
-- @tag TAG-SEC-002.3

CREATE TABLE IF NOT EXISTS password_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add comment for documentation
ALTER TABLE password_history COMMENT = 'Stores password history for enforcing password reuse policies';
