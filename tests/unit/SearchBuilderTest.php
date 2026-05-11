<?php
/**
 * Unit Test: SearchBuilderTest
 * SPEC: SPEC-ADM-001 Admin Panel Enhancements
 * Feature: Advanced Search and Filtering
 * Test Coverage: Query construction, parameter binding, SQL injection prevention
 * Created: 2025-01-04
 */

use PHPUnit\Framework\TestCase;

class SearchBuilderTest extends TestCase
{
    private $db;
    private $builder;

    protected function setUp(): void
    {
        // Mock database connection
        $this->db = $this->createMock(PDO::class);
        $this->builder = new SearchBuilder('applications', $this->db);
    }

    /**
     * Test: Constructor initializes with correct table
     * GIVEN: SearchBuilder instantiated with 'applications' table
     * WHEN: Constructor is called
     * THEN: Builder should set table and initialize empty filters
     */
    public function testConstructorInitializesWithCorrectTable()
    {
        $this->assertEquals('applications', $this->builder->getTable());
        $this->assertEquals([], $this->builder->getFilters());
    }

    /**
     * Test: Add single text filter
     * GIVEN: SearchBuilder instance
     * WHEN: Text filter is added
     * THEN: Filter should be stored and accessible
     */
    public function testAddSingleTextFilter()
    {
        $this->builder->addFilter('applicant_name', 'John Doe', 'text');
        $filters = $this->builder->getFilters();

        $this->assertCount(1, $filters);
        $this->assertEquals('applicant_name', $filters[0]['field']);
        $this->assertEquals('John Doe', $filters[0]['value']);
        $this->assertEquals('text', $filters[0]['type']);
    }

    /**
     * Test: Add multiple filters with AND logic
     * GIVEN: SearchBuilder instance
     * WHEN: Multiple filters are added
     * THEN: All filters should be stored with AND logic
     */
    public function testAddMultipleFiltersWithAndLogic()
    {
        $this->builder->addFilter('status', 'completed', 'select');
        $this->builder->addFilter('grant_type_id', 1, 'select');
        $filters = $this->builder->getFilters();

        $this->assertCount(2, $filters);
        $this->assertEquals('status', $filters[0]['field']);
        $this->assertEquals('grant_type_id', $filters[1]['field']);
    }

    /**
     * Test: Build query with single filter
     * GIVEN: SearchBuilder with one filter
     * WHEN: Query is built
     * THEN: SQL should contain WHERE clause with filter
     */
    public function testBuildQueryWithSingleFilter()
    {
        $this->builder->addFilter('status', 'completed', 'select');
        $query = $this->builder->buildQuery();

        $this->assertStringContainsString('WHERE', $query);
        $this->assertStringContainsString('status = :status', $query);
    }

    /**
     * Test: Build query with multiple filters
     * GIVEN: SearchBuilder with multiple filters
     * WHEN: Query is built
     * THEN: SQL should contain WHERE clause with AND conditions
     */
    public function testBuildQueryWithMultipleFilters()
    {
        $this->builder->addFilter('status', 'completed', 'select');
        $this->builder->addFilter('grant_type_id', 1, 'select');
        $query = $this->builder->buildQuery();

        $this->assertStringContainsString('WHERE', $query);
        $this->assertStringContainsString('AND', $query);
        $this->assertStringContainsString('status = :status', $query);
        $this->assertStringContainsString('grant_type_id = :grant_type_id', $query);
    }

    /**
     * Test: Build query with date range filter
     * GIVEN: SearchBuilder with date range filter
     * WHEN: Query is built
     * THEN: SQL should contain BETWEEN clause
     */
    public function testBuildQueryWithDateRangeFilter()
    {
        $this->builder->addFilter('created_at', ['2025-01-01', '2025-12-31'], 'date_range');
        $query = $this->builder->buildQuery();

        $this->assertStringContainsString('BETWEEN', $query);
        $this->assertStringContainsString('BETWEEN', $query);
        $this->assertStringContainsString(':created_at_start_', $query);
        $this->assertStringContainsString(':created_at_end_', $query);
    }

    /**
     * Test: Build query with text search (LIKE)
     * GIVEN: SearchBuilder with text search filter
     * WHEN: Query is built
     * THEN: SQL should contain LIKE with wildcards
     */
    public function testBuildQueryWithTextSearch()
    {
        $this->builder->addFilter('applicant_name', 'John', 'text');
        $query = $this->builder->buildQuery();

        $this->assertStringContainsString('LIKE', $query);
        $this->assertStringContainsString('applicant_name LIKE :applicant_name_', $query);
    }

    /**
     * Test: SQL injection prevention in text search
     * GIVEN: SearchBuilder with SQL injection payload
     * WHEN: Query is built
     * THEN: Special characters should be escaped, not executed
     */
    public function testSqlInjectionPreventionInTextSearch()
    {
        $this->builder->addFilter('applicant_name', "' OR '1'='1", 'text');
        $query = $this->builder->buildQuery();
        $params = $this->builder->getParameters();

        // Should use parameter binding, not direct string interpolation
        $this->assertStringContainsString(':applicant_name_', $query);
        $this->assertStringNotContainsString("' OR '1'='1", $query);
        // Parameter value preserved for safe binding — find the key with prefix
        $paramKey = array_key_first(array_filter($params, fn($k) => str_starts_with($k, ':applicant_name_'), ARRAY_FILTER_USE_KEY));
        $this->assertNotNull($paramKey);
        $this->assertEquals("%' OR '1'='1%", $params[$paramKey]);
    }

