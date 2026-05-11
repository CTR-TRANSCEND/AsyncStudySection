<?php
declare(strict_types=1);
require_once 'includes/session.php';
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Redirect if already logged in
if (Auth::isLoggedIn()) {
    // Redirect based on user role
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
    } elseif ($role === 'reviewer') {
        header('Location: ' . BASE_URL . '/reviewer/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/index.php');
    }
    exit;
}

$error = '';
$institutionLabel = getInstitutionLabel();
$institutionIcon = getInstitutionIconUrl();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfError = verifyCsrfToken();
    if ($csrfError) {
        $error = $csrfError;
    }

    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$error && (empty($username) || empty($password))) {
        $error = 'Please enter both username and password.';
    } elseif (!$error) {
        $auth = new Auth();
        if ($auth->login($username, $password)) {
            // Redirect based on user role
            $role = $_SESSION['role'] ?? '';
            if ($role === 'admin') {
                header('Location: ' . BASE_URL . '/admin/dashboard.php');
            } elseif ($role === 'reviewer') {
                header('Location: ' . BASE_URL . '/reviewer/dashboard.php');
            } else {
                header('Location: ' . BASE_URL . '/index.php');
            }
            exit;
        } else {
            $error = $auth->getLastError() ?: 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo escape(APP_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <!-- Skip to main content link for accessibility (SPEC-UIX-002 Milestone 3) -->
    <a href="#login-form" class="skip-to-content">Skip to login form</a>

    <div class="login-container">
        <main class="login-card" id="login-form">
            <header class="login-header">
                <?php if ($institutionIcon !== ''): ?>
                    <img src="<?php echo escape($institutionIcon); ?>" alt="Institution logo" class="login-brand-icon">
                <?php endif; ?>
                <h1 class="login-title"><?php echo escape(APP_NAME); ?></h1>
                <?php if ($institutionLabel !== ''): ?>
                    <div class="login-brand-meta"><?php echo escape($institutionLabel); ?></div>
                <?php endif; ?>
                <p class="login-subtitle">Sign in to your account</p>
                <!-- Default credentials removed for security -->
            </header>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo escape($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        required
                        autofocus
                        value="<?php echo isset($_POST['username']) ? escape($_POST['username']) : ''; ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-block">Sign In</button>
            </form>
        </main>
    </div>
</body>
</html>
