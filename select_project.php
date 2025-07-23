<?php
// public_html/select_project.php
require_once __DIR__ . '/../sercon/bootstrap.php'; // Use the new bootstrap

secureSession(); // Initializes session and applies security measures

// Redirect to login if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$available_projects = [];

/**
 * Sets project-specific variables in the session.
 */
function set_project_session_vars($project_data)
{
    $_SESSION['current_project_id'] = $project_data['project_id'];
    $_SESSION['current_project_name'] = $project_data['project_name'];
    $_SESSION['current_project_code'] = $project_data['project_code'];
    $_SESSION['current_project_config_key'] = $project_data['config_key'];
    $_SESSION['current_project_ro_config_key'] = $project_data['ro_config_key'];
    $_SESSION['current_project_base_path'] = $project_data['base_path']; // e.g., /Fereshteh
}

/**
 * Determines the redirect path based on user role and project base path.
 */
function get_role_based_redirect_url($role, $project_base_path, $project_code)
{
    $target_page = 'dashboard.php'; // Default page if no specific role match

    switch ($role) {
        case 'guest':
            $target_page = 'messages.php';
            break;
        case 'admin':
        case 'superuser':
        case 'supervisor':
        case 'user':
        case 'cnc_operator':
        case 'planner':
            if ($project_code === 'GHM') {
                $target_page = 'index.php';
            } else {
                $target_page = 'admin_panel_search.php';
            }
            break;
        case 'cat': // پیمانکار آتیه نما
        case 'car': // پیمانکار آرانسج
        case 'coa': // پیمانکار عمران آذرستان
        case 'crs': // پیمانکار شرکت ساختمانی رس
            $target_page = 'contractor_batch_update.php'; // Adjust to your contractor page
            break;
        default:
            $target_page = 'index.php'; // Fallback page
            break;
    }

    return rtrim($project_base_path, '/') . '/' . ltrim($target_page, '/');
}


