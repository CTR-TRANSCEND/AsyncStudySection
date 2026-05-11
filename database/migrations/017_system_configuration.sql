-- Migration 008: System Configuration Table
-- SPEC: SPEC-ADM-001 Admin Panel Enhancements
-- Feature: Configuration Management UI
-- Created: 2025-01-04

-- Create system_configuration table for dynamic configuration
CREATE TABLE IF NOT EXISTS system_configuration (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) UNIQUE NOT NULL COMMENT 'Unique configuration key',
    config_value TEXT NOT NULL COMMENT 'Configuration value',
    config_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    is_sensitive BOOLEAN DEFAULT FALSE COMMENT 'Mark sensitive configs (passwords, keys)',
    description TEXT NULL COMMENT 'Human-readable description of the config',
    category VARCHAR(50) DEFAULT 'general' COMMENT 'Config category for grouping',
    updated_by BIGINT NULL COMMENT 'User ID who last updated the config',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_config_key (config_key),
    INDEX idx_category (category),
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Dynamic system configuration settings manageable through web UI';

-- Insert default configuration values
INSERT INTO system_configuration (config_key, config_value, config_type, is_sensitive, description, category) VALUES
('app_name', 'Grant Review System', 'string', FALSE, 'Application name displayed in header', 'general'),
('institution_name', 'Research Institution', 'string', FALSE, 'Name of the institution', 'general'),
('session_lifetime', '3600', 'integer', FALSE, 'Session lifetime in seconds', 'security'),
('login_max_attempts', '5', 'integer', FALSE, 'Maximum failed login attempts before lockout', 'security'),
('password_min_length', '8', 'integer', FALSE, 'Minimum password length', 'security'),
('csrf_protection', 'true', 'boolean', FALSE, 'Enable CSRF token validation', 'security'),
('max_upload_size', '10485760', 'integer', FALSE, 'Maximum file upload size in bytes (10MB default)', 'uploads'),
('dashboard_refresh_interval', '30', 'integer', FALSE, 'Dashboard auto-refresh interval in seconds', 'general'),
('health_check_interval', '5', 'integer', FALSE, 'System health check interval in minutes', 'monitoring'),
('disk_usage_warning_threshold', '80', 'integer', FALSE, 'Disk usage warning threshold percentage', 'monitoring'),
('disk_usage_critical_threshold', '90', 'integer', FALSE, 'Disk usage critical threshold percentage', 'monitoring')
ON DUPLICATE KEY UPDATE updated_at = updated_at;
