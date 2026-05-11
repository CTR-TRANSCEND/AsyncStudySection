<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireReviewer();

$db = Database::getInstance()->getConnection();
$userId = Auth::getUserId();

// Get assigned applications
$stmt = $db->prepare("
    SELECT
        a.*,
        ss.name as study_section_name,
        ss.id as study_section_id,
        COALESCE(gt.name, a.grant_type) as grant_type_name,
        ass.anonymous_label,
        COUNT(DISTINCT r.id) as total_reviews,
        MAX(CASE WHEN r.reviewer_id = ? THEN 1 ELSE 0 END) as has_reviewed
    FROM assignments ass
    JOIN applications a ON ass.application_id = a.id
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    LEFT JOIN grant_types gt ON gt.id = a.grant_type_id
    LEFT JOIN reviews r ON a.id = r.application_id
    WHERE ass.reviewer_id = ?
      AND (ss.is_active = TRUE OR ss.id IS NULL)
    GROUP BY a.id
    ORDER BY a.created_at DESC
");
$stmt->execute([$userId, $userId]);
$applications = $stmt->fetchAll();

// Get statistics
$stats = [];
$stats['total_assigned'] = count($applications);
$stats['reviewed'] = array_sum(array_column($applications, 'has_reviewed'));
$stats['pending'] = $stats['total_assigned'] - $stats['reviewed'];

// Get unread messages count
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT dm.id) as unread_count
    FROM assignments ass
    JOIN discussion_messages dm ON ass.application_id = dm.application_id
    WHERE ass.reviewer_id = ?
    AND dm.user_id != ?
    AND dm.created_at > COALESCE(
        (SELECT MAX(created_at) FROM discussion_messages WHERE user_id = ? AND application_id = dm.application_id),
        '2000-01-01'
    )
");
$stmt->execute([$userId, $userId, $userId]);
$row = $stmt->fetch();
$stats['unread_messages'] = $row ? (int) $row['unread_count'] : 0;

