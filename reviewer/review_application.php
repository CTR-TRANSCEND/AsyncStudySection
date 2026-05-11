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
$message = '';
$error = '';
$csrfError = null;

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

$grantTypeId = getApplicationGrantTypeId($applicationId);
$grantSections = $grantTypeId ? getGrantSections($grantTypeId) : [];
$useLegacySections = empty($grantSections);

// Get my anonymous label
$myLabel = getAnonymousLabel($applicationId, $userId);

// Check if application is marked complete
$isComplete = (bool)$application['is_complete'];

// Handle review submission/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review']) && !$csrfError) {
    // Prevent edits if application is complete
    if ($isComplete) {
        $error = 'This application has been marked as complete. No further edits are allowed.';
    } else {
        if ($useLegacySections) {
            $overallImpactScore = intval($_POST['overall_impact_score']);
            $overallImpactExplanation = sanitize($_POST['overall_impact_explanation']);
            $relevanceScore = intval($_POST['relevance_score']);
            $relevanceExplanation = sanitize($_POST['relevance_explanation']);
            $budgetAcceptable = isset($_POST['budget_acceptable']) ? ($_POST['budget_acceptable'] === '1') : null;
            $budgetModifications = sanitize($_POST['budget_modifications'] ?? '');

            $criteriaScores = $_POST['criteria_score'] ?? [];
            $criteriaStrengths = $_POST['criteria_strengths'] ?? [];
            $criteriaWeaknesses = $_POST['criteria_weaknesses'] ?? [];

            // Validate
            $validationErrors = [];
            if (!isValidScore($overallImpactScore)) $validationErrors[] = "Invalid Overall Impact score";
            if (!isValidScore($relevanceScore)) $validationErrors[] = "Invalid Relevance score";

            foreach (REVIEW_CRITERIA as $idx => $criterionName) {
                if (!isset($criteriaScores[$idx]) || !isValidScore($criteriaScores[$idx])) {
                    $validationErrors[] = "Invalid score for $criterionName";
                }
            }

            if (empty($validationErrors)) {
                try {
                    $db->beginTransaction();

                    // Check if review exists
                    $stmt = $db->prepare("SELECT id FROM reviews WHERE application_id = ? AND reviewer_id = ?");
                    $stmt->execute([$applicationId, $userId]);
                    $existingReview = $stmt->fetch();

                    if ($existingReview) {
                        // Update existing review
                        $reviewId = $existingReview['id'];

                        // Get old values for audit
                        $stmt = $db->prepare("SELECT overall_impact_score, relevance_score FROM reviews WHERE id = ?");
                        $stmt->execute([$reviewId]);
                        $oldReview = $stmt->fetch();

                        $stmt = $db->prepare("
                            UPDATE reviews SET
                                overall_impact_score = ?,
                                overall_impact_explanation = ?,
                                relevance_score = ?,
                                relevance_explanation = ?,
                                budget_acceptable = ?,
                                budget_modifications = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $overallImpactScore,
                            $overallImpactExplanation,
                            $relevanceScore,
                            $relevanceExplanation,
                            $budgetAcceptable,
                            $budgetModifications ?: null,
                            $reviewId
                        ]);

                        // Log changes
                        if ((int)$oldReview['overall_impact_score'] !== $overallImpactScore) {
                            logAudit('reviews', $reviewId, 'overall_impact_score', $oldReview['overall_impact_score'], $overallImpactScore, 'update');
                        }
                        if ((int)$oldReview['relevance_score'] !== $relevanceScore) {
                            logAudit('reviews', $reviewId, 'relevance_score', $oldReview['relevance_score'], $relevanceScore, 'update');
                        }

                    } else {
                        // Insert new review
                        $stmt = $db->prepare("
                            INSERT INTO reviews (
                                application_id, reviewer_id,
                                overall_impact_score, overall_impact_explanation,
                                relevance_score, relevance_explanation,
                                budget_acceptable, budget_modifications,
                                review_date
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
                        ");
                        $stmt->execute([
                            $applicationId, $userId,
                            $overallImpactScore, $overallImpactExplanation,
                            $relevanceScore, $relevanceExplanation,
                            $budgetAcceptable, $budgetModifications ?: null
                        ]);
                        $reviewId = $db->lastInsertId();
                        logAudit('reviews', $reviewId, 'created', null, 'new review', 'insert');
                    }

                    // Update or insert criteria scores
                    foreach (REVIEW_CRITERIA as $idx => $criterionName) {
                        $score = intval($criteriaScores[$idx]);
                        $strengths = sanitize($criteriaStrengths[$idx]);
                        $weaknesses = sanitize($criteriaWeaknesses[$idx]);

                        // Check if exists
                        $stmt = $db->prepare("SELECT id, score FROM review_criteria_scores WHERE review_id = ? AND criterion_name = ?");
                        $stmt->execute([$reviewId, $criterionName]);
                        $existingCriterion = $stmt->fetch();

                        if ($existingCriterion) {
                            $stmt = $db->prepare("
                                UPDATE review_criteria_scores SET
                                    score = ?, strengths = ?, weaknesses = ?, updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                            ");
                            $stmt->execute([$score, $strengths, $weaknesses, $existingCriterion['id']]);

                            if ((int)$existingCriterion['score'] !== $score) {
                                logAudit('review_criteria_scores', $existingCriterion['id'], 'score', $existingCriterion['score'], $score, 'update');
                            }
                        } else {
                            $stmt = $db->prepare("
                                INSERT INTO review_criteria_scores (review_id, criterion_name, score, strengths, weaknesses)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$reviewId, $criterionName, $score, $strengths, $weaknesses]);
                        }
                    }

                    $db->commit();
                    $message = 'Review saved successfully!';

                } catch (Exception $e) {
                    $db->rollBack();
                    error_log('Review save error: ' . $e->getMessage());
                    $error = 'An error occurred while saving. Please try again.';
                }
            } else {
                $error = implode(', ', $validationErrors);
            }
        } else {
            $sectionScores = $_POST['section_score'] ?? [];
            $sectionSummaries = $_POST['section_summative'] ?? [];
            $sectionStrengths = $_POST['section_strengths'] ?? [];
            $sectionWeaknesses = $_POST['section_weaknesses'] ?? [];

            $validationErrors = [];
            $sectionPayloads = [];
            $overallImpactScore = null;
            $overallImpactExplanation = null;
            $relevanceScore = null;
            $relevanceExplanation = null;

            foreach ($grantSections as $section) {
                $sectionId = (int) $section['id'];
                $scoreRaw = $sectionScores[$sectionId] ?? '';
                $score = $scoreRaw !== '' ? (int) $scoreRaw : null;
                $summative = sanitize($sectionSummaries[$sectionId] ?? '');
                $strengths = sanitize($sectionStrengths[$sectionId] ?? '');
                $weaknesses = sanitize($sectionWeaknesses[$sectionId] ?? '');

                if ($section['is_scored']) {
                    if ($score !== null && ($score < (int) $section['score_min'] || $score > (int) $section['score_max'])) {
                        $validationErrors[] = "Invalid score for {$section['name']}";
                    }
                    if ($section['is_required'] && $score === null) {
                        $validationErrors[] = "Score required for {$section['name']}";
                    }
                } elseif ($score !== null) {
                    $validationErrors[] = "Score not allowed for {$section['name']}";
                }

                if ($section['is_required'] && $summative === '' && $strengths === '' && $weaknesses === '') {
                    $validationErrors[] = "Critiques required for {$section['name']}";
                }

                $normalizedName = strtolower($section['name']);
                if (strpos($normalizedName, 'overall') !== false && $score !== null) {
                    $overallImpactScore = $score;
                    $overallImpactExplanation = $summative;
                }
                if (strpos($normalizedName, 'relevance') !== false && $score !== null) {
                    $relevanceScore = $score;
                    $relevanceExplanation = $summative;
                }

                $sectionPayloads[$sectionId] = [
                    'score' => $section['is_scored'] ? $score : null,
                    'summative' => $summative,
                    'strengths' => $strengths,
                    'weaknesses' => $weaknesses
                ];
            }

            $legacyOverallScore = ($overallImpactScore !== null && $overallImpactScore >= 1 && $overallImpactScore <= 9)
                ? $overallImpactScore
                : null;
            $legacyRelevanceScore = ($relevanceScore !== null && $relevanceScore >= 1 && $relevanceScore <= 9)
                ? $relevanceScore
                : null;

            if (empty($validationErrors)) {
                try {
                    $db->beginTransaction();

                    // Check if review exists
                    $stmt = $db->prepare("SELECT id FROM reviews WHERE application_id = ? AND reviewer_id = ?");
                    $stmt->execute([$applicationId, $userId]);
                    $existingReview = $stmt->fetch();

                    if ($existingReview) {
                        $reviewId = $existingReview['id'];
                        $stmt = $db->prepare("SELECT overall_impact_score, relevance_score FROM reviews WHERE id = ?");
                        $stmt->execute([$reviewId]);
                        $oldReview = $stmt->fetch();

                        $stmt = $db->prepare("
                            UPDATE reviews SET
                                overall_impact_score = ?,
                                overall_impact_explanation = ?,
                                relevance_score = ?,
                                relevance_explanation = ?,
                                budget_acceptable = NULL,
                                budget_modifications = NULL,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $legacyOverallScore,
                            $overallImpactExplanation ?: null,
                            $legacyRelevanceScore,
                            $relevanceExplanation ?: null,
                            $reviewId
                        ]);

                        $oldOverallScore = $oldReview['overall_impact_score'] ?? null;
                        if ($oldOverallScore !== null || $legacyOverallScore !== null) {
                            if ((int)($oldOverallScore ?? 0) !== $legacyOverallScore) {
                                logAudit('reviews', $reviewId, 'overall_impact_score', $oldOverallScore, $legacyOverallScore, 'update');
                            }
                        }
                        $oldRelevanceScore = $oldReview['relevance_score'] ?? null;
                        if ($oldRelevanceScore !== null || $legacyRelevanceScore !== null) {
                            if ((int)($oldRelevanceScore ?? 0) !== $legacyRelevanceScore) {
                                logAudit('reviews', $reviewId, 'relevance_score', $oldRelevanceScore, $legacyRelevanceScore, 'update');
                            }
                        }
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO reviews (
                                application_id, reviewer_id,
                                overall_impact_score, overall_impact_explanation,
                                relevance_score, relevance_explanation,
                                budget_acceptable, budget_modifications,
                                review_date
                            ) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, CURDATE())
                        ");
                        $stmt->execute([
                            $applicationId,
                            $userId,
                            $legacyOverallScore,
                            $overallImpactExplanation ?: null,
                            $legacyRelevanceScore,
                            $relevanceExplanation ?: null
                        ]);
                        $reviewId = $db->lastInsertId();
                        logAudit('reviews', $reviewId, 'created', null, 'new review', 'insert');
                    }

                    $existingSectionScores = getReviewSectionScores($reviewId);
                    foreach ($sectionPayloads as $sectionId => $payload) {
                        $hasContent = $payload['score'] !== null
                            || $payload['summative'] !== ''
                            || $payload['strengths'] !== ''
                            || $payload['weaknesses'] !== '';

                        if ($hasContent) {
                            $stmt = $db->prepare("
                                INSERT INTO review_section_scores
                                    (review_id, grant_section_id, score, summative_comments, strengths, weaknesses)
                                VALUES (?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    score = VALUES(score),
                                    summative_comments = VALUES(summative_comments),
                                    strengths = VALUES(strengths),
                                    weaknesses = VALUES(weaknesses),
                                    updated_at = CURRENT_TIMESTAMP
                            ");
                            $stmt->execute([
                                $reviewId,
                                $sectionId,
                                $payload['score'],
                                $payload['summative'] ?: null,
                                $payload['strengths'] ?: null,
                                $payload['weaknesses'] ?: null
                            ]);
                        } elseif (isset($existingSectionScores[$sectionId])) {
                            $stmt = $db->prepare("DELETE FROM review_section_scores WHERE id = ?");
                            $stmt->execute([$existingSectionScores[$sectionId]['id']]);
                        }
                    }

                    $db->commit();
                    $message = 'Review saved successfully!';

                } catch (Exception $e) {
                    $db->rollBack();
                    error_log('Review save error: ' . $e->getMessage());
                    $error = 'An error occurred while saving. Please try again.';
                }
            } else {
                $error = implode(', ', $validationErrors);
            }
        }
    } // Close the isComplete check
}

