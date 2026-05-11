<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/DocumentParser.php';
require_once '../includes/file_validation_enhanced.php';

Auth::requireReviewer();

$db = Database::getInstance()->getConnection();
$userId = Auth::getUserId();
$message = '';
$error = '';
$parsedData = null;
$validationErrors = [];
$prefilledApplicationId = isset($_GET['app_id']) ? (int) $_GET['app_id'] : null;
$csrfError = null;
$parseGrantSections = [];
$parseGrantTypeName = null;

if (isset($_POST['parse_application_id']) && (int) $_POST['parse_application_id'] > 0) {
    $prefilledApplicationId = (int) $_POST['parse_application_id'];
}

if ($prefilledApplicationId && !hasApplicationAccess($prefilledApplicationId, $userId)) {
    $prefilledApplicationId = null;
}

if ($prefilledApplicationId) {
    $grantTypeData = getApplicationGrantTypeWithSections($prefilledApplicationId, true);
    $grantTypeId = $grantTypeData['grant_type_id'] ?? null;
    $parseGrantSections = $grantTypeData['sections'] ?? [];
    if (!empty($grantTypeData['grant_type_name'])) {
        $parseGrantTypeName = $grantTypeData['grant_type_name'];
    }
}

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    if (!mkdir(UPLOAD_DIR, 0755, true)) {
        $error = 'Upload directory is not writable.';
    }
} elseif (!is_writable(UPLOAD_DIR)) {
    $error = 'Upload directory is not writable.';
}

