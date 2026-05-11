<?php
declare(strict_types=1);
/**
 * Health Check Endpoint
 *
 * Provides health status for application monitoring
 * Supports basic, ready, live, and detailed checks
 *
 * @package DevOps
 * @subpackage Health
 * @author TDD Implementation Agent
 * @version 1.0.0
 */

// Require configuration files
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Set JSON content type
header('Content-Type: application/json');

// Health check type
$checkType = $_GET['check'] ?? 'basic';

// Initialize health status
$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'version' => defined('APP_VERSION') ? APP_VERSION : 'unknown',
    'environment' => defined('APP_ENV') ? APP_ENV : 'unknown'
];

// Database connectivity check
try {
    $db = Database::getInstance();
    $result = $db->query("SELECT 1 as test")->fetch();
    $health['checks']['database'] = [
        'status' => 'healthy',
        'message' => 'Database connection successful',
        'response_time_ms' => null
    ];
} catch (Exception $e) {
    error_log('Health check DB error: ' . $e->getMessage());
    $health['status'] = 'unhealthy';
    $health['checks']['database'] = [
        'status' => 'unhealthy',
        'message' => 'Database connection failed'
    ];
}

// Disk space check
$free_bytes = disk_free_space(__DIR__);
$total_bytes = disk_total_space(__DIR__);
$free_percent = ($free_bytes / $total_bytes) * 100;

$health['checks']['disk'] = [
    'status' => $free_percent < 10 ? 'degraded' : 'healthy',
    'message' => sprintf('Disk space: %.1f%% free (%.2f GB / %.2f GB)',
        $free_percent,
        $free_bytes / 1073741824,
        $total_bytes / 1073741824
    ),
    'free_bytes' => $free_bytes,
    'total_bytes' => $total_bytes,
    'free_percent' => round($free_percent, 2)
];

if ($free_percent < 10) {
    if ($health['status'] === 'healthy') {
        $health['status'] = 'degraded';
    }
}

// Uploads directory check
$uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads';
$health['checks']['uploads'] = [
    'status' => is_writable($uploadDir) ? 'healthy' : 'unhealthy',
    'message' => is_writable($uploadDir) ? 'Uploads directory writable' : 'Uploads directory not writable',
];

if (!is_writable($uploadDir) && $health['status'] === 'healthy') {
    $health['status'] = 'degraded';
}

// Session storage check
$session_path = session_save_path();
$health['checks']['sessions'] = [
    'status' => is_writable($session_path) ? 'healthy' : 'unhealthy',
    'message' => is_writable($session_path) ? 'Session storage writable' : 'Session storage not writable',
];

if (!is_writable($session_path) && $health['status'] === 'healthy') {
    $health['status'] = 'degraded';
}

// Readiness probe
if ($checkType === 'ready') {
    $ready = true;
    foreach ($health['checks'] as $check) {
        if ($check['status'] === 'unhealthy') {
            $ready = false;
            break;
        }
    }

    $health['ready'] = $ready;
    $health['status'] = $ready ? 'ready' : 'not_ready';
}

// Liveness probe
if ($checkType === 'live') {
    $health['alive'] = true;
    $health['status'] = 'alive';
}

// Detailed information (authenticated only)
if ($checkType === 'detailed') {
    // Require authentication
    session_start();
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Add configuration summary
    $health['configuration'] = [
        'app_name' => defined('APP_NAME') ? APP_NAME : 'unknown',
        'app_env' => defined('APP_ENV') ? APP_ENV : 'unknown',
        'app_debug' => defined('APP_DEBUG') ? (APP_DEBUG ? 'true' : 'false') : 'unknown',
        'base_url' => defined('BASE_URL') ? BASE_URL : '/',
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'session_lifetime' => defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 1800,
        'timezone' => date_default_timezone_get()
    ];

    // Add database information
    try {
        $db = Database::getInstance();
        $result = $db->query("SELECT VERSION() as version")->fetch();
        $health['database'] = [
            'version' => $result['version'] ?? 'unknown',
            'charset' => defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4',
            'host' => defined('DB_HOST') ? DB_HOST : 'localhost'
        ];
    } catch (Exception $e) {
        $health['database'] = [
            'error' => $e->getMessage()
        ];
    }

    // Add recent error count (from logs)
    $error_log = __DIR__ . '/logs/error.log';
    if (file_exists($error_log)) {
        $lines = file($error_log);
        $health['recent_errors'] = count($lines);
    }
}

// Set HTTP status code
$status_map = [
    'healthy' => 200,
    'ready' => 200,
    'alive' => 200,
    'degraded' => 200,
    'not_ready' => 503,
    'unhealthy' => 503
];

$http_status = $status_map[$health['status']] ?? 503;
http_response_code($http_status);

echo json_encode($health, JSON_PRETTY_PRINT);
?>
