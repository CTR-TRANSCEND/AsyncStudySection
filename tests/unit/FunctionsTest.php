<?php
/**
 * Utility Functions Unit Tests
 *
 * RED PHASE: These tests define expected behavior of utility functions.
 * Tests will fail initially until functions match requirements.
 *
 * Test Categories:
 * - Input sanitization functions
 * - Output escaping functions
 * - Date/time formatting functions
 * - Array manipulation functions
 * - File path validation functions
 */

namespace GrantReview\Tests\Unit;

use GrantReview\Tests\TestCase;

class FunctionsTest extends TestCase
{
    /**
     * Input Sanitization Tests
     */

    /**
     * Test that escape() converts HTML special characters
     *
     * GIVEN: A string with HTML special characters
     * WHEN: escape() is called
     * THEN: Characters should be converted to HTML entities
     */
    public function testEscapeConvertsHtmlSpecialCharacters(): void
    {
        $input = '<script>alert("XSS")</script>';
        $escaped = escape($input);

        $this->assertNotEquals($input, $escaped);
        $this->assertStringContainsString('&lt;', $escaped);
        $this->assertStringContainsString('&gt;', $escaped);
        $this->assertStringNotContainsString('<script>', $escaped);
    }

    /**
     * Test that escape() handles quotes correctly
     *
     * GIVEN: A string with quotes
     * WHEN: escape() is called
     * THEN: Quotes should be converted to HTML entities
     */
    public function testEscapeHandlesQuotesCorrectly(): void
    {
        $input = "'quoted' \"double quoted\"";
        $escaped = escape($input);

        // ENT_HTML5 produces &apos; for single quotes; accept either form
        $this->assertTrue(
            str_contains($escaped, '&#039;') || str_contains($escaped, '&apos;'),
            "Expected single quote to be escaped as &#039; or &apos;"
        );
        $this->assertStringContainsString('&quot;', $escaped);
    }

    /**
     * Test that escape() preserves UTF-8 characters
     *
     * GIVEN: A string with UTF-8 characters
     * WHEN: escape() is called
     * THEN: UTF-8 characters should be preserved
     */
    public function testEscapePreservesUtf8Characters(): void
    {
        $input = 'Hello 世界 🌍';
        $escaped = escape($input);

        $this->assertStringContainsString('世界', $escaped);
        $this->assertStringContainsString('🌍', $escaped);
    }

    /**
     * Test that sanitize() removes HTML tags
     *
     * GIVEN: A string with HTML tags
     * WHEN: sanitize() is called
     * THEN: Tags should be removed
     */
    public function testSanitizeRemovesHtmlTags(): void
    {
        $input = '<p>Hello <b>World</b></p>';
        $sanitized = sanitize($input);

        $this->assertStringNotContainsString('<p>', $sanitized);
        $this->assertStringNotContainsString('<b>', $sanitized);
        $this->assertEquals('Hello World', $sanitized);
    }

    /**
     * Test that sanitize() trims whitespace
     *
     * GIVEN: A string with leading/trailing whitespace
     * WHEN: sanitize() is called
     * THEN: Whitespace should be trimmed
     */
    public function testSanitizeTrimsWhitespace(): void
    {
        $input = '  test content  ';
        $sanitized = sanitize($input);

        $this->assertEquals('test content', $sanitized);
    }

    /**
     * Test that sanitizeRichText() removes dangerous HTML
     *
     * GIVEN: HTML with script tags
     * WHEN: sanitizeRichText() is called
     * THEN: Script tags should be removed
     */
    public function testSanitizeRichTextRemovesDangerousHtml(): void
    {
        $input = '<p>Safe content</p><script>alert("XSS")</script>';
        $sanitized = sanitizeRichText($input);

        $this->assertStringContainsString('<p>Safe content</p>', $sanitized);
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('alert', $sanitized);
    }

    /**
     * Test that sanitizeRichText() removes event handlers
     *
     * GIVEN: HTML with onclick event
     * WHEN: sanitizeRichText() is called
     * THEN: Event handlers should be removed
     */
    public function testSanitizeRichTextRemovesEventHandlers(): void
    {
        $input = '<p onclick="alert("XSS")">Click me</p>';
        $sanitized = sanitizeRichText($input);

        $this->assertStringNotContainsString('onclick', $sanitized);
    }

