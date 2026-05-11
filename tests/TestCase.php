<?php
/**
 * Base Test Case Class
 *
 * Provides common functionality for all test cases including
 * database fixture loading, test data helpers, and assertion utilities.
 */

namespace GrantReview\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use PDO;
use PDOException;

abstract class TestCase extends PHPUnitTestCase
{
    /**
     * @var PDO|null Test database connection
     */
    protected ?PDO $db = null;

    /**
     * @var array Database configuration
     */
    protected array $dbConfig = [];

    /**
     * Set up test before each test method
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Load test database configuration
        $this->dbConfig = [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'dbname' => getenv('DB_NAME') ?: 'grant_review_test',
            'user' => getenv('DB_USER') ?: 'test_user',
            'pass' => getenv('DB_PASS') ?: 'test_password',
            'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
        ];

        // Establish database connection
        $this->connectTestDatabase();

        // Start transaction for test isolation
        $this->beginTransaction();
    }

    /**
     * Tear down test after each test method
     */
    protected function tearDown(): void
    {
        // Rollback transaction to ensure test isolation
        $this->rollbackTransaction();

        // Close database connection
        $this->disconnectTestDatabase();

        // Clean up session
        $this->cleanSession();

        parent::tearDown();
    }

    /**
     * Connect to test database
     */
    protected function connectTestDatabase(): void
    {
        if ($this->db !== null) {
            return;
        }

        try {
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=%s",
                $this->dbConfig['host'],
                $this->dbConfig['dbname'],
                $this->dbConfig['charset']
            );

            $this->db = new PDO(
                $dsn,
                $this->dbConfig['user'],
                $this->dbConfig['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped(
                'Cannot connect to test database: ' . $e->getMessage()
            );
        }
    }

    /**
     * Disconnect from test database
     */
    protected function disconnectTestDatabase(): void
    {
        $this->db = null;
    }

    /**
     * Begin database transaction for test isolation
     */
    protected function beginTransaction(): void
    {
        if ($this->db !== null && $this->db->inTransaction() === false) {
            $this->db->beginTransaction();
        }
    }

    /**
     * Rollback database transaction to clean up test data
     */
    protected function rollbackTransaction(): void
    {
        if ($this->db !== null && $this->db->inTransaction() === true) {
            $this->db->rollBack();
        }
    }

    /**
     * Load database fixture from SQL file
     *
     * @param string $fixtureFile Path to fixture SQL file
     */
    protected function loadDbFixture(string $fixtureFile): void
    {
        if (!file_exists($fixtureFile)) {
            $this->fail("Fixture file not found: {$fixtureFile}");
        }

        $sql = file_get_contents($fixtureFile);
        if ($sql === false) {
            $this->fail("Failed to read fixture file: {$fixtureFile}");
        }

        // Split SQL by semicolon and execute each statement
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($stmt) => !empty($stmt) && !preg_match('/^--/', $stmt)
        );

