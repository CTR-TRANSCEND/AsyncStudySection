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
ON DUPLICATE KEY UPDATE name=name;

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
ON DUPLICATE KEY UPDATE name=name;

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
ON DUPLICATE KEY UPDATE name=name;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================
-- Tables created: review_drafts, review_templates, review_versions
-- Indexes added: 3 FULLTEXT indexes for search functionality
-- Seed data: 3 initial review templates for TRANSCEND Pilot
-- ============================================================================
