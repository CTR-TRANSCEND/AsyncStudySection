-- Security Event Log Table
-- SPEC-SEC-001: Security Event Logging System (SEC-003, SEC-108)
--
-- Stores security-relevant events for audit trail and alerting
--
-- @author SPEC-SEC-001 Implementation
-- @version 1.0.0

CREATE TABLE IF NOT EXISTS security_event_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL COMMENT 'Type of security event (e.g., csrf_failure, session_hijack)',
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning' COMMENT 'Event severity level',
    user_id BIGINT NULL COMMENT 'Associated user ID if logged in',
    ip_address VARCHAR(45) NOT NULL COMMENT 'Client IP address (IPv4 or IPv6)',
    user_agent VARCHAR(500) NULL COMMENT 'Client user agent string',
    event_data JSON NULL COMMENT 'Additional event data in JSON format',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Event timestamp',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_event_type (event_type),
    INDEX idx_severity (severity),
    INDEX idx_created_at (created_at),
    INDEX idx_user_id (user_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_event_severity (event_type, severity),
    INDEX idx_time_range (created_at, severity),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Security event audit log';

-- Insert sample security events for testing (optional)
-- INSERT INTO security_event_log (event_type, severity, user_id, ip_address, user_agent, event_data)
-- VALUES
-- ('test_event', 'info', NULL, '127.0.0.1', 'Test Browser', '{"test": true}');
