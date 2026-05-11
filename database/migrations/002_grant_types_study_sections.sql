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
ON DUPLICATE KEY UPDATE name=name;