// Assigned applications for reviewer
$stmt = $db->prepare("
    SELECT a.id, a.grant_id, a.applicant_name, a.application_title, a.study_section_id,
           ss.name as study_section_name
    FROM assignments ass
    JOIN applications a ON ass.application_id = a.id
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    WHERE ass.reviewer_id = ?
    ORDER BY a.grant_id
");
$stmt->execute([$userId]);
$applications = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['review_file']) && !$csrfError && !$error) {
    $upload = validateDocxUploadEnhanced($_FILES['review_file'], $error);

    if ($upload) {
        $parser = new DocumentParser();
        $parsedData = $parser->parseFile($upload['tmp_path'], !empty($parseGrantSections) ? $parseGrantSections : null);

        if ($parsedData === null) {
            $error = 'Failed to parse document: ' . implode(', ', $parser->getErrors());
        } else {
            if ($parseGrantTypeName) {
                $parsedData['grant_type'] = $parseGrantTypeName;
            }
            $validationErrors = $parser->validateData($parsedData, !empty($parseGrantSections) ? $parseGrantSections : null);

            if (empty($validationErrors)) {
                $storedFilename = generateStoredFilename($upload['original_name']);
                $storedPath = UPLOAD_DIR . $storedFilename;
                while (file_exists($storedPath)) {
                    $storedFilename = generateStoredFilename($upload['original_name']);
                    $storedPath = UPLOAD_DIR . $storedFilename;
                }

                if (move_uploaded_file($upload['tmp_path'], $storedPath)) {
                    $_SESSION['parsed_data'] = $parsedData;
                    $_SESSION['uploaded_file'] = [
                        'original' => $upload['original_name'],
                        'stored' => $storedFilename,
                        'path' => $storedPath,
                        'size' => $upload['file_size'],
                        'mime_type' => $upload['mime_type']
                    ];
                    if ($prefilledApplicationId) {
                        $_SESSION['prefilled_application_id'] = $prefilledApplicationId;
                    }
                    $message = 'Document parsed successfully. Review the data below and confirm.';
                } else {
                    $error = 'Failed to save uploaded file.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && !$csrfError) {
    if (!isset($_SESSION['parsed_data']) || !isset($_SESSION['uploaded_file'])) {
        $error = 'No data to import. Please upload a file first.';
    } else {
        $data = $_SESSION['parsed_data'];
        $fileInfo = $_SESSION['uploaded_file'];
        $selectedApplicationId = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;

        if (!$selectedApplicationId) {
            $error = 'Please select an application.';
        } elseif (!hasApplicationAccess($selectedApplicationId, $userId)) {
            $error = 'You are not assigned to this application.';
        } else {
            $stmt = $db->prepare("SELECT is_complete FROM applications WHERE id = ?");
            $stmt->execute([$selectedApplicationId]);
            $appRow = $stmt->fetch();
            if (!$appRow) {
                $error = 'Application not found.';
            } elseif ((bool) $appRow['is_complete']) {
                $error = 'This application is marked complete and cannot be edited.';
            } else {
                try {
                    $db->beginTransaction();

                    $stmt = $db->prepare("SELECT id FROM reviews WHERE application_id = ? AND reviewer_id = ?");
                    $stmt->execute([$selectedApplicationId, $userId]);
                    $existingReview = $stmt->fetch();

                    $overallImpactScore = $data['overall_impact']['score'] ?? null;
                    $overallImpactExplanation = $data['overall_impact']['explanation'] ?? null;
                    $relevanceScore = $data['relevance']['score'] ?? null;
                    $relevanceExplanation = $data['relevance']['explanation'] ?? null;

                    if (!is_numeric($overallImpactScore) || $overallImpactScore < 1 || $overallImpactScore > 9) {
                        $overallImpactScore = null;
                    } else {
                        $overallImpactScore = (int) $overallImpactScore;
                    }
                    if (!is_numeric($relevanceScore) || $relevanceScore < 1 || $relevanceScore > 9) {
                        $relevanceScore = null;
                    } else {
                        $relevanceScore = (int) $relevanceScore;
                    }

                    if ($existingReview) {
                        $reviewId = $existingReview['id'];
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
                            $overallImpactScore,
                            $overallImpactExplanation,
                            $relevanceScore,
                            $relevanceExplanation,
                            $reviewId
                        ]);
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
                            $selectedApplicationId,
                            $userId,
                            $overallImpactScore,
                            $overallImpactExplanation,
                            $relevanceScore,
                            $relevanceExplanation
                        ]);
                        $reviewId = $db->lastInsertId();
                        logAudit('reviews', $reviewId, 'created', null, 'imported from file', 'insert');
                    }

                    $grantTypeId = getApplicationGrantTypeId($selectedApplicationId);
                    $grantSections = $grantTypeId ? getGrantSections($grantTypeId) : [];
                    $useLegacySections = empty($grantSections);

                    if ($useLegacySections) {
                        foreach ($data['criteria'] as $criterion) {
                            $stmt = $db->prepare("
                                INSERT INTO review_criteria_scores (review_id, criterion_name, score, strengths, weaknesses)
                                VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    score = VALUES(score),
                                    strengths = VALUES(strengths),
                                    weaknesses = VALUES(weaknesses),
                                    updated_at = CURRENT_TIMESTAMP
                            ");
                            $stmt->execute([
                                $reviewId,
                                $criterion['name'],
                                $criterion['score'],
                                $criterion['strengths'],
                                $criterion['weaknesses']
                            ]);
                        }
                    } else {
                        $sectionMap = [];
                        foreach ($grantSections as $section) {
                            $sectionMap[strtolower($section['name'])] = $section['id'];
                        }

                        $sectionPayloads = [];
                        $parsedSections = $data['sections'] ?? [];

                        if (!empty($parsedSections)) {
                            foreach ($parsedSections as $sectionData) {
                                $normalized = strtolower($sectionData['name'] ?? '');
                                $matchedId = $sectionMap[$normalized] ?? null;
                                if (!$matchedId) {
                                    foreach ($sectionMap as $name => $id) {
                                        if ($normalized !== '' && (strpos($name, $normalized) !== false || strpos($normalized, $name) !== false)) {
                                            $matchedId = $id;
                                            break;
                                        }
                                    }
                                }
                                if ($matchedId) {
                                    $sectionPayloads[$matchedId] = [
                                        'score' => $sectionData['score'] ?? null,
                                        'summative' => $sectionData['summative_comments'] ?? '',
                                        'strengths' => $sectionData['strengths'] ?? '',
                                        'weaknesses' => $sectionData['weaknesses'] ?? ''
                                    ];
                                }
                            }
                        } else {
                            foreach ($grantSections as $section) {
                                $normalized = strtolower($section['name']);
                                if (strpos($normalized, 'overall') !== false) {
                                    $sectionPayloads[$section['id']] = [
                                        'score' => $overallImpactScore,
                                        'summative' => $overallImpactExplanation ?? '',
                                        'strengths' => '',
                                        'weaknesses' => ''
                                    ];
                                }
                                if (strpos($normalized, 'relevance') !== false) {
                                    $sectionPayloads[$section['id']] = [
                                        'score' => $relevanceScore,
                                        'summative' => $relevanceExplanation ?? '',
                                        'strengths' => '',
                                        'weaknesses' => ''
                                    ];
                                }
                            }

                            foreach ($data['criteria'] as $criterion) {
                                $normalized = strtolower($criterion['name']);
                                $matchedId = $sectionMap[$normalized] ?? null;
                                if (!$matchedId) {
                                    foreach ($sectionMap as $name => $id) {
                                        if (strpos($name, $normalized) !== false || strpos($normalized, $name) !== false) {
                                            $matchedId = $id;
                                            break;
                                        }
                                    }
                                }
                                if ($matchedId) {
                                    $sectionPayloads[$matchedId] = [
                                        'score' => $criterion['score'] ?? null,
                                        'summative' => $criterion['summative_comments'] ?? '',
                                        'strengths' => $criterion['strengths'] ?? '',
                                        'weaknesses' => $criterion['weaknesses'] ?? ''
                                    ];
                                }
                            }
                        }

                        foreach ($sectionPayloads as $sectionId => $payload) {
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
                        }
                    }

                    $stmt = $db->prepare("
                        INSERT INTO uploaded_files (
                            application_id, original_filename, stored_filename, file_path, file_size, mime_type, uploaded_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $selectedApplicationId,
                        $fileInfo['original'],
                        $fileInfo['stored'],
                        $fileInfo['path'],
                        $fileInfo['size'],
                        $fileInfo['mime_type'] ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        $userId
                    ]);

                    $db->commit();
                    $message = 'Review imported successfully.';
                    unset($_SESSION['parsed_data'], $_SESSION['uploaded_file'], $_SESSION['prefilled_application_id']);
                    $parsedData = null;
                } catch (Exception $e) {
                    $db->rollBack();
                    error_log('Review import error: ' . $e->getMessage());
                    $error = 'An error occurred while saving. Please try again.';
                }
            }
        }
    }
}

if (isset($_POST['cancel_import']) && !$csrfError) {
    if (!empty($_SESSION['uploaded_file']['path']) && is_file($_SESSION['uploaded_file']['path'])) {
        unlink($_SESSION['uploaded_file']['path']);
    }
    unset($_SESSION['parsed_data'], $_SESSION['uploaded_file'], $_SESSION['prefilled_application_id']);
    $parsedData = null;
    $message = 'Import cancelled.';
}

if (!$parsedData && isset($_SESSION['parsed_data'])) {
    $parsedData = $_SESSION['parsed_data'];
}

$prefilledAppId = $_SESSION['prefilled_application_id'] ?? $prefilledApplicationId;

$pageTitle = 'Upload Critique';
require_once '../includes/header.php';
?>

<h1 class="mb-4">Upload Critique Document</h1>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo escape($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo escape($error); ?></div>
<?php endif; ?>

<?php if (!empty($validationErrors)): ?>
    <div class="alert alert-warning">
        <strong>Validation Errors:</strong>
        <ul>
            <?php foreach ($validationErrors as $err): ?>
                <li><?php echo escape($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$parsedData): ?>
    <div class="card">
        <div class="card-header">Upload Review Document</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label class="form-label">Optional: Select Application for Template Matching</label>
                    <select name="parse_application_id" class="form-control">
                        <option value="">-- Select Application --</option>
                        <?php foreach ($applications as $app): ?>
                            <option value="<?php echo $app['id']; ?>" <?php echo ($prefilledAppId && (int)$prefilledAppId === (int)$app['id']) ? 'selected' : ''; ?>>
                                <?php
                                $studyLabel = $app['study_section_name'] ? ' (' . $app['study_section_name'] . ')' : '';
                                ?>
                                <?php echo escape($app['grant_id']); ?> - <?php echo escape($app['applicant_name']); ?><?php echo escape($studyLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Select an application to match custom section names.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Select .docx file</label>
                    <input type="file" name="review_file" class="form-control" accept=".docx" required>
                    <small class="text-muted">Maximum file size: <?php echo MAX_UPLOAD_SIZE / 1024 / 1024; ?>MB</small>
                </div>
                <button type="submit" class="btn btn-primary">Upload and Parse</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">Review Parsed Data & Assign</div>
        <div class="card-body">
            <h3>Application Information</h3>
            <table class="table">
                <tr>
                    <th>Applicant Name:</th>
                    <td><?php echo escape($parsedData['applicant_name']); ?></td>
                </tr>
                <tr>
                    <th>Application Title:</th>
                    <td><?php echo escape($parsedData['application_title']); ?></td>
                </tr>
                <tr>
                    <th>Grant Type:</th>
                    <td><span class="badge badge-primary"><?php echo escape($parsedData['grant_type'] ?: 'N/A'); ?></span></td>
                </tr>
            </table>

            <?php if (array_key_exists('sections', $parsedData)): ?>
                <h3 class="mt-4">Parsed Sections</h3>
                <?php if (empty($parsedData['sections'])): ?>
                    <p class="text-muted">No matching sections were detected in the document.</p>
                <?php else: ?>
                    <?php foreach ($parsedData['sections'] as $section): ?>
                        <div class="card mb-3">
                            <div class="card-header">
                                <?php echo escape($section['name']); ?>
                                <?php if ($section['score'] !== null): ?>
                                    <span class="score-display <?php echo getScoreColorClass($section['score']); ?>" style="float: right;">
                                        Score: <?php echo $section['score']; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($section['summative_comments'])): ?>
                                    <p><strong>Summative Comments:</strong></p>
                                    <div><?php echo nl2br(escape($section['summative_comments'])); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($section['strengths'])): ?>
                                    <p class="mt-3"><strong>Strengths:</strong></p>
                                    <div><?php echo nl2br(escape($section['strengths'])); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($section['weaknesses'])): ?>
                                    <p class="mt-3"><strong>Weaknesses:</strong></p>
                                    <div><?php echo nl2br(escape($section['weaknesses'])); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php else: ?>
                <h3 class="mt-4">Overall Sections</h3>
                <div class="card mb-3">
                    <div class="card-header">
                        Overall Impact
                        <span class="score-display <?php echo getScoreColorClass($parsedData['overall_impact']['score']); ?>" style="float: right;">
                            Score: <?php echo $parsedData['overall_impact']['score']; ?> - <?php echo getScoreLabel($parsedData['overall_impact']['score']); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <p><strong>Explanation:</strong></p>
                        <p><?php echo nl2br(escape($parsedData['overall_impact']['explanation'])); ?></p>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        Relevance to RFA
                        <span class="score-display <?php echo getScoreColorClass($parsedData['relevance']['score']); ?>" style="float: right;">
                            Score: <?php echo $parsedData['relevance']['score']; ?> - <?php echo getScoreLabel($parsedData['relevance']['score']); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <p><strong>Explanation:</strong></p>
                        <p><?php echo nl2br(escape($parsedData['relevance']['explanation'])); ?></p>
                    </div>
                </div>

                <h3 class="mt-4">Review Sections</h3>
                <?php foreach ($parsedData['criteria'] as $criterion): ?>
                    <div class="card mb-3">
                        <div class="card-header">
                            <?php echo escape($criterion['name']); ?>
                            <span class="score-display <?php echo getScoreColorClass($criterion['score']); ?>" style="float: right;">
                                Score: <?php echo $criterion['score']; ?> - <?php echo getScoreLabel($criterion['score']); ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($criterion['summative_comments'])): ?>
                                <p><strong>Summative Comments:</strong></p>
                                <div><?php echo nl2br(escape($criterion['summative_comments'])); ?></div>
                            <?php endif; ?>
                            <p class="mt-3"><strong>Strengths:</strong></p>
                            <div><?php echo nl2br(escape($criterion['strengths'])); ?></div>
                            <p class="mt-3"><strong>Weaknesses:</strong></p>
                            <div><?php echo nl2br(escape($criterion['weaknesses'])); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <hr class="mt-4 mb-4">

            <h3>Assign to Application</h3>
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label class="form-label">Select Application (Grant ID) *</label>
                    <select name="application_id" class="form-control" required>
                        <option value="">-- Select Grant ID --</option>
                        <?php foreach ($applications as $app): ?>
                            <option value="<?php echo $app['id']; ?>" <?php echo ($prefilledAppId && (int)$prefilledAppId === (int)$app['id']) ? 'selected' : ''; ?>>
                                <?php
                                $studyLabel = $app['study_section_name'] ? ' (' . $app['study_section_name'] . ')' : '';
                                ?>
                                <?php echo escape($app['grant_id']); ?> - <?php echo escape($app['applicant_name']); ?><?php echo escape($studyLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" name="confirm_import" class="btn btn-success">✓ Confirm and Import</button>
                    <button type="button" onclick="cancelImport()" class="btn btn-danger">✗ Cancel</button>
                </div>
            </form>

            <form id="cancel-form" method="POST" style="display: none;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="cancel_import" value="1">
            </form>

            <script>
            function cancelImport() {
                if (confirm('Cancel this import? The uploaded file will be discarded.')) {
                    document.getElementById('cancel-form').submit();
                }
            }
            </script>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
