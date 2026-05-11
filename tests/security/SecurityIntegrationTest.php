<?php
/**
 * Security Integration Test Suite
 *
 * SPEC-SEC-001: Integration tests for security implementations
 *
 * These tests validate the actual security implementations.
 * All files are loaded before testing.
 *
 * @author SPEC-SEC-001 Implementation
 * @version 1.0.0
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SecurityIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        // Start session for tests
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('TEST_SESSION');
            session_start();
        }

        // Clear session data
        unset($_SESSION['csrf_tokens']);
        unset($_SESSION['security']);
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /**
     * ============================================================
     * CSRF PROTECTION INTEGRATION TESTS
     * ============================================================
     */

    /**
     * Test: CSRF token generation and validation
     * @test
     */
    public function csrfTokenGenerationAndValidation(): void
    {
        // Given: Session is active
        $this->assertTrue(session_status() === PHP_SESSION_ACTIVE);

        // When: Token is generated
        require_once __DIR__ . '/../../includes/csrf_enhanced.php';
        $token = generateCsrfToken();

        // Then: Token should be 64-character hex string
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        // And: Token should be valid
        $this->assertTrue(validateCsrfToken($token));
    }

    /**
     * Test: CSRF token expiration
     * @test
     */
    public function csrfTokenExpiration(): void
    {
        require_once __DIR__ . '/../../includes/csrf_enhanced.php';

        // Given: Token generated
        $token = generateCsrfToken();

        // When: Token expiration is modified to past
        $_SESSION['csrf_tokens'][$token]['expires_at'] = time() - 1;

        // Then: Token should be invalid
        $this->assertFalse(validateCsrfToken($token));
    }

    /**
     * Test: CSRF token one-time use
     * @test
     */
    public function csrfTokenOneTimeUse(): void
    {
        require_once __DIR__ . '/../../includes/csrf_enhanced.php';

        // Given: Valid token
        $token = generateCsrfToken();

        // When: Validated twice
        $firstValidation = validateCsrfToken($token);
        $secondValidation = validateCsrfToken($token);

        // Then: First succeeds, second fails
        $this->assertTrue($firstValidation);
        $this->assertFalse($secondValidation);
    }

    /**
     * ============================================================
     * XSS PROTECTION INTEGRATION TESTS
     * ============================================================
     */

    /**
     * Test: HTML escaping prevents script execution
     * @test
     */
    public function htmlEscapingPreventsXss(): void
    {
        require_once __DIR__ . '/../../includes/sanitize_enhanced.php';

        // Given: Malicious script
        $input = "<script>alert('XSS')</script>";

        // When: Escaped for HTML
        $escaped = escapeHtml($input);

        // Then: Should be neutralized
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }

    /**
     * Test: JavaScript escaping neutralizes special characters
     * @test
     */
    public function javascriptEscapingPreventsXss(): void
    {
        require_once __DIR__ . '/../../includes/sanitize_enhanced.php';

        // Given: Malicious JavaScript
        $input = "';alert('XSS');//";

        // When: Escaped for JavaScript
        $escaped = escapeJs($input);

        // Then: Special characters should be hex-encoded
        $this->assertStringNotContainsString("'", $escaped);
        $this->assertStringNotContainsString(';', $escaped);
    }

    /**
     * ============================================================
     * DATABASE VALIDATION INTEGRATION TESTS
     * ============================================================
     */

    /**
     * Test: Sort column whitelist validation
     * @test
     */
    public function sortColumnWhitelistValidation(): void
    {
        require_once __DIR__ . '/../../includes/database_helpers.php';

        // Given: Allowed columns
        $allowed = ['username', 'email', 'created_at'];

        // When: Valid column requested
        $result1 = getValidSortColumn('username', $allowed);

        // Then: Should return valid column
        $this->assertSame('username', $result1);

        // When: Invalid column (SQL injection attempt)
        $result2 = getValidSortColumn("username; DROP TABLE users; --", $allowed);

        // Then: Should return default
        $this->assertSame('username', $result2);
    }

    /**
     * Test: Sort direction validation
     * @test
     */
    public function sortDirectionValidation(): void
    {
        require_once __DIR__ . '/../../includes/database_helpers.php';

        // When: Valid directions
        $result1 = getValidSortDirection('ASC');
        $result2 = getValidSortDirection('DESC');

        // Then: Should pass
        $this->assertSame('ASC', $result1);
        $this->assertSame('DESC', $result2);

        // When: Invalid direction (injection attempt)
        $result3 = getValidSortDirection("ASC; DROP TABLE users; --");

        // Then: Should default to ASC
        $this->assertSame('ASC', $result3);
    }

    /**
     * ============================================================
     * SECURITY HEADERS INTEGRATION TESTS
     * ============================================================
     */

    /**
     * Test: Security headers are generated
     * @test
     */
    public function securityHeadersGenerated(): void
    {
        require_once __DIR__ . '/../../includes/security_headers.php';

        // When: Security headers are retrieved
        $headers = getSecurityHeaders();

        // Then: Should contain all required headers
        $this->assertArrayHasKey('X-Frame-Options', $headers);
        $this->assertArrayHasKey('X-Content-Type-Options', $headers);
        $this->assertArrayHasKey('X-XSS-Protection', $headers);
        $this->assertArrayHasKey('Referrer-Policy', $headers);
        $this->assertArrayHasKey('Permissions-Policy', $headers);
        $this->assertArrayHasKey('Content-Security-Policy', $headers);

        // And: Values should be correct
        $this->assertSame('DENY', $headers['X-Frame-Options']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    }

    /**
     * Test: CSP nonce generation
     * @test
     */
    public function cspNonceGeneration(): void
    {
        require_once __DIR__ . '/../../includes/security_headers.php';

        // When: Nonce is generated
        $nonce = generateCspNonce();

        // Then: Should be base64 string
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9\/+=]+$/', $nonce);

        // And: CSP should contain script-src directive
        $headers = getSecurityHeaders();
        $this->assertStringContainsString('script-src', $headers['Content-Security-Policy']);
    }

    /**
     * ============================================================
     * SESSION SECURITY INTEGRATION TESTS
     * ============================================================
     */

    /**
     * Test: Secure session initialization
     * @test
     */
    public function secureSessionInitialization(): void
    {
        require_once __DIR__ . '/../../includes/session.php';

        // Destroy existing session so secureSessionStart() can reinitialize
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];

        // When: Secure session is initialized
        secureSessionStart();

        // Then: Security metadata should be present
        $this->assertArrayHasKey('security', $_SESSION);
        $this->assertArrayHasKey('ip_address', $_SESSION['security']);
        $this->assertArrayHasKey('user_agent', $_SESSION['security']);
        $this->assertArrayHasKey('created_at', $_SESSION['security']);
        $this->assertArrayHasKey('last_validated', $_SESSION['security']);
    }

    /**
     * Test: Session cookie parameters
     * @test
     */
    public function sessionCookieParameters(): void
    {
        require_once __DIR__ . '/../../includes/session.php';

        // Ensure secure session is initialized with proper params
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        secureSessionStart();

        // When: Session cookie params are retrieved
        $params = getSessionCookieParams();

        // Then: Should have secure parameters
        $this->assertIsArray($params);
        $this->assertArrayHasKey('lifetime', $params);
        $this->assertArrayHasKey('path', $params);
        $this->assertArrayHasKey('httponly', $params);
        $this->assertTrue($params['httponly']);
    }

    /**
     * ============================================================
     * FILE VALIDATION INTEGRATION TESTS
     * ============================================================
     */

    /**
     * Test: Filename validation
     * @test
     */
    public function filenameValidation(): void
    {
        require_once __DIR__ . '/../../includes/file_validation_enhanced.php';

        // Valid filenames
        $this->assertTrue(isValidFilename('document.docx'));
        $this->assertTrue(isValidFilename('my-file_v1.docx'));
        $this->assertTrue(isValidFilename('file with spaces.docx'));

        // Invalid filenames (directory traversal)
        $this->assertFalse(isValidFilename('../etc/passwd'));
        $this->assertFalse(isValidFilename('..\\windows\\system32\\config'));
        $this->assertFalse(isValidFilename('../../../malicious'));
        $this->assertFalse(isValidFilename("file\0.exe"));
    }

    /**
     * Test: LIKE query escaping
     * @test
     */
    public function likeQueryEscaping(): void
    {
        require_once __DIR__ . '/../../includes/database_helpers.php';

        // Given: Input with wildcards
        $input = "test%_";

        // When: Escaped for LIKE
        $escaped = escapeLike($input);

        // Then: Wildcards should be escaped
        $this->assertStringContainsString('\\%', $escaped);
        $this->assertStringContainsString('\\_', $escaped);
    }

    protected function tearDown(): void
    {
        // Clean up session
        $_SESSION = [];
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up
    }
}
