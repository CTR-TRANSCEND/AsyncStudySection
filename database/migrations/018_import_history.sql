-- Migration 009: Import History Table
-- SPEC: SPEC-ADM-001 Admin Panel Enhancements
-- Feature: Enhanced Data Export/Import
-- Created: 2025-01-04

-- Create import_history table for tracking bulk imports
CREATE TABLE IF NOT EXISTS import_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    import_type ENUM('users', 'applications', 'reviews', 'config') NOT NULL,
    file_name VARCHAR(255) NOT NULL COMMENT 'Name of uploaded file',
    file_path VARCHAR(500) NULL COMMENT 'Path to uploaded file',
    record_count INT NOT NULL COMMENT 'Total number of records in import',
    success_count INT DEFAULT 0 COMMENT 'Number of successfully imported records',
    error_count INT DEFAULT 0 COMMENT 'Number of failed records',
    status ENUM('pending', 'completed', 'failed', 'rolled_back') DEFAULT 'pending',
    error_details TEXT NULL COMMENT 'JSON array of error details',
    imported_by BIGINT NOT NULL COMMENT 'User ID who initiated the import',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (imported_by) REFERENCES users(id),
    INDEX idx_import_type (import_type),
    INDEX idx_status (status),
    INDEX idx_imported_by (imported_by),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='History of bulk import operations for audit and rollback';
