<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/DocumentParser.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';
$parsedData = null;
$validationErrors = [];
$prefilledApplicationId = isset($_POST['prefilled_application_id']) ? (int) $_POST['prefilled_application_id'] : null;
$prefilledReviewerId = isset($_POST['reviewer_id']) ? (int) $_POST['reviewer_id'] : null;
$csrfError = null;
$parseGrantSections = [];
$parseGrantTypeName = null;

if (isset($_POST['parse_application_id']) && (int) $_POST['parse_application_id'] > 0) {
    $prefilledApplicationId = (int) $_POST['parse_application_id'];
}

if ($prefilledApplicationId) {
    // Single optimized query replaces getApplicationGrantTypeId() + getGrantSections() + getGrantTypeById()
    $grantTypeInfo = getApplicationGrantTypeWithSections($prefilledApplicationId, true);
    if ($grantTypeInfo['grant_type_id']) {
        $parseGrantSections = $grantTypeInfo['sections'];
        $parseGrantTypeName = $grantTypeInfo['grant_type_name'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
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

// Get all applications for dropdown
$stmt = $db->query("
    SELECT a.id, a.grant_id, a.applicant_name, a.application_title, a.study_section_id,
           ss.name as study_section_name
    FROM applications a
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    ORDER BY a.grant_id
");
$applications = $stmt->fetchAll();

// Get all active reviewers with their study section assignments in a single JOIN query
$stmt = $db->query("
    SELECT u.id, u.full_name, u.email,
           GROUP_CONCAT(ssr.study_section_id ORDER BY ssr.study_section_id) AS study_section_ids
    FROM users u
    LEFT JOIN study_section_reviewers ssr ON ssr.reviewer_id = u.id
    WHERE u.role = 'reviewer' AND u.is_active = TRUE
    GROUP BY u.id, u.full_name, u.email
    ORDER BY u.last_name, u.first_name
");
$reviewers = $stmt->fetchAll();

// Build the study section map expected by the JS template below
$reviewerStudySections = [];
foreach ($reviewers as $reviewer) {
    if ($reviewer['study_section_ids'] !== null) {
        $reviewerStudySections[$reviewer['id']] = array_map('intval', explode(',', $reviewer['study_section_ids']));
    } else {
        $reviewerStudySections[$reviewer['id']] = [];
    }
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['review_file']) && !$csrfError && !$error) {
    $upload = validateDocxUpload($_FILES['review_file'], $error);

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
                    if ($prefilledReviewerId) {
                        $_SESSION['prefilled_reviewer_id'] = $prefilledReviewerId;
                    }

                    $message = 'Document parsed successfully. Review the parsed data below and confirm.';
                } else {
                    $error = 'Failed to save uploaded file.';
                }
            }
        }
    }
}

