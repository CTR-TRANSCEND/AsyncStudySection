<?php
/**
 * TemplateManager - Review Template Library Management
 * SPEC: SPEC-REV-001, Feature 2: Review Template Library
 *
 * Acceptance Criteria Covered:
 * - AC-2.1: Template creation
 * - AC-2.2: Variable substitution
 * - AC-2.3: Template application with confirmation
 * - AC-2.4: Template browser filtering
 * - AC-2.5: Template search
 * - AC-2.6: Grant type contextual display
 * - AC-2.7: Save as template workflow
 * - AC-2.8: Template activation/deactivation
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
 * TemplateManager Class
 *
 * Manages reusable review templates including:
 * - CRUD operations for templates
 * - Variable substitution
 * - Template search and filtering
 * - Template activation/deactivation
 */
class TemplateManager
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
     * Create a new review template
     *
     * AC-2.1: Template creation with all required fields
     *
     * @param int   $creatorId  User ID of template creator
     * @param array $templateData Template data including name, content, etc.
     *
     * @return int Template ID on success, 0 on failure
     */
    public function createTemplate(int $creatorId, array $templateData): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO review_templates
                 (name, grant_type_id, section_id, category, content, variables, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $variables = isset($templateData['variables'])
                ? json_encode($templateData['variables'])
                : null;

            $result = $stmt->execute([
                $templateData['name'],
                $templateData['grant_type_id'] ?? null,
                $templateData['section_id'] ?? null,
                $templateData['category'] ?? null,
                $templateData['content'],
                $variables,
                $creatorId,
            ]);

            return $result ? (int) $this->pdo->lastInsertId() : 0;
        } catch (PDOException $e) {
            error_log("TemplateManager createTemplate error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get template by ID
     *
     * @param int $templateId Template ID
     *
     * @return array|null Template data or null if not found
     */
    public function getTemplateById(int $templateId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT rt.*, gt.name as grant_type_name, gs.name as section_name
                 FROM review_templates rt
                 LEFT JOIN grant_types gt ON rt.grant_type_id = gt.id
                 LEFT JOIN grant_sections gs ON rt.section_id = gs.id
                 WHERE rt.id = ?"
            );
            $stmt->execute([$templateId]);

            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            return $template ?: null;
        } catch (PDOException $e) {
            error_log("TemplateManager getTemplateById error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get templates by grant type
     *
     * AC-2.6: Grant type contextual display
     * Returns templates for specific grant type + templates for all grant types
     *
     * @param int $grantTypeId Grant type ID
     *
     * @return array Array of templates
     */
    public function getTemplatesByGrantType(int $grantTypeId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT rt.*, gt.name as grant_type_name, gs.name as section_name
                 FROM review_templates rt
                 LEFT JOIN grant_types gt ON rt.grant_type_id = gt.id
                 LEFT JOIN grant_sections gs ON rt.section_id = gs.id
                 WHERE rt.is_active = TRUE
                 AND (rt.grant_type_id = ? OR rt.grant_type_id IS NULL)
                 ORDER BY rt.grant_type_id DESC, rt.name ASC"
            );
            $stmt->execute([$grantTypeId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("TemplateManager getTemplatesByGrantType error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get templates by section
     *
     * AC-2.4: Template browser filtering by section
     *
     * @param int $sectionId Section ID
     *
     * @return array Array of templates
     */
    public function getTemplatesBySection(int $sectionId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT rt.*, gs.name as section_name
                 FROM review_templates rt
                 LEFT JOIN grant_sections gs ON rt.section_id = gs.id
                 WHERE rt.is_active = TRUE AND rt.section_id = ?
                 ORDER BY rt.category, rt.name ASC"
            );
            $stmt->execute([$sectionId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("TemplateManager getTemplatesBySection error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get templates by category
     *
     * @param string $category Category name (e.g., 'Strengths', 'Weaknesses')
     *
     * @return array Array of templates
     */
    public function getTemplatesByCategory(string $category): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT rt.*, gs.name as section_name
                 FROM review_templates rt
                 LEFT JOIN grant_sections gs ON rt.section_id = gs.id
                 WHERE rt.is_active = TRUE AND rt.category = ?
                 ORDER BY rt.name ASC"
            );
            $stmt->execute([$category]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("TemplateManager getTemplatesByCategory error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all active templates
     *
     * @return array Array of active templates
     */
    public function getActiveTemplates(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT rt.*, gt.name as grant_type_name, gs.name as section_name
                 FROM review_templates rt
                 LEFT JOIN grant_types gt ON rt.grant_type_id = gt.id
                 LEFT JOIN grant_sections gs ON rt.section_id = gs.id
                 WHERE rt.is_active = TRUE
                 ORDER BY rt.grant_type_id DESC, rt.section_id, rt.name ASC"
            );

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("TemplateManager getActiveTemplates error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Search templates by keyword
     *
     * AC-2.5: Template search functionality
     * Searches in name, category, and content fields
     *
     * @param string $keyword Search keyword
     *
     * @return array Array of matching templates
     */
    public function searchTemplates(string $keyword): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT rt.*, gt.name as grant_type_name, gs.name as section_name
                 FROM review_templates rt
                 LEFT JOIN grant_types gt ON rt.grant_type_id = gt.id
                 LEFT JOIN grant_sections gs ON rt.section_id = gs.id
                 WHERE rt.is_active = TRUE
                 AND (rt.name LIKE ? OR rt.category LIKE ? OR rt.content LIKE ?)
                 ORDER BY rt.name ASC"
            );

            $searchPattern = "%{$keyword}%";
            $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("TemplateManager searchTemplates error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Substitute variables in template content
     *
     * AC-2.2: Variable substitution with actual values
     *
     * @param string $content  Template content with {{variable}} placeholders
     * @param array  $variables Associative array of variable names to values
     *
     * @return string Content with variables substituted
     */
    public function substituteVariables(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $placeholder = "{{{$key}}}";
            $content = str_replace($placeholder, (string) $value, $content);
        }

        return $content;
    }

    /**
     * Update existing template
     *
     * @param int   $templateId Template ID
     * @param array $data       Updated template data
     *
     * @return bool True on success, false on failure
     */
    public function updateTemplate(int $templateId, array $data): bool
    {
        try {
            $allowedColumns = ['name', 'content', 'variables', 'category', 'is_active', 'description'];
            $fields = [];
            $values = [];

            foreach ($data as $key => $value) {
                if (!in_array($key, $allowedColumns, true)) {
                    continue;
                }
                if ($key === 'variables') {
                    $fields[] = "$key = ?";
                    $values[] = json_encode($value);
                } else {
                    $fields[] = "$key = ?";
                    $values[] = $value;
                }
            }

            if (empty($fields)) {
                return false;
            }

            $values[] = $templateId;
            $sql = "UPDATE review_templates SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);

            return $stmt->execute($values);
        } catch (PDOException $e) {
            error_log("TemplateManager updateTemplate error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set template active status (activation/deactivation)
     *
     * AC-2.8: Template activation/deactivation
     *
     * @param int  $templateId Template ID
     * @param bool $isActive   Active status
     *
     * @return bool True on success, false on failure
     */
    public function setTemplateActive(int $templateId, bool $isActive): bool
    {
        return $this->updateTemplate($templateId, ['is_active' => $isActive ? 1 : 0]);
    }

    /**
     * Delete template permanently
     *
     * @param int $templateId Template ID
     *
     * @return bool True on success, false on failure
     */
    public function deleteTemplate(int $templateId): bool
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM review_templates WHERE id = ?");
            $stmt->execute([$templateId]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("TemplateManager deleteTemplate error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Save review content as a template
     *
     * AC-2.7: Save as template workflow (reviewer-initiated)
     *
     * @param int    $reviewerId  Reviewer user ID
     * @param int    $grantTypeId Grant type ID
     * @param int    $sectionId   Section ID
     * @param string $content     Review content to save as template
     * @param string $category    Template category
     *
     * @return int Template ID on success, 0 on failure
     */
    public function saveReviewAsTemplate(
        int $reviewerId,
        int $grantTypeId,
        int $sectionId,
        string $content,
        string $category
    ): int {
        try {
            // Get reviewer name for template title
            $stmt = $this->pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $stmt->execute([$reviewerId]);
            $reviewerName = $stmt->fetchColumn();

            // Get section name
            $stmt = $this->pdo->prepare("SELECT name FROM grant_sections WHERE id = ?");
            $stmt->execute([$sectionId]);
            $sectionName = $stmt->fetchColumn();

            $templateName = "{$reviewerName} - {$sectionName} Template";

            return $this->createTemplate($reviewerId, [
                'name' => $templateName,
                'grant_type_id' => $grantTypeId,
                'section_id' => $sectionId,
                'category' => $category,
                'content' => $content,
            ]);
        } catch (PDOException $e) {
            error_log("TemplateManager saveReviewAsTemplate error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if template would overwrite existing data
     *
     * AC-2.3: Template application confirmation check
     *
     * @param string $fieldContent Existing field content
     *
     * @return bool True if existing content would be overwritten
     */
    public function wouldOverwriteContent(string $fieldContent): bool
    {
        return !empty(trim($fieldContent));
    }

    /**
     * Get template usage statistics
     *
     * @return array Usage stats including total, active, by category
     */
    public function getTemplateStats(): array
    {
        try {
            // Total templates
            $totalResult = $this->pdo->query(
                "SELECT COUNT(*) FROM review_templates"
            );
            $total = (int) $totalResult->fetchColumn();

            // Active templates
            $activeResult = $this->pdo->query(
                "SELECT COUNT(*) FROM review_templates WHERE is_active = TRUE"
            );
            $active = (int) $activeResult->fetchColumn();

            // By category
            $categoryStmt = $this->pdo->query(
                "SELECT category, COUNT(*) as count
                 FROM review_templates
                 WHERE is_active = TRUE AND category IS NOT NULL
                 GROUP BY category
                 ORDER BY count DESC"
            );
            $byCategory = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'by_category' => $byCategory,
            ];
        } catch (PDOException $e) {
            error_log("TemplateManager getTemplateStats error: " . $e->getMessage());
            return [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'by_category' => [],
            ];
        }
    }
}
