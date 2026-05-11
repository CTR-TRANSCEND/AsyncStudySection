-- Migration 025: Add is_complete column + indexes to assignments
--
-- Brings older deploys in line with fresh-install schema.sql:120, where the
-- column already exists. Without this column, migration 019's
-- idx_assignments_reviewer / idx_assignments_application indexes fail on
-- existing deploys because their composite key references is_complete.
--
-- Idempotent: safe to re-run.
--
-- @version 1.0.0
-- @ref PEND-7 (Session 12)

ALTER TABLE assignments
    ADD COLUMN IF NOT EXISTS is_complete BOOLEAN DEFAULT FALSE AFTER assigned_at;

CREATE INDEX IF NOT EXISTS idx_assignments_reviewer
    ON assignments(reviewer_id, is_complete);

CREATE INDEX IF NOT EXISTS idx_assignments_application
    ON assignments(application_id, is_complete);