    /**
     * Test that sanitizeRichText() removes javascript: links
     *
     * GIVEN: HTML with javascript: href
     * WHEN: sanitizeRichText() is called
     * THEN: JavaScript links should be removed
     */
    public function testSanitizeRichTextRemovesJavaScriptLinks(): void
    {
        $input = '<a href="javascript:alert("XSS")">Click</a>';
        $sanitized = sanitizeRichText($input);

        $this->assertStringNotContainsString('javascript:', $sanitized);
    }

    /**
     * Test that sanitizeRichText() preserves safe HTML
     *
     * GIVEN: HTML with safe tags (p, br, strong, em)
     * WHEN: sanitizeRichText() is called
     * THEN: Safe tags should be preserved
     */
    public function testSanitizeRichTextPreservesSafeHtml(): void
    {
        $input = '<p><strong>Bold</strong> and <em>italic</em></p><br>';
        $sanitized = sanitizeRichText($input);

        $this->assertStringContainsString('<p>', $sanitized);
        $this->assertStringContainsString('<strong>', $sanitized);
        $this->assertStringContainsString('<em>', $sanitized);
        $this->assertStringContainsString('<br', $sanitized); // accepts <br> and <br />
    }

    /**
     * Test that sanitizeRichText() enforces maximum length
     *
     * GIVEN: HTML content exceeding maxLength
     * WHEN: sanitizeRichText() is called
     * THEN: Content should be truncated to maxLength
     */
    public function testSanitizeRichTextEnforcesMaximumLength(): void
    {
        $input = '<p>' . str_repeat('A', 10000) . '</p>';
        $sanitized = sanitizeRichText($input, 100);

        $this->assertLessThanOrEqual(100, mb_strlen($sanitized));
    }

    /**
     * Date/Time Formatting Tests
     */

    /**
     * Test that formatDateTime() formats timestamps correctly
     *
     * GIVEN: A valid timestamp
     * WHEN: formatDateTime() is called
     * THEN: Should return formatted date/time string
     */
    public function testFormatDateTimeFormatsTimestampsCorrectly(): void
    {
        $timestamp = '2024-01-15 14:30:00';
        $formatted = formatDateTime($timestamp);

        $this->assertNotEquals('N/A', $formatted);
        $this->assertStringContainsString('2024', $formatted);
        $this->assertStringContainsString('Jan', $formatted);
    }

    /**
     * Test that formatDateTime() handles null input
     *
     * GIVEN: Null or empty timestamp
     * WHEN: formatDateTime() is called
     * THEN: Should return 'N/A'
     */
    public function testFormatDateTimeHandlesNullInput(): void
    {
        $this->assertEquals('N/A', formatDateTime(null));
        $this->assertEquals('N/A', formatDateTime(''));
        $this->assertEquals('N/A', formatDateTime(false));
    }

    /**
     * Test that formatDate() formats dates correctly
     *
     * GIVEN: A valid date
     * WHEN: formatDate() is called
     * THEN: Should return formatted date string
     */
    public function testFormatDateFormatsDatesCorrectly(): void
    {
        $date = '2024-01-15';
        $formatted = formatDate($date);

        $this->assertNotEquals('N/A', $formatted);
        $this->assertStringContainsString('Jan', $formatted);
        $this->assertStringContainsString('2024', $formatted);
    }

    /**
     * Test that formatDate() handles null input
     *
     * GIVEN: Null or empty date
     * WHEN: formatDate() is called
     * THEN: Should return 'N/A'
     */
    public function testFormatDateHandlesNullInput(): void
    {
        $this->assertEquals('N/A', formatDate(null));
        $this->assertEquals('N/A', formatDate(''));
    }

    /**
     * Array Manipulation Tests
     */

