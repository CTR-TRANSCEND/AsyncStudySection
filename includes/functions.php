<?php
declare(strict_types=1);
// Helper functions for the Grant Review System

require_once __DIR__ . '/sanitize_enhanced.php';
require_once __DIR__ . '/upload_helpers.php';
require_once __DIR__ . '/navigation_helpers.php';

function safeUrl($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    if (strpos($url, '/') === 0) {
        return $url;
    }

    return '';
}

function getInstitutionLabel() {
    $parts = [];
    if (defined('INSTITUTION_NAME') && INSTITUTION_NAME !== '') {
        $parts[] = INSTITUTION_NAME;
    }
    if (defined('UNIT_NAME') && UNIT_NAME !== '') {
        $parts[] = UNIT_NAME;
    }
    return implode(' | ', $parts);
}

function getInstitutionIconUrl() {
    if (!defined('INSTITUTION_ICON_URL') || INSTITUTION_ICON_URL === '') {
        return '';
    }
    return safeUrl(INSTITUTION_ICON_URL);
}

/**
 * Get or create CSRF token
 */
if (!function_exists('getCsrfToken')) {
function getCsrfToken() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }

    return $_SESSION['csrf_token'];
}
}

/**
 * Render CSRF hidden field
 */
if (!function_exists('csrfField')) {
function csrfField() {
    $token = getCsrfToken();
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . escape($token) . '">';
}
}

/**
 * Verify CSRF token on POST
 */
if (!function_exists('verifyCsrfToken')) {
function verifyCsrfToken() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return 'Session not initialized.';
    }

    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!is_string($token) || $token === '') {
        return 'Invalid session token.';
    }

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        return 'Invalid session token.';
    }

    return null;
}
}

/**
 * Log audit trail
 */