try {
    $pdo_common = getCommonDBConnection();

    // 1. Fetch user's default_project_id AND all projects they have access to
    $stmt_user_data = $pdo_common->prepare("
        SELECT u.default_project_id,
               p.project_id, p.project_name, p.project_code, p.config_key, p.ro_config_key, p.base_path
        FROM users u
        LEFT JOIN user_projects up_default ON u.id = up_default.user_id AND u.default_project_id = up_default.project_id
        LEFT JOIN projects p ON u.default_project_id = p.project_id AND p.is_active = TRUE
        WHERE u.id = ?
    ");
    $stmt_user_data->execute([$user_id]);
    $user_default_project_data = $stmt_user_data->fetch();

    // Fetch all accessible projects for the selection form
    $stmt_accessible = $pdo_common->prepare("
        SELECT p.project_id, p.project_name, p.project_code, p.config_key, p.ro_config_key, p.base_path
        FROM projects p
        JOIN user_projects up ON p.project_id = up.project_id
        WHERE up.user_id = ? AND p.is_active = TRUE
        ORDER BY p.project_name
    ");
    $stmt_accessible->execute([$user_id]);
    $available_projects = $stmt_accessible->fetchAll();

    // If no projects are available at all, inform the user.
    if (empty($available_projects)) {
        $error = "شما در حال حاضر به هیچ پروژه‌ای دسترسی ندارید. لطفاً با مدیر سیستم تماس بگیرید.";
        // Display this error and then perhaps offer only logout.
    }


    // 2. Handle POST request (user explicitly selected a project)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_project_id']) && !empty($available_projects)) {
        $selected_project_id = filter_input(INPUT_POST, 'select_project_id', FILTER_VALIDATE_INT);
        $set_as_default = isset($_POST['set_as_default']);
        $project_to_set = null;

        foreach ($available_projects as $proj) {
            if ($proj['project_id'] === $selected_project_id) {
                $project_to_set = $proj;
                break;
            }
        }

        if ($project_to_set) {
            set_project_session_vars($project_to_set);

            if ($set_as_default) {
                $update_default_stmt = $pdo_common->prepare("UPDATE users SET default_project_id = ? WHERE id = ?");
                $update_default_stmt->execute([$project_to_set['project_id'], $user_id]);
                $_SESSION['user_default_project_id'] = $project_to_set['project_id']; // Update session immediately
            }

            log_activity($_SESSION['user_id'], $_SESSION['username'], 'select_project', "Selected Project: {$project_to_set['project_name']}", $project_to_set['project_id']);
            $redirect_url = get_role_based_redirect_url($_SESSION['role'], $project_to_set['base_path'], $project_to_set['project_code']);
            header("Location: " . $redirect_url);
            exit();
            $error = "پروژه انتخاب شده نامعتبر است.";
        }
    }
    // 3. Handle GET request (check for default project if no POST)
    // Only proceed if it's not a POST (to avoid re-evaluating default after a failed POST selection)
    elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $user_default_project_data && $user_default_project_data['project_id'] !== null && !empty($available_projects)) {
        // Check if the default project is in the list of accessible projects
        $default_is_accessible = false;
        foreach ($available_projects as $ap) {
            if ($ap['project_id'] == $user_default_project_data['project_id']) {
                $default_is_accessible = true;
                break;
            }
        }

        if ($default_is_accessible) {
            set_project_session_vars($user_default_project_data); // This contains all necessary fields from the JOIN
            log_activity($_SESSION['user_id'], $_SESSION['username'], 'auto_select_project', "Default Project: {$user_default_project_data['project_name']}", $user_default_project_data['project_id']);
            $redirect_url = get_role_based_redirect_url($_SESSION['role'], $user_default_project_data['base_path'], $user_default_project_data['project_code']);
            header("Location: " . $redirect_url);
            exit();
            // User's default project is no longer accessible or inactive, clear it
            if ($user_default_project_data['default_project_id'] !== null) {
                $clear_default_stmt = $pdo_common->prepare("UPDATE users SET default_project_id = NULL WHERE id = ?");
                $clear_default_stmt->execute([$user_id]);
                $_SESSION['user_default_project_id'] = null; // Update session
            }
            // Proceed to show selection form
        }
    }
} catch (PDOException $e) {
    logError("Database error in select_project.php: " . $e->getMessage());
    $error = "خطا در بارگذاری اطلاعات پروژه.";
} catch (Exception $e) {
    logError("General error in select_project.php: " . $e->getMessage());
    $error = "یک خطای سیستمی رخ داده است.";
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>انتخاب پروژه</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="/assets/css/select_project_styles.css"> <!-- Create this file for specific styles -->
    <style>
        body {
            background-color: #f4f7f6;
            font-family: 'Vazir', sans-serif;
        }

        .project-selection-container {
            max-width: 600px;
            margin: 5rem auto;
            padding: 2rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .project-button {
            display: block;
            width: 100%;
            margin-bottom: 1rem;
            padding: 1rem;
            font-size: 1.2rem;
        }

        .welcome-message {
            margin-bottom: 2rem;
            font-size: 1.1rem;
            color: #555;
        }

        .form-check-input {
            margin-left: 0.5rem;
        }

        /* Adjust checkbox alignment for RTL */
        .form-check-label {
            margin-right: 0.5rem;
        }

        .logout-profile-links {
            margin-top: 2rem;
            border-top: 1px solid #eee;
            padding-top: 1rem;
        }
    </style>
</head>

<body>
    <div class="project-selection-container">
        <h1>انتخاب پروژه</h1>
        <p class="welcome-message">
            سلام <?php echo escapeHtml($_SESSION['first_name'] ?? $_SESSION['username']); ?>،
            <?php if (!empty($available_projects)): ?>
                لطفاً پروژه‌ای که می‌خواهید وارد شوید را انتخاب کنید.
            <?php endif; ?>
        </p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= escapeHtml($error) ?></div>
        <?php endif; ?>

        <?php if (empty($available_projects) && !$error): ?>
            <!-- This case is handled by the $error message now, but can be more specific if needed -->
            <div class="alert alert-warning">شما در حال حاضر به هیچ پروژه‌ای دسترسی ندارید. لطفاً با مدیر سیستم تماس بگیرید.</div>
        <?php elseif (!empty($available_projects)): ?>
            <form method="POST" action="select_project.php">
                <?php foreach ($available_projects as $project): ?>
                    <button type="submit" name="select_project_id" value="<?= $project['project_id'] ?>" class="btn btn-primary project-button">
                        <?= escapeHtml($project['project_name']) ?>
                    </button>
                <?php endforeach; ?>
                <hr>
                <div class="form-check mt-3 mb-3">
                    <input class="form-check-input" type="checkbox" name="set_as_default" id="set_as_default" value="1">
                    <label class="form-check-label" for="set_as_default">
                        این پروژه را به عنوان پیش‌فرض من انتخاب کن
                    </label>
                </div>
                <small class="form-text text-muted">با انتخاب این گزینه، در ورودهای بعدی به طور خودکار وارد این پروژه خواهید شد.</small>
            </form>
        <?php endif; ?>

        <div class="logout-profile-links">
            <a href="profile.php" class="btn btn-outline-secondary btn-sm">ویرایش پروفایل</a>
            <a href="logout.php" class="btn btn-secondary btn-sm">خروج از سیستم</a>
        </div>
    </div>
    <script src="/assets/js/bootstrap.bundle.min.js"></script>
</body>

</html>