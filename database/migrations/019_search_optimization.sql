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
