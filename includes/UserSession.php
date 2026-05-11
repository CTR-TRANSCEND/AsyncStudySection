<?php
/**
 * User Session Management Class
 * SPEC-AUTH-001.4: Session Management Interface
 *
 * Features:
 * - Session tracking and recording
 * - Session revocation
 * - Active session listing
 * - Session cleanup
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Tracks active user sessions and provides session revocation and cleanup.
 */
class UserSession {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Record a new user session
     * @param int $userId User ID
     * @param string $sessionId PHP session ID
     * @param bool $isRemembered Whether this is a persistent session
     * @return bool Success status
     */
    public function createSession(int $userId, string $sessionId, bool $isRemembered = false): bool {
        try {
            $ipAddress = $this->getClientIp();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

            $stmt = $this->db->prepare("
                INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, login_time, last_activity, expires_at, is_remembered)
                VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?)
            ");
            $stmt->execute([$userId, $sessionId, $ipAddress, $userAgent, $expiresAt, $isRemembered ? 1 : 0]);

            return true;
        } catch (PDOException $e) {
            error_log("Session creation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update session last activity time
     * @param string $sessionId Session ID
     */
    public function updateActivity(string $sessionId): void {
        try {
            $stmt = $this->db->prepare("
                UPDATE user_sessions
                SET last_activity = NOW()
                WHERE session_id = ?
            ");
            $stmt->execute([$sessionId]);
        } catch (PDOException $e) {
            // Ignore errors
        }
    }

    /**
     * Revoke a specific session
     * @param int $sessionId Session record ID
     * @return bool Success status
     */
    public function revokeSession(int $sessionId): bool {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM user_sessions WHERE id = ?
            ");
            $stmt->execute([$sessionId]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Session revocation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Revoke all sessions for a user except current
     * @param int $userId User ID
     * @param string $currentSessionId Current session ID to keep
     * @return int Number of sessions revoked
     */
    public function revokeAllUserSessions(int $userId, string $currentSessionId = ''): int {
        try {
            if ($currentSessionId !== '') {
                $stmt = $this->db->prepare("
                    DELETE FROM user_sessions
                    WHERE user_id = ? AND session_id != ?
                ");
                $stmt->execute([$userId, $currentSessionId]);
            } else {
                $stmt = $this->db->prepare("
                    DELETE FROM user_sessions WHERE user_id = ?
                ");
                $stmt->execute([$userId]);
            }

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Bulk session revocation failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get all active sessions
     * @return array List of active sessions
     */
    public function getActiveSessions(): array {
        try {
            $stmt = $this->db->prepare("
                SELECT us.id, us.user_id, us.session_id, us.ip_address,
                       us.user_agent, us.login_time, us.last_activity,
                       us.expires_at, us.is_remembered,
                       u.username, u.full_name, u.role
                FROM user_sessions us
                JOIN users u ON us.user_id = u.id
                WHERE us.expires_at > NOW()
                ORDER BY us.last_activity DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get sessions for a specific user
     * @param int $userId User ID
     * @return array List of user sessions
     */
    public function getUserSessions(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT id, session_id, ip_address, user_agent, login_time,
                       last_activity, expires_at, is_remembered
                FROM user_sessions
                WHERE user_id = ? AND expires_at > NOW()
                ORDER BY login_time DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get session count statistics
     * @return array Statistics
     */
    public function getSessionStats(): array {
        try {
            $stmt = $this->db->query("
                SELECT
                    COUNT(*) as total_active,
                    SUM(CASE WHEN is_remembered = 1 THEN 1 ELSE 0 END) as remembered,
                    SUM(CASE WHEN expires_at < DATE_ADD(NOW(), INTERVAL 15 MINUTE) THEN 1 ELSE 0 END) as expiring_soon
                FROM user_sessions
                WHERE expires_at > NOW()
            ");
            return $stmt->fetch() ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Cleanup expired sessions
     * @return int Number of sessions cleaned up
     */
    public function cleanupExpiredSessions(): int {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM user_sessions WHERE expires_at < NOW()
            ");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Session cleanup failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get client IP address
     * @return string IP address
     */
    private function getClientIp(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = trim((string) $ip);
        return $ip !== '' ? $ip : '0.0.0.0';
    }

    /**
     * Detect device type from user agent
     * @param string|null $userAgent User agent string
     * @return string Device type (desktop, mobile, tablet, unknown)
     */
    public function detectDeviceType(?string $userAgent): string {
        if ($userAgent === null) {
            return 'unknown';
        }

        $mobile = '/(android|iphone|ipod|blackberry|mobile|windows phone)/i';
        $tablet = '/(ipad|tablet|kindle)/i';

        if (preg_match($tablet, $userAgent)) {
            return 'tablet';
        } elseif (preg_match($mobile, $userAgent)) {
            return 'mobile';
        } else {
            return 'desktop';
        }
    }

    /**
     * Format session info for display
     * @param array $session Session data
     * @return array Formatted session info
     */
    public function formatSessionInfo(array $session): array {
        return [
            'id' => $session['id'],
            'device' => $this->detectDeviceType($session['user_agent'] ?? null),
            'ip' => $session['ip_address'],
            'login_time' => $session['login_time'],
            'last_activity' => $session['last_activity'],
            'is_current' => isset($_SESSION) && ($session['session_id'] ?? '') === session_id(),
            'is_remembered' => (bool) $session['is_remembered']
        ];
    }
}
