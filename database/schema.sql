-- Grant Review System Database Schema
-- Database: grant_review
-- User: configured via DB_USER environment variable

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS grant_review CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE grant_review;

-- Users table (both admins and reviewers)
-- See SPEC-NAME-SPLIT-001: full_name is a denormalized cache recomputed
-- by triggers from first_name/last_name/degrees on INSERT/UPDATE.
-- Triggers are installed by migration 023 (must run via mysql CLI for
-- DELIMITER support; see 023_user_name_split.sql).
CREATE TABLE IF NOT EXISTS users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    degrees VARCHAR(100) NULL,
    email VARCHAR(255) NOT NULL,
    institution VARCHAR(255) NULL,
    role ENUM('admin', 'reviewer') NOT NULL DEFAULT 'reviewer',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grant types (templates for review criteria)
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

-- Grant sections (scored and non-scored)
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

-- Study sections / program calls
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

-- Grant types assigned to study sections (many-to-many)
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

-- Reviewers assigned to study sections
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

-- Backfill study section grant types from legacy column
INSERT INTO study_section_grant_types (study_section_id, grant_type_id)
SELECT ss.id, ss.grant_type_id
FROM study_sections ss
WHERE ss.grant_type_id IS NOT NULL
ON DUPLICATE KEY UPDATE grant_type_id=VALUES(grant_type_id);

-- Applications table
CREATE TABLE IF NOT EXISTS applications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    applicant_name VARCHAR(255) NOT NULL,
    grant_id VARCHAR(100) NULL,
    application_title TEXT NOT NULL,
    grant_type VARCHAR(150) NOT NULL,
    grant_type_id BIGINT NULL,
    study_section_id BIGINT NULL,
    status ENUM('pending', 'in_review', 'completed') DEFAULT 'pending',
    is_complete BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (grant_type_id) REFERENCES grant_types(id),
    FOREIGN KEY (study_section_id) REFERENCES study_sections(id),
    INDEX idx_grant_type (grant_type),
    INDEX idx_grant_type_id (grant_type_id),
    INDEX idx_study_section (study_section_id),
    INDEX idx_status (status),
    INDEX idx_grant_id (grant_id),
    INDEX idx_applicant_name (applicant_name(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reviewer assignments (maps reviewers to applications)
CREATE TABLE IF NOT EXISTS assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL,
    reviewer_id BIGINT NOT NULL,
    anonymous_label VARCHAR(50) NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_assignment (application_id, reviewer_id),
    INDEX idx_reviewer (reviewer_id),
    INDEX idx_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reviews table
CREATE TABLE IF NOT EXISTS reviews (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL,
    reviewer_id BIGINT NOT NULL,
    overall_impact_score INT NULL,
    overall_impact_explanation TEXT NULL,
    relevance_score INT NULL,
    relevance_explanation TEXT NULL,
    budget_acceptable BOOLEAN NULL,
    budget_modifications TEXT NULL,
    review_date DATE NULL,
    is_final BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_review (application_id, reviewer_id),
    INDEX idx_application (application_id),
    INDEX idx_reviewer (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Review section scores (dynamic by grant type)
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

-- Review criteria scores table
CREATE TABLE IF NOT EXISTS review_criteria_scores (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT NOT NULL,
    criterion_name VARCHAR(100) NOT NULL,
    score TINYINT NOT NULL CHECK (score BETWEEN 1 AND 9),
    strengths TEXT NULL,
    weaknesses TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
    UNIQUE KEY unique_criterion (review_id, criterion_name),
    INDEX idx_review (review_id),
    INDEX idx_criterion (criterion_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Discussion messages table (chat system)
CREATE TABLE IF NOT EXISTS discussion_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    message TEXT NOT NULL,
    is_edited BOOLEAN DEFAULT FALSE,
    edited_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_application (application_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log table (tracks all changes)
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    record_id BIGINT NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    changed_by BIGINT NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    action_type ENUM('insert', 'update', 'delete') NOT NULL,
    FOREIGN KEY (changed_by) REFERENCES users(id),
    INDEX idx_table_record (table_name, record_id),
    INDEX idx_changed_by (changed_by),
    INDEX idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login attempts (rate limiting)
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

-- Uploaded files tracking
CREATE TABLE IF NOT EXISTS uploaded_files (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    uploaded_by BIGINT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed admin row. The structured columns are populated explicitly so the
-- BEFORE INSERT trigger (when present after migration 023) does not need
-- to recompute. On a fresh install where migration 023 has not yet been
-- applied, only full_name takes effect; the structured columns will be
-- populated when 023 backfills.
INSERT INTO users (username, password_hash, full_name, first_name, last_name, degrees, email, role)
VALUES (
    'admin',
    '$2y$10$33Srm6Ze8nHYkGJhATReYefNIVqIJvL4DDFhkERzdz4xoFbyQRI3W',
    'System Administrator',
    'System',
    'Administrator',
    NULL,
    'admin@example.com',
    'admin'
)
ON DUPLICATE KEY UPDATE username=username;

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
ON DUPLICATE KEY UPDATE name=VALUES(name);
