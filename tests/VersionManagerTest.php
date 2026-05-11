<?php
/**
 * VersionManagerTest - Unit Tests for Review Version History
 * SPEC: SPEC-REV-001, Feature 3: Review Version History
 *
 * Test Coverage Target: >=85%
 * TDD Cycle: RED-GREEN-REFACTOR
 *
 * @category Test
 * @package  GrantReview\Tests
 * @author   TDD Implementer
 * @license  MIT
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use GrantReview\VersionManager;
use GrantReview\Database;

/**
 * VersionManagerTest Class
 *
 * Test suite for VersionManager class covering all acceptance criteria:
 * - AC-3.1: Version creation on modify
 * - AC-3.2: Version timeline display
 * - AC-3.3: Side-by-side comparison
 * - AC-3.4: Version rollback
 * - AC-3.5: Section data preservation
 * - AC-3.6: Version access control
 */
final class VersionManagerTest extends TestCase
{
    /**
     * @var Database Database connection
     */
    private Database $db;

    /**
     * @var VersionManager Version manager instance
     */
    private VersionManager $versionManager;

    /**
     * @var int Test reviewer ID
     */
    private int $reviewerId;

    /**
     * @var int Test admin ID
     */
    private int $adminId;

    /**
     * @var int Test application ID
     */
    private int $applicationId;

    /**
     * @var int Test review ID
     */
    private int $reviewId;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->versionManager = new VersionManager($this->db);

        // Create test reviewer
        $this->db->query(
            "INSERT INTO users (username, password_hash, full_name, email, role)
             VALUES ('test_reviewer_version', '$2y$10$test', 'Test Reviewer', 'reviewer@test.com', 'reviewer')
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );
        $this->reviewerId = (int) $this->db->lastInsertId();

        // Create test admin
        $this->db->query(
            "INSERT INTO users (username, password_hash, full_name, email, role)
             VALUES ('test_admin_version', '$2y$10$test', 'Test Admin', 'adminv@test.com', 'admin')
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );
        $this->adminId = (int) $this->db->lastInsertId();

        // Create test application
        $this->db->query(
            "INSERT INTO applications (applicant_name, grant_id, application_title, grant_type, grant_type_id)
             VALUES ('Test Applicant Version', 'TEST-V-001', 'Test Application Version', 'TRANSCEND Pilot', 1)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );
        $this->applicationId = (int) $this->db->lastInsertId();

