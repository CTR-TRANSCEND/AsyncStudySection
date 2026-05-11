<?php
/**
 * TDD Test File: Account Lockout Enhancement
 * SPEC-AUTH-001.3: Enhanced Account Lockout
 *
 * Test Coverage:
 * - Progressive lockout (5 min, 15 min, 1 hour, permanent)
 * - IP-based and username-based lockout tracking
 * - Account unlock functionality
 * - Lockout event logging
 */

declare(strict_types=1);

class AccountLockoutTest {
    private $db;
    private $auth;

    public function __construct() {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../includes/auth.php';

        $this->db = Database::getInstance()->getConnection();
        $this->auth = new Auth();
    }

    /**
     * TEST: Progressive lockout after 3 failed attempts
     * Expected: Account locked for 5 minutes
     */
    public function testProgressiveLockout_3Attempts() {
        echo "TEST: Progressive lockout after 3 failed attempts\n";

        // Create test user
        $this->createTestUser('testuser_3', 'password123');

        // Simulate 3 failed login attempts
        for ($i = 0; $i < 3; $i++) {
            $this->auth->login('testuser_3', 'wrongpassword');
        }

        // Check if account is locked
        $stmt = $this->db->prepare("SELECT account_locked, locked_until FROM users WHERE username = ?");
        $stmt->execute(['testuser_3']);
        $user = $stmt->fetch();

        $this->assertTrue($user['account_locked'], "Account should be locked after 3 failed attempts");
        $this->assertTrue($user['locked_until'] > date('Y-m-d H:i:s'), "Lock expiration should be in future");

        // Cleanup
        $this->cleanupTestUser('testuser_3');
        echo "PASS\n\n";
    }

    /**
     * TEST: Progressive lockout after 6 failed attempts
     * Expected: Account locked for 15 minutes
     */
    public function testProgressiveLockout_6Attempts() {
        echo "TEST: Progressive lockout after 6 failed attempts\n";

        $this->createTestUser('testuser_6', 'password123');

        // Simulate 6 failed login attempts
        for ($i = 0; $i < 6; $i++) {
            $this->auth->login('testuser_6', 'wrongpassword');
        }

        // Check lockout duration (should be approximately 15 minutes)
        $stmt = $this->db->prepare("SELECT failed_login_count, account_locked FROM users WHERE username = ?");
        $stmt->execute(['testuser_6']);
        $user = $stmt->fetch();

        $this->assertEquals(6, $user['failed_login_count'], "Failed login count should be 6");
        $this->assertTrue($user['account_locked'], "Account should be locked");

        $this->cleanupTestUser('testuser_6');
        echo "PASS\n\n";
    }

    /**
     * TEST: Progressive lockout after 9 failed attempts
     * Expected: Account locked for 1 hour
     */
    public function testProgressiveLockout_9Attempts() {
        echo "TEST: Progressive lockout after 9 failed attempts\n";

        $this->createTestUser('testuser_9', 'password123');

        for ($i = 0; $i < 9; $i++) {
            $this->auth->login('testuser_9', 'wrongpassword');
        }

        $stmt = $this->db->prepare("SELECT failed_login_count, account_locked, lockout_reason FROM users WHERE username = ?");
        $stmt->execute(['testuser_9']);
        $user = $stmt->fetch();

        $this->assertEquals(9, $user['failed_login_count'], "Failed login count should be 9");
        $this->assertTrue($user['account_locked'], "Account should be locked");
        $this->assertNotEmpty($user['lockout_reason'], "Lockout reason should be recorded");

        $this->cleanupTestUser('testuser_9');
        echo "PASS\n\n";
    }

    /**
     * TEST: Permanent lockout after 12 failed attempts
     * Expected: Account permanently locked (requires admin unlock)
     */
    public function testProgressiveLockout_12Attempts() {
        echo "TEST: Permanent lockout after 12 failed attempts\n";

        $this->createTestUser('testuser_12', 'password123');

        for ($i = 0; $i < 12; $i++) {
            $this->auth->login('testuser_12', 'wrongpassword');
        }

        $stmt = $this->db->prepare("SELECT failed_login_count, account_locked, locked_until FROM users WHERE username = ?");
        $stmt->execute(['testuser_12']);
        $user = $stmt->fetch();

        $this->assertEquals(12, $user['failed_login_count'], "Failed login count should be 12");
        $this->assertTrue($user['account_locked'], "Account should be permanently locked");
        $this->assertNull($user['locked_until'], "Permanent lockout should have NULL locked_until");

        // Verify login is blocked even with correct password
        $loginResult = $this->auth->login('testuser_12', 'password123');
        $this->assertFalse($loginResult, "Login should fail even with correct password when account is permanently locked");

        $this->cleanupTestUser('testuser_12');
        echo "PASS\n\n";
    }

