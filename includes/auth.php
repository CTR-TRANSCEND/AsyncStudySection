<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

/**
 * Handles user authentication, login rate limiting, and session binding.
 */
class Auth {
    private $db;
    private $lastError = null;
    // Legacy admin hash removed for security (was a well-known bcrypt hash)

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function login($username, $password) {
        $this->lastError = null;
        $username = trim((string) $username);
        $rateUsername = strtolower($username);
        $ipAddress = $this->getClientIp();

        if ($this->isLoginBlocked($rateUsername, $ipAddress)) {
            $this->lastError = 'Too many login attempts. Please wait and try again.';
            return false;
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ? AND is_active = TRUE");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->recordLoginAttempt($rateUsername, $ipAddress, false, null, 'Unknown user');
            return false;
        }

        // Check if account is locked
        if ($this->isAccountLocked($user)) {
            $lockoutInfo = $this->getLockoutInfo($user);
            $this->lastError = $lockoutInfo['message'];
            return false;
        }

        if (password_verify($password, $user['password_hash'])) {
            $this->recordLoginAttempt($rateUsername, $ipAddress, true, $user['id']);
            $this->clearFailedLoginAttempts($rateUsername);
            $this->resetFailedLoginCount($user['id']);
            $this->completeLogin($user);
            return true;
        }

        // Increment failed login count and apply progressive lockout
        $this->incrementFailedLoginCount($user['id']);
        $this->recordLoginAttempt($rateUsername, $ipAddress, false, $user['id'], 'Invalid password');

        // Check if we need to apply progressive lockout
        $lockoutResult = $this->applyProgressiveLockout($user);
        if ($lockoutResult['locked']) {
            $this->lastError = $lockoutResult['message'];
        }

        return false;
    }

    public function logout() {
        self::logoutUser();
    }

    public static function requireLogin() {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }

        if (self::isSessionExpired()) {
            self::logoutUser();
            header('Location: ' . BASE_URL . '/login.php?timeout=1');
            exit;
        }

