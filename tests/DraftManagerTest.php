<?php
/**
 * DraftManagerTest - Unit Tests for Draft Auto-Save System
 * SPEC: SPEC-REV-001, Feature 1: Draft Review Auto-Save System
 *
 * Test Coverage Target: >=85%
 * TDD Cycle: RED-GREEN-REFACTOR
 *
 * @category Test
 * @package  GrantReview\Tests
 * @author   TDD Implementer
 * @license  MIT
 * @link     https://github.com/your-org/AsynchronousGrantReview2
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use GrantReview\DraftManager;
use GrantReview\Database;

/**
 * DraftManagerTest Class
 *
 * Test suite for DraftManager class covering all acceptance criteria:
 * - AC-1.1: Auto-save trigger after 30 seconds
 * - AC-1.2: Draft persistence across sessions
 * - AC-1.3: Draft expiration after 7 days
 * - AC-1.4: Draft clear on submit
 * - AC-1.5: Manual draft save
 * - AC-1.6: Draft security (access control)
 */
final class DraftManagerTest extends TestCase
{
    /**
     * @var Database Database connection
     */
    private Database $db;

    /**
     * @var DraftManager Draft manager instance
     */
    private DraftManager $draftManager;

    /**
     * @var int Test reviewer ID
     */
    private int $reviewerId;

    /**
     * @var int Test application ID
     */
    private int $applicationId;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        // Initialize database connection
        $this->db = Database::getInstance();

        // Create DraftManager instance
        $this->draftManager = new DraftManager($this->db);

        // Create test reviewer
        $this->db->query(
            "INSERT INTO users (username, password_hash, full_name, email, role)
             VALUES ('test_reviewer_draft', '$2y$10$test', 'Test Reviewer', 'test@test.com', 'reviewer')
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );
        $this->reviewerId = (int) $this->db->lastInsertId();

        // Create test application
        $this->db->query(
            "INSERT INTO applications (applicant_name, grant_id, application_title, grant_type, grant_type_id)
             VALUES ('Test Applicant', 'TEST-001', 'Test Application', 'TRANSCEND Pilot', 1)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );
        $this->applicationId = (int) $this->db->lastInsertId();

