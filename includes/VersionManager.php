<?php
/**
 * VersionManager - Review Version History Management
 * SPEC: SPEC-REV-001, Feature 3: Review Version History
 *
 * Acceptance Criteria Covered:
 * - AC-3.1: Version creation on modify
 * - AC-3.2: Version timeline display
 * - AC-3.3: Side-by-side comparison
 * - AC-3.4: Version rollback
 * - AC-3.5: Section data preservation
 * - AC-3.6: Version access control
 *
 * @category Manager
 * @package  GrantReview\Managers
 * @author   TDD Implementer
 * @license  MIT
 */

declare(strict_types=1);

namespace GrantReview;

use PDO;
use PDOException;

/**
 * VersionManager Class
 *
 * Manages version history for reviews including:
 * - Version creation
 * - Version retrieval and comparison
 * - Rollback functionality
 * - Access control
 */
class VersionManager
{
    /**
     * @var Database Database connection instance
     */
    private Database $db;

    /**
     * @var PDO PDO connection instance
     */
    private PDO $pdo;

    /**
     * Constructor
     *
     * @param Database $db Database connection
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->pdo = $db->getConnection();
    }

    /**
     * Create a new version of a review
     *
     * AC-3.1: Version creation on modify
     * AC-3.5: Section data preservation
     *
     * @param int         $reviewId      Review ID
     * @param array       $reviewData    Review data including scores and comments
     * @param int         $createdBy     User ID who made the change
     * @param string|null $changeSummary Optional summary of changes
     *
     * @return int Version ID on success, 0 on failure
     */
    public function createVersion(
        int $reviewId,
        array $reviewData,
        int $createdBy,
        ?string $changeSummary = null
    ): int {
        try {
            // Get next version number
            $versionNumber = $this->getNextVersionNumber($reviewId);

            // Prepare data for insertion
            $fields = [
                'review_id' => $reviewId,
                'version_number' => $versionNumber,
                'created_by' => $createdBy,
            ];

            // Add optional fields
            $optionalFields = [
                'overall_impact_score',
                'relevance_score',
                'budget_acceptable',
                'overall_impact_explanation',
                'relevance_explanation',
                'budget_modifications',
                'change_summary',
            ];

            foreach ($optionalFields as $field) {
                if (isset($reviewData[$field])) {
                    $fields[$field] = $reviewData[$field];
                }
            }

            if ($changeSummary !== null) {
                $fields['change_summary'] = $changeSummary;
            }

            // Encode section data as JSON
            if (isset($reviewData['section_data'])) {
                $fields['section_data'] = json_encode($reviewData['section_data']);
            }

            // Build INSERT query
            $columnNames = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));

            $stmt = $this->pdo->prepare(
                "INSERT INTO review_versions ($columnNames) VALUES ($placeholders)"
            );

            $result = $stmt->execute(array_values($fields));

