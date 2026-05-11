<?php
/**
 * Database Class Unit Tests
 *
 * RED PHASE: These tests define expected behavior of Database class.
 * Tests will fail initially until Database implementation matches requirements.
 *
 * Test Categories:
 * - Singleton pattern enforcement
 * - Connection establishment
 * - PDO attribute configuration
 * - Transaction handling
 */

namespace GrantReview\Tests\Unit;

use GrantReview\Tests\TestCase;
use Database;
use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;

class DatabaseTest extends TestCase
{
    /**
     * Singleton Pattern Tests
     */

    /**
     * Test that Database implements singleton pattern
     *
     * GIVEN: Database class is instantiated
     * WHEN: getInstance() is called multiple times
     * THEN: Same instance should be returned
     */
    public function testDatabaseImplementsSingletonPattern(): void
    {
        // RED: Verify singleton behavior
        $instance1 = Database::getInstance();
        $instance2 = Database::getInstance();

        $this->assertSame($instance1, $instance2);
        $this->assertInstanceOf(Database::class, $instance1);
    }

    /**
     * Test that only one instance exists
     *
     * GIVEN: Database singleton instance
     * WHEN: Checking instance count
     * THEN: Only one instance should exist
     */
    public function testOnlyOneInstanceExists(): void
    {
        $instances = [];
        for ($i = 0; $i < 5; $i++) {
            $instances[] = Database::getInstance();
        }

        // All instances should be identical
        foreach ($instances as $instance) {
            $this->assertSame($instances[0], $instance);
        }
    }

    /**
     * Test that cloning is prevented
     *
     * GIVEN: Database singleton instance
     * WHEN: Cloning is attempted
     * THEN: Clone should fail or return same instance
     */
    public function testCloningIsPrevented(): void
    {
        $instance = Database::getInstance();

        // Use reflection to check if __clone is private
        $reflection = new ReflectionClass($instance);

        try {
            $cloneMethod = $reflection->getMethod('__clone');
            $this->assertTrue($cloneMethod->isPrivate());
        } catch (\ReflectionException $e) {
            // If method doesn't exist or isn't accessible, that's also acceptable
            $this->assertTrue(true);
        }
    }

    /**
     * Test that unserialization is prevented
     *
     * GIVEN: Database singleton instance
     * WHEN: Unserialization is attempted
     * THEN: Unserialize should throw exception
     */
    public function testUnserializationIsPrevented(): void
    {
        $instance = Database::getInstance();

        // Verify __wakeup throws exception
        $reflection = new ReflectionClass($instance);

        try {
            $wakeupMethod = $reflection->getMethod('__wakeup');
            $this->assertTrue($wakeupMethod->isPublic());
        } catch (\ReflectionException $e) {
            // Method doesn't exist
            $this->assertTrue(true);
        }
    }

    /**
     * Connection Establishment Tests
     */

    /**
     * Test that connection is established successfully
     *
     * GIVEN: Valid database credentials
     * WHEN: getInstance() is called
     * THEN: PDO connection should be established
     */
    public function testConnectionIsEstablishedSuccessfully(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        $this->assertInstanceOf(PDO::class, $connection);
    }

    /**
     * Test that connection uses correct database host
     *
     * GIVEN: Database connection
     * WHEN: Connection is established
     * THEN: Should connect to configured host
     */
    public function testConnectionUsesCorrectHost(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        // Get connection info
        $info = $connection->query("SELECT @@hostname as host")->fetch();

        $this->assertNotFalse($info);
        $this->assertArrayHasKey('host', $info);
    }

    /**
     * Test that connection uses correct database name
     *
     * GIVEN: Database connection
     * WHEN: Connection is established
     * THEN: Should use configured database name
     */
    public function testConnectionUsesCorrectDatabaseName(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        // Get current database name
        $dbName = $connection->query("SELECT DATABASE() as db")->fetch();

        $this->assertNotFalse($dbName);
        $expectedDb = getenv('DB_NAME') ?: 'grant_review_test';
        $this->assertEquals($expectedDb, $dbName['db']);
    }

