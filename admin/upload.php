<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/DocumentParser.php';
require_once '../includes/file_validation_enhanced.php';

Auth::requireAdmin();

header('Location: upload_review.php');
exit;

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';
$parsedData = null;
$validationErrors = [];

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Verify CSRF token before any POST processing
$csrfError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    }
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['review_file']) && !$csrfError) {
    $file = $_FILES['review_file'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $filename = $file['name'];
        $tmpPath = $file['tmp_name'];
        $fileSize = $file['size'];
        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Validate file
        if ($fileExt !== 'docx') {
            $error = 'Only .docx files are allowed.';
        } elseif ($fileSize > MAX_UPLOAD_SIZE) {
            $error = 'File size exceeds maximum allowed size.';
        } else {
            // Parse the document
            $parser = new DocumentParser();
            $parsedData = $parser->parseFile($tmpPath);

            if ($parsedData === null) {
                $error = 'Failed to parse document: ' . implode(', ', $parser->getErrors());
            } else {
                // Validate parsed data
                $validationErrors = $parser->validateData($parsedData);

                if (empty($validationErrors)) {
                    // Validate magic number to confirm actual DOCX format
                    if (!validateDocxMagicNumber($tmpPath)) {
                        $error = 'File content does not match .docx format.';
                    } elseif (scanForMaliciousContent($tmpPath)) {
                        $error = 'File contains potentially malicious content.';
                    } else {
                        // Use random filename to prevent enumeration
                        $storedFilename = bin2hex(random_bytes(16)) . '.docx';
                        $storedPath = UPLOAD_DIR . $storedFilename;

                        if (move_uploaded_file($tmpPath, $storedPath)) {
                            // Store in session for confirmation
                            $_SESSION['parsed_data'] = $parsedData;
                            $_SESSION['uploaded_file'] = [
                                'original' => $filename,
                                'stored' => $storedFilename,
                                'path' => $storedPath,
                                'size' => $fileSize
                            ];

                            $message = 'Document parsed successfully. Please review and confirm.';
                        } else {
                            $error = 'Failed to save uploaded file.';
                        }
                    }
                }
            }
        }
    } else {
        $error = 'File upload error: ' . $file['error'];
    }
}

// Handle confirmation and save to database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && !$csrfError) {
    if (!isset($_SESSION['parsed_data']) || !isset($_SESSION['uploaded_file'])) {
        $error = 'No data to import. Please upload a file first.';
    } else {
        $data = $_SESSION['parsed_data'];
        $fileInfo = $_SESSION['uploaded_file'];

        try {
            $db->beginTransaction();

            // Check if application already exists
            $stmt = $db->prepare("
                SELECT id FROM applications
                WHERE applicant_name = ? AND application_title = ?
            ");
            $stmt->execute([$data['applicant_name'], $data['application_title']]);
            $existing = $stmt->fetch();

            if ($existing) {
                $applicationId = $existing['id'];
                $message = 'Application already exists. ';
            } else {
                // Insert application
                $stmt = $db->prepare("
                    INSERT INTO applications (applicant_name, application_title, grant_type, status)
                    VALUES (?, ?, ?, 'pending')
                ");
                $stmt->execute([
                    $data['applicant_name'],
                    $data['application_title'],
                    $data['grant_type']
                ]);
                $applicationId = $db->lastInsertId();
                logAudit('applications', $applicationId, 'created', null, $data['applicant_name'], 'insert');
            }

            // Get reviewer ID (or create placeholder if needed)
            // For now, we'll create a review without a specific reviewer
            // Admin will need to assign it later
            $reviewerId = null;

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
                $reviewerId,
                $data['overall_impact']['score'],
                $data['overall_impact']['explanation'],
                $data['relevance']['score'],
                $data['relevance']['explanation'],
                $data['budget']['acceptable'],
                $data['budget']['modifications'] ?: null
            ]);
            $reviewId = $db->lastInsertId();
            logAudit('reviews', $reviewId, 'created', null, 'imported from file', 'insert');

            // Insert criteria scores
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

            // Record uploaded file
            $stmt = $db->prepare("
                INSERT INTO uploaded_files (
                    application_id, original_filename, stored_filename, file_path, file_size, mime_type, uploaded_by
                ) VALUES (?, ?, ?, ?, ?, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ?)
            ");
            $stmt->execute([
                $applicationId,
                $fileInfo['original'],
                $fileInfo['stored'],
                $fileInfo['path'],
                $fileInfo['size'],
                Auth::getUserId()
            ]);

            $db->commit();

            $message .= 'Review imported successfully!';
            unset($_SESSION['parsed_data']);
            unset($_SESSION['uploaded_file']);
            $parsedData = null;

        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Error importing review: ' . $e->getMessage();
        }
    }
}

// Cancel import
if (isset($_POST['cancel_import']) && !$csrfError) {
    unset($_SESSION['parsed_data']);
    unset($_SESSION['uploaded_file']);
    $parsedData = null;
    $message = 'Import cancelled.';
}

// Restore parsed data from session if exists
if (!$parsedData && isset($_SESSION['parsed_data'])) {
    $parsedData = $_SESSION['parsed_data'];
}

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
                <li>Select a review report document (.docx format)</li>
                <li>Click "Upload and Parse" to extract the review data</li>
                <li>Review the extracted data for accuracy</li>
                <li>Confirm to import the review into the database</li>
                <li>Assign reviewers to the application if needed</li>
            </ol>

            <p class="mt-3"><strong>Sample files are available in:</strong> sampleReports/</p>
        </div>
    </div>

<?php else: ?>
    <!-- Preview Parsed Data -->
    <div class="card">
        <div class="card-header">Review Parsed Data</div>
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
                    <td><span class="badge badge-primary"><?php echo escape($parsedData['grant_type']); ?></span></td>
                </tr>
            </table>

            <h3 class="mt-4">Overall Scores</h3>
            <table class="table">
                <tr>
                    <th>Overall Impact:</th>
                    <td>
                        <span class="score-display <?php echo getScoreColorClass($parsedData['overall_impact']['score']); ?>">
                            <?php echo $parsedData['overall_impact']['score']; ?> - <?php echo getScoreLabel($parsedData['overall_impact']['score']); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Explanation:</th>
                    <td><?php echo escape($parsedData['overall_impact']['explanation']); ?></td>
                </tr>
                <tr>
                    <th>Relevance:</th>
                    <td>
                        <span class="score-display <?php echo getScoreColorClass($parsedData['relevance']['score']); ?>">
                            <?php echo $parsedData['relevance']['score']; ?> - <?php echo getScoreLabel($parsedData['relevance']['score']); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Explanation:</th>
                    <td><?php echo escape($parsedData['relevance']['explanation']); ?></td>
                </tr>
            </table>

            <h3 class="mt-4">Review Criteria</h3>
            <?php foreach ($parsedData['criteria'] as $criterion): ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <?php echo escape($criterion['name']); ?>
                        <span class="score-display <?php echo getScoreColorClass($criterion['score']); ?>" style="float: right;">
                            Score: <?php echo $criterion['score']; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <p><strong>Strengths:</strong> <?php echo escape($criterion['strengths']); ?></p>
                        <p><strong>Weaknesses:</strong> <?php echo escape($criterion['weaknesses']); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (stripos((string) $parsedData['grant_type'], 'Developmental') !== false): ?>
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

            <div class="mt-4 d-flex gap-2">
                <form method="POST" style="display: inline;">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="confirm_import" class="btn btn-success">Confirm and Import</button>
                </form>
                <form method="POST" style="display: inline;">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="cancel_import" class="btn btn-danger">Cancel</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
