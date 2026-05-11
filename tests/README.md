# Testing Infrastructure Guide

This document describes the testing infrastructure for the Asynchronous Grant Review System.

## Overview

The testing framework implements comprehensive test coverage including:
- **Unit Tests**: Test individual classes and functions in isolation
- **Integration Tests**: Test component interactions and database operations
- **Security Tests**: Validate security controls and vulnerability prevention
- **E2E Tests**: End-to-end testing with Playwright (planned)

## Requirements

- PHP 8.0 or higher
- Composer
- MySQL/MariaDB (for test database)
- PHPUnit 10.1+

## Installation

### 1. Install Dependencies

```bash
composer install
```

### 2. Set Up Test Database

Create a test database separate from production:

```sql
CREATE DATABASE grant_review_test;
GRANT ALL PRIVILEGES ON grant_review_test.* TO 'your_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Configure Environment

Set environment variables in `phpunit.xml` or `.env`:

```bash
export DB_HOST=localhost
export DB_NAME=grant_review_test
export DB_USER=your_user
export DB_PASS=your_password
export APP_ENV=testing
export APP_DEBUG=true
```

### 4. Load Test Schema

```bash
mysql -u your_user -p grant_review_test < tests/fixtures/db/schema.sql
```

## Running Tests

### Run All Tests

```bash
# Using Composer
composer test

# Using PHPUnit directly
./vendor/bin/phpunit
```

### Run Specific Test Suites

```bash
# Unit tests only
composer test:unit

# Integration tests only
composer test:integration

# Security tests only
composer test:security
```

### Run Specific Test File

```bash
./vendor/bin/phpunit tests/unit/AuthTest.php
```

### Run with Coverage Report

```bash
# HTML coverage report
composer test:coverage

# Text coverage summary
composer test:coverage-text
```

## Test Structure

```
tests/
├── bootstrap.php              # PHPUnit bootstrap file
├── TestCase.php               # Base test class with common functionality
├── unit/                      # Unit tests
│   ├── AuthTest.php          # Authentication class tests
│   ├── DatabaseTest.php      # Database singleton tests
│   ├── FunctionsTest.php     # Utility function tests
│   ├── ConfigTest.php        # Configuration loading tests
│   └── SampleTest.php        # Sample test (verifies setup)
├── integration/               # Integration tests (planned)
├── security/                  # Security tests (planned)
├── fixtures/                  # Test fixtures
│   ├── db/                   # Database fixtures
│   │   ├── schema.sql        # Database schema
│   │   ├── users.sql         # Sample users
│   │   ├── applications.sql  # Sample applications
│   │   └── reviews.sql       # Sample reviews
│   └── documents/            # Document fixtures for parser testing
└── screenshots/              # E2E test screenshots (planned)
```

## Writing Tests

### Unit Test Example

```php
<?php

namespace GrantReview\Tests\Unit;

use GrantReview\Tests\TestCase;

class MyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Set up test data
    }

    protected function tearDown(): void
    {
        // Clean up
        parent::tearDown();
    }

    public function testSomething(): void
    {
        // Arrange
        $input = 'test';

        // Act
        $result = someFunction($input);

        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Test Naming Conventions

- Test files: `<ClassName>Test.php` in `tests/unit/`
- Test classes: `<ClassName>Test` in `GrantReview\Tests\Unit` namespace
- Test methods: `test_<methodName>_<scenario>()`
- Data providers: `<methodName>DataProvider()`

### Using Test Helpers

The base `TestCase` class provides useful helpers:

```php
// Create test user
$userId = $this->createTestUser([
    'username' => 'testuser',
    'password' => 'Password123!',
    'role' => 'admin'
]);

// Create test application
$appId = $this->createTestApplication([
    'title' => 'Test Application',
    'grant_type' => 'pilot'
]);

// Load database fixture
$this->loadDbFixture('/path/to/fixture.sql');

// Session helpers
$this->setSession('key', 'value');
$value = $this->getSession('key');
$this->assertSessionHas('key');
$this->assertSessionMissing('key');

// Bcrypt hash validation
$this->assertValidBcryptHash($hash);
```

## Test Isolation

All tests are isolated using:

1. **Database Transactions**: Each test runs in a transaction that is rolled back after completion
2. **Session Cleanup**: Sessions are cleaned between tests
3. **Independent Execution**: Tests can run in any order without interference

## Coverage Requirements

- **Overall Coverage**: 85% minimum
- **Business Logic Coverage**: 90% minimum
- **Security-Critical Code**: 95% minimum

View coverage report:

```bash
# Generate HTML report
composer test:coverage

# Open report
open .moai/reports/coverage/index.html  # macOS
xdg-open .moai/reports/coverage/index.html  # Linux
```

## Code Quality

### Linting

```bash
# Check PSR-12 compliance
composer lint

# Auto-fix linting issues
composer lint-fix
```

## TDD Workflow

Follow the RED-GREEN-REFACTOR cycle:

1. **RED**: Write a failing test that defines expected behavior
2. **GREEN**: Write minimal code to make the test pass
3. **REFACTOR**: Improve code quality while keeping tests passing

Example:

```php
// RED: Write failing test
public function testUserPasswordIsHashed(): void
{
    $user = new User('username', 'plain_password');
    $hash = $user->getPasswordHash();

    $this->assertValidBcryptHash($hash);
    $this->assertTrue(password_verify('plain_password', $hash));
}

// GREEN: Write minimal implementation
class User
{
    public function __construct($username, $password)
    {
        $this->passwordHash = password_hash($password, PASSWORD_DEFAULT);
    }
}

// REFACTOR: Improve code quality
// Extract password hashing to separate service
// Add password validation
// Improve error handling
```

## Troubleshooting

### Database Connection Errors

**Problem**: Tests fail with "Cannot connect to test database"

**Solution**:
1. Verify test database exists: `mysql -e "SHOW DATABASES LIKE 'grant_review_test'"`
2. Check credentials in `phpunit.xml`
3. Ensure MySQL/MariaDB is running

### Session Errors

**Problem**: "Session not initialized" errors

**Solution**: Tests automatically start session in `bootstrap.php`. If using custom session handling, ensure session is started before tests run.

### Permission Errors

**Problem**: Cannot write to log/coverage directories

**Solution**: Ensure directories are writable:
```bash
chmod -R 755 .moai/
chmod -R 755 tests/screenshots/
```

## Continuous Integration

### Pre-Commit Hook (Planned)

```bash
# Run fast tests before commit
git commit  # Automatically runs pre-commit hook
```

### Pull Request Validation (Planned)

Tests run automatically on PR creation:
- Full unit test suite
- Integration test suite
- Security smoke tests
- Coverage validation

## Best Practices

1. **Test Isolation**: Each test should be independent
2. **Descriptive Names**: Test method names should describe what is being tested
3. **One Assertion Per Test**: Focus on single behavior
4. **Arrange-Act-Assert**: Structure tests clearly
5. **Mock External Dependencies**: Use mocks for external services
6. **Test Edge Cases**: Test boundary conditions and error cases
7. **Keep Tests Fast**: Unit tests should run in under 2 minutes
8. **Maintain Test Coverage**: Don't let coverage drop below 85%

## Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [PSR-12 Coding Standard](https://www.php-fig.org/psr/psr-12/)
- [TDD Best Practices](https://martinfowler.com/bliki/TestDrivenDevelopment.html)

## Support

For issues or questions:
1. Check this documentation
2. Review test files for examples
3. Consult PHPUnit documentation
4. Contact development team