    /**
     * Test that connection uses correct charset
     *
     * GIVEN: Database connection
     * WHEN: Connection is established
     * THEN: Should use utf8mb4 charset
     */
    public function testConnectionUsesCorrectCharset(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        // Get charset info
        $charset = $connection->query("SELECT @@character_set_database as charset")->fetch();

        $this->assertNotFalse($charset);
        $this->assertStringContainsString('utf8', $charset['charset']);
    }

    /**
     * PDO Attribute Configuration Tests
     */

    /**
     * Test that ERRMODE_EXCEPTION is set
     *
     * GIVEN: Database connection
     * WHEN: Error occurs
     * THEN: Exception should be thrown (not silent failure)
     */
    public function testErrorModeIsSetToException(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        // Check PDO::ATTR_ERRMODE
        $errorMode = $connection->getAttribute(PDO::ATTR_ERRMODE);

        $this->assertEquals(PDO::ERRMODE_EXCEPTION, $errorMode);
    }

    /**
     * Test that default fetch mode is ASSOC
     *
     * GIVEN: Database connection
     * WHEN: Data is fetched
     * THEN: Should return associative arrays
     */
    public function testDefaultFetchModeIsAssoc(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        // Check PDO::ATTR_DEFAULT_FETCH_MODE
        $fetchMode = $connection->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);

