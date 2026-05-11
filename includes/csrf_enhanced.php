<?php
/**
 * Enhanced CSRF Protection Implementation
 *
 * SPEC-SEC-001: CSRF Token Security Enhancements
 *
 * Features:
 * - Per-request CSRF tokens with 30-minute expiration
 * - One-time use tokens (removed after validation)
 * - Support for both POST parameters and X-CSRF-Token headers
 * - Automatic token pruning of expired entries
 *
 * @author SPEC-SEC-001 Implementation
 * @version 1.0.0
 */

declare(strict_types=1);

/**
 * Generate a new CSRF token with expiration
 *
 * @return string The generated CSRF token
 * @throws RuntimeException If session is not active
 */
function generateCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session not active');
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + 1800; // 30 minutes

    if (!isset($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }

    $_SESSION['csrf_tokens'][$token] = [
        'expires_at' => $expiresAt,
        'created_at' => time()
    ];

    // Prune expired tokens
    pruneExpiredCsrfTokens();

    return $token;
}

/**
 * Validate a CSRF token
 *
 * Checks POST parameter and X-CSRF-Token header.
 * Token is consumed (one-time use) after successful validation.
 *
 * @param string|null $token The token to validate
 * @return bool True if token is valid, false otherwise
 */
function validateCsrfToken(?string $token = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    // Get token from parameter or header
    if ($token === null) {
        $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }

    if (!is_string($token) || $token === '') {
        return false;
    }

    if (!isset($_SESSION['csrf_tokens'][$token])) {
        return false;
    }

    $tokenData = $_SESSION['csrf_tokens'][$token];

    // Check expiration
    if (time() > $tokenData['expires_at']) {
        unset($_SESSION['csrf_tokens'][$token]);
        return false;
    }

    // One-time use token - remove after validation
    unset($_SESSION['csrf_tokens'][$token]);

    return true;
}

/**
 * Remove expired CSRF tokens from session
 *
 * @return void
 */
function pruneExpiredCsrfTokens(): void
{
    if (!isset($_SESSION['csrf_tokens'])) {
        return;
    }

    $now = time();
    foreach ($_SESSION['csrf_tokens'] as $token => $data) {
        if ($now > $data['expires_at']) {
            unset($_SESSION['csrf_tokens'][$token]);
        }
    }
}

/**
 * Generate CSRF token for legacy compatibility
 *
 * @return string The generated token
 * @deprecated Use generateCsrfToken() instead
 */
if (!function_exists('getCsrfToken')) {
function getCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }

    return $_SESSION['csrf_token'];
}
}

/**
 * Verify CSRF token for legacy compatibility
 *
 * @return string|null Error message if invalid, null if valid
 * @deprecated Use validateCsrfToken() instead
 */
if (!function_exists('verifyCsrfToken')) {
function verifyCsrfToken(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return 'Session not initialized.';
    }

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!is_string($token) || $token === '') {
        return 'Invalid session token.';
    }

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        return 'Invalid session token.';
    }

    return null;
}
}

/**
 * Render CSRF hidden field for HTML forms
 *
 * @return string HTML input element with CSRF token
 */
if (!function_exists('csrfField')) {
function csrfField(): string
{
    $token = generateCsrfToken();
    $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="' . htmlspecialchars(CSRF_TOKEN_NAME, ENT_QUOTES, 'UTF-8') . '" value="' . $escapedToken . '">';
}
}

/**
 * Require CSRF validation for current request
 *
 * Terminates execution with 403 status if validation fails.
 *
 * @return void
 */
function requireCsrfValidation(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Only validate state-changing methods
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
        return;
    }

    if (!validateCsrfToken()) {
        http_response_code(403);
        echo 'Invalid CSRF token. Please refresh the page and try again.';
        exit;
    }
}