            return $result ? (int) $this->pdo->lastInsertId() : 0;
        } catch (PDOException $e) {
            error_log("VersionManager createVersion error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get version by ID
     *
     * @param int $versionId Version ID
     *
     * @return array|null Version data or null if not found
     */
    public function getVersionById(int $versionId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT rv.*, u.full_name as created_by_name
                 FROM review_versions rv
                 LEFT JOIN users u ON rv.created_by = u.id
                 WHERE rv.id = ?"
            );
            $stmt->execute([$versionId]);

            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            return $version ?: null;
        } catch (PDOException $e) {
            error_log("VersionManager getVersionById error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get version history for a review
     *
     * AC-3.2: Version timeline display
     * AC-3.6: Access control (admin only)
     *
     * @param int      $reviewId Review ID
     * @param int|null $userId   User ID for access control (null = skip check)
     *
     * @return array Array of versions in reverse chronological order
     */
    public function getVersionHistory(int $reviewId, ?int $userId = null): array
    {
        try {
            // Check access control if userId provided
            if ($userId !== null) {
                $userStmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ?");
                $userStmt->execute([$userId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);

                if (!$user || $user['role'] !== 'admin') {
                    return []; // Access denied
                }
            }

            $stmt = $this->pdo->prepare(
                "SELECT rv.*, u.full_name as created_by_name
                 FROM review_versions rv
                 LEFT JOIN users u ON rv.created_by = u.id
                 WHERE rv.review_id = ?
                 ORDER BY rv.version_number DESC"
            );
            $stmt->execute([$reviewId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("VersionManager getVersionHistory error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get latest version for a review
     *
     * @param int $reviewId Review ID
     *
     * @return array|null Latest version data or null if none exists
     */
    public function getLatestVersion(int $reviewId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT rv.*, u.full_name as created_by_name
                 FROM review_versions rv
                 LEFT JOIN users u ON rv.created_by = u.id
                 WHERE rv.review_id = ?
                 ORDER BY rv.version_number DESC
                 LIMIT 1"
            );
            $stmt->execute([$reviewId]);

            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            return $version ?: null;
        } catch (PDOException $e) {
            error_log("VersionManager getLatestVersion error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Compare two versions
     *
     * AC-3.3: Side-by-side comparison
     *
     * @param int $versionId1 First version ID
     * @param int $versionId2 Second version ID
     *
     * @return array Comparison data with differences highlighted
     */
    public function compareVersions(int $versionId1, int $versionId2): array
    {
        try {
            $version1 = $this->getVersionById($versionId1);
            $version2 = $this->getVersionById($versionId2);

            if (!$version1 || !$version2) {
                return [];
            }

            // Compare fields
            $fieldsToCompare = [
                'overall_impact_score',
                'relevance_score',
                'budget_acceptable',
                'overall_impact_explanation',
                'relevance_explanation',
                'budget_modifications',
            ];

            $comparison = [];
            foreach ($fieldsToCompare as $field) {
                $value1 = $version1[$field] ?? null;
                $value2 = $version2[$field] ?? null;

                if ($value1 !== $value2) {
                    $comparison[$field] = [
                        'version1' => $value1,
                        'version2' => $value2,
                        'changed' => true,
                    ];
                } else {
                    $comparison[$field] = [
                        'version1' => $value1,
                        'version2' => $value2,
                        'changed' => false,
                    ];
                }
            }

            // Compare section data
            $sectionData1 = isset($version1['section_data'])
                ? json_decode($version1['section_data'], true)
                : [];
            $sectionData2 = isset($version2['section_data'])
                ? json_decode($version2['section_data'], true)
                : [];

            if ($sectionData1 !== $sectionData2) {
                $comparison['section_data'] = [
                    'version1' => $sectionData1,
                    'version2' => $sectionData2,
                    'changed' => true,
                ];
            }

            return $comparison;
        } catch (PDOException $e) {
            error_log("VersionManager compareVersions error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Rollback review to a specific version
     *
     * AC-3.4: Version rollback functionality
     *
     * @param int $reviewId       Review ID to rollback
     * @param int $targetVersionId Target version ID to rollback to
     * @param int $rolledBackBy    User ID performing the rollback
     *
     * @return bool True on success, false on failure
     */
    public function rollbackToVersion(
        int $reviewId,
        int $targetVersionId,
        int $rolledBackBy
    ): bool {
        try {
            // Get target version
            $targetVersion = $this->getVersionById($targetVersionId);

            if (!$targetVersion || $targetVersion['review_id'] !== $reviewId) {
                return false;
            }

            // Get current review data
            $stmt = $this->pdo->prepare("SELECT * FROM reviews WHERE id = ?");
            $stmt->execute([$reviewId]);
            $currentReview = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentReview) {
                return false;
            }

            // Create version of current state before rollback
            $currentData = [
                'overall_impact_score' => $currentReview['overall_impact_score'],
                'relevance_score' => $currentReview['relevance_score'],
                'budget_acceptable' => $currentReview['budget_acceptable'],
                'overall_impact_explanation' => $currentReview['overall_impact_explanation'],
                'relevance_explanation' => $currentReview['relevance_explanation'],
                'budget_modifications' => $currentReview['budget_modifications'],
            ];

            $changeSummary = sprintf(
                'Rollback from version %d to version %d',
                $this->getLatestVersionNumber($reviewId),
                $targetVersion['version_number']
            );

            // Wrap snapshot creation and review update in a transaction so both
            // succeed or both fail — avoids orphaned version records on partial failure.
            $this->pdo->beginTransaction();

            $versionId = $this->createVersion($reviewId, $currentData, $rolledBackBy, $changeSummary);
            if ($versionId === 0) {
                $this->pdo->rollBack();
                return false;
            }

            // Update review with target version data
            $updateStmt = $this->pdo->prepare(
                "UPDATE reviews
                 SET overall_impact_score = ?,
                     relevance_score = ?,
                     budget_acceptable = ?,
                     overall_impact_explanation = ?,
                     relevance_explanation = ?,
                     budget_modifications = ?,
                     updated_at = NOW()
                 WHERE id = ?"
            );

            $result = $updateStmt->execute([
                $targetVersion['overall_impact_score'],
                $targetVersion['relevance_score'],
                $targetVersion['budget_acceptable'],
                $targetVersion['overall_impact_explanation'],
                $targetVersion['relevance_explanation'],
                $targetVersion['budget_modifications'],
                $reviewId,
            ]);

            if ($result === false) {
                $this->pdo->rollBack();
                return false;
            }

            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("VersionManager rollbackToVersion error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete version history for a review
     *
     * @param int $reviewId Review ID
     *
     * @return int Number of versions deleted
     */
    public function deleteVersionHistory(int $reviewId): int
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM review_versions WHERE review_id = ?");
            $stmt->execute([$reviewId]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("VersionManager deleteVersionHistory error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get version count for a review
     *
     * @param int $reviewId Review ID
     *
     * @return int Number of versions
     */
    public function getVersionCount(int $reviewId): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM review_versions WHERE review_id = ?"
            );
            $stmt->execute([$reviewId]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("VersionManager getVersionCount error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get next version number for a review
     *
     * @param int $reviewId Review ID
     *
     * @return int Next version number
     */
    private function getNextVersionNumber(int $reviewId): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(MAX(version_number), 0) + 1 as next_version
                 FROM review_versions
                 WHERE review_id = ?"
            );
            $stmt->execute([$reviewId]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("VersionManager getNextVersionNumber error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Get latest version number for a review
     *
     * @param int $reviewId Review ID
     *
     * @return int Latest version number
     */
    private function getLatestVersionNumber(int $reviewId): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(MAX(version_number), 0) as latest_version
                 FROM review_versions
                 WHERE review_id = ?"
            );
            $stmt->execute([$reviewId]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("VersionManager getLatestVersionNumber error: " . $e->getMessage());
            return 0;
        }
    }
}
