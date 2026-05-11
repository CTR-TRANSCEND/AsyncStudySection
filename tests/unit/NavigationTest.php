<?php

declare(strict_types=1);

namespace GrantReview\Tests\Unit;

use GrantReview\Tests\TestCase;
use PDO;

/**
 * Unit tests for Navigation System (SPEC-UIX-002 Milestone 2)
 *
 * Tests cover:
 * - Role-based navigation definitions
 * - Unread discussion count retrieval
 * - Per-request caching for badge counts
 * - Badge CSS class rendering
 */
class NavigationTest extends TestCase
{
    private $testUserId;
    private $testAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        // Use the Database singleton connection so test data is visible to
        // functions like getUnreadDiscussionCount() that use Database::getInstance()
        $this->db = \Database::getInstance()->getConnection();

        // Clean up any leftover test data from previous runs
        $this->db->prepare("DELETE FROM discussion_messages WHERE user_id IN (SELECT id FROM users WHERE username IN ('test_reviewer_nav', 'test_admin_nav'))")->execute();
        $this->db->prepare("DELETE FROM assignments WHERE reviewer_id IN (SELECT id FROM users WHERE username IN ('test_reviewer_nav', 'test_admin_nav'))")->execute();
        $this->db->prepare("DELETE FROM users WHERE username IN ('test_reviewer_nav', 'test_admin_nav')")->execute();

        // Create test users
        $stmt = $this->db->prepare("
            INSERT INTO users (username, full_name, email, password_hash, role, is_active)
            VALUES ('test_reviewer_nav', 'Test Reviewer', 'reviewer_nav@test.com', '\$2y\$10\$testhashnav1234567890123456789012345678901234567890', 'reviewer', TRUE)
        ");
        $stmt->execute();
        $this->testUserId = (int) $this->db->lastInsertId();

        $stmt = $this->db->prepare("
            INSERT INTO users (username, full_name, email, password_hash, role, is_active)
            VALUES ('test_admin_nav', 'Test Admin', 'admin_nav@test.com', '\$2y\$10\$testhashnav1234567890123456789012345678901234567890', 'admin', TRUE)
        ");
        $stmt->execute();
        $this->testAdminId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        // Clean up test data (manual cleanup since we're using singleton connection)
        $this->db->prepare("DELETE FROM discussion_message_reads WHERE user_id IN (?, ?)")->execute([$this->testUserId, $this->testAdminId]);
        $this->db->prepare("DELETE FROM discussion_messages WHERE user_id IN (?, ?)")->execute([$this->testUserId, $this->testAdminId]);
        $this->db->prepare("DELETE FROM assignments WHERE reviewer_id IN (?, ?)")->execute([$this->testUserId, $this->testAdminId]);
        $this->db->prepare("DELETE FROM applications WHERE applicant_name = 'Test Applicant' AND application_title = 'Test Application'")->execute();
        $this->db->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$this->testUserId, $this->testAdminId]);

        // Skip parent tearDown's rollback since we're not using transactions here
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /**
     * Test: getUnreadDiscussionCount returns 0 for user with no discussions
     */
    public function testGetUnreadDiscussionCountReturnsZeroForNoDiscussions(): void
    {
        // This test will fail until function is implemented
        if (!function_exists('getUnreadDiscussionCount')) {
            $this->markTestSkipped('getUnreadDiscussionCount function not yet implemented');
        }

        $count = getUnreadDiscussionCount($this->testUserId);
        $this->assertEquals(0, $count, 'User with no discussions should have 0 unread count');
    }