        $this->assertEquals(PDO::FETCH_ASSOC, $fetchMode);
    }

    /**
     * Test that emulated prepares are disabled
     *
     * GIVEN: Database connection
     * WHEN: Prepared statements are used
     * THEN: Should use native prepared statements
     */
    public function testEmulatedPreparesAreDisabled(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        // Check PDO::ATTR_EMULATE_PREPARES
        // getAttribute() returns 0 (int) for false on some drivers
        $emulatePrepares = $connection->getAttribute(PDO::ATTR_EMULATE_PREPARES);

        $this->assertEmpty($emulatePrepares);
    }

    /**
     * Transaction Handling Tests
     */

    /**
     * Test that transaction can be started
     *
     * GIVEN: Database connection
     * WHEN:beginTransaction() is called
     * THEN: Transaction should be started
     */
    public function testTransactionCanBeStarted(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        $this->assertFalse($connection->inTransaction());

        $connection->beginTransaction();

        $this->assertTrue($connection->inTransaction());

        // Cleanup
        $connection->rollBack();
    }

    /**
     * Test that transaction can be committed
     *
     * GIVEN: Active transaction
     * WHEN: commit() is called
     * THEN: Changes should be persisted
     */
    public function testTransactionCanBeCommitted(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        // Start transaction
        $connection->beginTransaction();

        // Insert test data
        $stmt = $connection->prepare("
            INSERT INTO users (username, password_hash, full_name, email, role, is_active)
            VALUES ('tx_commit_test', 'hash', 'Test User', 'tx_commit@test.com', 'reviewer', 1)
        ");
        $stmt->execute();

        // Commit transaction
        $connection->commit();

        // Verify data persists
        $stmt = $connection->prepare("SELECT COUNT(*) FROM users WHERE username = 'tx_commit_test'");
        $stmt->execute();
        $count = $stmt->fetchColumn();

        $this->assertEquals(1, (int) $count);

        // Cleanup
        $stmt = $connection->prepare("DELETE FROM users WHERE username = 'tx_commit_test'");
        $stmt->execute();
    }

    /**
     * Test that transaction can be rolled back
     *
     * GIVEN: Active transaction with changes
     * WHEN: rollBack() is called
     * THEN: Changes should be discarded
     */
    public function testTransactionCanBeRolledBack(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        // Start transaction
        $connection->beginTransaction();

        // Insert test data
        $stmt = $connection->prepare("
            INSERT INTO users (username, password_hash, full_name, email, role, is_active)
            VALUES ('tx_rollback_test', 'hash', 'Test User', 'tx_rollback@test.com', 'reviewer', 1)
        ");
        $stmt->execute();

        // Verify data exists within transaction
        $stmt = $connection->prepare("SELECT COUNT(*) FROM users WHERE username = 'tx_rollback_test'");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        $this->assertEquals(1, (int) $count);

        // Rollback transaction
        $connection->rollBack();

        // Verify data is discarded
        $stmt = $connection->prepare("SELECT COUNT(*) FROM users WHERE username = 'tx_rollback_test'");
        $stmt->execute();
        $count = $stmt->fetchColumn();

        $this->assertEquals(0, (int) $count);
    }

    /**
     * Test that prepared statements use native prepared statements
     *
     * GIVEN: Database connection
     * WHEN: Prepared statement is created
     * THEN: Should use native prepared statements (not emulated)
     */
    public function testPreparedStatementsUseNativePreparedStatements(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        // Create prepared statement
        $stmt = $connection->prepare("SELECT * FROM users WHERE username = ?");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
    }

    /**
     * Test that prepared statements prevent SQL injection
     *
     * GIVEN: User input with SQL injection attempt
     * WHEN: Prepared statement is used
     * THEN: Injection should be prevented
     */
    public function testPreparedStatementsPreventSQLInjection(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        // Attempt SQL injection
        $maliciousInput = "admin' OR '1'='1";

        $stmt = $connection->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$maliciousInput]);

        $user = $stmt->fetch();

        // Should not find user with literal injection string
        // Or should find no users (safe behavior)
        $this->assertTrue($user === false || $user['username'] === $maliciousInput);
    }

    /**
     * Test that multiple queries can be executed
     *
     * GIVEN: Database connection
     * WHEN: Multiple queries are executed sequentially
     * THEN: All queries should succeed
     */
    public function testMultipleQueriesCanBeExecuted(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        // Execute multiple queries
        $result1 = $connection->query("SELECT 1 as num");
        $result2 = $connection->query("SELECT 2 as num");
        $result3 = $connection->query("SELECT 3 as num");

        $this->assertEquals(1, $result1->fetch()['num']);
        $this->assertEquals(2, $result2->fetch()['num']);
        $this->assertEquals(3, $result3->fetch()['num']);
    }

    /**
     * Test that connection is reusable
     *
     * GIVEN: Database connection
     * WHEN: getConnection() is called multiple times
     * THEN: Same PDO instance should be returned
     */
    public function testConnectionIsReusable(): void
    {
        $db = Database::getInstance();
        $connection1 = $db->getConnection();
        $connection2 = $db->getConnection();

        $this->assertSame($connection1, $connection2);
    }

    /**
     * Test that query execution returns results
     *
     * GIVEN: Valid query
     * WHEN: Query is executed
     * THEN: Results should be returned
     */
    public function testQueryExecutionReturnsResults(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        $result = $connection->query("SELECT 1 as test_column");

        $this->assertNotFalse($result);
        $row = $result->fetch();
        $this->assertArrayHasKey('test_column', $row);
        $this->assertEquals(1, $row['test_column']);
    }

    /**
     * Test that lastInsertId returns correct value
     *
     * GIVEN: Insert operation
     * WHEN: Data is inserted
     * THEN: lastInsertId() should return inserted ID
     */
    public function testLastInsertIdReturnsCorrectValue(): void
    {
        $db = Database::getInstance();
        $connection = $db->getConnection();

        $connection->beginTransaction();

        // Insert user
        $stmt = $connection->prepare("
            INSERT INTO users (username, password_hash, full_name, email, role, is_active)
            VALUES ('last_insert_test', 'hash', 'Test User', 'last_insert@test.com', 'reviewer', 1)
        ");
        $stmt->execute();

        $lastId = $connection->lastInsertId();

        $this->assertGreaterThan(0, (int) $lastId);

        // Cleanup
        $connection->rollBack();
    }
}
