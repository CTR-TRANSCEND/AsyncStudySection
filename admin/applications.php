<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';
$csrfError = null;

// Handle toggle complete status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_complete']) && !$csrfError) {
    $appId = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;
    if ($appId > 0) {
        $stmt = $db->prepare("UPDATE applications SET is_complete = NOT is_complete WHERE id = ?");
        $stmt->execute([$appId]);
        logAudit('applications', $appId, 'is_complete', null, 'toggled', 'update');
        $message = 'Application status updated.';
    }
}

// Filters
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$studySectionFilter = $_GET['study_section'] ?? '';
$grantTypeFilter = $_GET['grant_type'] ?? '';

// Build WHERE clause
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(a.applicant_name LIKE ? OR a.grant_id LIKE ? OR a.application_title LIKE ?)';
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($statusFilter === 'complete') {
    $where[] = 'a.is_complete = TRUE';
} elseif ($statusFilter === 'incomplete') {
    $where[] = 'a.is_complete = FALSE';
} elseif ($statusFilter === 'pending') {
    $where[] = 'a.status = ?';
    $params[] = 'pending';
}

if ($studySectionFilter !== '') {
    $where[] = 'a.study_section_id = ?';
    $params[] = (int) $studySectionFilter;
}

if ($grantTypeFilter !== '') {
    $where[] = 'a.grant_type_id = ?';
    $params[] = (int) $grantTypeFilter;
}

$whereClause = implode(' AND ', $where);

