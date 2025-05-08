<?php
// public_html/header_common.php
// This header is for pages like login, registration, select_project, profile, admin.
// It assumes bootstrap.php has been included by the calling page.
// It also assumes initializeSession() or secureSession() has been called.

// Default title if not set by the calling page
$pageTitle = isset($pageTitle) ? $pageTitle : 'سامانه مدیریت کارخانه';
$avatarPathCommon = '/assets/images/default-avatar.jpg'; // Default, ensure this path is web accessible

if (isLoggedIn()) { // isLoggedIn() from bootstrap.php
    try {
        $pdo_common_header = getCommonDBConnection(); // From bootstrap.php

        // Fetch minimal user info for header display (avatar, name)
        $stmt_user_header = $pdo_common_header->prepare("SELECT first_name, last_name, avatar_path FROM users WHERE id = ?");
        $stmt_user_header->execute([$_SESSION['user_id']]);
        $user_header_data = $stmt_user_header->fetch(PDO::FETCH_ASSOC);

        if ($user_header_data) {
            // Use a web-accessible path for the avatar.
            // The AVATAR_UPLOAD_DIR_PUBLIC constant should define the base web path.
            // PUBLIC_HTML_ROOT is for filesystem checks.
            $potentialAvatarWebPath = isset($user_header_data['avatar_path']) ? $user_header_data['avatar_path'] : null;
            if ($potentialAvatarWebPath && fileExistsAndReadable(PUBLIC_HTML_ROOT . $potentialAvatarWebPath)) {
                $avatarPathCommon = escapeHtml($potentialAvatarWebPath);
            }
            $_SESSION['first_name_header'] = $user_header_data['first_name'];
            $_SESSION['last_name_header'] = $user_header_data['last_name'];
        }
    } catch (Exception $e) {
        logError("Error fetching user data for common header: " . $e->getMessage());
        // Continue, use defaults
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapeHtml($pageTitle); ?> - مدیریت مرکزی</title>
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
    <link rel="stylesheet" href="/assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="/assets/css/all.min.css"> <!-- Font Awesome -->
    <style>
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2'),
                url('/assets/fonts/Vazir.woff') format('woff');
            font-weight: normal;
            font-style: normal;
        }

        body {
            font-family: 'Vazir', sans-serif;
            background-color: #eef2f7;
            padding-top: 70px;
            /* Adjusted for fixed navbar height */
        }

        .navbar-common {
            background-color: #34495e;
            border-bottom: 3px solid #f39c12;
        }

        .navbar-common .navbar-brand,
        .navbar-common .nav-link {
            color: #ecf0f1;
        }

        .navbar-common .nav-link:hover,
        .navbar-common .nav-link.active {
            color: #ffffff;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .navbar-common .navbar-brand img {
            max-height: 30px;
            margin-left: 10px;
        }

        .user-avatar-common {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            margin-left: 8px;
            /* Avatar before name for RTL */
            object-fit: cover;
        }

        .navbar-common .dropdown-toggle::after {
            /* Bootstrap 5 dropdown arrow */
            margin-right: 0.255em;
            /* Spacing for RTL */
            margin-left: 0;
        }

        .main-content-common {
            min-height: calc(100vh - 70px - 56px);
            padding-top: 1rem;
            padding-bottom: 1rem;
        }

        .footer-common {
            background-color: #2c3e50;
            color: #bdc3c7;
            padding: 1rem 0;
            text-align: center;
            font-size: 0.9rem;
        }

        /* Scroll to top button */
        #scrollToTopBtn {
            position: fixed;
            bottom: 20px;
            left: 20px;
            /* For RTL, you might prefer right: 20px; left: auto; */
            z-index: 99;
            border: none;
            outline: none;
            background-color: #f39c12;
            color: white;
            cursor: pointer;
            padding: 10px 15px;
            border-radius: 50%;
            font-size: 18px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
            transition: opacity 0.3s, visibility 0.3s;
        }

        #scrollToTopBtn.hidden {
            opacity: 0;
            visibility: hidden;
        }

        #scrollToTopBtn:hover {
            background-color: #e67e22;
        }
    </style>
    <?php
    if (isset($extra_css) && is_array($extra_css)) {
        foreach ($extra_css as $css_file) {
            echo '<link rel="stylesheet" href="' . escapeHtml($css_file) . '">';
        }
    }
    ?>
</head>

<body>

    <nav class="navbar navbar-expand-lg navbar-common fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="/select_project.php">
                <img src="/assets/images/favicon-32x32.png" alt="آیکن سایت">
                مدیریت مرکزی
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavCommon" aria-controls="navbarNavCommon" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon" style="filter: invert(1) brightness(2);"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNavCommon">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <?php if (isLoggedIn()): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == 'select_project.php') echo 'active'; ?>" href="/select_project.php">
                                <i class="fas fa-th-large me-1"></i> انتخاب پروژه
                            </a>
                        </li>
                        <?php if (isAdmin()): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == 'admin.php') echo 'active'; ?>" href="/admin.php" style="color: #f39c12; font-weight: bold;">
                                    <i class="fas fa-users-cog me-1"></i> مدیریت کاربران
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <?php if (isLoggedIn()): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="navbarDropdownUserCommon" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <img src="<?= escapeHtml($avatarPathCommon) ?>" alt="آواتار" class="user-avatar-common">
                                <?= escapeHtml($_SESSION['first_name_header'] ?? $_SESSION['username'] ?? 'کاربر') ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end" aria-labelledby="navbarDropdownUserCommon">
                                <li><a class="dropdown-item" href="/profile.php"><i class="fas fa-user-edit me-2"></i> پروفایل من</a></li>
                                <?php if (isset($_SESSION['current_project_base_path'])): ?>
                                    <li><a class="dropdown-item" href="<?= escapeHtml(rtrim($_SESSION['current_project_base_path'], '/') . '/admin_panel_search.php') ?>"><i class="fas fa-arrow-left me-2"></i> بازگشت به پروژه</a></li>
                                <?php endif; ?>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item" href="/logout.php"><i class="fas fa-sign-out-alt me-2"></i> خروج از سیستم</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <?php if (basename($_SERVER['PHP_SELF']) != 'login.php'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="/login.php"><i class="fas fa-sign-in-alt me-1"></i> ورود</a>
                            </li>
                        <?php endif; ?>
                        <?php if (basename($_SERVER['PHP_SELF']) != 'registration.php'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="/registration.php"><i class="fas fa-user-plus me-1"></i> ثبت نام</a>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container main-content-common">