-- 023_user_name_split.sql
-- SPEC-NAME-SPLIT-001
--
-- Adds structured first_name / last_name / degrees columns to users,
-- backfills from full_name, rebuilds the search index, and installs
-- BEFORE INSERT / BEFORE UPDATE triggers that recompute full_name as
-- a denormalized cache.
--
-- IMPORTANT: source this file ONLY via the mysql CLI client. The
-- DELIMITER directive below is a client-side directive, NOT honored by
-- PDO::exec(), mysqli_multi_query, or arbitrary migration runners.
--   Correct:   sudo mysql --no-defaults grant_review < 023_user_name_split.sql
--   WRONG:     PDO::exec(file_get_contents('023_user_name_split.sql'))
--
-- TRIGGER privilege required. The runtime grant_review user does NOT
-- have CREATE TRIGGER. Run as a privileged DBA via sudo mysql (the
-- localhost root@unix_socket auth path).
--
-- Idempotent: safe to re-run.

-- Step 1: add columns. ALGORITHM=INSTANT is metadata-only on InnoDB
-- (MariaDB 10.3+) regardless of table size.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NULL AFTER full_name,
    ADD COLUMN IF NOT EXISTS last_name  VARCHAR(100) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS degrees    VARCHAR(100) NULL AFTER last_name,
    ALGORITHM=INSTANT;

-- Step 2: backfill structured columns from full_name. Done BEFORE
-- triggers are installed so the bulk UPDATE doesn't fire them per row.
-- WHERE last_name IS NULL keeps it idempotent on re-run.
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

-- Step 3: rebuild idx_users_search to lead with the new sort columns.
-- (Original from migration 019: full_name, email, institution, role, is_active.)
DROP INDEX IF EXISTS idx_users_search ON users;
CREATE INDEX idx_users_search
    ON users(last_name, first_name, email, institution, role, is_active);

-- Step 4: install both triggers. DROP IF EXISTS first for re-runnability.
-- The trigger body uses BEGIN..END so we need DELIMITER //.
DROP TRIGGER IF EXISTS users_compose_full_name_bu;
DROP TRIGGER IF EXISTS users_compose_full_name_bi;

DELIMITER //

CREATE TRIGGER users_compose_full_name_bu BEFORE UPDATE ON users FOR EACH ROW
BEGIN
    DECLARE composed VARCHAR(255);
    -- Fire only when one of the structured columns has actually changed.
    -- <=> is NULL-safe equality; NOT (a <=> b) is the right "differs" check
    -- including NULL <-> value transitions.
    IF (NOT (NEW.first_name <=> OLD.first_name))
       OR (NOT (NEW.last_name  <=> OLD.last_name))
       OR (NOT (NEW.degrees    <=> OLD.degrees))
    THEN
        -- Compose: trim each part, drop empty parts, join with single space,
        -- append ", degrees" if degrees is non-empty.
        SET composed = TRIM(CONCAT_WS(' ',
            NULLIF(TRIM(COALESCE(NEW.first_name, '')), ''),
            NULLIF(TRIM(COALESCE(NEW.last_name,  '')), '')
        ));
        IF TRIM(COALESCE(NEW.degrees, '')) <> '' THEN
            SET composed = CONCAT(composed, ', ', TRIM(NEW.degrees));
        END IF;
        -- Reject the update if the composed value would be empty (NOT NULL
        -- column allows '' silently; we want a hard error instead).
        IF composed = '' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'users.full_name cannot be empty: at least one of first_name or last_name required';
        END IF;
        SET NEW.full_name = composed;
    END IF;
END//

CREATE TRIGGER users_compose_full_name_bi BEFORE INSERT ON users FOR EACH ROW
BEGIN
    DECLARE composed VARCHAR(255);
    -- Only override full_name if at least one of first_name/last_name was
    -- supplied. Legacy INSERTs that pass only full_name (no structured cols)
    -- are not modified, preserving backward compat during rollout.
    IF (NEW.first_name IS NOT NULL OR NEW.last_name IS NOT NULL) THEN
        SET composed = TRIM(CONCAT_WS(' ',
            NULLIF(TRIM(COALESCE(NEW.first_name, '')), ''),
            NULLIF(TRIM(COALESCE(NEW.last_name,  '')), '')
        ));
        IF TRIM(COALESCE(NEW.degrees, '')) <> '' THEN
            SET composed = CONCAT(composed, ', ', TRIM(NEW.degrees));
        END IF;
        IF composed = '' THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'users.full_name cannot be empty: at least one of first_name or last_name required';
        END IF;
        SET NEW.full_name = composed;
    END IF;
END//

DELIMITER ;
