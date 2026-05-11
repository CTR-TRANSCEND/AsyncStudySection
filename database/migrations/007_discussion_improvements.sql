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

-- Migration complete
-- To rollback this migration, execute 005_discussion_improvements_rollback.sql
