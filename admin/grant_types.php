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
$grantTypeId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_sections') {
    $sourceGrantTypeId = isset($_GET['grant_type_id']) ? (int) $_GET['grant_type_id'] : 0;
    header('Content-Type: application/json; charset=utf-8');
    if ($sourceGrantTypeId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Grant type is required.'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        exit;
    }

    $sections = getGrantSections($sourceGrantTypeId, true);
    $payload = array_map(static function ($section) {
        return [
            'id' => (int) $section['id'],
            'name' => (string) $section['name'],
            'is_scored' => (int) $section['is_scored'],
            'is_required' => (int) $section['is_required'],
            'score_min' => $section['score_min'] !== null ? (int) $section['score_min'] : null,
            'score_max' => $section['score_max'] !== null ? (int) $section['score_max'] : null
        ];
    }, $sections);

    echo json_encode(['success' => true, 'sections' => $payload], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    }

    $action = $_POST['action'] ?? '';

    if (!$csrfError && $action === 'add_grant_type') {
        $name = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $url = sanitize($_POST['url'] ?? '');
        $copyFromId = isset($_POST['copy_from_id']) ? (int) $_POST['copy_from_id'] : 0;
        $sourceGrantType = null;

        if ($name === '') {
            $error = 'Grant type name is required.';
        } else {
            try {
                if ($copyFromId > 0) {
                    $stmt = $db->prepare("SELECT * FROM grant_types WHERE id = ?");
                    $stmt->execute([$copyFromId]);
                    $sourceGrantType = $stmt->fetch();
                    if (!$sourceGrantType) {
                        throw new RuntimeException('Source grant type not found.');
                    }

                    if (strcasecmp($name, (string) ($sourceGrantType['name'] ?? '')) === 0) {
                        throw new RuntimeException('Update the name when duplicating a grant type.');
                    }

                    if ($description === '') {
                        $description = (string) ($sourceGrantType['description'] ?? '');
                    }
                    if ($url === '') {
                        $url = (string) ($sourceGrantType['url'] ?? '');
                    }
                }

                $db->beginTransaction();
                $stmt = $db->prepare("
                    INSERT INTO grant_types (name, description, url)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$name, $description ?: null, $url ?: null]);
                $newId = (int) $db->lastInsertId();

                if ($copyFromId > 0 && $newId > 0) {
                    $stmt = $db->prepare("
                        SELECT name, description, is_scored, is_required, score_min, score_max, display_order, is_active
                        FROM grant_sections
                        WHERE grant_type_id = ?
                        ORDER BY display_order, id
                    ");
                    $stmt->execute([$copyFromId]);
                    $sections = $stmt->fetchAll();

                    if ($sections) {
                        $insertStmt = $db->prepare("
                            INSERT INTO grant_sections (
                                grant_type_id, name, description, is_scored, is_required,
                                score_min, score_max, display_order, is_active
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        foreach ($sections as $section) {
                            $insertStmt->execute([
                                $newId,
                                $section['name'],
                                $section['description'],
                                $section['is_scored'],
                                $section['is_required'],
                                $section['score_min'],
                                $section['score_max'],
                                $section['display_order'],
                                $section['is_active']
                            ]);
                        }
                    }
                }

                $db->commit();
                if ($newId > 0) {
                    header('Location: grant_types.php?view=edit&id=' . $newId . '&created=1');
                    exit;
                }
                $message = 'Grant type created.';
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($e->getCode() === '23000') {
                    $error = 'Grant type name already exists.';
                } else {
                    $error = 'Failed to create grant type.';
                }
            } catch (RuntimeException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = $e->getMessage();
            }
        }
    } elseif (!$csrfError && $action === 'update_grant_type') {
        $grantTypeId = isset($_POST['grant_type_id']) ? (int) $_POST['grant_type_id'] : 0;
        $name = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $url = sanitize($_POST['url'] ?? '');
        $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;

        if ($grantTypeId <= 0 || $name === '') {
            $error = 'Grant type name is required.';
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE grant_types
                    SET name = ?, description = ?, url = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $description ?: null, $url ?: null, $isActive, $grantTypeId]);
                $message = 'Grant type updated.';
                $view = 'edit';
                $grantTypeId = $grantTypeId;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'Grant type name already exists.';
                } else {
                    $error = 'Failed to update grant type.';
                }
            }
        }
    } elseif (!$csrfError && $action === 'add_section') {
        $grantTypeId = isset($_POST['grant_type_id']) ? (int) $_POST['grant_type_id'] : 0;
        $name = sanitize($_POST['section_name'] ?? '');
        $isScored = isset($_POST['is_scored']) ? (int) $_POST['is_scored'] : 0;
        $isRequired = isset($_POST['is_required']) ? (int) $_POST['is_required'] : 0;
        $scoreMin = $_POST['score_min'] !== '' ? (int) $_POST['score_min'] : null;
        $scoreMax = $_POST['score_max'] !== '' ? (int) $_POST['score_max'] : null;
        $displayOrder = isset($_POST['display_order']) && $_POST['display_order'] !== '' ? (int) $_POST['display_order'] : null;

        if ($grantTypeId <= 0 || $name === '') {
            $error = 'Section name is required.';
        } elseif ($isScored && ($scoreMin === null || $scoreMax === null || $scoreMin > $scoreMax)) {
            $error = 'Score range is invalid.';
        } else {
            try {
                if ($displayOrder === null || $displayOrder <= 0) {
                    $stmt = $db->prepare("SELECT COALESCE(MAX(display_order), 0) FROM grant_sections WHERE grant_type_id = ?");
                    $stmt->execute([$grantTypeId]);
                    $displayOrder = ((int) $stmt->fetchColumn()) + 1;
                }
                $stmt = $db->prepare("
                    INSERT INTO grant_sections (
                        grant_type_id, name, is_scored, is_required,
                        score_min, score_max, display_order
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $grantTypeId,
                    $name,
                    $isScored,
                    $isRequired,
                    $isScored ? $scoreMin : null,
                    $isScored ? $scoreMax : null,
                    $displayOrder
                ]);
                $message = 'Section added.';
                $view = 'edit';
                $grantTypeId = $grantTypeId;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'Section name already exists for this grant type.';
                } else {
                    $error = 'Failed to add section.';
                }
            }
        }
    } elseif (!$csrfError && $action === 'update_section') {
        $sectionId = isset($_POST['section_id']) ? (int) $_POST['section_id'] : 0;
        $name = sanitize($_POST['section_name'] ?? '');
        $isScored = isset($_POST['is_scored']) ? (int) $_POST['is_scored'] : 0;
        $isRequired = isset($_POST['is_required']) ? (int) $_POST['is_required'] : 0;
        $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;
        $scoreMin = $_POST['score_min'] !== '' ? (int) $_POST['score_min'] : null;
        $scoreMax = $_POST['score_max'] !== '' ? (int) $_POST['score_max'] : null;
        $displayOrder = isset($_POST['display_order']) ? (int) $_POST['display_order'] : 0;
        $grantTypeId = isset($_POST['grant_type_id']) ? (int) $_POST['grant_type_id'] : 0;

        if ($sectionId <= 0 || $name === '') {
            $error = 'Section name is required.';
        } elseif ($isScored && ($scoreMin === null || $scoreMax === null || $scoreMin > $scoreMax)) {
            $error = 'Score range is invalid.';
        } else {
            $stmt = $db->prepare("
                UPDATE grant_sections
                SET name = ?, is_scored = ?, is_required = ?, score_min = ?, score_max = ?,
                    display_order = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $name,
                $isScored,
                $isRequired,
                $isScored ? $scoreMin : null,
                $isScored ? $scoreMax : null,
                $displayOrder,
                $isActive,
                $sectionId
            ]);
            $message = 'Section updated.';
            $view = 'edit';
        }
    } elseif (!$csrfError && $action === 'reorder_sections') {
        $grantTypeId = isset($_POST['grant_type_id']) ? (int) $_POST['grant_type_id'] : 0;
        $orderString = trim((string) ($_POST['section_order'] ?? ''));
        $orderItems = $orderString === '' ? [] : preg_split('/\s*,\s*/', $orderString);
        $orderedIds = [];
        foreach ($orderItems as $item) {
            $id = (int) $item;
            if ($id > 0 && !in_array($id, $orderedIds, true)) {
                $orderedIds[] = $id;
            }
        }

        if ($grantTypeId <= 0 || empty($orderedIds)) {
            $error = 'Section order payload is missing.';
        } else {
            $stmt = $db->prepare("SELECT id FROM grant_sections WHERE grant_type_id = ? ORDER BY display_order, id");
            $stmt->execute([$grantTypeId]);
            $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $missing = array_diff($validIds, $orderedIds);
            if (!empty($missing) || count($orderedIds) !== count($validIds)) {
                $error = 'Section order did not include all sections.';
            } else {
                try {
                    $db->beginTransaction();
                    $updateStmt = $db->prepare("
                        UPDATE grant_sections
                        SET display_order = ?
                        WHERE id = ? AND grant_type_id = ?
                    ");
                    $order = 1;
                    foreach ($orderedIds as $sectionId) {
                        $updateStmt->execute([$order, $sectionId, $grantTypeId]);
                        $order++;
                    }
                    $db->commit();
                    $message = 'Section order updated.';
                    $view = 'edit';
                } catch (PDOException $e) {
                    $db->rollBack();
                    $error = 'Failed to update section order.';
                }
            }
        }
    } elseif (!$csrfError && $action === 'import_sections') {
        $grantTypeId = isset($_POST['grant_type_id']) ? (int) $_POST['grant_type_id'] : 0;
        $sourceGrantTypeId = isset($_POST['source_grant_type_id']) ? (int) $_POST['source_grant_type_id'] : 0;
        $selectedSectionIds = isset($_POST['section_ids']) && is_array($_POST['section_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $_POST['section_ids']))))
            : [];

        if ($grantTypeId <= 0 || $sourceGrantTypeId <= 0) {
            $error = 'Select a grant type to import sections.';
            $view = 'edit';
        } elseif ($grantTypeId === $sourceGrantTypeId) {
            $error = 'Select a different grant type to import sections.';
            $view = 'edit';
        } elseif (empty($selectedSectionIds)) {
            $error = 'Select at least one section to import.';
            $view = 'edit';
        } else {
            $placeholders = implode(',', array_fill(0, count($selectedSectionIds), '?'));
            $params = array_merge([$sourceGrantTypeId], $selectedSectionIds);
            $stmt = $db->prepare("
                SELECT id, name, description, is_scored, is_required, score_min, score_max, display_order, is_active
                FROM grant_sections
                WHERE grant_type_id = ? AND id IN ($placeholders)
                ORDER BY display_order, id
            ");
            $stmt->execute($params);
            $sections = $stmt->fetchAll();

            if (empty($sections) || count($sections) !== count($selectedSectionIds)) {
                $error = 'Selected sections could not be found.';
                $view = 'edit';
            } else {
                $stmt = $db->prepare("SELECT name FROM grant_sections WHERE grant_type_id = ?");
                $stmt->execute([$grantTypeId]);
                $existingNames = array_map('strtolower', array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN)));
                $existingNameMap = array_fill_keys($existingNames, true);

                $importSections = [];
                $skipped = 0;
                foreach ($sections as $section) {
                    $nameKey = strtolower(trim((string) $section['name']));
                    if ($nameKey === '' || isset($existingNameMap[$nameKey])) {
                        $skipped++;
                        continue;
                    }
                    $existingNameMap[$nameKey] = true;
                    $importSections[] = $section;
                }

                if (empty($importSections)) {
                    $error = 'All selected sections already exist.';
                    $view = 'edit';
                } else {
                    try {
                        $db->beginTransaction();
                        $stmt = $db->prepare("SELECT COALESCE(MAX(display_order), 0) FROM grant_sections WHERE grant_type_id = ?");
                        $stmt->execute([$grantTypeId]);
                        $order = ((int) $stmt->fetchColumn()) + 1;

                        $insertStmt = $db->prepare("
                            INSERT INTO grant_sections (
                                grant_type_id, name, description, is_scored, is_required,
                                score_min, score_max, display_order, is_active
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        foreach ($importSections as $section) {
                            $insertStmt->execute([
                                $grantTypeId,
                                $section['name'],
                                $section['description'],
                                $section['is_scored'],
                                $section['is_required'],
                                $section['score_min'],
                                $section['score_max'],
                                $order,
                                $section['is_active']
                            ]);
                            $order++;
                        }
                        $db->commit();
                        $message = 'Imported ' . count($importSections) . ' section(s).';
                        if ($skipped > 0) {
                            $message .= ' Skipped ' . $skipped . ' duplicate(s).';
                        }
                        $view = 'edit';
                    } catch (PDOException $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = 'Failed to import sections.';
                        $view = 'edit';
                    }
                }
            }
        }
    } elseif (!$csrfError && $action === 'delete_sections') {
        $grantTypeId = isset($_POST['grant_type_id']) ? (int) $_POST['grant_type_id'] : 0;
        $selectedSectionIds = isset($_POST['section_ids']) && is_array($_POST['section_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $_POST['section_ids']))))
            : [];

        if ($grantTypeId <= 0 || empty($selectedSectionIds)) {
            $error = 'Select at least one section to delete.';
            $view = 'edit';
        } else {
            $placeholders = implode(',', array_fill(0, count($selectedSectionIds), '?'));
            $params = array_merge([$grantTypeId], $selectedSectionIds);
            $stmt = $db->prepare("
                SELECT id
                FROM grant_sections
                WHERE grant_type_id = ? AND id IN ($placeholders)
            ");
            $stmt->execute($params);
            $foundIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            if (count($foundIds) !== count($selectedSectionIds)) {
                $error = 'Some selected sections could not be found.';
                $view = 'edit';
            } else {
                $stmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM review_section_scores
                    WHERE grant_section_id IN ($placeholders)
                ");
                $stmt->execute($selectedSectionIds);
                $linkedCount = (int) $stmt->fetchColumn();

                if ($linkedCount > 0) {
                    $error = 'Selected sections have review scores and cannot be deleted.';
                    $view = 'edit';
                } else {
                    try {
                        $db->beginTransaction();
                        $stmt = $db->prepare("
                            DELETE FROM grant_sections
                            WHERE grant_type_id = ? AND id IN ($placeholders)
                        ");
                        $stmt->execute($params);
                        $db->commit();
                        $message = 'Deleted ' . count($selectedSectionIds) . ' section(s).';
                        $view = 'edit';
                    } catch (PDOException $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = 'Failed to delete sections.';
                        $view = 'edit';
                    }
                }
            }
        }
    } elseif (!$csrfError && $action === 'delete_grant_type') {
        $grantTypeId = isset($_POST['grant_type_id']) ? (int) $_POST['grant_type_id'] : 0;
        $confirmPhrase = trim((string) ($_POST['confirm_phrase'] ?? ''));

        if ($grantTypeId <= 0) {
            $error = 'Select a grant type to delete.';
        } elseif ($confirmPhrase !== 'DELETE THIS GRANT TYPE') {
            $error = 'Confirmation phrase did not match.';
            $view = 'edit';
        } else {
            $stmt = $db->prepare("SELECT id, name FROM grant_types WHERE id = ?");
            $stmt->execute([$grantTypeId]);
            $grantTypeRow = $stmt->fetch();

            if (!$grantTypeRow) {
                $error = 'Grant type not found.';
            } else {
                $stmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM applications
                    WHERE grant_type_id = ? OR grant_type = ?
                ");
                $stmt->execute([$grantTypeId, $grantTypeRow['name']]);
                $applicationCount = (int) $stmt->fetchColumn();

                $stmt = $db->prepare("SELECT COUNT(*) FROM study_sections WHERE grant_type_id = ?");
                $stmt->execute([$grantTypeId]);
                $primaryStudySectionCount = (int) $stmt->fetchColumn();

                if ($applicationCount > 0 || $primaryStudySectionCount > 0) {
                    $error = 'Grant type is still in use and cannot be deleted.';
                    $view = 'edit';
                    $grantTypeId = $grantTypeId;
                } else {
                    try {
                        $db->beginTransaction();
                        $stmt = $db->prepare("DELETE FROM grant_types WHERE id = ?");
                        $stmt->execute([$grantTypeId]);
                        $db->commit();
                        $message = 'Grant type deleted.';
                        $view = 'list';
                        $grantTypeId = 0;
                    } catch (PDOException $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = 'Failed to delete grant type.';
                        $view = 'edit';
                        $grantTypeId = $grantTypeId;
                    }
                }
            }
        }
    }
}

$grantTypes = getGrantTypes(true);
$grantType = null;
$grantSections = [];
$sectionCounts = [];
$deleteSummary = [
    'application_count' => 0,
    'primary_study_section_count' => 0,
    'linked_study_section_count' => 0,
    'can_delete' => false
];

if ($message === '' && isset($_GET['created'])) {
    $message = 'Grant type created.';
}

if ($view === 'list') {
    $stmt = $db->query("
        SELECT grant_type_id, COUNT(*) as section_count
        FROM grant_sections
        GROUP BY grant_type_id
    ");
    foreach ($stmt->fetchAll() as $row) {
        $sectionCounts[(int) $row['grant_type_id']] = (int) $row['section_count'];
    }
}

if ($view === 'edit') {
    if ($grantTypeId <= 0) {
        $error = $error ?: 'Select a grant type to edit.';
        $view = 'list';
    } else {
        $grantType = getGrantTypeById($grantTypeId);
        if (!$grantType) {
            $error = $error ?: 'Grant type not found.';
            $view = 'list';
        } else {
            $grantSections = getGrantSections($grantTypeId, true);
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM applications
                WHERE grant_type_id = ? OR grant_type = ?
            ");
            $stmt->execute([$grantTypeId, $grantType['name']]);
            $deleteSummary['application_count'] = (int) $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM study_sections WHERE grant_type_id = ?");
            $stmt->execute([$grantTypeId]);
            $deleteSummary['primary_study_section_count'] = (int) $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM study_section_grant_types WHERE grant_type_id = ?");
            $stmt->execute([$grantTypeId]);
            $deleteSummary['linked_study_section_count'] = (int) $stmt->fetchColumn();

            $deleteSummary['can_delete'] = $deleteSummary['application_count'] === 0
                && $deleteSummary['primary_study_section_count'] === 0;
        }
    }
}

$pageTitle = 'Grant Types';
require_once '../includes/header.php';
?>

<div class="d-flex justify-between align-center mb-4">
    <div>
        <h1 class="mb-1">Grant Types</h1>
        <div class="text-muted">Manage grant templates and review sections.</div>
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
            <span>Available Grant Types</span>
            <a href="grant_types.php?view=new" class="btn btn-sm btn-primary">Add Grant Type</a>
        </div>
        <div class="card-body" style="overflow-x: auto;">
            <?php if (empty($grantTypes)): ?>
                <p class="text-muted">No grant types yet. <a href="grant_types.php?view=new">Create the first grant type</a>.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Sections</th>
                            <th>Description</th>
                            <th>Template</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grantTypes as $type): ?>
                            <?php
                            $description = trim($type['description'] ?? '');
                            if ($description === '') {
                                $description = '—';
                            } elseif (strlen($description) > 90) {
                                $description = substr($description, 0, 87) . '...';
                            }
                            $templateUrl = safeUrl($type['url'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <a class="table-link" href="grant_types.php?view=edit&id=<?php echo $type['id']; ?>">
                                        <?php echo escape($type['name']); ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $type['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $type['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo $sectionCounts[$type['id']] ?? 0; ?></td>
                                <td><?php echo escape($description); ?></td>
                                <td>
                                    <?php if ($templateUrl !== ''): ?>
                                        <a class="table-link" href="<?php echo escape($templateUrl); ?>" target="_blank" rel="noopener">Open</a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <a class="btn btn-sm btn-primary" href="grant_types.php?view=edit&id=<?php echo $type['id']; ?>">Manage</a>
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
        <h2>Add Grant Type</h2>
        <a href="grant_types.php" class="btn btn-sm btn-outline">Back to list</a>
    </div>
    <div class="card mb-4">
        <div class="card-header">New Grant Type</div>
        <div class="card-body">
            <form method="POST" action="grant_types.php?view=new">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_grant_type">
                <div class="form-group">
                    <label class="form-label">Duplicate From (optional)</label>
                    <select name="copy_from_id" id="copy_from_id" class="form-control">
                        <option value="0">Start from scratch</option>
                        <?php foreach ($grantTypes as $type): ?>
                            <option value="<?php echo (int) $type['id']; ?>">
                                <?php echo escape($type['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-muted">Selecting a grant type will populate the fields below. Update the name before creating.</div>
                </div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" id="grant_type_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">URL</label>
                        <input type="text" name="url" id="grant_type_url" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="grant_type_description" class="form-control" rows="2"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" id="create_grant_type_btn">Create Grant Type</button>
            </form>
        </div>
    </div>
    <script>
    const grantTypeData = <?php echo json_encode(array_reduce($grantTypes, function ($carry, $type) {
        $carry[(int) $type['id']] = [
            'name' => (string) ($type['name'] ?? ''),
            'description' => (string) ($type['description'] ?? ''),
            'url' => (string) ($type['url'] ?? '')
        ];
        return $carry;
    }, []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    const copySelect = document.getElementById('copy_from_id');
    const nameInput = document.getElementById('grant_type_name');
    const urlInput = document.getElementById('grant_type_url');
    const descriptionInput = document.getElementById('grant_type_description');
    const createButton = document.getElementById('create_grant_type_btn');
    let baseline = { name: '', description: '', url: '' };

    function normalize(value) {
        return (value || '').trim();
    }

    function setBaseline(values) {
        baseline = {
            name: values.name || '',
            description: values.description || '',
            url: values.url || ''
        };
    }

    function updateButtonState() {
        if (!createButton || !nameInput) {
            return;
        }
        const nameValue = normalize(nameInput.value);
        const isCopy = copySelect && copySelect.value !== '0';
        if (isCopy) {
            const nameChanged = nameValue !== normalize(baseline.name);
            createButton.disabled = nameValue === '' || !nameChanged;
            return;
        }
        createButton.disabled = nameValue === '';
    }

    function populateFromSelection() {
        if (!copySelect || !nameInput || !urlInput || !descriptionInput) {
            return;
        }
        const selectedId = parseInt(copySelect.value, 10);
        if (selectedId && grantTypeData[selectedId]) {
            const data = grantTypeData[selectedId];
            nameInput.value = data.name || '';
            urlInput.value = data.url || '';
            descriptionInput.value = data.description || '';
            setBaseline(data);
        } else {
            nameInput.value = '';
            urlInput.value = '';
            descriptionInput.value = '';
            setBaseline({ name: '', description: '', url: '' });
        }
        updateButtonState();
    }

    if (copySelect) {
        copySelect.addEventListener('change', populateFromSelection);
    }
    [nameInput, urlInput, descriptionInput].forEach((input) => {
        if (input) {
            input.addEventListener('input', updateButtonState);
        }
    });
    updateButtonState();
    </script>
<?php elseif ($view === 'edit' && $grantType): ?>
    <?php $nextSectionOrder = count($grantSections) + 1; ?>
    <div class="d-flex justify-between align-center mb-3">
        <div>
            <h2 class="mb-1"><?php echo escape($grantType['name']); ?></h2>
            <div class="text-muted">Update details and review sections.</div>
        </div>
        <a href="grant_types.php" class="btn btn-sm btn-outline">Back to list</a>
    </div>

    <div class="card mb-4">
        <div class="card-header">Grant Type Details</div>
        <div class="card-body">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_grant_type">
                <input type="hidden" name="grant_type_id" value="<?php echo $grantType['id']; ?>">
                <div class="grid grid-2">
                    <div class="form-group">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" value="<?php echo escape($grantType['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">URL</label>
                        <input type="text" name="url" class="form-control" value="<?php echo escape($grantType['url'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"><?php echo escape($grantType['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="is_active" class="form-control">
                        <option value="1" <?php echo $grantType['is_active'] ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo !$grantType['is_active'] ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary">Save Grant Type</button>
            </form>
        </div>
    </div>

    <div class="card" id="sections">
    <div class="card-header d-flex justify-between align-center">
        <span>Sections</span>
        <div class="d-flex align-center gap-2">
            <span class="text-muted"><?php echo count($grantSections); ?> total</span>
            <button type="button" class="btn btn-sm btn-secondary" id="openImportSections">Import Sections</button>
        </div>
    </div>
        <div class="card-body">
            <form method="POST" id="sectionOrderForm" class="mb-3">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="reorder_sections">
                <input type="hidden" name="grant_type_id" value="<?php echo $grantType['id']; ?>">
                <input type="hidden" name="section_order" id="section_order" value="">
                <div class="d-flex justify-between align-center">
                    <div>
                        <div class="text-muted">Drag sections to reorder. Changes save automatically.</div>
                        <div class="order-status" id="section_order_status" aria-live="polite">Auto-save enabled.</div>
                    </div>
                </div>
            </form>
            <?php if (empty($grantSections)): ?>
                <p class="text-muted">No sections configured for this grant type.</p>
            <?php else: ?>
                <form method="POST" id="bulkDeleteSectionsForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete_sections">
                    <input type="hidden" name="grant_type_id" value="<?php echo $grantType['id']; ?>">
                    <div class="d-flex justify-between align-center mb-2">
                        <span class="text-muted">Select sections to delete.</span>
                        <button type="submit" class="btn btn-sm btn-danger btn-icon" id="deleteSelectedSections" title="Delete selected sections" aria-label="Delete selected sections" disabled>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" width="16" height="16">
                                <path fill="currentColor" d="M9 3h6l1 2h5v2H3V5h5l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM6 9h2v9H6V9zm1 12h10a2 2 0 0 0 2-2V7H5v12a2 2 0 0 0 2 2z"/>
                            </svg>
                        </button>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="table" id="sectionTable">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Order</th>
                                    <th>Name</th>
                                    <th>Scored</th>
                                    <th>Required</th>
                                    <th>Score Range</th>
                                    <th>Status</th>
                                    <th>
                                        <div class="d-flex justify-between align-center">
                                            <span>Action</span>
                                            <input type="checkbox" id="selectAllSections" aria-label="Select all sections">
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody data-section-table>
                                <?php foreach ($grantSections as $section): ?>
                                    <tr draggable="true" data-section-id="<?php echo (int) $section['id']; ?>" data-display-order="<?php echo (int) $section['display_order']; ?>">
                                        <td class="drag-handle" title="Drag to reorder">⋮⋮</td>
                                        <td class="section-order"><?php echo (int) $section['display_order']; ?></td>
                                        <td><?php echo escape($section['name']); ?></td>
                                        <td><?php echo $section['is_scored'] ? 'Yes' : 'No'; ?></td>
                                        <td><?php echo $section['is_required'] ? 'Yes' : 'No'; ?></td>
                                        <td>
                                            <?php if ($section['is_scored']): ?>
                                                <?php echo (int) $section['score_min']; ?> - <?php echo (int) $section['score_max']; ?>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $section['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $section['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <div class="d-flex align-center gap-2">
                                                <button type="button" class="btn btn-sm btn-primary" onclick="showSectionEdit(<?php echo htmlspecialchars(json_encode($section), ENT_QUOTES); ?>)">Edit</button>
                                                <input type="checkbox" name="section_ids[]" value="<?php echo (int) $section['id']; ?>" class="section-select" aria-label="Select section for delete">
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            <?php endif; ?>

            <form method="POST" class="mt-3">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_section">
                <input type="hidden" name="grant_type_id" value="<?php echo $grantType['id']; ?>">
                <div class="grid grid-3">
                    <div class="form-group">
                        <label class="form-label">Section Name</label>
                        <input type="text" name="section_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Scored?</label>
                        <select name="is_scored" class="form-control">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Required?</label>
                        <select name="is_required" class="form-control">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-3">
                    <div class="form-group">
                        <label class="form-label">Score Min</label>
                        <input type="number" name="score_min" class="form-control" min="0" max="99">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Score Max</label>
                        <input type="number" name="score_max" class="form-control" min="0" max="99">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" class="form-control" min="1" value="<?php echo $nextSectionOrder; ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-success">Add Section</button>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Delete Grant Type</div>
        <div class="card-body">
            <p class="text-muted">Deletion is irreversible. Use this only for unused grant types.</p>
            <div class="mb-2">
                <strong>Applications linked:</strong> <?php echo (int) $deleteSummary['application_count']; ?>
            </div>
            <div class="mb-2">
                <strong>Primary study sections linked:</strong> <?php echo (int) $deleteSummary['primary_study_section_count']; ?>
            </div>
            <?php if ($deleteSummary['linked_study_section_count'] > 0): ?>
                <div class="mb-3 text-muted">
                    This grant type is referenced by <?php echo (int) $deleteSummary['linked_study_section_count']; ?> study section(s).
                    Deleting will remove it from those study sections.
                </div>
            <?php endif; ?>

            <?php if (!$deleteSummary['can_delete']): ?>
                <div class="alert alert-warning">
                    This grant type is still in use. Deactivate it instead.
                </div>
            <?php endif; ?>

            <form method="POST" id="deleteGrantTypeForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete_grant_type">
                <input type="hidden" name="grant_type_id" value="<?php echo $grantType['id']; ?>">
                <input type="hidden" name="confirm_phrase" id="confirm_phrase" value="">
                <button type="button" class="btn btn-danger" id="deleteGrantTypeBtn" <?php echo $deleteSummary['can_delete'] ? '' : 'disabled'; ?>>
                    Delete Grant Type
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div id="sectionEditModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 0.5rem; max-width: 500px; width: 90%;">
        <h3 class="mb-3">Edit Section</h3>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_section">
            <input type="hidden" name="section_id" id="section_id">
            <input type="hidden" name="grant_type_id" id="section_grant_type_id">

            <div class="form-group">
                <label class="form-label">Name</label>
                <input type="text" name="section_name" id="section_name" class="form-control" required>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">Scored?</label>
                    <select name="is_scored" id="section_is_scored" class="form-control">
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Required?</label>
                    <select name="is_required" id="section_is_required" class="form-control">
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label">Score Min</label>
                    <input type="number" name="score_min" id="section_score_min" class="form-control" min="0" max="99">
                </div>
                <div class="form-group">
                    <label class="form-label">Score Max</label>
                    <input type="number" name="score_max" id="section_score_max" class="form-control" min="0" max="99">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Display Order</label>
                <input type="number" name="display_order" id="section_display_order" class="form-control" min="0">
            </div>

            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="is_active" id="section_is_active" class="form-control">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Section</button>
                <button type="button" class="btn btn-secondary" onclick="closeSectionEdit()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="importSectionsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); z-index: 2100;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 0.6rem; max-width: 720px; width: 92%;">
        <div class="d-flex justify-between align-center mb-2">
            <h3 class="mb-0">Import Sections</h3>
            <button type="button" class="btn btn-sm btn-outline" id="closeImportSections">Close</button>
        </div>
        <p class="text-muted">Select a grant type and choose the sections to add. Existing section names are skipped.</p>
        <form method="POST" id="importSectionsForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="import_sections">
            <input type="hidden" name="grant_type_id" value="<?php echo $grantType ? $grantType['id'] : 0; ?>">
            <div class="form-group">
                <label class="form-label">Source Grant Type</label>
                <select name="source_grant_type_id" id="import_source_grant_type_id" class="form-control" required>
                    <option value="">Select a grant type</option>
                    <?php foreach ($grantTypes as $type): ?>
                        <?php if ($grantType && (int) $type['id'] === (int) $grantType['id']) { continue; } ?>
                        <option value="<?php echo (int) $type['id']; ?>">
                            <?php echo escape($type['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Available Sections</label>
                <div class="d-flex gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-outline" id="importSelectAll">Select all</button>
                    <button type="button" class="btn btn-sm btn-outline" id="importDeselectAll">Deselect all</button>
                    <button type="button" class="btn btn-sm btn-outline" id="importClear">Clear</button>
                </div>
                <div id="importSectionsList" class="checkbox-list">
                    <span class="text-muted">Choose a grant type to load sections.</span>
                </div>
                <div class="text-muted mt-2">Import adds the selected sections to the current list.</div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="confirmImportSections" disabled>Import Selected Sections</button>
                <button type="button" class="btn btn-secondary" id="cancelImportSections">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function showSectionEdit(section) {
    document.getElementById('section_id').value = section.id;
    document.getElementById('section_grant_type_id').value = section.grant_type_id ?? '';
    document.getElementById('section_name').value = section.name;
    document.getElementById('section_is_scored').value = section.is_scored ? '1' : '0';
    document.getElementById('section_is_required').value = section.is_required ? '1' : '0';
    document.getElementById('section_score_min').value = section.score_min ?? '';
    document.getElementById('section_score_max').value = section.score_max ?? '';
    document.getElementById('section_display_order').value = section.display_order ?? 0;
    document.getElementById('section_is_active').value = section.is_active ? '1' : '0';
    document.getElementById('sectionEditModal').style.display = 'block';
}

function closeSectionEdit() {
    document.getElementById('sectionEditModal').style.display = 'none';
}

const sectionTable = document.querySelector('[data-section-table]');
const sectionOrderInput = document.getElementById('section_order');
const sectionOrderForm = document.getElementById('sectionOrderForm');
const sectionOrderStatus = document.getElementById('section_order_status');
const deleteGrantTypeButton = document.getElementById('deleteGrantTypeBtn');
const deleteGrantTypeForm = document.getElementById('deleteGrantTypeForm');
const deleteConfirmPhraseInput = document.getElementById('confirm_phrase');
const bulkDeleteForm = document.getElementById('bulkDeleteSectionsForm');
const deleteSelectedButton = document.getElementById('deleteSelectedSections');
const selectAllSectionsCheckbox = document.getElementById('selectAllSections');
const importModal = document.getElementById('importSectionsModal');
const openImportButton = document.getElementById('openImportSections');
const closeImportButton = document.getElementById('closeImportSections');
const cancelImportButton = document.getElementById('cancelImportSections');
const importSectionsForm = document.getElementById('importSectionsForm');
const importSourceSelect = document.getElementById('import_source_grant_type_id');
const importSectionsList = document.getElementById('importSectionsList');
const importSelectAllButton = document.getElementById('importSelectAll');
const importDeselectAllButton = document.getElementById('importDeselectAll');
const importClearButton = document.getElementById('importClear');
const importSubmitButton = document.getElementById('confirmImportSections');
const existingSectionNames = <?php echo json_encode(array_values(array_filter(array_map(static function ($section) {
    return strtolower(trim((string) ($section['name'] ?? '')));
}, $grantSections))), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const existingSectionNameSet = new Set(existingSectionNames);
let draggingRow = null;
let isSavingOrder = false;
let pendingSave = false;
let lastSavedOrder = '';

function setOrderStatus(text, statusClass) {
    if (!sectionOrderStatus) {
        return;
    }
    sectionOrderStatus.textContent = text;
    sectionOrderStatus.classList.remove('is-saving', 'is-saved', 'is-error');
    if (statusClass) {
        sectionOrderStatus.classList.add(statusClass);
    }
}

function updateSectionOrder(updateNumbers) {
    if (!sectionTable || !sectionOrderInput) {
        return false;
    }
    const rows = Array.from(sectionTable.querySelectorAll('tr[data-section-id]'));
    const ids = rows.map((row, index) => {
        if (updateNumbers) {
            const cell = row.querySelector('.section-order');
            if (cell) {
                cell.textContent = String(index + 1);
            }
        }
        return row.dataset.sectionId;
    });
    const newValue = ids.join(',');
    const changed = newValue !== sectionOrderInput.value;
    sectionOrderInput.value = newValue;
    return changed;
}

function saveSectionOrder() {
    if (!sectionOrderForm || !sectionOrderInput) {
        return;
    }
    if (isSavingOrder) {
        pendingSave = true;
        return;
    }
    if (sectionOrderInput.value === '' || sectionOrderInput.value === lastSavedOrder) {
        return;
    }

    isSavingOrder = true;
    pendingSave = false;
    setOrderStatus('Saving changes…', 'is-saving');

    const formData = new FormData(sectionOrderForm);
    fetch(sectionOrderForm.action || window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then((response) => response.text().then((text) => ({ response, text })))
        .then(({ response, text }) => {
            if (!response.ok || text.includes('alert-error')) {
                throw new Error('Save failed');
            }
            lastSavedOrder = sectionOrderInput.value;
            setOrderStatus('All changes saved.', 'is-saved');
        })
        .catch(() => {
            setOrderStatus('Auto-save failed. Try reordering again.', 'is-error');
        })
        .finally(() => {
            isSavingOrder = false;
            if (pendingSave) {
                saveSectionOrder();
            }
        });
}

if (sectionTable) {
    sectionTable.addEventListener('dragstart', (event) => {
        const row = event.target.closest('tr[data-section-id]');
        if (!row) {
            return;
        }
        draggingRow = row;
        row.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.dataset.sectionId);
    });

    sectionTable.addEventListener('dragover', (event) => {
        event.preventDefault();
        const row = event.target.closest('tr[data-section-id]');
        if (!row || row === draggingRow) {
            return;
        }
        const rect = row.getBoundingClientRect();
        const shouldInsertAfter = (event.clientY - rect.top) > rect.height / 2;
        row.parentNode.insertBefore(draggingRow, shouldInsertAfter ? row.nextSibling : row);
    });

    sectionTable.addEventListener('drop', (event) => {
        event.preventDefault();
        const changed = updateSectionOrder(true);
        if (changed) {
            setOrderStatus('Saving changes…', 'is-saving');
            saveSectionOrder();
        }
    });

    sectionTable.addEventListener('dragend', () => {
        if (draggingRow) {
            draggingRow.classList.remove('is-dragging');
        }
        draggingRow = null;
        const changed = updateSectionOrder(true);
        if (changed) {
            setOrderStatus('Saving changes…', 'is-saving');
            saveSectionOrder();
        }
    });

    updateSectionOrder(false);
    lastSavedOrder = sectionOrderInput ? sectionOrderInput.value : '';
}

function updateBulkDeleteState() {
    if (!bulkDeleteForm || !deleteSelectedButton) {
        return;
    }
    const checkboxes = bulkDeleteForm.querySelectorAll('input[name="section_ids[]"]');
    const checked = bulkDeleteForm.querySelectorAll('input[name="section_ids[]"]:checked');
    deleteSelectedButton.disabled = checked.length === 0;
    if (selectAllSectionsCheckbox) {
        selectAllSectionsCheckbox.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
        selectAllSectionsCheckbox.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
    }
}

if (bulkDeleteForm) {
    bulkDeleteForm.addEventListener('change', (event) => {
        if (event.target && event.target.name === 'section_ids[]') {
            updateBulkDeleteState();
        }
    });
}

if (selectAllSectionsCheckbox && bulkDeleteForm) {
    selectAllSectionsCheckbox.addEventListener('change', () => {
        const checkboxes = bulkDeleteForm.querySelectorAll('input[name="section_ids[]"]');
        checkboxes.forEach((checkbox) => {
            checkbox.checked = selectAllSectionsCheckbox.checked;
        });
        updateBulkDeleteState();
    });
}

if (bulkDeleteForm && deleteSelectedButton) {
    bulkDeleteForm.addEventListener('submit', (event) => {
        const checked = bulkDeleteForm.querySelectorAll('input[name="section_ids[]"]:checked');
        if (!checked.length) {
            event.preventDefault();
            return;
        }
        const confirmed = window.confirm('Delete selected sections? This action cannot be undone.');
        if (!confirmed) {
            event.preventDefault();
        }
    });
    updateBulkDeleteState();
}

if (deleteGrantTypeButton && deleteGrantTypeForm && deleteConfirmPhraseInput) {
    deleteGrantTypeButton.addEventListener('click', () => {
        const requiredPhrase = 'DELETE THIS GRANT TYPE';
        const promptText = 'Deleting this grant type is irreversible.\n\nType "DELETE THIS GRANT TYPE" to confirm.';
        const response = window.prompt(promptText, '');
        if (response !== requiredPhrase) {
            alert('Deletion cancelled. Confirmation phrase did not match.');
            return;
        }
        const confirmDelete = window.confirm('Delete this grant type? This action cannot be undone.');
        if (!confirmDelete) {
            return;
        }
        deleteConfirmPhraseInput.value = requiredPhrase;
        deleteGrantTypeForm.submit();
    });
}

function openImportModal() {
    if (importModal) {
        importModal.style.display = 'block';
    }
}

function closeImportModal() {
    if (importModal) {
        importModal.style.display = 'none';
    }
}

function setImportListMessage(message) {
    if (!importSectionsList) {
        return;
    }
    importSectionsList.innerHTML = '';
    const span = document.createElement('span');
    span.className = 'text-muted';
    span.textContent = message;
    importSectionsList.appendChild(span);
}

function updateImportSubmitState() {
    if (!importSubmitButton || !importSectionsList) {
        return;
    }
    const checked = importSectionsList.querySelectorAll('input[type="checkbox"]:checked');
    importSubmitButton.disabled = checked.length === 0;
}

function renderImportSections(sections) {
    if (!importSectionsList) {
        return;
    }
    importSectionsList.innerHTML = '';
    if (!sections.length) {
        setImportListMessage('No sections available for this grant type.');
        updateImportSubmitState();
        return;
    }
    sections.forEach((section) => {
        const label = document.createElement('label');
        label.className = 'checkbox-item';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'section_ids[]';
        checkbox.value = section.id;
        const normalizedName = (section.name || '').trim().toLowerCase();
        if (normalizedName !== '' && existingSectionNameSet.has(normalizedName)) {
            checkbox.disabled = true;
            label.classList.add('is-disabled');
        }
        checkbox.addEventListener('change', updateImportSubmitState);

        const nameSpan = document.createElement('span');
        nameSpan.textContent = section.name;

        const metaParts = [];
        if (section.is_scored) {
            const range = section.score_min !== null && section.score_max !== null
                ? `${section.score_min}-${section.score_max}`
                : 'Scored';
            metaParts.push(`Scored ${range}`);
        } else {
            metaParts.push('Not scored');
        }
        metaParts.push(section.is_required ? 'Required' : 'Optional');

        const metaSpan = document.createElement('span');
        metaSpan.className = 'text-muted';
        if (checkbox.disabled) {
            metaParts.push('Already exists');
        }
        metaSpan.textContent = `(${metaParts.join(' • ')})`;

        label.appendChild(checkbox);
        label.appendChild(nameSpan);
        label.appendChild(metaSpan);
        importSectionsList.appendChild(label);
    });
    updateImportSubmitState();
}

async function loadImportSections() {
    if (!importSourceSelect) {
        return;
    }
    const sourceId = importSourceSelect.value;
    if (!sourceId) {
        setImportListMessage('Choose a grant type to load sections.');
        updateImportSubmitState();
        return;
    }
    setImportListMessage('Loading sections...');
    updateImportSubmitState();
    try {
        const response = await fetch(`grant_types.php?action=fetch_sections&grant_type_id=${encodeURIComponent(sourceId)}`);
        const data = await response.json();
        if (!data.success) {
            setImportListMessage(data.message || 'Failed to load sections.');
            updateImportSubmitState();
            return;
        }
        renderImportSections(data.sections || []);
    } catch (error) {
        setImportListMessage('Failed to load sections.');
        updateImportSubmitState();
    }
}

if (openImportButton) {
    openImportButton.addEventListener('click', openImportModal);
}
if (closeImportButton) {
    closeImportButton.addEventListener('click', closeImportModal);
}
if (cancelImportButton) {
    cancelImportButton.addEventListener('click', closeImportModal);
}
if (importSourceSelect) {
    importSourceSelect.addEventListener('change', loadImportSections);
}
if (importSelectAllButton) {
    importSelectAllButton.addEventListener('click', () => {
        if (!importSectionsList) {
            return;
        }
        importSectionsList.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
            if (!checkbox.disabled) {
                checkbox.checked = true;
            }
        });
        updateImportSubmitState();
    });
}
if (importDeselectAllButton) {
    importDeselectAllButton.addEventListener('click', () => {
        if (!importSectionsList) {
            return;
        }
        importSectionsList.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
            if (!checkbox.disabled) {
                checkbox.checked = false;
            }
        });
        updateImportSubmitState();
    });
}
if (importClearButton) {
    importClearButton.addEventListener('click', () => {
        if (importSourceSelect) {
            importSourceSelect.value = '';
        }
        setImportListMessage('Choose a grant type to load sections.');
        updateImportSubmitState();
    });
}
if (importSectionsForm) {
    importSectionsForm.addEventListener('submit', (event) => {
        const checked = importSectionsList
            ? importSectionsList.querySelectorAll('input[type="checkbox"]:checked')
            : [];
        if (!checked.length) {
            event.preventDefault();
            alert('Select at least one section to import.');
        }
    });
}
</script>

<?php require_once '../includes/footer.php'; ?>
