<?php
/**
 * Database Query Validation Helpers
 *
 * SPEC-SEC-001: SQL Injection Prevention Enhancement
 *
 * Features:
 * - Whitelist validation for ORDER BY columns
 * - Validation for sort direction (ASC/DESC)
 * - Safe LIKE query escaping
 * - Type checking for database parameters
 *
 * @author SPEC-SEC-001 Implementation
 * @version 1.0.0
 */

declare(strict_types=1);

/**
 * Validate and return safe sort column
 *
 * Validates user-provided column name against whitelist.
 * Returns default column if input is invalid.
 *
 * @param string $userInput The user-provided column name
 * @param array $allowedColumns Array of allowed column names
 * @param string $default Default column to return if validation fails
 * @return string The validated column name
 */
function getValidSortColumn(string $userInput, array $allowedColumns, string $default = ''): string
{
    // Trim and sanitize input
    $column = trim($userInput);

    // Check if column is in whitelist
    if (in_array($column, $allowedColumns, true)) {
        return $column;
    }

    // Return default or first allowed column
    if ($default !== '' && in_array($default, $allowedColumns, true)) {
        return $default;
    }

    return $allowedColumns[0] ?? '';
}

/**
 * Validate and return safe sort direction
 *
 * Validates user-provided direction against allowed values.
 * Returns 'ASC' as default if input is invalid.
 *
 * @param string $userInput The user-provided direction
 * @return string Either 'ASC' or 'DESC'
 */
function getValidSortDirection(string $userInput): string
{
    $direction = strtoupper(trim($userInput));
    return in_array($direction, ['ASC', 'DESC'], true) ? $direction : 'ASC';
}

/**
 * Escape string for LIKE query
 *
 * Escapes special LIKE characters (% and _) with backslash.
 * Prevents wildcard injection in search queries.
 *
 * @param string $input The string to escape
 * @param string|null $escapeChar The escape character to use (default: backslash)
 * @return string The escaped string
 */
function escapeLike(string $input, ?string $escapeChar = null): string
{
    if ($escapeChar === null) {
        $escapeChar = '\\';
    }

    $search = [$escapeChar, '%', '_'];
    $replace = [
        $escapeChar . $escapeChar,
        $escapeChar . '%',
        $escapeChar . '_'
    ];

    return str_replace($search, $replace, $input);
}

/**
 * Validate integer parameter for database query
 *
 * Ensures parameter is a valid integer within range.
 * Returns null if invalid.
 *
 * @param mixed $value The value to validate
 * @param int|null $min Minimum allowed value (null = no minimum)
 * @param int|null $max Maximum allowed value (null = no maximum)
 * @return int|null The validated integer, or null if invalid
 */
function validateInt($value, ?int $min = null, ?int $max = null): ?int
{
    if (!is_numeric($value)) {
        return null;
    }

    $intValue = (int) $value;

    if ($min !== null && $intValue < $min) {
        return null;
    }

    if ($max !== null && $intValue > $max) {
        return null;
    }

    return $intValue;
}

/**
 * Validate email parameter for database query
 *
 * @param string $email The email to validate
 * @return string|null The sanitized email, or null if invalid
 */
function validateEmail(string $email): ?string
{
    $email = trim($email);
    if ($email === '') {
        return null;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    return $email;
}

/**
 * Validate boolean parameter
 *
 * Converts common boolean representations to actual boolean.
 * Returns null if value is not boolean-like.
 *
 * @param mixed $value The value to validate
 * @return bool|null The validated boolean, or null if invalid
 */
function validateBool($value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return $value != 0; // loose comparison intentional: numeric value may be int, float, or string
    }

    if (is_string($value)) {
        $lower = strtolower(trim($value));
        if (in_array($lower, ['true', 'yes', 'on', '1'], true)) {
            return true;
        }
        if (in_array($lower, ['false', 'no', 'off', '0'], true)) {
            return false;
        }
    }

    return null;
}

/**
 * Validate date parameter for database query
 *
 * @param string $date The date string to validate
 * @param string $format Expected date format (default: Y-m-d)
 * @return string|null The validated date, or null if invalid
 */
function validateDate(string $date, string $format = 'Y-m-d'): ?string
{
    $date = trim($date);
    if ($date === '') {
        return null;
    }

    $d = DateTime::createFromFormat($format, $date);
    if ($d && $d->format($format) === $date) {
        return $date;
    }

    return null;
}

/**
 * Validate URL parameter for database query
 *
 * @param string $url The URL to validate
 * @return string|null The sanitized URL, or null if invalid
 */
function validateUrl(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    return $url;
}

/**
 * Sanitize and validate string length
 *
 * Ensures string is within specified length range.
 *
 * @param string $string The string to validate
 * @param int|null $min Minimum length (null = no minimum)
 * @param int|null $max Maximum length (null = no maximum)
 * @return string|null The validated string, or null if invalid
 */
function validateStringLength(string $string, ?int $min = null, ?int $max = null): ?string
{
    $length = strlen($string);

    if ($min !== null && $length < $min) {
        return null;
    }

    if ($max !== null && $length > $max) {
        return null;
    }

    return $string;
}

/**
 * Validate UUID
 *
 * Validates UUID v4 format.
 *
 * @param string $uuid The UUID to validate
 * @return string|null The validated UUID, or null if invalid
 */
function validateUuid(string $uuid): ?string
{
    $uuid = trim($uuid);
    $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    if (preg_match($pattern, $uuid)) {
        return $uuid;
    }

    return null;
}

/**
 * Build safe WHERE IN clause
 *
 * Prevents SQL injection in IN clauses by validating all values.
 *
 * @param array $values Array of values to include
 * @param int $type PDO parameter type (PDO::PARAM_STR or PDO::PARAM_INT)
 * @return array Array of ['clause' => string, 'params' => array]
 */
function buildSafeInClause(array $values, int $type = PDO::PARAM_STR): array
{
    if (empty($values)) {
        return ['clause' => '1=0', 'params' => []];
    }

    // Validate all values based on type
    $validated = [];
    foreach ($values as $value) {
        if ($type === PDO::PARAM_INT) {
            $intVal = filter_var($value, FILTER_VALIDATE_INT);
            if ($intVal !== false) {
                $validated[] = $intVal;
            }
        } else {
            $validated[] = (string) $value;
        }
    }

    if (empty($validated)) {
        return ['clause' => '1=0', 'params' => []];
    }

    // Build placeholders
    $placeholders = implode(',', array_fill(0, count($validated), '?'));

    return [
        'clause' => "IN ($placeholders)",
        'params' => $validated
    ];
}

/**
 * Validate file path to prevent directory traversal
 *
 * @param string $path The file path to validate
 * @return bool True if safe, false if suspicious
 */
function isSafePath(string $path): bool
{
    // Check for directory traversal
    if (strpos($path, '../') !== false || strpos($path, '..\\') !== false) {
        return false;
    }

    // Check for absolute paths
    if (DIRECTORY_SEPARATOR === '/') {
        if (strpos($path, '/') === 0) {
            return false;
        }
    } else {
        // Windows paths
        if (preg_match('/^[a-zA-Z]:\\\\/', $path)) {
            return false;
        }
    }

    // Check for null bytes
    if (strpos($path, "\0") !== false) {
        return false;
    }

    return true;
}
