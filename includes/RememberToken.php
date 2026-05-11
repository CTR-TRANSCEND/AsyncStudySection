<?php
/**
 * Remember Me Token Management Class
 * SPEC-AUTH-001.6: Persistent Authentication
 *
 * Features:
 * - Secure token generation and validation
 * - Token rotation on each use
 * - Device management
 * - Automatic token cleanup
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Manages persistent login tokens with rotation, device tracking, and cleanup.
 */
class RememberToken {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Create a remember token for a user
     * @param int $userId User ID
     * @return array|false Token info or false on failure
     */
    public function createToken(int $userId) {
        try {
            // Check device limit
            $tokenCount = $this->getUserTokenCount($userId);
            $maxDevices = 5; // Configurable

            if ($tokenCount >= $maxDevices) {
                // Remove oldest token
                $this->removeOldestToken($userId);
            }

            // Generate secure token
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $deviceId = $this->generateDeviceId();
            $expiresAt = date('Y-m-d H:i:s', time() + (30 * 86400)); // 30 days

            $stmt = $this->db->prepare("
                INSERT INTO remember_tokens (user_id, token_hash, device_id, user_agent, ip_address, expires_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $tokenHash,
                $deviceId,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $this->getClientIp(),
                $expiresAt
            ]);

            return [
                'token' => $token,
                'expires_at' => $expiresAt,
                'device_id' => $deviceId
            ];
        } catch (PDOException $e) {
            error_log("Remember token creation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate a remember token and return user info
     * @param string $token Remember token
     * @return array|false User info or false if invalid
     */
    public function validateToken(string $token) {
        try {
            $tokenHash = hash('sha256', $token);

            $stmt = $this->db->prepare("
                SELECT rt.id, rt.user_id, rt.token_hash, rt.expires_at, u.username, u.full_name, u.role
                FROM remember_tokens rt
                JOIN users u ON rt.user_id = u.id
                WHERE rt.token_hash = ? AND rt.expires_at > NOW()
            ");
            $stmt->execute([$tokenHash]);
            $tokenData = $stmt->fetch();

            if (!$tokenData) {
                return false;
            }

            // Rotate token (pass the old hash so rotation is atomic)
            $this->rotateToken($tokenData['id'], $tokenData['user_id'], $tokenData['token_hash']);

            return [
                'id' => $tokenData['user_id'],
                'username' => $tokenData['username'],
                'full_name' => $tokenData['full_name'],
                'role' => $tokenData['role']
            ];
        } catch (PDOException $e) {
            error_log("Token validation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Rotate a remember token (generate new one)
     * @param int $tokenId Token ID
     * @param int $userId User ID
     * @param string $oldTokenHash The current token hash (used to prevent race conditions)
     */
    private function rotateToken(int $tokenId, int $userId, string $oldTokenHash = ''): void {
        try {
            // Generate new token
            $newToken = bin2hex(random_bytes(32));
            $newTokenHash = hash('sha256', $newToken);
            $expiresAt = date('Y-m-d H:i:s', time() + (30 * 86400));

            // Update token — include old token_hash in WHERE to detect concurrent rotation
            $stmt = $this->db->prepare("
                UPDATE remember_tokens
                SET token_hash = ?, last_used_at = NOW(), expires_at = ?
                WHERE id = ? AND token_hash = ?
            ");
            $stmt->execute([$newTokenHash, $expiresAt, $tokenId, $oldTokenHash]);

            if ($stmt->rowCount() === 0) {
                // Token was already rotated by another concurrent request; skip cookie update
                return;
            }

            // Set new cookie
            $this->setCookie($newToken, $expiresAt);
        } catch (PDOException $e) {
            error_log("Token rotation failed: " . $e->getMessage());
        }
    }

    /**
     * Revoke a specific remember token
     * @param int $tokenId Token ID
     * @return bool Success status
     */
    public function revokeToken(int $tokenId): bool {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM remember_tokens WHERE id = ?
            ");
            $stmt->execute([$tokenId]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Revoke all remember tokens for a user
     * @param int $userId User ID
     * @return int Number of tokens revoked
     */
    public function revokeAllUserTokens(int $userId): int {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM remember_tokens WHERE user_id = ?
            ");
            $stmt->execute([$userId]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Get all remember tokens for a user
     * @param int $userId User ID
     * @return array List of tokens
     */
    public function getUserTokens(int $userId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT id, device_id, user_agent, ip_address,
                       created_at, last_used_at, expires_at
                FROM remember_tokens
                WHERE user_id = ? AND expires_at > NOW()
                ORDER BY last_used_at DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get number of active tokens for a user
     * @param int $userId User ID
     * @return int Token count
     */
    private function getUserTokenCount(int $userId): int {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM remember_tokens
                WHERE user_id = ? AND expires_at > NOW()
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            return (int) ($result['count'] ?? 0);
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Remove oldest token for a user
     * @param int $userId User ID
     */
    private function removeOldestToken(int $userId): void {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM remember_tokens
                WHERE user_id = ? AND id = (
                    SELECT id FROM (
                        SELECT id FROM remember_tokens
                        WHERE user_id = ?
                        ORDER BY created_at ASC
                        LIMIT 1
                    ) as oldest
                )
            ");
            $stmt->execute([$userId, $userId]);
        } catch (PDOException $e) {
            error_log("Failed to remove oldest token: " . $e->getMessage());
        }
    }

    /**
     * Cleanup expired tokens
     * @return int Number of tokens cleaned up
     */
    public function cleanupExpiredTokens(): int {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM remember_tokens WHERE expires_at < NOW()
            ");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Token cleanup failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Set remember cookie
     * @param string $token Token value
     * @param string $expiresAt Expiration date
     */
    public function setCookie(string $token, string $expiresAt): void {
        $cookieName = 'remember_token';
        $expiry = strtotime($expiresAt);
        $path = defined('BASE_URL') ? parse_url(BASE_URL, PHP_URL_PATH) ?: '/' : '/';

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $httpOnly = true;
        $sameSite = 'Lax';

        $cookieValue = $token;

        if (PHP_VERSION_ID >= 70300) {
            setcookie($cookieName, $cookieValue, [
                'expires' => $expiry,
                'path' => $path,
                'domain' => '',
                'secure' => $secure,
                'httponly' => $httpOnly,
                'samesite' => $sameSite
            ]);
        } else {
            setcookie($cookieName, $cookieValue, $expiry, $path . '; samesite=' . $sameSite, '', $secure, $httpOnly);
        }
    }

    /**
     * Clear remember cookie
     */
    public static function clearCookie(): void {
        $cookieName = 'remember_token';
        $path = defined('BASE_URL') ? parse_url(BASE_URL, PHP_URL_PATH) ?: '/' : '/';

        if (PHP_VERSION_ID >= 70300) {
            setcookie($cookieName, '', [
                'expires' => time() - 3600,
                'path' => $path,
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } else {
            setcookie($cookieName, '', time() - 3600, $path . '; samesite=Lax', '', true, true);
        }
    }

    /**
     * Get remember token from cookie
     * @return string|null Token value or null
     */
    public static function getTokenFromCookie(): ?string {
        return $_COOKIE['remember_token'] ?? null;
    }

    /**
     * Generate device identifier
     * @return string Device ID
     */
    private function generateDeviceId(): string {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

        return hash('sha256', $userAgent . $acceptLanguage);
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
     * Format token info for display
     * @param array $token Token data
     * @return array Formatted token info
     */
    public function formatTokenInfo(array $token): array {
        $deviceType = $this->detectDevice($token['user_agent'] ?? null);
        $isCurrent = $token['device_id'] === $this->generateDeviceId();

        return [
            'id' => $token['id'],
            'device' => $deviceType,
            'ip' => $token['ip_address'],
            'created_at' => $token['created_at'],
            'last_used_at' => $token['last_used_at'],
            'expires_at' => $token['expires_at'],
            'is_current' => $isCurrent
        ];
    }

    /**
     * Detect device type from user agent
     * @param string|null $userAgent User agent string
     * @return string Device type
     */
    private function detectDevice(?string $userAgent): string {
        if ($userAgent === null) {
            return 'Unknown Device';
        }

        if (preg_match('/(ipad|tablet)/i', $userAgent)) {
            return 'Tablet';
        } elseif (preg_match('/(android|iphone|mobile)/i', $userAgent)) {
            return 'Mobile';
        } else {
            return 'Desktop';
        }
    }
}
