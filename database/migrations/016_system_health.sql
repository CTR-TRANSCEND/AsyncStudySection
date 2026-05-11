-- Migration 007: System Health and Alerts Tables
-- SPEC: SPEC-ADM-001 Admin Panel Enhancements
-- Feature: System Health Monitoring
-- Created: 2025-01-04

-- Create system_health_metrics table for historical tracking
CREATE TABLE IF NOT EXISTS system_health_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL COMMENT 'Name of the metric (e.g., disk_usage_uploads)',
    metric_value DECIMAL(15,4) NOT NULL COMMENT 'Metric value',
    metric_unit VARCHAR(50) NULL COMMENT 'Unit of measurement (e.g., MB, %, count)',
    measured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_metric_name_time (metric_name, measured_at),
    INDEX idx_measured_at (measured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Historical system health metrics for monitoring and trend analysis';

-- Create system_alerts table for alert tracking
CREATE TABLE IF NOT EXISTS system_alerts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    alert_type ENUM('warning', 'critical', 'security') NOT NULL,
    alert_message VARCHAR(500) NOT NULL,
    metric_name VARCHAR(100) NULL COMMENT 'Metric that triggered the alert',
    threshold_value DECIMAL(15,4) NULL COMMENT 'Threshold that was exceeded',
    actual_value DECIMAL(15,4) NULL COMMENT 'Actual value that triggered alert',
    is_resolved BOOLEAN DEFAULT FALSE,
    resolved_at TIMESTAMP NULL,
    resolved_by BIGINT NULL COMMENT 'User ID who resolved the alert',
    resolution_note TEXT NULL COMMENT 'Notes on how the alert was resolved',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_is_resolved (is_resolved),
    INDEX idx_alert_type (alert_type),
    INDEX idx_created_at (created_at),
    INDEX idx_metric_name (metric_name),
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='System alerts for threshold breaches and security events';
