<?php
/**
 * DraftManager - Auto-Save Draft Management System
 * SPEC: SPEC-REV-001, Feature 1: Draft Review Auto-Save System
 *
 * Acceptance Criteria Covered:
 * - AC-1.1: Auto-save trigger after 30 seconds
 * - AC-1.2: Draft persistence across sessions
 * - AC-1.3: Draft expiration after 7 days
 * - AC-1.4: Draft clear on submit
 * - AC-1.5: Manual draft save
 * - AC-1.6: Draft security (access control)
 *
 * @category Manager
 * @package  GrantReview\Managers
 * @author   TDD Implementer
 * @license  MIT
 * @link     https://github.com/your-org/AsynchronousGrantReview2
 */

declare(strict_types=1);

namespace GrantReview;

use PDO;
use PDOException;

/**
 * DraftManager Class
 *
 * Manages auto-saved draft reviews for reviewers including:
 * - Saving draft form data
 * - Loading existing drafts
 * - Deleting drafts
 * - Cleanup of expired drafts
 * - Security validation
 */
class DraftManager
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
     * Save draft data for a reviewer and application
     *
     * AC-1.1, AC-1.5: Auto-save and manual save functionality
     *
     * @param int   $reviewerId    Reviewer user ID
     * @param int   $applicationId Application ID
     * @param array $formData      Form field data as associative array
     *
     * @return bool True on success, false on failure
     */
    public function saveDraft(int $reviewerId, int $applicationId, array $formData): bool
    {
        try {
            // Sanitize form data
            $sanitizedData = $this->sanitizeFormData($formData);

            // Convert to JSON
            $jsonData = json_encode($sanitizedData);

            if ($jsonData === false) {
                error_log("DraftManager: Failed to encode form data to JSON");
                return false;
            }

            // Check if draft already exists
            $existingDraft = $this->loadDraft($reviewerId, $applicationId);

            if ($existingDraft !== null) {
                // Update existing draft
                $stmt = $this->pdo->prepare(
                    "UPDATE review_drafts
                     SET form_data = ?, saved_at = NOW(), updated_at = NOW()
                     WHERE reviewer_id = ? AND application_id = ?
                     AND expires_at > NOW()"
                );
                $result = $stmt->execute([$jsonData, $reviewerId, $applicationId]);
            } else {
                // Insert new draft
                $stmt = $this->pdo->prepare(
                    "INSERT INTO review_drafts (reviewer_id, application_id, form_data, saved_at, expires_at)
                     VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))"
                );
                $result = $stmt->execute([$reviewerId, $applicationId, $jsonData]);
            }

            return $result !== false;
        } catch (PDOException $e) {
            error_log("DraftManager saveDraft error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load draft data for a reviewer and application
     *
     * AC-1.2: Draft restoration functionality
     * AC-1.3: Expired drafts are not returned
     * AC-1.6: Security - reviewer can only access their own drafts
     *
     * @param int $reviewerId    Reviewer user ID
     * @param int $applicationId Application ID
     *
     * @return array|null Form data array or null if not found/expired
     */
    public function loadDraft(int $reviewerId, int $applicationId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT form_data, saved_at, expires_at
                 FROM review_drafts
                 WHERE reviewer_id = ? AND application_id = ?
                 AND expires_at > NOW()
                 ORDER BY saved_at DESC
                 LIMIT 1"
            );
            $stmt->execute([$reviewerId, $applicationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false || $row === null) {
                return null;
            }

            // Decode JSON data
            $formData = json_decode($row['form_data'], true);

            if (!is_array($formData)) {
                error_log("DraftManager: Failed to decode draft form data");
                return null;
            }

            return $formData;
        } catch (PDOException $e) {
            error_log("DraftManager loadDraft error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete draft for a reviewer and application
     *
     * AC-1.4: Draft clear on submit
     *
     * @param int $reviewerId    Reviewer user ID
     * @param int $applicationId Application ID
     *
     * @return bool True on success, false on failure
     */
    public function deleteDraft(int $reviewerId, int $applicationId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "DELETE FROM review_drafts
                 WHERE reviewer_id = ? AND application_id = ?"
            );
            $stmt->execute([$reviewerId, $applicationId]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("DraftManager deleteDraft error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up expired drafts
     *
     * AC-1.3: Automatic cleanup of drafts older than 7 days
     *
     * @return int Number of drafts deleted
     */
    public function cleanupExpiredDrafts(): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "DELETE FROM review_drafts WHERE expires_at < NOW()"
            );
            $stmt->execute();

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("DraftManager cleanupExpiredDrafts error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get all drafts for a specific reviewer
     *
     * @param int $reviewerId Reviewer user ID
     *
     * @return array Array of drafts with metadata
     */
    public function getAllDraftsForReviewer(int $reviewerId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    rd.id,
                    rd.reviewer_id,
                    rd.application_id,
                    rd.form_data,
                    rd.saved_at,
                    rd.expires_at,
                    a.applicant_name,
                    a.grant_id,
                    a.application_title
                 FROM review_drafts rd
                 JOIN applications a ON rd.application_id = a.id
                 WHERE rd.reviewer_id = ? AND rd.expires_at > NOW()
                 ORDER BY rd.saved_at DESC"
            );
            $stmt->execute([$reviewerId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("DraftManager getAllDraftsForReviewer error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if a draft exists for a reviewer and application
     *
     * @param int $reviewerId    Reviewer user ID
     * @param int $applicationId Application ID
     *
     * @return bool True if draft exists and is not expired
     */
    public function draftExists(int $reviewerId, int $applicationId): bool
    {
        return $this->loadDraft($reviewerId, $applicationId) !== null;
    }

    /**
     * Sanitize form data to prevent XSS attacks
     *
     * @param array $formData Raw form data
     *
     * @return array Sanitized form data
     */
    private function sanitizeFormData(array $formData): array
    {
        $sanitized = [];

        foreach ($formData as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeFormData($value);
            } elseif (is_string($value)) {
                // Escape HTML entities
                $sanitized[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get draft statistics for a reviewer
     *
     * @param int $reviewerId Reviewer user ID
     *
     * @return array Statistics including total, active, expired counts
     */
    public function getDraftStats(int $reviewerId): array
    {
        try {
            // Active drafts
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) as active_count
                 FROM review_drafts
                 WHERE reviewer_id = ? AND expires_at > NOW()"
            );
            $stmt->execute([$reviewerId]);
            $activeCount = (int) $stmt->fetchColumn();

            // Expired drafts (not yet cleaned up)
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) as expired_count
                 FROM review_drafts
                 WHERE reviewer_id = ? AND expires_at <= NOW()"
            );
            $stmt->execute([$reviewerId]);
            $expiredCount = (int) $stmt->fetchColumn();

            return [
                'active_count' => $activeCount,
                'expired_count' => $expiredCount,
                'total_count' => $activeCount + $expiredCount,
            ];
        } catch (PDOException $e) {
            error_log("DraftManager getDraftStats error: " . $e->getMessage());
            return [
                'active_count' => 0,
                'expired_count' => 0,
                'total_count' => 0,
            ];
        }
    }
}
