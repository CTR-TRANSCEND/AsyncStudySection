<?php
declare(strict_types=1);
// Navigation system helpers extracted from functions.php (SPEC-UIX-002 Milestone 2)

require_once __DIR__ . '/sanitize_enhanced.php';

/**
 * Get unread discussion count for a reviewer
 * SPEC-UIX-002: Extract unread count logic from header.php to reusable function
 *
 * @param int $userId User ID to check
 * @return int Number of discussions with unread messages
 */
function getUnreadDiscussionCount($userId) {
    // Initialize cache if not exists
    if (!isset($GLOBALS['_navigationCache'])) {
        $GLOBALS['_navigationCache'] = [];
    }

    // Check per-request cache
    $cacheKey = "unread_count_{$userId}";
    if (isset($GLOBALS['_navigationCache'][$cacheKey])) {
        return $GLOBALS['_navigationCache'][$cacheKey];
    }

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT dm.id) as unread_count
        FROM discussion_messages dm
        INNER JOIN assignments ass ON dm.application_id = ass.application_id
        LEFT JOIN discussion_message_reads dmr ON dm.id = dmr.message_id AND dmr.user_id = ?
        WHERE ass.reviewer_id = ?
        AND dm.user_id != ?
        AND dm.is_deleted = FALSE
        AND dmr.id IS NULL
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $row = $stmt->fetch();
    $count = $row ? (int) $row['unread_count'] : 0;

    // Cache result for this request
    $GLOBALS['_navigationCache'][$cacheKey] = $count;

    return $count;
}

/**
 * Clear navigation cache (useful for testing or after updates)
 */
function clearNavigationCache() {
    global $_navigationCache;
    $_navigationCache = [];
}

/**
 * Get navigation items for a specific role
 * SPEC-UIX-002: Centralized navigation structure for consistency
 *
 * @param string $role Role name ('admin' or 'reviewer')
 * @return array Navigation structure with accessibility attributes
 */
function getNavigationItems($role) {
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';

    if ($role === 'admin') {
        return [
            [
                'title' => 'Dashboard',
                'url' => $baseUrl . '/admin/dashboard.php',
                'icon' => 'dashboard',
            ],
            [
                'title' => 'Grant Types',
                'url' => $baseUrl . '/admin/grant_types.php',
                'icon' => 'grants',
            ],
            [
                'title' => 'Study Sections',
                'url' => $baseUrl . '/admin/study_sections.php',
                'icon' => 'sections',
            ],
            [
                'title' => 'Manage Applications',
                'icon' => 'applications',
                'aria_label' => 'Applications menu',
                'children' => [
                    [
                        'title' => 'All Applications',
                        'url' => $baseUrl . '/admin/manage_applications.php',
                    ],
                    [
                        'title' => 'Upload Reviews',
                        'url' => $baseUrl . '/admin/upload_review.php',
                    ],
                ],
            ],
            [
                'title' => 'Users',
                'url' => $baseUrl . '/admin/users.php',
                'icon' => 'users',
            ],
        ];
    } elseif ($role === 'reviewer') {
        return [
            [
                'title' => 'Dashboard',
                'url' => $baseUrl . '/reviewer/dashboard.php',
                'icon' => 'dashboard',
            ],
            [
                'title' => 'Discussions',
                'url' => $baseUrl . '/reviewer/discussions.php',
                'icon' => 'discussions',
                'badge' => 'unread', // Will be populated dynamically
            ],
        ];
    }

    return [];
}

/**
 * Render navigation HTML with accessibility attributes
 * SPEC-UIX-002: Extract navigation rendering from header.php
 *
 * @param string $role Role name ('admin' or 'reviewer')
 * @param int|null $userId User ID for dynamic badges (optional)
 * @return string HTML navigation markup
 */
function renderNavigation($role, $userId = null) {
    $items = getNavigationItems($role);

    if (empty($items)) {
        return '';
    }

    $html = '<nav class="nav" id="main-nav" aria-label="Main navigation">';

    foreach ($items as $item) {
        if (isset($item['children'])) {
            // Dropdown menu
            $dropdownId = sanitize($item['title']) . '-menu-toggle';
            $dropdownMenuId = sanitize($item['title']) . '-menu-dropdown';

            $html .= sprintf(
                '<div class="nav-dropdown">' .
                '<button class="nav-link nav-dropdown-toggle" type="button" ' .
                'aria-haspopup="true" aria-expanded="false" id="%s" aria-controls="%s">%s ' .
                '<span class="nav-dropdown-caret" aria-hidden="true">▼</span></button>',
                $dropdownId,
                $dropdownMenuId,
                escape($item['title'])
            );

            $html .= sprintf(
                '<div class="nav-dropdown-menu" role="menu" aria-labelledby="%s" id="%s">',
                $dropdownId,
                $dropdownMenuId
            );

            foreach ($item['children'] as $child) {
                $html .= sprintf(
                    '<a href="%s" role="menuitem">%s</a>',
                    escape($child['url']),
                    escape($child['title'])
                );
            }

            $html .= '</div></div>';
        } else {
            // Regular link
            $badgeHtml = '';

            // Add unread badge for Discussions
            if (isset($item['badge']) && $item['badge'] === 'unread' && $userId) {
                $unreadCount = getUnreadDiscussionCount($userId);
                if ($unreadCount > 0) {
                    $badgeHtml = sprintf(
                        ' <span class="badge badge-danger" aria-label="%d unread messages">%d</span>',
                        $unreadCount,
                        $unreadCount
                    );
                }
            }

            $html .= sprintf(
                '<a href="%s" class="nav-link">%s%s</a>',
                escape($item['url']),
                escape($item['title']),
                $badgeHtml
            );
        }
    }

    $html .= '</nav>';

    return $html;
}
