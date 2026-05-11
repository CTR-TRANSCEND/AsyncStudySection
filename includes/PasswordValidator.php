<?php
declare(strict_types=1);
/**
 * TAG-SEC-002.3: Password Policy Enforcement
 *
 * Comprehensive password validation class implementing strong password policy
 * Requirements: REQ-SEC-105, REQ-SEC-303
 *
 * Features:
 * - Length validation (min 12, max 128 characters)
 * - Character class requirements (uppercase, lowercase, digit, special)
 * - Common password detection
 * - Password entropy calculation (minimum 60 bits)
 * - Password history checking
 *
 * @version 1.0.0
 * @tag TAG-SEC-002.3
 */

class PasswordValidator {

    /** @var int Minimum password length */
    private $minLength;

    /** @var int Maximum password length */
    private $maxLength;

    /** @var bool Require uppercase letter */
    private $requireUppercase;

    /** @var bool Require lowercase letter */
    private $requireLowercase;

    /** @var bool Require digit */
    private $requireDigit;

    /** @var bool Require special character */
    private $requireSpecial;

    /** @var int Minimum entropy in bits */
    private $minEntropy;

    /** @var int Number of passwords to check in history */
    private $historyCount;

    /** @var array Common passwords list */
    private $commonPasswords;

    /** @var PDO|null Database connection for history checking */
    private $db;

    /**
     * Constructor
     *
     * @param array $config Configuration options
     */
    public function __construct(array $config = []) {
        $this->minLength = $config['min_length'] ?? (defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 12);
        $this->maxLength = $config['max_length'] ?? 128;
        $this->requireUppercase = $config['require_uppercase'] ?? true;
        $this->requireLowercase = $config['require_lowercase'] ?? true;
        $this->requireDigit = $config['require_digit'] ?? true;
        $this->requireSpecial = $config['require_special'] ?? true;
        $this->minEntropy = $config['min_entropy'] ?? 60;
        $this->historyCount = $config['history_count'] ?? 5;

        $this->loadCommonPasswords();
        $this->loadDatabaseConnection();
    }

    /**
     * Validate password against all policy requirements
     *
     * @param string $password Password to validate
     * @return ValidationResult Validation result with errors
     */
    public function validate(string $password): ValidationResult {
        $errors = [];

        // Length validation
        if (strlen($password) < $this->minLength) {
            $errors[] = 'min_length';
        }

        if (strlen($password) > $this->maxLength) {
            $errors[] = 'max_length';
        }

        // Character class validation
        if ($this->requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'uppercase';
        }

        if ($this->requireLowercase && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'lowercase';
        }

        if ($this->requireDigit && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'digit';
        }

        if ($this->requireSpecial && !preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/', $password)) {
            $errors[] = 'special';
        }

        // Common password check
        if ($this->isCommonPassword($password)) {
            $errors[] = 'common';
        }

        // Entropy check
        if ($this->calculateEntropy($password) < $this->minEntropy) {
            $errors[] = 'entropy';
        }

        return new ValidationResult($errors, $this);
    }

    /**
     * Check if password is in user's password history
     *
     * @param int $userId User ID
     * @param string $password Password to check
     * @return bool True if password is in history (should be rejected)
     */
    public function checkPasswordHistory(int $userId, string $password): bool {
        if (!$this->db || $this->historyCount <= 0) {
            return false; // No history check if DB not available
        }

        try {
            $stmt = $this->db->prepare("
                SELECT password_hash
                FROM password_history
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $this->historyCount]);
            $history = $stmt->fetchAll();

            foreach ($history as $entry) {
                if (password_verify($password, $entry['password_hash'])) {
                    return true; // Password found in history
                }
            }
        } catch (PDOException $e) {
            // Log error but don't block password change
            error_log("Password history check failed: " . $e->getMessage());
        }

