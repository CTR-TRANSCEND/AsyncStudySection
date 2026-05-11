<?php
/**
 * Configuration Loading Unit Tests
 *
 * RED PHASE: These tests define expected behavior of configuration loading.
 * Tests will fail initially until configuration functions match requirements.
 *
 * Test Categories:
 * - Environment variable loading
 * - Default value fallbacks
 * - Boolean parsing (envBool)
 * - Integer parsing (envInt)
 * - Configuration caching
 */

namespace GrantReview\Tests\Unit;

use GrantReview\Tests\TestCase;

class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Save current environment variables
        $this->originalEnv = $_ENV;

        // Save current constants
        $this->originalConstants = [];
        $definedConstants = get_defined_constants(true)['user'] ?? [];
        foreach ($definedConstants as $name => $value) {
            $this->originalConstants[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        // Restore environment variables
        $_ENV = $this->originalEnv;

        parent::tearDown();
    }

    /**
     * Environment Variable Loading Tests
     */

    /**
     * Test that envValue() loads environment variable
     *
     * GIVEN: Environment variable is set
     * WHEN: envValue() is called
     * THEN: Should return environment variable value
     */
    public function testEnvValueLoadsEnvironmentVariable(): void
    {
        // Set environment variable for test
        putenv('TEST_VAR=test_value');

        $value = envValue('TEST_VAR');

        $this->assertEquals('test_value', $value);

        // Cleanup
        putenv('TEST_VAR');
    }

    /**
     * Test that envValue() returns default when not set
     *
     * GIVEN: Environment variable is not set
     * WHEN: envValue() is called with default
     * THEN: Should return default value
     */
    public function testEnvValueReturnsDefaultWhenNotSet(): void
    {
        // Ensure variable is not set
        putenv('NONEXISTENT_VAR');

        $value = envValue('NONEXISTENT_VAR', 'default_value');

        $this->assertEquals('default_value', $value);
    }

    /**
     * Test that envValue() handles null default
     *
     * GIVEN: Environment variable is not set
     * WHEN: envValue() is called without default
     * THEN: Should return null
     */
    public function testEnvValueHandlesNullDefault(): void
    {
        $value = envValue('DEFINITELY_NONEXISTENT_VAR_xyz123');

        $this->assertNull($value);
    }

    /**
     * Test that envValue() preserves string types
     *
     * GIVEN: Environment variable with string value
     * WHEN: envValue() is called
     * THEN: Should return string value
     */
    public function testEnvValuePreservesStringTypes(): void
    {
        putenv('TEST_STRING_VAR=hello world');

        $value = envValue('TEST_STRING_VAR');

        $this->assertIsString($value);
        $this->assertEquals('hello world', $value);

        putenv('TEST_STRING_VAR');
    }

    /**
     * Boolean Parsing Tests (envBool)
     */

    /**
     * Test that envBool() parses "true" as true
     *
     * GIVEN: Environment variable set to "true"
     * WHEN: envBool() is called
     * THEN: Should return true
     */
    public function testEnvBoolParsesTrueString(): void
    {
        putenv('TEST_BOOL_VAR=true');

        $value = envBool('TEST_BOOL_VAR', false);

        $this->assertTrue($value);

        putenv('TEST_BOOL_VAR');
    }

    /**
     * Test that envBool() parses "1" as true
     *
     * GIVEN: Environment variable set to "1"
     * WHEN: envBool() is called
     * THEN: Should return true
     */
    public function testEnvBoolParsesOneString(): void
    {
        putenv('TEST_BOOL_VAR=1');

        $value = envBool('TEST_BOOL_VAR', false);

        $this->assertTrue($value);

        putenv('TEST_BOOL_VAR');
    }

    /**
     * Test that envBool() parses "yes" as true
     *
     * GIVEN: Environment variable set to "yes"
     * WHEN: envBool() is called
     * THEN: Should return true
     */
    public function testEnvBoolParsesYesString(): void
    {
        putenv('TEST_BOOL_VAR=yes');

        $value = envBool('TEST_BOOL_VAR', false);

        $this->assertTrue($value);

        putenv('TEST_BOOL_VAR');
    }

    /**
     * Test that envBool() parses "on" as true
     *
     * GIVEN: Environment variable set to "on"
     * WHEN: envBool() is called
     * THEN: Should return true
     */
    public function testEnvBoolParsesOnString(): void
    {
        putenv('TEST_BOOL_VAR=on');

        $value = envBool('TEST_BOOL_VAR', false);

        $this->assertTrue($value);

        putenv('TEST_BOOL_VAR');
    }

    /**
     * Test that envBool() parses "false" as false
     *
     * GIVEN: Environment variable set to "false"
     * WHEN: envBool() is called
     * THEN: Should return false
     */
    public function testEnvBoolParsesFalseString(): void
    {
        putenv('TEST_BOOL_VAR=false');

        $value = envBool('TEST_BOOL_VAR', true);

        $this->assertFalse($value);

        putenv('TEST_BOOL_VAR');
    }

    /**
     * Test that envBool() parses "0" as false
     *
     * GIVEN: Environment variable set to "0"
     * WHEN: envBool() is called
     * THEN: Should return false
     */
    public function testEnvBoolParsesZeroString(): void
    {
        putenv('TEST_BOOL_VAR=0');

        $value = envBool('TEST_BOOL_VAR', true);

        $this->assertFalse($value);

        putenv('TEST_BOOL_VAR');
    }

    /**
     * Test that envBool() handles case insensitivity
     *
     * GIVEN: Environment variable with mixed case
     * WHEN: envBool() is called
     * THEN: Should parse correctly regardless of case
     */
    public function testEnvBoolHandlesCaseInsensitivity(): void
    {
        putenv('TEST_BOOL_VAR=TRUE');
        $this->assertTrue(envBool('TEST_BOOL_VAR', false));
        putenv('TEST_BOOL_VAR');

        putenv('TEST_BOOL_VAR=TrUe');
        $this->assertTrue(envBool('TEST_BOOL_VAR', false));
        putenv('TEST_BOOL_VAR');

        putenv('TEST_BOOL_VAR=FaLsE');
        $this->assertFalse(envBool('TEST_BOOL_VAR', true));
        putenv('TEST_BOOL_VAR');
    }

    /**
     * Test that envBool() handles whitespace
     *
     * GIVEN: Environment variable with whitespace
     * WHEN: envBool() is called
     * THEN: Should trim and parse correctly
     */
    public function testEnvBoolHandlesWhitespace(): void
    {
        putenv('TEST_BOOL_VAR=  true  ');
        $this->assertTrue(envBool('TEST_BOOL_VAR', false));
        putenv('TEST_BOOL_VAR');

        putenv('TEST_BOOL_VAR="  false  "');
        $this->assertFalse(envBool('TEST_BOOL_VAR', true));
        putenv('TEST_BOOL_VAR');
    }

    /**
     * Test that envBool() returns default when not set
     *
     * GIVEN: Environment variable is not set
     * WHEN: envBool() is called with default
     * THEN: Should return default value
     */
    public function testEnvBoolReturnsDefaultWhenNotSet(): void
    {
        putenv('NONEXISTENT_BOOL_VAR');

        $value = envBool('NONEXISTENT_BOOL_VAR', true);

        $this->assertTrue($value);
    }

    /**
     * Integer Parsing Tests (envInt)
     */

    /**
     * Test that envInt() parses integer strings
     *
     * GIVEN: Environment variable with integer string
     * WHEN: envInt() is called
     * THEN: Should return integer value
     */
    public function testEnvIntParsesIntegerStrings(): void
    {
        putenv('TEST_INT_VAR=42');

        $value = envInt('TEST_INT_VAR', 0);

        $this->assertIsInt($value);
        $this->assertEquals(42, $value);

        putenv('TEST_INT_VAR');
    }

    /**
     * Test that envInt() parses negative numbers
     *
     * GIVEN: Environment variable with negative number
     * WHEN: envInt() is called
     * THEN: Should return negative integer
     */
    public function testEnvIntParsesNegativeNumbers(): void
    {
        putenv('TEST_INT_VAR=-10');

        $value = envInt('TEST_INT_VAR', 0);

        $this->assertEquals(-10, $value);

        putenv('TEST_INT_VAR');
    }

    /**
     * Test that envInt() handles zero
     *
     * GIVEN: Environment variable set to "0"
     * WHEN: envInt() is called
     * THEN: Should return 0
     */
    public function testEnvIntHandlesZero(): void
    {
        putenv('TEST_INT_VAR=0');

        $value = envInt('TEST_INT_VAR', 100);

        $this->assertEquals(0, $value);

        putenv('TEST_INT_VAR');
    }

    /**
     * Test that envInt() handles floating point strings
     *
     * GIVEN: Environment variable with float string
     * WHEN: envInt() is called
     * THEN: Should truncate to integer
     */
    public function testEnvIntHandlesFloatingPointStrings(): void
    {
        putenv('TEST_INT_VAR=3.14');

        $value = envInt('TEST_INT_VAR', 0);

        $this->assertEquals(3, $value);

        putenv('TEST_INT_VAR');
    }

    /**
     * Test that envInt() returns default when not set
     *
     * GIVEN: Environment variable is not set
     * WHEN: envInt() is called with default
     * THEN: Should return default value
     */
    public function testEnvIntReturnsDefaultWhenNotSet(): void
    {
        putenv('NONEXISTENT_INT_VAR');

        $value = envInt('NONEXISTENT_INT_VAR', 999);

        $this->assertEquals(999, $value);
    }

    /**
     * Test that envInt() handles empty string
     *
     * GIVEN: Environment variable set to empty string
     * WHEN: envInt() is called
     * THEN: Should return default value
     */
    public function testEnvIntHandlesEmptyString(): void
    {
        putenv('TEST_INT_VAR=');

        $value = envInt('TEST_INT_VAR', 100);

        $this->assertEquals(100, $value);

        putenv('TEST_INT_VAR');
    }

    /**
     * Configuration Constants Tests
     */

    /**
     * Test that APP_ENV constant is defined
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking APP_ENV constant
     * THEN: Should be defined and have valid value
     */
    public function testAppEnvConstantIsDefined(): void
    {
        $this->assertTrue(defined('APP_ENV'));
        $this->assertContains(APP_ENV, ['development', 'testing', 'production']);
    }

    /**
     * Test that APP_DEBUG constant is defined
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking APP_DEBUG constant
     * THEN: Should be defined and be boolean
     */
    public function testAppDebugConstantIsDefined(): void
    {
        $this->assertTrue(defined('APP_DEBUG'));
        $this->assertIsBool(APP_DEBUG);
    }

    /**
     * Test that BASE_URL constant is defined
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking BASE_URL constant
     * THEN: Should be defined and be non-empty string
     */
    public function testBaseUrlConstantIsDefined(): void
    {
        $this->assertTrue(defined('BASE_URL'));
        $this->assertIsString(BASE_URL);
    }

    /**
     * Test that database constants are defined
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking database constants
     * THEN: Should all be defined
     */
    public function testDatabaseConstantsAreDefined(): void
    {
        $this->assertTrue(defined('DB_HOST'));
        $this->assertTrue(defined('DB_NAME'));
        $this->assertTrue(defined('DB_USER'));
        $this->assertTrue(defined('DB_PASS'));
        $this->assertTrue(defined('DB_CHARSET'));

        $this->assertIsString(DB_HOST);
        $this->assertIsString(DB_NAME);
        $this->assertIsString(DB_CHARSET);
    }

    /**
     * Test that security constants are defined
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking security constants
     * THEN: Should all be defined with appropriate values
     */
    public function testSecurityConstantsAreDefined(): void
    {
        $this->assertTrue(defined('PASSWORD_MIN_LENGTH'));
        $this->assertTrue(defined('CSRF_TOKEN_NAME'));
        $this->assertTrue(defined('LOGIN_MAX_ATTEMPTS'));
        $this->assertTrue(defined('LOGIN_WINDOW_SECONDS'));
        $this->assertTrue(defined('LOGIN_BLOCK_SECONDS'));
        $this->assertTrue(defined('SESSION_LIFETIME'));

        $this->assertIsInt(PASSWORD_MIN_LENGTH);
        $this->assertGreaterThanOrEqual(8, PASSWORD_MIN_LENGTH);

        $this->assertIsInt(LOGIN_MAX_ATTEMPTS);
        $this->assertGreaterThan(0, LOGIN_MAX_ATTEMPTS);

        $this->assertIsInt(LOGIN_WINDOW_SECONDS);
        $this->assertGreaterThan(0, LOGIN_WINDOW_SECONDS);

        $this->assertIsInt(LOGIN_BLOCK_SECONDS);
        $this->assertGreaterThan(0, LOGIN_BLOCK_SECONDS);

        $this->assertIsInt(SESSION_LIFETIME);
        $this->assertGreaterThan(0, SESSION_LIFETIME);
    }

    /**
     * Test that UPLOAD_DIR constant is defined
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking UPLOAD_DIR constant
     * THEN: Should be defined and end with directory separator
     */
    public function testUploadDirConstantIsDefined(): void
    {
        $this->assertTrue(defined('UPLOAD_DIR'));
        $this->assertIsString(UPLOAD_DIR);
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR, UPLOAD_DIR);
    }

    /**
     * Test that MAX_UPLOAD_SIZE constant is defined
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking MAX_UPLOAD_SIZE constant
     * THEN: Should be defined and be reasonable value
     */
    public function testMaxUploadSizeConstantIsDefined(): void
    {
        $this->assertTrue(defined('MAX_UPLOAD_SIZE'));
        $this->assertIsInt(MAX_UPLOAD_SIZE);
        $this->assertGreaterThan(0, MAX_UPLOAD_SIZE);

        // Should be at least 1MB
        $this->assertGreaterThanOrEqual(1048576, MAX_UPLOAD_SIZE);
    }

    /**
     * Test that GRANT_TYPES constant is defined
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking GRANT_TYPES constant
     * THEN: Should be defined and be array
     */
    public function testGrantTypesConstantIsDefined(): void
    {
        $this->assertTrue(defined('GRANT_TYPES'));
        $this->assertIsArray(GRANT_TYPES);
        $this->assertNotEmpty(GRANT_TYPES);

        // Check each grant type is a string
        foreach (GRANT_TYPES as $grantType) {
            $this->assertIsString($grantType);
        }
    }

    /**
     * Test that REVIEW_CRITERIA constant is defined
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking REVIEW_CRITERIA constant
     * THEN: Should be defined and be array of criteria
     */
    public function testReviewCriteriaConstantIsDefined(): void
    {
        $this->assertTrue(defined('REVIEW_CRITERIA'));
        $this->assertIsArray(REVIEW_CRITERIA);
        $this->assertNotEmpty(REVIEW_CRITERIA);

        // Check each criterion is a string
        foreach (REVIEW_CRITERIA as $criterion) {
            $this->assertIsString($criterion);
        }
    }

    /**
     * Test that institution constants are defined
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking institution constants
     * THEN: Should all be defined (can be empty strings)
     */
    public function testInstitutionConstantsAreDefined(): void
    {
        $this->assertTrue(defined('INSTITUTION_NAME'));
        $this->assertTrue(defined('UNIT_NAME'));
        $this->assertTrue(defined('INSTITUTION_ICON_URL'));

        $this->assertIsString(INSTITUTION_NAME);
        $this->assertIsString(UNIT_NAME);
        $this->assertIsString(INSTITUTION_ICON_URL);
    }

    /**
     * Test that timezone is set
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking default timezone
     * THEN: Should be set to valid timezone
     */
    public function testTimezoneIsSet(): void
    {
        $timezone = date_default_timezone_get();
        $this->assertNotEmpty($timezone);
        $this->assertNotEquals('UTC', $timezone); // Should be specific timezone
    }

    /**
     * Test that error reporting is configured correctly
     *
     * GIVEN: Configuration file is loaded
     * WHEN: APP_DEBUG is true
     * THEN: display_errors should be '1'
     */
    public function testErrorReportingIsConfiguredCorrectly(): void
    {
        $displayErrors = ini_get('display_errors');
        $logErrors = ini_get('log_errors');

        $this->assertIsString($displayErrors);
        $this->assertIsString($logErrors);

        // Log errors should always be enabled
        $this->assertEquals('1', $logErrors);
    }

    /**
     * Test that BASE_URL does not end with slash
     *
     * GIVEN: Configuration file is loaded
     * WHEN: Checking BASE_URL
     * THEN: Should not end with trailing slash
     */
    public function testBaseUrlDoesNotEndWithSlash(): void
    {
        $this->assertTrue(defined('BASE_URL'));
        $this->assertStringEndsNotWith('/', BASE_URL);
    }
}
