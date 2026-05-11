<?php
declare(strict_types=1);

/**
 * Integration Tests for User Management Workflow
 * Description: Tests multi-class interactions between users, the Auth class,
 *              password hashing, and role-based access control.
 */

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

use GrantReview\Tests\TestCase;

class UserManagementIntegrationTest extends TestCase
{
    /**
     * Connection used by the Auth class and hasApplicationAccess.
     * The singleton connection sees committed data only; the TestCase $this->db
     * connection isolates test rows in a transaction.  For tests that exercise
     * classes that internally call Database::getInstance(), we use this shared
     * connection and clean up manually.
     *
     * @var \PDO
     */
    private \PDO $sharedDb;

    /**
     * IDs of rows inserted directly via $sharedDb so they can be cleaned up.
     *
     * @var array<string, list<int>>
     */
    private array $sharedDbCleanup = ['users' => [], 'assignments' => [], 'applications' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sharedDb = Database::getInstance()->getConnection();
    }

    protected function tearDown(): void
    {
        // Clean up rows inserted via sharedDb in reverse dependency order
        foreach ($this->sharedDbCleanup['assignments'] as $id) {
            $this->sharedDb->prepare("DELETE FROM assignments WHERE id = ?")->execute([$id]);
        }
        foreach ($this->sharedDbCleanup['applications'] as $id) {
            $this->sharedDb->prepare("DELETE FROM applications WHERE id = ?")->execute([$id]);
        }
        foreach ($this->sharedDbCleanup['users'] as $id) {
            $this->sharedDb->prepare("DELETE FROM login_attempts WHERE username IN (SELECT username FROM users WHERE id = ?)")->execute([$id]);
            $this->sharedDb->prepare("DELETE FROM login_history WHERE user_id = ?")->execute([$id]);
            $this->sharedDb->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        }
        $this->sharedDbCleanup = ['users' => [], 'assignments' => [], 'applications' => []];

        parent::tearDown();
    }

    // ------------------------------------------------------------------ //
    // Helper: insert a user into the shared (singleton) connection so that
    // Auth and other singleton-dependent classes can see it.
    // ------------------------------------------------------------------ //

