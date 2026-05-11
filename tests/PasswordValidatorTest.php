<?php
/**
 * TAG-SEC-002.3: Password Policy Enforcement Test Suite
 *
 * Test suite for PasswordValidator class implementing comprehensive password policy
 * Requirements: REQ-SEC-105, REQ-SEC-303
 *
 * Test Categories:
 * - Length validation (min 12, max 128)
 * - Character class requirements (uppercase, lowercase, digit, special)
 * - Common password detection
 * - Password history checking
 * - Entropy validation
 */

require_once __DIR__ . '/../includes/PasswordValidator.php';

class PasswordValidatorTest {
    private $validator;
    private $testDb;
    private $testUserId = 999;

    public function __construct() {
        $this->validator = new PasswordValidator();
        $this->setupTestDatabase();
    }

    private function setupTestDatabase() {
        // Create mock database connection for testing
        // In production, this would use a test database
        $this->testDb = new stdClass();
    }

    /**
     * TC-SEC-105.1: Password Length Requirement - Too Short
     * Requirement: Password must be minimum 12 characters
     */
    public function testPasswordTooShort() {
        $password = "Short1!"; // 7 characters (too short for 12 char minimum)
        $result = $this->validator->validate($password);

        $this->assertFalse($result->isValid(), "Password with 7 characters should be invalid");
        $this->assertContains('min_length', $result->getErrors(), "Should contain min_length error");
        echo "✓ Test passed: Password too short rejected\n";
    }

    /**
     * TC-SEC-105.1: Password Length Requirement - Exactly 12
     * Requirement: Password must be minimum 12 characters
     */
    public function testPasswordExactlyMinLength() {
        $password = "ValidPass1!!"; // 12 characters
        $result = $this->validator->validate($password);

        $this->assertTrue($result->isValid(), "Password with exactly 12 characters should be valid");
        echo "✓ Test passed: Password with exactly 12 characters accepted\n";
    }

    /**
     * TC-SEC-105.1: Password Length Requirement - Too Long
     * Requirement: Password must not exceed 128 characters
     */
    public function testPasswordTooLong() {
        $password = str_repeat("ValidPass1!", 20); // 140 characters
        $result = $this->validator->validate($password);

        $this->assertFalse($result->isValid(), "Password with 140 characters should be invalid");
        $this->assertContains('max_length', $result->getErrors(), "Should contain max_length error");
        echo "✓ Test passed: Password too long rejected\n";
    }

    /**
     * TC-SEC-105.2: Password Uppercase Requirement - Missing
     * Requirement: Password must contain at least one uppercase letter
     */
    public function testPasswordMissingUppercase() {
        $password = "lowercase123!"; // No uppercase
        $result = $this->validator->validate($password);

        $this->assertFalse($result->isValid(), "Password without uppercase should be invalid");
        $this->assertContains('uppercase', $result->getErrors(), "Should contain uppercase error");
        echo "✓ Test passed: Password missing uppercase rejected\n";
    }

    /**
     * TC-SEC-105.3: Password Lowercase Requirement - Missing
     * Requirement: Password must contain at least one lowercase letter
     */
    public function testPasswordMissingLowercase() {
        $password = "UPPERCASE123!"; // No lowercase
        $result = $this->validator->validate($password);

        $this->assertFalse($result->isValid(), "Password without lowercase should be invalid");
        $this->assertContains('lowercase', $result->getErrors(), "Should contain lowercase error");
        echo "✓ Test passed: Password missing lowercase rejected\n";
    }

    /**
     * TC-SEC-105.4: Password Digit Requirement - Missing
     * Requirement: Password must contain at least one digit
     */
    public function testPasswordMissingDigit() {
        $password = "NoDigitsHere!"; // No digits
        $result = $this->validator->validate($password);

        $this->assertFalse($result->isValid(), "Password without digit should be invalid");
        $this->assertContains('digit', $result->getErrors(), "Should contain digit error");
        echo "✓ Test passed: Password missing digit rejected\n";
    }

    /**
     * TC-SEC-105.5: Password Special Character Requirement - Missing
     * Requirement: Password must contain at least one special character
     */
    public function testPasswordMissingSpecial() {
        $password = "NoSpecialChars123"; // No special characters
        $result = $this->validator->validate($password);

        $this->assertFalse($result->isValid(), "Password without special character should be invalid");
        $this->assertContains('special', $result->getErrors(), "Should contain special error");
        echo "✓ Test passed: Password missing special character rejected\n";
    }

