<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();

// CR6-31: Consolidate 5 separate COUNT queries into one multi-subquery statement
$stmt = $db->prepare("
    SELECT
        (SELECT COUNT(*) FROM applications) as total_applications,
        (SELECT COUNT(*) FROM users WHERE role = 'reviewer' AND is_active = TRUE) as total_reviewers,
        (SELECT COUNT(*) FROM reviews) as total_reviews,
        (SELECT COUNT(*) FROM applications WHERE status = 'pending') as pending_applications,
        (SELECT COUNT(*) FROM study_sections WHERE is_active = TRUE) as active_study_sections
");
$stmt->execute();
$counts = $stmt->fetch();

$stats = [];
$stats['total_applications']  = $counts ? (int) $counts['total_applications']  : 0;
$stats['total_reviewers']     = $counts ? (int) $counts['total_reviewers']      : 0;
$stats['total_reviews']       = $counts ? (int) $counts['total_reviews']        : 0;
$stats['pending_applications']= $counts ? (int) $counts['pending_applications'] : 0;
$stats['active_study_sections']= $counts ? (int) $counts['active_study_sections']: 0;

// Recent applications
$stmt = $db->prepare("
    SELECT a.*, ss.name as study_section_name, COALESCE(gt.name, a.grant_type) as grant_type_name, COUNT(DISTINCT r.id) as review_count
    FROM applications a
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    LEFT JOIN grant_types gt ON gt.id = a.grant_type_id
    LEFT JOIN reviews r ON a.id = r.application_id
    GROUP BY a.id
    ORDER BY a.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recentApplications = $stmt->fetchAll();

// Study section summary
$stmt = $db->prepare("
    SELECT ss.id, ss.name, ss.is_active,
           COUNT(DISTINCT a.id) as application_count,
           COUNT(DISTINCT r.id) as review_count
    FROM study_sections ss
    LEFT JOIN applications a ON a.study_section_id = ss.id
    LEFT JOIN reviews r ON r.application_id = a.id
    GROUP BY ss.id
    ORDER BY ss.is_active DESC, ss.name
");
$stmt->execute();
$studySectionStats = $stmt->fetchAll();

// Recent activity (audit log)
$stmt = $db->prepare("
    SELECT al.*, u.full_name as user_name
    FROM audit_log al
    JOIN users u ON al.changed_by = u.id
    ORDER BY al.changed_at DESC
    LIMIT 10
");
$stmt->execute();
$recentActivity = $stmt->fetchAll();

$pageTitle = 'Admin Dashboard';
require_once '../includes/header.php';
?>

<!-- Dashboard Header with Actions -->
<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 class="mb-1">Admin Dashboard</h1>
        <p class="text-muted">Welcome back! Here's what's happening with your grant review system.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="upload.php" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-right: 4px;">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="17 8 12 3 7 8"></polyline>
                <line x1="12" y1="3" x2="12" y2="15"></line>
            </svg>
            Upload Application
        </a>
        <a href="users.php" class="btn btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-right: 4px;">
                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="8.5" cy="7" r="4"></circle>
                <line x1="20" y1="8" x2="20" y2="14"></line>
                <line x1="23" y1="11" x2="17" y2="11"></line>
            </svg>
            Add User
        </a>
    </div>
</div>

<!-- Statistics Cards with Enhanced Design -->
<div class="grid grid-stats mb-4">
    <a class="stat-link" href="applications.php" aria-label="View all applications">
        <div class="stat-card">
            <div class="stat-label">Total Applications</div>
            <div class="stat-value"><?php echo number_format($stats['total_applications']); ?></div>
            <div class="stat-meta">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
                View applications
            </div>
        </div>
    </a>
    <a class="stat-link" href="users.php" aria-label="View all reviewers">
        <div class="stat-card">
            <div class="stat-label">Active Reviewers</div>
            <div class="stat-value"><?php echo number_format($stats['total_reviewers']); ?></div>
            <div class="stat-meta">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                Manage users
            </div>
        </div>
    </a>
    <a class="stat-link" href="applications.php" aria-label="Browse reviews by application">
        <div class="stat-card">
            <div class="stat-label">Total Reviews</div>
            <div class="stat-value"><?php echo number_format($stats['total_reviews']); ?></div>
            <div class="stat-meta">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                    <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path>
                </svg>
                Browse reviews
            </div>
        </div>
    </a>
    <a class="stat-link" href="applications.php?status=pending" aria-label="View pending applications">
        <div class="stat-card" style="border-top-color: var(--warning-color);">
            <div class="stat-label">Pending Applications</div>
            <div class="stat-value"><?php echo number_format($stats['pending_applications']); ?></div>
            <div class="stat-meta">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                Review queue
            </div>
        </div>
    </a>
    <a class="stat-link" href="study_sections.php" aria-label="Manage study sections">
        <div class="stat-card">
            <div class="stat-label">Active Study Sections</div>
            <div class="stat-value"><?php echo number_format($stats['active_study_sections']); ?></div>
            <div class="stat-meta">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                    <rect x="3" y="3" width="7" height="7"></rect>
                    <rect x="14" y="3" width="7" height="7"></rect>
                    <rect x="14" y="14" width="7" height="7"></rect>
                    <rect x="3" y="14" width="7" height="7"></rect>
                </svg>
                Manage sections
            </div>
        </div>
    </a>
</div>

<div class="grid grid-3-1">
    <!-- Recent Applications -->
    <div class="card">
        <div class="card-header">Recent Applications</div>
        <div class="card-body">
            <?php if (empty($recentApplications)): ?>
                <p class="text-muted">No applications yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Study Section</th>
                            <th>Type</th>
                            <th>Reviews</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentApplications as $app): ?>
                            <tr>
                                <td>
                                    <a href="application_detail.php?id=<?php echo $app['id']; ?>">
                                        <?php echo escape($app['applicant_name']); ?>
                                    </a>
                                </td>
                                <td><?php echo escape($app['study_section_name'] ?? 'N/A'); ?></td>
                                <td><span class="badge badge-primary"><?php echo escape($app['grant_type_name'] ?? $app['grant_type']); ?></span></td>
                                <td><?php echo $app['review_count']; ?></td>
                                <td><span class="badge badge-<?php echo $app['status'] === 'completed' ? 'success' : 'warning'; ?>"><?php echo escape($app['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="card">
        <div class="card-header">Recent Activity</div>
        <div class="card-body">
            <?php if (empty($recentActivity)): ?>
                <p class="text-muted">No recent activity.</p>
            <?php else: ?>
                <div style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($recentActivity as $activity): ?>
                        <div class="mb-2 p-2" style="border-left: 3px solid var(--primary-color); background: var(--light-bg);">
                            <div class="d-flex justify-between align-center">
                                <strong><?php echo escape($activity['user_name']); ?></strong>
                                <span class="text-muted" style="font-size: 0.875rem;">
                                    <?php echo formatDateTime($activity['changed_at']); ?>
                                </span>
                            </div>
                            <div class="text-muted" style="font-size: 0.875rem;">
                                <?php echo ucfirst($activity['action_type']); ?> in <?php echo escape($activity['table_name']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($studySectionStats)): ?>
    <div class="card mt-4">
        <div class="card-header">
            <a href="study_sections.php">Study Sections Overview</a>
        </div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Study Section</th>
                        <th>Status</th>
                        <th>Applications</th>
                        <th>Reviews</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($studySectionStats as $section): ?>
                        <tr>
                            <td>
                                <a class="table-link" href="study_sections.php#study-section-<?php echo $section['id']; ?>">
                                    <?php echo escape($section['name']); ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $section['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $section['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td><?php echo $section['application_count']; ?></td>
                            <td><?php echo $section['review_count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
