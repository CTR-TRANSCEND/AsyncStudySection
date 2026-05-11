<?php
/**
 * Health Check Tests
 *
 * Test suite for health check endpoints
 * Tests /health, /health/ready, /health/live endpoints
 *
 * @package Tests
 * @subpackage DevOps
 * @author TDD Implementation Agent
 * @version 1.0.0
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * HealthCheckTest
 *
 * Tests for health check endpoint functionality
 * Validates that health checks return correct status and information
 */
class HealthCheckTest extends TestCase
{
    /**
     * @var string Path to health.php
     */
    private string $healthFilePath;

    /**
     * Set up test dependencies
     */
    protected function setUp(): void
    {
        $this->healthFilePath = __DIR__ . '/../health.php';
    }

    /**
     * TEST: health.php exists
     *
     * Given the project root directory
     * When I check for health.php
     * Then the file should exist
     */
    public function testHealthFileExists(): void
    {
        $this->assertFileExists(
            $this->healthFilePath,
            'health.php must exist in project root'
        );
    }

    /**
     * TEST: health.php returns JSON response
     *
     * Given health.php exists
     * When I execute the health check
     * Then the response should be JSON format
     */
    public function testHealthReturnsJson(): void
    {
        $this->assertFileExists($this->healthFilePath);

        // Check file content contains JSON header
        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            'Content-Type: application/json',
            $content,
            'health.php must return JSON content type'
        );
    }

    /**
     * TEST: health.php includes status field
     *
     * Given health.php exists
     * When I read its contents
     * Then it should include status field in response
     */
    public function testHealthIncludesStatus(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            "'status'",
            $content,
            'health.php must include status field'
        );
    }

    /**
     * TEST: health.php includes timestamp field
     *
     * Given health.php exists
     * When I read its contents
     * Then it should include timestamp field in response
     */
    public function testHealthIncludesTimestamp(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            "'timestamp'",
            $content,
            'health.php must include timestamp field'
        );
    }

    /**
     * TEST: health.php includes database check
     *
     * Given health.php exists
     * When I read its contents
     * Then it should check database connectivity
     */
    public function testHealthIncludesDatabaseCheck(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            "'database'",
            $content,
            'health.php must include database check'
        );
    }

    /**
     * TEST: health.php includes disk space check
     *
     * Given health.php exists
     * When I read its contents
     * Then it should check disk space
     */
    public function testHealthIncludesDiskCheck(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            "'disk'",
            $content,
            'health.php must include disk space check'
        );

        $this->assertStringContainsString(
            'disk_free_space',
            $content,
            'health.php must use disk_free_space function'
        );
    }

    /**
     * TEST: health.php includes uploads directory check
     *
     * Given health.php exists
     * When I read its contents
     * Then it should check uploads directory writability
     */
    public function testHealthIncludesUploadsCheck(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            "'uploads'",
            $content,
            'health.php must include uploads directory check'
        );

        $this->assertStringContainsString(
            'is_writable',
            $content,
            'health.php must use is_writable function'
        );
    }

    /**
     * TEST: health.php returns correct HTTP status codes
     *
     * Given health.php exists
     * When I read its contents
     * Then it should return 200 for healthy, 503 for unhealthy
     */
    public function testHealthReturnsCorrectHttpCodes(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            'http_response_code',
            $content,
            'health.php must set HTTP response code'
        );

        // Should contain status codes 200 and 503
        $this->assertStringContainsString('200', $content);
        $this->assertStringContainsString('503', $content);
    }

    /**
     * TEST: health.php supports ready probe
     *
     * Given health.php exists
     * When I check for ready probe support
     * Then it should support ?check=ready parameter
     */
    public function testHealthSupportsReadyProbe(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            "'ready'",
            $content,
            'health.php must support readiness probe'
        );
    }

    /**
     * TEST: health.php supports live probe
     *
     * Given health.php exists
     * When I check for live probe support
     * Then it should support ?check=live parameter
     */
    public function testHealthSupportsLiveProbe(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            "'live'",
            $content,
            'health.php must support liveness probe'
        );
    }

    /**
     * TEST: health.php includes session storage check
     *
     * Given health.php exists
     * When I read its contents
     * Then it should check session storage writability
     */
    public function testHealthIncludesSessionCheck(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            "'sessions'",
            $content,
            'health.php must include session storage check'
        );

        $this->assertStringContainsString(
            'session_save_path',
            $content,
            'health.php must use session_save_path function'
        );
    }

    /**
     * TEST: health.php uses json_encode
     *
     * Given health.php exists
     * When I read its contents
     * Then it should use json_encode to output JSON
     */
    public function testHealthUsesJsonEncode(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            'json_encode',
            $content,
            'health.php must use json_encode function'
        );
    }

    /**
     * TEST: health.php includes error handling
     *
     * Given health.php exists
     * When I read its contents
     * Then it should include try-catch blocks for error handling
     */
    public function testHealthIncludesErrorHandling(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertMatchesRegularExpression(
            '/try\s*{/',
            $content,
            'health.php must include try-catch blocks'
        );

        $this->assertMatchesRegularExpression(
            '/catch\s*\(/',
            $content,
            'health.php must include exception handling'
        );
    }

    /**
     * TEST: health.php checks require database config
     *
     * Given health.php exists
     * When I read its contents
     * Then it should require config files for database access
     */
    public function testHealthRequiresConfig(): void
    {
        $this->assertFileExists($this->healthFilePath);

        $content = file_get_contents($this->healthFilePath);

        $this->assertStringContainsString(
            'require_once',
            $content,
            'health.php must require config files'
        );

        $this->assertMatchesRegularExpression(
            '/config\/(config|database)\.php/',
            $content,
            'health.php must require config/config.php or config/database.php'
        );
    }
}
