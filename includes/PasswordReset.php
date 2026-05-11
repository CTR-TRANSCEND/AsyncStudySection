<?php
/**
 * Password Reset Management Class
 * SPEC-AUTH-001.2: Password Reset System
 *
 * Features:
 * - Admin-initiated password reset workflow
 * - Secure token generation and validation
 * - Password history tracking
 * - Password strength validation
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Manages admin-initiated password reset tokens and password history tracking.
 */
class PasswordReset {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Create a password reset request
     * @param int $userId User ID requesting reset
     * @param int $createdBy Admin user ID creating the request
     * @return array|false Reset token info or false on failure
     */
    public function createResetRequest(int $userId, int $createdBy) {
        try {
            // Generate secure random token
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 hours

            $stmt = $this->db->prepare("
                INSERT INTO password_resets (user_id, token, expires_at, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $tokenHash, $expiresAt, $createdBy]);

            return [
                'token' => $token,
                'expires_at' => $expiresAt,
                'reset_id' => $this->db->lastInsertId()
            ];
        } catch (PDOException $e) {
            error_log("Password reset creation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate a password reset token
     * @param string $token Reset token
     * @return array|false User info or false if invalid
     */
    public function validateToken(string $token) {
        try {
            $tokenHash = hash('sha256', $token);

            $stmt = $this->db->prepare("
                SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.username, u.full_name
                FROM password_resets pr
                JOIN users u ON pr.user_id = u.id
                WHERE pr.token = ? AND pr.used_at IS NULL
            ");
            $stmt->execute([$tokenHash]);
            $reset = $stmt->fetch();

            if (!$reset) {
                return false;
            }

            // Check if token has expired
            if (strtotime($reset['expires_at']) < time()) {
                return false;
            }

            return $reset;
        } catch (PDOException $e) {
            error_log("Token validation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Reset password using token
     * @param string $token Reset token
     * @param string $newPassword New password
     * @param int $changedBy User ID making the change
     * @return bool Success status
     */
    public function resetPassword(string $token, string $newPassword, int $changedBy): bool {
        try {
            // Validate token
            $resetInfo = $this->validateToken($token);
            if (!$resetInfo) {
                return false;
            }

            $userId = $resetInfo['user_id'];
            $tokenHash = hash('sha256', $token);

            // Validate password strength
            if (!$this->isPasswordStrong($newPassword)) {
                return false;
            }

            // Check password history
            if ($this->isPasswordReused($userId, $newPassword)) {
                return false;
            }

            // Start transaction
            $this->db->beginTransaction();

            // Update password
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("
                UPDATE users
                SET password_hash = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$passwordHash, $userId]);

            // Record in password history
            $stmt = $this->db->prepare("
                INSERT INTO password_history (user_id, password_hash, changed_by)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userId, $passwordHash, $changedBy]);

            // Mark token as used (AND used_at IS NULL prevents double-use race condition)
            $stmt = $this->db->prepare("
                UPDATE password_resets
                SET used_at = NOW()
                WHERE token = ? AND used_at IS NULL
            ");
            $stmt->execute([$tokenHash]);
            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException('Password reset token has already been used');
            }

            // Invalidate all existing sessions for this user
            $this->invalidateUserSessions($userId);

            $this->db->commit();

            return true;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Password reset failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if password meets strength requirements
     * @param string $password Password to validate
     * @return bool True if password is strong enough
     */
    public function isPasswordStrong(string $password): bool {
        // Minimum length
        if (strlen($password) < 8) {
            return false;
        }

        // Character type counting
        $hasLower = preg_match('/[a-z]/', $password);
        $hasUpper = preg_match('/[A-Z]/', $password);
        $hasDigit = preg_match('/[0-9]/', $password);
        $hasSymbol = preg_match('/[^a-zA-Z0-9]/', $password);

        $typeCount = (int) $hasLower + (int) $hasUpper + (int) $hasDigit + (int) $hasSymbol;

        // Require at least 2 character types
        return $typeCount >= 2;
    }

    /**
     * Get password strength score (0-100)
     * @param string $password Password to evaluate
     * @return array Strength info with score and label
     */
    public function getPasswordStrength(string $password): array {
        $score = 0;
        $feedback = [];

        // Length scoring
        $length = strlen($password);
        if ($length >= 12) {
            $score += 30;
        } elseif ($length >= 8) {
            $score += 20;
        } else {
            $feedback[] = 'Use at least 8 characters';
        }

        // Character variety
        $hasLower = preg_match('/[a-z]/', $password);
        $hasUpper = preg_match('/[A-Z]/', $password);
        $hasDigit = preg_match('/[0-9]/', $password);
        $hasSymbol = preg_match('/[^a-zA-Z0-9]/', $password);

        $typeCount = (int) $hasLower + (int) $hasUpper + (int) $hasDigit + (int) $hasSymbol;
        $score += $typeCount * 15;

        if ($typeCount < 2) {
            $feedback[] = 'Mix uppercase, lowercase, numbers, and symbols';
        }

        // Complexity bonus
        if ($length >= 12 && $typeCount >= 3) {
            $score += 10;
        }

        // Cap at 100
        $score = min(100, $score);

        // Determine label
        if ($score < 20) {
            $label = 'Weak';
        } elseif ($score < 40) {
            $label = 'Fair';
        } elseif ($score < 60) {
            $label = 'Good';
        } elseif ($score < 80) {
            $label = 'Strong';
        } else {
            $label = 'Very Strong';
        }

        return [
            'score' => $score,
            'label' => $label,
            'feedback' => $feedback
        ];
    }

    /**
     * Check if password is being reused (last 5 passwords)
     * @param int $userId User ID
     * @param string $newPassword New password to check
     * @return bool True if password is reused
     */
    private function isPasswordReused(int $userId, string $newPassword): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT password_hash
                FROM password_history
                WHERE user_id = ?
                ORDER BY changed_at DESC
                LIMIT 5
            ");
            $stmt->execute([$userId]);
            $history = $stmt->fetchAll();

            foreach ($history as $entry) {
                if (password_verify($newPassword, $entry['password_hash'])) {
                    return true;
                }
            }

            return false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get password reset requests by user
     * @param int $userId User ID
     * @return array List of reset requests
     */
    public function getResetHistory(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT pr.id, pr.expires_at, pr.used_at, pr.created_at,
                       u1.username as created_by_name
                FROM password_resets pr
                LEFT JOIN users u1 ON pr.created_by = u1.id
                WHERE pr.user_id = ?
                ORDER BY pr.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Invalidate all sessions for a user
     * @param int $userId User ID
     */
    private function invalidateUserSessions(int $userId): void {
        try {
            // Delete from user_sessions if table exists
            $stmt = $this->db->prepare("
                DELETE FROM user_sessions WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            // Table might not exist yet, ignore
        }
    }

    /**
     * Cleanup expired tokens
     */
    public function cleanupExpiredTokens(): void {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM password_resets
                WHERE expires_at < NOW() OR (used_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
            ");
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("Token cleanup failed: " . $e->getMessage());
        }
    }
}
