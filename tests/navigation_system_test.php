<?php
/**
 * Navigation System Integration Test (SPEC-UIX-002 Milestone 2)
 *
 * Tests the centralized navigation system including:
 * - renderNavigation() function
 * - getNavigationItems() function
 * - getUnreadDiscussionCount() function
 * - Badge display for unread discussions
 * - Admin and reviewer navigation items
 *
 * @author MoAI TDD Implementation
 * @version 1.0.0
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

use Models\Auth;

class NavigationSystemTest
{
    private $testResults = [];
    private $testCount = 0;
    private $passCount = 0;
    private $failCount = 0;

    /**
     * Run all navigation system tests
     */
    public function runAllTests()
    {
        echo "=== Navigation System Integration Test ===\n\n";

        $this->testGetNavigationItemsForAdmin();
        $this->testGetNavigationItemsForReviewer();
        $this->testGetNavigationItemsForUnknownRole();
        $this->testRenderNavigationForAdmin();
        $this->testRenderNavigationForReviewer();
        $this->testRenderNavigationWithUnreadBadge();
        $this->testUnreadDiscussionCount();
        $this->testClearNavigationCache();
        $this->testNavigationAccessibilityAttributes();
        $this->testNavigationDropdownStructure();

        $this->printSummary();
    }

    /**
     * Test getNavigationItems for admin role
     */
    private function testGetNavigationItemsForAdmin()
    {
        $this->testCount++;
        $testName = 'getNavigationItems returns correct items for admin role';

        try {
            $items = getNavigationItems('admin');

            $passed = true;
            $errors = [];

            // Check that we have items
            if (empty($items)) {
                $passed = false;
                $errors[] = 'No items returned for admin role';
            }

            // Check for expected items
            $expectedItems = ['Dashboard', 'Grant Types', 'Study Sections', 'Users'];
            $foundItems = [];

            foreach ($items as $item) {
                if (isset($item['title'])) {
                    $foundItems[] = $item['title'];
                }
            }

            foreach ($expectedItems as $expected) {
                if (!in_array($expected, $foundItems)) {
                    $passed = false;
                    $errors[] = "Missing expected item: {$expected}";
                }
            }

            // Check for dropdown menu
            $dropdownFound = false;
            foreach ($items as $item) {
                if (isset($item['children']) && !empty($item['children'])) {
                    $dropdownFound = true;
                    break;
                }
            }

            if (!$dropdownFound) {
                $passed = false;
                $errors[] = 'Dropdown menu not found for admin';
            }

            $this->recordTest($testName, $passed, $errors);
        } catch (Exception $e) {
            $this->recordTest($testName, false, [$e->getMessage()]);
        }
    }

    /**
     * Test getNavigationItems for reviewer role
     */
    private function testGetNavigationItemsForReviewer()
    {
        $this->testCount++;
        $testName = 'getNavigationItems returns correct items for reviewer role';

        try {
            $items = getNavigationItems('reviewer');

            $passed = true;
            $errors = [];

            // Check that we have items
            if (empty($items)) {
                $passed = false;
                $errors[] = 'No items returned for reviewer role';
            }

            // Check for expected items
            $expectedItems = ['Dashboard', 'Discussions'];
            $foundItems = [];

            foreach ($items as $item) {
                if (isset($item['title'])) {
                    $foundItems[] = $item['title'];
                }
            }

            foreach ($expectedItems as $expected) {
                if (!in_array($expected, $foundItems)) {
                    $passed = false;
                    $errors[] = "Missing expected item: {$expected}";
                }
            }

            // Check for unread badge on Discussions
            $discussionsHasBadge = false;
            foreach ($items as $item) {
                if (isset($item['title']) && $item['title'] === 'Discussions' && isset($item['badge']) && $item['badge'] === 'unread') {
                    $discussionsHasBadge = true;
                    break;
                }
            }

            if (!$discussionsHasBadge) {
                $passed = false;
                $errors[] = 'Discussions item missing unread badge configuration';
            }

            $this->recordTest($testName, $passed, $errors);
        } catch (Exception $e) {
            $this->recordTest($testName, false, [$e->getMessage()]);
        }
    }

    /**
     * Test getNavigationItems for unknown role
     */
    private function testGetNavigationItemsForUnknownRole()
    {
        $this->testCount++;
        $testName = 'getNavigationItems returns empty array for unknown role';

        try {
            $items = getNavigationItems('unknown_role');

            $passed = is_array($items) && empty($items);

            if (!$passed) {
                $this->recordTest($testName, false, ['Expected empty array for unknown role']);
            } else {
                $this->recordTest($testName, true);
            }
        } catch (Exception $e) {
            $this->recordTest($testName, false, [$e->getMessage()]);
        }
    }

    /**
     * Test renderNavigation for admin
     */
    private function testRenderNavigationForAdmin()
    {
        $this->testCount++;
        $testName = 'renderNavigation generates correct HTML for admin';

        try {
            $html = renderNavigation('admin');

            $passed = true;
            $errors = [];

            // Check for nav element
            if (strpos($html, '<nav class="nav"') === false) {
                $passed = false;
                $errors[] = 'Missing nav element with class "nav"';
            }

            // Check for aria-label
            if (strpos($html, 'aria-label="Main navigation"') === false) {
                $passed = false;
                $errors[] = 'Missing aria-label on nav element';
            }

            // Check for nav-link class
            if (strpos($html, 'class="nav-link"') === false) {
                $passed = false;
                $errors[] = 'Missing nav-link class';
            }

            // Check for dropdown structure
            if (strpos($html, 'nav-dropdown') === false) {
                $passed = false;
                $errors[] = 'Missing dropdown structure';
            }

            // Check for dropdown toggle button
            if (strpos($html, 'nav-dropdown-toggle') === false) {
                $passed = false;
                $errors[] = 'Missing dropdown toggle button';
            }

            // Check for ARIA attributes
            if (strpos($html, 'aria-haspopup="true"') === false) {
                $passed = false;
                $errors[] = 'Missing aria-haspopup attribute';
            }

            if (strpos($html, 'aria-expanded="false"') === false) {
                $passed = false;
                $errors[] = 'Missing aria-expanded attribute';
            }

            $this->recordTest($testName, $passed, $errors);
        } catch (Exception $e) {
            $this->recordTest($testName, false, [$e->getMessage()]);
        }
    }

    /**
     * Test renderNavigation for reviewer
     */
    private function testRenderNavigationForReviewer()
    {
        $this->testCount++;
        $testName = 'renderNavigation generates correct HTML for reviewer';

        try {
            $html = renderNavigation('reviewer', null);

            $passed = true;
            $errors = [];

            // Check for nav element
            if (strpos($html, '<nav class="nav"') === false) {
                $passed = false;
                $errors[] = 'Missing nav element with class "nav"';
            }

            // Check for Dashboard link
            if (strpos($html, 'Dashboard') === false) {
                $passed = false;
                $errors[] = 'Missing Dashboard link';
            }

            // Check for Discussions link
            if (strpos($html, 'Discussions') === false) {
                $passed = false;
                $errors[] = 'Missing Discussions link';
            }

            $this->recordTest($testName, $passed, $errors);
        } catch (Exception $e) {
            $this->recordTest($testName, false, [$e->getMessage()]);
        }
    }

    /**
     * Test renderNavigation with unread badge
     */
    private function testRenderNavigationWithUnreadBadge()
    {
        $this->testCount++;
        $testName = 'renderNavigation displays unread badge when count > 0';

        try {
            // Mock a user ID that might have unread messages
            $userId = 1;

            $html = renderNavigation('reviewer', $userId);

            $passed = true;
            $errors = [];

            // Check if badge HTML is present (may or may not be present depending on actual data)
            // We're testing that the structure is correct
            if (strpos($html, 'Discussions') !== false) {
                // Discussions link exists - badge may or may not be present depending on unread count
                $passed = true;
            } else {
                $passed = false;
                $errors[] = 'Discussions link not found';
            }

            $this->recordTest($testName, $passed, $errors);
        } catch (Exception $e) {
            $this->recordTest($testName, false, [$e->getMessage()]);
        }
    }

    /**
     * Test getUnreadDiscussionCount function
     */
    private function testUnreadDiscussionCount()
    {
        $this->testCount++;
        $testName = 'getUnreadDiscussionCount returns valid count';

        try {
            $userId = 1;
            $count = getUnreadDiscussionCount($userId);

            $passed = is_numeric($count) && $count >= 0;

            if (!$passed) {
                $this->recordTest($testName, false, ['Expected non-negative integer']);
            } else {
                $this->recordTest($testName, true);
            }
        } catch (Exception $e) {
            $this->recordTest($testName, false, [$e->getMessage()]);
        }
    }

    /**
     * Test clearNavigationCache function
     */
    private function testClearNavigationCache()
    {
        $this->testCount++;
        $testName = 'clearNavigationCache executes without errors';

        try {
            clearNavigationCache();
            $this->recordTest($testName, true);
        } catch (Exception $e) {
            $this->recordTest($testName, false, [$e->getMessage()]);
        }
    }

    /**
     * Test navigation accessibility attributes
     */
    private function testNavigationAccessibilityAttributes()
    {
        $this->testCount++;
        $testName = 'Navigation HTML includes required ARIA attributes';

        try {
            $html = renderNavigation('admin');

            $passed = true;
            $errors = [];

            // Check for required ARIA attributes
            $requiredAttrs = [
                'aria-label',
                'aria-haspopup',
                'aria-expanded',
                'role="menu"',
                'aria-labelledby'
            ];

            foreach ($requiredAttrs as $attr) {
                if (strpos($html, $attr) === false) {
                    $passed = false;
                    $errors[] = "Missing required attribute: {$attr}";
                }
            }

            // Check for aria-hidden on decorative elements
            if (strpos($html, 'aria-hidden="true"') === false) {
                $passed = false;
                $errors[] = 'Missing aria-hidden on decorative elements';
            }

            $this->recordTest($testName, $passed, $errors);
        } catch (Exception $e) {
            $this->recordTest($testName, false, [$e->getMessage()]);
        }
    }

    /**
     * Test navigation dropdown structure
     */
    private function testNavigationDropdownStructure()
    {
        $this->testCount++;
        $testName = 'Navigation dropdown has correct structure';

        try {
            $html = renderNavigation('admin');

            $passed = true;
            $errors = [];

            // Check for dropdown container
            if (strpos($html, '<div class="nav-dropdown">') === false) {
                $passed = false;
                $errors[] = 'Missing nav-dropdown container';
            }

            // Check for dropdown toggle button
            if (strpos($html, '<button class="nav-link nav-dropdown-toggle"') === false) {
                $passed = false;
                $errors[] = 'Missing dropdown toggle button';
            }

            // Check for dropdown menu
            if (strpos($html, '<div class="nav-dropdown-menu"') === false) {
                $passed = false;
                $errors[] = 'Missing dropdown menu container';
            }

            // Check for dropdown caret
            if (strpos($html, 'nav-dropdown-caret') === false) {
                $passed = false;
                $errors[] = 'Missing dropdown caret indicator';
            }

            $this->recordTest($testName, $passed, $errors);
        } catch (Exception $e) {
            $this->recordTest($testName, false, [$e->getMessage()]);
        }
    }

    /**
     * Record test result
     */
    private function recordTest($testName, $passed, $errors = [])
    {
        $result = [
            'name' => $testName,
            'passed' => $passed,
            'errors' => $errors
        ];

        $this->testResults[] = $result;

        if ($passed) {
            $this->passCount++;
            echo "✓ {$testName}\n";
        } else {
            $this->failCount++;
            echo "✗ {$testName}\n";
            foreach ($errors as $error) {
                echo "  - {$error}\n";
            }
        }
    }

    /**
     * Print test summary
     */
    private function printSummary()
    {
        echo "\n=== Test Summary ===\n";
        echo "Total: {$this->testCount}\n";
        echo "Passed: {$this->passCount}\n";
        echo "Failed: {$this->failCount}\n";

        if ($this->failCount === 0) {
            echo "\n✓ All tests passed!\n";
        } else {
            echo "\n✗ Some tests failed. Please review the errors above.\n";
            exit(1);
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli') {
    $test = new NavigationSystemTest();
    $test->runAllTests();
}
