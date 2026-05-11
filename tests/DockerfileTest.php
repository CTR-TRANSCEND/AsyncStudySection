<?php
/**
 * Docker Containerization Tests
 *
 * Test suite for validating Docker containerization setup
 * Tests Dockerfile, docker-compose, and container configuration
 *
 * @package Tests
 * @subpackage DevOps
 * @author TDD Implementation Agent
 * @version 1.0.0
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * DockerfileTest
 *
 * Tests for Dockerfile configuration and container setup
 * Validates that Docker container meets production requirements
 */
class DockerfileTest extends TestCase
{
    /**
     * @var string Path to Dockerfile
     */
    private string $dockerfilePath;

    /**
     * @var string Path to docker-compose.yml
     */
    private string $dockerComposePath;

    /**
     * Set up test dependencies
     */
    protected function setUp(): void
    {
        $this->dockerfilePath = __DIR__ . '/../Dockerfile';
        $this->dockerComposePath = __DIR__ . '/../docker-compose.yml';
    }

    /**
     * TEST: Dockerfile exists in project root
     *
     * Given the project root directory
     * When I check for Dockerfile
     * Then the file should exist
     */
    public function testDockerfileExists(): void
    {
        $this->assertFileExists(
            $this->dockerfilePath,
            'Dockerfile must exist in project root'
        );
    }

    /**
     * TEST: Dockerfile uses correct base image
     *
     * Given the Dockerfile exists
     * When I read its contents
     * Then it should use php:8.2-apache or php:8.3-apache as base image
     */
    public function testDockerfileUsesCorrectBaseImage(): void
    {
        $this->assertFileExists($this->dockerfilePath);

        $content = file_get_contents($this->dockerfilePath);
        $this->assertNotEmpty($content, 'Dockerfile should not be empty');

        $this->assertMatchesRegularExpression(
            '/FROM\s+php:(8\.2|8\.3)-apache/',
            $content,
            'Dockerfile must use php:8.2-apache or php:8.3-apache base image'
        );
    }

    /**
     * TEST: Dockerfile installs required PHP extensions
     *
     * Given the Dockerfile exists
     * When I check the installed extensions
     * Then it should include pdo_mysql, zip, dom, mbstring, gd
     */
    public function testDockerfileInstallsRequiredExtensions(): void
    {
        $this->assertFileExists($this->dockerfilePath);

        $content = file_get_contents($this->dockerfilePath);

        $requiredExtensions = ['pdo_mysql', 'zip', 'xml', 'mbstring', 'gd'];

        foreach ($requiredExtensions as $extension) {
            $this->assertStringContainsString(
                $extension,
                $content,
                "Dockerfile must install PHP extension: {$extension}"
            );
        }
    }

    /**
     * TEST: Dockerfile enables Apache mod_rewrite
     *
     * Given the Dockerfile exists
     * When I check Apache configuration
     * Then mod_rewrite should be enabled
     */
    public function testDockerfileEnablesModRewrite(): void
    {
        $this->assertFileExists($this->dockerfilePath);

        $content = file_get_contents($this->dockerfilePath);

        $this->assertMatchesRegularExpression(
            '/a2enmod\s+rewrite/',
            $content,
            'Dockerfile must enable Apache mod_rewrite module'
        );
    }

    /**
     * TEST: Dockerfile sets correct working directory
     *
     * Given the Dockerfile exists
     * When I check the working directory
     * Then it should be set to /var/www/html
     */
    public function testDockerfileSetsWorkingDirectory(): void
    {
        $this->assertFileExists($this->dockerfilePath);

        $content = file_get_contents($this->dockerfilePath);

        $this->assertStringContainsString(
            'WORKDIR /var/www/html',
            $content,
            'Dockerfile must set working directory to /var/www/html'
        );
    }

    /**
     * TEST: Dockerfile creates uploads directory with permissions
     *
     * Given the Dockerfile exists
     * When I check directory creation commands
     * Then uploads directory should be created with correct permissions
     */
    public function testDockerfileCreatesUploadsDirectory(): void
    {
        $this->assertFileExists($this->dockerfilePath);

        $content = file_get_contents($this->dockerfilePath);

        $this->assertStringContainsString(
            'mkdir -p /var/www/html/uploads',
            $content,
            'Dockerfile must create uploads directory'
        );

        $this->assertMatchesRegularExpression(
            '/chown.*www-data.*uploads/',
            $content,
            'Dockerfile must set ownership of uploads directory to www-data'
        );
    }

