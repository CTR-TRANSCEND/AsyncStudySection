<?php
declare(strict_types=1);
if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config/config.php';
}

if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $cookiePath = $cookieParams['path'] ?? '/';
    if (defined('BASE_URL')) {
        $basePath = parse_url(BASE_URL, PHP_URL_PATH);
        if (!is_string($basePath) || $basePath === '') {
            $basePath = BASE_URL;
        }
        $cookiePath = rtrim($basePath, '/') . '/';
    }
    if ($cookiePath === '' || $cookiePath === '//') {
        $cookiePath = '/';
    }

    $secure = $isHttps;
    if (defined('SESSION_COOKIE_SECURE') && SESSION_COOKIE_SECURE !== '') {
        $secureValue = strtolower(trim((string) SESSION_COOKIE_SECURE));
        $secure = in_array($secureValue, ['1', 'true', 'yes', 'on'], true);
    }
    $sameSite = defined('SESSION_COOKIE_SAMESITE') ? SESSION_COOKIE_SAMESITE : 'Lax';
    $lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600;

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    ini_set('session.cookie_samesite', $sameSite);
    ini_set('session.gc_maxlifetime', (string) $lifetime);

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => $cookiePath,
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    } else {
        session_set_cookie_params(
            $lifetime,
            $cookiePath . '; samesite=' . $sameSite,
            $cookieParams['domain'] ?? '',
            $secure,
            true
        );
    }

    session_start();

    // Initialize session security metadata if not already set
    if (!isset($_SESSION['security'])) {
        $_SESSION['security'] = [
            'ip_address' => getClientIp(),
            'user_agent' => substr(getUserAgent(), 0, 255),
            'created_at' => time(),
            'last_validated' => time()
        ];
    }

    // Update last validated time
    $_SESSION['security']['last_validated'] = time();
}

function secureSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $cookieParams = session_get_cookie_params();
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $cookiePath = $cookieParams['path'] ?? '/';
    if (defined('BASE_URL')) {
        $basePath = parse_url(BASE_URL, PHP_URL_PATH);
        if (!is_string($basePath) || $basePath === '') {
            $basePath = BASE_URL;
        }
        $cookiePath = rtrim($basePath, '/') . '/';
    }
    if ($cookiePath === '' || $cookiePath === '//') {
        $cookiePath = '/';
    }

    $secure = $isHttps;
    if (defined('SESSION_COOKIE_SECURE') && SESSION_COOKIE_SECURE !== '') {
        $secureValue = strtolower(trim((string) SESSION_COOKIE_SECURE));
        $secure = in_array($secureValue, ['1', 'true', 'yes', 'on'], true);
    }
    $sameSite = defined('SESSION_COOKIE_SAMESITE') ? SESSION_COOKIE_SAMESITE : 'Lax';
    $lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600;

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    ini_set('session.cookie_samesite', $sameSite);
    ini_set('session.gc_maxlifetime', (string) $lifetime);

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => $cookiePath,
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    } else {
        session_set_cookie_params(
            $lifetime,
            $cookiePath . '; samesite=' . $sameSite,
            $cookieParams['domain'] ?? '',
            $secure,
            true
        );
    }

    session_start();

    // Initialize session security metadata if not already set
    if (!isset($_SESSION['security'])) {
        $_SESSION['security'] = [
            'ip_address' => getClientIp(),
            'user_agent' => substr(getUserAgent(), 0, 255),
            'created_at' => time(),
            'last_validated' => time()
        ];
    }

    // Update last validated time
    $_SESSION['security']['last_validated'] = time();
}

function validateSession(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    if (!isset($_SESSION['security'])) {
        session_destroy();
        return false;
    }

    $security = $_SESSION['security'];

    $maxLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 1800;
    if (time() - $security['last_validated'] > $maxLifetime) {
        logSecurityEvent('session_timeout', [
            'session_id' => session_id(),
            'last_activity' => $security['last_validated']
        ]);
        session_destroy();
        return false;
    }

    if (defined('SECURITY_EXPIRE_SESSIONS_ON_IP_CHANGE') && SECURITY_EXPIRE_SESSIONS_ON_IP_CHANGE) {
        $currentIp = getClientIp();
        if ($security['ip_address'] !== $currentIp) {
            logSecurityEvent('session_ip_mismatch', [
                'session_id' => session_id(),
                'expected_ip' => $security['ip_address'],
                'actual_ip' => $currentIp
            ]);
            session_destroy();
            return false;
        }
    }

    $currentUA = substr(getUserAgent(), 0, 255);
    if (!hash_equals($security['user_agent'], $currentUA)) {
        logSecurityEvent('session_ua_mismatch', [
            'session_id' => session_id(),
            'expected_ua' => $security['user_agent'],
            'actual_ua' => $currentUA
        ]);
        session_destroy();
        return false;
    }

    $_SESSION['security']['last_validated'] = time();

    return true;
}

function isSessionCookieSecureRequired(): bool
{
    if (defined('SESSION_COOKIE_SECURE') && is_string(SESSION_COOKIE_SECURE)) {
        return SESSION_COOKIE_SECURE === 'true' || SESSION_COOKIE_SECURE === '1';
    }

    return defined('APP_ENV') && APP_ENV === 'production';
}

function getClientIp(): string
{
    $ipHeaders = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ];

    foreach ($ipHeaders as $header) {
        $ip = $_SERVER[$header] ?? '';
        if ($ip !== '') {
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function getUserAgent(): string
{
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function regenerateSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    session_regenerate_id(true);

    $_SESSION['security']['ip_address'] = getClientIp();
    $_SESSION['security']['user_agent'] = substr(getUserAgent(), 0, 255);
    $_SESSION['security']['created_at'] = time();
}

function destroySession(): void
{
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

function getSessionCookieParams(): array
{
    return session_get_cookie_params();
}

function isSessionExpired(): bool
{
    if (!isset($_SESSION['security']['last_validated'])) {
        return false;
    }

    $maxLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 1800;
    return (time() - $_SESSION['security']['last_validated']) > $maxLifetime;
}

function getSessionRemainingLifetime(): int
{
    if (!isset($_SESSION['security']['last_validated'])) {
        return 0;
    }

    $maxLifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 1800;
    $elapsed = time() - $_SESSION['security']['last_validated'];
    $remaining = $maxLifetime - $elapsed;

    return max(0, $remaining);
}

function logSecurityEvent(string $eventType, array $eventData = []): void
{
    try {
        $db = Database::getInstance()->getConnection();

        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $ipAddress = getClientIp();
        $userAgent = substr(getUserAgent(), 0, 500);

        $stmt = $db->prepare("
            INSERT INTO security_event_log (event_type, severity, user_id, ip_address, user_agent, event_data)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $eventType,
            'warning',
            $userId,
            $ipAddress,
            $userAgent,
            json_encode($eventData)
        ]);
    } catch (\Exception $e) {
        error_log('Failed to log security event: ' . $e->getMessage());
        // Silently fail — never crash page for audit logging
    }
}
