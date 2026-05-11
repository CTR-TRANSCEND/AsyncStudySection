-- Migration 005: MFA and Account Lockout Enhancements
-- This migration adds MFA support and enhanced account lockout features

-- Add MFA columns to users table
ALTER TABLE users
ADD COLUMN mfa_enabled BOOLEAN DEFAULT FALSE,
ADD COLUMN mfa_secret VARCHAR(255) NULL,
ADD COLUMN mfa_backup_codes TEXT NULL;

-- Add account lockout columns to users table
ALTER TABLE users
ADD COLUMN account_locked BOOLEAN DEFAULT FALSE,
ADD COLUMN locked_at TIMESTAMP NULL,
ADD COLUMN locked_until TIMESTAMP NULL,
ADD COLUMN lockout_reason VARCHAR(255) NULL,
ADD COLUMN failed_login_count INT DEFAULT 0;

-- Add indexes for MFA and lockout queries
ALTER TABLE users
ADD INDEX idx_mfa_enabled (mfa_enabled),
ADD INDEX idx_account_locked (account_locked);

-- Rollback instructions:
-- ALTER TABLE users DROP COLUMN mfa_enabled, DROP COLUMN mfa_secret, DROP COLUMN mfa_backup_codes, DROP COLUMN account_locked, DROP COLUMN locked_at, DROP COLUMN locked_until, DROP COLUMN lockout_reason, DROP COLUMN failed_login_count;
-- ALTER TABLE users DROP INDEX idx_mfa_enabled, DROP INDEX idx_account_locked;