        // Create test review
        $this->db->query(
            "INSERT INTO reviews (application_id, reviewer_id, overall_impact_score, overall_impact_explanation)
             VALUES (?, ?, 3, 'Good impact')
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            [$this->applicationId, $this->reviewerId]
        );
        $this->reviewId = (int) $this->db->lastInsertId();
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
            "DELETE FROM review_versions WHERE review_id = ?",
            [$this->reviewId]
        );
        $this->db->query(
            "DELETE FROM reviews WHERE id = ?",
            [$this->reviewId]
        );
    }

    /**
     * Test: Create version records review state
     *
     * AC-3.1: Version creation on modify
     *
     * @return void
     */
    public function testCreateVersionSavesReviewState(): void
    {
        // Arrange
        $reviewData = [
            'overall_impact_score' => 2,
            'relevance_score' => 3,
            'overall_impact_explanation' => 'Strong impact',
            'relevance_explanation' => 'Highly relevant',
            'budget_acceptable' => true,
            'budget_modifications' => 'No modifications needed',
        ];

        // Act
        $versionId = $this->versionManager->createVersion($this->reviewId, $reviewData, $this->reviewerId);

        // Assert
        $this->assertGreaterThan(0, $versionId, 'Version ID should be positive');

        // Verify version in database
        $version = $this->versionManager->getVersionById($versionId);
        $this->assertNotNull($version);
        $this->assertEquals(1, $version['version_number']);
        $this->assertEquals(2, $version['overall_impact_score']);
    }

    /**
     * Test: Version number increments for each version
     *
     * AC-3.1: Sequential version numbering
     *
     * @return void
     */
    public function testVersionNumbersIncrementSequentially(): void
    {
        // Arrange
        $reviewData1 = ['overall_impact_score' => 1, 'overall_impact_explanation' => 'Version 1'];
        $reviewData2 = ['overall_impact_score' => 2, 'overall_impact_explanation' => 'Version 2'];
        $reviewData3 = ['overall_impact_score' => 3, 'overall_impact_explanation' => 'Version 3'];

        // Act
        $version1Id = $this->versionManager->createVersion($this->reviewId, $reviewData1, $this->reviewerId);
        $version2Id = $this->versionManager->createVersion($this->reviewId, $reviewData2, $this->reviewerId);
        $version3Id = $this->versionManager->createVersion($this->reviewId, $reviewData3, $this->reviewerId);

        // Assert
        $version1 = $this->versionManager->getVersionById($version1Id);
        $version2 = $this->versionManager->getVersionById($version2Id);
        $version3 = $this->versionManager->getVersionById($version3Id);

        $this->assertEquals(1, $version1['version_number']);
        $this->assertEquals(2, $version2['version_number']);
        $this->assertEquals(3, $version3['version_number']);
    }

    /**
     * Test: Get version history timeline
     *
     * AC-3.2: Version timeline display
     *
     * @return void
     */
    public function testGetVersionHistoryReturnsTimeline(): void
    {
        // Arrange
        $reviewData1 = ['overall_impact_score' => 1, 'overall_impact_explanation' => 'First version'];
        $reviewData2 = ['overall_impact_score' => 2, 'overall_impact_explanation' => 'Second version'];
        $this->versionManager->createVersion($this->reviewId, $reviewData1, $this->reviewerId);
        $this->versionManager->createVersion($this->reviewId, $reviewData2, $this->reviewerId);

        // Act
        $history = $this->versionManager->getVersionHistory($this->reviewId);

        // Assert
        $this->assertGreaterThanOrEqual(2, count($history));
        $this->assertEquals(2, $history[0]['version_number']);
        $this->assertEquals(1, $history[1]['version_number']);
    }

    /**
     * Test: Compare two versions
     *
     * AC-3.3: Side-by-side comparison
     *
     * @return void
     */
    public function testCompareVersionsReturnsDiff(): void
    {
        // Arrange
        $reviewData1 = [
            'overall_impact_explanation' => 'Good approach with solid methodology',
            'relevance_explanation' => 'Highly relevant to the RFA',
        ];
        $reviewData2 = [
            'overall_impact_explanation' => 'Excellent approach with rigorous methodology and innovative design',
            'relevance_explanation' => 'Highly relevant to the RFA',
        ];

        $version1Id = $this->versionManager->createVersion($this->reviewId, $reviewData1, $this->reviewerId);
        $version2Id = $this->versionManager->createVersion($this->reviewId, $reviewData2, $this->reviewerId);

        // Act
        $diff = $this->versionManager->compareVersions($version1Id, $version2Id);

        // Assert
        $this->assertIsArray($diff);
        $this->assertArrayHasKey('overall_impact_explanation', $diff);
        $this->assertArrayHasKey('relevance_explanation', $diff);
    }

    /**
     * Test: Rollback to previous version
     *
     * AC-3.4: Version rollback functionality
     *
     * @return void
     */
    public function testRollbackToVersionRestoresState(): void
    {
        // Arrange
        $reviewData1 = ['overall_impact_score' => 1, 'overall_impact_explanation' => 'Original version'];
        $reviewData2 = ['overall_impact_score' => 5, 'overall_impact_explanation' => 'Modified version'];
        $reviewData3 = ['overall_impact_score' => 3, 'overall_impact_explanation' => 'Final version'];

        $version1Id = $this->versionManager->createVersion($this->reviewId, $reviewData1, $this->reviewerId);
        $version2Id = $this->versionManager->createVersion($this->reviewId, $reviewData2, $this->reviewerId);
        $version3Id = $this->versionManager->createVersion($this->reviewId, $reviewData3, $this->reviewerId);

        // Act - Rollback to version 1
        $result = $this->versionManager->rollbackToVersion($this->reviewId, $version1Id, $this->adminId);

        // Assert
        $this->assertTrue($result);

        // Verify new version created for rollback (snapshot of pre-rollback state)
        $history = $this->versionManager->getVersionHistory($this->reviewId);
        $this->assertGreaterThanOrEqual(4, count($history));

        // Most recent version is a snapshot of pre-rollback state with Rollback summary
        $snapshotVersion = $history[0];
        $this->assertStringContainsString('Rollback', $snapshotVersion['change_summary']);

        // Verify the reviews table was restored to version 1 state
        $stmt = $this->db->query(
            "SELECT overall_impact_score FROM reviews WHERE id = ?",
            [$this->reviewId]
        );
        $review = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(1, $review['overall_impact_score']);
    }

    /**
     * Test: Section data preservation in versions
     *
     * AC-3.5: Section data JSON storage
     *
     * @return void
     */
    public function testVersionPreservesSectionData(): void
    {
        // Arrange
        $sectionData = [
            'approach' => [
                'score' => 2,
                'strengths' => 'Rigorous methodology',
                'weaknesses' => 'Limited sample size',
            ],
            'significance' => [
                'score' => 1,
                'strengths' => 'Addresses critical gap',
                'weaknesses' => null,
            ],
        ];

        $reviewData = [
            'overall_impact_score' => 2,
            'section_data' => $sectionData,
        ];

        // Act
        $versionId = $this->versionManager->createVersion($this->reviewId, $reviewData, $this->reviewerId);
        $version = $this->versionManager->getVersionById($versionId);

        // Assert
        $this->assertNotNull($version);
        $this->assertNotNull($version['section_data']);
        $decodedSectionData = json_decode($version['section_data'], true);
        $this->assertIsArray($decodedSectionData);
        $this->assertArrayHasKey('approach', $decodedSectionData);
        $this->assertEquals(2, $decodedSectionData['approach']['score']);
    }

    /**
     * Test: Access control - reviewers cannot access version history
     *
     * AC-3.6: Version access control
     *
     * @return void
     */
    public function testReviewerCannotAccessVersionHistory(): void
    {
        // Arrange - Create version
        $reviewData = ['overall_impact_score' => 3, 'overall_impact_explanation' => 'Test'];
        $this->versionManager->createVersion($this->reviewId, $reviewData, $this->reviewerId);

        // Act - Try to access as reviewer
        $history = $this->versionManager->getVersionHistory($this->reviewId, $this->reviewerId);

        // Assert - Reviewer should get empty array or access denied
        $this->assertIsArray($history);
        $this->assertEmpty($history, 'Reviewer should not access version history');
    }

    /**
     * Test: Admin can access version history
     *
     * AC-3.6: Admin access to version history
     *
     * @return void
     */
    public function testAdminCanAccessVersionHistory(): void
    {
        // Arrange - Create version
        $reviewData = ['overall_impact_score' => 3, 'overall_impact_explanation' => 'Test'];
        $this->versionManager->createVersion($this->reviewId, $reviewData, $this->reviewerId);

        // Act - Access as admin
        $history = $this->versionManager->getVersionHistory($this->reviewId, $this->adminId);

        // Assert
        $this->assertNotEmpty($history, 'Admin should access version history');
        $this->assertCount(1, $history);
    }

    /**
     * Test: Get latest version for a review
     *
     * @return void
     */
    public function testGetLatestVersionReturnsMostRecent(): void
    {
        // Arrange
        $reviewData1 = ['overall_impact_score' => 1];
        $reviewData2 = ['overall_impact_score' => 2];
        $version1Id = $this->versionManager->createVersion($this->reviewId, $reviewData1, $this->reviewerId);
        $version2Id = $this->versionManager->createVersion($this->reviewId, $reviewData2, $this->reviewerId);

        // Act
        $latestVersion = $this->versionManager->getLatestVersion($this->reviewId);

        // Assert
        $this->assertNotNull($latestVersion);
        $this->assertEquals(2, $latestVersion['version_number']);
        $this->assertEquals($version2Id, $latestVersion['id']);
    }

    /**
     * Test: Delete version history for a review
     *
     * @return void
     */
    public function testDeleteVersionHistoryRemovesAllVersions(): void
    {
        // Arrange
        $reviewData1 = ['overall_impact_score' => 1];
        $reviewData2 = ['overall_impact_score' => 2];
        $this->versionManager->createVersion($this->reviewId, $reviewData1, $this->reviewerId);
        $this->versionManager->createVersion($this->reviewId, $reviewData2, $this->reviewerId);

        // Act
        $deletedCount = $this->versionManager->deleteVersionHistory($this->reviewId);

        // Assert
        $this->assertEquals(2, $deletedCount);
        $history = $this->versionManager->getVersionHistory($this->reviewId, $this->adminId);
        $this->assertEmpty($history);
    }

    /**
     * Test: Version includes change summary
     *
     * @return void
     */
    public function testVersionIncludesChangeSummary(): void
    {
        // Arrange
        $reviewData = ['overall_impact_score' => 3];
        $changeSummary = 'Updated score based on new information';

        // Act
        $versionId = $this->versionManager->createVersion(
            $this->reviewId,
            $reviewData,
            $this->reviewerId,
            $changeSummary
        );

        // Assert
        $version = $this->versionManager->getVersionById($versionId);
        $this->assertEquals($changeSummary, $version['change_summary']);
    }

    /**
     * Test: Get version count for review
     *
     * @return void
     */
    public function testGetVersionCountReturnsCorrectNumber(): void
    {
        // Arrange
        $this->versionManager->createVersion($this->reviewId, ['score' => 1], $this->reviewerId);
        $this->versionManager->createVersion($this->reviewId, ['score' => 2], $this->reviewerId);
        $this->versionManager->createVersion($this->reviewId, ['score' => 3], $this->reviewerId);

        // Act
        $count = $this->versionManager->getVersionCount($this->reviewId);

        // Assert
        $this->assertEquals(3, $count);
    }
}
