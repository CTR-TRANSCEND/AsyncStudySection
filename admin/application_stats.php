<?php
declare(strict_types=1);
/**
 * Application Statistics Dashboard
 * SPEC-RPT-001: Reporting and Analytics System
 *
 * Displays comprehensive application statistics:
 * - Status breakdown (pending, in_review, completed)
 * - Score distribution with percentiles
 * - Applications by study section
 * - Applications by grant type
 * - Drill-down interface for detailed exploration
 *
 * @author SPEC-RPT-001 Implementation
 * @version 1.0.0
 * @created 2025-01-04
 */

require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/ReportSecurity.php';
require_once '../includes/AggregationEngine.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();
$security = new ReportSecurity($db);
$aggregation = new AggregationEngine();

// Check rate limit
$security->checkRateLimit(Auth::getUserId());

// Get filter parameters
$statusFilter = $_GET['status'] ?? '';
$studySectionFilter = $_GET['study_section_id'] ?? '';
$grantTypeFilter = $_GET['grant_type_id'] ?? '';

// Build WHERE clause
$where = ['1=1'];
$params = [];

if ($statusFilter) {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}
if ($studySectionFilter) {
    $where[] = 'a.study_section_id = ?';
    $params[] = $studySectionFilter;
}
if ($grantTypeFilter) {
    $where[] = 'a.grant_type_id = ?';
    $params[] = $grantTypeFilter;
}

$whereClause = implode(' AND ', $where);

// CR6-09: Fetch totalApps first so breakdown queries can compute percentage in PHP
// instead of embedding (SELECT COUNT(*) FROM applications) subquery in each
$stmt = $db->prepare("SELECT COUNT(*) as total FROM applications a WHERE $whereClause");
$stmt->execute($params);
$row = $stmt->fetch();
$totalApps = $row ? (int) $row['total'] : 0;

