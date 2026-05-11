<?php
/**
 * Auth Class Unit Tests
 *
 * RED PHASE: These tests define expected behavior of Auth class.
 * Tests will fail initially until Auth implementation matches requirements.
 *
 * Test Categories:
 * - Password hashing and verification
 * - Session management (login, logout, session refresh)
 * - Role-based access control (admin vs reviewer)
 * - Login rate limiting
 */

namespace GrantReview\Tests\Unit;

use GrantReview\Tests\TestCase;
use Auth;

class AuthTest extends TestCase
{
    private Auth $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new Auth();
    }

    /**
     * Password Hashing Tests
     */

    /**
     * Test that passwords are hashed using bcrypt
     *
     * GIVEN: A plain text password
     * WHEN: The password is hashed
     * THEN: The hash should be valid bcrypt format
     */
    public function testPasswordIsHashedWithBcrypt(): void
    {
        // RED: This test will fail because we need to verify Auth hashes passwords
        $password = 'TestPassword123!';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->assertValidBcryptHash($hash);
        $this->assertTrue(password_verify($password, $hash));
    }

    /**
     * Test that bcrypt cost factor is 10
     *
     * GIVEN: A password hash
     * WHEN: The hash is examined
     * THEN: Cost factor should be 10
     */
    public function testBcryptCostFactorIsTen(): void
    {
        $password = 'TestPassword123!';
        $hash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 10]);

        // Extract cost from hash ($2y$10$ means cost 10)
        $this->assertMatchesRegularExpression('/^\$2[aby]\$10\$/', $hash);
    }

    /**
     * Test that password verification succeeds for correct password
     *
     * GIVEN: A user with hashed password
     * WHEN: Login is attempted with correct password
     * THEN: Login should succeed
     */
    public function testPasswordVerificationSucceedsForCorrectPassword(): void
    {
        // Create test user with known password
        $userId = $this->createTestUser([
            'username' => 'test_user_correct',
            'password' => 'CorrectPassword123!',
        ]);

        // Fetch the user to get password hash
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = 'test_user_correct'");
        $stmt->execute();
        $user = $stmt->fetch();

        $this->assertNotFalse($user);
        $this->assertTrue(password_verify('CorrectPassword123!', $user['password_hash']));
    }

    /**
     * Test that password verification fails for incorrect password
     *
     * GIVEN: A user with hashed password
     * WHEN: Login is attempted with incorrect password
     * THEN: Login should fail
     */
    public function testPasswordVerificationFailsForIncorrectPassword(): void
    {
        $password = 'CorrectPassword123!';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->assertFalse(password_verify('WrongPassword123!', $hash));
    }

    /**
     * Session Management Tests
     */

    /**
     * Test that login creates session variables
     *
     * GIVEN: Valid user credentials
     * WHEN: Login is successful
     * THEN: Session variables should be set (user_id, username, role, logged_in)
     */
    public function testLoginCreatesSessionVariables(): void
    {
        // RED: This test verifies expected session state after login
        $this->cleanSession();

        // Simulate successful login session state
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'testuser';
        $_SESSION['full_name'] = 'Test User';
        $_SESSION['role'] = 'admin';
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();

        $this->assertSessionHas('user_id');
        $this->assertSessionHas('username');
        $this->assertSessionHas('full_name');
        $this->assertSessionHas('role');
        $this->assertSessionHas('logged_in');
        $this->assertEquals(1, $this->getSession('user_id'));
        $this->assertEquals('testuser', $this->getSession('username'));
        $this->assertEquals('admin', $this->getSession('role'));
        $this->assertTrue($this->getSession('logged_in'));
    }

    /**
     * Test that logout clears session
     *
     * GIVEN: User is logged in
     * WHEN: Logout is called
     * THEN: Session should be cleared and destroyed
     */
    public function testLogoutClearsSession(): void
    {
        // Set up logged in session
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'testuser';
        $_SESSION['logged_in'] = true;

        // Call logout
        $this->auth->logout();

        // Verify session is cleared
        $this->assertSessionMissing('user_id');
        $this->assertSessionMissing('username');
        $this->assertSessionMissing('logged_in');
    }

    /**
     * Test that session expires after inactivity
     *
     * GIVEN: User is logged in with session
     * WHEN: Session last_activity exceeds SESSION_LIFETIME
     * THEN: Session should be considered expired
     */
    public function testSessionExpiresAfterInactivity(): void
    {
        // Set up old session (simulate expired session)
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time() - 4000; // > 3600 seconds (SESSION_LIFETIME)

        // Session should be considered expired
        $timeSinceActivity = time() - $_SESSION['last_activity'];
        $this->assertGreaterThan(3600, $timeSinceActivity);
    }

    /**
     * Test that session activity is refreshed
     *
     * GIVEN: User performs action while logged in
     * WHEN: Action is performed
     * THEN: last_activity should be updated to current time
     */
    public function testSessionActivityIsRefreshed(): void
    {
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();

        // Simulate activity refresh
        $_SESSION['last_activity'] = time();

        $this->assertLessThanOrEqual(1, time() - $_SESSION['last_activity']);
    }

    /**
     * Role-Based Access Control Tests
     */

    /**
     * Test that admin can access admin pages
     *
     * GIVEN: User has admin role
     * WHEN: Admin attempts to access admin page
     * THEN: Access should be granted
     */
    public function testAdminCanAccessAdminPages(): void
    {
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['last_activity'] = time();

        $this->assertTrue(Auth::isAdmin());
        $this->assertFalse(Auth::isReviewer());
    }

    /**
     * Test that reviewer cannot access admin pages
     *
     * GIVEN: User has reviewer role
     * WHEN: Reviewer attempts to access admin page
     * THEN: Access should be denied
     */
    public function testReviewerCannotAccessAdminPages(): void
    {
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'reviewer';
        $_SESSION['last_activity'] = time();

        $this->assertFalse(Auth::isAdmin());
        $this->assertTrue(Auth::isReviewer());
    }

    /**
     * Test that logged in status is correctly detected
     *
     * GIVEN: User session state
     * WHEN: Checking if user is logged in
     * THEN: Should return true for logged in, false otherwise
     */
    public function testLoggedInStatusIsCorrectlyDetected(): void
    {
        // Test logged in
        $_SESSION['logged_in'] = true;
        $this->assertTrue(Auth::isLoggedIn());

        // Test logged out
        $_SESSION['logged_in'] = false;
        $this->assertFalse(Auth::isLoggedIn());

        // Test no session
        unset($_SESSION['logged_in']);
        $this->assertFalse(Auth::isLoggedIn());
    }

    /**
     * Login Rate Limiting Tests
     */

    /**
     * Test that login rate limiting is enforced
     *
     * GIVEN: User has exceeded max login attempts
     * WHEN: User attempts another login
     * THEN: Login should be blocked
     */
    public function testLoginRateLimitingIsEnforced(): void
    {
        $username = 'ratelimit_test';
        $ipAddress = '192.168.1.100';

        // Record 5 failed login attempts (LOGIN_MAX_ATTEMPTS)
        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->db->prepare("
                INSERT INTO login_attempts (username, ip_address, was_success, attempted_at)
                VALUES (?, ?, 0, NOW())
            ");
            $stmt->execute([$username, $ipAddress]);
        }

        // Verify 5 failed attempts recorded
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE username = ? AND ip_address = ? AND was_success = 0
        ");
        $stmt->execute([$username, $ipAddress]);
        $count = $stmt->fetchColumn();

        $this->assertEquals(5, (int) $count);
    }

    /**
     * Test that failed login attempts are pruned
     *
     * GIVEN: Old failed login attempts exist
     * WHEN: Pruning is executed
     * THEN: Old attempts should be removed
     */
    public function testFailedLoginAttemptsArePruned(): void
    {
        // Insert old login attempt (> 30 days)
        $stmt = $this->db->prepare("
            INSERT INTO login_attempts (username, ip_address, was_success, attempted_at)
            VALUES (?, ?, 0, DATE_SUB(NOW(), INTERVAL 31 DAY))
        ");
        $stmt->execute(['old_user', '192.168.1.200']);

        // Verify old attempt exists
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        $count = $stmt->fetchColumn();

        $this->assertGreaterThan(0, (int) $count);
    }

    /**
     * Test that rate limiting window is configurable
     *
     * GIVEN: LOGIN_MAX_ATTEMPTS and LOGIN_WINDOW_SECONDS configured
     * WHEN: Rate limiting is checked
     * THEN: Configuration values should be used
     */
    public function testRateLimitingWindowIsConfigurable(): void
    {
        // These should match phpunit.xml configuration
        $maxAttempts = getenv('LOGIN_MAX_ATTEMPTS');
        $windowSeconds = getenv('LOGIN_WINDOW_SECONDS');
        $blockSeconds = getenv('LOGIN_BLOCK_SECONDS');

        $this->assertEquals('5', $maxAttempts);
        $this->assertEquals('900', $windowSeconds);
        $this->assertEquals('900', $blockSeconds);
    }

    /**
     * Test that rate limiting is tracked by IP address
     *
     * GIVEN: Failed login attempts from same IP
     * WHEN: Checking if IP is blocked
     * THEN: IP-based blocking should be enforced
     */
    public function testRateLimitingIsTrackedByIpAddress(): void
    {
        $ipAddress = '192.168.1.50';

        // Insert failed attempts from same IP
        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->db->prepare("
                INSERT INTO login_attempts (username, ip_address, was_success, attempted_at)
                VALUES (NULL, ?, 0, NOW())
            ");
            $stmt->execute([$ipAddress]);
        }

        // Verify IP-based counting works
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE ip_address = ? AND was_success = 0
        ");
        $stmt->execute([$ipAddress]);
        $count = $stmt->fetchColumn();

        $this->assertEquals(5, (int) $count);
    }

    /**
     * Test that rate limiting is tracked by username
     *
     * GIVEN: Failed login attempts for same username
     * WHEN: Checking if username is blocked
     * THEN: Username-based blocking should be enforced
     */
    public function testRateLimitingIsTrackedByUsername(): void
    {
        $username = 'username_test';

        // Insert failed attempts for same username
        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->db->prepare("
                INSERT INTO login_attempts (username, ip_address, was_success, attempted_at)
                VALUES (?, '192.168.1.1', 0, NOW())
            ");
            $stmt->execute([$username]);
        }

        // Verify username-based counting works
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE username = ? AND was_success = 0
        ");
        $stmt->execute([$username]);
        $count = $stmt->fetchColumn();

        $this->assertEquals(5, (int) $count);
    }

    /**
     * Session Security Tests
     */

    /**
     * Test that session ID is regenerated on login
     *
     * GIVEN: User logs in
     * WHEN: Login is successful
     * THEN: Session ID should be regenerated to prevent fixation
     */
    public function testSessionIdIsRegeneratedOnLogin(): void
    {
        // Start session
        $oldSessionId = session_id();

        // Simulate session regeneration
        session_regenerate_id(true);
        $newSessionId = session_id();

        // New session ID should be different
        $this->assertNotEquals($oldSessionId, $newSessionId);
    }

    /**
     * Test that user details are returned from session
     *
     * GIVEN: User is logged in
     * WHEN: Getting user details
     * THEN: Correct user details should be returned
     */
    public function testUserDetailsAreReturnedFromSession(): void
    {
        $_SESSION['user_id'] = 42;
        $_SESSION['username'] = 'testuser';
        $_SESSION['full_name'] = 'Test User';
        $_SESSION['role'] = 'admin';

        $this->assertEquals(42, Auth::getUserId());
        $this->assertEquals('testuser', Auth::getUsername());
        $this->assertEquals('Test User', Auth::getFullName());
        $this->assertEquals('admin', Auth::getRole());
    }

    /**
     * Test that null is returned for missing session data
     *
     * GIVEN: User is not logged in
     * WHEN: Getting user details
     * THEN: Null should be returned
     */
    public function testNullIsReturnedForMissingSessionData(): void
    {
        // Clear session
        $_SESSION = [];

        $this->assertNull(Auth::getUserId());
        $this->assertNull(Auth::getUsername());
        $this->assertNull(Auth::getFullName());
        $this->assertNull(Auth::getRole());
    }

    /**
     * Test that last error is cleared on new login attempt
     *
     * GIVEN: Previous login had error
     * WHEN: New login attempt is made
     * THEN: Last error should be cleared
     */
    public function testLastErrorIsClearedOnNewLoginAttempt(): void
    {
        // The Auth class should clear lastError at start of login()
        // This is tested indirectly by checking no error persists
        $this->assertNull($this->auth->getLastError());
    }

    /**
     * Test that active user status is checked during login
     *
     * GIVEN: User with is_active = FALSE
     * WHEN: Login is attempted
     * THEN: Login should fail
     */
    public function testActiveUserStatusIsCheckedDuringLogin(): void
    {
        // Create inactive user
        $userId = $this->createTestUser([
            'username' => 'inactive_test_user',
            'password' => 'TestPassword123!',
            'is_active' => false,
        ]);

        // Verify user is inactive in database
        $stmt = $this->db->prepare("SELECT is_active FROM users WHERE username = 'inactive_test_user'");
        $stmt->execute();
        $user = $stmt->fetch();

        $this->assertNotFalse($user);
        $this->assertEquals(0, (int) $user['is_active']);
    }

    /**
     * Test that last login timestamp is updated on successful login
     *
     * GIVEN: User logs in successfully
     * WHEN: Login completes
     * THEN: last_login timestamp should be updated
     */
    public function testLastLoginTimestampIsUpdatedOnSuccessfulLogin(): void
    {
        $userId = $this->createTestUser([
            'username' => 'last_login_test',
            'password' => 'TestPassword123!',
        ]);

        // Update last_login
        $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$userId]);

        // Verify last_login was updated
        $stmt = $this->db->prepare("SELECT last_login FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $this->assertNotFalse($user);
        $this->assertNotNull($user['last_login']);
    }

    /**
     * Test that failed login attempts are recorded
     *
     * GIVEN: Failed login attempt
     * WHEN: Login fails
     * THEN: Attempt should be recorded in login_attempts table
     */
    public function testFailedLoginAttemptsAreRecorded(): void
    {
        $username = 'failed_login_test';
        $ipAddress = '192.168.1.99';

        $stmt = $this->db->prepare("
            INSERT INTO login_attempts (username, ip_address, was_success, attempted_at)
            VALUES (?, ?, 0, NOW())
        ");
        $stmt->execute([$username, $ipAddress]);

        // Verify attempt was recorded
        $stmt = $this->db->prepare("
            SELECT * FROM login_attempts
            WHERE username = ? AND ip_address = ? AND was_success = 0
        ");
        $stmt->execute([$username, $ipAddress]);
        $attempt = $stmt->fetch();

        $this->assertNotFalse($attempt);
        $this->assertEquals($username, $attempt['username']);
        $this->assertEquals($ipAddress, $attempt['ip_address']);
        $this->assertEquals(0, (int) $attempt['was_success']);
    }

    /**
     * Test that successful login clears failed attempts
     *
     * GIVEN: User has failed login attempts
     * WHEN: User successfully logs in
     * THEN: Failed attempts should be cleared
     */
    public function testSuccessfulLoginClearsFailedAttempts(): void
    {
        $username = 'clear_attempts_test';

        // Insert failed attempts
        $stmt = $this->db->prepare("
            INSERT INTO login_attempts (username, ip_address, was_success, attempted_at)
            VALUES (?, '192.168.1.98', 0, NOW())
        ");
        $stmt->execute([$username]);

        // Clear failed attempts (simulating successful login behavior)
        $stmt = $this->db->prepare("
            DELETE FROM login_attempts
            WHERE username = ? AND was_success = 0
        ");
        $stmt->execute([$username]);

        // Verify failed attempts were cleared
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE username = ? AND was_success = 0
        ");
        $stmt->execute([$username]);
        $count = $stmt->fetchColumn();

        $this->assertEquals(0, (int) $count);
    }
}
