<?php
declare(strict_types=1);
require_once 'includes/session.php';
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/UserNameHelper.php';

Auth::requireLogin();

$db = Database::getInstance()->getConnection();
$userId = Auth::getUserId();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    } else {
        // === BEGIN POST shim (one-release backward compat for in-flight forms;
        //     remove after next release). SPEC-NAME-SPLIT-001 §7.4. ===
        if (!isset($_POST['first_name']) && isset($_POST['full_name'])) {
            $cleanFullName = sanitize($_POST['full_name']);
            $decomposed = UserNameHelper::decompose($cleanFullName);
            $_POST['first_name'] = $decomposed['first_name'];
            $_POST['last_name']  = $decomposed['last_name'];
            $_POST['degrees']    = $decomposed['degrees'];
        }
        // === END POST shim ===

        $firstName   = sanitize($_POST['first_name'] ?? '');
        $lastName    = sanitize($_POST['last_name'] ?? '');
        $degrees     = sanitize($_POST['degrees'] ?? '');
        $fullName    = UserNameHelper::compose($firstName, $lastName, $degrees);
        $email       = sanitize($_POST['email'] ?? '');
        $institution = sanitize($_POST['institution'] ?? '');
        $password    = (string) ($_POST['password'] ?? '');
        $currentPassword = (string) ($_POST['current_password'] ?? '');

        if ($lastName === '' || $email === '') {
            $error = 'Last name and email are required.';
        } elseif ($password !== '' && strlen($password) < PASSWORD_MIN_LENGTH) {
            $error = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        } else {
            $stmt = $db->prepare("SELECT full_name, first_name, last_name, degrees, email, institution, password_hash FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $current = $stmt->fetch();

            if ($current) {
                $updates = [
                    'full_name'   => $fullName,
                    'first_name'  => $firstName,
                    'last_name'   => $lastName,
                    'degrees'     => $degrees,
                    'email'       => $email,
                    'institution' => $institution,
                ];

                $passwordHash = null;
                if ($password !== '') {
                    if ($currentPassword === '') {
                        $error = 'Current password is required to change your password.';
                    } elseif (!password_verify($currentPassword, $current['password_hash'])) {
                        $error = 'Current password is incorrect.';
                    } else {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    }
                }

                if ($error === '') {
                    $stmt = $db->prepare("
                        UPDATE users
                        SET full_name = ?, first_name = ?, last_name = ?, degrees = ?,
                            email = ?, institution = ?,
                            password_hash = COALESCE(?, password_hash),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $fullName, $firstName, $lastName, $degrees ?: null,
                        $email, $institution, $passwordHash, $userId
                    ]);

                    foreach ($updates as $field => $value) {
                        if (($current[$field] ?? '') !== $value) {
                            logAudit('users', $userId, $field, $current[$field] ?? null, $value, 'update');
                        }
                    }

                    if ($password !== '') {
                        logAudit('users', $userId, 'password_hash', null, 'updated', 'update');
                    }

                    $message = 'Profile updated.';
                }
            }
        }
    }
}

$stmt = $db->prepare("SELECT username, full_name, first_name, last_name, degrees, email, institution, role FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$pageTitle = 'Profile & Settings';
require_once 'includes/header.php';
?>

<h1 class="mb-4">Profile & Settings</h1>

<div class="card">
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo escape($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo escape($error); ?></div>
        <?php endif; ?>

        <?php if (!$user): ?>
            <div class="alert alert-error">User record not found.</div>
        <?php else: ?>
            <form method="POST" action="">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" value="<?php echo escape($user['username']); ?>" disabled>
                </div>
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <input type="text" class="form-control" value="<?php echo escape($user['role']); ?>" disabled>
                </div>
                <div class="form-group">
                    <label for="first_name" class="form-label">First Name</label>
                    <input type="text" id="first_name" name="first_name" class="form-control" value="<?php echo escape($user['first_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="last_name" class="form-label">Last Name</label>
                    <input type="text" id="last_name" name="last_name" class="form-control" required value="<?php echo escape($user['last_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="degrees" class="form-label">Degrees</label>
                    <input type="text" id="degrees" name="degrees" class="form-control" placeholder="e.g. PhD, MD, MS" value="<?php echo escape($user['degrees'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required value="<?php echo escape($user['email']); ?>">
                </div>
                <div class="form-group">
                    <label for="institution" class="form-label">Institution</label>
                    <input type="text" id="institution" name="institution" class="form-control" value="<?php echo escape($user['institution'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="current_password" class="form-label">Current Password</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" placeholder="Required to change password" autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label for="password" class="form-label">New Password</label>
                    <input type="password" id="password" name="password" class="form-control" autocomplete="new-password">
                    <small class="text-muted">Leave blank to keep your current password.</small>
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
