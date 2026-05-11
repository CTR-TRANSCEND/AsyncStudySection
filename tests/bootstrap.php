<?php
/**
 * PHPUnit Bootstrap File
 *
 * Sets up the testing environment, loads dependencies,
 * and configures test-specific settings.
 */

// Set test environment variables BEFORE loading config.php
// These take priority over .env file values
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET', 'APP_ENV', 'APP_DEBUG'] as $envKey) {
    $val = getenv($envKey);
    if ($val !== false) {
        putenv("{$envKey}={$val}");
        $_ENV[$envKey] = $val;
    }
}
// Ensure test env
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load configuration (will pick up env vars set above)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Load helper functions
require_once __DIR__ . '/../includes/functions.php';

// Create namespace aliases for classes that don't use namespaces
// Tests reference GrantReview\ClassName but classes are in global namespace
if (!class_exists('GrantReview\Database')) {
    class_alias('Database', 'GrantReview\Database');
}
if (!class_exists('GrantReview\DraftManager')) {
    class_alias('DraftManager', 'GrantReview\DraftManager');
}
if (!class_exists('GrantReview\TemplateManager')) {
    class_alias('TemplateManager', 'GrantReview\TemplateManager');
}
if (!class_exists('GrantReview\VersionManager')) {
    class_alias('VersionManager', 'GrantReview\VersionManager');
}

// Initialize global cache for navigation
if (!isset($GLOBALS['_navigationCache'])) {
    $GLOBALS['_navigationCache'] = [];
}

// Start session for tests
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../.moai/logs/php_errors.log');

// Ensure required directories exist
$directories = [
    __DIR__ . '/../.moai/logs',
    __DIR__ . '/../.moai/reports/coverage',
    __DIR__ . '/../.moai/cache/phpunit',
    __DIR__ . '/fixtures',
    __DIR__ . '/fixtures/db',
    __DIR__ . '/fixtures/documents',
    __DIR__ . '/screenshots',
];

foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

// Timezone is set by config.php via APP_TIMEZONE env var (defaults to America/New_York)

// Configure test-specific settings
ini_set('memory_limit', '512M');

// Prevent output during tests
ob_start();

// Register shutdown function to clean up
register_shutdown_function(function() {
    // Clean up session
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Flush output buffer
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
});