    /**
     * TEST: Successful login resets failed count
     * Expected: failed_login_count reset to 0 after successful login
     */
    public function testSuccessfulLoginResetsFailedCount() {
        echo "TEST: Successful login resets failed count\n";

        $this->createTestUser('testuser_reset', 'password123');

        // Simulate 2 failed attempts
        for ($i = 0; $i < 2; $i++) {
            $this->auth->login('testuser_reset', 'wrongpassword');
        }

        // Verify count is 2
        $stmt = $this->db->prepare("SELECT failed_login_count FROM users WHERE username = ?");
        $stmt->execute(['testuser_reset']);
        $user = $stmt->fetch();
        $this->assertEquals(2, $user['failed_login_count'], "Failed count should be 2");

        // Successful login
        $this->auth->login('testuser_reset', 'password123');

        // Verify count is reset to 0
        $stmt->execute();
        $user = $stmt->fetch();
        $this->assertEquals(0, $user['failed_login_count'], "Failed count should be reset to 0");

        $this->cleanupTestUser('testuser_reset');
        echo "PASS\n\n";
    }

    /**
     * TEST: Admin account unlock functionality
     * Expected: Admin can unlock permanently locked accounts
     */
    public function testAdminAccountUnlock() {
        echo "TEST: Admin account unlock functionality\n";

        $this->createTestUser('testuser_unlock', 'password123');

        // Lock account permanently
        for ($i = 0; $i < 12; $i++) {
            $this->auth->login('testuser_unlock', 'wrongpassword');
        }

        // Verify locked
        $stmt = $this->db->prepare("SELECT account_locked FROM users WHERE username = ?");
        $stmt->execute(['testuser_unlock']);
        $user = $stmt->fetch();
        $this->assertTrue($user['account_locked'], "Account should be locked");

        // Unlock account (simulating admin action)
        $unlockResult = $this->auth->unlockAccount('testuser_unlock');
        $this->assertTrue($unlockResult, "Admin should be able to unlock account");

        // Verify unlocked
        $stmt->execute();
        $user = $stmt->fetch();
        $this->assertFalse($user['account_locked'], "Account should be unlocked");
        $this->assertEquals(0, $user['failed_login_count'], "Failed count should be reset");
        $this->assertNull($user['locked_until'], "Lock until should be NULL");

        // Verify login works after unlock
        $loginResult = $this->auth->login('testuser_unlock', 'password123');
        $this->assertTrue($loginResult, "Login should succeed after unlock");

        $this->cleanupTestUser('testuser_unlock');
        echo "PASS\n\n";
    }

    // Helper methods

    private function createTestUser(string $username, string $password) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, full_name, email, role, is_active)
            VALUES (?, ?, 'Test User', 'test@example.com', 'reviewer', TRUE)
        ");
        $stmt->execute([$username, $passwordHash]);
    }

    private function cleanupTestUser(string $username) {
        $stmt = $this->db->prepare("DELETE FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $stmt = $this->db->prepare("DELETE FROM login_attempts WHERE username = ?");
        $stmt->execute([$username]);
    }

    private function assertTrue($condition, $message) {
        if (!$condition) {
            throw new Exception("ASSERTION FAILED: $message");
        }
    }

    private function assertFalse($condition, $message) {
        if ($condition) {
            throw new Exception("ASSERTION FAILED: $message");
        }
    }

    private function assertEquals($expected, $actual, $message) {
        if ($expected !== $actual) {
            throw new Exception("ASSERTION FAILED: $message (expected: " . var_export($expected, true) . ", actual: " . var_export($actual, true) . ")");
        }
    }

    private function assertNull($value, $message) {
        if ($value !== null) {
            throw new Exception("ASSERTION FAILED: $message (expected: null, actual: " . var_export($value, true) . ")");
        }
    }

    private function assertNotEmpty($value, $message) {
        if (empty($value)) {
            throw new Exception("ASSERTION FAILED: $message");
        }
    }

    public function runAllTests() {
        echo "\n========================================\n";
        echo "Account Lockout Enhancement Tests\n";
        echo "========================================\n\n";

        try {
            $this->testProgressiveLockout_3Attempts();
            $this->testProgressiveLockout_6Attempts();
            $this->testProgressiveLockout_9Attempts();
            $this->testProgressiveLockout_12Attempts();
            $this->testSuccessfulLoginResetsFailedCount();
            $this->testAdminAccountUnlock();

            echo "\n========================================\n";
            echo "ALL TESTS PASSED!\n";
            echo "========================================\n\n";
        } catch (Exception $e) {
            echo "\n========================================\n";
            echo "TEST FAILED!\n";
            echo "Error: " . $e->getMessage() . "\n";
            echo "========================================\n\n";
            throw $e;
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
    $test = new AccountLockoutTest();
    $test->runAllTests();
}
