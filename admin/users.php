<?php
declare(strict_types=1);
require_once '../includes/session.php';
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/UserNameHelper.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';
$csrfError = null;

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Handle user actions
// (User-list query is deliberately AFTER the POST handler so the rendered
// $users array reflects any add/edit/delete/toggle/reset/upload performed
// in this request — otherwise a freshly-created user wouldn't appear until
// the next page refresh.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    }

    // === BEGIN POST shim (one-release backward compat for in-flight forms;
    //     remove after next release). SPEC-NAME-SPLIT-001 §7.4. ===
    if (!$csrfError && !isset($_POST['first_name']) && isset($_POST['full_name'])) {
        $cleanFullName = sanitize($_POST['full_name']);
        $decomposed = UserNameHelper::decompose($cleanFullName);
        $_POST['first_name'] = $decomposed['first_name'];
        $_POST['last_name']  = $decomposed['last_name'];
        $_POST['degrees']    = $decomposed['degrees'];
    }
    // === END POST shim ===

    $action = $_POST['action'] ?? '';

    if ($action === 'add_user' && !$csrfError) {
        $username    = sanitize($_POST['username']);
        $password    = $_POST['password'];
        $first_name  = sanitize($_POST['first_name'] ?? '');
        $last_name   = sanitize($_POST['last_name'] ?? '');
        $degrees     = sanitize($_POST['degrees'] ?? '');
        $full_name   = UserNameHelper::compose($first_name, $last_name, $degrees);
        $email       = sanitize($_POST['email']);
        $institution = sanitize($_POST['institution'] ?? '');
        $role        = in_array($_POST['role'] ?? '', ['admin', 'reviewer'], true) ? $_POST['role'] : 'reviewer';

        if (empty($username) || empty($last_name) || empty($email)) {
            $error = 'Username, last name, and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < PASSWORD_MIN_LENGTH) {
            $error = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO users (username, password_hash, full_name, first_name, last_name, degrees, email, institution, role)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt->execute([
                    $username, $passwordHash, $full_name,
                    $first_name, $last_name, $degrees ?: null,
                    $email, $institution, $role
                ]);

                logAudit('users', $db->lastInsertId(), 'created', null, $username, 'insert');
                $message = 'User created successfully.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'Username already exists.';
                } else {
                    $error = 'Error creating user.';
                }
            }
        }
    } elseif ($action === 'edit_user' && !$csrfError) {
        $userId      = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $username    = sanitize($_POST['username']);
        $first_name  = sanitize($_POST['first_name'] ?? '');
        $last_name   = sanitize($_POST['last_name'] ?? '');
        $degrees     = sanitize($_POST['degrees'] ?? '');
        $full_name   = UserNameHelper::compose($first_name, $last_name, $degrees);
        $email       = sanitize($_POST['email']);
        $institution = sanitize($_POST['institution'] ?? '');
        $role        = in_array($_POST['role'] ?? '', ['admin', 'reviewer'], true) ? $_POST['role'] : 'reviewer';

        try {
            $stmt = $db->prepare("SELECT role, is_active FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $currentUser = $stmt->fetch();
            if (!$currentUser) {
                throw new Exception('User not found.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            if ($last_name === '') {
                throw new Exception('Last name is required.');
            }

            if ($currentUser['role'] === 'admin' && $role !== 'admin') {
                $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin' AND is_active = TRUE");
                $row = $stmt->fetch();
                $adminCount = $row ? (int) $row['count'] : 0;
                if ($adminCount <= 1) {
                    throw new Exception('Cannot remove the last active admin.');
                }
            }

            $stmt = $db->prepare("
                UPDATE users
                SET username = ?, full_name = ?, first_name = ?, last_name = ?, degrees = ?,
                    email = ?, institution = ?, role = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $username, $full_name, $first_name, $last_name, $degrees ?: null,
                $email, $institution, $role, $userId
            ]);
            logAudit('users', $userId, 'updated', null, 'user details updated', 'update');
            $message = 'User updated successfully.';
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'Username already exists.';
            } else {
                $error = 'Error updating user.';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'delete_user' && !$csrfError) {
        $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        $stmt = $db->prepare("SELECT role, is_active FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) {
            $error = 'User not found.';
        } elseif ($userId === (int) Auth::getUserId()) {
            $error = 'You cannot delete your own account.';
        }

        // Check if user has reviews or assignments
        if (!$error) {
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM reviews WHERE reviewer_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            $reviewCount = $row ? (int) $row['count'] : 0;

            if ($reviewCount > 0) {
                $error = 'Cannot delete user with existing reviews. Deactivate instead.';
            }
        }

        if (!$error && $targetUser['role'] === 'admin') {
            $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin' AND is_active = TRUE");
            $row = $stmt->fetch();
            $adminCount = $row ? (int) $row['count'] : 0;
            if ($adminCount <= 1) {
                $error = 'Cannot delete the last active admin.';
            }
        }

        if (!$error) {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            logAudit('users', $userId, 'deleted', null, null, 'delete');
            $message = 'User deleted successfully.';
        }
    } elseif ($action === 'toggle_status' && !$csrfError) {
        $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        $stmt = $db->prepare("SELECT role, is_active FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) {
            $error = 'User not found.';
        } elseif ($userId === (int) Auth::getUserId()) {
            $error = 'You cannot deactivate your own account.';
        } elseif ($targetUser['role'] === 'admin' && $targetUser['is_active']) {
            $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin' AND is_active = TRUE");
            $row = $stmt->fetch();
            $adminCount = $row ? (int) $row['count'] : 0;
            if ($adminCount <= 1) {
                $error = 'Cannot deactivate the last active admin.';
            }
        }

        if (!$error) {
            $stmt = $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$userId]);
            logAudit('users', $userId, 'is_active', null, 'toggled', 'update');
            $message = 'User status updated.';
        }
    } elseif ($action === 'reset_password' && !$csrfError) {
        $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $newPassword = $_POST['new_password'];

        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            $error = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$passwordHash, $userId]);
            logAudit('users', $userId, 'password', 'changed', 'changed', 'update');
            $message = 'Password reset successfully.';
        }
    } elseif ($action === 'upload_users' && !$csrfError) {
        if (isset($_FILES['user_file']) && $_FILES['user_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['user_file'];
            $tmpPath = $file['tmp_name'];
            $fileExt = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

            if ($fileExt !== 'csv') {
                $error = 'Only .csv files are supported for bulk import.';
            } elseif (($file['size'] ?? 0) > MAX_UPLOAD_SIZE) {
                $error = 'File size exceeds maximum allowed size.';
            } else {
            $handle = fopen($tmpPath, 'r');
            if ($handle) {
                // Header-row content check (SPEC-NAME-SPLIT-001 §4.7.1):
                // detect new 8-col vs legacy 6-col format. Reject scrambled
                // or unknown headers rather than silently mis-mapping rows.
                $EXPECTED_NEW    = ['username','password','first_name','last_name','degrees','email','institution','role'];
                $EXPECTED_LEGACY = ['username','password','full_name','email','institution','role'];

                $headerRow = fgetcsv($handle);
                if ($headerRow === false) {
                    fclose($handle);
                    $error = 'CSV file is empty.';
                } else {
                    $header = array_map('strtolower', array_map('trim', $headerRow));

                    if ($header === $EXPECTED_NEW) {
                        $csvFormat = 'new';
                    } elseif ($header === $EXPECTED_LEGACY) {
                        $csvFormat = 'legacy';
                    } else {
                        fclose($handle);
                        $error = 'Unexpected CSV header. Expected: ' .
                                 implode(',', $EXPECTED_NEW) . ' (current) or ' .
                                 implode(',', $EXPECTED_LEGACY) . ' (legacy). Re-download the template.';
                        $csvFormat = null;
                    }

                    if ($csvFormat !== null) {
                        $imported = 0;
                        $errors_list = [];
                        if ($csvFormat === 'legacy') {
                            $errors_list[] = 'Note: legacy 6-column CSV format detected; first/last/degrees were derived from full_name. Re-download the template to upgrade.';
                        }

                        while (($data = fgetcsv($handle)) !== false) {
                            if ($csvFormat === 'new') {
                                if (count($data) !== 8) {
                                    $errors_list[] = 'Skipped row: expected 8 columns, got ' . count($data);
                                    continue;
                                }
                                [$username, $password, $first_name, $last_name, $degrees, $email, $institution, $role] = $data;
                            } else {
                                if (count($data) !== 6) {
                                    $errors_list[] = 'Skipped row: expected 6 columns, got ' . count($data);
                                    continue;
                                }
                                [$username, $password, $full_name_raw, $email, $institution, $role] = $data;
                                $decomposed = UserNameHelper::decompose(sanitize($full_name_raw));
                                $first_name = $decomposed['first_name'];
                                $last_name  = $decomposed['last_name'];
                                $degrees    = $decomposed['degrees'];
                            }

                            $username    = sanitize($username);
                            $first_name  = sanitize($first_name);
                            $last_name   = sanitize($last_name);
                            $degrees     = sanitize($degrees);
                            $email       = sanitize($email);
                            $institution = sanitize($institution);
                            $full_name   = UserNameHelper::compose($first_name, $last_name, $degrees);

                            if (empty($username) || empty($password) || empty($last_name) || empty($email)) {
                                $errors_list[] = "Skipped row: Missing required fields for $username";
                                continue;
                            }

                            if (strlen($password) < PASSWORD_MIN_LENGTH) {
                                $errors_list[] = "Skipped $username: Password too short";
                                continue;
                            }

                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $errors_list[] = "Skipped $username: Invalid email";
                                continue;
                            }

                            if (!in_array($role, ['admin', 'reviewer'])) {
                                $role = 'reviewer';
                            }

                            try {
                                $stmt = $db->prepare("
                                    INSERT INTO users (username, password_hash, full_name, first_name, last_name, degrees, email, institution, role)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                                $stmt->execute([
                                    $username, $passwordHash, $full_name,
                                    $first_name, $last_name, $degrees ?: null,
                                    $email, $institution, $role
                                ]);
                                logAudit('users', $db->lastInsertId(), 'created', null, 'imported from CSV', 'insert');
                                $imported++;
                            } catch (PDOException $e) {
                                if ($e->getCode() === '23000') {
                                    $errors_list[] = "Skipped $username: Already exists";
                                } else {
                                    $errors_list[] = "Error importing $username";
                                }
                            }
                        }
                        fclose($handle);

                        $message = "Imported $imported users successfully.";
                        if (!empty($errors_list)) {
                            $error = implode("\n", $errors_list);
                        }
                    }
                }
            } else {
                $error = 'Failed to read uploaded file.';
            }
            }
        } else {
            $error = 'No file uploaded or upload error.';
        }
    }
}

