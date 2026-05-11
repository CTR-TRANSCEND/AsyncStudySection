-- ==========================================================================
-- Consolidated Migration File for Docker Initialization
-- Generated from database/migrations/ (001 through 020)
-- ==========================================================================

-- =========================================================================
-- Migration: 001_review_management.sql
-- =========================================================================
-- Migration 001: Review Management Features (SPEC-REV-001)
-- Database: grant_review
-- Description: Adds draft auto-save, template library, and version history
-- Author: TDD Implementer
-- Created: 2025-01-04

-- ============================================================================
-- TABLE 1: review_drafts
-- Purpose: Store auto-saved draft reviews for data loss prevention
-- ============================================================================
CREATE TABLE IF NOT EXISTS review_drafts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    reviewer_id BIGINT NOT NULL,
    application_id BIGINT NOT NULL,
    form_data JSON NOT NULL COMMENT 'Form field values as JSON',
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP DEFAULT (DATE_ADD(NOW(), INTERVAL 7 DAY)) COMMENT 'Auto-delete after 7 days',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,

    INDEX idx_reviewer_app (reviewer_id, application_id),
    INDEX idx_expires (expires_at),
    INDEX idx_reviewer_expires (reviewer_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Auto-saved draft reviews for reviewers';

-- ============================================================================
-- TABLE 2: review_templates
-- Purpose: Store reusable review templates by grant type and section
-- ============================================================================
CREATE TABLE IF NOT EXISTS review_templates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    grant_type_id BIGINT NULL COMMENT 'NULL = applies to all grant types',
    section_id BIGINT NULL COMMENT 'NULL = general template',
    category VARCHAR(100) NULL COMMENT 'e.g., Strengths, Weaknesses, Approach',
    content TEXT NOT NULL COMMENT 'Template content with variable placeholders',
    variables JSON NULL COMMENT 'Available variables as JSON array',
    created_by BIGINT NOT NULL COMMENT 'User ID of template creator',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,

    FOREIGN KEY (grant_type_id) REFERENCES grant_types(id) ON DELETE SET NULL,
    FOREIGN KEY (section_id) REFERENCES grant_sections(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id),

    INDEX idx_grant_type (grant_type_id),
    INDEX idx_section (section_id),
    INDEX idx_active (is_active),
    INDEX idx_category (category),
    INDEX idx_grant_type_active (grant_type_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Reusable review templates for standardization';

-- ============================================================================
-- TABLE 3: review_versions
-- Purpose: Track complete version history for all review modifications
-- ============================================================================
CREATE TABLE IF NOT EXISTS review_versions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT NOT NULL,
    version_number INT NOT NULL COMMENT 'Sequential version for this review',
    overall_impact_score INT NULL,
    relevance_score INT NULL,
    budget_acceptable BOOLEAN NULL,
    overall_impact_explanation TEXT NULL,
    relevance_explanation TEXT NULL,
    budget_modifications TEXT NULL,
    section_data JSON NULL COMMENT 'Complete section scores and comments',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT NOT NULL COMMENT 'User ID who made the change',
    change_summary TEXT NULL COMMENT 'Human-readable summary of changes',

    FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),

    INDEX idx_review_version (review_id, version_number),
    INDEX idx_created_at (created_at),
    INDEX idx_review_id (review_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Version history for review modifications';

-- ============================================================================
-- FULLTEXT INDEXES for Search Functionality
-- ============================================================================
-- Add FULLTEXT indexes for review comment search
ALTER TABLE review_section_scores ADD FULLTEXT INDEX ft_review_sections (summative_comments, strengths, weaknesses);
ALTER TABLE review_criteria_scores ADD FULLTEXT INDEX ft_review_criteria (strengths, weaknesses);
ALTER TABLE reviews ADD FULLTEXT INDEX ft_reviews (overall_impact_explanation, relevance_explanation);

-- ============================================================================
-- SEED DATA: Initial Review Templates
-- ============================================================================
-- Insert common review templates as examples
INSERT INTO review_templates (name, grant_type_id, section_id, category, content, variables, created_by, is_active)
SELECT
    'Approach Strengths Template',
    gt.id,
    gs.id,
    'Strengths',
    'The {{applicant_name}} has developed a rigorous and well-considered approach for {{grant_type}}. The methodology is appropriately designed to address the specific aims of the project. The experimental plan is logical and feasible.',
    JSON_ARRAY('applicant_name', 'grant_type'),
    (SELECT id FROM users WHERE username = 'admin' LIMIT 1),
    TRUE
FROM grant_types gt
CROSS JOIN (
    SELECT id FROM grant_sections WHERE name = 'Approach' LIMIT 1
) gs
WHERE gt.name = 'TRANSCEND Pilot'
ON DUPLICATE KEY UPDATE review_templates.id = review_templates.id;

INSERT INTO review_templates (name, grant_type_id, section_id, category, content, variables, created_by, is_active)
SELECT
    'Innovation Weaknesses Template',
    gt.id,
    gs.id,
    'Weaknesses',
    'While the proposed research is interesting, the level of innovation is limited. The application does not sufficiently differentiate the proposed work from existing studies in the field of {{research_area}}.',
    JSON_ARRAY('research_area'),
    (SELECT id FROM users WHERE username = 'admin' LIMIT 1),
    TRUE
FROM grant_types gt
CROSS JOIN (
    SELECT id FROM grant_sections WHERE name = 'Innovation' LIMIT 1
) gs
WHERE gt.name = 'TRANSCEND Pilot'
ON DUPLICATE KEY UPDATE review_templates.id = review_templates.id;

INSERT INTO review_templates (name, grant_type_id, section_id, category, content, variables, created_by, is_active)
SELECT
    'Significance Strengths Template',
    gt.id,
    gs.id,
    'Strengths',
    'This application addresses a critical gap in our understanding of {{research_topic}}. Successful completion of this work could have significant impact on the field of {{research_area}} and potentially lead to advancements in {{clinical_application}}.',
    JSON_ARRAY('research_topic', 'research_area', 'clinical_application'),
    (SELECT id FROM users WHERE username = 'admin' LIMIT 1),
    TRUE
FROM grant_types gt
CROSS JOIN (
    SELECT id FROM grant_sections WHERE name = 'Significance' LIMIT 1
) gs
WHERE gt.name = 'TRANSCEND Pilot'
ON DUPLICATE KEY UPDATE review_templates.id = review_templates.id;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================
-- Tables created: review_drafts, review_templates, review_versions
-- Indexes added: 3 FULLTEXT indexes for search functionality
-- Seed data: 3 initial review templates for TRANSCEND Pilot
-- ============================================================================

-- =========================================================================
-- Migration: 002_grant_types_study_sections.sql
-- =========================================================================
-- Migration: Add grant types, sections, study sections, and dynamic review sections

CREATE TABLE IF NOT EXISTS grant_types (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) UNIQUE NOT NULL,
    description TEXT NULL,
    url VARCHAR(255) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_grant_type_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grant_sections (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    grant_type_id BIGINT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    is_scored BOOLEAN DEFAULT TRUE,
    is_required BOOLEAN DEFAULT TRUE,
    score_min INT NULL,
    score_max INT NULL,
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (grant_type_id) REFERENCES grant_types(id) ON DELETE CASCADE,
    UNIQUE KEY unique_section (grant_type_id, name),
    INDEX idx_section_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS study_sections (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) UNIQUE NOT NULL,
    description TEXT NULL,
    grant_type_id BIGINT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (grant_type_id) REFERENCES grant_types(id),
    INDEX idx_study_section_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS study_section_reviewers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    study_section_id BIGINT NOT NULL,
    reviewer_id BIGINT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (study_section_id) REFERENCES study_sections(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_reviewer_section (study_section_id, reviewer_id),
    INDEX idx_section_reviewer (study_section_id, reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE applications
    MODIFY grant_type VARCHAR(150) NOT NULL,
    ADD COLUMN grant_type_id BIGINT NULL,
    ADD COLUMN study_section_id BIGINT NULL,
    ADD CONSTRAINT fk_applications_grant_type FOREIGN KEY (grant_type_id) REFERENCES grant_types(id),
    ADD CONSTRAINT fk_applications_study_section FOREIGN KEY (study_section_id) REFERENCES study_sections(id);

ALTER TABLE reviews
    MODIFY overall_impact_score INT NULL,
    MODIFY overall_impact_explanation TEXT NULL,
    MODIFY relevance_score INT NULL,
    MODIFY relevance_explanation TEXT NULL;

CREATE TABLE IF NOT EXISTS review_section_scores (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT NOT NULL,
    grant_section_id BIGINT NOT NULL,
    score INT NULL,
    summative_comments TEXT NULL,
    strengths TEXT NULL,
    weaknesses TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (grant_section_id) REFERENCES grant_sections(id) ON DELETE CASCADE,
    UNIQUE KEY unique_review_section (review_id, grant_section_id),
    INDEX idx_review_section (review_id, grant_section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO grant_types (name, description, url)
VALUES
    ('TRANSCEND Pilot', 'TRANSCEND Pilot grant template', NULL),
    ('TRANSCEND Developmental', 'TRANSCEND Developmental grant template', NULL)
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO grant_sections (grant_type_id, name, description, is_scored, is_required, score_min, score_max, display_order)
SELECT gt.id, gs.name, gs.description, gs.is_scored, gs.is_required, gs.score_min, gs.score_max, gs.display_order
FROM grant_types gt
JOIN (
    SELECT 1 as display_order, 'Overall Impact' as name, NULL as description, 1 as is_scored, 1 as is_required, 1 as score_min, 9 as score_max
    UNION ALL SELECT 2, 'Relevance to RFA', NULL, 1, 1, 1, 9
    UNION ALL SELECT 3, 'Significance', NULL, 1, 1, 1, 9
    UNION ALL SELECT 4, 'Investigator(s)', NULL, 1, 1, 1, 9
    UNION ALL SELECT 5, 'Innovation', NULL, 1, 1, 1, 9
    UNION ALL SELECT 6, 'Approach', NULL, 1, 1, 1, 9
    UNION ALL SELECT 7, 'Environment', NULL, 1, 0, 1, 9
    UNION ALL SELECT 8, 'Mentoring Team/Plan & Pathway to External Funding', NULL, 1, 0, 1, 9
    UNION ALL SELECT 9, 'Budget', NULL, 0, 0, NULL, NULL
    UNION ALL SELECT 10, 'Human Samples', NULL, 0, 0, NULL, NULL
    UNION ALL SELECT 11, 'Animal Samples', NULL, 0, 0, NULL, NULL
) gs ON 1=1
ON DUPLICATE KEY UPDATE review_templates.id = review_templates.id;

-- =========================================================================
-- Migration: 003_flexible_grant_type.sql
-- =========================================================================
-- Migration: Relax grant_type enum and review score range

ALTER TABLE applications
    MODIFY grant_type VARCHAR(150) NOT NULL;

ALTER TABLE reviews
    MODIFY overall_impact_score INT NULL,
    MODIFY relevance_score INT NULL;

-- =========================================================================
-- Migration: 004_login_rate_limit.sql
-- =========================================================================
-- Migration: Add login attempts tracking for rate limiting

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_success BOOLEAN DEFAULT FALSE,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_username_time (username, attempted_at),
    INDEX idx_login_attempts_ip_time (ip_address, attempted_at),
    INDEX idx_login_attempts_success_time (was_success, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Migration: 005_study_section_grant_types.sql
-- =========================================================================
-- Migration: Support multiple grant types per study section

CREATE TABLE IF NOT EXISTS study_section_grant_types (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    study_section_id BIGINT NOT NULL,
    grant_type_id BIGINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (study_section_id) REFERENCES study_sections(id) ON DELETE CASCADE,
    FOREIGN KEY (grant_type_id) REFERENCES grant_types(id) ON DELETE CASCADE,
    UNIQUE KEY unique_section_grant_type (study_section_id, grant_type_id),
    INDEX idx_section (study_section_id),
    INDEX idx_grant_type (grant_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO study_section_grant_types (study_section_id, grant_type_id)
SELECT ss.id, ss.grant_type_id
FROM study_sections ss
WHERE ss.grant_type_id IS NOT NULL
ON DUPLICATE KEY UPDATE grant_type_id=VALUES(grant_type_id);

-- =========================================================================
-- Migration: 006_user_preferences.sql
-- =========================================================================
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

-- =========================================================================
-- Migration: 007_discussion_improvements.sql
-- =========================================================================
-- Migration 005: Discussion System Improvements
-- SPEC: SPEC-DISC-001
-- Description: Add support for rich text, file attachments, search, read/unread tracking, threading, and moderation
-- Version: 1.0.0
-- Date: 2025-01-04

-- Start transaction for atomic migration
START TRANSACTION;

-- Step 1: Add new columns to discussion_messages table
-- Add rich text HTML storage
ALTER TABLE discussion_messages
ADD COLUMN message_html TEXT NULL COMMENT 'Sanitized HTML version of message for rich text display' AFTER message;

-- Add threading support
ALTER TABLE discussion_messages
ADD COLUMN parent_message_id BIGINT NULL COMMENT 'Parent message ID for threaded replies' AFTER message_html,
ADD COLUMN thread_path VARCHAR(255) NULL COMMENT 'Thread path for efficient tree queries (e.g., "1/5/12")' AFTER parent_message_id,
ADD FOREIGN KEY (parent_message_id) REFERENCES discussion_messages(id) ON DELETE CASCADE;

-- Add moderation columns
ALTER TABLE discussion_messages
ADD COLUMN is_deleted BOOLEAN DEFAULT FALSE COMMENT 'Soft delete flag for moderation' AFTER thread_path,
ADD COLUMN deleted_at TIMESTAMP NULL COMMENT 'Timestamp when message was deleted' AFTER is_deleted,
ADD COLUMN deleted_by BIGINT NULL COMMENT 'User ID who deleted the message' AFTER deleted_at,
ADD COLUMN is_pinned BOOLEAN DEFAULT FALSE COMMENT 'Pin flag for important messages' AFTER deleted_by,
ADD COLUMN pinned_at TIMESTAMP NULL COMMENT 'Timestamp when message was pinned' AFTER is_pinned,
ADD COLUMN flag_reason VARCHAR(255) NULL COMMENT 'Reason for flagging content' AFTER pinned_at,
ADD FOREIGN KEY (deleted_by) REFERENCES users(id);

-- Add indexes for performance
CREATE INDEX idx_parent_message ON discussion_messages(parent_message_id);
CREATE INDEX idx_thread_path ON discussion_messages(thread_path);
CREATE INDEX idx_is_deleted ON discussion_messages(is_deleted);
CREATE INDEX idx_is_pinned ON discussion_messages(is_pinned);
CREATE INDEX idx_created_at_app ON discussion_messages(application_id, created_at);

-- Add FULLTEXT index for search (MySQL-specific)
-- Note: FULLTEXT indexes require InnoDB tables and specific MySQL configurations
ALTER TABLE discussion_messages ADD FULLTEXT INDEX idx_message_search (message);

-- Step 2: Update uploaded_files table to support message attachments
ALTER TABLE uploaded_files
ADD COLUMN message_id BIGINT NULL COMMENT 'Link to discussion message if attached to message' AFTER application_id,
ADD FOREIGN KEY (message_id) REFERENCES discussion_messages(id) ON DELETE CASCADE;

-- Step 3: Create discussion_message_reads table for read/unread tracking
CREATE TABLE IF NOT EXISTS discussion_message_reads (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL COMMENT 'User who read the message',
    application_id BIGINT NOT NULL COMMENT 'Application containing the message',
    message_id BIGINT NOT NULL COMMENT 'Message that was read',
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the message was read',

    UNIQUE KEY unique_read (user_id, message_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES discussion_messages(id) ON DELETE CASCADE,

    INDEX idx_user_app (user_id, application_id),
    INDEX idx_message (message_id),
    INDEX idx_read_at (read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks read/unread status of discussion messages per user';

-- Step 4: Create user_notification_preferences table (optional - for future email notifications)
CREATE TABLE IF NOT EXISTS user_notification_preferences (
    user_id BIGINT PRIMARY KEY,
    email_discussion_immediate BOOLEAN DEFAULT FALSE COMMENT 'Immediate email notifications for new messages',
    email_discussion_hourly BOOLEAN DEFAULT FALSE COMMENT 'Hourly digest email notifications',
    email_discussion_daily BOOLEAN DEFAULT TRUE COMMENT 'Daily digest email notifications (default)',
    email_discussion_weekly BOOLEAN DEFAULT FALSE COMMENT 'Weekly digest email notifications',
    vacation_mode BOOLEAN DEFAULT FALSE COMMENT 'Vacation mode suspends all notifications',
    vacation_start DATE NULL COMMENT 'Vacation start date',
    vacation_end DATE NULL COMMENT 'Vacation end date',

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User preferences for discussion notifications';

-- Step 5: Create email_queue table (optional - for future email notifications)
CREATE TABLE IF NOT EXISTS email_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(255) NOT NULL COMMENT 'Email address of recipient',
    recipient_name VARCHAR(255) NULL COMMENT 'Name of recipient',
    subject VARCHAR(500) NOT NULL COMMENT 'Email subject line',
    body_html TEXT NOT NULL COMMENT 'HTML email body',
    body_text TEXT NOT NULL COMMENT 'Plain text email body',
    priority INT DEFAULT 5 COMMENT 'Priority (1=highest, 10=lowest)',
    attempts INT DEFAULT 0 COMMENT 'Number of send attempts',
    last_error TEXT NULL COMMENT 'Last error message if send failed',
    sent_at TIMESTAMP NULL COMMENT 'Timestamp when successfully sent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When email was queued',

    INDEX idx_status (sent_at, attempts),
    INDEX idx_priority (priority, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Queue for sending email notifications';

-- Step 6: Add discussion lock columns to applications table
ALTER TABLE applications
ADD COLUMN discussion_locked BOOLEAN DEFAULT FALSE COMMENT 'Whether discussion is locked for new messages' AFTER status,
ADD COLUMN discussion_locked_at TIMESTAMP NULL COMMENT 'Timestamp when discussion was locked' AFTER discussion_locked,
ADD COLUMN discussion_locked_by BIGINT NULL COMMENT 'User ID who locked the discussion' AFTER discussion_locked_at,
ADD COLUMN discussion_locked_reason TEXT NULL COMMENT 'Reason for locking discussion' AFTER discussion_locked_by,
ADD FOREIGN KEY (discussion_locked_by) REFERENCES users(id);

-- Step 7: Create discussion_exports table for export tracking
CREATE TABLE IF NOT EXISTS discussion_exports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL COMMENT 'Application being exported',
    user_id BIGINT NOT NULL COMMENT 'User requesting export',
    export_format ENUM('pdf', 'csv', 'json', 'html') NOT NULL COMMENT 'Export file format',
    date_range_start DATE NULL COMMENT 'Start date for filtered export',
    date_range_end DATE NULL COMMENT 'End date for filtered export',
    file_path VARCHAR(500) NOT NULL COMMENT 'Path to generated export file',
    file_size INT NULL COMMENT 'Size of export file in bytes',
    include_attachments BOOLEAN DEFAULT FALSE COMMENT 'Whether attachments are included',
    expires_at TIMESTAMP NOT NULL COMMENT 'When export file should be deleted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When export was generated',

    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),

    INDEX idx_application (application_id),
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks discussion export requests and files';

-- Commit transaction
COMMIT;

-- =========================================================================
-- Migration: 008_password_history.sql
-- =========================================================================
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

-- =========================================================================
-- Migration: 009_scheduled_reports.sql
-- =========================================================================
-- Migration 005: Scheduled Reports System
-- SPEC-RPT-001: Reporting and Analytics System
-- Created: 2025-01-04

-- Table: scheduled_reports
-- Stores report schedule configurations for automated report generation
CREATE TABLE IF NOT EXISTS scheduled_reports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Human-readable name for the scheduled report',
    description TEXT NULL COMMENT 'Detailed description of the report purpose',
    created_by BIGINT NOT NULL COMMENT 'User ID who created the schedule',
    report_config JSON NOT NULL COMMENT 'Full report configuration (filters, grouping, format)',
    schedule_type ENUM('once', 'daily', 'weekly', 'monthly', 'quarterly') NOT NULL COMMENT 'Type of schedule',
    schedule_config JSON NOT NULL COMMENT 'Schedule-specific configuration (time, day, timezone)',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Whether the schedule is currently active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the schedule was created',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update time',
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_schedule_type (schedule_type),
    INDEX idx_is_active (is_active),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Scheduled report configurations';

-- Table: report_generation_log
-- Tracks all report generation history including scheduled and manual reports
CREATE TABLE IF NOT EXISTS report_generation_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    scheduled_report_id BIGINT NULL COMMENT 'Reference to scheduled report if generated by schedule',
    report_name VARCHAR(255) NOT NULL COMMENT 'Name of the generated report',
    generated_by BIGINT NULL COMMENT 'User ID who triggered generation (null for automated)',
    file_path VARCHAR(500) NOT NULL COMMENT 'Path to the generated report file',
    file_format VARCHAR(10) NOT NULL COMMENT 'File format (pdf, xlsx, csv, docx)',
    file_size BIGINT NULL COMMENT 'File size in bytes',
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending' COMMENT 'Generation status',
    error_message TEXT NULL COMMENT 'Error details if generation failed',
    generation_time_seconds INT NULL COMMENT 'Time taken to generate report in seconds',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When generation was requested',
    completed_at TIMESTAMP NULL COMMENT 'When generation completed (or failed)',
    FOREIGN KEY (scheduled_report_id) REFERENCES scheduled_reports(id) ON DELETE SET NULL,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_scheduled_report (scheduled_report_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_generated_by (generated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Report generation history log';

-- Table: report_templates
-- Stores custom report builder templates created by administrators
CREATE TABLE IF NOT EXISTS report_templates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Template name',
    description TEXT NULL COMMENT 'Template description',
    created_by BIGINT NOT NULL COMMENT 'User ID who created the template',
    template_config JSON NOT NULL COMMENT 'Template configuration (components, layout, settings)',
    is_public BOOLEAN DEFAULT FALSE COMMENT 'Whether template is visible to other admins',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When template was created',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update time',
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_created_by (created_by),
    INDEX idx_is_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Custom report builder templates';

-- Table: report_cache
-- Caches query results for frequently generated reports to improve performance
CREATE TABLE IF NOT EXISTS report_cache (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(255) NOT NULL UNIQUE COMMENT 'Unique identifier for the cached result',
    cache_data LONGTEXT NOT NULL COMMENT 'Cached data (JSON serialized)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When cache entry was created',
    expires_at TIMESTAMP NOT NULL COMMENT 'When cache entry expires',
    hit_count INT DEFAULT 0 COMMENT 'Number of times this cache was accessed',
    INDEX idx_cache_key (cache_key),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Query result cache for reports';

-- Add indexes for optimized report queries
-- These indexes improve performance for common report queries

-- Index for application status and study section queries
ALTER TABLE applications ADD INDEX idx_report_status_studysection (status, study_section_id, created_at);

-- Index for grant type and status queries
ALTER TABLE applications ADD INDEX idx_report_granttype_status (grant_type_id, status, updated_at);

-- Index for reviewer queries with completion status
ALTER TABLE reviews ADD INDEX idx_report_reviewer_completion (reviewer_id, is_final, created_at);

-- Index for application time-series queries
ALTER TABLE applications ADD INDEX idx_report_timeseries (created_at, status);

-- =========================================================================
-- Migration: 010_auth_mfa_and_lockout.sql
-- =========================================================================
-- Migration 005: MFA and Account Lockout Enhancements
-- This migration adds MFA support and enhanced account lockout features

-- Add MFA columns to users table
ALTER TABLE users
ADD COLUMN mfa_enabled BOOLEAN DEFAULT FALSE,
ADD COLUMN mfa_secret VARCHAR(255) NULL,
ADD COLUMN mfa_backup_codes TEXT NULL;

-- Add account lockout columns to users table
ALTER TABLE users
ADD COLUMN account_locked BOOLEAN DEFAULT FALSE,
ADD COLUMN locked_at TIMESTAMP NULL,
ADD COLUMN locked_until TIMESTAMP NULL,
ADD COLUMN lockout_reason VARCHAR(255) NULL,
ADD COLUMN failed_login_count INT DEFAULT 0;

-- Add indexes for MFA and lockout queries
ALTER TABLE users
ADD INDEX idx_mfa_enabled (mfa_enabled),
ADD INDEX idx_account_locked (account_locked);

-- =========================================================================
-- Migration: 011_auth_password_reset.sql
-- =========================================================================
-- Migration 006: Password Reset System
-- This migration creates tables for password reset functionality

-- Create password_resets table
CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at TIMESTAMP NULL,
    created_by BIGINT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create password_history table
CREATE TABLE IF NOT EXISTS password_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    changed_by BIGINT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id),
    INDEX idx_user_history (user_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Migration: 012_auth_login_history.sql
-- =========================================================================
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

-- =========================================================================
-- Migration: 013_auth_user_sessions.sql
-- =========================================================================
-- Migration 008: User Session Management
-- This migration creates table for session tracking and management

CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    session_id VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    is_remembered BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_sessions (user_id),
    INDEX idx_session_id (session_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Migration: 014_auth_remember_tokens.sql
-- =========================================================================
-- Migration 009: Remember Me Functionality
-- This migration creates table for persistent authentication tokens

CREATE TABLE IF NOT EXISTS remember_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    device_id VARCHAR(255) NULL,
    user_agent TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token_hash (token_hash),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Migration: 015_bulk_operations.sql
-- =========================================================================
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

-- =========================================================================
-- Migration: 016_system_health.sql
-- =========================================================================
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

-- =========================================================================
-- Migration: 017_system_configuration.sql
-- =========================================================================
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

-- =========================================================================
-- Migration: 018_import_history.sql
-- =========================================================================
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

-- =========================================================================
-- Migration: 019_search_optimization.sql
-- =========================================================================
-- Migration 010: Search Performance Optimization Indexes
-- SPEC: SPEC-ADM-001 Admin Panel Enhancements
-- Feature: Advanced Search and Filtering
-- Created: 2025-01-04

-- Applications search optimization
-- Composite index for common search patterns
CREATE INDEX idx_app_search ON applications(applicant_name(100), grant_type_id, study_section_id, status, created_at);
CREATE INDEX idx_app_title_search ON applications(application_title(255));
CREATE INDEX idx_app_grant_id_search ON applications(grant_id);

-- Users search optimization
-- Composite index for user filtering
CREATE INDEX idx_users_search ON users(full_name, email, institution, role, is_active);

-- Audit log timeline optimization
-- Composite index for activity timeline queries
CREATE INDEX idx_audit_timeline ON audit_log(table_name, action_type, changed_at DESC);
CREATE INDEX idx_audit_user_timeline ON audit_log(changed_by, changed_at DESC);

-- Reviews search optimization
-- Index for review score range queries
CREATE INDEX idx_reviews_score ON reviews(application_id, overall_impact_score);

-- Assignments optimization
-- Index for reviewer-study section queries
CREATE INDEX idx_assignments_reviewer ON assignments(reviewer_id, is_complete);
CREATE INDEX idx_assignments_application ON assignments(application_id, is_complete);

-- =========================================================================
-- Migration: 020_create_security_event_log.sql
-- =========================================================================
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

-- =========================================================================
-- Migration: 021_discussion_messages_index.sql
-- =========================================================================
-- Composite index for unread badge queries (CR6-25)

ALTER TABLE discussion_messages
  ADD INDEX idx_dm_user_app_created (user_id, application_id, created_at DESC);

-- =========================================================================
-- Migration: 022_review_section_scores_index.sql
-- =========================================================================
ALTER TABLE review_section_scores
  ADD INDEX idx_rss_review_id (review_id);

-- =========================================================================
-- Migration: 023_user_name_split.sql (DDL + backfill ONLY)
-- =========================================================================
-- SPEC-NAME-SPLIT-001. The DDL and backfill are inlined here for fresh-install
-- runners that source this file. The BEFORE INSERT/UPDATE trigger block lives
-- ONLY in database/migrations/023_user_name_split.sql because it requires the
-- mysql CLI's DELIMITER directive (NOT honored by PDO::exec or
-- mysqli_multi_query). Fresh-install operators must run, AFTER this file:
--   sudo mysql --no-defaults grant_review < database/migrations/023_user_name_split.sql
-- which is idempotent and will skip the already-applied DDL but install the triggers.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NULL AFTER full_name,
    ADD COLUMN IF NOT EXISTS last_name  VARCHAR(100) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS degrees    VARCHAR(100) NULL AFTER last_name,
    ALGORITHM=INSTANT;

UPDATE users SET
    degrees    = IF(LOCATE(',', full_name) > 0,
                    TRIM(SUBSTRING(full_name, LOCATE(',', full_name) + 1)),
                    NULL),
    last_name  = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ',', 1), ' ', -1)),
    first_name = TRIM(
                    SUBSTRING(
                        SUBSTRING_INDEX(full_name, ',', 1),
                        1,
                        LENGTH(SUBSTRING_INDEX(full_name, ',', 1))
                        - LENGTH(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ',', 1), ' ', -1)))
                    )
                 )
WHERE last_name IS NULL;

DROP INDEX IF EXISTS idx_users_search ON users;
CREATE INDEX idx_users_search
    ON users(last_name, first_name, email, institution, role, is_active);

-- =========================================================================
-- Migration: 025_assignments_is_complete.sql
-- =========================================================================
-- PEND-7: Brings older deploys in line with fresh-install schema.sql:120.
-- Idempotent.

ALTER TABLE assignments
    ADD COLUMN IF NOT EXISTS is_complete BOOLEAN DEFAULT FALSE AFTER assigned_at;

CREATE INDEX IF NOT EXISTS idx_assignments_reviewer
    ON assignments(reviewer_id, is_complete);

CREATE INDEX IF NOT EXISTS idx_assignments_application
    ON assignments(application_id, is_complete);
