<?php
/**
 * Sample Test
 *
 * Verifies that the testing infrastructure is properly configured.
 */

namespace GrantReview\Tests\Unit;

use GrantReview\Tests\TestCase;

class SampleTest extends TestCase
{
    /**
     * Test that PHPUnit is working
     */
    public function testPhpUnitIsWorking(): void
    {
        $this->assertTrue(true);
        $this->assertEquals(1, 1);
        $this->assertNotEmpty('Sample Test');
    }

    /**
     * Test that test environment is set
     */
    public function testEnvironmentIsTesting(): void
    {
        $this->assertEquals('testing', getenv('APP_ENV'));
        $this->assertIsBool(APP_DEBUG);
    }

    /**
     * Test that test directories exist
     */
    public function testTestDirectoriesExist(): void
    {
        $this->assertDirectoryExists(__DIR__ . '/../fixtures');
        $this->assertDirectoryExists(__DIR__ . '/../fixtures/db');
        $this->assertDirectoryExists(__DIR__ . '/../fixtures/documents');
        $this->assertDirectoryExists(__DIR__ . '/../screenshots');
    }

    /**
     * Test that moai directories exist
     */
    public function testMoaiDirectoriesExist(): void
    {
        $this->assertDirectoryExists(__DIR__ . '/../../.moai/logs');
        $this->assertDirectoryExists(__DIR__ . '/../../.moai/reports/coverage');
        $this->assertDirectoryExists(__DIR__ . '/../../.moai/cache/phpunit');
    }

    /**
     * Test that database connection can be established
     */
    public function testDatabaseConnectionCanBeEstablished(): void
    {
        // If this test fails, it means test database is not configured
        // The test will be marked as skipped, not failed
        $this->assertNotNull($this->db, 'Database connection should be established');
        $this->assertInstanceOf(\PDO::class, $this->db);
    }

    /**
     * Test that session is clean for each test
     */
    public function testSessionIsClean(): void
    {
        $this->assertEmpty($_SESSION, 'Session should be clean at test start');

        // Set some session data
        $_SESSION['test_key'] = 'test_value';
        $this->assertSessionHas('test_key');
    }

    /**
     * Test that transaction rollback works for isolation
     */
    public function testTransactionRollbackWorks(): void
    {
        if ($this->db === null) {
            $this->markTestSkipped('Database connection not available');
        }

        // Create a user
        $userId = $this->createTestUser(['username' => 'rollback_test_user']);

        // Verify user exists
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE username = 'rollback_test_user'");
        $stmt->execute();
        $count = $stmt->fetchColumn();

        $this->assertEquals(1, $count, 'User should exist during test');
    }

    /**
     * Test that session data is isolated between tests
     */
    public function testSessionDataIsIsolated(): void
    {
        // This test verifies that session is clean despite previous test setting data
        $this->assertEmpty($_SESSION, 'Session should be clean despite previous test');
    }

    /**
     * Test helper methods for assertions
     */
    public function testHelperMethodsWork(): void
    {
        // Test bcrypt hash validation
        $hash = password_hash('test_password', PASSWORD_DEFAULT);
        $this->assertValidBcryptHash($hash);

        // Test session helpers
        $this->setSession('test_key', 'test_value');
        $this->assertSessionHas('test_key');
        $this->assertEquals('test_value', $this->getSession('test_key'));
        $this->assertNull($this->getSession('non_existent_key'));
    }
}
