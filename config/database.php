<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Database connection failed.');
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    /**
     * Execute a query and return the PDOStatement.
     * If params are provided, uses prepare/execute.
     */
    public function query(string $sql, array $params = []): \PDOStatement {
        if (empty($params)) {
            return $this->connection->query($sql);
        }
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Prepare a statement
     */
    public function prepare(string $sql): \PDOStatement {
        return $this->connection->prepare($sql);
    }

    /**
     * Execute a statement
     */
    public function exec(string $sql): int|false {
        return $this->connection->exec($sql);
    }

    /**
     * Get last insert ID
     */
    public function lastInsertId(): string {
        return $this->connection->lastInsertId();
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool {
        return $this->connection->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool {
        return $this->connection->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollBack(): bool {
        return $this->connection->rollBack();
    }

    /**
     * Check if in transaction
     */
    public function inTransaction(): bool {
        return $this->connection->inTransaction();
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