// Handle confirmation and save to database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && !$csrfError) {
    if (!isset($_SESSION['parsed_data']) || !isset($_SESSION['uploaded_file'])) {
        $error = 'No data to import. Please upload a file first.';
    } else {
        $data = $_SESSION['parsed_data'];
        $fileInfo = $_SESSION['uploaded_file'];
        $selectedApplicationId = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;
        $selectedReviewerId = isset($_POST['reviewer_id']) ? (int) $_POST['reviewer_id'] : 0;

        if (!$selectedApplicationId) {
            $error = 'Please select an application (Grant ID).';
        } elseif (!$selectedReviewerId) {
            $error = 'Please select a reviewer.';
        } else {
            try {
                $stmt = $db->prepare("SELECT id, study_section_id FROM applications WHERE id = ?");
                $stmt->execute([$selectedApplicationId]);
                $appRow = $stmt->fetch();
                if (!$appRow) {
                    throw new Exception('Selected application not found.');
                }

                $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'reviewer' AND is_active = TRUE");
                $stmt->execute([$selectedReviewerId]);
                if (!$stmt->fetch()) {
                    throw new Exception('Selected reviewer is not active.');
                }

                if (!empty($appRow['study_section_id'])) {
                    $stmt = $db->prepare("
                        SELECT id FROM study_section_reviewers
                        WHERE study_section_id = ? AND reviewer_id = ?
                    ");
                    $stmt->execute([$appRow['study_section_id'], $selectedReviewerId]);
                    if (!$stmt->fetch()) {
                        throw new Exception('Reviewer is not assigned to this study section.');
                    }
                }

                $db->beginTransaction();

                $applicationId = $selectedApplicationId;
                $grantTypeId = getApplicationGrantTypeId($applicationId);
                $grantSections = $grantTypeId ? getGrantSections($grantTypeId) : [];
                $useLegacySections = empty($grantSections);

                // Check if this reviewer already has a review for this application
                $stmt = $db->prepare("SELECT id FROM reviews WHERE application_id = ? AND reviewer_id = ?");
                $stmt->execute([$applicationId, $selectedReviewerId]);
                if ($stmt->fetch()) {
                    throw new Exception('This reviewer already has a review for this application.');
                }

                $overallImpactScore = $data['overall_impact']['score'] ?? null;
                $relevanceScore = $data['relevance']['score'] ?? null;
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

                // Insert review
                $stmt = $db->prepare("
                    INSERT INTO reviews (
                        application_id,
                        reviewer_id,
                        overall_impact_score,
                        overall_impact_explanation,
                        relevance_score,
                        relevance_explanation,
                        budget_acceptable,
                        budget_modifications,
                        review_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
                ");
                $stmt->execute([
                    $applicationId,
                    $selectedReviewerId,
                    $overallImpactScore,
                    $data['overall_impact']['explanation'],
                    $relevanceScore,
                    $data['relevance']['explanation'],
                    $data['budget']['acceptable'],
                    $data['budget']['modifications'] ?: null
                ]);
                $reviewId = $db->lastInsertId();
                logAudit('reviews', $reviewId, 'created', null, 'imported from file', 'insert');

                if ($useLegacySections) {
                    foreach ($data['criteria'] as $criterion) {
                        $stmt = $db->prepare("
                            INSERT INTO review_criteria_scores (
                                review_id, criterion_name, score, strengths, weaknesses
                            ) VALUES (?, ?, ?, ?, ?)
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
                                    'score' => $data['overall_impact']['score'] ?? null,
                                    'summative' => $data['overall_impact']['explanation'] ?? '',
                                    'strengths' => '',
                                    'weaknesses' => ''
                                ];
                            }
                            if (strpos($normalized, 'relevance') !== false) {
                                $sectionPayloads[$section['id']] = [
                                    'score' => $data['relevance']['score'] ?? null,
                                    'summative' => $data['relevance']['explanation'] ?? '',
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

                // Check if assignment exists, create if not
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignments WHERE application_id = ? AND reviewer_id = ?");
                $stmt->execute([$applicationId, $selectedReviewerId]);
                $row = $stmt->fetch();
                if (($row ? (int) $row['count'] : 0) === 0) {
                    // Get next anonymous label
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM assignments WHERE application_id = ?");
                    $stmt->execute([$applicationId]);
                    $row = $stmt->fetch();
                    $count = $row ? (int) $row['count'] : 0;
                    $anonymousLabel = 'Reviewer ' . chr(65 + $count); // A, B, C, etc.

                    $stmt = $db->prepare("INSERT INTO assignments (application_id, reviewer_id, anonymous_label) VALUES (?, ?, ?)");
                    $stmt->execute([$applicationId, $selectedReviewerId, $anonymousLabel]);
                    logAudit('assignments', $db->lastInsertId(), 'created', null, $anonymousLabel, 'insert');
                }

                // Record uploaded file
                $stmt = $db->prepare("
                    INSERT INTO uploaded_files (
                        application_id, original_filename, stored_filename, file_path, file_size, mime_type, uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $applicationId,
                    $fileInfo['original'],
                    $fileInfo['stored'],
                    $fileInfo['path'],
                    $fileInfo['size'],
                    $fileInfo['mime_type'] ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    Auth::getUserId()
                ]);

                $db->commit();

                $message = 'Review imported successfully and assigned to reviewer!';
                unset($_SESSION['parsed_data']);
                unset($_SESSION['uploaded_file']);
                unset($_SESSION['prefilled_application_id']);
                unset($_SESSION['prefilled_reviewer_id']);
                $parsedData = null;

            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Error importing review: ' . $e->getMessage();
            }
        }
    }
}

// Cancel import
if (isset($_POST['cancel_import']) && !$csrfError) {
    if (!empty($_SESSION['uploaded_file']['path']) && is_file($_SESSION['uploaded_file']['path'])) {
        unlink($_SESSION['uploaded_file']['path']);
    }
    unset($_SESSION['parsed_data']);
    unset($_SESSION['uploaded_file']);
    unset($_SESSION['prefilled_application_id']);
    unset($_SESSION['prefilled_reviewer_id']);
    $parsedData = null;
    $message = 'Import cancelled.';
}

// Restore parsed data from session if exists
if (!$parsedData && isset($_SESSION['parsed_data'])) {
    $parsedData = $_SESSION['parsed_data'];
}

// Get prefilled values if available
$prefilledAppId = $_SESSION['prefilled_application_id'] ?? null;
$prefilledRevId = $_SESSION['prefilled_reviewer_id'] ?? null;

$pageTitle = 'Upload Review Reports';
require_once '../includes/header.php';
?>

<h1 class="mb-4">Upload Review Reports</h1>

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
    <!-- Upload Form -->
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
                    <input
                        type="file"
                        name="review_file"
                        class="form-control"
                        accept=".docx"
                        required
                    >
                    <small class="text-muted">Maximum file size: <?php echo MAX_UPLOAD_SIZE / 1024 / 1024; ?>MB</small>
                </div>

                <button type="submit" class="btn btn-primary">Upload and Parse</button>
            </form>
        </div>
    </div>

    <!-- Instructions -->
    <div class="card mt-3">
        <div class="card-header">Instructions</div>
        <div class="card-body">
            <ol>
                <li><strong>Step 1:</strong> Make sure the application exists in <a href="manage_applications.php">Manage Applications</a></li>
                <li><strong>Step 2:</strong> Upload the review report document (.docx format)</li>
                <li><strong>Step 3:</strong> Review the extracted data for accuracy</li>
                <li><strong>Step 4:</strong> Select the Grant ID (application) and assign a reviewer</li>
                <li><strong>Step 5:</strong> Confirm to import the review into the database</li>
            </ol>

            <p class="mt-3"><strong>Sample files are available in:</strong> sampleReports/</p>
        </div>
    </div>

<?php else: ?>
    <!-- Preview Parsed Data -->
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
                            <p><strong>Strengths:</strong></p>
                            <div><?php echo nl2br(escape($criterion['strengths'])); ?></div>
                            <p class="mt-3"><strong>Weaknesses:</strong></p>
                            <div><?php echo nl2br(escape($criterion['weaknesses'])); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (stripos((string) $parsedData['grant_type'], 'Developmental') !== false && isset($parsedData['budget']['acceptable'])): ?>
                <h3 class="mt-4">Budget</h3>
                <table class="table">
                    <tr>
                        <th>Acceptable:</th>
                        <td>
                            <span class="badge badge-<?php echo $parsedData['budget']['acceptable'] ? 'success' : 'danger'; ?>">
                                <?php echo $parsedData['budget']['acceptable'] ? 'Yes' : 'No'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php if (!empty($parsedData['budget']['modifications'])): ?>
                        <tr>
                            <th>Modifications:</th>
                            <td><?php echo escape($parsedData['budget']['modifications']); ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            <?php endif; ?>

            <hr class="mt-4 mb-4">

            <h3>Assign to Application & Reviewer</h3>
            <form method="POST" id="assignment-form">
                <?php echo csrfField(); ?>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label class="form-label">Select Application (Grant ID) *</label>
                        <select name="application_id" class="form-control" id="application_id" required>
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
                        <small class="text-muted">
                            Don't see the application? <a href="manage_applications.php" target="_blank">Create it first</a>
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Assign to Reviewer *</label>
                        <select name="reviewer_id" class="form-control" id="reviewer_id" required>
                            <option value="">-- Select Reviewer --</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" name="confirm_import" class="btn btn-success">✓ Confirm and Import</button>
                    <button type="button" onclick="cancelImport()" class="btn btn-danger">✗ Cancel</button>
                </div>
            </form>

            <!-- Hidden cancel form -->
            <form id="cancel-form" method="POST" style="display: none;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="cancel_import" value="1">
            </form>

            <script>
            const appStudySections = <?php echo json_encode(array_reduce($applications, function ($carry, $app) {
                $carry[$app['id']] = $app['study_section_id'] ?? null;
                return $carry;
            }, [])); ?>;
            const reviewerData = <?php echo json_encode(array_map(function ($reviewer) use ($reviewerStudySections) {
                return [
                    'id' => $reviewer['id'],
                    'name' => $reviewer['full_name'],
                    'email' => $reviewer['email'],
                    'study_sections' => $reviewerStudySections[$reviewer['id']] ?? [],
                ];
            }, $reviewers)); ?>;

            function renderReviewerOptions(applicationId, preselectId = null) {
                const reviewerSelect = document.getElementById('reviewer_id');
                if (!reviewerSelect) {
                    return;
                }
                reviewerSelect.innerHTML = '<option value="">-- Select Reviewer --</option>';
                const sectionId = appStudySections[applicationId] ?? null;
                reviewerData.forEach(reviewer => {
                    if (!sectionId || reviewer.study_sections.includes(parseInt(sectionId, 10))) {
                        const option = document.createElement('option');
                        option.value = reviewer.id;
                        option.textContent = `${reviewer.name} (${reviewer.email})`;
                        if (preselectId && String(reviewer.id) === String(preselectId)) {
                            option.selected = true;
                        }
                        reviewerSelect.appendChild(option);
                    }
                });
            }

            const applicationSelect = document.getElementById('application_id');
            if (applicationSelect) {
                applicationSelect.addEventListener('change', () => {
                    renderReviewerOptions(applicationSelect.value);
                });
                if (applicationSelect.value) {
                    renderReviewerOptions(applicationSelect.value, <?php echo $prefilledRevId ? (int) $prefilledRevId : 'null'; ?>);
                }
            }

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