    /**
     * TEST: Dockerfile includes health check
     *
     * Given the Dockerfile exists
     * When I check for health check configuration
     * Then HEALTHCHECK instruction should be present
     */
    public function testDockerfileIncludesHealthCheck(): void
    {
        $this->assertFileExists($this->dockerfilePath);

        $content = file_get_contents($this->dockerfilePath);

        $this->assertMatchesRegularExpression(
            '/HEALTHCHECK/',
            $content,
            'Dockerfile must include HEALTHCHECK instruction'
        );

        $this->assertStringContainsString(
            '/health',
            $content,
            'Health check should test /health endpoint'
        );
    }

    /**
     * TEST: Dockerfile exposes port 80
     *
     * Given the Dockerfile exists
     * When I check exposed ports
     * Then port 80 should be exposed
     */
    public function testDockerfileExposesPort80(): void
    {
        $this->assertFileExists($this->dockerfilePath);

        $content = file_get_contents($this->dockerfilePath);

        $this->assertStringContainsString(
            'EXPOSE 80',
            $content,
            'Dockerfile must expose port 80'
        );
    }

    /**
     * TEST: docker-compose.yml exists
     *
     * Given the project root directory
     * When I check for docker-compose.yml
     * Then the file should exist
     */
    public function testDockerComposeExists(): void
    {
        $this->assertFileExists(
            $this->dockerComposePath,
            'docker-compose.yml must exist in project root'
        );
    }

    /**
     * TEST: docker-compose.yml defines php-apache service
     *
     * Given docker-compose.yml exists
     * When I read its contents
     * Then it should define php-apache service
     */
    public function testDockerComposeDefinesPhpApacheService(): void
    {
        $this->assertFileExists($this->dockerComposePath);

        $content = file_get_contents($this->dockerComposePath);

        $this->assertStringContainsString(
            'php-apache:',
            $content,
            'docker-compose.yml must define php-apache service'
        );
    }

    /**
     * TEST: docker-compose.yml defines database service (mariadb)
     *
     * Given docker-compose.yml exists
     * When I read its contents
     * Then it should define mariadb service
     */
    public function testDockerComposeDefinesMySqlService(): void
    {
        $this->assertFileExists($this->dockerComposePath);

        $content = file_get_contents($this->dockerComposePath);

        $this->assertStringContainsString(
            'mariadb:',
            $content,
            'docker-compose.yml must define mariadb service'
        );
    }

    /**
     * TEST: docker-compose.yml configures health checks
     *
     * Given docker-compose.yml exists
     * When I check service configuration
     * Then health checks should be configured for services
     */
    public function testDockerComposeConfiguresHealthChecks(): void
    {
        $this->assertFileExists($this->dockerComposePath);

        $content = file_get_contents($this->dockerComposePath);

        $this->assertMatchesRegularExpression(
            '/healthcheck:/i',
            $content,
            'docker-compose.yml should configure health checks'
        );
    }

    /**
     * TEST: docker-compose.yml configures networking
     *
     * Given docker-compose.yml exists
     * When I check network configuration
     * Then services should be on the same network
     */
    public function testDockerComposeConfiguresNetworking(): void
    {
        $this->assertFileExists($this->dockerComposePath);

        $content = file_get_contents($this->dockerComposePath);

        $this->assertMatchesRegularExpression(
            '/networks:/i',
            $content,
            'docker-compose.yml should configure networks'
        );

        $this->assertMatchesRegularExpression(
            '/grant-review-network/',
            $content,
            'docker-compose.yml should define grant-review-network'
        );
    }

    /**
     * TEST: docker-compose.yml configures volumes
     *
     * Given docker-compose.yml exists
     * When I check volume configuration
     * Then volumes should be configured for data persistence
     */
    public function testDockerComposeConfiguresVolumes(): void
    {
        $this->assertFileExists($this->dockerComposePath);

        $content = file_get_contents($this->dockerComposePath);

        $this->assertMatchesRegularExpression(
            '/volumes:/i',
            $content,
            'docker-compose.yml should configure volumes'
        );

        $this->assertMatchesRegularExpression(
            '/mariadb-data:/',
            $content,
            'docker-compose.yml should define mariadb-data volume'
        );
    }
}