        // Unify session timeout: update both mechanisms so they stay in sync
        $_SESSION['last_activity'] = time();
        if (isset($_SESSION['security'])) {
            $_SESSION['security']['last_validated'] = time();
        }
    }

    public static function requireAdmin() {
        self::requireLogin();
        if ($_SESSION['role'] !== 'admin') {
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
    }

    public static function requireReviewer() {
        self::requireLogin();
        if ($_SESSION['role'] !== 'reviewer') {
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
    }

    public static function isLoggedIn() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    public static function isAdmin() {
        return self::isLoggedIn() && $_SESSION['role'] === 'admin';
    }

    public static function isReviewer() {
        return self::isLoggedIn() && $_SESSION['role'] === 'reviewer';
    }

    public static function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }

    public static function getUsername() {
        return $_SESSION['username'] ?? null;
    }

    public static function getFullName() {
        return $_SESSION['full_name'] ?? null;
    }

    public static function getRole() {
        return $_SESSION['role'] ?? null;
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    private function completeLogin(array $user): void {
        $updateStmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);

        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
    }

    private static function isSessionExpired(): bool {
        if (!isset($_SESSION['last_activity'])) {
            return false;
        }
        return (time() - $_SESSION['last_activity']) > SESSION_LIFETIME;
    }

    private function getClientIp(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = trim((string) $ip);
        return $ip !== '' ? $ip : '0.0.0.0';
    }

    private function isLoginBlocked(string $username, string $ipAddress): bool {
        $maxAttempts = defined('LOGIN_MAX_ATTEMPTS') ? LOGIN_MAX_ATTEMPTS : 0;
        $windowSeconds = defined('LOGIN_WINDOW_SECONDS') ? LOGIN_WINDOW_SECONDS : 0;
        $blockSeconds = defined('LOGIN_BLOCK_SECONDS') ? LOGIN_BLOCK_SECONDS : 0;

        if ($maxAttempts <= 0 || $windowSeconds <= 0 || $blockSeconds <= 0) {
            return false;
        }

        try {
            $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);
            $blocked = $this->isBlockedForIp($ipAddress, $cutoff, $maxAttempts, $blockSeconds);
            if ($blocked) {
                return true;
            }

            if ($username !== '') {
                return $this->isBlockedForUsername($username, $cutoff, $maxAttempts, $blockSeconds);
            }
        } catch (PDOException $e) {
            return false;
        }

        return false;
    }

    private function isBlockedForIp(string $ipAddress, string $cutoff, int $maxAttempts, int $blockSeconds): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as fail_count, MAX(attempted_at) as last_attempt
            FROM login_attempts
            WHERE ip_address = ? AND was_success = 0 AND attempted_at >= ?
        ");
        $stmt->execute([$ipAddress, $cutoff]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        $failCount = (int) ($row['fail_count'] ?? 0);
        $lastAttempt = $row['last_attempt'] ?? null;
        if ($failCount < $maxAttempts || !$lastAttempt) {
            return false;
        }

        return (strtotime($lastAttempt) + $blockSeconds) > time();
    }

    private function isBlockedForUsername(string $username, string $cutoff, int $maxAttempts, int $blockSeconds): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as fail_count, MAX(attempted_at) as last_attempt
            FROM login_attempts
            WHERE username = ? AND was_success = 0 AND attempted_at >= ?
        ");
        $stmt->execute([$username, $cutoff]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        $failCount = (int) ($row['fail_count'] ?? 0);
        $lastAttempt = $row['last_attempt'] ?? null;
        if ($failCount < $maxAttempts || !$lastAttempt) {
            return false;
        }

        return (strtotime($lastAttempt) + $blockSeconds) > time();
    }

    private function recordLoginAttempt(string $username, string $ipAddress, bool $success, ?int $userId = null, ?string $failureReason = null): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO login_attempts (username, ip_address, was_success)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([
                $username !== '' ? $username : null,
                $ipAddress,
                $success ? 1 : 0
            ]);

            // Record in login_history if table exists
            try {
                $historyStmt = $this->db->prepare("
                    INSERT INTO login_history (user_id, username, ip_address, user_agent, success, failure_reason)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $historyStmt->execute([
                    $userId,
                    $username,
                    $ipAddress,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $success ? 1 : 0,
                    $success ? null : $failureReason
                ]);
            } catch (PDOException $historyEx) {
                // Ignore if login_history table doesn't exist yet
            }

            $this->pruneLoginAttempts();
        } catch (PDOException $e) {
            // Ignore logging errors to avoid blocking authentication.
        }
    }

    private function clearFailedLoginAttempts(string $username): void {
        if ($username === '') {
            return;
        }
        try {
            $stmt = $this->db->prepare("
                DELETE FROM login_attempts
                WHERE username = ? AND was_success = 0
            ");
            $stmt->execute([$username]);
        } catch (PDOException $e) {
            // Ignore cleanup errors.
        }
    }

    private function pruneLoginAttempts(): void {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM login_attempts
                WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute();
        } catch (PDOException $e) {
            // Ignore cleanup errors.
        }
    }

    /**
     * Check if a user account is currently locked
     */
    private function isAccountLocked(array $user): bool {
        // Check if account_locked column exists and is TRUE
        if (!isset($user['account_locked']) || !$user['account_locked']) {
            return false;
        }

        // Check if lockout has expired (for temporary lockouts)
        if (isset($user['locked_until']) && $user['locked_until'] !== null) {
            $lockedUntil = strtotime($user['locked_until']);
            if ($lockedUntil <= time()) {
                // Lockout has expired, automatically unlock
                $this->autoUnlockAccount($user['id']);
                return false;
            }
        }

        return true;
    }

    /**
     * Get lockout information for display
     */
    private function getLockoutInfo(array $user): array {
        $info = [
            'locked' => true,
            'permanent' => false,
            'message' => 'Account is locked. Please contact an administrator.'
        ];

        if (isset($user['locked_until']) && $user['locked_until'] !== null) {
            $lockedUntil = strtotime($user['locked_until']);
            $minutesRemaining = ceil(($lockedUntil - time()) / 60);

            $info['permanent'] = false;
            $info['locked_until'] = $user['locked_until'];
            $info['message'] = sprintf(
                'Account locked. Try again in %d minutes.',
                $minutesRemaining
            );
        } else {
            $info['permanent'] = true;
            $info['message'] = 'Account permanently locked. Contact an administrator.';
        }

        return $info;
    }

    /**
     * Increment failed login count for a user
     */
    private function incrementFailedLoginCount(int $userId): void {
        try {
            $stmt = $this->db->prepare("
                UPDATE users
                SET failed_login_count = COALESCE(failed_login_count, 0) + 1
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            // Column might not exist yet, ignore
        }
    }

    /**
     * Reset failed login count for a user
     */
    private function resetFailedLoginCount(int $userId): void {
        try {
            $stmt = $this->db->prepare("
                UPDATE users
                SET failed_login_count = 0,
                    account_locked = FALSE,
                    locked_at = NULL,
                    locked_until = NULL,
                    lockout_reason = NULL
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            // Columns might not exist yet, ignore
        }
    }

    /**
     * Apply progressive lockout based on failed login count
     */
    private function applyProgressiveLockout(array $user): array {
        $result = [
            'locked' => false,
            'message' => ''
        ];

        try {
            $stmt = $this->db->prepare("SELECT failed_login_count FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $userData = $stmt->fetch();

            if (!$userData || !isset($userData['failed_login_count'])) {
                return $result;
            }

            $failedCount = (int) $userData['failed_login_count'];
            $lockoutDuration = null;
            $lockoutReason = '';

            switch ($failedCount) {
                case 3:
                    $lockoutDuration = '+5 minutes';
                    $lockoutReason = '3 failed login attempts';
                    $result['message'] = 'Account locked for 5 minutes due to multiple failed attempts.';
                    break;
                case 6:
                    $lockoutDuration = '+15 minutes';
                    $lockoutReason = '6 failed login attempts';
                    $result['message'] = 'Account locked for 15 minutes due to multiple failed attempts.';
                    break;
                case 9:
                    $lockoutDuration = '+1 hour';
                    $lockoutReason = '9 failed login attempts';
                    $result['message'] = 'Account locked for 1 hour due to multiple failed attempts.';
                    break;
                case 12:
                    // Permanent lockout
                    $lockoutDuration = null;
                    $lockoutReason = '12 failed login attempts';
                    $result['message'] = 'Account permanently locked due to excessive failed attempts. Contact an administrator.';
                    break;
                default:
                    return $result;
            }

            $result['locked'] = true;

            if ($failedCount >= 12) {
                // Permanent lockout
                $stmt = $this->db->prepare("
                    UPDATE users
                    SET account_locked = TRUE,
                        locked_at = NOW(),
                        locked_until = NULL,
                        lockout_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([$lockoutReason, $user['id']]);
            } else {
                // Temporary lockout — compute timestamp in PHP to avoid INTERVAL binding issues
                $lockoutSeconds = $this->parseDurationToSeconds($lockoutDuration);
                $lockedUntil = date('Y-m-d H:i:s', time() + $lockoutSeconds);
                $stmt = $this->db->prepare("
                    UPDATE users
                    SET account_locked = TRUE,
                        locked_at = NOW(),
                        locked_until = ?,
                        lockout_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([$lockedUntil, $lockoutReason, $user['id']]);
            }

        } catch (PDOException $e) {
            // Columns might not exist yet, ignore
        }

        return $result;
    }

    /**
     * Parse a duration string like '+5 minutes' into seconds.
     */
    private function parseDurationToSeconds(string $duration): int {
        $duration = trim(str_replace('+', '', $duration));
        if (preg_match('/^(\d+)\s*(second|minute|hour|day)s?$/i', $duration, $m)) {
            $multipliers = ['second' => 1, 'minute' => 60, 'hour' => 3600, 'day' => 86400];
            return (int)$m[1] * ($multipliers[strtolower($m[2])] ?? 60);
        }
        return 300; // Default 5 minutes
    }

    /**
     * Auto-unlock account when lockout expires
     */
    private function autoUnlockAccount(int $userId): void {
        try {
            $stmt = $this->db->prepare("
                UPDATE users
                SET account_locked = FALSE,
                    locked_at = NULL,
                    locked_until = NULL,
                    lockout_reason = NULL
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            // Ignore errors
        }
    }

    /**
     * Public method to unlock an account (admin function)
     */
    public function unlockAccount(string $username): bool {
        try {
            $stmt = $this->db->prepare("
                UPDATE users
                SET account_locked = FALSE,
                    locked_at = NULL,
                    locked_until = NULL,
                    lockout_reason = NULL,
                    failed_login_count = 0
                WHERE username = ?
            ");
            $stmt->execute([$username]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Get list of locked accounts
     */
    public function getLockedAccounts(): array {
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, full_name, email,
                       failed_login_count, locked_at, locked_until,
                       lockout_reason, account_locked
                FROM users
                WHERE account_locked = TRUE
                ORDER BY locked_at DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public static function logoutUser(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}