// Get my review if exists
$stmt = $db->prepare("SELECT * FROM reviews WHERE application_id = ? AND reviewer_id = ?");
$stmt->execute([$applicationId, $userId]);
$myReview = $stmt->fetch();

// Get my criteria scores
$myCriteria = [];
$mySectionScores = [];
if ($myReview) {
    if ($useLegacySections) {
        $stmt = $db->prepare("SELECT * FROM review_criteria_scores WHERE review_id = ? ORDER BY id");
        $stmt->execute([$myReview['id']]);
        $myCriteria = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $mySectionScores = getReviewSectionScores($myReview['id']);
    }
}

// Get all other reviews (anonymized)
$stmt = $db->prepare("
    SELECT r.*, ass.anonymous_label
    FROM reviews r
    JOIN assignments ass ON r.application_id = ass.application_id AND r.reviewer_id = ass.reviewer_id
    WHERE r.application_id = ? AND r.reviewer_id != ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$applicationId, $userId]);
$otherReviews = $stmt->fetchAll();

$otherReviewSections = [];
if (!$useLegacySections && !empty($otherReviews)) {
    $reviewIds = array_column($otherReviews, 'id');
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
        $otherReviewSections[$row['review_id']][] = $row;
    }
}

// Get discussion messages
$stmt = $db->prepare("
    SELECT dm.*, ass.anonymous_label
    FROM discussion_messages dm
    JOIN assignments ass ON dm.application_id = ass.application_id AND dm.user_id = ass.reviewer_id
    WHERE dm.application_id = ?
    ORDER BY dm.created_at ASC
");
$stmt->execute([$applicationId]);
$discussionMessages = $stmt->fetchAll();

// Handle discussion message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && !$csrfError) {
    $message_text = sanitize($_POST['message']);

    if (!empty($message_text) && hasApplicationAccess($applicationId, $userId)) {
        $stmt = $db->prepare("INSERT INTO discussion_messages (application_id, user_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$applicationId, $userId, $message_text]);
        header('Location: review_application.php?id=' . $applicationId);
        exit;
    }
}

$pageTitle = 'Review Application';
require_once '../includes/header.php';
?>

<!-- SPEC-UIX-002 Milestone 5: Review Form UX Enhancements -->
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/review-form.css">
<script src="<?php echo BASE_URL; ?>/assets/js/review-form-ux.js" defer></script>

<!-- Review Form Layout Container -->
<div class="review-form-layout" style="display: flex; gap: 2rem; align-items: flex-start;">
    <div style="flex: 1; min-width: 0;">


<div class="mb-4">
    <a href="dashboard.php" class="btn btn-secondary btn-sm">← Back to Dashboard</a>
    <a href="discussions.php?app_id=<?php echo $applicationId; ?>" class="btn btn-primary btn-sm">Discussion</a>
    <a href="upload_review.php?app_id=<?php echo $applicationId; ?>" class="btn btn-outline btn-sm">Upload Critique</a>
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
        <p><strong>Applicant:</strong> <?php echo escape($application['applicant_name']); ?></p>
        <p><strong>Study Section:</strong> <?php echo escape($application['study_section_name'] ?? 'N/A'); ?></p>
        <p><strong>Grant Type:</strong> <span class="badge badge-primary"><?php echo escape($application['grant_type_name'] ?? $application['grant_type']); ?></span></p>
        <p><strong>Title:</strong> <?php echo escape($application['application_title']); ?></p>
        <p><strong>Your Role:</strong> <span class="badge badge-secondary"><?php echo escape($myLabel); ?></span></p>
    </div>
</div>

<!-- Other Reviewers' Reviews -->
<?php
// Batch-fetch criteria scores for all other reviews (avoids N+1 inside the loop)
$otherReviewIds = array_column($otherReviews, 'id');
$allOtherCriteria = [];
if (!empty($otherReviewIds)) {
    $placeholders = implode(',', array_fill(0, count($otherReviewIds), '?'));
    $critStmt = $db->prepare("SELECT * FROM review_criteria_scores WHERE review_id IN ($placeholders) ORDER BY review_id, id");
    $critStmt->execute($otherReviewIds);
    while ($row = $critStmt->fetch()) {
        $allOtherCriteria[$row['review_id']][] = $row;
    }
}
?>
<?php if (!empty($otherReviews)): ?>
    <div class="card mb-4">
        <div class="card-header">Other Reviewers' Assessments</div>
        <div class="card-body">
            <?php foreach ($otherReviews as $review): ?>
                <div class="card mb-3" style="border-left: 4px solid var(--secondary-color);">
                    <div class="card-header">
                        <strong><?php echo escape($review['anonymous_label']); ?></strong>
                        <span style="float: right; font-size: 0.875rem;" class="text-muted">
                            Updated: <?php echo formatDateTime($review['updated_at']); ?>
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
                                    <p class="text-muted" style="font-size: 0.875rem;"><?php echo escape(substr($review['overall_impact_explanation'], 0, 150)) . '...'; ?></p>
                                </div>
                                <div>
                                    <p><strong>Relevance:</strong>
                                        <span class="score-display <?php echo getScoreColorClass($review['relevance_score']); ?>">
                                            <?php echo $review['relevance_score']; ?> - <?php echo getScoreLabel($review['relevance_score']); ?>
                                        </span>
                                    </p>
                                    <p class="text-muted" style="font-size: 0.875rem;"><?php echo escape(substr($review['relevance_explanation'], 0, 150)) . '...'; ?></p>
                                </div>
                            </div>

                            <?php
                            $criteria = $allOtherCriteria[$review['id']] ?? [];
                            ?>

                            <div class="d-flex gap-2">
                                <?php foreach ($criteria as $criterion): ?>
                                    <div style="flex: 1; background: var(--light-bg); padding: 0.5rem; border-radius: 0.25rem; text-align: center;">
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo escape($criterion['criterion_name']); ?></div>
                                        <div class="score-display <?php echo getScoreColorClass($criterion['score']); ?>" style="margin-top: 0.25rem; font-size: 1rem;">
                                            <?php echo $criterion['score']; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <?php
                            $sections = $otherReviewSections[$review['id']] ?? [];
                            $summaryText = '';
                            $scoredSections = [];
                            foreach ($sections as $section) {
                                if ($summaryText === '' && !empty($section['summative_comments'])) {
                                    $summaryText = $section['summative_comments'];
                                }
                                if ($section['is_scored'] && $section['score'] !== null) {
                                    $scoredSections[] = $section;
                                }
                            }
                            ?>
                            <?php if (!empty($scoredSections)): ?>
                                <div class="d-flex gap-2 mb-3">
                                    <?php foreach ($scoredSections as $section): ?>
                                        <div style="flex: 1; background: var(--light-bg); padding: 0.5rem; border-radius: 0.25rem; text-align: center;">
                                            <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo escape($section['name']); ?></div>
                                            <div class="score-display <?php echo getScoreColorClass($section['score']); ?>" style="margin-top: 0.25rem; font-size: 1rem;">
                                                <?php echo $section['score']; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($summaryText): ?>
                                <p class="text-muted" style="font-size: 0.875rem;"><?php echo escape(substr($summaryText, 0, 150)) . '...'; ?></p>
                            <?php else: ?>
                                <p class="text-muted" style="font-size: 0.875rem;">No summary provided.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- My Review Form -->
<div class="card mb-4">
    <div class="card-header">
        <?php echo $myReview ? 'Edit My Review' : 'Submit My Review'; ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <?php echo csrfField(); ?>
            <?php if ($useLegacySections): ?>
                <!-- Overall Impact -->
                <div class="form-group">
                    <label class="form-label">Overall Impact Score (1-9, 1=Outstanding, 9=Poor)</label>
                    <select name="overall_impact_score" class="form-control" required>
                        <option value="">-- Select Score --</option>
                        <?php for ($i = 1; $i <= 9; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($myReview && (int)$myReview['overall_impact_score'] === $i) ? 'selected' : ''; ?>>
                                <?php echo $i; ?> - <?php echo getScoreLabel($i); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Overall Impact Explanation</label>
                    <textarea name="overall_impact_explanation" class="form-control" rows="4" required><?php echo $myReview ? escape($myReview['overall_impact_explanation']) : ''; ?></textarea>
                </div>

                <!-- Relevance -->
                <div class="form-group">
                    <label class="form-label">Relevance to RFA Score (1-9)</label>
                    <select name="relevance_score" class="form-control" required>
                        <option value="">-- Select Score --</option>
                        <?php for ($i = 1; $i <= 9; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($myReview && (int)$myReview['relevance_score'] === $i) ? 'selected' : ''; ?>>
                                <?php echo $i; ?> - <?php echo getScoreLabel($i); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Relevance Explanation</label>
                    <textarea name="relevance_explanation" class="form-control" rows="4" required><?php echo $myReview ? escape($myReview['relevance_explanation']) : ''; ?></textarea>
                </div>

                <!-- Review Criteria -->
                <h3 class="mt-4 mb-3">Review Criteria</h3>
                <?php foreach (REVIEW_CRITERIA as $idx => $criterionName): ?>
                    <?php
                    $criterionData = null;
                    if (!empty($myCriteria)) {
                        foreach ($myCriteria as $c) {
                            if ($c['criterion_name'] === $criterionName) {
                                $criterionData = $c;
                                break;
                            }
                        }
                    }
                    ?>
                    <div class="card mb-3">
                        <div class="card-header"><?php echo escape($criterionName); ?></div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">Score (1-9)</label>
                                <select name="criteria_score[<?php echo $idx; ?>]" class="form-control" required>
                                    <option value="">-- Select Score --</option>
                                    <?php for ($i = 1; $i <= 9; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($criterionData && (int)$criterionData['score'] === $i) ? 'selected' : ''; ?>>
                                            <?php echo $i; ?> - <?php echo getScoreLabel($i); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Strengths</label>
                                <textarea name="criteria_strengths[<?php echo $idx; ?>]" class="form-control" rows="3" required data-bullet-editor="auto" data-placeholder="Enter strengths (one per line)..."><?php echo $criterionData ? escape($criterionData['strengths']) : ''; ?></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Weaknesses</label>
                                <textarea name="criteria_weaknesses[<?php echo $idx; ?>]" class="form-control" rows="3" required data-bullet-editor="auto" data-placeholder="Enter weaknesses (one per line)..."><?php echo $criterionData ? escape($criterionData['weaknesses']) : ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Budget (Developmental only) -->
                <?php if (stripos((string) $application['grant_type'], 'Developmental') !== false): ?>
                    <h3 class="mt-4 mb-3">Budget and Period of Support</h3>
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">Budget Acceptable?</label>
                                <select name="budget_acceptable" class="form-control">
                                    <option value="">-- Select --</option>
                                    <option value="1" <?php echo ($myReview && $myReview['budget_acceptable'] === 1) ? 'selected' : ''; ?>>Yes</option>
                                    <option value="0" <?php echo ($myReview && $myReview['budget_acceptable'] === 0) ? 'selected' : ''; ?>>No</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Budget Modifications (if any)</label>
                                <textarea name="budget_modifications" class="form-control" rows="3"><?php echo $myReview ? escape($myReview['budget_modifications']) : ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php foreach ($grantSections as $section): ?>
                    <?php
                    $sectionId = (int) $section['id'];
                    $existing = $mySectionScores[$sectionId] ?? [];
                    $existingScore = $existing['score'] ?? '';
                    $summative = $existing['summative_comments'] ?? '';
                    $strengths = $existing['strengths'] ?? '';
                    $weaknesses = $existing['weaknesses'] ?? '';
                    $scoreMin = $section['score_min'];
                    $scoreMax = $section['score_max'];
                    $scoreRange = $section['is_scored'] ? $scoreMin . ' - ' . $scoreMax : 'N/A';
                    ?>
                    <div class="card mb-3">
                        <div class="card-header">
                            <?php echo escape($section['name']); ?>
                            <?php if ($section['is_required']): ?>
                                <span class="text-muted" style="font-size: 0.85rem;">(Required)</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if ($section['is_scored']): ?>
                                <div class="form-group">
                                    <label class="form-label">Score (<?php echo escape($scoreRange); ?>)</label>
                                    <?php if ($scoreMax - $scoreMin <= 20): ?>
                                        <select name="section_score[<?php echo $sectionId; ?>]" class="form-control" >
                                            <option value="">-- Select Score --</option>
                                            <?php for ($i = (int) $scoreMin; $i <= (int) $scoreMax; $i++): ?>
                                                <option value="<?php echo $i; ?>" <?php echo ($existingScore !== '' && (int) $existingScore === $i) ? 'selected' : ''; ?>>
                                                    <?php echo $i; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="number" name="section_score[<?php echo $sectionId; ?>]" class="form-control"
                                               min="<?php echo (int) $scoreMin; ?>" max="<?php echo (int) $scoreMax; ?>"
                                               value="<?php echo $existingScore !== '' ? (int) $existingScore : ''; ?>"
                                               >
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="form-group">
                                <label class="form-label">Summative Comments</label>
                                <textarea name="section_summative[<?php echo $sectionId; ?>]" class="form-control" rows="3" ><?php echo escape($summative); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Strengths (bullet points)</label>
                                <textarea name="section_strengths[<?php echo $sectionId; ?>]" class="form-control" rows="3" data-bullet-editor="auto" data-placeholder="Enter strengths (one per line)..."><?php echo escape($strengths); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Weaknesses (bullet points)</label>
                                <textarea name="section_weaknesses[<?php echo $sectionId; ?>]" class="form-control" rows="3" data-bullet-editor="auto" data-placeholder="Enter weaknesses (one per line)..."><?php echo escape($weaknesses); ?></textarea>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <button type="submit" name="submit_review" class="btn btn-primary btn-block">
                <?php echo $myReview ? 'Update Review' : 'Submit Review'; ?>
            </button>
        </form>
    </div>
</div>

<!-- End Review Form Layout Container -->
    </div>

    <!-- Right Rail: Summary Card -->
    <div style="width: 300px; flex-shrink: 0;">
        <!-- Summary card will be inserted here by JavaScript -->
    </div>
</div>

<!-- Discussion -->
<div class="card mb-4">
    <div class="card-header">Discussion with Other Reviewers</div>
    <div class="card-body">
        <div class="chat-container">
            <?php if (empty($discussionMessages)): ?>
                <p class="text-muted">No messages yet. Start the discussion!</p>
            <?php else: ?>
                <?php foreach ($discussionMessages as $msg): ?>
                    <div class="chat-message">
                        <div class="chat-message-header">
                            <span class="chat-author"><?php echo escape($msg['anonymous_label']); ?></span>
                            <span class="chat-time"><?php echo formatDateTime($msg['created_at']); ?></span>
                        </div>
                        <div class="chat-content">
                            <?php echo nl2br(escape($msg['message'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form method="POST" class="chat-input-area">
            <?php echo csrfField(); ?>
            <textarea name="message" class="form-control chat-input" placeholder="Type your message..." rows="2" required></textarea>
            <button type="submit" name="send_message" class="btn btn-primary btn-sm mt-2">Send Message</button>
        </form>
    </div>
</div>

<!-- Bullet editor is now auto-initialized via data-bullet-editor="auto" attribute -->
<!-- The AGR.BulletEditor module handles all bullet editor functionality -->

<?php require_once '../includes/footer.php'; ?>
