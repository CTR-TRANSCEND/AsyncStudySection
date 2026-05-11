-- Migration 021: Add composite index on discussion_messages for unread badge queries
-- Fixes CR6-25: Missing index causes full-scan on every unread badge query

ALTER TABLE discussion_messages
  ADD INDEX idx_dm_user_app_created (user_id, application_id, created_at DESC);
