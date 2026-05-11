-- Migration: Relax grant_type enum and review score range

ALTER TABLE applications
    MODIFY grant_type VARCHAR(150) NOT NULL;

ALTER TABLE reviews
    MODIFY overall_impact_score INT NULL,
    MODIFY relevance_score INT NULL;
