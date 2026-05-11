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
$message = '';
$error = '';
$csrfError = null;

// Get application details
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

// Handle reviewer assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_reviewer']) && !$csrfError) {
    $reviewerId = isset($_POST['reviewer_id']) ? (int) $_POST['reviewer_id'] : 0;

    if (!$reviewerId) {
        $error = 'Please select a reviewer.';
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'reviewer' AND is_active = TRUE");
        $stmt->execute([$reviewerId]);
        if (!$stmt->fetch()) {
            $error = 'Selected reviewer is not active.';
        }
    }

    // Check if already assigned
    if (!$error) {
        if (!empty($application['study_section_id'])) {
            $stmt = $db->prepare("
                SELECT id FROM study_section_reviewers
                WHERE study_section_id = ? AND reviewer_id = ?
            ");
            $stmt->execute([$application['study_section_id'], $reviewerId]);
            if (!$stmt->fetch()) {
                $error = 'Reviewer is not assigned to this study section.';
            }
        }

        $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignments WHERE application_id = ? AND reviewer_id = ?");
        $stmt->execute([$applicationId, $reviewerId]);
        $row = $stmt->fetch();
        if (($row ? (int) $row['count'] : 0) > 0) {
            $error = 'Reviewer already assigned to this application.';
        } else {
            $stmt = $db->prepare("SELECT anonymous_label FROM assignments WHERE application_id = ?");
            $stmt->execute([$applicationId]);
            $existingLabels = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $anonymousLabel = generateAnonymousLabel($existingLabels);

            $stmt = $db->prepare("INSERT INTO assignments (application_id, reviewer_id, anonymous_label) VALUES (?, ?, ?)");
            $stmt->execute([$applicationId, $reviewerId, $anonymousLabel]);
            logAudit('assignments', $db->lastInsertId(), 'created', null, $anonymousLabel, 'insert');
            $message = 'Reviewer assigned successfully.';
        }
    }
}

// Handle reviewer removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_reviewer']) && !$csrfError) {
    $assignmentId = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;
    $stmt = $db->prepare("SELECT id FROM assignments WHERE id = ? AND application_id = ?");
    $stmt->execute([$assignmentId, $applicationId]);
    if ($stmt->fetch()) {
        $stmt = $db->prepare("DELETE FROM assignments WHERE id = ?");
        $stmt->execute([$assignmentId]);
        logAudit('assignments', $assignmentId, 'deleted', null, null, 'delete');
        $message = 'Reviewer removed from application.';
    } else {
        $error = 'Invalid reviewer assignment.';
    }
}