        foreach ($statements as $statement) {
            try {
                $this->db->exec($statement);
            } catch (PDOException $e) {
                $this->fail(
                    "Failed to execute fixture statement: " . $e->getMessage() .
                    "\nStatement: " . substr($statement, 0, 100)
                );
            }
        }
    }

    /**
     * Clean session data
     */
    protected function cleanSession(): void
    {
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /**
     * Create a test user. Accepts structured first_name/last_name/degrees
     * (SPEC-NAME-SPLIT-001) OR a single full_name (legacy callers); when only
     * full_name is supplied, the structured columns are derived via
     * UserNameHelper::decompose(). When structured cols are supplied, full_name
     * is recomposed via UserNameHelper::compose().
     */
    protected function createTestUser(array $overrides = []): int
    {
        if (!class_exists('UserNameHelper')) {
            require_once __DIR__ . '/../includes/UserNameHelper.php';
        }

        $uniqueId = uniqid();
        $defaults = [
            'username'   => 'testuser_' . $uniqueId,
            'password'   => 'TestPassword123!',
            'full_name'  => 'Test User',
            'email'      => 'testuser_' . $uniqueId . '@test.com',
            'role'       => 'reviewer',
            'is_active'  => true,
        ];

        $data = array_merge($defaults, $overrides);

        // Reconcile structured + full_name. Whichever the caller supplied
        // wins; the other is derived to keep them consistent.
        if (isset($data['first_name']) || isset($data['last_name']) || isset($data['degrees'])) {
            $data['first_name'] = $data['first_name'] ?? '';
            $data['last_name']  = $data['last_name']  ?? '';
            $data['degrees']    = $data['degrees']    ?? '';
            $data['full_name']  = UserNameHelper::compose($data['first_name'], $data['last_name'], $data['degrees']);
        } else {
            $decomposed = UserNameHelper::decompose($data['full_name']);
            $data['first_name'] = $decomposed['first_name'];
            $data['last_name']  = $decomposed['last_name'];
            $data['degrees']    = $decomposed['degrees'];
        }

        $passwordHash = isset($data['password_hash'])
            ? $data['password_hash']
            : password_hash($data['password'], PASSWORD_DEFAULT);

        $stmt = $this->db->prepare(
            "INSERT INTO users (username, email, password_hash, full_name, first_name, last_name, degrees, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );

        $stmt->execute([
            $data['username'],
            $data['email'],
            $passwordHash,
            $data['full_name'],
            $data['first_name'],
            $data['last_name'],
            $data['degrees'] ?: null,
            $data['role'],
            $data['is_active'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    protected function createTestApplication(array $overrides = []): int
    {
        $uniqueId = uniqid();
        $defaults = [
            'applicant_name' => 'Test Applicant',
            'grant_id' => 'GRANT-' . $uniqueId,
            'application_title' => 'Test Application',
            'grant_type' => 'TRANSCEND Pilot',
            'grant_type_id' => null,
            'study_section_id' => null,
            'status' => 'pending',
            'is_complete' => false,
        ];

        $data = array_merge($defaults, $overrides);

        $stmt = $this->db->prepare(
            "INSERT INTO applications
             (applicant_name, grant_id, application_title, grant_type, grant_type_id, study_section_id, status, is_complete, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );

        $stmt->execute([
            $data['applicant_name'],
            $data['grant_id'],
            $data['application_title'],
            $data['grant_type'],
            $data['grant_type_id'],
            $data['study_section_id'],
            $data['status'],
            $data['is_complete'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    protected function createTestGrantType(array $overrides = []): int
    {
        $uniqueId = uniqid();
        $defaults = [
            'name' => 'Test Grant Type ' . $uniqueId,
            'description' => 'Test grant type description',
            'url' => null,
            'is_active' => true,
        ];

        $data = array_merge($defaults, $overrides);

        $stmt = $this->db->prepare(
            "INSERT INTO grant_types (name, description, url, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())"
        );

        $stmt->execute([
            $data['name'],
            $data['description'],
            $data['url'],
            $data['is_active'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    protected function createTestStudySection(array $overrides = []): int
    {
        $uniqueId = uniqid();

        if (!isset($overrides['grant_type_id'])) {
            $overrides['grant_type_id'] = $this->createTestGrantType();
        }

        $defaults = [
            'name' => 'Test Study Section ' . $uniqueId,
            'description' => 'Test study section description',
            'is_active' => true,
        ];

        $data = array_merge($defaults, $overrides);

        $stmt = $this->db->prepare(
            "INSERT INTO study_sections (name, description, grant_type_id, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())"
        );

        $stmt->execute([
            $data['name'],
            $data['description'],
            $data['grant_type_id'],
            $data['is_active'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    protected function createTestReview(array $overrides = []): int
    {
        if (!isset($overrides['application_id'])) {
            $overrides['application_id'] = $this->createTestApplication();
        }

        if (!isset($overrides['reviewer_id'])) {
            $overrides['reviewer_id'] = $this->createTestUser();
        }

        $defaults = [
            'overall_impact_score' => null,
            'overall_impact_explanation' => null,
            'relevance_score' => null,
            'relevance_explanation' => null,
            'budget_acceptable' => null,
            'budget_modifications' => null,
            'review_date' => null,
            'is_final' => false,
        ];

        $data = array_merge($defaults, $overrides);

        $stmt = $this->db->prepare(
            "INSERT INTO reviews
             (application_id, reviewer_id, overall_impact_score, overall_impact_explanation,
              relevance_score, relevance_explanation, budget_acceptable, budget_modifications,
              review_date, is_final, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );

        $stmt->execute([
            $data['application_id'],
            $data['reviewer_id'],
            $data['overall_impact_score'],
            $data['overall_impact_explanation'],
            $data['relevance_score'],
            $data['relevance_explanation'],
            $data['budget_acceptable'] !== null ? ($data['budget_acceptable'] ? 1 : 0) : null,
            $data['budget_modifications'],
            $data['review_date'],
            $data['is_final'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    protected function createTestDiscussionMessage(array $overrides = []): int
    {
        if (!isset($overrides['application_id'])) {
            $overrides['application_id'] = $this->createTestApplication();
        }

        if (!isset($overrides['user_id'])) {
            $overrides['user_id'] = $this->createTestUser();
        }

        $defaults = [
            'message' => 'Test discussion message.',
            'is_edited' => false,
            'edited_at' => null,
        ];

        $data = array_merge($defaults, $overrides);

        $stmt = $this->db->prepare(
            "INSERT INTO discussion_messages (application_id, user_id, message, is_edited, edited_at, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );

        $stmt->execute([
            $data['application_id'],
            $data['user_id'],
            $data['message'],
            $data['is_edited'] ? 1 : 0,
            $data['edited_at'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Assert that password hash is valid bcrypt
     *
     * @param string $hash Password hash to validate
     * @param string $message Optional assertion message
     */
    protected function assertValidBcryptHash(string $hash, string $message = ''): void
    {
        $this->assertMatchesRegularExpression(
            '/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/',
            $hash,
            $message ?: 'Password hash should be valid bcrypt format'
        );
    }

    /**
     * Assert that session variable exists
     *
     * @param string $key Session key
     * @param string $message Optional assertion message
     */
    protected function assertSessionHas(string $key, string $message = ''): void
    {
        $this->assertArrayHasKey(
            $key,
            $_SESSION,
            $message ?: "Session should contain key: {$key}"
        );
    }

    /**
     * Assert that session variable does not exist
     *
     * @param string $key Session key
     * @param string $message Optional assertion message
     */
    protected function assertSessionMissing(string $key, string $message = ''): void
    {
        $this->assertArrayNotHasKey(
            $key,
            $_SESSION,
            $message ?: "Session should not contain key: {$key}"
        );
    }

    /**
     * Set session variable
     *
     * @param string $key Session key
     * @param mixed $value Session value
     */
    protected function setSession(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Get session variable
     *
     * @param string $key Session key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Session value
     */
    protected function getSession(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }
}
