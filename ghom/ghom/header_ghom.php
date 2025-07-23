<?php
// public_html/Fereshteh/header.php
// --- Bootstrap and Session ---
if (!defined('APP_ROOT')) {
    require_once __DIR__ . '/../sercon/bootstrap.php';
    secureSession();
}

// --- Redirect if not logged in or project context not Fereshteh ---
if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}
$currentProjectConfigKeyInSession = $_SESSION['current_project_config_key'] ?? null;
if ($currentProjectConfigKeyInSession !== 'ghom') { // Hardcoded for this Fereshteh header
    logError("Arad header loaded with incorrect project context. Session: " . $currentProjectConfigKeyInSession);
    header('Location: /select_project.php?msg=project_mismatch_header');
    exit();
}
// --- End Redirects ---


$pdo_common_header = null; // Initialize
try {
    $pdo_common_header = getCommonDBConnection();
} catch (Exception $e) {
    logError("Critical: Common DB connection failed in Fereshteh header: " . $e->getMessage());
}

// --- Update Last Activity (uses alumglas_hpc_common.users) ---
if ($pdo_common_header && isset($_SESSION['user_id'])) {
    try {
        // Exclude user with ID 1 (if this is a special admin/system account)
        if ($_SESSION['user_id'] != 1) {
            $stmt_update_activity = $pdo_common_header->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
            $stmt_update_activity->execute([$_SESSION['user_id']]);
        }
    } catch (PDOException $e) {
        logError("Database error in Fereshteh header (updating last_activity): " . $e->getMessage());
    }
}
// --- End Update Last Activity ---