    private function insertSharedUser(
        string $username,
        string $plainPassword,
        string $role = 'reviewer',
        bool $isActive = true
    ): int {
        $uniqueId = uniqid();
        $stmt = $this->sharedDb->prepare(
            "INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $username,
            $username . '_' . $uniqueId . '@test.com',
            password_hash($plainPassword, PASSWORD_DEFAULT),
            'Test User',
            $role,
            $isActive ? 1 : 0,
        ]);
        $id = (int) $this->sharedDb->lastInsertId();
        $this->sharedDbCleanup['users'][] = $id;
        return $id;
    }

    private function insertSharedApplication(): int
    {
        $uniqueId = uniqid();
        $stmt = $this->sharedDb->prepare(
            "INSERT INTO applications (applicant_name, grant_id, application_title, grant_type, status, is_complete, created_at, updated_at)
             VALUES (?, ?, ?, 'TRANSCEND Pilot', 'pending', 0, NOW(), NOW())"
        );
        $stmt->execute(['Test Applicant', 'GRANT-' . $uniqueId, 'Test Application ' . $uniqueId]);
        $id = (int) $this->sharedDb->lastInsertId();
        $this->sharedDbCleanup['applications'][] = $id;
        return $id;
    }

    private function insertSharedAssignment(int $applicationId, int $reviewerId, string $label): int
    {
        $stmt = $this->sharedDb->prepare(
            "INSERT INTO assignments (application_id, reviewer_id, anonymous_label) VALUES (?, ?, ?)"
        );
        $stmt->execute([$applicationId, $reviewerId, $label]);
        $id = (int) $this->sharedDb->lastInsertId();
        $this->sharedDbCleanup['assignments'][] = $id;
        return $id;
    }

    // ------------------------------------------------------------------ //
    // Tests
    // ------------------------------------------------------------------ //

    /**
     * Test: Password stored as bcrypt hash and password_verify confirms it
     *
     * Uses TestCase::createTestUser (transaction-isolated) because this test
     * does not call any singleton-dependent class.
     */
    public function testPasswordHashIsStoredAndVerifiable(): void
    {
        $plainPassword = 'SecurePass@2025!';
        $userId = $this->createTestUser([
            'password' => $plainPassword,
            'role'     => 'reviewer',
        ]);

        $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        $this->assertNotFalse($hash, 'User password_hash row should exist');
        $this->assertValidBcryptHash((string) $hash);
        $this->assertTrue(
            password_verify($plainPassword, (string) $hash),
            'password_verify should confirm the plain password matches the stored hash'
        );
    }

    /**
     * Test: Auth::login authenticates a user with correct credentials and sets session
     *
     * Inserts the user via the shared singleton connection (committed) so that
     * Auth::login can find it.  Manual cleanup is performed in tearDown.
     */
    public function testAuthLoginSetsSessionOnValidCredentials(): void
    {
        $plainPassword = 'LoginTest@9876!';
        $username      = 'int_user_login_' . uniqid();

        $userId = $this->insertSharedUser($username, $plainPassword, 'reviewer');

        // Clear session before login
        $_SESSION = [];

        $auth   = new Auth();
        $result = $auth->login($username, $plainPassword);

        $this->assertTrue(
            $result,
            'Auth::login should return true for valid credentials. Error: ' . ($auth->getLastError() ?? 'none')
        );
        $this->assertTrue(Auth::isLoggedIn(), 'Session should indicate logged-in state after successful login');
        $this->assertEquals($userId, Auth::getUserId(), 'Session user_id should match created user');
        $this->assertEquals('reviewer', Auth::getRole(), 'Session role should be reviewer');
        $this->assertEquals($username, Auth::getUsername(), 'Session username should match');
    }

    /**
     * Test: Auth::login rejects invalid password and does not set session
     *
     * Verifies that a wrong password causes login to return false.
     */
    public function testAuthLoginRejectsInvalidPassword(): void
    {
        $username = 'int_user_bad_' . uniqid();
        $this->insertSharedUser($username, 'CorrectPass@2025!', 'reviewer');

        // Clear session
        $_SESSION = [];

        $auth   = new Auth();
        $result = $auth->login($username, 'WrongPassword!');

        $this->assertFalse($result, 'Auth::login should return false for wrong password');
        $this->assertFalse(Auth::isLoggedIn(), 'Session should NOT indicate logged-in after failed login');
    }

    /**
     * Test: hasApplicationAccess returns true for assigned reviewer and false for unassigned
     *
     * Uses the shared connection so that hasApplicationAccess (which calls
     * Database::getInstance()) can see the inserted rows.
     */
    public function testHasApplicationAccessReflectsAssignments(): void
    {
        $applicationId      = $this->insertSharedApplication();
        $assignedReviewerId = $this->insertSharedUser('int_assigned_' . uniqid(), 'Pass@2025!', 'reviewer');
        $otherReviewerId    = $this->insertSharedUser('int_other_' . uniqid(), 'Pass@2025!', 'reviewer');

        $this->insertSharedAssignment($applicationId, $assignedReviewerId, 'Reviewer A');

        // Simulate non-admin reviewer session
        $_SESSION['logged_in'] = true;
        $_SESSION['role']      = 'reviewer';
        $_SESSION['user_id']   = $assignedReviewerId;

        $this->assertTrue(
            hasApplicationAccess($applicationId, $assignedReviewerId),
            'Assigned reviewer should have access to the application'
        );
        $this->assertFalse(
            hasApplicationAccess($applicationId, $otherReviewerId),
            'Unassigned reviewer should not have access to the application'
        );
    }

    /**
     * Test: Admin user created via factory has the correct role in the database
     *
     * Uses TestCase::createTestUser (transaction-isolated) as no singleton
     * classes are exercised here.
     */
    public function testCreateAdminUserStoresCorrectRole(): void
    {
        $adminId = $this->createTestUser([
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $stmt = $this->db->prepare("SELECT role, is_active FROM users WHERE id = ?");
        $stmt->execute([$adminId]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'Admin user row should exist');
        $this->assertEquals('admin', $row['role'], 'Stored role should be admin');
        $this->assertEquals(1, (int) $row['is_active'], 'Admin should be active');
    }
}
