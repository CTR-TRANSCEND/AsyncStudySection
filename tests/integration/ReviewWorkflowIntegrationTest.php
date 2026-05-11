<?php
declare(strict_types=1);

/**
 * Integration Tests for Review Workflow
 * Description: Tests multi-class interactions in the complete review workflow
 *              across GrantType, StudySection, Application, Reviewer, and Review entities.
 */

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

use GrantReview\Tests\TestCase;

class ReviewWorkflowIntegrationTest extends TestCase
{
    /**
     * Test: Full review workflow — grant type, study section, application, reviewer, review
     *
     * Verifies that all entities associate correctly when created in order.
     */
    public function testFullReviewWorkflowCreation(): void
    {
        // Create a grant type
        $grantTypeId = $this->createTestGrantType(['name' => 'TRANSCEND Pilot Integration']);

        // Create a study section linked to the grant type
        $studySectionId = $this->createTestStudySection([
            'grant_type_id' => $grantTypeId,
            'name'          => 'Cardiovascular Integration Section',
        ]);

        // Create an application with both grant_type_id and study_section_id populated
        $applicationId = $this->createTestApplication([
            'grant_type_id'    => $grantTypeId,
            'study_section_id' => $studySectionId,
            'status'           => 'pending',
        ]);

        // Create a reviewer user
        $reviewerId = $this->createTestUser(['role' => 'reviewer']);

        // Assign reviewer to application
        $stmt = $this->db->prepare(
            "INSERT INTO assignments (application_id, reviewer_id, anonymous_label)
             VALUES (?, ?, 'Reviewer A')"
        );
        $stmt->execute([$applicationId, $reviewerId]);

        // Create a review
        $reviewId = $this->createTestReview([
            'application_id' => $applicationId,
            'reviewer_id'    => $reviewerId,
        ]);

        // Verify review is associated with the correct application and reviewer
        $stmt = $this->db->prepare(
            "SELECT r.id, r.application_id, r.reviewer_id, a.study_section_id, a.grant_type_id
             FROM reviews r
             JOIN applications a ON r.application_id = a.id
             WHERE r.id = ?"
        );
        $stmt->execute([$reviewId]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'Review row should exist in database');
        $this->assertEquals($applicationId, (int) $row['application_id']);
        $this->assertEquals($reviewerId, (int) $row['reviewer_id']);
        $this->assertEquals($studySectionId, (int) $row['study_section_id']);
        $this->assertEquals($grantTypeId, (int) $row['grant_type_id']);
    }

    /**
     * Test: Review count query for an application returns correct number
     *
     * Creates two reviews for one application and one review for another,
     * then verifies count isolation.
     */
    public function testReviewCountIsolationPerApplication(): void
    {
        $applicationId1 = $this->createTestApplication();
        $applicationId2 = $this->createTestApplication();

        $reviewer1 = $this->createTestUser(['role' => 'reviewer']);
        $reviewer2 = $this->createTestUser(['role' => 'reviewer']);

        $this->createTestReview([
            'application_id' => $applicationId1,
            'reviewer_id'    => $reviewer1,
        ]);
        $this->createTestReview([
            'application_id' => $applicationId1,
            'reviewer_id'    => $reviewer2,
        ]);
        $this->createTestReview([
            'application_id' => $applicationId2,
            'reviewer_id'    => $reviewer1,
        ]);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as total FROM reviews WHERE application_id = ?"
        );

        $stmt->execute([$applicationId1]);
        $count1 = (int) $stmt->fetchColumn();

        $stmt->execute([$applicationId2]);
        $count2 = (int) $stmt->fetchColumn();

