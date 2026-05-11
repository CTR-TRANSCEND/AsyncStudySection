<?php
declare(strict_types=1);
/**
 * Reviewer Analytics Dashboard
 * SPEC-RPT-001: Reporting and Analytics System
 *
 * Displays comprehensive reviewer performance metrics:
 * - Completion metrics (total reviews, completion time, on-time rate)
 * - Scoring metrics (average scores, distribution, consistency)
 * - Quality metrics (inter-rater reliability, comment quality)
 * - Comparative metrics (peer comparison, trends, rankings)
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
require_once '../includes/ReviewerAnalytics.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();
$security = new ReportSecurity($db);
$analytics = new ReviewerAnalytics($db);

// Check rate limit
$security->checkRateLimit(Auth::getUserId());

// Get reviewer ID from query parameter or show list
$reviewerId = isset($_GET['reviewer_id']) ? (int) $_GET['reviewer_id'] : null;

// Get all reviewers for the dropdown
$stmt = $db->query("
    SELECT u.id, u.full_name,
           COUNT(DISTINCT r.application_id) as review_count
    FROM users u
    LEFT JOIN reviews r ON u.id = r.reviewer_id AND r.is_final = TRUE
    WHERE u.role = 'reviewer'
    GROUP BY u.id, u.full_name
    ORDER BY u.last_name, u.first_name
");
$reviewers = $stmt->fetchAll();

$metrics = null;
$selectedReviewer = null;

if ($reviewerId) {
    try {
        $metrics = $analytics->calculateMetrics($reviewerId);

        // Get selected reviewer info
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$reviewerId]);
        $selectedReviewer = $stmt->fetch();

        // Log access
        $security->logAudit('view_reviewer_analytics', Auth::getUserId(), [
            'reviewer_id' => $reviewerId,
            'has_sufficient_data' => $metrics['has_sufficient_data']
        ]);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Reviewer Analytics Dashboard';
require_once '../includes/header.php';
?>

<div class="mb-4">
    <h1><?php echo escape($pageTitle); ?></h1>
    <p class="text-muted">Comprehensive reviewer performance metrics and analytics</p>
</div>

<!-- Reviewer Selection -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="form-inline">
            <label for="reviewer_id" class="mr-2">Select Reviewer:</label>
            <select name="reviewer_id" id="reviewer_id" class="form-control mr-2" required>
                <option value="">-- Choose Reviewer --</option>
                <?php foreach ($reviewers as $reviewer): ?>
                    <option value="<?php echo $reviewer['id']; ?>"
                            <?php echo $reviewerId === $reviewer['id'] ? 'selected' : ''; ?>>
                        <?php echo escape($reviewer['full_name']); ?>
                        (<?php echo $reviewer['review_count']; ?> reviews)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">View Analytics</button>
        </form>
    </div>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger">
        <?php echo escape($error); ?>
    </div>
<?php elseif ($metrics && $selectedReviewer): ?>

    <!-- Insufficient Data Warning -->
    <?php if (!$metrics['has_sufficient_data']): ?>
    <div class="alert alert-warning">
        <strong>Insufficient Data:</strong>
        This reviewer has completed fewer than 5 reviews. Comparative metrics require more data for accurate analysis.
        Basic metrics are shown below.
    </div>
    <?php endif; ?>

    <!-- Reviewer Header -->
    <div class="card mb-4">
        <div class="card-body">
            <h2><?php echo escape($selectedReviewer['full_name']); ?></h2>
            <p class="text-muted mb-0">Email: <?php echo escape($selectedReviewer['email']); ?></p>
        </div>
    </div>

    <!-- Completion Metrics -->
    <div class="card mb-4">
        <div class="card-header">
            <h3>Completion Metrics</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="metric-card">
                        <div class="metric-label">Total Reviews</div>
                        <div class="metric-value"><?php echo $metrics['completion_metrics']['total_reviews']; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card">
                        <div class="metric-label">Completed</div>
                        <div class="metric-value"><?php echo $metrics['completion_metrics']['completed_reviews']; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card">
                        <div class="metric-label">Avg Completion Time</div>
                        <div class="metric-value">
                            <?php
                            echo $metrics['completion_metrics']['avg_completion_hours']
                                ? number_format($metrics['completion_metrics']['avg_completion_hours'], 1) . ' hrs'
                                : 'N/A';
                            ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="metric-card">
                        <div class="metric-label">On-Time Rate</div>
                        <div class="metric-value">
                            <?php echo number_format($metrics['completion_metrics']['on_time_completion_rate'], 1); ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scoring Metrics -->
    <div class="card mb-4">
        <div class="card-header">
            <h3>Scoring Metrics</h3>
        </div>
        <div class="card-body">
            <?php if ($metrics['scoring_metrics']['average_score_given'] !== null): ?>
            <div class="row">
                <div class="col-md-6">
                    <h4>Overall Scoring</h4>
                    <table class="table">
                        <tr>
                            <th>Average Score Given:</th>
                            <td><?php echo number_format($metrics['scoring_metrics']['average_score_given'], 2); ?></td>
                        </tr>
                        <tr>
                            <th>Score Range:</th>
                            <td>
                                <?php echo $metrics['scoring_metrics']['score_min']; ?> -
                                <?php echo $metrics['scoring_metrics']['score_max']; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Score Std Dev:</th>
                            <td>
                                <?php echo number_format($metrics['scoring_metrics']['score_stddev'], 2); ?>
                                <small class="text-muted">(Lower = more consistent)</small>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h4>Score Distribution</h4>
                    <canvas id="scoreDistributionChart" height="200"></canvas>
                </div>
            </div>
            <?php else: ?>
                <p class="text-muted">No scoring data available.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Comparative Metrics -->
    <div class="card mb-4">
        <div class="card-header">
            <h3>Comparative Metrics</h3>
        </div>
        <div class="card-body">
            <?php if ($metrics['comparative_metrics']['peer_z_score'] !== null): ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="metric-card">
                        <div class="metric-label">Peer Average</div>
                        <div class="metric-value">
                            <?php echo number_format($metrics['comparative_metrics']['peer_average'], 2); ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="metric-card">
                        <div class="metric-label">Z-Score</div>
                        <div class="metric-value">
                            <?php echo $metrics['comparative_metrics']['peer_z_score'] > 0 ? '+' : ''; ?>
                            <?php echo number_format($metrics['comparative_metrics']['peer_z_score'], 2); ?>
                        </div>
                        <div class="metric-description">
                            <?php echo escape(str_replace('_', ' ', $metrics['comparative_metrics']['interpretation'])); ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="metric-card">
                        <div class="metric-label">Trend</div>
                        <div class="metric-value">
                            <?php
                            $trendIcons = [
                                'improving' => '↑',
                                'declining' => '↓',
                                'stable' => '→',
                                'insufficient_data' => '–'
                            ];
                            echo $trendIcons[$metrics['comparative_metrics']['trend_direction']] ?? '–';
                            ?>
                            <?php echo escape(ucfirst(str_replace('_', ' ', $metrics['comparative_metrics']['trend_direction']))); ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <p class="text-muted">Comparative metrics require more data.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quality Metrics -->
    <div class="card mb-4">
        <div class="card-header">
            <h3>Quality Metrics</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="metric-card">
                        <div class="metric-label">Inter-Rater Reliability</div>
                        <div class="metric-value">
                            <?php
                            if ($metrics['quality_metrics']['inter_rater_reliability']['correlation_coefficient'] !== null) {
                                echo number_format($metrics['quality_metrics']['inter_rater_reliability']['correlation_coefficient'], 3);
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </div>
                        <div class="metric-description">
                            <?php
                            if ($metrics['quality_metrics']['inter_rater_reliability']['correlation_coefficient'] !== null) {
                                echo ucfirst($metrics['quality_metrics']['inter_rater_reliability']['interpretation']);
                            } else {
                                echo 'Insufficient data';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="metric-card">
                        <div class="metric-label">Avg Comment Length</div>
                        <div class="metric-value">
                            <?php echo number_format($metrics['quality_metrics']['avg_comment_length'], 0); ?>
                        </div>
                        <div class="metric-description">characters per review</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="metric-card">
                        <div class="metric-label">Discussion Participation</div>
                        <div class="metric-value">
                            <?php echo $metrics['quality_metrics']['discussion_messages_posted']; ?>
                        </div>
                        <div class="metric-description">messages posted</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Radar Chart for Criteria Comparison -->
    <?php if (!empty($metrics['scoring_metrics']['scores_by_criterion'])): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h3>Performance by Criterion</h3>
        </div>
        <div class="card-body">
            <canvas id="criteriaRadarChart" height="300"></canvas>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="../assets/js/report-charts.js"></script>
    <script>
        // Score Distribution Chart
        const scoreDistCtx = document.getElementById('scoreDistributionChart').getContext('2d');
        new Chart(scoreDistCtx, {
            type: 'bar',
            data: {
                labels: ['1', '2', '3', '4', '5', '6', '7', '8', '9'],
                datasets: [{
                    label: 'Score Distribution',
                    data: <?php echo json_encode(array_values($metrics['scoring_metrics']['score_distribution']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Frequency'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Score'
                        }
                    }
                }
            }
        });

        // Criteria Radar Chart
        const criteriaRadarCtx = document.getElementById('criteriaRadarChart').getContext('2d');
        new Chart(criteriaRadarCtx, {
            type: 'radar',
            data: {
                labels: <?php echo json_encode(array_keys($metrics['scoring_metrics']['scores_by_criterion']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
                datasets: [{
                    label: 'Average Score',
                    data: <?php echo json_encode(array_column($metrics['scoring_metrics']['scores_by_criterion'], 'average'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: 'rgba(75, 192, 192, 1)'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    r: {
                        beginAtZero: true,
                        min: 1,
                        max: 9,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
    <?php endif; ?>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
