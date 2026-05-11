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