// Pagination
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Get total count with filters
$countStmt = $db->prepare("SELECT COUNT(*) as total FROM applications a WHERE $whereClause");
$countStmt->execute($params);
$row = $countStmt->fetch();
$totalApplications = $row ? (int) $row['total'] : 0;
$totalPages = max(1, (int) ceil($totalApplications / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Get paginated applications with review counts
$stmt = $db->prepare("
    SELECT
        a.*,
        ss.name as study_section_name,
        COALESCE(gt.name, a.grant_type) as grant_type_name,
        COUNT(DISTINCT r.id) as review_count,
        COUNT(DISTINCT ass.id) as reviewer_count
    FROM applications a
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    LEFT JOIN grant_types gt ON gt.id = a.grant_type_id
    LEFT JOIN reviews r ON a.id = r.application_id
    LEFT JOIN assignments ass ON a.id = ass.application_id
    WHERE $whereClause
    GROUP BY a.id
    ORDER BY a.is_complete ASC, a.created_at DESC
    LIMIT " . (int) $perPage . " OFFSET " . (int) $offset . "
");
$stmt->execute($params);
$applications = $stmt->fetchAll();

// Get study sections and grant types for filter dropdowns
$studySections = $db->query("SELECT id, name FROM study_sections ORDER BY name")->fetchAll();
$grantTypes = $db->query("SELECT id, name FROM grant_types WHERE is_active = TRUE ORDER BY name")->fetchAll();

// Build query string for pagination links (preserve filters)
function buildQueryString($overrides = []) {
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }
    // Remove empty params
    $params = array_filter($params, function ($v) { return $v !== '' && $v !== null; });
    return $params ? '?' . http_build_query($params) : '';
}

$pageTitle = 'All Applications';
require_once '../includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1>All Applications</h1>
        <p class="text-muted" style="margin: 0.25rem 0 0;">
            <?php echo number_format($totalApplications); ?> application<?php echo $totalApplications !== 1 ? 's' : ''; ?>
            <?php if ($search !== '' || $statusFilter !== '' || $studySectionFilter !== '' || $grantTypeFilter !== ''): ?>
                (filtered)
            <?php endif; ?>
        </p>
    </div>
    <a href="upload_review.php" class="btn btn-primary">Upload New Review</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo escape($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo escape($error); ?></div>
<?php endif; ?>

<!-- Search & Filter Bar -->
<div class="card mb-4">
    <div class="card-body" style="padding: 1rem;">
        <form method="GET" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
            <div style="flex: 1; min-width: 200px;">
                <label for="search" style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--secondary-color); margin-bottom: 0.25rem;">Search</label>
                <input type="text" id="search" name="search" value="<?php echo escape($search); ?>"
                       placeholder="Applicant name, Grant ID, or Title..."
                       class="form-control" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--gray-300); border-radius: 6px; font-size: 0.9rem;">
            </div>
            <div style="min-width: 140px;">
                <label for="status" style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--secondary-color); margin-bottom: 0.25rem;">Status</label>
                <select id="status" name="status" class="form-control" style="padding: 0.5rem 0.75rem; border: 1px solid var(--gray-300); border-radius: 6px; font-size: 0.9rem;">
                    <option value="">All</option>
                    <option value="complete" <?php echo $statusFilter === 'complete' ? 'selected' : ''; ?>>Complete</option>
                    <option value="incomplete" <?php echo $statusFilter === 'incomplete' ? 'selected' : ''; ?>>Incomplete</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                </select>
            </div>
            <div style="min-width: 160px;">
                <label for="study_section" style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--secondary-color); margin-bottom: 0.25rem;">Study Section</label>
                <select id="study_section" name="study_section" class="form-control" style="padding: 0.5rem 0.75rem; border: 1px solid var(--gray-300); border-radius: 6px; font-size: 0.9rem;">
                    <option value="">All Sections</option>
                    <?php foreach ($studySections as $ss): ?>
                        <option value="<?php echo $ss['id']; ?>" <?php echo (int)$studySectionFilter === (int)$ss['id'] ? 'selected' : ''; ?>>
                            <?php echo escape($ss['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width: 160px;">
                <label for="grant_type" style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--secondary-color); margin-bottom: 0.25rem;">Grant Type</label>
                <select id="grant_type" name="grant_type" class="form-control" style="padding: 0.5rem 0.75rem; border: 1px solid var(--gray-300); border-radius: 6px; font-size: 0.9rem;">
                    <option value="">All Types</option>
                    <?php foreach ($grantTypes as $gt): ?>
                        <option value="<?php echo $gt['id']; ?>" <?php echo (int)$grantTypeFilter === (int)$gt['id'] ? 'selected' : ''; ?>>
                            <?php echo escape($gt['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn btn-primary btn-sm" style="padding: 0.5rem 1rem;">Filter</button>
                <?php if ($search !== '' || $statusFilter !== '' || $studySectionFilter !== '' || $grantTypeFilter !== ''): ?>
                    <a href="applications.php" class="btn btn-secondary btn-sm" style="padding: 0.5rem 1rem;">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Applications Table -->
<div class="card">
    <div class="card-body" style="overflow-x: auto;">
        <?php if (empty($applications)): ?>
            <p class="text-muted text-center" style="padding: 2rem 0;">
                <?php if ($search !== '' || $statusFilter !== '' || $studySectionFilter !== '' || $grantTypeFilter !== ''): ?>
                    No applications match your filters. <a href="applications.php">Clear filters</a>
                <?php else: ?>
                    No applications yet. <a href="upload_review.php">Upload a review report</a> to get started.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Grant ID</th>
                        <th>Applicant</th>
                        <th>Title</th>
                        <th>Study Section</th>
                        <th>Grant Type</th>
                        <th>Complete</th>
                        <th>Reviews</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($applications as $app): ?>
                        <tr class="<?php echo $app['is_complete'] ? 'row-complete' : ''; ?>">
                            <td style="font-family: monospace; font-size: 0.85rem;"><?php echo escape($app['grant_id'] ?? 'N/A'); ?></td>
                            <td><?php echo escape($app['applicant_name']); ?></td>
                            <td style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo escape($app['application_title']); ?>">
                                <?php echo escape($app['application_title']); ?>
                            </td>
                            <td><?php echo escape($app['study_section_name'] ?? 'N/A'); ?></td>
                            <td><span class="badge badge-primary"><?php echo escape($app['grant_type_name'] ?? $app['grant_type']); ?></span></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="application_id" value="<?php echo (int) $app['id']; ?>">
                                    <input type="hidden" name="page" value="<?php echo $page; ?>">
                                    <button
                                        type="submit"
                                        name="toggle_complete"
                                        class="btn btn-sm btn-<?php echo $app['is_complete'] ? 'success' : 'secondary'; ?>"
                                        onclick="return confirm('Toggle complete status? <?php echo $app['is_complete'] ? 'Reviewers will be able to edit again.' : 'Reviewers will not be able to edit.'; ?>')"
                                        title="<?php echo $app['is_complete'] ? 'Mark as Incomplete' : 'Mark as Complete'; ?>"
                                    >
                                        <?php echo $app['is_complete'] ? '✓ Complete' : 'Incomplete'; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <span style="font-weight: 600;"><?php echo $app['review_count']; ?></span>
                                <span class="text-muted" style="font-size: 0.8rem;">/ <?php echo $app['reviewer_count']; ?></span>
                            </td>
                            <td style="white-space: nowrap;">
                                <a href="application_detail.php?id=<?php echo (int) $app['id']; ?>" class="btn btn-sm btn-primary">Details</a>
                                <a href="admin_discussion.php?id=<?php echo (int) $app['id']; ?>" class="btn btn-sm btn-outline" title="Discussion">Chat</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="mt-3" aria-label="Application pagination">
                    <ul style="display: flex; list-style: none; padding: 0; justify-content: center; gap: 0.25rem; flex-wrap: wrap;">
                        <?php if ($page > 1): ?>
                            <li><a href="<?php echo buildQueryString(['page' => 1]); ?>" class="btn btn-sm btn-secondary" aria-label="First page">&laquo;</a></li>
                            <li><a href="<?php echo buildQueryString(['page' => $page - 1]); ?>" class="btn btn-sm btn-secondary" aria-label="Previous page">&lsaquo;</a></li>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1) $endPage = min($totalPages, $startPage + 4);
                        if ($endPage < $totalPages) $startPage = max(1, $endPage - 4);
                        ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php if ($i === $page): ?>
                                <li><span class="btn btn-sm btn-primary" aria-current="page"><?php echo $i; ?></span></li>
                            <?php else: ?>
                                <li><a href="<?php echo buildQueryString(['page' => $i]); ?>" class="btn btn-sm btn-secondary"><?php echo $i; ?></a></li>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <li><a href="<?php echo buildQueryString(['page' => $page + 1]); ?>" class="btn btn-sm btn-secondary" aria-label="Next page">&rsaquo;</a></li>
                            <li><a href="<?php echo buildQueryString(['page' => $totalPages]); ?>" class="btn btn-sm btn-secondary" aria-label="Last page">&raquo;</a></li>
                        <?php endif; ?>
                    </ul>
                    <p class="text-center text-muted mt-2" style="font-size: 0.85rem;">
                        Showing <?php echo number_format($offset + 1); ?>&ndash;<?php echo number_format(min($offset + $perPage, $totalApplications)); ?>
                        of <?php echo number_format($totalApplications); ?> applications
                    </p>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