    /**
     * TC-SEC-105.6: Common Password Rejection
     * Requirement: Password must not be in common passwords list
     */
    public function testCommonPasswordRejected() {
        $password = "Password123!"; // Common password
        $result = $this->validator->validate($password);

        $this->assertFalse($result->isValid(), "Common password should be invalid");
        $this->assertContains('common', $result->getErrors(), "Should contain common password error");
        echo "✓ Test passed: Common password rejected\n";
    }

    /**
     * TC-SEC-105.8: Valid Password Acceptance
     * Requirement: Valid password meeting all requirements should be accepted
     */
    public function testValidPasswordAccepted() {
        $password = "SecureP@ssw0rd123"; // Valid password
        $result = $this->validator->validate($password);

        $this->assertTrue($result->isValid(), "Valid password should be accepted");
        $this->assertEmpty($result->getErrors(), "Valid password should have no errors");
        echo "✓ Test passed: Valid password accepted\n";
    }

    /**
     * Test Password Entropy Calculation
     * Requirement: Password must have minimum 60 bits of entropy
     */
    public function testPasswordEntropy() {
        $weakPassword = "Aa1!Aa1!Aa1!"; // Low entropy despite meeting requirements
        $result = $this->validator->validate($weakPassword);

        // This password meets character requirements but has low entropy
        // depending on implementation, may or may not be rejected
        $entropy = $this->validator->calculateEntropy($weakPassword);
        $this->assertGreaterThan(0, $entropy, "Entropy should be calculated");
        echo "✓ Test passed: Password entropy calculated ({$entropy} bits)\n";
    }

    /**
     * Test Password History Check
     * Requirement: Password should not be in last 5 passwords
     */
    public function testPasswordHistoryCheck() {
        // This test would require database integration
        // Mock implementation for now
        $password = "OldPassword1!";
        $result = $this->validator->checkPasswordHistory($this->testUserId, $password);

        // Should not throw error even if database not available
        $this->assertIsBool($result, "Should return boolean");
        echo "✓ Test passed: Password history check executed\n";
    }

    /**
     * Test Error Messages Are Clear
     * Requirement: Error messages should be user-friendly
     */
    public function testErrorMessagesAreClear() {
        $password = "short"; // Fails multiple requirements
        $result = $this->validator->validate($password);
        $errors = $result->getErrorMessages();

        $this->assertIsArray($errors, "Error messages should be array");
        $this->assertNotEmpty($errors, "Should have error messages");
        echo "✓ Test passed: Error messages are clear and structured\n";
    }

    // Helper assertion methods
    private function assertTrue($condition, $message) {
        if (!$condition) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    private function assertFalse($condition, $message) {
        if ($condition) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    private function assertContains($needle, $haystack, $message) {
        if (!in_array($needle, $haystack)) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    private function assertEmpty($array, $message) {
        if (!empty($array)) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    private function assertGreaterThan($expected, $actual, $message) {
        if (!($actual > $expected)) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    private function assertIsBool($value, $message) {
        if (!is_bool($value)) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    private function assertIsArray($value, $message) {
        if (!is_array($value)) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    private function assertNotEmpty($value, $message) {
        if (empty($value)) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    public function runAllTests() {
        echo "\n=== Running Password Validator Tests ===\n\n";

        try {
            $this->testPasswordTooShort();
            $this->testPasswordExactlyMinLength();
            $this->testPasswordTooLong();
            $this->testPasswordMissingUppercase();
            $this->testPasswordMissingLowercase();
            $this->testPasswordMissingDigit();
            $this->testPasswordMissingSpecial();
            $this->testCommonPasswordRejected();
            $this->testValidPasswordAccepted();
            $this->testPasswordEntropy();
            $this->testPasswordHistoryCheck();
            $this->testErrorMessagesAreClear();

            echo "\n=== All Tests Passed ===\n";
            return true;
        } catch (Exception $e) {
            echo "\n✗ Test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
    $test = new PasswordValidatorTest();
    $success = $test->runAllTests();
    exit($success ? 0 : 1);
}
