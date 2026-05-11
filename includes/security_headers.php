<?php
/**
 * Security Headers Implementation
 *
 * SPEC-SEC-001: Security Headers Implementation (SEC-201)
 *
 * Features:
 * - Content-Security-Policy with nonce support
 * - X-Frame-Options for clickjacking prevention
 * - X-Content-Type-Options for MIME sniffing prevention
 * - X-XSS-Protection for legacy browser support
 * - Strict-Transport-Security for HTTPS enforcement
 * - Referrer-Policy for privacy
 * - Permissions-Policy for feature control
 *
 * @author SPEC-SEC-001 Implementation
 * @version 1.0.0
 */

declare(strict_types=1);

/**
 * Send all security headers
 *
 * Sends comprehensive security headers for the current response.
 *
 * @return string The CSP nonce for use in inline scripts
 */
function sendSecurityHeaders(): string
{
    // Prevent clickjacking
    header('X-Frame-Options: DENY');

    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // Enable XSS filter (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');

    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Prevent feature policy abuse
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // HTTPS enforcement (production only)
    if (isProductionEnvironment()) {
        $hstsMaxAge = 31536000; // 1 year
        header("Strict-Transport-Security: max-age=$hstsMaxAge; includeSubDomains; preload");
    }

    // Content Security Policy with nonce
    $nonce = generateCspNonce();
    $csp = buildContentSecurityPolicy($nonce);
    header("Content-Security-Policy: $csp");

    return $nonce;
}

/**
 * Generate CSP nonce
 *
 * Creates a cryptographically secure random nonce for inline scripts.
 *
 * @return string The generated nonce
 */
function generateCspNonce(): string
{
    return base64_encode(random_bytes(16));
}

/**
 * Build Content-Security-Policy header value
 *
 * Constructs a restrictive CSP with nonce for inline scripts.
 *
 * @param string $nonce The CSP nonce
 * @return string The CSP header value
 */
function buildContentSecurityPolicy(string $nonce): string
{
    $directives = [
        'default-src' => "'self'",
        // unsafe-inline required: inline <script> blocks with json_encode for Chart.js data
        // and ~160 style= attributes across templates. Nonce would break both.
        'script-src' => "'self' 'unsafe-inline'",
        'style-src' => "'self' 'unsafe-inline' https://fonts.googleapis.com",
        'img-src' => "'self' data:",
        'font-src' => "'self' https://fonts.gstatic.com",
        'connect-src' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'none'",
        'base-uri' => "'self'",
        'object-src' => "'none'"
    ];

    $policyParts = [];
    foreach ($directives as $directive => $value) {
        $policyParts[] = "$directive $value";
    }

    return implode('; ', $policyParts);
}

/**
 * Get CSP nonce for use in HTML templates
 *
 * Returns the nonce if it was already generated, or generates a new one.
 *
 * @return string The CSP nonce
 */
function getCspNonce(): string
{
    static $nonce = null;

    if ($nonce === null) {
        $nonce = generateCspNonce();
    }

    return $nonce;
}

/**
 * Render nonce attribute for inline scripts
 *
 * @return string The nonce attribute string
 */
function renderNonceAttribute(): string
{
    $nonce = getCspNonce();
    return ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
}

/**
 * Check if application is in production environment
 *
 * @return bool True if production environment
 */
function isProductionEnvironment(): bool
{
    return defined('APP_ENV') && APP_ENV === 'production';
}

/**
 * Get all security headers as array
 *
 * Returns the security headers that would be sent.
 * Useful for testing and debugging.
 *
 * @return array Associative array of header names and values
 */
function getSecurityHeaders(): array
{
    $nonce = getCspNonce();

    $headers = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        'Content-Security-Policy' => buildContentSecurityPolicy($nonce)
    ];

    if (isProductionEnvironment()) {
        $hstsMaxAge = 31536000;
        $headers['Strict-Transport-Security'] = "max-age=$hstsMaxAge; includeSubDomains; preload";
    }

    return $headers;
}

/**
 * Send security headers with custom CSP
 *
 * Allows customization of CSP directives if needed.
 *
 * @param array $customDirectives Custom CSP directives (key-value pairs)
 * @return string The CSP nonce
 */
function sendCustomSecurityHeaders(array $customDirectives = []): string
{
    // Send standard security headers (except CSP)
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // HTTPS enforcement in production
    if (isProductionEnvironment()) {
        $hstsMaxAge = 31536000;
        header("Strict-Transport-Security: max-age=$hstsMaxAge; includeSubDomains; preload");
    }

    // Build and send CSP with custom directives
    $nonce = generateCspNonce();
    $csp = buildCustomContentSecurityPolicy($nonce, $customDirectives);
    header("Content-Security-Policy: $csp");

    return $nonce;
}

/**
 * Build custom Content-Security-Policy
 *
 * @param string $nonce The CSP nonce
 * @param array $customDirectives Custom directives to override defaults
 * @return string The CSP header value
 */
function buildCustomContentSecurityPolicy(string $nonce, array $customDirectives): string
{
    $defaultDirectives = [
        'default-src' => "'self'",
        'script-src' => "'self' 'unsafe-inline'",
        'style-src' => "'self' 'unsafe-inline' https://fonts.googleapis.com",
        'img-src' => "'self' data:",
        'font-src' => "'self' https://fonts.gstatic.com",
        'connect-src' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'none'",
        'base-uri' => "'self'"
    ];

    // Merge custom directives with defaults
    $directives = array_merge($defaultDirectives, $customDirectives);

    $policyParts = [];
    foreach ($directives as $directive => $value) {
        $policyParts[] = "$directive $value";
    }

    return implode('; ', $policyParts);
}

/**
 * Report Content-Security-Policy violations
 *
 * Endpoint handler for CSP violation reports.
 * Logs violations for security monitoring.
 *
 * @return void
 */
function handleCspViolationReport(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        exit;
    }

    $input = file_get_contents('php://input');
    $report = json_decode($input, true);

    if (!isset($report['csp-report'])) {
        http_response_code(400);
        echo 'Invalid report format';
        exit;
    }

    $violation = $report['csp-report'];

    // Log CSP violation
    logSecurityEvent('csp_violation', [
        'document-uri' => $violation['document-uri'] ?? 'unknown',
        'referrer' => $violation['referrer'] ?? 'unknown',
        'violated-directive' => $violation['violated-directive'] ?? 'unknown',
        'effective-directive' => $violation['effective-directive'] ?? 'unknown',
        'original-policy' => $violation['original-policy'] ?? 'unknown',
        'blocked-uri' => $violation['blocked-uri'] ?? 'unknown',
        'status-code' => $violation['status-code'] ?? 0
    ], 'info');

    http_response_code(204);
    exit;
}

/**
 * Add CSP report-uri to policy
 *
 * Modifies CSP to include violation reporting endpoint.
 *
 * @param string $reportUri The URI to send reports to
 * @return string The CSP with report-uri
 */
function addCspReportUri(string $reportUri): string
{
    $nonce = getCspNonce();
    $csp = buildContentSecurityPolicy($nonce);
    $csp .= ", report-uri $reportUri";

    return $csp;
}
