<?php
declare(strict_types=1);

/**
 * Integration Tests for SearchBuilder with Real Database
 * Description: Tests SearchBuilder filter, sort, and pagination against real
 *              database rows to verify multi-class workflow interactions.
 */

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/SearchBuilder.php';

use GrantReview\Tests\TestCase;

class SearchIntegrationTest extends TestCase
{
    /**
     * Test: Text filter on applicant_name returns only matching rows
     *
     * Creates several applications with distinct applicant names, then
     * uses a text filter to confirm only matching records are returned.
     */
    public function testTextFilterMatchesCorrectRows(): void
    {
        $this->createTestApplication(['applicant_name' => 'Alice Johnson']);
        $this->createTestApplication(['applicant_name' => 'Bob Smith']);
        $this->createTestApplication(['applicant_name' => 'Alice Wong']);

        $builder = new SearchBuilder('applications', $this->db);
        $builder->addFilter('applicant_name', 'Alice', 'text');
        $results = $builder->execute();

        foreach ($results as $row) {
            $this->assertStringContainsStringIgnoringCase(
                'Alice',
                $row['applicant_name'],
                'Text filter should return only rows with "Alice" in applicant_name'
            );
        }

        // We created 2 "Alice" applications; there may be others in the DB from other
        // tests, but the transaction ensures only this test's rows are visible.
        $this->assertGreaterThanOrEqual(2, count($results));
    }

    /**
     * Test: Select filter on status returns only exact matches
     *
     * Creates applications with three different statuses and verifies
     * that a select filter returns only the requested status.
     */
    public function testSelectFilterReturnsExactStatusMatch(): void
    {
        $this->createTestApplication(['status' => 'pending']);
        $this->createTestApplication(['status' => 'in_review']);
        $this->createTestApplication(['status' => 'completed']);

        $builder = new SearchBuilder('applications', $this->db);
        $builder->addFilter('status', 'in_review', 'select');
        $results = $builder->execute();

        $this->assertNotEmpty($results, 'Should find at least one application with status in_review');
        foreach ($results as $row) {
            $this->assertEquals('in_review', $row['status']);
        }
    }

    /**
     * Test: getCount returns accurate total matching record count
     *
     * Sets page size large enough to retrieve all rows within the transaction,
     * then verifies getCount matches the number of rows returned by execute().
     * Only the rows inserted in this test are relevant because the transaction
     * keeps them isolated from rows committed by other tests.
     */
    public function testGetCountMatchesExecuteResultCount(): void
    {
        // Insert a unique applicant_name prefix to isolate exactly these rows
        $prefix = 'CountTest_' . uniqid() . '_';

        $this->createTestApplication(['status' => 'pending', 'applicant_name' => $prefix . 'A']);
        $this->createTestApplication(['status' => 'pending', 'applicant_name' => $prefix . 'B']);
        $this->createTestApplication(['status' => 'completed', 'applicant_name' => $prefix . 'C']);

        $builder = new SearchBuilder('applications', $this->db);
        $builder->addFilter('applicant_name', $prefix, 'text');
        $builder->addFilter('status', 'pending', 'select');
        // Use a large page size so all matching rows fit in one page
        $builder->setPagination(1, 1000);

        $count   = $builder->getCount();
        $results = $builder->execute();

        $this->assertCount(
            $count,
            $results,
            'getCount() must equal the number of rows returned by execute() for the same filters'
        );
        $this->assertEquals(2, $count, 'Exactly 2 pending applications with the unique prefix should be found');
    }

    /**
     * Test: Pagination limits results to the requested page size
     *
     * Creates 5 applications, then requests page 1 with pageSize 2 and
     * verifies that at most 2 rows are returned.
     */
    public function testPaginationLimitsResultsToPageSize(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createTestApplication(['status' => 'pending']);
        }

        $builder = new SearchBuilder('applications', $this->db);
        $builder->addFilter('status', 'pending', 'select');
        $builder->setPagination(1, 2);

        $results = $builder->execute();

        $this->assertLessThanOrEqual(2, count($results), 'Pagination should limit results to 2 per page');

        $paginationInfo = $builder->getPaginationInfo();
        $this->assertEquals(1, $paginationInfo['page']);
        $this->assertEquals(2, $paginationInfo['pageSize']);
        $this->assertGreaterThanOrEqual(5, $paginationInfo['total'], 'Total count should include all 5 created rows');
    }

    /**
     * Test: Multi-select filter returns results matching any of the given values
     *
     * Creates applications with three different statuses, then uses a
     * multi_select filter to retrieve two of them.
     */
    public function testMultiSelectFilterReturnsMatchingRows(): void
    {
        $this->createTestApplication(['status' => 'pending']);
        $this->createTestApplication(['status' => 'completed']);
        $this->createTestApplication(['status' => 'in_review']);

        $builder = new SearchBuilder('applications', $this->db);
        $builder->addFilter('status', ['pending', 'completed'], 'multi_select');
        $results = $builder->execute();

        $this->assertNotEmpty($results, 'Multi-select should return at least 2 rows');
        foreach ($results as $row) {
            $this->assertContains(
                $row['status'],
                ['pending', 'completed'],
                'Multi-select filter should not return rows with status "in_review"'
            );
        }
    }
}