function logAudit($table, $recordId, $field, $oldValue, $newValue, $actionType = 'update') {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        INSERT INTO audit_log (table_name, record_id, field_name, old_value, new_value, changed_by, action_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $changedBy = Auth::getUserId() ?? 0;
    try {
        $stmt->execute([
            $table,
            $recordId,
            $field,
            $oldValue,
            $newValue,
            $changedBy,
            $actionType,
        ]);
    } catch (\Exception $e) {
        error_log('Failed to write audit log: ' . $e->getMessage());
    }
}

/**
 * Get anonymous label for reviewer on specific application
 */
function getAnonymousLabel($applicationId, $reviewerId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT anonymous_label FROM assignments WHERE application_id = ? AND reviewer_id = ?");
    $stmt->execute([$applicationId, $reviewerId]);
    $result = $stmt->fetch();
    return $result ? $result['anonymous_label'] : null;
}

function indexToLetters($index) {
    $letters = '';
    $index = (int) $index;
    while ($index >= 0) {
        $letters = chr(($index % 26) + 65) . $letters;
        $index = intdiv($index, 26) - 1;
    }
    return $letters;
}

function generateAnonymousLabel(array $existingLabels) {
    $used = [];
    foreach ($existingLabels as $label) {
        if (!is_string($label)) {
            continue;
        }
        if (preg_match('/^Reviewer\s+([A-Z]+)$/', $label, $matches)) {
            $used[$matches[1]] = true;
        }
    }

    for ($i = 0; $i < 702; $i++) {
        $candidate = indexToLetters($i);
        if (!isset($used[$candidate])) {
            return 'Reviewer ' . $candidate;
        }
    }

    return 'Reviewer ' . indexToLetters(count($used));
}

/**
 * Check if user has access to application
 */
function hasApplicationAccess($applicationId, $userId) {
    // Admins have access to everything
    if (Auth::isAdmin()) {
        return true;
    }

    // Reviewers have access if assigned
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT COUNT(*) as count
        FROM assignments ass
        JOIN applications a ON ass.application_id = a.id
        LEFT JOIN study_sections ss ON a.study_section_id = ss.id
        WHERE ass.application_id = ? AND ass.reviewer_id = ?
          AND (ss.is_active = TRUE OR ss.id IS NULL)
    ");
    $stmt->execute([$applicationId, $userId]);
    $result = $stmt->fetch();
    return $result['count'] > 0;
}

function getGrantTypes($includeInactive = false) {
    // CR6-26: Static cache to prevent re-querying on every call within the same request
    static $cache = [];
    $cacheKey = $includeInactive ? 'all' : 'active';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    $db = Database::getInstance()->getConnection();
    $sql = "SELECT * FROM grant_types";
    if (!$includeInactive) {
        $sql .= " WHERE is_active = TRUE";
    }
    $sql .= " ORDER BY name";
    $stmt = $db->query($sql);
    $cache[$cacheKey] = $stmt->fetchAll();
    return $cache[$cacheKey];
}

function getGrantTypeById($grantTypeId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM grant_types WHERE id = ?");
    $stmt->execute([$grantTypeId]);
    return $stmt->fetch();
}

function getGrantSections($grantTypeId, $includeInactive = false) {
    $db = Database::getInstance()->getConnection();
    $sql = "SELECT * FROM grant_sections WHERE grant_type_id = ?";
    if (!$includeInactive) {
        $sql .= " AND is_active = TRUE";
    }
    $sql .= " ORDER BY display_order, id";
    $stmt = $db->prepare($sql);
    $stmt->execute([$grantTypeId]);
    return $stmt->fetchAll();
}

function getStudySections($includeInactive = false) {
    // CR6-26: Static cache to prevent re-querying on every call within the same request
    static $cache = [];
    $cacheKey = $includeInactive ? 'all' : 'active';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    $db = Database::getInstance()->getConnection();
    $sql = "
        SELECT ss.*,
               COALESCE(GROUP_CONCAT(DISTINCT gt.name ORDER BY gt.name SEPARATOR ', '), '') as grant_type_names
        FROM study_sections ss
        LEFT JOIN study_section_grant_types ssgt ON ssgt.study_section_id = ss.id
        LEFT JOIN grant_types gt ON gt.id = ssgt.grant_type_id
    ";
    if (!$includeInactive) {
        $sql .= " WHERE ss.is_active = TRUE";
    }
    $sql .= " GROUP BY ss.id ORDER BY ss.name";
    $stmt = $db->query($sql);
    $cache[$cacheKey] = $stmt->fetchAll();
    return $cache[$cacheKey];
}

function getStudySectionById($studySectionId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT ss.*,
               COALESCE(GROUP_CONCAT(DISTINCT gt.name ORDER BY gt.name SEPARATOR ', '), '') as grant_type_names
        FROM study_sections ss
        LEFT JOIN study_section_grant_types ssgt ON ssgt.study_section_id = ss.id
        LEFT JOIN grant_types gt ON gt.id = ssgt.grant_type_id
        WHERE ss.id = ?
        GROUP BY ss.id
    ");
    $stmt->execute([$studySectionId]);
    return $stmt->fetch();
}

function getStudySectionGrantTypeIds($studySectionId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT grant_type_id
        FROM study_section_grant_types
        WHERE study_section_id = ?
        ORDER BY grant_type_id
    ");
    $stmt->execute([$studySectionId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function getStudySectionReviewers($studySectionId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.email
        FROM study_section_reviewers ssr
        JOIN users u ON ssr.reviewer_id = u.id
        WHERE ssr.study_section_id = ?
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute([$studySectionId]);
    return $stmt->fetchAll();
}

function getApplicationGrantTypeId($applicationId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT a.grant_type_id, a.grant_type
        FROM applications a
        WHERE a.id = ?
    ");
    $stmt->execute([$applicationId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (!empty($row['grant_type_id'])) {
        return (int) $row['grant_type_id'];
    }

    $grantTypeName = trim((string) ($row['grant_type'] ?? ''));
    if ($grantTypeName === '') {
        return null;
    }

    $stmt = $db->prepare("SELECT id FROM grant_types WHERE name = ? LIMIT 1");
    $stmt->execute([$grantTypeName]);
    $grantTypeId = $stmt->fetchColumn();
    if (!$grantTypeId) {
        if (strcasecmp($grantTypeName, 'Pilot') === 0) {
            $grantTypeName = 'TRANSCEND Pilot';
        } elseif (strcasecmp($grantTypeName, 'Developmental') === 0) {
            $grantTypeName = 'TRANSCEND Developmental';
        }
        $stmt->execute([$grantTypeName]);
        $grantTypeId = $stmt->fetchColumn();
    }
    return $grantTypeId ? (int) $grantTypeId : null;
}

/**
 * Fetch grant type info and its sections for a given application in a single JOIN query.
 *
 * Returns an array with keys:
 *   'grant_type_id'   => int|null
 *   'grant_type_name' => string|null
 *   'sections'        => array  (rows from grant_sections, may be empty)
 *
 * This replaces the three-call pattern:
 *   getApplicationGrantTypeId() + getGrantSections() + getGrantTypeById()
 */
function getApplicationGrantTypeWithSections(int $applicationId, bool $includeInactiveSections = false): array {
    $db = Database::getInstance()->getConnection();

    // First resolve the grant_type_id using the same logic as getApplicationGrantTypeId().
    // We do this in one step: fetch application row and resolve by name if needed.
    $stmt = $db->prepare("SELECT grant_type_id, grant_type FROM applications WHERE id = ?");
    $stmt->execute([$applicationId]);
    $appRow = $stmt->fetch();

    if (!$appRow) {
        return ['grant_type_id' => null, 'grant_type_name' => null, 'sections' => []];
    }

    $grantTypeId = null;
    if (!empty($appRow['grant_type_id'])) {
        $grantTypeId = (int) $appRow['grant_type_id'];
    } else {
        $grantTypeName = trim((string) ($appRow['grant_type'] ?? ''));
        if ($grantTypeName !== '') {
            $stmt = $db->prepare("SELECT id FROM grant_types WHERE name = ? LIMIT 1");
            $stmt->execute([$grantTypeName]);
            $grantTypeId = $stmt->fetchColumn() ?: null;
            if (!$grantTypeId) {
                if (strcasecmp($grantTypeName, 'Pilot') === 0) {
                    $grantTypeName = 'TRANSCEND Pilot';
                } elseif (strcasecmp($grantTypeName, 'Developmental') === 0) {
                    $grantTypeName = 'TRANSCEND Developmental';
                }
                $stmt->execute([$grantTypeName]);
                $grantTypeId = $stmt->fetchColumn() ?: null;
            }
            $grantTypeId = $grantTypeId ? (int) $grantTypeId : null;
        }
    }

    if (!$grantTypeId) {
        return ['grant_type_id' => null, 'grant_type_name' => null, 'sections' => []];
    }

    // Single JOIN query to get grant type name + sections together.
    $activeFilter = $includeInactiveSections ? '' : ' AND gs.is_active = TRUE';
    $stmt = $db->prepare("
        SELECT gt.id AS grant_type_id,
               gt.name AS grant_type_name,
               gs.id AS section_id,
               gs.name AS section_name,
               gs.description AS section_description,
               gs.display_order,
               gs.is_active
        FROM grant_types gt
        LEFT JOIN grant_sections gs
               ON gs.grant_type_id = gt.id{$activeFilter}
        WHERE gt.id = ?
        ORDER BY gs.display_order, gs.id
    ");
    $stmt->execute([$grantTypeId]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return ['grant_type_id' => $grantTypeId, 'grant_type_name' => null, 'sections' => []];
    }

    $grantTypeName = $rows[0]['grant_type_name'];
    $sections = [];
    foreach ($rows as $row) {
        if ($row['section_id'] !== null) {
            $sections[] = [
                'id'           => $row['section_id'],
                'name'         => $row['section_name'],
                'description'  => $row['section_description'],
                'display_order' => $row['display_order'],
                'is_active'    => $row['is_active'],
                'grant_type_id' => $grantTypeId,
            ];
        }
    }

    return [
        'grant_type_id'   => $grantTypeId,
        'grant_type_name' => $grantTypeName,
        'sections'        => $sections,
    ];
}

function getReviewSectionScores($reviewId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT * FROM review_section_scores
        WHERE review_id = ?
    ");
    $stmt->execute([$reviewId]);
    $rows = $stmt->fetchAll();
    $scores = [];
    foreach ($rows as $row) {
        $scores[$row['grant_section_id']] = $row;
    }
    return $scores;
}

function getLegacyCriteriaScores($reviewId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM review_criteria_scores WHERE review_id = ? ORDER BY id");
    $stmt->execute([$reviewId]);
    return $stmt->fetchAll();
}

/**
 * Format timestamp for display
 */
function formatDateTime($timestamp) {
    if (!$timestamp) return 'N/A';
    return date('M d, Y g:i A', strtotime($timestamp));
}

/**
 * Format date for display
 */
function formatDate($date) {
    if (!$date) return 'N/A';
    return date('M d, Y', strtotime($date));
}

// Upload helpers (generateRandomString, generateStoredFilename, detectMimeType, validateDocxUpload)
// are in includes/upload_helpers.php (loaded above)

/**
 * Get score label
 */
function getScoreLabel($score) {
    $labels = [
        1 => 'Exceptional',
        2 => 'Outstanding',
        3 => 'Excellent',
        4 => 'Very Good',
        5 => 'Good',
        6 => 'Satisfactory',
        7 => 'Fair',
        8 => 'Marginal',
        9 => 'Poor'
    ];
    return $labels[$score] ?? 'Unknown';
}

/**
 * Get score color class for styling
 */
function getScoreColorClass($score) {
    if ($score <= 2) return 'score-excellent';
    if ($score <= 4) return 'score-good';
    if ($score <= 6) return 'score-fair';
    return 'score-poor';
}

/**
 * Validate score range
 */
function isValidScore($score) {
    return is_numeric($score) && $score >= 1 && $score <= 9;
}

/**
 * Get all reviewers for an application
 */
function getApplicationReviewers($applicationId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT u.id, u.full_name, a.anonymous_label
        FROM assignments a
        JOIN users u ON a.reviewer_id = u.id
        WHERE a.application_id = ?
        ORDER BY a.anonymous_label
    ");
    $stmt->execute([$applicationId]);
    return $stmt->fetchAll();
}

/**
 * Get review statistics for an application
 */
// CR6-11: Accept optional $grantTypeId to skip a redundant getApplicationGrantTypeId() call
function getReviewStats($applicationId, ?int $grantTypeId = null) {
    $db = Database::getInstance()->getConnection();

    $stats = [];
    if ($grantTypeId === null) {
        $grantTypeId = getApplicationGrantTypeId($applicationId);
    }
    if ($grantTypeId) {
        $stmt = $db->prepare("SELECT COUNT(*) as review_count FROM reviews WHERE application_id = ?");
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch();
        $totalReviews = $row ? (int) $row['review_count'] : 0;

        $stmt = $db->prepare("
            SELECT gs.name, rss.score
            FROM review_section_scores rss
            JOIN reviews r ON rss.review_id = r.id
            JOIN grant_sections gs ON rss.grant_section_id = gs.id
            WHERE r.application_id = ? AND gs.is_scored = TRUE
        ");
        $stmt->execute([$applicationId]);
        $rows = $stmt->fetchAll();

        $sectionScores = [];
        foreach ($rows as $row) {
            if ($row['score'] === null) {
                continue;
            }
            if (!isset($sectionScores[$row['name']])) {
                $sectionScores[$row['name']] = [];
            }
            $sectionScores[$row['name']][] = (int) $row['score'];
        }

        foreach ($sectionScores as $sectionName => $scores) {
            if (!empty($scores)) {
                $stats[$sectionName] = [
                    'mean' => round(array_sum($scores) / count($scores), 2),
                    'min' => min($scores),
                    'max' => max($scores),
                    'count' => count($scores)
                ];
            }
        }

        if (!empty($stats)) {
            return $stats;
        }
    }

    // Legacy fallback — single batch query instead of N+1
    $stmt = $db->prepare("
        SELECT r.overall_impact_score, r.relevance_score, rcs.criterion_name, rcs.score as criterion_score
        FROM reviews r
        LEFT JOIN review_criteria_scores rcs ON rcs.review_id = r.id
        WHERE r.application_id = ?
    ");
    $stmt->execute([$applicationId]);
    $rows = $stmt->fetchAll();

    $overallImpact = [];
    $relevance = [];
    $criteriaStats = [];
    $seenReviewIds = [];

    foreach ($rows as $row) {
        if (!isset($seenReviewIds[$row['overall_impact_score'] . '_' . $row['relevance_score']])) {
            if ($row['overall_impact_score'] !== null) {
                $overallImpact[] = $row['overall_impact_score'];
            }
            if ($row['relevance_score'] !== null) {
                $relevance[] = $row['relevance_score'];
            }
        }
        if ($row['criterion_name'] !== null) {
            if (!isset($criteriaStats[$row['criterion_name']])) {
                $criteriaStats[$row['criterion_name']] = [];
            }
            $criteriaStats[$row['criterion_name']][] = $row['criterion_score'];
        }
    }

    $totalReviews = count(array_unique(array_merge($overallImpact, $relevance)));

    if (!empty($overallImpact)) {
        $stats['Overall Impact'] = [
            'mean' => round(array_sum($overallImpact) / count($overallImpact), 2),
            'min' => min($overallImpact),
            'max' => max($overallImpact),
            'count' => count($overallImpact)
        ];
    }

    if (!empty($relevance)) {
        $stats['Relevance to RFA'] = [
            'mean' => round(array_sum($relevance) / count($relevance), 2),
            'min' => min($relevance),
            'max' => max($relevance),
            'count' => count($relevance)
        ];
    }

    foreach ($criteriaStats as $criterionName => $scores) {
        if (!empty($scores)) {
            $stats[$criterionName] = [
                'mean' => round(array_sum($scores) / count($scores), 2),
                'min' => min($scores),
                'max' => max($scores),
                'count' => count($scores)
            ];
        }
    }

    return $stats;
}

// Navigation functions are in includes/navigation_helpers.php (loaded above)