        return false; // Password not in history
    }

    /**
     * Save password to history
     *
     * @param int $userId User ID
     * @param string $passwordHash Password hash to store
     * @return bool True if saved successfully
     */
    public function saveToHistory(int $userId, string $passwordHash): bool {
        if (!$this->db) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO password_history (user_id, password_hash)
                VALUES (?, ?)
            ");
            $stmt->execute([$userId, $passwordHash]);

            // Prune old history entries
            $this->pruneHistory($userId);

            return true;
        } catch (PDOException $e) {
            error_log("Failed to save password history: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Calculate password entropy in bits
     *
     * @param string $password Password to analyze
     * @return float Entropy in bits
     */
    public function calculateEntropy(string $password): float {
        $length = strlen($password);
        if ($length === 0) {
            return 0.0;
        }

        // Determine character set size
        $charsetSize = 0;
        if (preg_match('/[a-z]/', $password)) $charsetSize += 26;
        if (preg_match('/[A-Z]/', $password)) $charsetSize += 26;
        if (preg_match('/[0-9]/', $password)) $charsetSize += 10;
        if (preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/', $password)) $charsetSize += 26;

        if ($charsetSize === 0) {
            return 0.0;
        }

        // Entropy = length * log2(charset_size)
        return $length * log($charsetSize, 2);
    }

    /**
     * Load common passwords list
     */
    private function loadCommonPasswords(): void {
        // Top 100 most common passwords (abbreviated list for example)
        $this->commonPasswords = [
            'password', '123456', 'password123', 'admin', 'qwerty',
            '12345678', 'abc123', 'Password123!', 'letmein', 'trustno1',
            'dragon', 'baseball', '111111', 'iloveyou', 'master',
            'sunshine', 'ashley', 'bailey', 'passw0rd', 'shadow',
            '123123', '654321', 'superman', 'qazwsx', 'michael',
            'football', '1234', 'welcome', 'login', 'princess',
            'solo', 'azerty', 'password1', 'qwerty123', '123qwe'
        ];
    }

    /**
     * Check if password is common
     *
     * @param string $password Password to check
     * @return bool True if password is common
     */
    private function isCommonPassword(string $password): bool {
        $lowerPassword = strtolower($password);
        foreach ($this->commonPasswords as $common) {
            if (strtolower($common) === $lowerPassword) {
                return true;
            }
        }
        return false;
    }

    /**
     * Load database connection
     */
    private function loadDatabaseConnection(): void {
        try {
            if (class_exists('Database')) {
                $this->db = Database::getInstance()->getConnection();
            }
        } catch (Exception $e) {
            $this->db = null;
        }
    }

    /**
     * Prune old password history entries
     *
     * @param int $userId User ID
     */
    private function pruneHistory(int $userId): void {
        if (!$this->db || $this->historyCount <= 0) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                DELETE FROM password_history
                WHERE user_id = ? AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM password_history
                        WHERE user_id = ?
                        ORDER BY created_at DESC
                        LIMIT ?
                    ) AS recent
                )
            ");
            $stmt->execute([$userId, $userId, $this->historyCount]);
        } catch (PDOException $e) {
            error_log("Failed to prune password history: " . $e->getMessage());
        }
    }

    /**
     * Get minimum password length
     *
     * @return int Minimum length
     */
    public function getMinLength(): int {
        return $this->minLength;
    }

    /**
     * Get maximum password length
     *
     * @return int Maximum length
     */
    public function getMaxLength(): int {
        return $this->maxLength;
    }
}

/**
 * Validation Result Class
 *
 * Encapsulates password validation results with detailed error information
 */
class ValidationResult {

    /** @var array List of error codes */
    private $errors;

    /** @var PasswordValidator Reference to validator */
    private $validator;

    /**
     * Constructor
     *
     * @param array $errors List of error codes
     * @param PasswordValidator $validator Reference to validator
     */
    public function __construct(array $errors, PasswordValidator $validator) {
        $this->errors = $errors;
        $this->validator = $validator;
    }

    /**
     * Check if password is valid
     *
     * @return bool True if valid (no errors)
     */
    public function isValid(): bool {
        return empty($this->errors);
    }

    /**
     * Get error codes
     *
     * @return array List of error codes
     */
    public function getErrors(): array {
        return $this->errors;
    }

    /**
     * Get user-friendly error messages
     *
     * @return array List of error messages
     */
    public function getErrorMessages(): array {
        $messages = [];
        foreach ($this->errors as $error) {
            $messages[] = $this->getErrorMessage($error);
        }
        return $messages;
    }

    /**
     * Get error message for specific error code
     *
     * @param string $errorCode Error code
     * @return string Error message
     */
    private function getErrorMessage(string $errorCode): string {
        $messages = [
            'min_length' => sprintf(
                'Password must be at least %d characters long',
                $this->validator->getMinLength()
            ),
            'max_length' => sprintf(
                'Password must not exceed %d characters',
                $this->validator->getMaxLength()
            ),
            'uppercase' => 'Password must contain at least one uppercase letter (A-Z)',
            'lowercase' => 'Password must contain at least one lowercase letter (a-z)',
            'digit' => 'Password must contain at least one digit (0-9)',
            'special' => 'Password must contain at least one special character (!@#$%^&* etc.)',
            'common' => 'This password is too common. Please choose a more unique password.',
            'entropy' => 'Password is not complex enough. Please use a more varied password.'
        ];

        return $messages[$errorCode] ?? 'Invalid password';
    }
}