    /**
     * Test that indexToLetters() converts indices to letters
     *
     * GIVEN: Numeric index
     * WHEN: indexToLetters() is called
     * THEN: Should return corresponding letters (0=A, 1=B, 26=AA, etc.)
     */
    public function testIndexToLettersConvertsIndicesToLetters(): void
    {
        $this->assertEquals('A', indexToLetters(0));
        $this->assertEquals('B', indexToLetters(1));
        $this->assertEquals('Z', indexToLetters(25));
        $this->assertEquals('AA', indexToLetters(26));
        $this->assertEquals('AB', indexToLetters(27));
    }

    /**
     * Test that generateAnonymousLabel() generates unique labels
     *
     * GIVEN: Existing labels array
     * WHEN: generateAnonymousLabel() is called
     * THEN: Should return unique label not in existing labels
     */
    public function testGenerateAnonymousLabelGeneratesUniqueLabels(): void
    {
        $existingLabels = ['Reviewer A', 'Reviewer B', 'Reviewer C'];
        $newLabel = generateAnonymousLabel($existingLabels);

        $this->assertStringStartsWith('Reviewer ', $newLabel);
        $this->assertNotContains($newLabel, $existingLabels);
    }

    /**
     * Test that generateAnonymousLabel() handles empty array
     *
     * GIVEN: Empty existing labels array
     * WHEN: generateAnonymousLabel() is called
     * THEN: Should return 'Reviewer A'
     */
    public function testGenerateAnonymousLabelHandlesEmptyArray(): void
    {
        $existingLabels = [];
        $newLabel = generateAnonymousLabel($existingLabels);

        $this->assertEquals('Reviewer A', $newLabel);
    }

    /**
     * Score and Validation Tests
     */

    /**
     * Test that getScoreLabel() returns correct label
     *
     * GIVEN: Numeric score (1-9)
     * WHEN: getScoreLabel() is called
     * THEN: Should return corresponding text label
     */
    public function testGetScoreLabelReturnsCorrectLabel(): void
    {
        $this->assertEquals('Exceptional', getScoreLabel(1));
        $this->assertEquals('Outstanding', getScoreLabel(2));
        $this->assertEquals('Excellent', getScoreLabel(3));
        $this->assertEquals('Good', getScoreLabel(5));
        $this->assertEquals('Poor', getScoreLabel(9));
    }

    /**
     * Test that getScoreLabel() handles invalid scores
     *
     * GIVEN: Invalid score
     * WHEN: getScoreLabel() is called
     * THEN: Should return 'Unknown'
     */
    public function testGetScoreLabelHandlesInvalidScores(): void
    {
        $this->assertEquals('Unknown', getScoreLabel(0));
        $this->assertEquals('Unknown', getScoreLabel(10));
        $this->assertEquals('Unknown', getScoreLabel(-1));
    }

    /**
     * Test that getScoreColorClass() returns correct class
     *
     * GIVEN: Numeric score
     * WHEN: getScoreColorClass() is called
     * THEN: Should return appropriate CSS class
     */
    public function testGetScoreColorClassReturnsCorrectClass(): void
    {
        $this->assertEquals('score-excellent', getScoreColorClass(1));
        $this->assertEquals('score-excellent', getScoreColorClass(2));
        $this->assertEquals('score-good', getScoreColorClass(4));
        $this->assertEquals('score-fair', getScoreColorClass(6));
        $this->assertEquals('score-poor', getScoreColorClass(9));
    }

    /**
     * Test that isValidScore() validates score range
     *
     * GIVEN: Score value
     * WHEN: isValidScore() is called
     * THEN: Should return true for 1-9, false otherwise
     */
    public function testIsValidScoreValidatesScoreRange(): void
    {
        $this->assertTrue(isValidScore(1));
        $this->assertTrue(isValidScore(5));
        $this->assertTrue(isValidScore(9));
        $this->assertFalse(isValidScore(0));
        $this->assertFalse(isValidScore(10));
        $this->assertFalse(isValidScore(-1));
        $this->assertFalse(isValidScore('invalid'));
    }

    /**
     * CSRF Token Tests
     */