// Fetch the user list AFTER any POST mutations so the rendered table is current.
$stmt = $db->query("SELECT COUNT(*) as total FROM users");
$row = $stmt->fetch();
$totalUsers = $row ? (int) $row['total'] : 0;
$totalPages = max(1, (int) ceil($totalUsers / $perPage));

// Clamp $page to current $totalPages in case a delete reduced the page count.
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $db->prepare("SELECT * FROM users ORDER BY role, last_name, first_name LIMIT ? OFFSET ?");
$stmt->execute([$perPage, $offset]);
$users = $stmt->fetchAll();

$pageTitle = 'User Management';
require_once '../includes/header.php';
?>

<h1 class="mb-4">User Management</h1>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo escape($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo nl2br(escape($error)); ?></div>
<?php endif; ?>

<!-- Tab Navigation -->
<div class="tab-container">
    <div class="tab-buttons">
        <button class="tab-button active" onclick="showTab('all-users')">All Users (<?php echo $totalUsers; ?>)</button>
        <button class="tab-button" onclick="showTab('create-user')">Create User</button>
    </div>
</div>

<!-- Tab 1: All Users -->
<div id="tab-all-users" class="tab-content active">
    <div class="card">
        <div class="card-body" style="overflow-x: auto;">
            <p id="users-table-description" class="sr-only">
                Table listing all system users with their details including name, username, email, institution, role, status, and available actions.
            </p>
            <table class="table" aria-describedby="users-table-description">
                <caption class="sr-only">All Users - System user management table</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Username</th>
                        <th scope="col">Email</th>
                        <th scope="col">Institution</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo escape($user['full_name']); ?></td>
                                <td><?php echo escape($user['username']); ?></td>
                                <td><?php echo escape($user['email']); ?></td>
                                <td><?php echo escape($user['institution'] ?? 'N/A'); ?></td>
                                <td><span class="badge badge-<?php echo $user['role'] === 'admin' ? 'danger' : 'primary'; ?>"><?php echo escape($user['role']); ?></span></td>
                                <td>
                                    <span class="badge badge-<?php echo $user['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td style="white-space: nowrap; font-size: 0.85rem;">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-primary"
                                        onclick="showEditUser(<?php echo htmlspecialchars(json_encode($user), ENT_QUOTES); ?>)"
                                        title="Edit User"
                                        style="padding: 0.25rem 0.4rem; font-size: 0.8rem;"
                                    >
                                        Edit
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        onclick="showToggleStatus(<?php echo (int) $user['id']; ?>, '<?php echo escapeJs($user['full_name']); ?>', <?php echo (int)$user['is_active']; ?>)"
                                        title="Toggle Active Status"
                                        style="padding: 0.25rem 0.4rem; font-size: 0.8rem;"
                                    >
                                        <?php echo $user['is_active'] ? 'Off' : 'On'; ?>
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-warning"
                                        onclick="showResetPassword(<?php echo (int) $user['id']; ?>, '<?php echo escapeJs($user['username']); ?>', '<?php echo escapeJs($user['full_name']); ?>')"
                                        title="Reset Password"
                                        style="padding: 0.25rem 0.4rem; font-size: 0.8rem;"
                                    >
                                        Pwd
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-danger"
                                        onclick="showDeleteUser(<?php echo (int) $user['id']; ?>, '<?php echo escapeJs($user['full_name']); ?>')"
                                        title="Delete User"
                                        style="padding: 0.25rem 0.4rem; font-size: 0.8rem;"
                                    >
                                        Del
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination" style="display: flex; list-style: none; padding: 0; justify-content: center; gap: 0.25rem;">
                        <?php if ($page > 1): ?>
                            <li><a href="?page=1" class="btn btn-sm btn-secondary">« First</a></li>
                            <li><a href="?page=<?php echo $page - 1; ?>" class="btn btn-sm btn-secondary">‹ Prev</a></li>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1) $endPage = min($totalPages, $startPage + 4);
                        if ($endPage < $totalPages) $startPage = max(1, $endPage - 4);
                        ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php if ($i === $page): ?>
                                <li><span class="btn btn-sm btn-primary"><?php echo $i; ?></span></li>
                            <?php else: ?>
                                <li><a href="?page=<?php echo $i; ?>" class="btn btn-sm btn-secondary"><?php echo $i; ?></a></li>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <li><a href="?page=<?php echo $page + 1; ?>" class="btn btn-sm btn-secondary">Next ›</a></li>
                            <li><a href="?page=<?php echo $totalPages; ?>" class="btn btn-sm btn-secondary">Last »</a></li>
                        <?php endif; ?>
                    </ul>
                    <p class="text-center text-muted mt-2" style="font-size: 0.85rem;">
                        Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $perPage, $totalUsers); ?> of <?php echo $totalUsers; ?> users
                        (Page <?php echo $page; ?> of <?php echo $totalPages; ?>)
                    </p>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tab 2: Create User -->
