<?php
declare(strict_types=1);
// Configuration file for Grant Review System

require_once __DIR__ . '/env.php';

// Load Composer autoloader for HTMLPurifier and other dependencies
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

function detectBaseUrl(): string {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $appRoot = realpath(__DIR__ . '/../');

    if ($docRoot !== '' && $appRoot !== false) {
        $docRootReal = realpath($docRoot);
        if ($docRootReal !== false) {
            $docRootReal = rtrim(str_replace('\\', '/', $docRootReal), '/');
            $appRoot = str_replace('\\', '/', $appRoot);
            if ($docRootReal !== '' && strpos($appRoot, $docRootReal) === 0) {
                $relative = substr($appRoot, strlen($docRootReal));
                if ($relative === '') {
                    return '/';
                }
                return $relative[0] === '/' ? $relative : '/' . $relative;
            }
        }
    }

    return '/';
}

// Application settings
define('APP_ENV', envValue('APP_ENV', 'production'));
define('APP_DEBUG', envBool('APP_DEBUG', false));
define('APP_NAME', envValue('APP_NAME', 'Asynchronous Grant Review System'));
define('BASE_URL', rtrim(envValue('BASE_URL', detectBaseUrl()), '/'));
define('UPLOAD_DIR', rtrim(envValue('UPLOAD_DIR', __DIR__ . '/../uploads/'), '/\\') . DIRECTORY_SEPARATOR);
define('MAX_UPLOAD_SIZE', envInt('MAX_UPLOAD_SIZE', 10485760)); // 10MB in bytes
define('SHOW_DEFAULT_CREDENTIALS', false);
define('INSTITUTION_NAME', envValue('INSTITUTION_NAME', ''));
define('UNIT_NAME', envValue('UNIT_NAME', ''));
define('INSTITUTION_ICON_URL', envValue('INSTITUTION_ICON_URL', ''));

// Database configuration
define('DB_HOST', envValue('DB_HOST', 'localhost'));
define('DB_NAME', envValue('DB_NAME', 'grant_review'));
define('DB_USER', envValue('DB_USER', ''));
define('DB_PASS', envValue('DB_PASS', ''));
define('DB_CHARSET', envValue('DB_CHARSET', 'utf8mb4'));

// Session settings
define('SESSION_LIFETIME', envInt('SESSION_LIFETIME', 1800)); // 30 minutes
define('SESSION_COOKIE_SAMESITE', envValue('SESSION_COOKIE_SAMESITE', 'Lax'));
define('SESSION_COOKIE_SECURE', envValue('SESSION_COOKIE_SECURE', ''));

// Security settings
define('PASSWORD_MIN_LENGTH', envInt('PASSWORD_MIN_LENGTH', 12));
define('PASSWORD_MAX_LENGTH', envInt('PASSWORD_MAX_LENGTH', 128));
define('PASSWORD_REQUIRE_UPPERCASE', envBool('PASSWORD_REQUIRE_UPPERCASE', true));
define('PASSWORD_REQUIRE_LOWERCASE', envBool('PASSWORD_REQUIRE_LOWERCASE', true));
define('PASSWORD_REQUIRE_DIGIT', envBool('PASSWORD_REQUIRE_DIGIT', true));
define('PASSWORD_REQUIRE_SPECIAL', envBool('PASSWORD_REQUIRE_SPECIAL', true));
define('PASSWORD_HISTORY_COUNT', envInt('PASSWORD_HISTORY_COUNT', 5));
define('PASSWORD_EXPIRATION_DAYS', envInt('PASSWORD_EXPIRATION_DAYS', 365));
define('CSRF_TOKEN_NAME', envValue('CSRF_TOKEN_NAME', 'csrf_token'));
define('LOGIN_MAX_ATTEMPTS', envInt('LOGIN_MAX_ATTEMPTS', 5));
define('LOGIN_WINDOW_SECONDS', envInt('LOGIN_WINDOW_SECONDS', 900));
define('LOGIN_BLOCK_SECONDS', envInt('LOGIN_BLOCK_SECONDS', 900));

// Grant types and criteria
define('GRANT_TYPES', ['TRANSCEND Pilot', 'TRANSCEND Developmental']);
define('REVIEW_CRITERIA', [
    'Significance',
    'Investigator(s)',
    'Innovation',
    'Approach',
    'Mentoring Team/Plan & Pathway to External Funding'
]);

// Timezone
date_default_timezone_set(envValue('APP_TIMEZONE', 'America/New_York'));

// Error reporting (avoid display in production)
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/ErrorHandler.php';
ErrorHandler::register();
