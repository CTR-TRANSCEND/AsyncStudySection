<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';
$csrfError = null;
$grantTypes = getGrantTypes(true);
$studySections = getStudySections(true);

$grantTypeNameById = [];
$grantTypeIdByName = [];
foreach ($grantTypes as $grantType) {
    $grantTypeNameById[$grantType['id']] = $grantType['name'];
    $grantTypeIdByName[strtolower($grantType['name'])] = $grantType['id'];
    if (stripos($grantType['name'], 'Pilot') !== false) {
        $grantTypeIdByName['pilot'] = $grantType['id'];
    }
    if (stripos($grantType['name'], 'Developmental') !== false) {
        $grantTypeIdByName['developmental'] = $grantType['id'];
    }
}

$studySectionGrantTypes = [];
$stmt = $db->query("SELECT study_section_id, grant_type_id FROM study_section_grant_types");
foreach ($stmt->fetchAll() as $row) {
    $studySectionGrantTypes[(int) $row['study_section_id']][] = (int) $row['grant_type_id'];
}
foreach ($studySections as $studySection) {
    $sectionId = (int) $studySection['id'];
    if (empty($studySectionGrantTypes[$sectionId]) && !empty($studySection['grant_type_id'])) {
        $studySectionGrantTypes[$sectionId] = [(int) $studySection['grant_type_id']];
    }
}