<div id="tab-create-user" class="tab-content">
    <div class="grid" style="grid-template-columns: 1fr 1fr;">
        <!-- Add New User Form -->
        <div class="card">
            <div class="card-header">Add New User</div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_user">

                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">
                            <small class="text-muted">Minimum <?php echo PASSWORD_MIN_LENGTH; ?> characters</small>
                        </div>
                    </div>

                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-control">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                    </div>

                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">Degrees</label>
                            <input type="text" name="degrees" class="form-control" placeholder="e.g. PhD, MD, MS">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                    </div>

                    <div class="grid grid-2">
                        <div class="form-group">
                            <label class="form-label">Institution</label>
                            <input type="text" name="institution" class="form-control">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-control" required>
                                <option value="reviewer">Reviewer</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Create User</button>
                </form>
            </div>
        </div>

        <!-- Bulk Import -->
        <div class="card">
            <div class="card-header">Bulk Import Users from CSV</div>
            <div class="card-body">
                <h4>Upload CSV File</h4>
                <form method="POST" enctype="multipart/form-data" class="mb-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="upload_users">
                    <div class="form-group">
                        <label class="form-label">Select CSV file</label>
                        <input type="file" name="user_file" class="form-control" accept=".csv" required>
                        <small class="text-muted">CSV format (current): username, password, first_name, last_name, degrees, email, institution, role. Legacy 6-column files (with full_name instead) are accepted with a deprecation warning.</small>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload and Import</button>
                </form>

                <hr>

                <h4>Download Template</h4>
                <p>Download the CSV template with sample data to see the required format.</p>
                <a href="download_user_template.php" class="btn btn-success" download>
                    Download Template
                </a>
                <p class="mt-3 text-muted" style="font-size: 0.85rem;">
                    <strong>Template columns (current 8-col format):</strong><br>
                    1. username<br>
                    2. password<br>
                    3. first_name<br>
                    4. last_name<br>
                    5. degrees (e.g. PhD; may be blank)<br>
                    6. email<br>
                    7. institution<br>
                    8. role (admin or reviewer)<br>
                    <em>Legacy 6-col format (full_name instead of first/last/degrees) is still accepted with a deprecation warning.</em>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; overflow-y: auto;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 0.5rem; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h3 class="mb-3">Edit User</h3>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="edit_user_id">

            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" id="edit_username" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">First Name</label>
                <input type="text" name="first_name" id="edit_first_name" class="form-control">
            </div>

            <div class="form-group">
                <label class="form-label">Last Name</label>
                <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">Degrees</label>
                <input type="text" name="degrees" id="edit_degrees" class="form-control" placeholder="e.g. PhD, MD, MS">
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" id="edit_email" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">Institution</label>
                <input type="text" name="institution" id="edit_institution" class="form-control">
            </div>

            <div class="form-group">
                <label class="form-label">Role</label>
                <select name="role" id="edit_role" class="form-control" required>
                    <option value="reviewer">Reviewer</option>
                    <option value="admin">Admin</option>
                </select>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditUser()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 0.5rem; max-width: 400px; width: 90%;">
        <h3 class="mb-3">Reset Password</h3>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id">

            <div class="form-group">
                <label class="form-label">
                    User:
                    <strong id="reset_username"></strong>
                    <span id="reset_full_name" style="color: #666; font-weight: normal;"></span>
                </label>
            </div>

            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="new_password" class="form-control" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Reset Password</button>
                <button type="button" class="btn btn-secondary" onclick="closeResetPassword()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Toggle Status Modal -->
