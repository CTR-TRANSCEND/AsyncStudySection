-- Migration 005: User Preferences Table
-- SPEC: SPEC-ADM-001 Admin Panel Enhancements
-- Feature: Dashboard Customization
-- Created: 2025-01-04

-- Create user_preferences table for storing user-specific settings
CREATE TABLE IF NOT EXISTS user_preferences (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    preference_key VARCHAR(100) NOT NULL,
    preference_value TEXT NOT NULL COMMENT 'JSON data for flexible preference storage',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_preference (user_id, preference_key),
    INDEX idx_user (user_id),
    INDEX idx_key (preference_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores user-specific preferences including dashboard layout, widget visibility, and custom settings';

-- Insert default dashboard layout for existing admin users
INSERT IGNORE INTO user_preferences (user_id, preference_key, preference_value)
SELECT u.id, 'dashboard_layout', '{"widgets":["stats","recent_apps","activity"],"order":[0,1,2]}'
FROM users u
WHERE u.role = 'admin'
AND NOT EXISTS (
    SELECT 1 FROM user_preferences up
    WHERE up.user_id = u.id AND up.preference_key = 'dashboard_layout'
);

-- Insert default widget visibility for existing admin users
INSERT IGNORE INTO user_preferences (user_id, preference_key, preference_value)
SELECT u.id, 'widget_visibility', '{"stats":true,"recent_apps":true,"activity":true}'
FROM users u
WHERE u.role = 'admin'
AND NOT EXISTS (
    SELECT 1 FROM user_preferences up
    WHERE up.user_id = u.id AND up.preference_key = 'widget_visibility'
);