// Study sections assigned to reviewer
$stmt = $db->prepare("
    SELECT ss.*,
           COALESCE(GROUP_CONCAT(DISTINCT gt.name ORDER BY gt.name SEPARATOR ', '), '') as grant_type_names
    FROM study_section_reviewers ssr
    JOIN study_sections ss ON ssr.study_section_id = ss.id
    LEFT JOIN study_section_grant_types ssgt ON ssgt.study_section_id = ss.id
    LEFT JOIN grant_types gt ON gt.id = ssgt.grant_type_id
    WHERE ssr.reviewer_id = ? AND ss.is_active = TRUE
    GROUP BY ss.id
    ORDER BY ss.name
");
$stmt->execute([$userId]);
$studySections = $stmt->fetchAll();

// Unread messages by study section
$stmt = $db->prepare("
    SELECT a.study_section_id, COUNT(DISTINCT dm.id) as unread_count
    FROM assignments ass
    JOIN applications a ON ass.application_id = a.id
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    LEFT JOIN discussion_messages dm ON a.id = dm.application_id
    WHERE ass.reviewer_id = ?
      AND (ss.is_active = TRUE OR ss.id IS NULL)
      AND dm.user_id != ?
      AND dm.created_at > COALESCE(
          (SELECT MAX(created_at) FROM discussion_messages WHERE user_id = ? AND application_id = a.id),
          '2000-01-01'
      )
    GROUP BY a.study_section_id
");
$stmt->execute([$userId, $userId, $userId]);
$unreadBySection = [];
foreach ($stmt->fetchAll() as $row) {
    $unreadBySection[$row['study_section_id']] = $row['unread_count'];
}

$applicationsBySection = [];
foreach ($applications as $application) {
    $sectionKey = $application['study_section_id'] ?? 'none';
    if (!isset($applicationsBySection[$sectionKey])) {
        $applicationsBySection[$sectionKey] = [
            'study_section_name' => $application['study_section_name'] ?? 'Unassigned',
            'grant_type_name' => $application['grant_type_name'] ?? $application['grant_type'],
            'applications' => []
        ];
    }
    $applicationsBySection[$sectionKey]['applications'][] = $application;
}

$pageTitle = 'Reviewer Dashboard';
require_once '../includes/header.php';
?>

<!-- Reviewer Dashboard Header -->
<div class="mb-4">
    <h1 class="mb-1">Reviewer Dashboard</h1>
    <p class="text-muted">Welcome, <strong><?php echo escape(Auth::getFullName()); ?></strong>! Your identity is anonymous to other reviewers.</p>
</div>

<!-- Enhanced Statistics with Icons and Better Design -->
<div class="grid grid-stats mb-4">
    <a class="stat-link" href="#assigned-applications" aria-label="View assigned applications">
        <div class="stat-card">
            <div class="stat-label">Assigned Applications</div>
            <div class="stat-value"><?php echo number_format($stats['total_assigned']); ?></div>
            <div class="stat-meta">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
                View assignments
            </div>
        </div>
    </a>
    <a class="stat-link" href="#assigned-applications" aria-label="View completed reviews">
        <div class="stat-card" style="border-top-color: var(--success-color);">
            <div class="stat-label">Completed Reviews</div>
            <div class="stat-value"><?php echo number_format($stats['reviewed']); ?></div>
            <div class="stat-meta">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                See completed
            </div>
        </div>
    </a>
    <a class="stat-link" href="#assigned-applications" aria-label="View pending reviews">
        <div class="stat-card" style="border-top-color: var(--warning-color);">
            <div class="stat-label">Pending Reviews</div>
            <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
            <div class="stat-meta">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                See pending
            </div>
        </div>
    </a>
    <a class="stat-link" href="discussions.php" aria-label="View unread discussions">
        <div class="stat-card" style="border-top-color: var(--danger-color);">
            <div class="stat-label">Unread Messages</div>
            <div class="stat-value"><?php echo number_format($stats['unread_messages']); ?></div>
            <div class="stat-meta">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                Open discussions
            </div>
        </div>
    </a>
</div>

<!-- Assigned Study Sections -->
<div class="card mb-4">
    <div class="card-header">Assigned Study Sections</div>
    <div class="card-body">
        <?php if (empty($studySections)): ?>
            <p class="text-muted">No study sections assigned yet. Please contact the administrator.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Study Section</th>
                        <th>Grant Types</th>
                        <th>Status</th>
                        <th>Unread Messages</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($studySections as $section): ?>
                        <tr>
                            <td><?php echo escape($section['name']); ?></td>
                            <td><?php echo escape($section['grant_type_names'] !== '' ? $section['grant_type_names'] : '—'); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $section['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $section['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td><?php echo $unreadBySection[$section['id']] ?? 0; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Assigned Applications -->
<div id="assigned-applications"></div>
<?php if (empty($applicationsBySection)): ?>
    <div class="card">
        <div class="card-body">
            <p class="text-muted">No applications assigned yet. Please contact the administrator.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($applicationsBySection as $sectionGroup): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-between align-center">
                <span>
                    <?php echo escape($sectionGroup['study_section_name']); ?>
                    <?php if ($sectionGroup['grant_type_name']): ?>
                        <span class="badge badge-primary" style="margin-left: 0.5rem;">
                            <?php echo escape($sectionGroup['grant_type_name']); ?>
                        </span>
                    <?php endif; ?>
                </span>
                <span class="text-muted"><?php echo count($sectionGroup['applications']); ?> application(s)</span>
            </div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Title</th>
                            <th>Your Role</th>
                            <th>Total Reviews</th>
                            <th>Your Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sectionGroup['applications'] as $app): ?>
                            <tr>
                                <td><?php echo escape($app['applicant_name']); ?></td>
                                <td><?php echo escape(substr($app['application_title'], 0, 50)) . (strlen($app['application_title']) > 50 ? '...' : ''); ?></td>
                                <td><span class="badge badge-secondary"><?php echo escape($app['anonymous_label']); ?></span></td>
                                <td><?php echo $app['total_reviews']; ?></td>
                                <td>
                                    <?php if ($app['has_reviewed']): ?>
                                        <span class="badge badge-success">Reviewed</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="review_application.php?id=<?php echo $app['id']; ?>" class="btn btn-sm btn-primary">
                                        <?php echo $app['has_reviewed'] ? 'My Review' : 'Submit Review'; ?>
                                    </a>
                                    <a href="view_all_reviews.php?id=<?php echo $app['id']; ?>" class="btn btn-sm btn-outline">
                                        All Reviews (<?php echo $app['total_reviews']; ?>)
                                    </a>
                                    <a href="discussions.php?app_id=<?php echo $app['id']; ?>" class="btn btn-sm btn-secondary">
                                        Discussion
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
