-- Migration 007: Login History Tracking
-- This migration creates table for comprehensive login history

CREATE TABLE IF NOT EXISTS login_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NULL,
    username VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    success BOOLEAN NOT NULL,
    failure_reason VARCHAR(255) NULL,
    country_code VARCHAR(2) NULL,
    city VARCHAR(100) NULL,
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_login_time (user_id, login_time),
    INDEX idx_ip_time (ip_address, login_time),
    INDEX idx_login_time (login_time),
    INDEX idx_success (success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback instructions:
-- DROP TABLE IF EXISTS login_history;
