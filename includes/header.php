<?php
require_once __DIR__ . '/security_headers.php';
sendSecurityHeaders();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? escape($pageTitle) . ' - ' : ''; ?><?php echo escape(APP_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=20260510b">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/components.css?v=20260510b">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/utilities.css?v=20260510b">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/page-styles.css?v=20260510b">
    <!-- Skip navigation for accessibility -->
</head>
<body>
    <a href="#main-content" class="skip-to-content">Skip to main content</a>
    <header class="header" id="main-header">
        <div class="container">
            <div class="header-content">
                <?php
                $institutionLabel = getInstitutionLabel();
                $institutionIcon = getInstitutionIconUrl();
                ?>
                <a href="<?php echo BASE_URL; ?>/welcome.php" class="brand" aria-label="<?php echo escape(APP_NAME); ?> home">
                    <?php if ($institutionIcon !== ''): ?>
                        <img src="<?php echo escape($institutionIcon); ?>" alt="Institution logo" class="brand-icon">
                    <?php endif; ?>
                    <span class="brand-text">
                        <span class="brand-name"><?php echo escape(APP_NAME); ?></span>
                        <?php if ($institutionLabel !== ''): ?>
                            <span class="brand-meta"><?php echo escape($institutionLabel); ?></span>
                        <?php endif; ?>
                    </span>
                </a>

                <!-- Mobile menu toggle button -->
                <button class="mobile-menu-toggle" type="button" aria-label="Toggle navigation menu" aria-expanded="false" id="mobile-menu-toggle">
                    <svg class="mobile-menu-toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                </button>

                <?php
                // Render navigation using centralized system (SPEC-UIX-002 Milestone 2)
                $userRole = Auth::isAdmin() ? 'admin' : (Auth::isReviewer() ? 'reviewer' : null);
                if ($userRole) {
                    echo renderNavigation($userRole, Auth::getUserId());
                }
                ?>

                <?php
                $fullName = trim(Auth::getFullName() ?? '');
                $firstName = $fullName !== '' ? preg_split('/\s+/', $fullName)[0] : 'Account';
                $initial = $firstName !== '' ? strtoupper(substr($firstName, 0, 1)) : '?';
                ?>
                <div class="user-menu">
                    <button class="user-menu-button" type="button" aria-haspopup="true" aria-expanded="false" id="user-menu-button" aria-label="User menu for <?php echo escape($firstName); ?>">
                        <span class="user-avatar" aria-hidden="true"><?php echo escape($initial); ?></span>
                        <span class="user-menu-label"><?php echo escape($firstName); ?></span>
                        <span class="user-menu-caret" aria-hidden="true">▾</span>
                    </button>
                    <div class="user-menu-dropdown" role="menu" aria-labelledby="user-menu-button" id="user-menu-dropdown">
                        <div style="padding: 0.5rem 0.9rem; border-bottom: 1px solid var(--border-color); margin-bottom: 0.5rem;" id="agr-theme-toggle-container"></div>
                        <a href="<?php echo BASE_URL; ?>/profile.php" role="menuitem">Profile & Settings</a>
                        <a href="<?php echo BASE_URL; ?>/logout.php" role="menuitem">Logout</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="main-content" id="main-content">
        <div class="container">