// Handle bulk upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_applications']) && !$csrfError) {
    if (isset($_FILES['application_file']) && $_FILES['application_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['application_file'];
        $tmpPath = $file['tmp_name'];
        $fileExt = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

        if ($fileExt !== 'csv') {
            $error = 'Only .csv files are supported for bulk import.';
        } elseif (($file['size'] ?? 0) > MAX_UPLOAD_SIZE) {
            $error = 'File size exceeds maximum allowed size.';
        } else {
        $handle = fopen($tmpPath, 'r');
        if ($handle) {
            // CR6-23: Use try/finally so fclose() is always called even on exception
            try {
            $header = fgetcsv($handle); // Skip header row
            $imported = 0;
            $errors_list = [];

            // CR6-10: Wrap CSV import in a transaction to reduce per-row round-trips
            $db->beginTransaction();

            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) < 4) continue; // Skip incomplete rows

                $grant_id = $data[0] ?? '';
                $applicant_name = $data[1] ?? '';
                $application_title = $data[2] ?? '';
                $grant_type = $data[3] ?? '';
                $study_section_name = $data[4] ?? '';
                $grant_id = sanitize($grant_id);
                $applicant_name = sanitize($applicant_name);
                $application_title = sanitize($application_title);
                $grant_type = sanitize($grant_type);
                $study_section_name = sanitize($study_section_name);

                // Validate
                if (empty($grant_id) || empty($applicant_name) || empty($application_title)) {
                    $errors_list[] = "Skipped row: Missing required fields for $grant_id";
                    continue;
                }

                $grantTypeId = null;
                $studySectionId = null;
                if ($study_section_name !== '') {
                    foreach ($studySections as $studySection) {
                        if (strcasecmp($studySection['name'], $study_section_name) === 0) {
                            $studySectionId = $studySection['id'];
                            break;
                        }
                    }
                    if (!$studySectionId) {
                        $errors_list[] = "Skipped $grant_id: Unknown study section \"$study_section_name\"";
                        continue;
                    }

                    $allowedGrantTypeIds = $studySectionGrantTypes[$studySectionId] ?? [];
                    if ($grant_type !== '') {
                        $grantTypeId = $grantTypeIdByName[strtolower($grant_type)] ?? null;
                        if (!$grantTypeId) {
                            $errors_list[] = "Skipped $grant_id: Unknown grant type \"$grant_type\"";
                            continue;
                        }
                        if (!empty($allowedGrantTypeIds) && !in_array($grantTypeId, $allowedGrantTypeIds, true)) {
                            $errors_list[] = "Skipped $grant_id: Grant type \"$grant_type\" not allowed for study section \"$study_section_name\"";
                            continue;
                        }
                        $grant_type = $grantTypeNameById[$grantTypeId] ?? $grant_type;
                    } elseif (count($allowedGrantTypeIds) === 1) {
                        $grantTypeId = $allowedGrantTypeIds[0];
                        $grant_type = $grantTypeNameById[$grantTypeId] ?? $grant_type;
                    } elseif (!empty($allowedGrantTypeIds)) {
                        $errors_list[] = "Skipped $grant_id: Study section \"$study_section_name\" requires a grant type.";
                        continue;
                    }
                }

                if (!$grantTypeId && $grant_type !== '') {
                    $grantTypeId = $grantTypeIdByName[strtolower($grant_type)] ?? null;
                    if ($grantTypeId) {
                        $grant_type = $grantTypeNameById[$grantTypeId] ?? $grant_type;
                    }
                }

                if (!$grantTypeId) {
                    $grantTypeId = $grantTypes[0]['id'] ?? null;
                    $grant_type = $grantTypes[0]['name'] ?? 'TRANSCEND Pilot';
                }

                try {
                    $stmt = $db->prepare("
                        INSERT INTO applications (
                            grant_id, applicant_name, application_title,
                            grant_type, grant_type_id, study_section_id, status
                        ) VALUES (?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([
                        $grant_id,
                        $applicant_name,
                        $application_title,
                        $grant_type,
                        $grantTypeId,
                        $studySectionId
                    ]);
                    logAudit('applications', $db->lastInsertId(), 'created', null, 'imported from CSV', 'insert');
                    $imported++;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $errors_list[] = "Skipped $grant_id: Grant ID already exists";
                    } else {
                        $errors_list[] = "Error importing $grant_id";
                    }
                }
            }
            $db->commit();

            $message = "Imported $imported applications successfully.";
            if (!empty($errors_list)) {
                $error = implode("\n", $errors_list);
            }
            } catch (PDOException $e) {
                $db->rollBack();
                $error = 'Import failed due to a database error. No records were saved.';
            } finally {
                fclose($handle);
            }
        } else {
            $error = 'Failed to read uploaded file.';
        }
        }
    } else {
        $error = 'No file uploaded or upload error.';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfError) {
    if (isset($_POST['save_applications'])) {
        $applications = $_POST['applications'] ?? [];

        try {
            $db->beginTransaction();

            foreach ($applications as $appData) {
                $id = $appData['id'] ?? null;
                $grantId = sanitize($appData['grant_id']);
                $applicantName = sanitize($appData['applicant_name']);
                $title = sanitize($appData['application_title']);
                $grantTypeId = isset($appData['grant_type_id']) ? (int) $appData['grant_type_id'] : 0;
                $studySectionId = isset($appData['study_section_id']) ? (int) $appData['study_section_id'] : 0;
                // CR6-07: studySectionGrantTypes stores arrays; take first element as scalar
                if ($studySectionId && isset($studySectionGrantTypes[$studySectionId])) {
                    $grantTypeId = $studySectionGrantTypes[$studySectionId][0] ?? null;
                }
                $grantType = $grantTypeNameById[$grantTypeId] ?? 'TRANSCEND Pilot';

                if (empty($grantId) || empty($applicantName) || empty($title)) {
                    continue; // Skip empty rows
                }

                if ($id && $id > 0) {
                    // Update existing
                    $stmt = $db->prepare("
                        UPDATE applications
                        SET grant_id = ?, applicant_name = ?, application_title = ?,
                            grant_type = ?, grant_type_id = ?, study_section_id = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $grantId,
                        $applicantName,
                        $title,
                        $grantType,
                        $grantTypeId ?: null,
                        $studySectionId ?: null,
                        $id
                    ]);
                    logAudit('applications', $id, 'updated', null, 'bulk edit', 'update');
                } else {
                    // Insert new
                    $stmt = $db->prepare("
                        INSERT INTO applications (
                            grant_id, applicant_name, application_title,
                            grant_type, grant_type_id, study_section_id, status
                        ) VALUES (?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([
                        $grantId,
                        $applicantName,
                        $title,
                        $grantType,
                        $grantTypeId ?: null,
                        $studySectionId ?: null
                    ]);
                    logAudit('applications', $db->lastInsertId(), 'created', null, 'bulk edit', 'insert');
                }
            }

            $db->commit();
            $message = 'Applications saved successfully!';
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Error saving applications: ' . $e->getMessage();
        }
    } elseif (isset($_POST['delete_application'])) {
        $appId = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;
        try {
            if ($appId > 0) {
                $stmt = $db->prepare("DELETE FROM applications WHERE id = ?");
                $stmt->execute([$appId]);
                logAudit('applications', $appId, 'deleted', null, null, 'delete');
                $message = 'Application deleted successfully.';
            }
        } catch (Exception $e) {
            $error = 'Error deleting application: ' . $e->getMessage();
        }
    }
}

// Get all applications (limited to 100 most recent to prevent memory exhaustion)
$stmt = $db->query("
    SELECT a.*, ss.name as study_section_name, COALESCE(gt.name, a.grant_type) as grant_type_name
    FROM applications a
    LEFT JOIN study_sections ss ON a.study_section_id = ss.id
    LEFT JOIN grant_types gt ON gt.id = a.grant_type_id
    ORDER BY a.created_at DESC LIMIT 100
");
$applications = $stmt->fetchAll();

// Get all active reviewers for dropdown
$stmt = $db->query("SELECT id, full_name, email FROM users WHERE role = 'reviewer' AND is_active = TRUE ORDER BY last_name, first_name");
$reviewers = $stmt->fetchAll();

$reviewerStudySections = [];
$stmt = $db->query("SELECT study_section_id, reviewer_id FROM study_section_reviewers");
foreach ($stmt->fetchAll() as $row) {
    $reviewerStudySections[$row['reviewer_id']][] = $row['study_section_id'];
}

foreach ($applications as &$app) {
    if (empty($app['grant_type_id'])) {
        foreach ($grantTypes as $grantType) {
            if ($grantType['name'] === $app['grant_type']) {
                $app['grant_type_id'] = $grantType['id'];
                break;
            }
        }
    }
}
unset($app);

$pageTitle = 'Manage Applications';
require_once '../includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <h1>Manage Applications</h1>
    <a href="applications.php" class="btn btn-secondary">View All Applications</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo escape($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo nl2br(escape($error)); ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header">Bulk Import Applications from CSV</div>
    <div class="card-body">
        <div class="grid grid-2">
            <div>
                <h4>Upload CSV File</h4>
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    <div class="form-group">
                        <label class="form-label">Select CSV file</label>
                        <input type="file" name="application_file" class="form-control" accept=".csv" required>
                        <small class="text-muted">CSV format: grant_id, applicant_name, application_title, grant_type, study_section (optional)</small>
                    </div>
                    <button type="submit" name="upload_applications" class="btn btn-primary">Upload and Import</button>
                </form>
            </div>
            <div>
                <h4>Download Template</h4>
                <p>Download the CSV template with sample data to see the required format.</p>
                <a href="download_application_template.php" class="btn btn-success" download>
                    Download Template
                </a>
                <p class="mt-3 text-muted">
                    <strong>Template columns:</strong><br>
                    1. grant_id (unique)<br>
                    2. applicant_name (PI Name)<br>
                    3. application_title<br>
                    4. grant_type (name)<br>
                    5. study_section (optional, name)
                </p>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">Instructions</div>
    <div class="card-body">
        <ul>
            <li>Edit existing applications in the table below</li>
            <li>Add new applications by clicking "Add New Row" button</li>
            <li>Upload review reports directly using the "Rpt" button for each application</li>
            <li>Click "Save All Changes" when done</li>
            <li>Grant ID should be unique for each application</li>
        </ul>
    </div>
</div>

<form method="POST">
    <?php echo csrfField(); ?>
    <div class="mb-3 d-flex justify-between align-center">
        <button type="submit" name="save_applications" class="btn btn-success">💾 Save All Changes</button>
        <button type="button" onclick="addNewRow()" class="btn btn-primary">➕ Add New Row</button>
    </div>

    <div class="table-wrapper">
        <table class="app-table">
            <thead>
                <tr>
                    <th style="width: 120px;">Grant ID</th>
                    <th style="width: 180px;">PI Name</th>
                    <th style="width: 300px;">Application Title</th>
                    <th style="width: 180px;">Study Section</th>
                    <th style="width: 160px;">Grant Type</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 80px;">Actions</th>
                </tr>
            </thead>
            <tbody id="applications-tbody">
                <?php foreach ($applications as $app): ?>
                    <tr>
                        <td>
                            <input type="hidden" name="applications[<?php echo (int) $app['id']; ?>][id]" value="<?php echo (int) $app['id']; ?>">
                            <input type="text" name="applications[<?php echo (int) $app['id']; ?>][grant_id]"
                                   value="<?php echo escape($app['grant_id'] ?? ''); ?>" required>
                        </td>
                        <td>
                            <input type="text" name="applications[<?php echo (int) $app['id']; ?>][applicant_name]"
                                   value="<?php echo escape($app['applicant_name']); ?>" required>
                        </td>
                        <td>
                            <textarea name="applications[<?php echo (int) $app['id']; ?>][application_title]"
                                      required><?php echo escape($app['application_title']); ?></textarea>
                        </td>
                        <td>
                            <select name="applications[<?php echo (int) $app['id']; ?>][study_section_id]" class="study-section-select" data-row-id="<?php echo (int) $app['id']; ?>">
                                <option value="">-- None --</option>
                                <?php foreach ($studySections as $studySection): ?>
                                    <option value="<?php echo (int) $studySection['id']; ?>"
                                        <?php echo (int)$app['study_section_id'] === (int)$studySection['id'] ? 'selected' : ''; ?>>
                                        <?php echo escape($studySection['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="applications[<?php echo (int) $app['id']; ?>][grant_type_id]" class="grant-type-select" data-row-id="<?php echo (int) $app['id']; ?>" required>
                                <?php foreach ($grantTypes as $grantType): ?>
                                    <option value="<?php echo (int) $grantType['id']; ?>"
                                        <?php echo ((int)$app['grant_type_id'] === (int)$grantType['id']) ? 'selected' : ''; ?>>
                                        <?php echo escape($grantType['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $app['status'] === 'completed' ? 'success' : 'warning'; ?>">
                                <?php echo escape($app['status']); ?>
                            </span>
                        </td>
                        <td style="white-space: nowrap;">
                            <button type="button" onclick="uploadReport(<?php echo (int) $app['id']; ?>, '<?php echo escapeJs($app['grant_id'] ?? ''); ?>', <?php echo $app['study_section_id'] ? (int) $app['study_section_id'] : 0; ?>)"
                                    class="btn btn-primary btn-icon" title="Upload Review Report">Rpt</button>
                            <button type="button" onclick="deleteApplication(<?php echo (int) $app['id']; ?>, '<?php echo escapeJs($app['grant_id'] ?? ''); ?>')"
                                    class="btn btn-danger btn-icon" title="Delete Application">🗑️</button>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <!-- No default empty rows - use "Add New Row" button instead -->
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        <button type="submit" name="save_applications" class="btn btn-success btn-block">💾 Save All Changes</button>
    </div>
</form>

<!-- Delete confirmation form (hidden) -->
<form id="delete-form" method="POST" style="display: none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="delete_application" value="1">
    <input type="hidden" name="application_id" id="delete-app-id">
</form>

<!-- Upload Report Modal -->
<div id="uploadReportModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; overflow-y: auto;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 0.5rem; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h3 class="mb-3">Upload Review Report</h3>
        <p class="mb-3"><strong>Grant ID:</strong> <span id="upload-grant-id"></span></p>

        <form method="POST" action="upload_review.php" enctype="multipart/form-data" id="upload-report-form">
            <?php echo csrfField(); ?>
            <input type="hidden" name="prefilled_application_id" id="prefilled-app-id">

            <div class="form-group">
                <label class="form-label">Select Review Report (.docx)</label>
                <input type="file" name="review_file" class="form-control" accept=".docx" required id="report-file-input">
                <small class="text-muted">Maximum file size: <?php echo MAX_UPLOAD_SIZE / 1024 / 1024; ?>MB</small>
            </div>

            <div class="form-group">
                <label class="form-label">Assign to Reviewer</label>
                <select name="reviewer_id" class="form-control" required>
                    <option value="">-- Select Reviewer --</option>
                </select>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary">Upload and Parse</button>
                <button type="button" class="btn btn-secondary" onclick="closeUploadModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
let newRowCounter = 1;
const studySections = <?php echo json_encode($studySections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const grantTypes = <?php echo json_encode($grantTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const studySectionGrantTypes = <?php echo json_encode($studySectionGrantTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const reviewerData = <?php echo json_encode(array_map(function ($reviewer) use ($reviewerStudySections) {
    return [
        'id' => $reviewer['id'],
        'name' => $reviewer['full_name'],
        'email' => $reviewer['email'],
        'study_sections' => $reviewerStudySections[$reviewer['id']] ?? [],
    ];
}, $reviewers), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

function buildStudySectionOptions(selectedId = '') {
    let options = '<option value="">-- None --</option>';
    studySections.forEach(section => {
        const selected = String(section.id) === String(selectedId) ? 'selected' : '';
        options += `<option value="${section.id}" ${selected}>${section.name}</option>`;
    });
    return options;
}

function buildGrantTypeOptions(selectedId = '') {
    let options = '';
    grantTypes.forEach(type => {
        const selected = String(type.id) === String(selectedId) ? 'selected' : '';
        options += `<option value="${type.id}" ${selected}>${type.name}</option>`;
    });
    return options;
}

function addNewRow() {
    const tbody = document.getElementById('applications-tbody');
    const newRow = document.createElement('tr');
    newRow.className = 'new-row';
    newRow.innerHTML = `
        <td>
            <input type="text" name="applications[new_${newRowCounter}][grant_id]"
                   placeholder="e.g., GRANT-2024-001">
        </td>
        <td>
            <input type="text" name="applications[new_${newRowCounter}][applicant_name]"
                   placeholder="PI Name">
        </td>
        <td>
            <textarea name="applications[new_${newRowCounter}][application_title]"
                      placeholder="Application Title"></textarea>
        </td>
        <td>
            <select name="applications[new_${newRowCounter}][study_section_id]" class="study-section-select" data-row-id="new_${newRowCounter}">
                ${buildStudySectionOptions()}
            </select>
        </td>
        <td>
            <select name="applications[new_${newRowCounter}][grant_type_id]" class="grant-type-select" data-row-id="new_${newRowCounter}">
                ${buildGrantTypeOptions(grantTypes[0]?.id ?? '')}
            </select>
        </td>
        <td>
            <span class="badge badge-secondary">New</span>
        </td>
        <td>
            <button type="button" onclick="this.closest('tr').remove()" class="btn btn-danger btn-icon">🗑️</button>
        </td>
    `;
    tbody.appendChild(newRow);
    attachStudySectionHandlers(newRow);
    newRowCounter++;
}

function attachStudySectionHandlers(scope = document) {
    scope.querySelectorAll('.study-section-select').forEach(select => {
        select.addEventListener('change', () => {
            const studySectionId = select.value;
            const rowId = select.dataset.rowId;
            if (!rowId) return;
            const grantSelect = document.querySelector(`.grant-type-select[data-row-id="${rowId}"]`);
            if (grantSelect && studySectionId && studySectionGrantTypes[studySectionId]) {
                grantSelect.value = studySectionGrantTypes[studySectionId];
            }
        });
    });
}

function renderReviewerOptions(studySectionId) {
    const reviewerSelect = document.querySelector('#upload-report-form select[name="reviewer_id"]');
    if (!reviewerSelect) return;
    reviewerSelect.innerHTML = '<option value="">-- Select Reviewer --</option>';
    reviewerData.forEach(reviewer => {
        if (!studySectionId || reviewer.study_sections.includes(parseInt(studySectionId, 10))) {
            const option = document.createElement('option');
            option.value = reviewer.id;
            option.textContent = `${reviewer.name} (${reviewer.email})`;
            reviewerSelect.appendChild(option);
        }
    });
}

function uploadReport(appId, grantId, studySectionId) {
    document.getElementById('upload-grant-id').textContent = grantId;
    document.getElementById('prefilled-app-id').value = appId;
    renderReviewerOptions(studySectionId);
    document.getElementById('uploadReportModal').style.display = 'block';
}

function closeUploadModal() {
    document.getElementById('uploadReportModal').style.display = 'none';
    document.getElementById('upload-report-form').reset();
}

function deleteApplication(id, grantId) {
    if (confirm(`Delete application "${grantId}"?\n\nThis will also delete all associated reviews and assignments.`)) {
        document.getElementById('delete-app-id').value = id;
        document.getElementById('delete-form').submit();
    }
}

// Auto-save reminder
let formChanged = false;
document.querySelectorAll('input, textarea, select').forEach(el => {
    el.addEventListener('change', () => {
        formChanged = true;
    });
});

window.addEventListener('beforeunload', (e) => {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = '';
    }
});

document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', () => {
        formChanged = false;
    });
});

attachStudySectionHandlers();
</script>

<?php require_once '../includes/footer.php'; ?>
