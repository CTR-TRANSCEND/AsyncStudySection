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
$view = isset($_GET['view']) ? (string) $_GET['view'] : 'list';
$view = in_array($view, ['list', 'new', 'edit'], true) ? $view : 'list';
$studySectionId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    }

    $action = $_POST['action'] ?? '';

    if (!$csrfError && $action === 'add_study_section') {
        $name = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $grantTypeIds = isset($_POST['grant_type_ids']) && is_array($_POST['grant_type_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $_POST['grant_type_ids']))))
            : [];
        $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;

        if ($name === '' || empty($grantTypeIds)) {
            $error = 'Study section name and at least one grant type are required.';
        } else {
            try {
                $db->beginTransaction();
                $primaryGrantTypeId = $grantTypeIds[0];
                $stmt = $db->prepare("
                    INSERT INTO study_sections (name, description, grant_type_id, is_active)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$name, $description ?: null, $primaryGrantTypeId, $isActive]);
                $newId = (int) $db->lastInsertId();

                $mapStmt = $db->prepare("
                    INSERT INTO study_section_grant_types (study_section_id, grant_type_id)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE grant_type_id = VALUES(grant_type_id)
                ");
                foreach ($grantTypeIds as $grantTypeId) {
                    $mapStmt->execute([$newId, $grantTypeId]);
                }

                $db->commit();
                if ($newId > 0) {
                    header('Location: study_sections.php?view=edit&id=' . $newId . '&created=1');
                    exit;
                }
                $message = 'Study section created.';
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($e->getCode() === '23000') {
                    $error = 'Study section name already exists.';
                } else {
                    $error = 'Failed to create study section.';
                }
            }
        }
    } elseif (!$csrfError && $action === 'update_study_section') {
        $studySectionId = isset($_POST['study_section_id']) ? (int) $_POST['study_section_id'] : 0;
        $name = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $grantTypeIds = isset($_POST['grant_type_ids']) && is_array($_POST['grant_type_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $_POST['grant_type_ids']))))
            : [];
        $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;

        if ($studySectionId <= 0 || $name === '' || empty($grantTypeIds)) {
            $error = 'Study section name and at least one grant type are required.';
        } else {
            try {
                $db->beginTransaction();
                $primaryGrantTypeId = $grantTypeIds[0];
                $stmt = $db->prepare("
                    UPDATE study_sections
                    SET name = ?, description = ?, grant_type_id = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $description ?: null, $primaryGrantTypeId, $isActive, $studySectionId]);

                $stmt = $db->prepare("DELETE FROM study_section_grant_types WHERE study_section_id = ?");
                $stmt->execute([$studySectionId]);

                $mapStmt = $db->prepare("
                    INSERT INTO study_section_grant_types (study_section_id, grant_type_id)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE grant_type_id = VALUES(grant_type_id)
                ");
                foreach ($grantTypeIds as $grantTypeId) {
                    $mapStmt->execute([$studySectionId, $grantTypeId]);
                }

                $db->commit();
                $message = 'Study section updated.';
                $view = 'edit';
                $studySectionId = $studySectionId;
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($e->getCode() === '23000') {
                    $error = 'Study section name already exists.';
                } else {
                    $error = 'Failed to update study section.';
                }
            }
        }
    } elseif (!$csrfError && $action === 'add_reviewer') {
        $studySectionId = isset($_POST['study_section_id']) ? (int) $_POST['study_section_id'] : 0;
        $reviewerId = isset($_POST['reviewer_id']) ? (int) $_POST['reviewer_id'] : 0;

        if ($studySectionId <= 0 || $reviewerId <= 0) {
            $error = 'Please select a reviewer.';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO study_section_reviewers (study_section_id, reviewer_id)
                    VALUES (?, ?)
                ");
                $stmt->execute([$studySectionId, $reviewerId]);
                $message = 'Reviewer assigned to study section.';
                $view = 'edit';
                $studySectionId = $studySectionId;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'Reviewer already assigned to this study section.';
                } else {
                    $error = 'Failed to assign reviewer.';
                }
            }
        }
    } elseif (!$csrfError && $action === 'remove_reviewer') {
        $assignmentId = isset($_POST['assignment_id']) ? (int) $_POST['assignment_id'] : 0;
        $studySectionId = isset($_POST['study_section_id']) ? (int) $_POST['study_section_id'] : 0;
        if ($assignmentId > 0) {
            $stmt = $db->prepare("DELETE FROM study_section_reviewers WHERE id = ?");
            $stmt->execute([$assignmentId]);
            $message = 'Reviewer removed from study section.';
            $view = 'edit';
        }
    }
}

$grantTypes = getGrantTypes(true);
$reviewers = $db->query("SELECT id, full_name, email FROM users WHERE role = 'reviewer' AND is_active = TRUE ORDER BY last_name, first_name")->fetchAll();
$studySections = getStudySections(true);
$studySection = null;
$reviewersBySection = [];
$reviewerCounts = [];
$selectedGrantTypeIds = [];
$applicationsBySection = [];

if ($message === '' && isset($_GET['created'])) {
    $message = 'Study section created.';
}

if ($view === 'list') {
    $stmt = $db->query("
        SELECT study_section_id, COUNT(*) as reviewer_count
        FROM study_section_reviewers
        GROUP BY study_section_id
    ");
    foreach ($stmt->fetchAll() as $row) {
        $reviewerCounts[(int) $row['study_section_id']] = (int) $row['reviewer_count'];
    }
}

if ($view === 'edit') {
    if ($studySectionId <= 0) {
        $error = $error ?: 'Select a study section to edit.';
        $view = 'list';
    } else {
        $studySection = getStudySectionById($studySectionId);
        if (!$studySection) {
            $error = $error ?: 'Study section not found.';
            $view = 'list';
        } else {
            $stmt = $db->prepare("
                SELECT ssr.id, u.full_name, u.email
                FROM study_section_reviewers ssr
                JOIN users u ON ssr.reviewer_id = u.id
                WHERE ssr.study_section_id = ?
                ORDER BY u.last_name, u.first_name
            ");
            $stmt->execute([$studySectionId]);
            $reviewersBySection[$studySectionId] = $stmt->fetchAll();
            $selectedGrantTypeIds = getStudySectionGrantTypeIds($studySectionId);
            if (empty($selectedGrantTypeIds) && !empty($studySection['grant_type_id'])) {
                $selectedGrantTypeIds = [(int) $studySection['grant_type_id']];
            }

            $stmt = $db->prepare("
                SELECT
                    a.id,
                    a.grant_id,
                    a.applicant_name,
                    a.application_title,
                    a.status,
                    a.grant_type_id,
                    COALESCE(gt.name, a.grant_type) as grant_type_name,
                    COUNT(r.id) as review_count
                FROM applications a
                LEFT JOIN grant_types gt ON gt.id = a.grant_type_id
                LEFT JOIN reviews r ON r.application_id = a.id
                WHERE a.study_section_id = ?
                GROUP BY a.id
                ORDER BY a.created_at DESC
            ");
            $stmt->execute([$studySectionId]);
            $applicationsBySection = $stmt->fetchAll();
        }
    }
}

$pageTitle = 'Study Sections';
require_once '../includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 class="mb-1">Study Sections / Program Calls</h1>
        <div class="text-muted">Organize applications and reviewer assignments.</div>
    </div>
    <div class="subnav">
        <a class="subnav-link <?php echo $view === 'list' ? 'is-active' : ''; ?>" href="study_sections.php">All Study Sections</a>
        <a class="subnav-link <?php echo $view === 'new' ? 'is-active' : ''; ?>" href="study_sections.php?view=new">Add Study Section</a>
        <?php if ($view === 'edit' && $studySection): ?>
            <span class="subnav-link is-active"><?php echo escape($studySection['name']); ?></span>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo escape($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo escape($error); ?></div>
<?php endif; ?>

<?php if ($view === 'list'): ?>
    <div class="card">
        <div class="card-header d-flex justify-between align-center">
            <span>Available Study Sections</span>
            <a href="study_sections.php?view=new" class="btn btn-sm btn-primary">Add Study Section</a>
        </div>
        <div class="card-body" style="overflow-x: auto;">
            <?php if (empty($studySections)): ?>
                <p class="text-muted">No study sections yet. <a href="study_sections.php?view=new">Create the first study section</a>.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Grant Types</th>
                            <th>Status</th>
                            <th>Reviewers</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studySections as $section): ?>
                            <?php
                            $description = trim($section['description'] ?? '');
                            if ($description === '') {
                                $description = '—';
                            } elseif (strlen($description) > 90) {
                                $description = substr($description, 0, 87) . '...';
                            }
                            ?>
                            <tr>
                                <td>
                                    <a class="table-link" href="study_sections.php?view=edit&id=<?php echo $section['id']; ?>">
                                        <?php echo escape($section['name']); ?>
                                    </a>
                                </td>
                                <td><?php echo escape($section['grant_type_names'] !== '' ? $section['grant_type_names'] : '—'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $section['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $section['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo $reviewerCounts[$section['id']] ?? 0; ?></td>
                                <td><?php echo escape($description); ?></td>
                                <td style="white-space: nowrap;">
                                    <a class="btn btn-sm btn-primary" href="study_sections.php?view=edit&id=<?php echo $section['id']; ?>">Manage</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($view === 'new'): ?>
    <div class="d-flex justify-between align-center mb-3">
        <h2>Add Study Section</h2>
        <a href="study_sections.php" class="btn btn-sm btn-outline">Back to list</a>
    </div>
    <div class="card mb-4">
        <div class="card-header">New Study Section</div>
        <div class="card-body">
            <form method="POST" action="study_sections.php?view=new">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_study_section">
                <div class="grid grid-2">
                    <div class="form-group">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Grant Types</label>
                        <div class="checkbox-list">
                            <?php foreach ($grantTypes as $grantType): ?>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="grant_type_ids[]" value="<?php echo $grantType['id']; ?>">
                                    <span><?php echo escape($grantType['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">Select one or more grant types.</small>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="is_active" class="form-control">
                        <option value="1" selected>Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Create Study Section</button>
            </form>
        </div>
    </div>
<?php elseif ($view === 'edit' && $studySection): ?>
    <div class="d-flex justify-between align-center mb-3">
        <div>
            <h2 class="mb-1"><?php echo escape($studySection['name']); ?></h2>
            <div class="text-muted">Edit details and reviewer assignments.</div>
        </div>
        <a href="study_sections.php" class="btn btn-sm btn-outline">Back to list</a>
    </div>

    <div class="card mb-4" id="study-section-<?php echo $studySection['id']; ?>">
        <div class="card-header d-flex justify-between align-center">
            <span>Study Section Details</span>
            <span class="badge badge-<?php echo $studySection['is_active'] ? 'success' : 'secondary'; ?>">
                <?php echo $studySection['is_active'] ? 'Active' : 'Inactive'; ?>
            </span>
        </div>
        <div class="card-body">
            <form method="POST" class="mb-3" id="study-section-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_study_section">
                <input type="hidden" name="study_section_id" value="<?php echo $studySection['id']; ?>">
                <div class="grid grid-2">
                    <div class="form-group">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" value="<?php echo escape($studySection['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Grant Types</label>
                        <div class="checkbox-list">
                            <?php foreach ($grantTypes as $grantType): ?>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="grant_type_ids[]" value="<?php echo $grantType['id']; ?>" <?php echo in_array((int) $grantType['id'], $selectedGrantTypeIds, true) ? 'checked' : ''; ?>>
                                    <span><?php echo escape($grantType['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">Select one or more grant types.</small>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"><?php echo escape($studySection['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="is_active" class="form-control">
                        <option value="1" <?php echo $studySection['is_active'] ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo !$studySection['is_active'] ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary" id="study-section-save" disabled>Save Study Section</button>
            </form>

            <div class="grid grid-2">
                <div>
                    <h4>Assigned Reviewers</h4>
                    <?php if (empty($reviewersBySection[$studySection['id']])): ?>
                        <p class="text-muted">No reviewers assigned.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reviewersBySection[$studySection['id']] as $assignment): ?>
                                    <tr>
                                        <td><?php echo escape($assignment['full_name']); ?></td>
                                        <td><?php echo escape($assignment['email']); ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="remove_reviewer">
                                                <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                                <input type="hidden" name="study_section_id" value="<?php echo $studySection['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Remove reviewer from this study section?')">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <div>
                    <h4>Assign Reviewer</h4>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="add_reviewer">
                        <input type="hidden" name="study_section_id" value="<?php echo $studySection['id']; ?>">
                        <div class="form-group">
                            <label class="form-label">Reviewer</label>
                            <select name="reviewer_id" class="form-control" required>
                                <option value="">-- Select Reviewer --</option>
                                <?php foreach ($reviewers as $reviewer): ?>
                                    <option value="<?php echo $reviewer['id']; ?>">
                                        <?php echo escape($reviewer['full_name']) . ' (' . escape($reviewer['email']) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Assign Reviewer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-between align-center">
            <span>Applications</span>
            <span class="text-muted" id="application_count_label"></span>
        </div>
        <div class="card-body" style="overflow-x: auto;">
            <?php if (empty($applicationsBySection)): ?>
                <p class="text-muted">No applications are assigned to this study section.</p>
            <?php else: ?>
                <div class="text-muted mb-2" style="font-size: 0.85rem;">
                    Filtered by selected grant types above.
                </div>
                <table class="table" id="study-section-applications">
                    <thead>
                        <tr>
                            <th>Grant ID</th>
                            <th>Applicant</th>
                            <th>Title</th>
                            <th>Grant Type</th>
                            <th>Status</th>
                            <th>Reviews</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applicationsBySection as $app): ?>
                            <tr data-grant-type-id="<?php echo (int) ($app['grant_type_id'] ?? 0); ?>">
                                <td><?php echo escape($app['grant_id'] ?? 'N/A'); ?></td>
                                <td><?php echo escape($app['applicant_name']); ?></td>
                                <td><?php echo escape(substr($app['application_title'], 0, 60)) . (strlen($app['application_title']) > 60 ? '...' : ''); ?></td>
                                <td><?php echo escape($app['grant_type_name'] ?? $app['grant_type']); ?></td>
                                <td><?php echo escape($app['status']); ?></td>
                                <td><?php echo (int) $app['review_count']; ?></td>
                                <td style="white-space: nowrap;">
                                    <a class="btn btn-sm btn-outline" href="application_detail.php?id=<?php echo $app['id']; ?>">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($view === 'edit' && $studySection): ?>
<script>
const studySectionForm = document.getElementById('study-section-form');
const studySectionSave = document.getElementById('study-section-save');

function serializeStudySectionForm(form) {
    const parts = [];
    form.querySelectorAll('input, textarea, select').forEach((field) => {
        if (!field.name) {
            return;
        }
        if (field.type === 'checkbox') {
            parts.push(`${field.name}:${field.value}:${field.checked ? 1 : 0}`);
        } else {
            parts.push(`${field.name}:${field.value}`);
        }
    });
    parts.sort();
    return parts.join('|');
}

if (studySectionForm && studySectionSave) {
    let initialState = serializeStudySectionForm(studySectionForm);

    const updateSaveState = () => {
        const currentState = serializeStudySectionForm(studySectionForm);
        const hasChanges = currentState !== initialState;
        studySectionSave.disabled = !hasChanges;
    };

    studySectionForm.addEventListener('input', updateSaveState);
    studySectionForm.addEventListener('change', updateSaveState);
}

const applicationTable = document.getElementById('study-section-applications');
const applicationCountLabel = document.getElementById('application_count_label');

function getSelectedGrantTypes() {
    if (!studySectionForm) {
        return [];
    }
    const selected = [];
    studySectionForm.querySelectorAll('input[name="grant_type_ids[]"]').forEach((checkbox) => {
        if (checkbox.checked) {
            selected.push(String(checkbox.value));
        }
    });
    return selected;
}

function updateApplicationFilter() {
    if (!applicationTable) {
        return;
    }
    const selected = getSelectedGrantTypes();
    const rows = Array.from(applicationTable.querySelectorAll('tbody tr'));
    let visibleCount = 0;
    rows.forEach((row) => {
        const grantTypeId = row.dataset.grantTypeId || '0';
        const isVisible = selected.length === 0 || selected.includes(grantTypeId);
        row.style.display = isVisible ? '' : 'none';
        if (isVisible) {
            visibleCount += 1;
        }
    });
    if (applicationCountLabel) {
        applicationCountLabel.textContent = `${visibleCount} of ${rows.length} shown`;
    }
}

if (studySectionForm) {
    studySectionForm.addEventListener('change', updateApplicationFilter);
    updateApplicationFilter();
}
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