// --- Unread Messages Count (uses alumglas_hpc_common.messages) ---
$totalUnreadCount = 0;
if ($pdo_common_header && isset($_SESSION['user_id'])) {
    $currentUserIdHeader = $_SESSION['user_id'];
    try {
        $stmt_total_unread = $pdo_common_header->prepare("
            SELECT COUNT(*) as total_unread_count
            FROM messages
            WHERE receiver_id = :user_id AND is_read = 0 AND is_deleted = 0
        ");
        $stmt_total_unread->execute([':user_id' => $currentUserIdHeader]);
        $result_unread = $stmt_total_unread->fetch(PDO::FETCH_ASSOC);
        if ($result_unread) {
            $totalUnreadCount = (int) $result_unread['total_unread_count'];
        }
    } catch (PDOException $e) {
        logError("Error fetching total unread count in Fereshteh header: " . $e->getMessage());
    }
}
// --- End Unread Messages ---


$pageTitle = isset($pageTitle) ? $pageTitle : 'پروژه بیمارستان هزار تخت خوابی قم'; // Default title for Fereshteh
$current_page_filename = basename($_SERVER['PHP_SELF']); // Get the current page filename

// --- Role Text (from session) ---
$role = $_SESSION['role'] ?? '';
$roleText = ''; // Initialize
// Your existing switch statement for $roleText...
switch ($role) {
    case 'admin':
        $roleText = 'مشاور';
        break;
    case 'supervisor':
        $roleText = 'سرپرست';
        break;
    case 'planner':
        $roleText = 'طراح';
        break;
    case 'cnc_operator':
        $roleText = 'اپراتور CNC';
        break;
    case 'superuser':
        $roleText = 'سوپر یوزر';
        break;
    case 'receiver':
        $roleText = 'نصاب';
        break;
    case 'user':
        $roleText = 'کارفرما';
        break;
    case 'guest':
        $roleText = 'مهمان';
        break;
    case 'cat':
        $roleText = 'پیمانکار آتیه نما';
        break;
    case 'car':
        $roleText = 'پیمانکار آرانسج';
        break;
    case 'coa':
        $roleText = 'پیمانکار عمران آذرستان';
        break;
    case 'crs':
        $roleText = 'پیمانکار شرکت ساختمانی رس';
        break;
}

// --- End Role Text ---


// --- Online Users (uses alumglas_hpc_common.users) ---
$onlineUsers = [];
if ($pdo_common_header && isset($_SESSION['user_id'])) {
    $onlineTimeoutMinutes = 5;
    try {
        $stmt_online = $pdo_common_header->prepare("
            SELECT id, first_name, last_name
            FROM users
            WHERE last_activity >= NOW() - INTERVAL :timeout MINUTE
              AND id != :current_user_id
            ORDER BY first_name, last_name
        ");
        $stmt_online->bindParam(':timeout', $onlineTimeoutMinutes, PDO::PARAM_INT);
        $stmt_online->bindParam(':current_user_id', $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt_online->execute();
        $onlineUsers = $stmt_online->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logError("Database error in Fereshteh header (fetching online users): " . $e->getMessage());
    }
}
// --- End Fetch Online Users ---


// --- User Avatar Path (uses alumglas_hpc_common.users) ---
$avatarPath = '/assets/images/default-avatar.jpg'; // Default, ensure web accessible path
if ($pdo_common_header && isset($_SESSION['user_id'])) {
    try {
        $stmt_avatar = $pdo_common_header->prepare("SELECT avatar_path FROM users WHERE id = ?");
        $stmt_avatar->execute([$_SESSION['user_id']]);
        $user_avatar_data = $stmt_avatar->fetch(PDO::FETCH_ASSOC);

        $potentialAvatarWebPath = $user_avatar_data['avatar_path'] ?? null;
        if ($potentialAvatarWebPath && fileExistsAndReadable(PUBLIC_HTML_ROOT . $potentialAvatarWebPath)) {
            // Ensure $potentialAvatarWebPath starts with '/' if it's a root-relative web path
            $avatarPath = escapeHtml((strpos($potentialAvatarWebPath, '/') === 0 ? '' : '/') . ltrim($potentialAvatarWebPath, '/'));
        }
    } catch (PDOException $e) {
        logError("Database error in ghom header (fetching avatar path): " . $e->getMessage());
    }
}
// --- End User Avatar Path ---
// --- NEW: Fetch ALL projects the user has access to ---
$all_user_accessible_projects = [];
if ($pdo_common_header && isset($_SESSION['user_id'])) {
    try {
        $stmt_all_projects = $pdo_common_header->prepare(
            "SELECT p.project_id, p.project_name, p.project_code, p.base_path, p.config_key, p.ro_config_key
    FROM projects p
    JOIN user_projects up ON p.project_id = up.project_id
    WHERE up.user_id = ? AND p.is_active = TRUE
    ORDER BY p.project_name"
        );
        $stmt_all_projects->execute([$_SESSION['user_id']]);
        $all_user_accessible_projects = $stmt_all_projects->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logError("Error fetching all accessible projects in ghom header: " . $e->getMessage());
    }
}
// --- End Fetch ALL projects ---
// Helper function to check access (relies on $role from session)
// This could also be moved to bootstrap.php if used globally
if (!function_exists('hasAccess')) {
    function hasAccess($requiredRoles)
    {
        $current_user_role = $_SESSION['role'] ?? '';
        return in_array($current_user_role, (array)$requiredRoles);
    }
}

// Function to check if a link is active
if (!function_exists('getActiveClass')) {
    function getActiveClass($link_filename, $current_filename)
    {
        return ($link_filename == $current_filename) ? ' active' : '';
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HPC Factory - <?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/assets/images/favicon-96x96.png">
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
    <link rel="manifest" href="/assets/images/site.webmanifest">
    
    <!-- Material Design CSS -->
    <link href="/ghom/assets/css/material-design.css" rel="stylesheet">
    
    <!-- Additional CSS Files -->
    <link href="/assets/css/font-face.css" rel="stylesheet">
    <link href="/assets/css/persian-datepicker.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="/assets/css/sweetalert2.min.css" rel="stylesheet">
    <link href="/assets/css/datatables.min.css" rel="stylesheet">

    <?php
    // Add any additional styles specific to a page.
    if (isset($styles)) {
        echo "<style>\n$styles\n</style>";
    }
    ?>

    <!-- JavaScript Files -->
    <script src="/assets/js/main.min.js"></script>
    <script src="/assets/js/jquery-3.5.1.slim.min.js"></script>
    <script src="/assets/js/popper.min.js"></script>
    <script src="/assets/js/bootstrap.min.js"></script>
    <script type="text/javascript" charset="utf8" src="/assets/js/datatables.js"></script>
    <script type="text/javascript" charset="utf8" src="/assets/js/datatables.min.js"></script>
    <script src="/assets/js/persian-date.min.js"></script>
    <script src="/assets/js/persian-datepicker.min.js"></script>
    <script src="/assets/js/mobile-detect.min.js"></script>
    <script src="/ghom/assets/js/material-design.js"></script>
</head>

<body>
    <div class="main-container-flex">
        <!-- Material Design App Bar -->
        <header class="md-app-bar">
            <div class="md-app-bar-content">
                <div class="site-logo-container" style="display: flex; align-items: center; gap: 1rem;">
                    <a href="/">
                        <img src="/assets/images/alumglass-farsi-logo-H40.png" alt="HPC Factory Logo" style="height: 40px; width: auto;">
                    </a>
                    <h1 class="md-app-bar-title" style="display: flex; align-items: center; gap: 0.5rem; margin: 0;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19.5 13h-1.8v2.5h-1.5V13h-1.8v2.5h-1.5V13h-1.8v2.5h-1.5V13H8.1v2.5H6.6V13H4.8v8.5h14.7V13zm-6 5h-1.5v-2h1.5v2zm7.5 2h-16V11.5h1.5v.8h1.5v-.8h1.8v.8h1.5v-.8h1.8v.8h1.5v-.8h1.8v.8h1.5v-.8H18v.8h1.5V20zM21 8.9c-.1-.3-.4-.5-.7-.5H3.8c-.3 0-.6.2-.7.5l-1.9 5.3V21h20v-6.8L21 8.9zm-1 10.6H4v-4.5l.9-2.6h14.2l.9 2.6v4.5z" />
                        </svg>
                        <?php echo htmlspecialchars($pageTitle); ?>
                    </h1>
                </div>

                <div class="md-app-bar-actions">
                    <!-- User Profile -->
                    <a href="/../profile.php" style="display: flex; align-items: center; gap: 0.5rem; color: inherit; text-decoration: none; padding: 0.5rem; border-radius: 8px; transition: background-color 0.3s;">
                        <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Profile Picture" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                        <div style="text-align: right;">
                            <div style="font-weight: 500; font-size: 0.9rem;">
                                <?php echo htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')); ?>
                            </div>
                            <div style="font-size: 0.75rem; opacity: 0.8;">
                                <?php echo $roleText; ?>
                            </div>
                        </div>
                    </a>

                    <!-- Logout Button -->
                    <a href="/logout.php" class="md-button md-button--outlined" style="color: var(--md-on-primary); border-color: var(--md-on-primary);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M14.08,15.59L16.67,13H7V11H16.67L14.08,8.41L15.5,7L20.5,12L15.5,17L14.08,15.59M19,3A2,2 0 0,1 21,5V9.67L19,7.67V5H5V19H19V16.33L21,14.33V19A2,2 0 0,1 19,21H5C3.89,21 3,20.1 3,19V5C3,3.89 3.89,3 5,3H19Z" />
                        </svg>
                        خروج
                    </a>

                    <!-- Mobile Menu Toggle -->
                    <button class="md-drawer-toggle md-button md-button--text" style="display: none; color: var(--md-on-primary);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" />
                        </svg>
                    </button>
                </div>
            </div>
        </header>

        <!-- Material Design Navigation -->
        <nav class="md-card" style="margin: 0; border-radius: 0; box-shadow: var(--md-elevation-1);">
            <div class="md-card-content" style="padding: var(--md-spacing-sm) var(--md-spacing-lg);">
                <div style="display: flex; flex-wrap: wrap; gap: var(--md-spacing-sm); align-items: center; max-width: 1200px; margin: 0 auto;">
                    <?php if (hasAccess(['admin', 'superuser', 'user', 'cat', 'car', 'coa', 'crs'])): ?>
                        <a href="index.php" class="md-button <?php echo getActiveClass('index.php', $current_page_filename) ? 'md-button--contained' : 'md-button--text'; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 12L12 3l9 9" />
                                <path d="M9 21V12h6v9" />
                            </svg>
                            خانه
                        </a>
                    <?php endif; ?>

                    <?php if (hasAccess(['admin', 'superuser', 'user', 'cat', 'car', 'coa', 'crs'])): ?>
                        <a href="contractor_batch_update.php" class="md-button <?php echo getActiveClass('contractor_batch_update.php', $current_page_filename) ? 'md-button--contained' : 'md-button--text'; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="3 7 12 2 21 7 12 12 3 7" />
                                <polyline points="3 12 12 17 21 12" />
                                <polyline points="3 17 12 22 21 17" />
                            </svg>
                            به‌روزرسانی پیمانکار
                        </a>
                    <?php endif; ?>

                    <?php if (hasAccess(['admin', 'superuser', 'user', 'cat', 'car', 'coa', 'crs'])): ?>
                        <a href="inspection_dashboard.php" class="md-button <?php echo getActiveClass('inspection_dashboard.php', $current_page_filename) ? 'md-button--contained' : 'md-button--text'; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="7" height="7" rx="1" ry="1" />
                                <rect x="14" y="3" width="7" height="7" rx="1" ry="1" />
                                <rect x="3" y="14" width="7" height="7" rx="1" ry="1" />
                                <rect x="14" y="14" width="7" height="7" rx="1" ry="1" />
                            </svg>
                            بازرسی‌ها
                        </a>
                    <?php endif; ?>

                    <?php if (hasAccess(['admin', 'superuser', 'user', 'cat', 'car', 'coa', 'crs'])): ?>
                        <a href="reports.php" class="md-button <?php echo getActiveClass('reports.php', $current_page_filename) ? 'md-button--contained' : 'md-button--text'; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14,2 14,8 20,8" />
                                <line x1="16" y1="13" x2="8" y2="13" />
                                <line x1="16" y1="17" x2="8" y2="17" />
                                <polyline points="10,9 9,9 8,9" />
                            </svg>
                            گزارشات
                        </a>
                    <?php endif; ?>

                    <?php if (hasAccess(['admin', 'superuser'])): ?>
                        <a href="checklist_manager.php" class="md-button <?php echo getActiveClass('checklist_manager.php', $current_page_filename) ? 'md-button--contained' : 'md-button--text'; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 6h11" />
                                <path d="M9 12h11" />
                                <path d="M9 18h11" />
                                <path d="M4 6l1.5 1.5L8 5" />
                                <path d="M4 12l1.5 1.5L8 11" />
                                <path d="M4 18l1.5 1.5L8 17" />
                            </svg>
                            مدیریت چک‌لیست‌ها
                        </a>
                    <?php endif; ?>

                    <?php if (hasAccess(['admin', 'superuser'])): ?>
                        <a href="workflow_manager.php" class="md-button <?php echo getActiveClass('workflow_manager.php', $current_page_filename) ? 'md-button--contained' : 'md-button--text'; ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                            </svg>
                            مدیریت گردش کار
                        </a>
                    <?php endif; ?>

                    <?php if (hasAccess(['admin', 'superuser', 'supervisor', 'planner', 'user', 'receiver', 'cnc_operator', 'guest'])): ?>
                        <a href="/messages.php" class="md-button <?php echo getActiveClass('messages.php', $current_page_filename) ? 'md-button--contained' : 'md-button--text'; ?>" style="position: relative;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V8l8 5 8-5v10zm-8-7L4 6h16l-8 5z" />
                            </svg>
                            پیام‌ها
                            <?php if ($totalUnreadCount > 0): ?>
                                <span class="md-status-chip md-status-chip--error" style="position: absolute; top: -8px; left: -8px; min-width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; padding: 0;">
                                    <?= $totalUnreadCount ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if (isAdmin()): ?>
                        <a href="/admin.php" class="md-button md-button--contained" style="background: var(--md-secondary); color: var(--md-on-secondary);">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 15.5A3.5 3.5 0 0 1 8.5 12A3.5 3.5 0 0 1 12 8.5a3.5 3.5 0 0 1 3.5 3.5 3.5 3.5 0 0 1-3.5 3.5m7.43-2.53c.04-.32.07-.64.07-.97c0-.33-.03-.66-.07-1l2.11-1.63c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.31-.61-.22l-2.49 1c-.52-.39-1.06-.73-1.69-.98l-.37-2.65A.506.506 0 0 0 14 2h-4c-.25 0-.46.18-.5.42l-.37 2.65c-.63.25-1.17.59-1.69.98l-2.49-1c-.22-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64L4.57 11c-.04.34-.07.67-.07 1c0 .33.03.65.07.97l-2.11 1.66c-.19.15-.25.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1.01c.52.4 1.06.74 1.69.99l.37 2.65c.04.24.25.42.5.42h4c.25 0 .46-.18.5-.42l.37-2.65c.63-.26 1.17-.59 1.69-.99l2.49 1.01c.22.08.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.66Z" />
                            </svg>
                            مدیریت مرکزی کاربران
                        </a>
                    <?php endif; ?>

                    <?php if (count($all_user_accessible_projects) > 1): ?>
                        <div class="md-select" style="margin-right: auto;">
                            <select class="md-select__field" onchange="switchProject(this.value)">
                                <option value="">تعویض پروژه</option>
                                <?php foreach ($all_user_accessible_projects as $project_nav_item): ?>
                                    <?php $is_current_project_link = (isset($_SESSION['current_project_id']) && $_SESSION['current_project_id'] == $project_nav_item['project_id']); ?>
                                    <option value="<?= $project_nav_item['project_id'] ?>" <?= $is_current_project_link ? 'selected disabled' : '' ?>>
                                        <?= escapeHtml($project_nav_item['project_name']) ?>
                                        <?= $is_current_project_link ? ' (فعلی)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

    <!-- Mobile Navigation Drawer -->
    <div class="md-navigation-drawer">
        <div class="md-navigation-drawer__header">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <h6 style="margin: 0;">منوی اصلی</h6>
                <button class="md-drawer-close md-button md-button--text">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" />
                    </svg>
                </button>
            </div>
        </div>
        <ul class="md-navigation-drawer__list">
            <!-- Mobile navigation items will be populated by JavaScript -->
        </ul>
    </div>

    <script>
        // Project switcher function
        function switchProject(projectId) {
            if (projectId) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '/project_switch_handler.php';
                
                const projectInput = document.createElement('input');
                projectInput.type = 'hidden';
                projectInput.name = 'switch_to_project_id';
                projectInput.value = projectId;
                
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = '<?= $_SESSION['csrf_token'] ?? '' ?>';
                
                form.appendChild(projectInput);
                form.appendChild(csrfInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Mobile menu handling
        document.addEventListener('DOMContentLoaded', function() {
            const drawerToggle = document.querySelector('.md-drawer-toggle');
            const drawer = document.querySelector('.md-navigation-drawer');
            const drawerClose = document.querySelector('.md-drawer-close');
            const overlay = document.querySelector('.md-drawer-overlay');

            // Show mobile menu button on small screens
            function updateMobileMenu() {
                if (window.innerWidth <= 768) {
                    drawerToggle.style.display = 'flex';
                    // Populate mobile navigation
                    populateMobileNav();
                } else {
                    drawerToggle.style.display = 'none';
                    drawer.classList.remove('open');
                }
            }

            function populateMobileNav() {
                const navList = document.querySelector('.md-navigation-drawer__list');
                const navButtons = document.querySelectorAll('nav .md-button');
                
                navList.innerHTML = '';
                navButtons.forEach(button => {
                    if (button.classList.contains('md-drawer-toggle')) return;
                    
                    const li = document.createElement('li');
                    const link = document.createElement('a');
                    link.href = button.href;
                    link.className = 'md-navigation-drawer__item';
                    link.innerHTML = button.innerHTML;
                    li.appendChild(link);
                    navList.appendChild(li);
                });
            }

            updateMobileMenu();
            window.addEventListener('resize', updateMobileMenu);
        });
    </script>

