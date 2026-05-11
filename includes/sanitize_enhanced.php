<?php
/**
 * Context-Aware Output Escaping Implementation
 *
 * SPEC-SEC-001: XSS Prevention with Context-Aware Encoding
 *
 * Features:
 * - HTML context escaping (htmlspecialchars with ENT_QUOTES | ENT_HTML5)
 * - JavaScript context escaping (json_encode with HEX flags)
 * - URL context escaping (rawurlencode)
 * - CSS context escaping
 * - Attribute context escaping
 *
 * @author SPEC-SEC-001 Implementation
 * @version 1.0.0
 */

declare(strict_types=1);

/**
 * Escape string for HTML context
 *
 * Converts special characters to HTML entities to prevent XSS.
 * Uses ENT_QUOTES | ENT_HTML5 flags for comprehensive protection.
 *
 * @param string $string The string to escape
 * @return string The escaped string safe for HTML output
 */
function escapeHtml(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Escape string for HTML attribute context
 *
 * Similar to escapeHtml but specifically designed for attribute values.
 * Handles quotes, angle brackets, and other special characters.
 *
 * @param string $string The string to escape
 * @return string The escaped string safe for HTML attributes
 */
function escapeHtmlAttr(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Escape string for JavaScript context
 *
 * OWASP-recommended: hex-encode all non-alphanumeric characters
 * using \uXXXX sequences to prevent any injection in JS string contexts.
 *
 * @param string $string The string to escape
 * @return string The escaped string safe for JavaScript context
 */
function escapeJs(string $string): string
{
    $result = '';
    $length = mb_strlen($string, 'UTF-8');
    for ($i = 0; $i < $length; $i++) {
        $char = mb_substr($string, $i, 1, 'UTF-8');
        $ord = mb_ord($char, 'UTF-8');
        if (ctype_alnum($char)) {
            $result .= $char;
        } else {
            $result .= sprintf('\\u%04x', $ord);
        }
    }
    return $result;
}

/**
 * Escape string for URL context
 *
 * Encodes string for use in URL query parameters.
 * Uses rawurlencode for RFC 3986 compliance.
 *
 * @param string $string The string to escape
 * @return string The escaped string safe for URL parameters
 */
function escapeUrl(string $string): string
{
    return rawurlencode($string);
}

/**
 * Escape string for CSS context
 *
 * Escapes special characters for use in CSS strings.
 * Converts characters outside printable ASCII range to hex escapes.
 *
 * @param string $string The string to escape
 * @return string The escaped string safe for CSS context
 */
function escapeCss(string $string): string
{
    $result = '';
    $length = strlen($string);

    for ($i = 0; $i < $length; $i++) {
        $char = $string[$i];
        $code = ord($char);

        // Allow printable ASCII (32-126) except backslash and quote
        if ($code >= 32 && $code <= 126 && $code !== 92 && $code !== 39 && $code !== 34) {
            $result .= $char;
        } else {
            // Convert to hex escape: \XX XX
            $result .= '\\' . dechex($code) . ' ';
        }
    }

    return $result;
}

/**
 * Escape string for XML context
 *
 * Similar to HTML escaping but for XML documents.
 * Converts &, <, >, ", ' to entities.
 *
 * @param string $string The string to escape
 * @return string The escaped string safe for XML output
 */
function escapeXml(string $string): string
{
    $string = str_replace('&', '&amp;', $string);
    $string = str_replace('<', '&lt;', $string);
    $string = str_replace('>', '&gt;', $string);
    $string = str_replace('"', '&quot;', $string);
    $string = str_replace("'", '&apos;', $string);
    return $string;
}

/**
 * Legacy escape function for backward compatibility
 *
 * @param string $string The string to escape
 * @return string The escaped string
 * @deprecated Use escapeHtml() instead
 */
if (!function_exists('escape')) {
function escape($string): string
{
    return escapeHtml($string);
}
}

/**
 * Sanitize user input by removing tags and trimming
 *
 * @param string $string The string to sanitize
 * @return string The sanitized string
 */
if (!function_exists('sanitize')) {
function sanitize(string $string): string
{
    return trim(strip_tags($string));
}
}

/**
 * Sanitize email address
 *
 * Validates and sanitizes email input.
 *
 * @param string $email The email to sanitize
 * @return string The sanitized email, or empty string if invalid
 */
function sanitizeEmail(string $email): string
{
    $email = trim($email);
    if ($email === '') {
        return '';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    return $email;
}

/**
 * Sanitize username
 *
 * Removes invalid characters from username.
 * Allows only alphanumeric, underscore, hyphen, and dot.
 *
 * @param string $username The username to sanitize
 * @return string The sanitized username
 */
function sanitizeUsername(string $username): string
{
    $username = trim($username);
    return preg_replace('/[^a-zA-Z0-9_.-]/', '', $username);
}

/**
 * Sanitize integer input
 *
 * Converts input to integer or returns default.
 *
 * @param mixed $input The input to sanitize
 * @param int $default The default value if conversion fails
 * @return int The sanitized integer
 */
function sanitizeInt($input, int $default = 0): int
{
    if (is_numeric($input)) {
        return (int) $input;
    }
    return $default;
}

/**
 * Sanitize string length
 *
 * Truncates string to maximum length if needed.
 *
 * @param string $string The string to sanitize
 * @param int $maxLength Maximum allowed length
 * @return string The sanitized string
 */
function sanitizeLength(string $string, int $maxLength): string
{
    if (strlen($string) > $maxLength) {
        return substr($string, 0, $maxLength);
    }
    return $string;
}

/**
 * Escape array of strings for HTML context
 *
 * @param array $array The array to escape
 * @return array The escaped array
 */
function escapeArray(array $array): array
{
    return array_map('escapeHtml', $array);
}

/**
 * Escape array for JSON output
 *
 * Uses json_encode with HEX flags for JavaScript safety.
 *
 * @param array $data The data to encode
 * @return string The JSON-encoded string
 */
function escapeJson(array $data): string
{
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return $json === false ? '{}' : $json;
}

if (!function_exists('sanitizeRichText')) {
function sanitizeRichText(string $html, int $maxLength = 0): string {
    static $purifier = null;
    if ($purifier === null) {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,i,u,strong,em,ul,ol,li,a[href],h1,h2,h3,h4,h5,h6,blockquote,pre,code,table,thead,tbody,tr,td,th');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.Nofollow', true);
        $config->set('HTML.TargetBlank', true);
        $config->set('Output.Newline', "\n");
        $config->set('Cache.DefinitionImpl', null);
        $purifier = new HTMLPurifier($config);
    }
    $result = trim($purifier->purify($html));
    if ($maxLength > 0 && mb_strlen($result) > $maxLength) {
        $result = mb_substr($result, 0, $maxLength);
    }
    return $result;
}
}
