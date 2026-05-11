<?php
/**
 * TemplateManagerTest - Unit Tests for Review Template Library
 * SPEC: SPEC-REV-001, Feature 2: Review Template Library
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
use GrantReview\TemplateManager;
use GrantReview\Database;

/**
 * TemplateManagerTest Class
 *
 * Test suite for TemplateManager class covering all acceptance criteria:
 * - AC-2.1: Template creation
 * - AC-2.2: Variable substitution
 * - AC-2.3: Template application with confirmation
 * - AC-2.4: Template browser filtering
 * - AC-2.5: Template search
 * - AC-2.6: Grant type contextual display
 * - AC-2.7: Save as template workflow
 * - AC-2.8: Template activation/deactivation
 */
final class TemplateManagerTest extends TestCase
{
    /**
     * @var Database Database connection
     */
    private Database $db;

    /**
     * @var TemplateManager Template manager instance
     */
    private TemplateManager $templateManager;

    /**
     * @var int Test admin ID
     */
    private int $adminId;

    /**
     * @var int Test grant type ID
     */
    private int $grantTypeId;

    /**
     * @var int Test section ID
     */
    private int $sectionId;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->templateManager = new TemplateManager($this->db);

        // Create test admin
        $this->db->query(
            "INSERT INTO users (username, password_hash, full_name, email, role)
             VALUES ('test_admin_template', '$2y$10$test', 'Test Admin', 'admin@test.com', 'admin')
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );
        $this->adminId = (int) $this->db->lastInsertId();

        // Get TRANSCEND Pilot grant type
        $result = $this->db->query(
            "SELECT id FROM grant_types WHERE name = 'TRANSCEND Pilot' LIMIT 1"
        );
        $this->grantTypeId = (int) $result->fetch()['id'];