    /**
     * Test: Build query with multi-select filter
     * GIVEN: SearchBuilder with multi-select filter
     * WHEN: Query is built
     * THEN: SQL should contain IN clause
     */
    public function testBuildQueryWithMultiSelectFilter()
    {
        $this->builder->addFilter('status', ['completed', 'pending'], 'multi_select');
        $query = $this->builder->buildQuery();

        $this->assertStringContainsString('IN', $query);
        $this->assertStringContainsString('status IN (:status_0, :status_1)', $query);
    }

    /**
     * Test: Build query with pagination
     * GIVEN: SearchBuilder with pagination parameters
     * WHEN: Query is built
     * THEN: SQL should contain LIMIT and OFFSET
     */
    public function testBuildQueryWithPagination()
    {
        $this->builder->setPagination(1, 20);
        $query = $this->builder->buildQuery();

        $this->assertStringContainsString('LIMIT', $query);
        $this->assertStringContainsString('OFFSET', $query);
        $this->assertStringContainsString('LIMIT 20 OFFSET 0', $query);
    }

    /**
     * Test: Build query with sorting
     * GIVEN: SearchBuilder with sort parameters
     * WHEN: Query is built
     * THEN: SQL should contain ORDER BY clause
     */
    public function testBuildQueryWithSorting()
    {
        $this->builder->setSort('created_at', 'DESC');
        $query = $this->builder->buildQuery();

        $this->assertStringContainsString('ORDER BY', $query);
        $this->assertStringContainsString('created_at DESC', $query);
    }

    /**
     * Test: Build query with no filters
     * GIVEN: SearchBuilder with no filters
     * WHEN: Query is built
     * THEN: SQL should not contain WHERE clause
     */
    public function testBuildQueryWithNoFilters()
    {
        $query = $this->builder->buildQuery();

        $this->assertStringNotContainsString('WHERE', $query);
        $this->assertStringContainsString('SELECT', $query);
        $this->assertStringContainsString('FROM applications', $query);
    }

    /**
     * Test: Get parameters for prepared statement
     * GIVEN: SearchBuilder with filters
     * WHEN: Parameters are retrieved
     * THEN: Should return array of parameter bindings
     */
    public function testGetParametersForPreparedStatement()
    {
        $this->builder->addFilter('status', 'completed', 'select');
        $this->builder->addFilter('grant_type_id', 1, 'select');
        $params = $this->builder->getParameters();

        $this->assertIsArray($params);
        // Parameter names include counter suffix for collision prevention
        $statusKey = array_key_first(array_filter($params, fn($k) => str_starts_with($k, ':status_'), ARRAY_FILTER_USE_KEY));
        $grantKey = array_key_first(array_filter($params, fn($k) => str_starts_with($k, ':grant_type_id_'), ARRAY_FILTER_USE_KEY));
        $this->assertNotNull($statusKey);
        $this->assertNotNull($grantKey);
        $this->assertEquals('completed', $params[$statusKey]);
        $this->assertEquals(1, $params[$grantKey]);
    }

    /**
     * Test: Execute search returns results
     * GIVEN: SearchBuilder with valid filters and mock database
     * WHEN: Search is executed
     * THEN: Should return array of results
     */
    public function testExecuteSearchReturnsResults()
    {
        $this->builder->addFilter('status', 'completed', 'select');

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([
            ['id' => 1, 'applicant_name' => 'John Doe'],
            ['id' => 2, 'applicant_name' => 'Jane Smith']
        ]);

        $this->db->method('prepare')->willReturn($stmt);
        $stmt->method('execute')->willReturn(true);

        $results = $this->builder->execute();

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertEquals('John Doe', $results[0]['applicant_name']);
    }

    /**
     * Test: Get count query for pagination
     * GIVEN: SearchBuilder with filters
     * WHEN: Count query is built
     * THEN: Should return COUNT(*) query with same filters
     */
    public function testGetCountQueryForPagination()
    {
        $this->builder->addFilter('status', 'completed', 'select');
        $countQuery = $this->builder->buildCountQuery();

        $this->assertStringContainsString('SELECT COUNT(*)', $countQuery);
        $this->assertStringContainsString('FROM applications', $countQuery);
        $this->assertStringContainsString('WHERE', $countQuery);
    }

    /**
     * Test: Clear all filters
     * GIVEN: SearchBuilder with filters
     * WHEN: Filters are cleared
     * THEN: Should have empty filter array
     */
    public function testClearAllFilters()
    {
        $this->builder->addFilter('status', 'completed', 'select');
        $this->builder->addFilter('grant_type_id', 1, 'select');
        $this->builder->clearFilters();

        $this->assertEquals([], $this->builder->getFilters());
    }
}