// Get status breakdown
$stmt = $db->prepare("
    SELECT
        a.status,
        COUNT(*) as count
    FROM applications a
    WHERE $whereClause
    GROUP BY a.status
    ORDER BY count DESC
");
$stmt->execute($params);
$statusBreakdownRaw = $stmt->fetchAll();
$statusBreakdown = [];
foreach ($statusBreakdownRaw as $row) {
    $row['percentage'] = $totalApps > 0 ? round($row['count'] * 100.0 / $totalApps, 1) : 0;
    $statusBreakdown[] = $row;
}

// Get study section breakdown
$stmt = $db->prepare("
    SELECT
        COALESCE(ss.name, 'Unassigned') as study_section,
        COUNT(*) as count
    FROM applications a
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    WHERE $whereClause
    GROUP BY a.study_section_id, ss.name
    ORDER BY count DESC
");
$stmt->execute($params);
$studySectionBreakdownRaw = $stmt->fetchAll();
$studySectionBreakdown = [];
foreach ($studySectionBreakdownRaw as $row) {
    $row['percentage'] = $totalApps > 0 ? round($row['count'] * 100.0 / $totalApps, 1) : 0;
    $studySectionBreakdown[] = $row;
}

// Get grant type breakdown
$stmt = $db->prepare("
    SELECT
        COALESCE(gt.name, a.grant_type, 'Unknown') as grant_type,
        COUNT(*) as count
    FROM applications a
    LEFT JOIN grant_types gt ON a.grant_type_id = gt.id
    WHERE $whereClause
    GROUP BY a.grant_type_id, a.grant_type, gt.name
    ORDER BY count DESC
");
$stmt->execute($params);
$grantTypeBreakdownRaw = $stmt->fetchAll();
$grantTypeBreakdown = [];
foreach ($grantTypeBreakdownRaw as $row) {
    $row['percentage'] = $totalApps > 0 ? round($row['count'] * 100.0 / $totalApps, 1) : 0;
    $grantTypeBreakdown[] = $row;
}

// Get score statistics for completed applications
$stmt = $db->prepare("
    SELECT AVG(avg_score) as overall_avg
    FROM (
        SELECT AVG(COALESCE(r.overall_impact_score, r.relevance_score)) as avg_score
        FROM reviews r
        JOIN applications a ON r.application_id = a.id
        WHERE r.is_final = TRUE
          AND (r.overall_impact_score IS NOT NULL OR r.relevance_score IS NOT NULL)
          AND $whereClause
        GROUP BY r.application_id
    ) app_scores
");
$stmt->execute($params);
$overallAvg = $stmt->fetch();

// Get study sections for filter
$studySections = getStudySections();

// Get grant types for filter
$grantTypes = getGrantTypes();

$pageTitle = 'Application Statistics Dashboard';
require_once '../includes/header.php';
?>

<div class="mb-4">
    <h1><?php echo escape($pageTitle); ?></h1>
    <p class="text-muted">Comprehensive statistics on grant applications</p>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="form-inline">
            <div class="form-group mr-3">
                <label for="status">Status:</label>
                <select name="status" id="status" class="form-control ml-2">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="in_review" <?php echo $statusFilter === 'in_review' ? 'selected' : ''; ?>>In Review</option>
                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            <div class="form-group mr-3">
                <label for="study_section_id">Study Section:</label>
                <select name="study_section_id" id="study_section_id" class="form-control ml-2">
                    <option value="">All Sections</option>
                    <?php foreach ($studySections as $section): ?>
                        <option value="<?php echo $section['id']; ?>"
                                <?php echo (int)$studySectionFilter === (int)$section['id'] ? 'selected' : ''; ?>>
                            <?php echo escape($section['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mr-3">
                <label for="grant_type_id">Grant Type:</label>
                <select name="grant_type_id" id="grant_type_id" class="form-control ml-2">
                    <option value="">All Types</option>
                    <?php foreach ($grantTypes as $type): ?>
                        <option value="<?php echo $type['id']; ?>"
                                <?php echo (int)$grantTypeFilter === (int)$type['id'] ? 'selected' : ''; ?>>
                            <?php echo escape($type['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="application_stats.php" class="btn btn-secondary">Clear Filters</a>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h5 class="card-title">Total Applications</h5>
                <h2 class="text-primary"><?php echo $totalApps; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h5 class="card-title">Overall Average Score</h5>
                <h2 class="text-success">
                    <?php
                    $avgValue = (is_array($overallAvg)
                        && isset($overallAvg['overall_avg'])
                        && is_numeric($overallAvg['overall_avg']))
                        ? (float) $overallAvg['overall_avg']
                        : null;
                    echo $avgValue !== null ? number_format($avgValue, 2) : 'N/A';
                    ?>
                </h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h5 class="card-title">Study Sections</h5>
                <h2 class="text-info"><?php echo count($studySections); ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h5 class="card-title">Grant Types</h5>
                <h2 class="text-warning"><?php echo count($grantTypes); ?></h2>
            </div>
        </div>
    </div>
</div>

<!-- Status Breakdown -->
<div class="card mb-4">
    <div class="card-header">
        <h3>Applications by Status</h3>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <canvas id="statusChart" height="250"></canvas>
            </div>
            <div class="col-md-6">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Count</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statusBreakdown as $status): ?>
                        <tr>
                            <td><strong><?php echo escape(ucfirst(str_replace('_', ' ', $status['status']))); ?></strong></td>
                            <td>
                                <a href="applications.php?status=<?php echo urlencode($status['status']); ?>">
                                    <?php echo $status['count']; ?>
                                </a>
                            </td>
                            <td><?php echo $status['percentage']; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Study Section Breakdown -->
<div class="card mb-4">
    <div class="card-header">
        <h3>Applications by Study Section</h3>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <canvas id="studySectionChart" height="250"></canvas>
            </div>
            <div class="col-md-6">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Study Section</th>
                            <th>Count</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studySectionBreakdown as $section): ?>
                        <tr>
                            <td><?php echo escape($section['study_section']); ?></td>
                            <td>
                                <a href="applications.php?study_section_id=<?php echo urlencode($section['study_section']); ?>">
                                    <?php echo $section['count']; ?>
                                </a>
                            </td>
                            <td><?php echo $section['percentage']; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Grant Type Breakdown -->
<div class="card mb-4">
    <div class="card-header">
        <h3>Applications by Grant Type</h3>
    </div>
    <div class="card-body">
        <canvas id="grantTypeChart" height="200"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // Status Distribution Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'pie',
        data: {
            labels: <?php echo json_encode(array_column($statusBreakdown, 'status'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($statusBreakdown, 'count'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
                backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    // Study Section Chart
    const studySectionCtx = document.getElementById('studySectionChart').getContext('2d');
    new Chart(studySectionCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($studySectionBreakdown, 'study_section'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            datasets: [{
                label: 'Applications',
                data: <?php echo json_encode(array_column($studySectionBreakdown, 'count'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.6)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    // Grant Type Chart
    const grantTypeCtx = document.getElementById('grantTypeChart').getContext('2d');
    new Chart(grantTypeCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($grantTypeBreakdown, 'grant_type'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($grantTypeBreakdown, 'count'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
                backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'right'
                }
            }
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>
