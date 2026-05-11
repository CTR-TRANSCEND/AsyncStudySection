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
$reviewId = isset($_GET['review_id']) ? (int) $_GET['review_id'] : 0;
$applicationId = isset($_GET['app_id']) ? (int) $_GET['app_id'] : 0;

// Verify access to application
if (!hasApplicationAccess($applicationId, $userId)) {
    header('Location: dashboard.php');
    exit;
}

// Get review with reviewer info
$stmt = $db->prepare("
    SELECT r.*, ass.anonymous_label, a.applicant_name, a.application_title, a.grant_type, a.grant_id,
           ss.name as study_section_name, COALESCE(gt.name, a.grant_type) as grant_type_name
    FROM reviews r
    JOIN assignments ass ON r.application_id = ass.application_id AND r.reviewer_id = ass.reviewer_id
    JOIN applications a ON r.application_id = a.id
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    LEFT JOIN grant_types gt ON gt.id = a.grant_type_id
    WHERE r.id = ? AND r.application_id = ?
");
$stmt->execute([$reviewId, $applicationId]);
$review = $stmt->fetch();

if (!$review) {
    header('Location: dashboard.php');
    exit;
}

$grantTypeId = getApplicationGrantTypeId($applicationId);
$grantSections = $grantTypeId ? getGrantSections($grantTypeId) : [];
$useLegacySections = empty($grantSections);
$sectionScores = $useLegacySections ? [] : getReviewSectionScores($reviewId);

// Get criteria scores
$criteria = [];
if ($useLegacySections) {
    $stmt = $db->prepare("SELECT * FROM review_criteria_scores WHERE review_id = ? ORDER BY id");
    $stmt->execute([$reviewId]);
    $criteria = $stmt->fetchAll();
}

$pageTitle = 'Review Details';
require_once '../includes/header.php';
?>

<div class="mb-4">
    <a href="view_all_reviews.php?id=<?php echo $applicationId; ?>" class="btn btn-secondary btn-sm">← Back to All Reviews</a>
    <a href="discussions.php?app_id=<?php echo $applicationId; ?>" class="btn btn-primary btn-sm">Discussion</a>
</div>

<h1 class="mb-4">Review Details</h1>

<!-- Application Info -->
<div class="card mb-4">
    <div class="card-header">Application Information</div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <p><strong>Grant ID:</strong> <?php echo escape($review['grant_id'] ?? 'N/A'); ?></p>
                <p><strong>Applicant:</strong> <?php echo escape($review['applicant_name']); ?></p>
                <p><strong>Study Section:</strong> <?php echo escape($review['study_section_name'] ?? 'N/A'); ?></p>
                <p><strong>Grant Type:</strong> <span class="badge badge-primary"><?php echo escape($review['grant_type_name'] ?? $review['grant_type']); ?></span></p>
            </div>
            <div>
                <p><strong>Reviewer:</strong> <span class="badge badge-secondary"><?php echo escape($review['anonymous_label']); ?></span></p>
                <p><strong>Review Date:</strong> <?php echo formatDate($review['review_date']); ?></p>
                <p><strong>Last Updated:</strong> <?php echo formatDateTime($review['updated_at']); ?></p>
            </div>
        </div>
        <p><strong>Title:</strong> <?php echo escape($review['application_title']); ?></p>
    </div>
</div>

<!-- Overall Impact -->
<?php
$overallSection = null;
$relevanceSection = null;
if (!$useLegacySections) {
    foreach ($sectionScores as $sectionScore) {
        if ($overallSection === null && stripos($sectionScore['name'], 'overall') !== false) {
            $overallSection = $sectionScore;
        }
        if ($relevanceSection === null && stripos($sectionScore['name'], 'relevance') !== false) {
            $relevanceSection = $sectionScore;
        }
    }
}
?>

<?php if ($useLegacySections): ?>
    <div class="card mb-3">
        <div class="card-header">
            Overall Impact
            <span class="score-display <?php echo getScoreColorClass($review['overall_impact_score']); ?>" style="float: right;">
                Score: <?php echo $review['overall_impact_score']; ?> - <?php echo getScoreLabel($review['overall_impact_score']); ?>
            </span>
        </div>
        <div class="card-body">
            <p><strong>Explanation:</strong></p>
            <div><?php echo nl2br(escape($review['overall_impact_explanation'])); ?></div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            Relevance to RFA
            <span class="score-display <?php echo getScoreColorClass($review['relevance_score']); ?>" style="float: right;">
                Score: <?php echo $review['relevance_score']; ?> - <?php echo getScoreLabel($review['relevance_score']); ?>
            </span>
        </div>
        <div class="card-body">
            <p><strong>Explanation:</strong></p>
            <div><?php echo nl2br(escape($review['relevance_explanation'])); ?></div>
        </div>
    </div>
<?php else: ?>
    <?php if ($overallSection): ?>
        <div class="card mb-3">
            <div class="card-header">
                <?php echo escape($overallSection['name']); ?>
                <?php if ($overallSection['score'] !== null): ?>
                    <span class="score-display <?php echo getScoreColorClass($overallSection['score']); ?>" style="float: right;">
                        Score: <?php echo $overallSection['score']; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p><strong>Summative Comments:</strong></p>
                <div><?php echo nl2br(escape($overallSection['summative_comments'] ?? '')); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($relevanceSection): ?>
        <div class="card mb-3">
            <div class="card-header">
                <?php echo escape($relevanceSection['name']); ?>
                <?php if ($relevanceSection['score'] !== null): ?>
                    <span class="score-display <?php echo getScoreColorClass($relevanceSection['score']); ?>" style="float: right;">
                        Score: <?php echo $relevanceSection['score']; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p><strong>Summative Comments:</strong></p>
                <div><?php echo nl2br(escape($relevanceSection['summative_comments'] ?? '')); ?></div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Review Sections -->
<h2 class="mt-4 mb-3">Review Sections</h2>
<?php if ($useLegacySections): ?>
    <?php foreach ($criteria as $criterion): ?>
        <div class="card mb-3">
            <div class="card-header">
                <?php echo escape($criterion['criterion_name']); ?>
                <span class="score-display <?php echo getScoreColorClass($criterion['score']); ?>" style="float: right;">
                    Score: <?php echo $criterion['score']; ?> - <?php echo getScoreLabel($criterion['score']); ?>
                </span>
            </div>
            <div class="card-body">
                <p><strong>Strengths:</strong></p>
                <div><?php echo nl2br(escape($criterion['strengths'])); ?></div>

                <p class="mt-3"><strong>Weaknesses:</strong></p>
                <div><?php echo nl2br(escape($criterion['weaknesses'])); ?></div>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <?php foreach ($grantSections as $section): ?>
        <?php
        $nameLower = strtolower($section['name']);
        if (strpos($nameLower, 'overall') !== false || strpos($nameLower, 'relevance') !== false) {
            continue;
        }
        $sectionScore = $sectionScores[$section['id']] ?? null;
        ?>
        <div class="card mb-3">
            <div class="card-header">
                <?php echo escape($section['name']); ?>
                <?php if ($section['is_scored'] && $sectionScore && $sectionScore['score'] !== null): ?>
                    <span class="score-display <?php echo getScoreColorClass($sectionScore['score']); ?>" style="float: right;">
                        Score: <?php echo $sectionScore['score']; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p><strong>Summative Comments:</strong></p>
                <div><?php echo nl2br(escape($sectionScore['summative_comments'] ?? '')); ?></div>

                <p class="mt-3"><strong>Strengths:</strong></p>
                <div><?php echo nl2br(escape($sectionScore['strengths'] ?? '')); ?></div>

                <p class="mt-3"><strong>Weaknesses:</strong></p>
                <div><?php echo nl2br(escape($sectionScore['weaknesses'] ?? '')); ?></div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Budget (if applicable) -->
<?php if ($useLegacySections && $review['budget_acceptable'] !== null): ?>
    <div class="card mb-3">
        <div class="card-header">Budget and Period of Support</div>
        <div class="card-body">
            <p><strong>Acceptable:</strong>
                <span class="badge badge-<?php echo $review['budget_acceptable'] ? 'success' : 'danger'; ?>">
                    <?php echo $review['budget_acceptable'] ? 'Yes' : 'No'; ?>
                </span>
            </p>
            <?php if ($review['budget_modifications']): ?>
                <p class="mt-2"><strong>Modifications:</strong></p>
                <div><?php echo escape($review['budget_modifications']); ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Print Button -->
<div class="mt-4">
    <button onclick="window.print()" class="btn btn-secondary">🖨️ Print This Review</button>
    <a href="view_all_reviews.php?id=<?php echo $applicationId; ?>" class="btn btn-primary">← Back to All Reviews</a>
</div>

<?php require_once '../includes/footer.php'; ?>