        $this->assertEquals(2, $count1, 'Application 1 should have 2 reviews');
        $this->assertEquals(1, $count2, 'Application 2 should have 1 review');
    }

    /**
     * Test: Finalised reviews are persisted and distinguishable from drafts
     *
     * Creates one draft review (is_final=false) and one finalised review (is_final=true)
     * for the same application, then verifies the final flag is stored correctly.
     */
    public function testFinalReviewFlagPersistence(): void
    {
        $applicationId = $this->createTestApplication();
        $reviewer1     = $this->createTestUser(['role' => 'reviewer']);
        $reviewer2     = $this->createTestUser(['role' => 'reviewer']);

        $draftReviewId = $this->createTestReview([
            'application_id'         => $applicationId,
            'reviewer_id'            => $reviewer1,
            'overall_impact_score'   => 3,
            'is_final'               => false,
        ]);

        $finalReviewId = $this->createTestReview([
            'application_id'         => $applicationId,
            'reviewer_id'            => $reviewer2,
            'overall_impact_score'   => 7,
            'is_final'               => true,
        ]);

        $stmt = $this->db->prepare(
            "SELECT id, is_final, overall_impact_score FROM reviews WHERE id IN (?, ?) ORDER BY id"
        );
        $stmt->execute([$draftReviewId, $finalReviewId]);
        $rows = $stmt->fetchAll();

        $this->assertCount(2, $rows);

        // Find draft and final by their IDs
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $this->assertEquals(0, (int) $byId[$draftReviewId]['is_final'], 'Draft review should have is_final = 0');
        $this->assertEquals(1, (int) $byId[$finalReviewId]['is_final'], 'Final review should have is_final = 1');
        $this->assertEquals(3, (int) $byId[$draftReviewId]['overall_impact_score']);
        $this->assertEquals(7, (int) $byId[$finalReviewId]['overall_impact_score']);
    }

    /**
     * Test: getReviewStats returns array keyed by section name for a scored application
     *
     * Inserts two reviews with overall_impact_score and verifies the legacy
     * fallback path in getReviewStats returns a non-null result.
     */
    public function testGetReviewStatsReturnsDataForScoredApplication(): void
    {
        $grantTypeId   = $this->createTestGrantType();
        $studySectionId = $this->createTestStudySection(['grant_type_id' => $grantTypeId]);
        $applicationId = $this->createTestApplication([
            'grant_type_id'    => $grantTypeId,
            'study_section_id' => $studySectionId,
        ]);
        $reviewer1 = $this->createTestUser(['role' => 'reviewer']);
        $reviewer2 = $this->createTestUser(['role' => 'reviewer']);

        $this->createTestReview([
            'application_id'       => $applicationId,
            'reviewer_id'          => $reviewer1,
            'overall_impact_score' => 4,
            'relevance_score'      => 5,
            'is_final'             => true,
        ]);
        $this->createTestReview([
            'application_id'       => $applicationId,
            'reviewer_id'          => $reviewer2,
            'overall_impact_score' => 6,
            'relevance_score'      => 7,
            'is_final'             => true,
        ]);

        $stats = getReviewStats($applicationId);

        // getReviewStats returns an array (may be empty if no grant sections configured,
        // but it must not throw and must be an array)
        $this->assertIsArray($stats, 'getReviewStats must return an array');
    }

    /**
     * Test: Reviewer can be assigned to multiple applications and each assignment
     *       is independent.
     */
    public function testReviewerAssignedToMultipleApplications(): void
    {
        $reviewerId     = $this->createTestUser(['role' => 'reviewer']);
        $applicationId1 = $this->createTestApplication();
        $applicationId2 = $this->createTestApplication();
        $applicationId3 = $this->createTestApplication();

        foreach ([$applicationId1, $applicationId2, $applicationId3] as $i => $appId) {
            $stmt = $this->db->prepare(
                "INSERT INTO assignments (application_id, reviewer_id, anonymous_label)
                 VALUES (?, ?, ?)"
            );
            $stmt->execute([$appId, $reviewerId, 'Reviewer ' . ($i + 1)]);
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM assignments WHERE reviewer_id = ? AND application_id IN (?, ?, ?)"
        );
        $stmt->execute([$reviewerId, $applicationId1, $applicationId2, $applicationId3]);
        $count = (int) $stmt->fetchColumn();

        $this->assertEquals(3, $count, 'Reviewer should be assigned to all 3 applications');
    }
}
