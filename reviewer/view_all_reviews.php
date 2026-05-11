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
$applicationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Verify access
if (!hasApplicationAccess($applicationId, $userId)) {
    header('Location: dashboard.php');
    exit;
}

// Get application
$stmt = $db->prepare("
    SELECT a.*, ss.name as study_section_name, COALESCE(gt.name, a.grant_type) as grant_type_name
    FROM applications a
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    LEFT JOIN grant_types gt ON gt.id = a.grant_type_id
    WHERE a.id = ?
");
$stmt->execute([$applicationId]);
$application = $stmt->fetch();

if (!$application) {
    header('Location: dashboard.php');
    exit;
}

// Get my anonymous label
$myLabel = getAnonymousLabel($applicationId, $userId);

$grantTypeId = getApplicationGrantTypeId($applicationId);
$grantSections = $grantTypeId ? getGrantSections($grantTypeId) : [];
$useLegacySections = empty($grantSections);

// Get all reviews for this application with reviewer info
$stmt = $db->prepare("
    SELECT r.*, ass.anonymous_label, u.id as user_id
    FROM reviews r
    JOIN assignments ass ON r.application_id = ass.application_id AND r.reviewer_id = ass.reviewer_id
    LEFT JOIN users u ON r.reviewer_id = u.id
    WHERE r.application_id = ?
    ORDER BY ass.anonymous_label
");
$stmt->execute([$applicationId]);
$allReviews = $stmt->fetchAll();

$reviewSectionScores = [];
if (!$useLegacySections && !empty($allReviews)) {
    $reviewIds = array_column($allReviews, 'id');
    $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
    $stmt = $db->prepare("
        SELECT rss.*, gs.name, gs.is_scored, gs.display_order
        FROM review_section_scores rss
        JOIN grant_sections gs ON rss.grant_section_id = gs.id
        WHERE rss.review_id IN ($placeholders)
        ORDER BY gs.display_order, gs.id
    ");
    $stmt->execute($reviewIds);
    foreach ($stmt->fetchAll() as $row) {
        $reviewSectionScores[$row['review_id']][] = $row;
    }
}

// Batch-fetch legacy criteria scores to avoid N+1 queries in the review loop
$allLegacyCriteria = [];
if ($useLegacySections && !empty($allReviews)) {
    $reviewIds = array_column($allReviews, 'id');
    $critPlaceholders = implode(',', array_fill(0, count($reviewIds), '?'));
    $critStmt = $db->prepare("SELECT * FROM review_criteria_scores WHERE review_id IN ($critPlaceholders) ORDER BY review_id, id");
    $critStmt->execute($reviewIds);
    while ($row = $critStmt->fetch()) {
        $allLegacyCriteria[$row['review_id']][] = $row;
    }
}

// Get review statistics
$stats = getReviewStats($applicationId);

$pageTitle = 'All Reviews';
require_once '../includes/header.php';
?>

<div class="mb-4">
    <a href="review_application.php?id=<?php echo $applicationId; ?>" class="btn btn-secondary btn-sm">← Back to My Review</a>
</div>

<h1 class="mb-4">All Reviews</h1>

<!-- Application Info -->
<div class="card mb-4">
    <div class="card-header">Application Information</div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <p><strong>Grant ID:</strong> <?php echo escape($application['grant_id'] ?? 'N/A'); ?></p>
                <p><strong>Applicant:</strong> <?php echo escape($application['applicant_name']); ?></p>
            </div>
            <div>
                <p><strong>Study Section:</strong> <?php echo escape($application['study_section_name'] ?? 'N/A'); ?></p>
                <p><strong>Grant Type:</strong> <span class="badge badge-primary"><?php echo escape($application['grant_type_name'] ?? $application['grant_type']); ?></span></p>
                <p><strong>Your Role:</strong> <span class="badge badge-secondary"><?php echo escape($myLabel); ?></span></p>
            </div>
        </div>
        <p><strong>Title:</strong> <?php echo escape($application['application_title']); ?></p>
    </div>
</div>

<div class="mb-3">
    <a href="discussions.php?app_id=<?php echo $applicationId; ?>" class="btn btn-secondary btn-sm">Go to Discussion</a>
</div>

<!-- Review Statistics -->
<?php if (!empty($stats)): ?>
    <div class="card mb-4">
        <div class="card-header">Review Statistics (All Reviewers)</div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Section</th>
                        <th>Mean Score</th>
                        <th>Range</th>
                        <th>Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $section => $data): ?>
                        <tr>
                            <td><strong><?php echo escape($section); ?></strong></td>
                            <td>
                                <span class="score-display <?php echo getScoreColorClass(round($data['mean'])); ?>">
                                    <?php echo number_format($data['mean'], 2); ?> - <?php echo getScoreLabel(round($data['mean'])); ?>
                                </span>
                            </td>
                            <td><?php echo $data['min']; ?> - <?php echo $data['max']; ?></td>
                            <td><?php echo $data['count']; ?> reviewer<?php echo (int)$data['count'] !== 1 ? 's' : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- All Reviews -->
<h2 class="mb-3">Individual Reviews (<?php echo count($allReviews); ?>)</h2>

<?php if (empty($allReviews)): ?>
    <div class="card">
        <div class="card-body">
            <p class="text-muted text-center">No reviews submitted yet.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($allReviews as $review): ?>
        <?php
        $isMyReview = ((int)$review['user_id'] === (int)$userId);
        $cardClass = $isMyReview ? 'review-summary-card my-review' : 'review-summary-card';
        ?>
        <div class="card <?php echo $cardClass; ?>">
            <div class="card-header">
                <strong><?php echo escape($review['anonymous_label']); ?></strong>
                <?php if ($isMyReview): ?>
                    <span class="badge badge-success" style="margin-left: 0.5rem;">Your Review</span>
                <?php endif; ?>
                <span style="float: right; font-size: 0.875rem;" class="text-muted">
                    Updated: <?php echo formatDateTime($review['updated_at']); ?>
                </span>
            </div>
            <div class="card-body">
                <!-- Scores Grid -->
                <div class="score-grid">
                    <?php if ($useLegacySections): ?>
                        <div class="score-item">
                            <div class="score-item-label">Overall Impact</div>
                            <div class="score-item-value <?php echo getScoreColorClass($review['overall_impact_score']); ?>">
                                <?php echo $review['overall_impact_score']; ?>
                            </div>
                        </div>
                        <div class="score-item">
                            <div class="score-item-label">Relevance</div>
                            <div class="score-item-value <?php echo getScoreColorClass($review['relevance_score']); ?>">
                                <?php echo $review['relevance_score']; ?>
                            </div>
                        </div>
                        <?php
                        $criteria = $allLegacyCriteria[$review['id']] ?? [];
                        ?>

                        <?php foreach ($criteria as $criterion): ?>
                            <div class="score-item">
                                <div class="score-item-label"><?php echo escape($criterion['criterion_name']); ?></div>
                                <div class="score-item-value <?php echo getScoreColorClass($criterion['score']); ?>">
                                    <?php echo $criterion['score']; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($reviewSectionScores[$review['id']] ?? [] as $sectionScore): ?>
                            <?php if (!$sectionScore['is_scored'] || $sectionScore['score'] === null) continue; ?>
                            <div class="score-item">
                                <div class="score-item-label"><?php echo escape($sectionScore['name']); ?></div>
                                <div class="score-item-value <?php echo getScoreColorClass($sectionScore['score']); ?>">
                                    <?php echo $sectionScore['score']; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Brief summary -->
                <div class="mt-3">
                    <p><strong>Summary:</strong></p>
                    <p class="text-muted" style="font-size: 0.9rem;">
                        <?php if ($useLegacySections): ?>
                            <?php echo escape(substr($review['overall_impact_explanation'], 0, 200)) . (strlen($review['overall_impact_explanation']) > 200 ? '...' : ''); ?>
                        <?php else: ?>
                            <?php
                            $summaryText = '';
                            foreach ($reviewSectionScores[$review['id']] ?? [] as $sectionScore) {
                                if (!empty($sectionScore['summative_comments'])) {
                                    $summaryText = $sectionScore['summative_comments'];
                                    break;
                                }
                            }
                            ?>
                            <?php echo $summaryText !== '' ? escape(substr($summaryText, 0, 200)) . (strlen($summaryText) > 200 ? '...' : '') : 'No summary provided.'; ?>
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Action buttons -->
                <div class="mt-3">
                    <?php if ($isMyReview): ?>
                        <a href="review_application.php?id=<?php echo $applicationId; ?>" class="btn btn-primary btn-sm">
                            ✏️ Edit My Review
                        </a>
                    <?php endif; ?>
                    <a href="view_review_detail.php?review_id=<?php echo $review['id']; ?>&app_id=<?php echo $applicationId; ?>"
                       class="btn btn-outline btn-sm">
                        👁️ View Full Details
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
