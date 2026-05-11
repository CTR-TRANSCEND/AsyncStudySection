<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();
$applicationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

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
    header('Location: applications.php');
    exit;
}

$grantTypeId = getApplicationGrantTypeId($applicationId);
$grantSections = $grantTypeId ? getGrantSections($grantTypeId) : [];
$useLegacySections = empty($grantSections);

// Get all reviews with reviewer info
$stmt = $db->prepare("
    SELECT r.*, u.full_name as reviewer_name, a.anonymous_label
    FROM reviews r
    LEFT JOIN users u ON r.reviewer_id = u.id
    LEFT JOIN assignments a ON r.application_id = a.application_id AND r.reviewer_id = a.reviewer_id
    WHERE r.application_id = ?
    ORDER BY a.anonymous_label
");
$stmt->execute([$applicationId]);
$reviews = $stmt->fetchAll();

$reviewSectionScores = [];
if (!$useLegacySections && !empty($reviews)) {
    $reviewIds = array_column($reviews, 'id');
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

// Get statistics
$stats = getReviewStats($applicationId);

// Get all discussion messages
$stmt = $db->prepare("
    SELECT dm.*, u.full_name, a.anonymous_label
    FROM discussion_messages dm
    JOIN users u ON dm.user_id = u.id
    LEFT JOIN assignments a ON dm.application_id = a.application_id AND dm.user_id = a.reviewer_id
    WHERE dm.application_id = ?
    ORDER BY dm.created_at ASC
");
$stmt->execute([$applicationId]);
$discussionMessages = $stmt->fetchAll();

// Get change log
$stmt = $db->prepare("
    SELECT al.*, u.full_name as user_name
    FROM audit_log al
    JOIN users u ON al.changed_by = u.id
    WHERE al.table_name IN ('reviews', 'review_criteria_scores')
    AND al.record_id IN (SELECT id FROM reviews WHERE application_id = ?)
    ORDER BY al.changed_at DESC
    LIMIT 50
");
$stmt->execute([$applicationId]);
$auditLog = $stmt->fetchAll();

$pageTitle = 'Generate Final Report';
require_once '../includes/header.php';
?>

<div class="mb-4">
    <a href="application_detail.php?id=<?php echo $applicationId; ?>" class="btn btn-secondary btn-sm">← Back to Application</a>
    <button onclick="window.print()" class="btn btn-primary btn-sm" style="float: right;">Print Report</button>
</div>

<div class="card mb-4" id="final-report">
    <div class="card-header">
        <h2>Final Review Report</h2>
    </div>
    <div class="card-body">
        <!-- Application Information -->
        <h3>Application Information</h3>
        <table class="table">
            <tr>
                <th>Applicant Name:</th>
                <td><?php echo escape($application['applicant_name']); ?></td>
            </tr>
            <tr>
                <th>Application Title:</th>
                <td><?php echo escape($application['application_title']); ?></td>
            </tr>
            <tr>
                <th>Study Section:</th>
                <td><?php echo escape($application['study_section_name'] ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <th>Grant Type:</th>
                <td><?php echo escape($application['grant_type_name'] ?? $application['grant_type']); ?></td>
            </tr>
            <tr>
                <th>Number of Reviews:</th>
                <td><?php echo count($reviews); ?></td>
            </tr>
        </table>

        <!-- Summary Statistics -->
        <h3 class="mt-4">Summary Statistics</h3>
        <p>Average scores across all reviewers:</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Criterion</th>
                    <th>Mean Score</th>
                    <th>Score Range</th>
                    <th>Rating</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stats as $criterion => $data): ?>
                    <tr>
                        <td><strong><?php echo escape($criterion); ?></strong></td>
                        <td><?php echo number_format($data['mean'], 2); ?></td>
                        <td><?php echo $data['min']; ?> - <?php echo $data['max']; ?></td>
                        <td><?php echo getScoreLabel(round($data['mean'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Individual Reviews -->
        <h3 class="mt-4">Individual Review Details</h3>
        <?php
        // CR6-01: Batch-fetch all criteria upfront to avoid N+1 queries
        $reviewIds = array_column($reviews, 'id');
        $allCriteria = [];
        if (!empty($reviewIds)) {
            $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
            $stmt = $db->prepare("SELECT * FROM review_criteria_scores WHERE review_id IN ($placeholders) ORDER BY review_id, id");
            $stmt->execute($reviewIds);
            while ($row = $stmt->fetch()) {
                $allCriteria[$row['review_id']][] = $row;
            }
        }
        ?>
        <?php foreach ($reviews as $idx => $review): ?>
            <div class="card mb-3">
                <div class="card-header">
                    <strong>Review <?php echo $idx + 1; ?>:</strong>
                    <?php echo $review['reviewer_name'] ? escape($review['reviewer_name']) : 'Unassigned'; ?>
                    <?php if ($review['anonymous_label']): ?>
                        (<?php echo escape($review['anonymous_label']); ?>)
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($useLegacySections): ?>
                        <div class="mb-3">
                            <h4>Overall Impact</h4>
                            <p><strong>Score:</strong> <?php echo $review['overall_impact_score']; ?> - <?php echo getScoreLabel($review['overall_impact_score']); ?></p>
                            <p><?php echo escape($review['overall_impact_explanation']); ?></p>
                        </div>

                        <div class="mb-3">
                            <h4>Relevance to RFA</h4>
                            <p><strong>Score:</strong> <?php echo $review['relevance_score']; ?> - <?php echo getScoreLabel($review['relevance_score']); ?></p>
                            <p><?php echo escape($review['relevance_explanation']); ?></p>
                        </div>

                        <h4>Review Criteria</h4>
                        <?php
                        $criteria = $allCriteria[$review['id']] ?? [];
                        ?>

                        <?php foreach ($criteria as $criterion): ?>
                            <div class="mb-3 p-2" style="background: var(--light-bg); border-radius: 0.375rem;">
                                <h5><?php echo escape($criterion['criterion_name']); ?>
                                    <span style="float: right;">Score: <?php echo $criterion['score']; ?></span>
                                </h5>
                                <p><strong>Strengths:</strong> <?php echo escape($criterion['strengths']); ?></p>
                                <p><strong>Weaknesses:</strong> <?php echo escape($criterion['weaknesses']); ?></p>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($review['budget_acceptable'] !== null): ?>
                            <div class="mb-3">
                                <h4>Budget and Period of Support</h4>
                                <p><strong>Acceptable:</strong> <?php echo $review['budget_acceptable'] ? 'Yes' : 'No'; ?></p>
                                <?php if ($review['budget_modifications']): ?>
                                    <p><strong>Modifications:</strong> <?php echo escape($review['budget_modifications']); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php
                        $sections = $reviewSectionScores[$review['id']] ?? [];
                        ?>
                        <?php foreach ($grantSections as $section): ?>
                            <?php
                            $sectionScore = null;
                            foreach ($sections as $scoreRow) {
                                if ((int)$scoreRow['grant_section_id'] === (int)$section['id']) {
                                    $sectionScore = $scoreRow;
                                    break;
                                }
                            }
                            ?>
                            <div class="mb-3 p-2" style="background: var(--light-bg); border-radius: 0.375rem;">
                                <h5><?php echo escape($section['name']); ?>
                                    <?php if ($section['is_scored'] && $sectionScore && $sectionScore['score'] !== null): ?>
                                        <span style="float: right;">Score: <?php echo $sectionScore['score']; ?></span>
                                    <?php endif; ?>
                                </h5>
                                <p><strong>Summative Comments:</strong> <?php echo escape($sectionScore['summative_comments'] ?? ''); ?></p>
                                <p><strong>Strengths:</strong> <?php echo escape($sectionScore['strengths'] ?? ''); ?></p>
                                <p><strong>Weaknesses:</strong> <?php echo escape($sectionScore['weaknesses'] ?? ''); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <p class="text-muted mt-3"><small>Review submitted: <?php echo formatDateTime($review['created_at']); ?>
                        | Last updated: <?php echo formatDateTime($review['updated_at']); ?></small></p>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Discussion Summary -->
        <?php if (!empty($discussionMessages)): ?>
            <h3 class="mt-4">Discussion Summary</h3>
            <div class="mb-3">
                <p>Total messages: <?php echo count($discussionMessages); ?></p>
                <?php foreach ($discussionMessages as $msg): ?>
                    <div class="mb-2 p-2" style="border-left: 3px solid var(--primary-color); background: var(--light-bg);">
                        <div class="d-flex justify-between">
                            <strong>
                                <?php echo escape($msg['full_name']); ?>
                                <?php if ($msg['anonymous_label']): ?>
                                    (<?php echo escape($msg['anonymous_label']); ?>)
                                <?php endif; ?>
                            </strong>
                            <span class="text-muted" style="font-size: 0.875rem;">
                                <?php echo formatDateTime($msg['created_at']); ?>
                            </span>
                        </div>
                        <p class="mt-1"><?php echo nl2br(escape($msg['message'])); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Change History -->
        <?php if (!empty($auditLog)): ?>
            <h3 class="mt-4">Change History</h3>
            <p>Recent changes to reviews and scores:</p>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>User</th>
                        <th>Field</th>
                        <th>Old Value</th>
                        <th>New Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auditLog as $log): ?>
                        <tr>
                            <td><?php echo formatDateTime($log['changed_at']); ?></td>
                            <td><?php echo escape($log['user_name']); ?></td>
                            <td><?php echo escape($log['field_name']); ?></td>
                            <td><?php echo escape(substr($log['old_value'] ?? 'N/A', 0, 50)); ?></td>
                            <td><?php echo escape(substr($log['new_value'] ?? 'N/A', 0, 50)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Report Generated -->
        <div class="mt-4 text-muted text-center">
            <p>Report generated on: <?php echo date('F d, Y g:i A'); ?></p>
            <p>Generated by: <?php echo escape(Auth::getFullName()); ?></p>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