// Get all reviews for this application
$stmt = $db->prepare("
    SELECT r.*, u.full_name as reviewer_name, a.anonymous_label
    FROM reviews r
    LEFT JOIN users u ON r.reviewer_id = u.id
    LEFT JOIN assignments a ON r.application_id = a.application_id AND r.reviewer_id = a.reviewer_id
    WHERE r.application_id = ?
    ORDER BY r.created_at DESC
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

// Get assigned reviewers
$stmt = $db->prepare("
    SELECT ass.*, u.full_name, u.email
    FROM assignments ass
    JOIN users u ON ass.reviewer_id = u.id
    WHERE ass.application_id = ?
    ORDER BY ass.anonymous_label
");
$stmt->execute([$applicationId]);
$assignedReviewers = $stmt->fetchAll();

// Get all active reviewers for assignment dropdown
if (!empty($application['study_section_id'])) {
    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.email
        FROM study_section_reviewers ssr
        JOIN users u ON ssr.reviewer_id = u.id
        WHERE ssr.study_section_id = ? AND u.is_active = TRUE
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute([$application['study_section_id']]);
    $allReviewers = $stmt->fetchAll();
} else {
    $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE role = 'reviewer' AND is_active = TRUE ORDER BY last_name, first_name");
    $stmt->execute();
    $allReviewers = $stmt->fetchAll();
}

// Get discussion messages
$stmt = $db->prepare("
    SELECT dm.*, u.full_name, a.anonymous_label
    FROM discussion_messages dm
    JOIN users u ON dm.user_id = u.id
    LEFT JOIN assignments a ON dm.application_id = a.application_id AND dm.user_id = a.reviewer_id
    WHERE dm.application_id = ?
    ORDER BY dm.created_at ASC
");
$stmt->execute([$applicationId]);
$messages = $stmt->fetchAll();

// Get review statistics
$stats = getReviewStats($applicationId);

$pageTitle = 'Application Details';
require_once '../includes/header.php';
?>

<div class="mb-4 d-flex justify-between">
    <a href="applications.php" class="btn btn-secondary btn-sm">← Back to Applications</a>
    <a href="generate_report.php?id=<?php echo $applicationId; ?>" class="btn btn-primary btn-sm">Generate Final Report</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo escape($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo escape($error); ?></div>
<?php endif; ?>

<!-- Application Info -->
<div class="card mb-4">
    <div class="card-header">Application Information</div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <p><strong>Grant ID:</strong> <?php echo escape($application['grant_id'] ?? 'N/A'); ?></p>
                <p><strong>Applicant:</strong> <?php echo escape($application['applicant_name']); ?></p>
                <p><strong>Study Section:</strong> <?php echo escape($application['study_section_name'] ?? 'N/A'); ?></p>
                <p><strong>Grant Type:</strong> <span class="badge badge-primary"><?php echo escape($application['grant_type_name'] ?? $application['grant_type']); ?></span></p>
            </div>
            <div>
                <p><strong>Complete:</strong>
                    <span class="badge badge-<?php echo $application['is_complete'] ? 'success' : 'secondary'; ?>">
                        <?php echo $application['is_complete'] ? 'Yes' : 'No'; ?>
                    </span>
                </p>
                <p><strong>Created:</strong> <?php echo formatDateTime($application['created_at']); ?></p>
                <p><strong>Last Updated:</strong> <?php echo formatDateTime($application['updated_at']); ?></p>
            </div>
        </div>
        <p><strong>Title:</strong> <?php echo escape($application['application_title']); ?></p>
    </div>
</div>

<!-- Reviewer Assignment -->
<div class="card mb-4">
    <div class="card-header">Assigned Reviewers</div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <h4>Current Reviewers</h4>
                <?php if (empty($assignedReviewers)): ?>
                    <p class="text-muted">No reviewers assigned yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>Name</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignedReviewers as $reviewer): ?>
                                <tr>
                                    <td><span class="badge badge-primary"><?php echo escape($reviewer['anonymous_label']); ?></span></td>
                                    <td><?php echo escape($reviewer['full_name']); ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="assignment_id" value="<?php echo $reviewer['id']; ?>">
                                            <button type="submit" name="remove_reviewer" class="btn btn-sm btn-danger" onclick="return confirm('Remove this reviewer?')">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div>
                <h4>Assign New Reviewer</h4>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <div class="form-group">
                        <label class="form-label">Select Reviewer</label>
                        <select name="reviewer_id" class="form-control" required>
                            <option value="">-- Select Reviewer --</option>
                            <?php foreach ($allReviewers as $reviewer): ?>
                                <option value="<?php echo $reviewer['id']; ?>">
                                    <?php echo escape($reviewer['full_name']) . ' (' . escape($reviewer['email']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="assign_reviewer" class="btn btn-primary">Assign Reviewer</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Review Statistics -->
<?php if (!empty($stats)): ?>
    <div class="card mb-4">
        <div class="card-header">Review Statistics</div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Criterion</th>
                        <th>Mean Score</th>
                        <th>Min Score</th>
                        <th>Max Score</th>
                        <th>Review Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $criterion => $data): ?>
                        <tr>
                            <td><strong><?php echo escape($criterion); ?></strong></td>
                            <td>
                                <span class="score-display <?php echo getScoreColorClass(round($data['mean'])); ?>">
                                    <?php echo number_format($data['mean'], 2); ?>
                                </span>
                            </td>
                            <td><?php echo $data['min']; ?></td>
                            <td><?php echo $data['max']; ?></td>
                            <td><?php echo $data['count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- All Reviews -->
<div class="card mb-4">
    <div class="card-header">All Reviews (<?php echo count($reviews); ?>)</div>
    <div class="card-body">
        <?php if (empty($reviews)): ?>
            <p class="text-muted">No reviews submitted yet.</p>
        <?php else: ?>
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
            <?php foreach ($reviews as $review): ?>
                <div class="card mb-3" style="border-left: 4px solid var(--primary-color);">
                    <div class="card-header">
                        Review by:
                        <?php if ($review['reviewer_name']): ?>
                            <strong><?php echo escape($review['reviewer_name']); ?></strong>
                            <?php if ($review['anonymous_label']): ?>
                                (<?php echo escape($review['anonymous_label']); ?>)
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">Unassigned</span>
                        <?php endif; ?>
                        <span style="float: right; font-size: 0.875rem;" class="text-muted">
                            <?php echo formatDateTime($review['created_at']); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if ($useLegacySections): ?>
                            <div class="grid grid-2 mb-3">
                                <div>
                                    <p><strong>Overall Impact:</strong>
                                        <span class="score-display <?php echo getScoreColorClass($review['overall_impact_score']); ?>">
                                            <?php echo $review['overall_impact_score']; ?> - <?php echo getScoreLabel($review['overall_impact_score']); ?>
                                        </span>
                                    </p>
                                    <div><?php echo nl2br(escape($review['overall_impact_explanation'])); ?></div>
                                </div>
                                <div>
                                    <p><strong>Relevance to RFA:</strong>
                                        <span class="score-display <?php echo getScoreColorClass($review['relevance_score']); ?>">
                                            <?php echo $review['relevance_score']; ?> - <?php echo getScoreLabel($review['relevance_score']); ?>
                                        </span>
                                    </p>
                                    <div><?php echo nl2br(escape($review['relevance_explanation'])); ?></div>
                                </div>
                            </div>

                            <?php
                            $criteria = $allCriteria[$review['id']] ?? [];
                            ?>

                            <h4>Criteria Scores</h4>
                            <?php foreach ($criteria as $criterion): ?>
                                <div class="mb-3 p-2" style="background: var(--light-bg); border-radius: 0.375rem;">
                                    <div class="d-flex justify-between">
                                        <strong><?php echo escape($criterion['criterion_name']); ?></strong>
                                        <span class="score-display <?php echo getScoreColorClass($criterion['score']); ?>">
                                            <?php echo $criterion['score']; ?>
                                        </span>
                                    </div>
                                    <p class="mt-2"><strong>Strengths:</strong></p>
                                    <div><?php echo nl2br(escape($criterion['strengths'])); ?></div>
                                    <p class="mt-2"><strong>Weaknesses:</strong></p>
                                    <div><?php echo nl2br(escape($criterion['weaknesses'])); ?></div>
                                </div>
                            <?php endforeach; ?>

                            <?php if ($review['budget_acceptable'] !== null): ?>
                                <h4>Budget and Period of Support</h4>
                                <div class="mb-3 p-2" style="background: var(--light-bg); border-radius: 0.375rem;">
                                    <p><strong>Acceptable:</strong>
                                        <span class="badge badge-<?php echo $review['budget_acceptable'] ? 'success' : 'danger'; ?>">
                                            <?php echo $review['budget_acceptable'] ? 'Yes' : 'No'; ?>
                                        </span>
                                    </p>
                                    <?php if ($review['budget_modifications']): ?>
                                        <p class="mt-2"><strong>Modifications:</strong></p>
                                        <div><?php echo nl2br(escape($review['budget_modifications'])); ?></div>
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
                                    <div class="d-flex justify-between">
                                        <strong><?php echo escape($section['name']); ?></strong>
                                        <?php if ($section['is_scored'] && $sectionScore && $sectionScore['score'] !== null): ?>
                                            <span class="score-display <?php echo getScoreColorClass($sectionScore['score']); ?>">
                                                <?php echo $sectionScore['score']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="mt-2"><strong>Summative Comments:</strong></p>
                                    <div><?php echo nl2br(escape($sectionScore['summative_comments'] ?? '')); ?></div>
                                    <p class="mt-2"><strong>Strengths:</strong></p>
                                    <div><?php echo nl2br(escape($sectionScore['strengths'] ?? '')); ?></div>
                                    <p class="mt-2"><strong>Weaknesses:</strong></p>
                                    <div><?php echo nl2br(escape($sectionScore['weaknesses'] ?? '')); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Discussion Messages -->
<div class="card mb-4">
    <div class="card-header">Discussion (<?php echo count($messages); ?> messages)</div>
    <div class="card-body">
        <div class="chat-container">
            <?php if (empty($messages)): ?>
                <p class="text-muted">No discussion messages yet.</p>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="chat-message">
                        <div class="chat-message-header">
                            <span class="chat-author">
                                <?php echo escape($msg['full_name']); ?>
                                <?php if ($msg['anonymous_label']): ?>
                                    (<?php echo escape($msg['anonymous_label']); ?>)
                                <?php endif; ?>
                            </span>
                            <span class="chat-time"><?php echo formatDateTime($msg['created_at']); ?></span>
                        </div>
                        <div class="chat-content">
                            <?php echo nl2br(escape($msg['message'])); ?>
                            <?php if ($msg['is_edited']): ?>
                                <span class="text-muted" style="font-size: 0.75rem;">(edited)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
