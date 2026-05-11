<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Authenticated users go straight to their role dashboard.
// Guests see the public landing page (welcome.php).
if (Auth::isLoggedIn()) {
    if (Auth::isAdmin()) {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/reviewer/dashboard.php');
    }
    exit;
}

require __DIR__ . '/welcome.php';
