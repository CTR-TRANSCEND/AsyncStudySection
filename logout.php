<?php
declare(strict_types=1);
require_once 'includes/session.php';
require_once 'includes/auth.php';

Auth::logoutUser();

header('Location: ' . BASE_URL . '/login.php');
exit;
