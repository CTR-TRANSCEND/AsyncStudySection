<?php
declare(strict_types=1);
// Loads .env file and provides environment helper functions.

// Load .env file if it exists
$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    // Try .env.development if .env doesn't exist
    $envFile = __DIR__ . '/../.env.development';
}

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        // Parse KEY=VALUE format
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Remove quotes if present
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                $value = $matches[2];
            }
            // Do not override env vars already set (e.g., by phpunit.xml or Docker)
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

function envValue(string $key, $default = null) {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function envBool(string $key, bool $default = false): bool {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    $value = strtolower(trim((string) $value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function envInt(string $key, int $default): int {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return (int) $value;
}
