-- 024_user_name_split_rollback.sql
-- SPEC-NAME-SPLIT-001 rollback companion to migration 023.
--
-- Run ONLY after reverting the corresponding code commit.
-- DATA LOSS: drops first_name, last_name, degrees columns.
-- full_name is preserved (triggers never modified it during backfill;
-- only on subsequent UPDATEs that touched structured columns).
--
-- Coordination: if rollback runs while the new code is still active,
-- every form submit will hit "Unknown column 'first_name'". Revert
-- code FIRST, then run this rollback.
--
-- Source via: sudo mysql --no-defaults grant_review < 024_user_name_split_rollback.sql

DROP TRIGGER IF EXISTS users_compose_full_name_bu;
DROP TRIGGER IF EXISTS users_compose_full_name_bi;

-- Restore original idx_users_search composition
DROP INDEX IF EXISTS idx_users_search ON users;
CREATE INDEX idx_users_search
    ON users(full_name, email, institution, role, is_active);

-- Drop structured columns (irreversible — first_name/last_name/degrees gone)
ALTER TABLE users
    DROP COLUMN IF EXISTS first_name,
    DROP COLUMN IF EXISTS last_name,
    DROP COLUMN IF EXISTS degrees;
