-- Migration 006: Bulk Operations Table
-- SPEC: SPEC-ADM-001 Admin Panel Enhancements
-- Feature: Bulk User Operations
-- Created: 2025-01-04

-- Create bulk_operations table for tracking bulk administrative actions
CREATE TABLE IF NOT EXISTS bulk_operations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    operation_type ENUM('activate', 'deactivate', 'role_change', 'assign_section', 'password_reset') NOT NULL,
    target_table VARCHAR(50) NOT NULL COMMENT 'Table being operated on (e.g., users, applications)',
    target_ids TEXT NOT NULL COMMENT 'JSON array of record IDs',
    status ENUM('pending', 'in_progress', 'completed', 'failed') DEFAULT 'pending',
    created_by BIGINT NOT NULL COMMENT 'User ID who initiated the operation',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    error_message TEXT NULL COMMENT 'Error details if operation failed',
    results TEXT NULL COMMENT 'JSON summary of operation results',
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_operation_type (operation_type),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks bulk administrative operations for audit and rollback purposes';
