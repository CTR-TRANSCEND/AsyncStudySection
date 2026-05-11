<?php
/**
 * Environment Configuration Tests
 *
 * Test suite for validating environment configuration management
 * Tests environment files, validation, and configuration loading
 *
 * @package Tests
 * @subpackage DevOps
 * @author TDD Implementation Agent
 * @version 1.0.0
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * EnvironmentConfigTest
 *
 * Tests for environment configuration and validation
 * Validates that environment variables are properly configured
 */
class EnvironmentConfigTest extends TestCase
{
    /**
     * @var string Path to .env.example
     */
    private string $envExamplePath;

    /**
     * @var string Path to .env.development
     */
    private string $envDevelopmentPath;

    /**
     * @var string Path to .env.staging
     */
    private string $envStagingPath;

    /**
     * @var array Required environment variables
     */
    private array $requiredVars = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_NAME',
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASS'
    ];

    /**
     * Set up test dependencies
     */
    protected function setUp(): void
    {
        $this->envExamplePath = __DIR__ . '/../.env.example';
        $this->envDevelopmentPath = __DIR__ . '/../.env.development';
        $this->envStagingPath = __DIR__ . '/../.env.staging';
    }

    /**
     * TEST: .env.example exists
     *
     * Given the project root directory
     * When I check for .env.example
     * Then the file should exist
     */
    public function testEnvExampleExists(): void
    {
        $this->assertFileExists(
            $this->envExamplePath,
            '.env.example must exist in project root'
        );
    }

    /**
     * TEST: .env.example contains all required variables
     *
     * Given .env.example exists
     * When I read its contents
     * Then it should contain all required environment variables
     */
    public function testEnvExampleContainsRequiredVariables(): void
    {
        $this->assertFileExists($this->envExamplePath);

        $content = file_get_contents($this->envExamplePath);

        foreach ($this->requiredVars as $var) {
            $this->assertMatchesRegularExpression(
                "/^{$var}=/m",
                $content,
                ".env.example must contain {$var} variable"
            );
        }
    }

    /**
     * TEST: .env.example contains placeholder values
     *
     * Given .env.example exists
     * When I check variable values
     * Then sensitive values should be placeholders, not real secrets
     */
    public function testEnvExampleContainsPlaceholders(): void
    {
        $this->assertFileExists($this->envExamplePath);

        $content = file_get_contents($this->envExamplePath);

        // Check for placeholder patterns
        $this->assertMatchesRegularExpression(
            '/change_/i',
            $content,
            '.env.example should contain placeholder values for secrets'
        );

        // Ensure no real secrets are present
        $this->assertDoesNotMatchRegularExpression(
            '/password\s*=\s*[^c]/i',
            $content,
            '.env.example should not contain real passwords'
        );
    }

    /**
     * TEST: .env.development exists
     *
     * Given the project root directory
     * When I check for .env.development
     * Then the file should exist
     */
    public function testEnvDevelopmentExists(): void
    {
        $this->assertFileExists(
            $this->envDevelopmentPath,
            '.env.development must exist in project root'
        );
    }

    /**
     * TEST: .env.development has development APP_ENV
     *
     * Given .env.development exists
     * When I check APP_ENV value
     * Then it should be set to "development"
     */
    public function testEnvDevelopmentHasCorrectEnv(): void
    {
        $this->assertFileExists($this->envDevelopmentPath);

        $content = file_get_contents($this->envDevelopmentPath);

        $this->assertMatchesRegularExpression(
            '/^APP_ENV=development$/m',
            $content,
            '.env.development must have APP_ENV=development'
        );
    }

    /**
     * TEST: .env.development has debug enabled
     *
     * Given .env.development exists
     * When I check APP_DEBUG value
     * Then it should be set to "true"
     */
    public function testEnvDevelopmentHasDebugEnabled(): void
    {
        $this->assertFileExists($this->envDevelopmentPath);

        $content = file_get_contents($this->envDevelopmentPath);

        $this->assertMatchesRegularExpression(
            '/^APP_DEBUG=true$/m',
            $content,
            '.env.development must have APP_DEBUG=true'
        );
    }

    /**
     * TEST: .env.staging exists
     *
     * Given the project root directory
     * When I check for .env.staging
     * Then the file should exist
     */
    public function testEnvStagingExists(): void
    {
        $this->assertFileExists(
            $this->envStagingPath,
            '.env.staging must exist in project root'
        );
    }

    /**
     * TEST: .env.staging has staging APP_ENV
     *
     * Given .env.staging exists
     * When I check APP_ENV value
     * Then it should be set to "staging"
     */
    public function testEnvStagingHasCorrectEnv(): void
    {
        $this->assertFileExists($this->envStagingPath);

        $content = file_get_contents($this->envStagingPath);

        $this->assertMatchesRegularExpression(
            '/^APP_ENV=staging$/m',
            $content,
            '.env.staging must have APP_ENV=staging'
        );
    }

    /**
     * TEST: .env.staging has debug disabled
     *
     * Given .env.staging exists
     * When I check APP_DEBUG value
     * Then it should be set to "false"
     */
    public function testEnvStagingHasDebugDisabled(): void
    {
        $this->assertFileExists($this->envStagingPath);

        $content = file_get_contents($this->envStagingPath);

        $this->assertMatchesRegularExpression(
            '/^APP_DEBUG=false$/m',
            $content,
            '.env.staging must have APP_DEBUG=false'
        );
    }

    /**
     * TEST: Environment files have different database names
     *
     * Given multiple environment files exist
     * When I compare database configurations
     * Then each environment should have different database name
     */
    public function testEnvironmentsHaveDifferentDatabases(): void
    {
        $this->assertFileExists($this->envDevelopmentPath);
        $this->assertFileExists($this->envStagingPath);

        $devContent = file_get_contents($this->envDevelopmentPath);
        $stagingContent = file_get_contents($this->envStagingPath);

        // Extract DB_NAME values
        preg_match('/^DB_NAME=(.*)$/m', $devContent, $devDb);
        preg_match('/^DB_NAME=(.*)$/m', $stagingContent, $stagingDb);

        $this->assertNotEmpty($devDb, 'Development DB_NAME should be set');
        $this->assertNotEmpty($stagingDb, 'Staging DB_NAME should be set');

        $this->assertNotEquals(
            $devDb[1],
            $stagingDb[1],
            'Development and staging should use different databases'
        );
    }

    /**
     * TEST: Environment validator script exists
     *
     * Given the scripts directory
     * When I check for environment validator
     * Then env-validator.sh script should exist
     */
    public function testEnvValidatorScriptExists(): void
    {
        $validatorPath = __DIR__ . '/../deploy/env-validator.sh';

        $this->assertFileExists(
            $validatorPath,
            'deploy/env-validator.sh must exist'
        );
    }

    /**
     * TEST: Environment validator is executable
     *
     * Given env-validator.sh exists
     * When I check file permissions
     * Then the script should be executable
     */
    public function testEnvValidatorIsExecutable(): void
    {
        $validatorPath = __DIR__ . '/../deploy/env-validator.sh';

        if (file_exists($validatorPath)) {
            $this->assertTrue(
                is_executable($validatorPath),
                'env-validator.sh should be executable'
            );
        }
    }

    /**
     * TEST: .dockerignore exists
     *
     * Given the project root directory
     * When I check for .dockerignore
     * Then the file should exist
     */
    public function testDockerignoreExists(): void
    {
        $dockerignorePath = __DIR__ . '/../.dockerignore';

        $this->assertFileExists(
            $dockerignorePath,
            '.dockerignore must exist in project root'
        );
    }

    /**
     * TEST: .dockerignore excludes unnecessary files
     *
     * Given .dockerignore exists
     * When I read its contents
     * Then it should exclude common unnecessary files
     */
    public function testDockerignoreExcludesUnnecessaryFiles(): void
    {
        $dockerignorePath = __DIR__ . '/../.dockerignore';

        if (file_exists($dockerignorePath)) {
            $content = file_get_contents($dockerignorePath);

            $patternsToExclude = [
                '.git',
                '.gitignore',
                'node_modules',
                '.env.*',
                'tests/',
                '*.md'
            ];

            foreach ($patternsToExclude as $pattern) {
                $this->assertStringContainsString(
                    $pattern,
                    $content,
                    ".dockerignore should exclude {$pattern}"
                );
            }
        }
    }
}
