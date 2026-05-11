<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security_headers.php';

sendSecurityHeaders();

$institutionLabel = getInstitutionLabel();
$institutionIcon = getInstitutionIconUrl();
$isLoggedIn = Auth::isLoggedIn();
$dashboardUrl = BASE_URL . '/index.php';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape(APP_NAME); ?> — Asynchronous Peer Review of Grant Applications</title>
    <meta name="description" content="<?php echo escape(APP_NAME); ?> — asynchronous, NIH-style peer review of grant applications. Anonymous reviewer discussions, 1–9 criteria scoring, and study-section-ready report export. Production deployment: https://ignet.org/grant-review/">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css?v=20260510b">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/components.css?v=20260510b">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/utilities.css?v=20260510b">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/page-styles.css?v=20260510b">
</head>
<body class="landing-page">
    <a href="#main" class="skip-to-content">Skip to main content</a>

    <header class="landing-header" id="landing-header">
        <div class="container landing-header-content">
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
            <nav aria-label="Primary" class="landing-nav">
                <?php if ($isLoggedIn): ?>
                    <a href="<?php echo escape($dashboardUrl); ?>" class="btn btn-primary landing-cta">Go to dashboard</a>
                <?php else: ?>
                    <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-primary landing-cta">Sign in</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main id="main">
        <section class="landing-hero" aria-labelledby="hero-heading">
            <div class="container landing-hero-inner">
                <p class="landing-eyebrow">University of North Dakota — Grant Review Tooling</p>
                <p class="landing-eyebrow-sub">In collaboration with TRANSCEND RDCDC</p>
                <h1 id="hero-heading" class="landing-title"><?php echo escape(APP_NAME); ?></h1>
                <p class="landing-lede">
                    Asynchronous, NIH-style peer review of grant applications. Reviewers score on the 1–9 criteria scale,
                    write structured critiques, and discuss anonymously — then administrators export study-section-ready reports.
                </p>
                <div class="landing-cta-row">
                    <?php if ($isLoggedIn): ?>
                        <a href="<?php echo escape($dashboardUrl); ?>" class="btn btn-primary btn-lg">Go to your dashboard</a>
                    <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-primary btn-lg">Sign in</a>
                        <span class="landing-cta-note">Account access is invite-only. Contact your study-section administrator for credentials.</span>
                    <?php endif; ?>
                </div>
                <p class="landing-prod-link">
                    Production deployment:
                    <span class="landing-prod-url">https://ignet.org/grant-review/</span>
                </p>
            </div>
        </section>

        <section class="landing-features" aria-labelledby="features-heading">
            <div class="container">
                <h2 id="features-heading" class="landing-section-title">How it works</h2>
                <ol class="landing-steps">
                    <li class="landing-step">
                        <div class="landing-step-head">
                            <span aria-hidden="true" class="landing-step-num">1</span>
                            <h3 class="landing-step-title">Submit applications &amp; assign reviewers</h3>
                        </div>
                        <p class="landing-step-body">
                            Administrators upload grant applications (DOCX or PDF), define grant types and study sections,
                            and assign reviewer panels with conflict-of-interest checks.
                        </p>
                    </li>
                    <li class="landing-step">
                        <div class="landing-step-head">
                            <span aria-hidden="true" class="landing-step-num">2</span>
                            <h3 class="landing-step-title">Anonymous critique &amp; discussion</h3>
                        </div>
                        <p class="landing-step-body">
                            Reviewers score each application on NIH 1–9 criteria, write structured critiques,
                            and exchange threaded comments under stable anonymous handles before the study-section meeting.
                        </p>
                    </li>
                    <li class="landing-step">
                        <div class="landing-step-head">
                            <span aria-hidden="true" class="landing-step-num">3</span>
                            <h3 class="landing-step-title">Generate study-section reports</h3>
                        </div>
                        <p class="landing-step-body">
                            Export consolidated critiques, summary statements, and overall impact scores
                            in a study-section-ready format suitable for archival and follow-up review cycles.
                        </p>
                    </li>
                </ol>
            </div>
        </section>
    </main>

    <footer class="landing-footer">
        <div class="container landing-footer-content">
            <p class="landing-footer-attribution">
                Developed by Dr. Junguk Hur, Associate Professor, University of North Dakota School of Medicine and Health Sciences.
                Supported by TRANSCEND RDCDC (NIH/NIGMS P20GM155890).
            </p>
            <div class="landing-footer-meta">
                <p><?php echo escape(APP_NAME); ?></p>
                <p>University of North Dakota &middot; TRANSCEND RDCDC</p>
            </div>
        </div>
    </footer>
</body>
</html>
