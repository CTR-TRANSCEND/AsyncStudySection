-- Test Suite for Migration 005: Discussion System Improvements
-- SPEC: SPEC-DISC-001
-- Description: Tests to verify migration success and rollback
-- Version: 1.0.0
-- Date: 2025-01-04

-- Test 1: Verify new columns in discussion_messages
-- Expected: All new columns should exist
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'discussion_messages'
  AND COLUMN_NAME IN (
    'message_html',
    'parent_message_id',
    'thread_path',
    'is_deleted',
    'deleted_at',
    'deleted_by',
    'is_pinned',
    'pinned_at',
    'flag_reason'
  )
ORDER BY ORDINAL_POSITION;

-- Expected result: 9 rows with correct data types

-- Test 2: Verify foreign keys on discussion_messages
-- Expected: parent_message_id and deleted_by should have foreign keys
SELECT
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'discussion_messages'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Expected result: Foreign keys to discussion_messages (parent_message_id) and users (deleted_by)

-- Test 3: Verify indexes on discussion_messages
-- Expected: All performance indexes should exist
SHOW INDEX FROM discussion_messages WHERE Key_name IN (
    'idx_parent_message',
    'idx_thread_path',
    'idx_is_deleted',
    'idx_is_pinned',
    'idx_created_at_app',
    'idx_message_search'
);

-- Expected result: 6 indexes

-- Test 4: Verify message_id column in uploaded_files
-- Expected: message_id column should exist with foreign key
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'uploaded_files'
  AND COLUMN_NAME = 'message_id';

-- Expected result: 1 row, BIGINT, nullable

-- Test 5: Verify discussion_message_reads table exists
-- Expected: Table should exist with correct structure
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_KEY
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'discussion_message_reads'
ORDER BY ORDINAL_POSITION;

-- Expected result: 5 columns (id, user_id, application_id, message_id, read_at)

-- Test 6: Verify unique constraint on discussion_message_reads
-- Expected: unique_read constraint should exist
SELECT
    CONSTRAINT_NAME,
    CONSTRAINT_TYPE
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'discussion_message_reads'
  AND CONSTRAINT_TYPE = 'UNIQUE';

-- Expected result: 1 row for unique_read

-- Test 7: Verify user_notification_preferences table exists
-- Expected: Table should exist with correct structure
SELECT COUNT(*) as column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'user_notification_preferences';

-- Expected result: 8 columns

-- Test 8: Verify email_queue table exists
-- Expected: Table should exist with correct structure
SELECT COUNT(*) as column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'email_queue';

-- Expected result: 10 columns

-- Test 9: Verify discussion lock columns in applications table
-- Expected: All lock columns should exist
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'applications'
  AND COLUMN_NAME LIKE 'discussion_locked%';

-- Expected result: 4 rows

-- Test 10: Verify discussion_exports table exists
-- Expected: Table should exist with correct structure
SELECT COUNT(*) as column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'discussion_exports';

-- Expected result: 10 columns

-- Test 11: Verify data integrity after migration
-- Expected: Existing data should be preserved
SELECT COUNT(*) as existing_messages
FROM discussion_messages;

-- Expected result: Count should match pre-migration count

-- Test 12: Verify thread path functionality
-- Expected: Should be able to insert threaded message
INSERT INTO discussion_messages (
    application_id,
    user_id,
    message,
    parent_message_id,
    thread_path
) VALUES (
    (SELECT MIN(id) FROM applications),
    (SELECT MIN(id) FROM users WHERE role = 'reviewer'),
    'Test reply message',
    (SELECT MIN(id) FROM discussion_messages),
    CONCAT((SELECT MIN(id) FROM discussion_messages), '/', (SELECT MIN(id) FROM discussion_messages) + 1000)
);

-- Expected result: Insert should succeed, last_insert_id should be set

-- Test 13: Verify soft delete functionality
-- Expected: Should be able to soft delete a message
UPDATE discussion_messages
SET is_deleted = TRUE,
    deleted_at = NOW(),
    deleted_by = (SELECT MIN(id) FROM users WHERE role = 'admin')
WHERE id = (SELECT MIN(id) FROM discussion_messages WHERE is_deleted = FALSE);

-- Expected result: 1 row affected

-- Test 14: Verify pin functionality
-- Expected: Should be able to pin a message
UPDATE discussion_messages
SET is_pinned = TRUE,
    pinned_at = NOW()
WHERE id = (SELECT MIN(id) FROM discussion_messages WHERE is_pinned = FALSE);

-- Expected result: 1 row affected

-- Test 15: Verify read tracking functionality
-- Expected: Should be able to mark message as read
INSERT INTO discussion_message_reads (user_id, application_id, message_id)
VALUES (
    (SELECT MIN(id) FROM users WHERE role = 'reviewer'),
    (SELECT MIN(id) FROM applications),
    (SELECT MIN(id) FROM discussion_messages)
);

-- Expected result: Insert should succeed, duplicate insert should fail

-- Test 16: Verify FULLTEXT index
-- Expected: Should be able to search messages
SELECT COUNT(*) as search_results
FROM discussion_messages
WHERE MATCH(message) AGAINST('test' IN NATURAL LANGUAGE MODE);

-- Expected result: Should return count of matching messages

-- All tests complete
-- Run this file after migration to verify success
-- To rollback, execute 005_discussion_improvements_rollback.sql