    /**
     * Test that getCsrfToken() generates token
     *
     * GIVEN: Active session
     * WHEN: getCsrfToken() is called
     * THEN: Should generate random token
     */
    public function testGetCsrfTokenGeneratesToken(): void
    {
        // Start session if not active
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Clear existing token
        unset($_SESSION['csrf_token']);

        $token = getCsrfToken();

        $this->assertNotEmpty($token);
        $this->assertGreaterThanOrEqual(64, strlen($token)); // 32 bytes = 64 hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $token);
    }

    /**
     * Test that getCsrfToken() reuses existing token
     *
     * GIVEN: Session with existing CSRF token
     * WHEN: getCsrfToken() is called
     * THEN: Should return existing token
     */
    public function testGetCsrfTokenReusesExistingToken(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Set token
        $_SESSION['csrf_token'] = 'existing_token_1234567890abcdef';

        $token = getCsrfToken();

        $this->assertEquals('existing_token_1234567890abcdef', $token);
    }

    /**
     * Test that csrfField() generates hidden input
     *
     * GIVEN: CSRF token exists
     * WHEN: csrfField() is called
     * THEN: Should return hidden input HTML
     */
    public function testCsrfFieldGeneratesHiddenInput(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['csrf_token'] = 'test_token';

        $field = csrfField();

        $this->assertStringContainsString('<input', $field);
        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="csrf_token"', $field);
        $this->assertStringContainsString('value="test_token"', $field);
    }

    /**
     * File and URL Tests
     */

    /**
     * Test that safeUrl() validates URLs
     *
     * GIVEN: URL string
     * WHEN: safeUrl() is called
     * THEN: Should return valid URLs, empty for invalid
     */
    public function testSafeUrlValidatesUrls(): void
    {
        $this->assertEquals('http://example.com', safeUrl('http://example.com'));
        $this->assertEquals('https://example.com', safeUrl('https://example.com'));
        $this->assertEquals('/path/to/resource', safeUrl('/path/to/resource'));
        $this->assertEquals('', safeUrl('javascript:alert(1)'));
        $this->assertEquals('', safeUrl('invalid url'));
    }

    /**
     * Test that safeUrl() trims whitespace
     *
     * GIVEN: URL with whitespace
     * WHEN: safeUrl() is called
     * THEN: Should return trimmed URL or empty string
     */
    public function testSafeUrlTrimsWhitespace(): void
    {
        $this->assertEquals('http://example.com', safeUrl('  http://example.com  '));
        $this->assertEquals('', safeUrl('   '));
    }

    /**
     * Test that generateRandomString() generates random strings
     *
     * GIVEN: Length parameter
     * WHEN: generateRandomString() is called
     * THEN: Should return random hex string of specified length
     */
    public function testGenerateRandomStringGeneratesRandomStrings(): void
    {
        $string1 = generateRandomString(32);
        $string2 = generateRandomString(32);

        $this->assertEquals(32, strlen($string1));
        $this->assertEquals(32, strlen($string2));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $string1);
        $this->assertNotEquals($string1, $string2); // Randomness
    }

    /**
     * Test that generateStoredFilename() generates unique filenames
     *
     * GIVEN: Original filename
     * WHEN: generateStoredFilename() is called
     * THEN: Should return random filename with same extension
     */
    public function testGenerateStoredFilenameGeneratesUniqueFilenames(): void
    {
        $filename1 = generateStoredFilename('document.docx');
        $filename2 = generateStoredFilename('document.docx');

        $this->assertStringEndsWith('.docx', $filename1);
        $this->assertStringEndsWith('.docx', $filename2);
        $this->assertNotEquals($filename1, $filename2); // Uniqueness
        $this->assertEquals(32 + 5, strlen($filename1)); // 32 char hex + .docx (5)
    }

    /**
     * Test that generateStoredFilename() handles no extension
     *
     * GIVEN: Filename without extension
     * WHEN: generateStoredFilename() is called
     * THEN: Should return random filename without extension
     */
    public function testGenerateStoredFilenameHandlesNoExtension(): void
    {
        $filename = generateStoredFilename('README');

        $this->assertDoesNotMatchRegularExpression('/\./', $filename);
        $this->assertEquals(32, strlen($filename)); // 32 char hex
    }
}