        // Get Approach section
        $result = $this->db->query(
            "SELECT id FROM grant_sections WHERE name = 'Approach' AND grant_type_id = ? LIMIT 1",
            [$this->grantTypeId]
        );
        $this->sectionId = (int) $result->fetch()['id'];
    }

    /**
     * Tear down test fixtures
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test templates
        $this->db->query(
            "DELETE FROM review_templates WHERE created_by = ? OR name LIKE 'Test %'",
            [$this->adminId]
        );
    }

    /**
     * Test: Create template successfully
     *
     * AC-2.1: Template creation with all fields
     *
     * @return void
     */
    public function testCreateTemplateSavesToDatabase(): void
    {
        // Arrange
        $templateData = [
            'name' => 'Test Approach Template',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $this->sectionId,
            'category' => 'Strengths',
            'content' => 'The {{applicant_name}} has developed a rigorous approach.',
            'variables' => ['applicant_name'],
        ];

        // Act
        $templateId = $this->templateManager->createTemplate($this->adminId, $templateData);

        // Assert
        $this->assertGreaterThan(0, $templateId, 'Template ID should be positive integer');

        // Verify template in database
        $template = $this->templateManager->getTemplateById($templateId);
        $this->assertNotNull($template);
        $this->assertEquals('Test Approach Template', $template['name']);
        $this->assertEquals($this->grantTypeId, $template['grant_type_id']);
    }

    /**
     * Test: Variable substitution
     *
     * AC-2.2: Replace placeholders with actual values
     *
     * @return void
     */
    public function testVariableSubstitutionReplacesPlaceholders(): void
    {
        // Arrange
        $templateContent = 'The {{applicant_name}} has submitted {{grant_id}} for {{grant_type}} funding.';
        $variables = [
            'applicant_name' => 'Dr. Jane Smith',
            'grant_id' => 'R01-TR-2025-001',
            'grant_type' => 'TRANSCEND Pilot',
        ];

        // Act
        $result = $this->templateManager->substituteVariables($templateContent, $variables);

        // Assert
        $this->assertEquals(
            'The Dr. Jane Smith has submitted R01-TR-2025-001 for TRANSCEND Pilot funding.',
            $result
        );
        $this->assertStringNotContainsString('{{', $result, 'No placeholders should remain');
    }

    /**
     * Test: Partial variable substitution (missing variables)
     *
     * @return void
     */
    public function testPartialSubstitutionHandlesMissingVariables(): void
    {
        // Arrange
        $templateContent = 'The {{applicant_name}} submitted {{grant_id}}.';
        $variables = [
            'applicant_name' => 'Dr. John Doe',
            // grant_id is missing
        ];

        // Act
        $result = $this->templateManager->substituteVariables($templateContent, $variables);

        // Assert
        $this->assertStringContainsString('Dr. John Doe', $result);
        $this->assertStringContainsString('{{grant_id}}', $result, 'Missing placeholder should remain');
    }

    /**
     * Test: Get templates by grant type
     *
     * AC-2.6: Grant type contextual display
     *
     * @return void
     */
    public function testGetTemplatesByGrantTypeReturnsCorrectTemplates(): void
    {
        // Arrange
        $templateData1 = [
            'name' => 'Test Template 1',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $this->sectionId,
            'category' => 'Strengths',
            'content' => 'Content for specific grant type',
        ];
        $this->templateManager->createTemplate($this->adminId, $templateData1);

        $templateData2 = [
            'name' => 'Test Template 2',
            'grant_type_id' => null, // All grant types
            'section_id' => $this->sectionId,
            'category' => 'Strengths',
            'content' => 'Content for all grant types',
        ];
        $this->templateManager->createTemplate($this->adminId, $templateData2);

        // Act
        $templates = $this->templateManager->getTemplatesByGrantType($this->grantTypeId);

        // Assert
        $this->assertGreaterThanOrEqual(2, count($templates));
        $hasSpecificType = false;
        $hasAllTypes = false;
        foreach ($templates as $template) {
            if ($template['name'] === 'Test Template 1') {
                $hasSpecificType = true;
            }
            if ($template['name'] === 'Test Template 2') {
                $hasAllTypes = true;
            }
        }
        $this->assertTrue($hasSpecificType, 'Specific grant type template should be included');
        $this->assertTrue($hasAllTypes, 'All grant types template should be included');
    }

    /**
     * Test: Get templates by section
     *
     * AC-2.4: Template browser filtering by section
     *
     * @return void
     */
    public function testGetTemplatesBySectionFiltersCorrectly(): void
    {
        // Arrange - Create templates for different sections
        $approachTemplate = [
            'name' => 'Test Approach Template',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $this->sectionId, // Approach
            'category' => 'Strengths',
            'content' => 'Approach content',
        ];
        $this->templateManager->createTemplate($this->adminId, $approachTemplate);

        // Get Innovation section ID
        $result = $this->db->query(
            "SELECT id FROM grant_sections WHERE name = 'Innovation' AND grant_type_id = ? LIMIT 1",
            [$this->grantTypeId]
        );
        $innovationSectionId = (int) $result->fetch()['id'];

        $innovationTemplate = [
            'name' => 'Test Innovation Template',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $innovationSectionId,
            'category' => 'Weaknesses',
            'content' => 'Innovation content',
        ];
        $this->templateManager->createTemplate($this->adminId, $innovationTemplate);

        // Act
        $approachTemplates = $this->templateManager->getTemplatesBySection($this->sectionId);

        // Assert
        $foundApproach = false;
        $foundInnovation = false;
        foreach ($approachTemplates as $template) {
            if ($template['name'] === 'Test Approach Template') {
                $foundApproach = true;
            }
            if ($template['name'] === 'Test Innovation Template') {
                $foundInnovation = true;
            }
        }
        $this->assertTrue($foundApproach, 'Approach template should be returned');
        $this->assertFalse($foundInnovation, 'Innovation template should NOT be returned');
    }

    /**
     * Test: Search templates by keyword
     *
     * AC-2.5: Template search functionality
     *
     * @return void
     */
    public function testSearchTemplatesReturnsMatchingResults(): void
    {
        // Arrange
        $template1 = [
            'name' => 'Test Methodology Template',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $this->sectionId,
            'category' => 'Strengths',
            'content' => 'The methodology is rigorous and well-designed.',
        ];
        $this->templateManager->createTemplate($this->adminId, $template1);

        $template2 = [
            'name' => 'Test Innovation Template',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $this->sectionId,
            'category' => 'Strengths',
            'content' => 'The innovation is significant.',
        ];
        $this->templateManager->createTemplate($this->adminId, $template2);

        // Act
        $results = $this->templateManager->searchTemplates('methodology');

        // Assert
        $this->assertGreaterThan(0, count($results));
        $foundMethodology = false;
        foreach ($results as $template) {
            if (stripos($template['name'], 'methodology') !== false ||
                stripos($template['content'], 'methodology') !== false) {
                $foundMethodology = true;
            }
        }
        $this->assertTrue($foundMethodology, 'Search should find methodology template');
    }

    /**
     * Test: Update template
     *
     * @return void
     */
    public function testUpdateTemplateModifiesExistingRecord(): void
    {
        // Arrange
        $templateData = [
            'name' => 'Test Original Template',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $this->sectionId,
            'category' => 'Strengths',
            'content' => 'Original content',
        ];
        $templateId = $this->templateManager->createTemplate($this->adminId, $templateData);

        // Act
        $updatedData = [
            'name' => 'Test Updated Template',
            'content' => 'Updated content',
        ];
        $result = $this->templateManager->updateTemplate($templateId, $updatedData);

        // Assert
        $this->assertTrue($result);
        $template = $this->templateManager->getTemplateById($templateId);
        $this->assertEquals('Test Updated Template', $template['name']);
        $this->assertEquals('Updated content', $template['content']);
    }

    /**
     * Test: Deactivate template (soft delete)
     *
     * AC-2.8: Template activation/deactivation
     *
     * @return void
     */
    public function testDeactivateTemplateSetsInactiveFlag(): void
    {
        // Arrange
        $templateData = [
            'name' => 'Test Deactivate Template',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $this->sectionId,
            'category' => 'Strengths',
            'content' => 'Content',
        ];
        $templateId = $this->templateManager->createTemplate($this->adminId, $templateData);

        // Act
        $result = $this->templateManager->setTemplateActive($templateId, false);

        // Assert
        $this->assertTrue($result);
        $template = $this->templateManager->getTemplateById($templateId);
        $this->assertEquals(0, $template['is_active']);

        // Template should not appear in active templates
        $activeTemplates = $this->templateManager->getActiveTemplates();
        $found = false;
        foreach ($activeTemplates as $t) {
            if ($t['id'] == $templateId) {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found, 'Deactivated template should not appear in active list');
    }

    /**
     * Test: Reactivate template
     *
     * AC-2.8: Reactivation of deactivated templates
     *
     * @return void
     */
    public function testReactivateTemplateSetsActiveFlag(): void
    {
        // Arrange
        $templateData = [
            'name' => 'Test Reactivate Template',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $this->sectionId,
            'category' => 'Strengths',
            'content' => 'Content',
        ];
        $templateId = $this->templateManager->createTemplate($this->adminId, $templateData);
        $this->templateManager->setTemplateActive($templateId, false);

        // Act
        $result = $this->templateManager->setTemplateActive($templateId, true);

        // Assert
        $this->assertTrue($result);
        $template = $this->templateManager->getTemplateById($templateId);
        $this->assertEquals(1, $template['is_active']);
    }

    /**
     * Test: Delete template permanently
     *
     * @return void
     */
    public function testDeleteTemplateRemovesFromDatabase(): void
    {
        // Arrange
        $templateData = [
            'name' => 'Test Delete Template',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $this->sectionId,
            'category' => 'Strengths',
            'content' => 'Content',
        ];
        $templateId = $this->templateManager->createTemplate($this->adminId, $templateData);

        // Act
        $result = $this->templateManager->deleteTemplate($templateId);

        // Assert
        $this->assertTrue($result);
        $template = $this->templateManager->getTemplateById($templateId);
        $this->assertNull($template, 'Deleted template should not exist');
    }

    /**
     * Test: Get templates by category
     *
     * @return void
     */
    public function testGetTemplatesByCategoryFiltersCorrectly(): void
    {
        // Arrange
        $strengthsTemplate = [
            'name' => 'Test Strengths Template',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $this->sectionId,
            'category' => 'Strengths',
            'content' => 'Strengths content',
        ];
        $this->templateManager->createTemplate($this->adminId, $strengthsTemplate);

        $weaknessesTemplate = [
            'name' => 'Test Weaknesses Template',
            'grant_type_id' => $this->grantTypeId,
            'section_id' => $this->sectionId,
            'category' => 'Weaknesses',
            'content' => 'Weaknesses content',
        ];
        $this->templateManager->createTemplate($this->adminId, $weaknessesTemplate);

        // Act
        $strengthsTemplates = $this->templateManager->getTemplatesByCategory('Strengths');

        // Assert
        $foundStrengths = false;
        $foundWeaknesses = false;
        foreach ($strengthsTemplates as $template) {
            if ($template['name'] === 'Test Strengths Template') {
                $foundStrengths = true;
            }
            if ($template['name'] === 'Test Weaknesses Template') {
                $foundWeaknesses = true;
            }
        }
        $this->assertTrue($foundStrengths, 'Strengths template should be returned');
        $this->assertFalse($foundWeaknesses, 'Weaknesses template should NOT be returned');
    }

    /**
     * Test: Save as template workflow (reviewer-initiated)
     *
     * AC-2.7: Save current review as template
     *
     * @return void
     */
    public function testSaveReviewAsTemplateCreatesPendingTemplate(): void
    {
        // Arrange
        $reviewContent = 'The applicant has demonstrated exceptional innovation in their approach.';
        $reviewerId = $this->adminId; // Using admin as reviewer for test

        // Act
        $templateId = $this->templateManager->saveReviewAsTemplate(
            $reviewerId,
            $this->grantTypeId,
            $this->sectionId,
            $reviewContent,
            'Innovation'
        );

        // Assert
        $this->assertGreaterThan(0, $templateId);
        $template = $this->templateManager->getTemplateById($templateId);
        $this->assertNotNull($template);
        // Template name is generated as "{reviewerName} - {sectionName} Template"
        $this->assertStringContainsString('Approach', $template['name']);
        $this->assertStringContainsString('Template', $template['name']);
        $this->assertEquals($reviewContent, $template['content']);
        $this->assertEquals('Innovation', $template['category']);
    }
}