        // Clean up any existing drafts
        $this->db->query(
            "DELETE FROM review_drafts
             WHERE reviewer_id = ? AND application_id = ?",
            [$this->reviewerId, $this->applicationId]
        );
    }

    /**
     * Tear down test fixtures
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test data
        $this->db->query(
            "DELETE FROM review_drafts
             WHERE reviewer_id = ? OR application_id = ?",
            [$this->reviewerId, $this->applicationId]
        );
    }

    /**
     * Test: Save draft successfully (RED phase - expected to fail initially)
     *
     * AC-1.1: Auto-save trigger saves form state to review_drafts table
     *
     * @return void
     */
    public function testSaveDraftCreatesRecordInDatabase(): void
    {
        // Arrange
        $formData = [
            'overall_impact_score' => 3,
            'overall_impact_explanation' => 'Good impact',
            'approach_score' => 2,
            'approach_strengths' => 'Rigorous methodology',
        ];

        // Act
        $result = $this->draftManager->saveDraft(
            $this->reviewerId,
            $this->applicationId,
            $formData
        );

        // Assert
        $this->assertTrue($result, 'Draft save should return true on success');

        // Verify draft was saved to database
        $draft = $this->draftManager->loadDraft($this->reviewerId, $this->applicationId);
        $this->assertNotNull($draft, 'Draft should exist in database');
        $this->assertIsArray($draft, 'Draft should be returned as array');
        $this->assertEquals($formData['overall_impact_score'], $draft['overall_impact_score']);
    }

    /**
     * Test: Load draft returns correct data
     *
     * AC-1.2: Draft restoration returns saved form state
     *
     * @return void
     */
    public function testLoadDraftReturnsSavedFormData(): void
    {
        // Arrange
        $formData = [
            'overall_impact_score' => 2,
            'significance_score' => 4,
            'significance_strengths' => 'Addresses critical gap',
        ];
        $this->draftManager->saveDraft($this->reviewerId, $this->applicationId, $formData);

        // Act
        $draft = $this->draftManager->loadDraft($this->reviewerId, $this->applicationId);

        // Assert
        $this->assertNotNull($draft, 'Draft should be loadable');
        $this->assertEquals($formData['overall_impact_score'], $draft['overall_impact_score']);
        $this->assertEquals($formData['significance_score'], $draft['significance_score']);
        $this->assertEquals($formData['significance_strengths'], $draft['significance_strengths']);
    }

    /**
     * Test: Load non-existent draft returns null
     *
     * @return void
     */
    public function testLoadNonExistentDraftReturnsNull(): void
    {
        // Act
        $draft = $this->draftManager->loadDraft($this->reviewerId, 99999);

        // Assert
        $this->assertNull($draft, 'Non-existent draft should return null');
    }

    /**
     * Test: Delete draft removes record
     *
     * AC-1.4: Draft clear on submit removes from database
     *
     * @return void
     */
    public function testDeleteDraftRemovesRecordFromDatabase(): void
    {
        // Arrange
        $formData = ['test_field' => 'test_value'];
        $this->draftManager->saveDraft($this->reviewerId, $this->applicationId, $formData);

        // Act
        $result = $this->draftManager->deleteDraft($this->reviewerId, $this->applicationId);

        // Assert
        $this->assertTrue($result, 'Delete should return true on success');
        $draft = $this->draftManager->loadDraft($this->reviewerId, $this->applicationId);
        $this->assertNull($draft, 'Deleted draft should not exist');
    }

    /**
     * Test: Draft expiration after 7 days
     *
     * AC-1.3: Drafts expire and are auto-deleted after 7 days
     *
     * @return void
     */
    public function testExpiredDraftsAreNotLoaded(): void
    {
        // Arrange - Create an expired draft
        $formData = ['expired_field' => 'expired_value'];
        $this->draftManager->saveDraft($this->reviewerId, $this->applicationId, $formData);

        // Manually set expires_at to past
        $this->db->query(
            "UPDATE review_drafts SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY)
             WHERE reviewer_id = ? AND application_id = ?",
            [$this->reviewerId, $this->applicationId]
        );

        // Act
        $draft = $this->draftManager->loadDraft($this->reviewerId, $this->applicationId);

        // Assert
        $this->assertNull($draft, 'Expired draft should not be loaded');
    }

    /**
     * Test: Draft security - reviewer cannot access another reviewer's draft
     *
     * AC-1.6: Draft access control prevents unauthorized access
     *
     * @return void
     */
    public function testReviewerCannotAccessAnotherReviewersDraft(): void
    {
        // Arrange - Create another reviewer
        $this->db->query(
            "INSERT INTO users (username, password_hash, full_name, email, role)
             VALUES ('other_reviewer', '$2y$10$test', 'Other Reviewer', 'other@test.com', 'reviewer')
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );
        $otherReviewerId = (int) $this->db->lastInsertId();

        // Create draft for original reviewer
        $formData = ['private_data' => 'sensitive'];
        $this->draftManager->saveDraft($this->reviewerId, $this->applicationId, $formData);

        // Act - Try to access as other reviewer
        $draft = $this->draftManager->loadDraft($otherReviewerId, $this->applicationId);

        // Assert
        $this->assertNull($draft, 'Other reviewer should not access draft');
    }

    /**
     * Test: Update existing draft (same reviewer and application)
     *
     * @return void
     */
    public function testUpdateExistingDraftReplacesOldData(): void
    {
        // Arrange
        $originalData = ['version' => 1, 'content' => 'original'];
        $this->draftManager->saveDraft($this->reviewerId, $this->applicationId, $originalData);

        // Act
        $updatedData = ['version' => 2, 'content' => 'updated'];
        $this->draftManager->saveDraft($this->reviewerId, $this->applicationId, $updatedData);

        // Assert
        $draft = $this->draftManager->loadDraft($this->reviewerId, $this->applicationId);
        $this->assertEquals($updatedData['version'], $draft['version']);
        $this->assertEquals($updatedData['content'], $draft['content']);
    }

    /**
     * Test: Draft data sanitization prevents XSS
     *
     * Security: Input sanitization before storage
     *
     * @return void
     */
    public function testDraftDataIsSanitizedBeforeStorage(): void
    {
        // Arrange - Malicious input
        $maliciousData = [
            'content' => '<script>alert("XSS")</script>',
            'safe_field' => 'Safe content',
        ];

        // Act
        $this->draftManager->saveDraft($this->reviewerId, $this->applicationId, $maliciousData);
        $draft = $this->draftManager->loadDraft($this->reviewerId, $this->applicationId);

        // Assert - Script tags should be escaped
        $this->assertStringNotContainsString('<script>', $draft['content']);
        $this->assertStringContainsString('Safe content', $draft['safe_field']);
    }

    /**
     * Test: Clean up expired drafts
     *
     * AC-1.3: Automatic cleanup of expired drafts
     *
     * @return void
     */
    public function testCleanupExpiredDraftsRemovesOldRecords(): void
    {
        // Arrange - Create expired drafts
        $this->db->query(
            "INSERT INTO review_drafts (reviewer_id, application_id, form_data, expires_at)
             VALUES (?, ?, '{\"test\": \"data\"}', DATE_SUB(NOW(), INTERVAL 2 DAY))",
            [$this->reviewerId, $this->applicationId]
        );

        // Act
        $deletedCount = $this->draftManager->cleanupExpiredDrafts();

        // Assert
        $this->assertGreaterThan(0, $deletedCount, 'Expired drafts should be deleted');
        $draft = $this->draftManager->loadDraft($this->reviewerId, $this->applicationId);
        $this->assertNull($draft, 'Expired draft should not exist after cleanup');
    }

    /**
     * Test: Get all drafts for reviewer
     *
     * @return void
     */
    public function testGetAllDraftsForReviewerReturnsCorrectList(): void
    {
        // Arrange - Create multiple applications
        $app1 = $this->applicationId;
        $this->db->query(
            "INSERT INTO applications (applicant_name, grant_id, application_title, grant_type)
             VALUES ('Test Applicant 2', 'TEST-002', 'Test Application 2', 'TRANSCEND Pilot')"
        );
        $app2 = (int) $this->db->lastInsertId();

        // Create drafts for both applications
        $this->draftManager->saveDraft($this->reviewerId, $app1, ['app' => 1]);
        $this->draftManager->saveDraft($this->reviewerId, $app2, ['app' => 2]);

        // Act
        $drafts = $this->draftManager->getAllDraftsForReviewer($this->reviewerId);

        // Assert
        $this->assertCount(2, $drafts, 'Should retrieve 2 drafts');
    }

    /**
     * Test: Draft timestamp tracking
     *
     * @return void
     */
    public function testDraftTimestampIsRecorded(): void
    {
        // Arrange
        $this->draftManager->saveDraft($this->reviewerId, $this->applicationId, ['test' => 'data']);

        // Act
        $drafts = $this->draftManager->getAllDraftsForReviewer($this->reviewerId);

        // Assert - verify saved_at is present and is a valid timestamp
        $this->assertNotEmpty($drafts);
        $this->assertArrayHasKey('saved_at', $drafts[0]);
        $this->assertNotEmpty($drafts[0]['saved_at']);

        // Verify saved_at is a valid datetime within 60 seconds of DB server time
        $dbNow = $this->db->query("SELECT NOW() as now")->fetch(\PDO::FETCH_ASSOC);
        $dbTimestamp = strtotime($dbNow['now']);
        $draftTimestamp = strtotime($drafts[0]['saved_at']);
        $this->assertLessThanOrEqual(60, abs($dbTimestamp - $draftTimestamp));
    }
}