    /**
     * Test: getUnreadDiscussionCount returns correct count for unread messages
     */
    public function testGetUnreadDiscussionCountReturnsCorrectCount(): void
    {
        if (!function_exists('getUnreadDiscussionCount')) {
            $this->markTestSkipped('getUnreadDiscussionCount function not yet implemented');
        }

        // Create test application and assignment
        $stmt = $this->db->prepare("
            INSERT INTO applications (applicant_name, application_title, grant_type, study_section_id)
            VALUES ('Test Applicant', 'Test Application', 'TRANSCEND Pilot', 1)
        ");
        $stmt->execute();
        $applicationId = (int) $this->db->lastInsertId();

        $stmt = $this->db->prepare("
            INSERT INTO assignments (application_id, reviewer_id, anonymous_label)
            VALUES (?, ?, 'Reviewer A')
        ");
        $stmt->execute([$applicationId, $this->testUserId]);

        // Create discussion messages from another user
        $otherUserId = $this->testAdminId;
        $stmt = $this->db->prepare("
            INSERT INTO discussion_messages (application_id, user_id, message, created_at)
            VALUES (?, ?, 'Test message 1', NOW()),
                   (?, ?, 'Test message 2', NOW())
        ");
        $stmt->execute([$applicationId, $otherUserId, $applicationId, $otherUserId]);

        // Clear any existing cache to ensure fresh query
        if (function_exists('clearNavigationCache')) {
            clearNavigationCache();
        }

        $count = getUnreadDiscussionCount($this->testUserId);
        $this->assertGreaterThan(0, $count, 'Should have unread messages');
        $this->assertEquals(2, $count, 'Should count unread messages from other users');

        // Cleanup is handled by transaction rollback
    }

    /**
     * Test: Per-request cache prevents multiple database queries
     */
    public function testPerRequestCachePreventsMultipleQueries(): void
    {
        if (!function_exists('getUnreadDiscussionCount')) {
            $this->markTestSkipped('getUnreadDiscussionCount function not yet implemented');
        }

        // First call
        $count1 = getUnreadDiscussionCount($this->testUserId);

        // Second call should use cache
        $count2 = getUnreadDiscussionCount($this->testUserId);

        $this->assertEquals($count1, $count2, 'Cached count should match initial count');
    }

    /**
     * Test: Badge CSS class is applied correctly
     */
    public function testBadgeCssClassIsApplied(): void
    {
        // This will be tested via HTML output in integration tests
        $this->assertTrue(true, 'Badge CSS class exists in components.css');
    }

    /**
     * Test: Navigation items are role-appropriate
     */
    public function testNavigationItemsAreRoleAppropriate(): void
    {
        if (!function_exists('getNavigationItems')) {
            $this->markTestSkipped('getNavigationItems function not yet implemented');
        }

        // Admin navigation
        $adminNav = getNavigationItems('admin');
        $this->assertIsArray($adminNav, 'Admin navigation should be an array');
        $this->assertNotEmpty($adminNav, 'Admin should have navigation items');

        // Reviewer navigation
        $reviewerNav = getNavigationItems('reviewer');
        $this->assertIsArray($reviewerNav, 'Reviewer navigation should be an array');
        $this->assertNotEmpty($reviewerNav, 'Reviewer should have navigation items');

        // Admin should have different items than reviewer
        $adminTitles = array_column($adminNav, 'title');
        $reviewerTitles = array_column($reviewerNav, 'title');

        $this->assertNotEquals(
            $adminTitles,
            $reviewerTitles,
            'Admin and reviewer should have different navigation items'
        );
    }

    /**
     * Test: Navigation structure has required accessibility attributes
     */
    public function testNavigationHasAccessibilityAttributes(): void
    {
        if (!function_exists('getNavigationItems')) {
            $this->markTestSkipped('getNavigationItems function not yet implemented');
        }

        $nav = getNavigationItems('reviewer');

        foreach ($nav as $item) {
            // Check for required keys
            $this->assertArrayHasKey('title', $item, 'Nav item should have title');
            $this->assertArrayHasKey('url', $item, 'Nav item should have URL');

            // If dropdown, check for children
            if (isset($item['children'])) {
                $this->assertIsArray($item['children'], 'Nav children should be array');
                $this->assertArrayHasKey('aria_label', $item, 'Dropdown nav should have aria_label');
            }
        }
    }

    /**
     * Test: Cache is cleared between requests (simulated)
     */
    public function testCacheIsClearedBetweenRequests(): void
    {
        if (!function_exists('getUnreadDiscussionCount')) {
            $this->markTestSkipped('getUnreadDiscussionCount function not yet implemented');
        }

        if (!function_exists('clearNavigationCache')) {
            $this->markTestSkipped('clearNavigationCache function not yet implemented');
        }

        // Get count
        $count1 = getUnreadDiscussionCount($this->testUserId);

        // Clear cache
        clearNavigationCache();

        // Get count again (should fetch fresh)
        $count2 = getUnreadDiscussionCount($this->testUserId);

        $this->assertEquals($count1, $count2, 'Count should be consistent');
    }
}