<div id="toggleStatusModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 0.5rem; max-width: 450px; width: 90%;">
        <h3 class="mb-3" id="toggle_status_title">Toggle User Status</h3>
        <p id="toggle_status_message" class="mb-3"></p>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="user_id" id="toggle_user_id">

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-warning">Confirm</button>
                <button type="button" class="btn btn-secondary" onclick="closeToggleStatus()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete User Modal -->
<div id="deleteUserModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 0.5rem; max-width: 450px; width: 90%;">
        <h3 class="mb-3" style="color: #dc3545;">Delete User</h3>
        <p id="delete_user_message" class="mb-3" style="font-size: 1rem;"></p>
        <p class="text-muted mb-3"><small>This action cannot be undone!</small></p>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" id="delete_user_id">

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger">Delete User</button>
                <button type="button" class="btn btn-secondary" onclick="closeDeleteUser()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function showTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(function(tab) {
        tab.classList.remove('active');
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('.tab-button').forEach(function(btn) {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById('tab-' + tabName).classList.add('active');
    
    // Find and activate the clicked button
    event.target.classList.add('active');
}

function showEditUser(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_first_name').value = user.first_name || '';
    document.getElementById('edit_last_name').value = user.last_name || '';
    document.getElementById('edit_degrees').value = user.degrees || '';
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_institution').value = user.institution || '';
    document.getElementById('edit_role').value = user.role;
    document.getElementById('editUserModal').style.display = 'block';
}

function closeEditUser() {
    document.getElementById('editUserModal').style.display = 'none';
}

function showResetPassword(userId, userName, fullName) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username').textContent = userName;
    document.getElementById('reset_full_name').textContent = fullName ? ' — ' + fullName : '';
    document.getElementById('resetPasswordModal').style.display = 'block';
}

function closeResetPassword() {
    document.getElementById('resetPasswordModal').style.display = 'none';
}

function showToggleStatus(userId, userName, isActive) {
    document.getElementById('toggle_user_id').value = userId;
    
    var title = document.getElementById('toggle_status_title');
    var message = document.getElementById('toggle_status_message');
    
    if (isActive) {
        title.textContent = 'Deactivate User';
        message.innerHTML = 'Are you sure you want to deactivate <strong>' + escapeHtml(userName) + '</strong>?<br>The user will not be able to login while inactive.';
    } else {
        title.textContent = 'Activate User';
        message.innerHTML = 'Are you sure you want to activate <strong>' + escapeHtml(userName) + '</strong>?<br>The user will be able to login after activation.';
    }
    
    document.getElementById('toggleStatusModal').style.display = 'block';
}

function closeToggleStatus() {
    document.getElementById('toggleStatusModal').style.display = 'none';
}

function showDeleteUser(userId, userName) {
    document.getElementById('delete_user_id').value = userId;
    document.getElementById('delete_user_message').innerHTML = 'Are you sure you want to delete <strong>' + escapeHtml(userName) + '</strong>?<br>All data associated with this account will be permanently removed.';
    document.getElementById('deleteUserModal').style.display = 'block';
}

function closeDeleteUser() {
    document.getElementById('deleteUserModal').style.display = 'none';
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once '../includes/footer.php'; ?>
