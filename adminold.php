<?php
// public_html/admin.php
require_once __DIR__ . '/../sercon/bootstrap.php'; // Use the new bootstrap

// If jdf.php and functions.php are still separate and in public_html:
require_once __DIR__ . '/includes/jdf.php';
//require_once __DIR__ . '/includes/functions.php'; // Assuming format_jalali_date is here

secureSession(); // Initializes session and security

// Authorization: Ensure only admin/superuser can access
if (!isAdmin()) { // isAdmin() should be in bootstrap.php
    // If you have a specific "unauthorized" page for non-admins trying to access admin areas:
    // header('Location: /unauthorized.php');
    // Otherwise, redirect to login or a general error page:
    logError("Unauthorized access attempt to admin.php. User ID: " . ($_SESSION['user_id'] ?? 'Guest'));
    header('Location: /login.php?msg=unauthorized');
    exit();
}

$pageTitle = 'پنل مدیریت کاربران و پروژه‌ها'; // Updated title

require_once __DIR__ . '/header_common.php';


$message = '';
$error = '';
$pdo = null; // Initialize pdo variable

try {
    $pdo = getCommonDBConnection(); // Connect to hpc_common
} catch (Exception $e) { // Catch general Exception from DBManager
    logError("Database connection error in admin.php: " . $e->getMessage());
    // Displaying the error directly might be okay for an admin page during development,
    // but in production, a generic message is better.
    die("A critical database error occurred. Please check logs or contact support. Error: " . $e->getMessage());
}

// --- Handle User Actions (Activate, Deactivate, Change Role, Delete, etc.) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id_action'])) {
    $user_id_to_action = filter_var($_POST['user_id_action'], FILTER_VALIDATE_INT);

    if ($user_id_to_action === false) {
        $error = "شناسه کاربر نامعتبر است.";
    } else {
        $action = $_POST['action'];
        $current_admin_id = $_SESSION['user_id'];
        $current_admin_username = $_SESSION['username'];

        try {
            $pdo->beginTransaction(); // Start transaction for user actions

            $sql = "";
            $params = [];

            switch ($action) {
                case 'activate':
                case 'deactivate':
                case 'allow_guest_chat':
                case 'disallow_guest_chat':
                case 'make_admin':
                case 'make_supervisor':
                case 'make_user':
                case 'make_guest':
                case 'make_planner':
                case 'make_cnc_operator':
                case 'make_car':
                case 'make_cat':
                case 'make_coa':
                case 'make_crs':
                    // These actions are handled similarly
                    $field_to_update = '';
                    $value_to_set = null;
                    $success_message_key = $action;

                    if ($action === 'activate') {
                        $field_to_update = 'is_active';
                        $value_to_set = 1;
                        $params = [$value_to_set, $user_id_to_action];
                    } elseif ($action === 'deactivate') {
                        $field_to_update = 'is_active';
                        $value_to_set = 0;
                        $params = [$value_to_set, $user_id_to_action];
                    } elseif ($action === 'allow_guest_chat') {
                        $field_to_update = 'can_chat_with_guests';
                        $value_to_set = 1;
                        $params = [$value_to_set, $user_id_to_action];
                    } elseif ($action === 'disallow_guest_chat') {
                        $field_to_update = 'can_chat_with_guests';
                        $value_to_set = 0;
                        $params = [$value_to_set, $user_id_to_action];
                    } else { // Role changes
                        $field_to_update = 'role';
                        $value_to_set = str_replace('make_', '', $action); // e.g., 'admin', 'user'
                        $params = [$value_to_set, $user_id_to_action];
                    }
                    $sql = "UPDATE users SET {$field_to_update} = ? " . ($action === 'activate' ? ", activation_date = NOW()" : "") . " WHERE id = ?";

                    $message_map = [
                        'activate' => 'کاربر با موفقیت فعال شد.',
                        'deactivate' => 'کاربر با موفقیت غیرفعال شد.',
                        'allow_guest_chat' => 'اجازه چت مهمان فعال شد.',
                        'disallow_guest_chat' => 'اجازه چت مهمان غیرفعال شد.',
                        'make_admin' => 'کاربر به مدیر تبدیل شد.',
                        'make_supervisor' => 'کاربر به سرپرست تبدیل شد.',
                        'make_user' => 'کاربر به کاربر عادی تبدیل شد.',
                        'make_guest' => 'کاربر به مهمان تبدیل شد.',
                        'make_planner' => 'کاربر به طراح تبدیل شد.',
                        'make_cnc_operator' => 'کاربر به اپراتور CNC تبدیل شد.',
                        'make_cat' => 'کاربر به پیمانکار آتیه نما تبدیل شد.',
                        'make_car' => 'کاربر به پیمانکار آرانسج تبدیل شد.',
                        'make_coa' => 'کاربر به پیمانکار عمران آذرستان تبدیل شد.',
                        'make_crs' => 'کاربر به پیمانکار شرکت ساختمانی رس تبدیل شد.',
                        'make_superuser' => 'کاربر به سوپریوزر تبدیل شد.'
                    ];

                    $message = $message_map[$success_message_key] ?? 'عملیات انجام شد.';
                    break;

                case 'delete_user':
                    if ($current_admin_id == $user_id_to_action) {
                        $error = "شما نمی‌توانید حساب کاربری خود را حذف کنید.";
                        // No SQL needed if error, break will prevent execution
                        $sql = ""; // Ensure $sql is empty to prevent execution
                        break;
                    }

                    // Check if this is the last admin/superuser to prevent lockout
                    // (Assuming 'admin' and 'superuser' are privileged roles)
                    $checkPrivilegedStmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM users WHERE (role = 'admin' OR role = 'superuser') AND id != ?"
                    );
                    $checkPrivilegedStmt->execute([$user_id_to_action]);
                    $privilegedUserCount = $checkPrivilegedStmt->fetchColumn();

                    $checkUserRoleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                    $checkUserRoleStmt->execute([$user_id_to_action]);
                    $userRoleToDelete = $checkUserRoleStmt->fetchColumn();

                    if (($userRoleToDelete === 'admin' || $userRoleToDelete === 'superuser') && $privilegedUserCount === 0) {
                        $error = "این آخرین حساب مدیر/سوپریوزر است و نمی‌تواند حذف شود.";
                        $sql = ""; // Ensure $sql is empty
                        break;
                    }

                    // Proceed with deletion (all these tables are in hpc_common)
                    // 1. Delete from user_projects (project assignments)
                    $stmt_del_projects = $pdo->prepare("DELETE FROM user_projects WHERE user_id = ?");
                    $stmt_del_projects->execute([$user_id_to_action]);

                    // 2. Delete from activity_log (user's own activities)
                    // Note: Activities *about* this user by *other* admins might remain, which is usually fine.
                    $stmt_del_activity = $pdo->prepare("DELETE FROM activity_log WHERE user_id = ?");
                    $stmt_del_activity->execute([$user_id_to_action]);

                    // 3. Delete from messages (where user is sender or receiver)
                    $stmt_del_msg_sent = $pdo->prepare("DELETE FROM messages WHERE sender_id = ?");
                    $stmt_del_msg_sent->execute([$user_id_to_action]);
                    $stmt_del_msg_received = $pdo->prepare("DELETE FROM messages WHERE receiver_id = ?");
                    $stmt_del_msg_received->execute([$user_id_to_action]);

                    // 4. Delete from user_preferences
                    $stmt_del_prefs = $pdo->prepare("DELETE FROM user_preferences WHERE user_id = ?");
                    $stmt_del_prefs->execute([$user_id_to_action]);

                    // 5. Delete from user_print_settings
                    $stmt_del_print_settings = $pdo->prepare("DELETE FROM user_print_settings WHERE user_id = ?");
                    $stmt_del_print_settings->execute([$user_id_to_action]);

                    // 6. Delete login_attempts for this user (though IP-based, good to clear if directly tied)
                    // This might be less critical if login_attempts are purely IP-based and not user_id linked.
                    // If your login_attempts table *can* be linked to a user_id, add that logic here.
                    // For now, assuming it's IP based as per your login.php.

                    // 7. Finally, delete the user from the users table
                    // This $sql and $params will be used by the generic execution block later.
                    $sql = "DELETE FROM users WHERE id = ?";
                    $params = [$user_id_to_action];
                    $message = "کاربر و تمام داده‌های مرتبط با او (دسترسی پروژه‌ها، فعالیت‌ها، پیام‌ها، تنظیمات) با موفقیت حذف شدند.";
                    // The log_activity for 'delete_user_user' will be handled by the generic block.
                    break;

                case 'reset_password':
                    // No $sql variable needed here as we execute directly
                    $sql = ""; // Explicitly clear $sql to prevent generic execution block from re-running
                    try {
                        $newPassword = bin2hex(random_bytes(8)); // Generate a random 16-char hex password
                        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                        $stmt_reset = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                        $stmt_reset->execute([$passwordHash, $user_id_to_action]);

                        if ($stmt_reset->rowCount() > 0) {
                            $usernameStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                            $usernameStmt->execute([$user_id_to_action]);
                            $username_for_reset = $usernameStmt->fetchColumn();

                            $message = "رمز عبور برای کاربر '" . escapeHtml($username_for_reset) . "' بازنشانی شد. رمز عبور جدید: <strong>" . escapeHtml($newPassword) . "</strong>";
                            // Log this specific action
                            log_activity(
                                $current_admin_id,
                                $current_admin_username,
                                'reset_user_password', // More specific activity type
                                "Password reset for User ID: {$user_id_to_action} (Username: {$username_for_reset})"
                            );
                        } else {
                            $error = "خطا در بازنشانی رمز عبور. کاربر یافت نشد یا تغییری ایجاد نشد.";
                        }
                    } catch (Exception $e) { // Catch specific Exception for password generation
                        logError("Password reset generation error: " . $e->getMessage());
                        $error = "خطا در ایجاد رمز عبور جدید. لطفاً دوباره تلاش کنید.";
                    }
                    break;

                default:
                    logError("Invalid user action in admin.php: {$action} by User ID: {$current_admin_id}");
                    $error = "عملیات کاربری نامعتبر است.";
            }

            if (!empty($sql) && empty($error)) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if ($stmt->rowCount() > 0) {
                    // For delete_user, the message is already set. For others, this confirms success.
                    if (empty($message)) {
                        // This part might be redundant if messages are set within each case.
                        // $message = "عملیات با موفقیت روی کاربر انجام شد.";
                    }
                    // Log the generic action if not 'reset_password' (which logs itself)
                    // For 'delete_user', the message is specific, but logging 'delete_user_user' is fine.
                    log_activity(
                        $current_admin_id,
                        $current_admin_username,
                        $action . '_user', // e.g., activate_user, make_admin_user, delete_user_user
                        "Target User ID: {$user_id_to_action}"
                    );
                } elseif (empty($error) && empty($message)) {
                    // If no rows affected, and no specific error/message set, it implies no change was needed.
                    $error = "عملیات روی کاربر تاثیری نداشت (ممکن است وضعیت فعلی همین باشد یا کاربر یافت نشد).";
                }
            }

            // If any error occurred during the switch or execution, rollback.
            // Otherwise, commit.
            if (empty($error)) {
                $pdo->commit();
            } else {
                $pdo->rollBack(); // Rollback if there was any error set within the switch or by execution
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logError("DB error during user action '{$action}' in admin.php: " . $e->getMessage());
            $error = ($error ? $error . "<br>" : "") . "خطایی در پایگاه داده هنگام انجام عملیات کاربری رخ داد.";
        } catch (Exception $e) { // Catch general exceptions
            if ($pdo->inTransaction()) $pdo->rollBack();
            logError("General error during user action '{$action}' in admin.php: " . $e->getMessage());
            $error = ($error ? $error . "<br>" : "") . "یک خطای سیستمی هنگام انجام عملیات کاربری رخ داد.";
        }
    }
}

// --- Handle Project Assignment and Default Project ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_projects'], $_POST['user_id_projects'])) {
    $user_id_for_projects = filter_var($_POST['user_id_projects'], FILTER_VALIDATE_INT);
    $action_projects = $_POST['action_projects'];

    if ($user_id_for_projects === false) {
        $error = "شناسه کاربر برای مدیریت پروژه‌ها نامعتبر است.";
    } else {
        try {
            $pdo->beginTransaction();
            $current_admin_id = $_SESSION['user_id'];
            $current_admin_username = $_SESSION['username'];

            if ($action_projects === 'update_assignments') {
                // 1. Get the list of projects the admin *intends* to assign from the checkboxes.
                // These are the projects that *will be* assigned after this save.
                $intended_assigned_project_ids = [];
                if (isset($_POST['projects']) && is_array($_POST['projects'])) {
                    foreach ($_POST['projects'] as $p_id) {
                        $validated_p_id = filter_var($p_id, FILTER_VALIDATE_INT);
                        if ($validated_p_id) {
                            $intended_assigned_project_ids[] = $validated_p_id;
                        }
                    }
                }

                // 2. Get the project ID the admin *intends* to set as default.
                $intended_default_project_id = isset($_POST['default_project']) ? filter_var($_POST['default_project'], FILTER_VALIDATE_INT) : null;

                // 3. VALIDATE: The intended default project *must* be one of the intended assigned projects.
                if ($intended_default_project_id !== null && !in_array($intended_default_project_id, $intended_assigned_project_ids)) {
                    // If the chosen default is not in the list of projects being assigned in *this* POST request,
                    // then it cannot be the default.
                    $error = "پروژه پیش‌فرض انتخاب شده ('" . escapeHtml($all_projects_map[$intended_default_project_id] ?? 'N/A') . "') باید ابتدا در لیست پروژه‌های تخصیص یافته انتخاب (تیک زده) شود.";
                    // To be safe, nullify the default project if it's invalid, so it doesn't try to save an invalid state.
                    $intended_default_project_id = null;
                }

                // Only proceed if no validation error occurred regarding the default project selection
                if (empty($error)) {
                    // 4. Update user_projects table (delete old, insert new based on $intended_assigned_project_ids)
                    $stmt_delete_old = $pdo->prepare("DELETE FROM user_projects WHERE user_id = ?");
                    $stmt_delete_old->execute([$user_id_for_projects]);

                    if (!empty($intended_assigned_project_ids)) {
                        $stmt_insert_new = $pdo->prepare("INSERT INTO user_projects (user_id, project_id) VALUES (?, ?)");
                        foreach ($intended_assigned_project_ids as $project_id_to_assign) {
                            $stmt_insert_new->execute([$user_id_for_projects, $project_id_to_assign]);
                        }
                    }

                    // 5. Update default_project_id in users table with the (now validated) $intended_default_project_id
                    $stmt_update_default = $pdo->prepare("UPDATE users SET default_project_id = ? WHERE id = ?");
                    $stmt_update_default->execute([$intended_default_project_id, $user_id_for_projects]);

                    $message = "پروژه‌های کاربر و پروژه پیش‌فرض با موفقیت به‌روز شد.";
                    log_activity($current_admin_id, $current_admin_username, 'update_user_project_assignments', "For User ID: {$user_id_for_projects}. Assigned: " . implode(',', $intended_assigned_project_ids) . ". Default: " . ($intended_default_project_id ?? 'None'));
                }
            }
            // Only commit if there were no errors during the process
            if (empty($error)) {
                $pdo->commit();
            } else {
                $pdo->rollBack(); // Rollback if there was a validation error
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logError("DB error during project assignment in admin.php: " . $e->getMessage());
            $error = ($error ? $error . "<br>" : "") . "خطایی در پایگاه داده هنگام تخصیص پروژه‌ها رخ داد.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logError("General error during project assignment in admin.php: " . $e->getMessage());
            $error = ($error ? $error . "<br>" : "") . "یک خطای سیستمی هنگام تخصیص پروژه‌ها رخ داد.";
        }
    }
}


// --- Fetch Data for Display ---
$users_list = [];
$all_projects_map = []; // [project_id => project_name]
$user_project_assignments = []; // [user_id => [project_id1, project_id2...]]

try {
    // Get all users with their default project ID
    $stmt_users = $pdo->prepare("SELECT id, username, email, first_name, last_name, role, is_active, created_at, activation_date, avatar_path, can_chat_with_guests, default_project_id FROM users ORDER BY created_at DESC");
    $stmt_users->execute();
    $users_list = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

    // Get all active projects
    $stmt_projects = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE is_active = TRUE ORDER BY project_name");
    $stmt_projects->execute();
    $all_projects_map = $stmt_projects->fetchAll(PDO::FETCH_KEY_PAIR); // Fetches into [project_id => project_name]

    // Get all user-project assignments
    $stmt_assignments = $pdo->prepare("SELECT user_id, project_id FROM user_projects");
    $stmt_assignments->execute();
    $raw_assignments = $stmt_assignments->fetchAll(PDO::FETCH_ASSOC);
    foreach ($raw_assignments as $assignment) {
        $user_project_assignments[$assignment['user_id']][] = $assignment['project_id'];
    }
} catch (PDOException $e) {
    logError("Database error fetching data for admin.php display: " . $e->getMessage());
    $error = ($error ? $error . "<br>" : "") . "خطا در بازیابی اطلاعات کاربران یا پروژه‌ها.";
} catch (Exception $e) {
    logError("General error fetching data for admin.php display: " . $e->getMessage());
    $error = ($error ? $error . "<br>" : "") . "خطای سیستمی در بازیابی اطلاعات.";
}

// Helper function to translate roles (keep yours or move to bootstrap.php)
if (!function_exists('translate_role')) {
    function translate_role($role)
    {
        $roles = [
            'admin' => 'مدیر',
            'supervisor' => 'سرپرست',
            'user' => 'کاربر',
            'planner' => 'طراح',
            'cnc_operator' => 'اپراتور CNC',
            'superuser' => 'سوپریوزر',
            'guest' => 'مهمان',
            'cat' => 'پیمانکار آتیه نما',
            'car' => 'پیمانکار آرانسج',
            'coa' => 'پیمانکار عمران آذرستان',
            'crs' => 'پیمانکار شرکت ساختمانی رس'
        ];
        return $roles[$role] ?? $role;
    }
}
if (!function_exists('format_jalali_date')) {
    function format_jalali_date($date)
    { /* Placeholder if not in functions.php/bootstrap */
        return $date;
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title><?= escapeHtml($pageTitle) ?></title>
    <!-- Ensure asset paths are correct from web root -->
    <link rel="stylesheet" href="/assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="/assets/css/all.min.css"> <!-- Assuming Font Awesome -->
    <style>
        /* Base Styles */
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
        }

        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #34495e;
        }

        body {
            font-family: 'Vazir', sans-serif;
            background-color: #f8f9fa;
            color: #333;
        }

        .admin-container {
            padding: 20px;
            margin-top: 20px;
            margin-bottom: 20px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.05);
        }

        .admin-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .admin-title {
            color: var(--secondary-color);
            font-weight: bold;
            display: flex;
            align-items: center;
        }

        .admin-title i {
            margin-left: 10px;
            color: var(--primary-color);
        }

        /* Alert styling */
        .alert {
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border: none;
        }

        .alert-success {
            background-color: rgba(46, 204, 113, 0.15);
            color: #27ae60;
            border-right: 4px solid #27ae60;
        }

        .alert-danger {
            background-color: rgba(231, 76, 60, 0.15);
            color: #c0392b;
            border-right: 4px solid #c0392b;
        }

        /* Table styling */
        .users-table {
            width: 100%;
            overflow-x: auto;
            margin-bottom: 30px;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.03);
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background-color: var(--secondary-color);
            color: white;
            padding: 15px 10px;
            font-weight: 500;
            border: none;
            vertical-align: middle;
        }

        .table tbody tr:nth-of-type(odd) {
            background-color: rgba(236, 240, 241, 0.4);
        }

        .table tbody tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }

        .table td {
            padding: 15px 10px;
            vertical-align: middle;
        }

        /* Profile picture styling */
        .profile-pic-container {
            width: 50px;
            height: 50px;
            overflow: hidden;
            border-radius: 50%;
            position: relative;
            margin: 0 auto;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            background-color: #f1f1f1;
            border: 2px solid white;
        }

        .profile-pic1 {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Status badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-active {
            background-color: rgba(46, 204, 113, 0.15);
            color: #27ae60;
        }

        .status-inactive {
            background-color: rgba(231, 76, 60, 0.15);
            color: #c0392b;
        }

        /* Role pills */
        .role-pill {
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .role-admin {
            background-color: rgba(52, 152, 219, 0.15);
            color: #2980b9;
        }

        .role-superuser {
            background-color: rgba(155, 89, 182, 0.15);
            color: #8e44ad;
        }

        .role-supervisor {
            background-color: rgba(241, 196, 15, 0.15);
            color: #d35400;
        }

        .role-user {
            background-color: rgba(149, 165, 166, 0.15);
            color: #7f8c8d;
        }

        .role-planner {
            background-color: rgba(26, 188, 156, 0.15);
            color: #16a085;
        }

        .role-cnc {
            background-color: rgba(230, 126, 34, 0.15);
            color: #d35400;
        }

        .role-guest {
            background-color: rgba(119, 136, 153, 0.15);
            /* Example: LightSlateGray background */
            color: #778899;
            /* Example: SlateGray text */
        }

        /* Button styling */
        .btn {
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            margin: 2px;
            padding: 0.5rem 1rem;
            transition: all 0.2s ease;
        }

        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            padding: 0;
            margin: 0 3px;
        }

        .btn-info {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-info:hover {
            background-color: #2980b9;
            border-color: #2980b9;
        }

        .btn-success {
            background-color: var(--success-color);
            border-color: var (--success-color);
        }

        .btn-success:hover {
            background-color: #27ae60;
            border-color: #27ae60;
        }

        .btn-warning {
            background-color: var(--warning-color);
            border-color: var(--warning-color);
        }

        .btn-warning:hover {
            background-color: #d35400;
            border-color: #d35400;
        }

        .btn-danger {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }

        .btn-danger:hover {
            background-color: #c0392b;
            border-color: #c0392b;
        }

        /* Dropdown styling */
        .role-select {
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
            border-radius: 6px;
            border: 1px solid #ddd;
            margin: 0 3px;
        }

        /* Actions container */
        .actions-container {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        /* Responsive fixes */
        @media (max-width: 992px) {
            .table-responsive {
                border: none;
            }

            .admin-container {
                padding: 15px;
            }

            .table td,
            .table th {
                padding: 10px 5px;
            }

            .btn-sm {
                padding: 0.3rem 0.5rem;
                font-size: 0.75rem;
            }

            .role-select {
                max-width: 110px;
                padding: 0.3rem 0.5rem;
                font-size: 0.75rem;
            }

            .actions-container {
                flex-direction: column;
                align-items: stretch;
            }

            .action-form {
                margin-bottom: 5px;
            }
        }

        /* For very small mobile screens */
        @media (max-width: 576px) {
            .table-responsive {
                display: block;
                width: 100%;
                overflow-x: auto;
            }

            .admin-title {
                font-size: 1.5rem;
            }

            .card-body {
                padding: 0.5rem;
            }

            .profile-pic-container {
                width: 40px;
                height: 40px;
            }

            .users-table {
                font-size: 0.8rem;
            }
        }

        /* Create a mobile card view for small screens */
        @media (max-width: 768px) {
            .desktop-table {
                display: none;
            }

            .mobile-cards {
                display: block;
            }

            .user-card {
                background-color: white;
                border-radius: 10px;
                padding: 15px;
                margin-bottom: 15px;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.05);
                position: relative;
            }

            .user-card-header {
                display: flex;
                align-items: center;
                margin-bottom: 15px;
            }

            .user-card-info {
                margin-right: 15px;
            }

            .user-card-name {
                font-weight: bold;
                font-size: 1.1rem;
                margin: 0;
            }

            .user-card-username {
                color: #666;
                font-size: 0.9rem;
                margin: 0;
            }

            .user-card-details {
                margin-bottom: 15px;
            }

            .user-card-details p {
                margin-bottom: 8px;
                display: flex;
                justify-content: space-between;
            }

            .user-card-details span {
                color: #666;
            }

            .user-card-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
            }
        }

        @media (min-width: 800px) {
            .desktop-table {
                display: block;
            }

            .mobile-cards {
                display: none;
            }
        }

        /* NEW Styles for Project Assignment section within the table/card */
        .project-assignments-section {
            margin-top: 10px;
            padding: 10px;
            background-color: #f9f9f9;
            border: 1px solid #eee;
            border-radius: 4px;
        }

        .project-assignments-section h6 {
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: #555;
        }

        .project-checkbox-group .form-check {
            margin-bottom: 5px;
        }

        .project-checkbox-group .form-check-label {
            font-size: 0.85rem;
        }

        .default-project-select {
            font-size: 0.85rem;
            padding: 0.25rem 0.5rem;
            max-width: 200px;
            /* Adjust as needed */
        }
    </style>
</head>

<body>

    <div class="container admin-container">
        <div class="admin-header">
            <h1 class="admin-title"><i class="fas fa-users-cog"></i> <?= escapeHtml($pageTitle) ?></h1>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i> <?= escapeHtml($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i> <?= nl2br(escapeHtml($error)) ?></div>
        <?php endif; ?>

        <!-- DESKTOP TABLE VIEW -->
        <div class="desktop-table">
            <div class="users-table table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><i class="fas fa-image me-1"></i> تصویر</th>
                            <th>نام کاربری</th>
                            <th>نام کامل</th>
                            <th>ایمیل</th>
                            <th>نقش</th>
                            <th>وضعیت</th>
                            <th>پروژه‌ها و پیش‌فرض</th>
                            <th>عملیات کاربری</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users_list) && !$error): ?>
                            <tr>
                                <td colspan="8" class="text-center">هیچ کاربری یافت نشد.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($users_list as $user_item):
                            $uid = $user_item['id'];
                            $assigned_project_ids_for_user = $user_project_assignments[$uid] ?? [];
                        ?>
                            <tr>
                                <td>
                                    <div class="profile-pic-container">
                                        <img src="<?= escapeHtml(!empty($user_item['avatar_path']) && fileExistsAndReadable(PUBLIC_HTML_ROOT . $user_item['avatar_path']) ? $user_item['avatar_path'] : '/assets/images/default-avatar.jpg') ?>"
                                            alt="تصویر پروفایل" class="profile-pic1">
                                    </div>
                                </td>
                                <td><?= escapeHtml($user_item['username']) ?></td>
                                <td><?= escapeHtml($user_item['first_name'] . ' ' . $user_item['last_name']) ?></td>
                                <td><?= escapeHtml($user_item['email']) ?></td>
                                <td>
                                    <?php /* Your existing role pill logic */
                                    $roleClass = 'role-user'; /* ... your switch ... */ ?>
                                    <span class="role-pill <?= $roleClass ?>"><?= escapeHtml(translate_role($user_item['role'])) ?></span>
                                </td>
                                <td>
                                    <?php if ($user_item['is_active']): ?>
                                        <span class="status-badge status-active"><i class="fas fa-check-circle me-1"></i> فعال</span>
                                    <?php else: ?>
                                        <span class="status-badge status-inactive"><i class="fas fa-times-circle me-1"></i> غیرفعال</span>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width: 300px;">
                                    <form method="post" action="admin.php" class="project-assignment-form">
                                        <input type="hidden" name="user_id_projects" value="<?= $uid ?>">
                                        <input type="hidden" name="action_projects" value="update_assignments">
                                        <h6>پروژه‌های تخصیص یافته:</h6>
                                        <div class="project-checkbox-group mb-2">
                                            <?php if (empty($all_projects_map)): ?>
                                                <small>هیچ پروژه‌ای برای تخصیص تعریف نشده است.</small>
                                            <?php endif; ?>
                                            <?php foreach ($all_projects_map as $project_id => $project_name): ?>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="projects[]"
                                                        value="<?= $project_id ?>" id="proj_<?= $uid ?>_<?= $project_id ?>"
                                                        <?= in_array($project_id, $assigned_project_ids_for_user) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="proj_<?= $uid ?>_<?= $project_id ?>"><?= escapeHtml($project_name) ?></label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <h6>پروژه پیش‌فرض:</h6>
                                        <select name="default_project" class="form-select form-select-sm default-project-select mb-2">
                                            <option value="">-- بدون پیش‌فرض --</option>
                                            <?php

                                            foreach ($all_projects_map as $project_id_map => $project_name_map):

                                                $is_currently_assigned = in_array($project_id_map, $assigned_project_ids_for_user);
                                            ?>
                                                <option value="<?= $project_id_map ?>"
                                                    <?= ($user_item['default_project_id'] == $project_id_map) ? 'selected' : '' ?>>
                                                    <?= escapeHtml($project_name_map) ?>
                                                    <?= !$is_currently_assigned ? ' (تخصیص نیافته)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-primary">ذخیره پروژه‌ها</button>
                                    </form>
                                </td>
                                <td style="min-width: 250px;">
                                    <div class="actions-container">
                                        <a href="profile.php?id=<?= $uid ?>" class="btn btn-info btn-sm" title="مشاهده/ویرایش پروفایل"><i class="fas fa-user-edit"></i></a>
                                        <form method="post" action="admin.php" class="d-inline action-form">
                                            <input type="hidden" name="user_id_action" value="<?= $uid ?>">
                                            <?php if ($user_item['is_active']): ?>
                                                <button type="submit" name="action" value="deactivate" class="btn btn-warning btn-sm" title="غیرفعال کردن کاربر"><i class="fas fa-user-slash"></i></button>
                                            <?php else: ?>
                                                <button type="submit" name="action" value="activate" class="btn btn-success btn-sm" title="فعال کردن کاربر"><i class="fas fa-user-check"></i></button>
                                            <?php endif; ?>
                                        </form>
                                        <!-- Role Change Dropdown -->
                                        <form method="post" action="admin.php" class="d-inline action-form">
                                            <input type="hidden" name="user_id_action" value="<?= $uid ?>">
                                            <select name="action" class="form-select form-select-sm role-select d-inline-block w-auto" onchange="this.form.submit()" title="تغییر نقش کاربر">
                                                <option value="">نقش: <?= escapeHtml(translate_role($user_item['role'])) ?></option>
                                                <?php
                                                $roles_available = [
                                                    'admin' => 'مدیر',
                                                    'supervisor' => 'سرپرست',
                                                    'user' => 'کاربر',
                                                    'planner' => 'طراح',
                                                    'cnc_operator' => 'اپراتور CNC',
                                                    'superuser' => 'سوپریوزر',
                                                    'guest' => 'مهمان',
                                                    'cat' => 'پیمانکار آتیه نما',
                                                    'car' => 'پیمانکار آرانسج',
                                                    'coa' => 'پیمانکار عمران آذرستان',
                                                    'crs' => 'پیمانکار شرکت ساختمانی رس'
                                                ];
                                                if ($_SESSION['role'] === 'superuser') $roles_available['superuser'] = 'سوپریوزر'; // Only superuser can make superuser
                                                foreach ($roles_available as $role_key => $role_name):
                                                    if ($role_key === $user_item['role']) continue; // Skip current role
                                                    if ($role_key === 'superuser' && $_SESSION['role'] !== 'superuser') continue; // Only SU can make SU
                                                    if ($user_item['role'] === 'superuser' && $role_key !== 'superuser' && $_SESSION['role'] !== 'superuser') continue; // Only SU can demote SU
                                                ?>
                                                    <option value="make_<?= $role_key ?>"><?= escapeHtml($role_name) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                        <!-- Guest Chat Toggle -->
                                        <form method="post" action="admin.php" class="d-inline action-form">
                                            <input type="hidden" name="user_id_action" value="<?= $uid ?>">
                                            <?php if ($user_item['can_chat_with_guests']): ?>
                                                <button type="submit" name="action" value="disallow_guest_chat" class="btn btn-outline-secondary btn-sm" title="لغو اجازه چت مهمان"><i class="fas fa-comments-slash"></i></button>
                                            <?php else: ?>
                                                <button type="submit" name="action" value="allow_guest_chat" class="btn btn-outline-success btn-sm" title="دادن اجازه چت مهمان"><i class="fas fa-comments"></i></button>
                                            <?php endif; ?>
                                        </form>
                                        <?php
                                        $current_admin_id = $_SESSION['user_id'];
                                        if ($current_admin_id != $uid): ?>
                                            <form method="post" action="admin.php" class="d-inline action-form">
                                                <input type="hidden" name="user_id_action" value="<?= $uid ?>">
                                                <button type="submit" name="action" value="delete_user" class="btn btn-danger btn-sm" title="حذف کاربر"
                                                    onclick="return confirm('آیا مطمئن هستید که می‌خواهید این کاربر و تمام دسترسی‌های پروژه‌ او را حذف کنید؟ این عمل قابل بازگشت نیست.')">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MOBILE CARD VIEW -->
        <div class="mobile-cards">
            <?php if (empty($users_list) && !$error): ?>
                <p class="text-center">هیچ کاربری یافت نشد.</p>
            <?php endif; ?>
            <?php foreach ($users_list as $user_item):
                $uid = $user_item['id'];
                $user_role_translated = escapeHtml(translate_role($user_item['role'])); // Pre-calculate for reuse
                $assigned_project_ids_for_user = $user_project_assignments[$uid] ?? [];
                // Determine role class for styling (same as desktop)                
                $roleClassMobile = 'role-user';
                switch ($user_item['role']) {
                    case 'admin':
                        $roleClassMobile = 'role-admin';
                        break;
                    case 'superuser':
                        $roleClassMobile = 'role-superuser';
                        break;
                    case 'supervisor':
                        $roleClassMobile = 'role-supervisor';
                        break;
                    case 'planner':
                        $roleClassMobile = 'role-planner';
                        break;
                    case 'cnc_operator':
                        $roleClassMobile = 'role-cnc';
                        break;
                    case 'guest':
                        $roleClassMobile = 'role-guest';
                        break;
                    case 'user':
                        $roleClassMobile = 'role-user';
                        break;
                    case 'cat':
                        $roleClassMobile = 'role-cat'; // پیمانکار آتیه نما
                        break;
                    case 'car':
                        $roleClassMobile = 'role-car'; // پیمانکار آرانسج
                        break;
                    case 'coa':
                        $roleClassMobile = 'role-coa'; // پیمانکار عمران آذرستان
                        break;
                    case 'crs':
                        $roleClassMobile = 'role-crs'; // پیمانکار شرکت ساختمانی رس
                        break;
                    default:
                        $roleClassMobile = 'role-unknown';
                        break;
                }
            ?>
                <div class="user-card">
                    <div class="user-card-header">
                        <div class="profile-pic-container">
                            <img src="<?= escapeHtml(!empty($user_item['avatar_path']) && fileExistsAndReadable(PUBLIC_HTML_ROOT . $user_item['avatar_path']) ? $user_item['avatar_path'] : '/assets/images/default-avatar.jpg') ?>"
                                alt="Profile Picture" class="profile-pic1">
                        </div>
                        <div class="user-card-info">
                            <h3 class="user-card-name"><?= escapeHtml($user_item['first_name'] . ' ' . $user_item['last_name']) ?></h3>
                            <p class="user-card-username">@<?= escapeHtml($user_item['username']) ?></p>
                        </div>
                    </div>

                    <div class="user-card-details">
                        <p><span>ایمیل:</span> <?= escapeHtml($user_item['email']) ?></p>
                        <p><span>نقش:</span> <span class="role-pill <?= $roleClassMobile ?>"><?= $user_role_translated ?></span></p>
                        <p><span>وضعیت:</span>
                            <?php if ($user_item['is_active']): ?>
                                <span class="status-badge status-active"><i class="fas fa-check-circle me-1"></i> فعال</span>
                            <?php else: ?>
                                <span class="status-badge status-inactive"><i class="fas fa-times-circle me-1"></i> غیرفعال</span>
                            <?php endif; ?>
                        </p>
                        <p><span>چت مهمان:</span>
                            <?php if ($user_item['can_chat_with_guests']): ?>
                                <span class="status-badge status-active">مجاز</span>
                            <?php else: ?>
                                <span class="status-badge status-inactive">غیرمجاز</span>
                            <?php endif; ?>
                        </p>
                        <p><span>تاریخ عضویت:</span> <?= escapeHtml(format_jalali_date($user_item['created_at'])) ?></p>
                    </div>

                    <!-- Project Assignments for Mobile -->
                    <div class="project-assignments-section">
                        <form method="post" action="admin.php" class="project-assignment-form-mobile">
                            <input type="hidden" name="user_id_projects" value="<?= $uid ?>">
                            <input type="hidden" name="action_projects" value="update_assignments">
                            <h6>پروژه‌های تخصیص یافته:</h6>
                            <div class="project-checkbox-group mb-2">
                                <?php if (empty($all_projects_map)): ?>
                                    <small>هیچ پروژه‌ای برای تخصیص تعریف نشده است.</small>
                                <?php else: ?>
                                    <?php foreach ($all_projects_map as $project_id => $project_name): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="projects[]"
                                                value="<?= $project_id ?>" id="mobile_proj_<?= $uid ?>_<?= $project_id ?>"
                                                <?= in_array($project_id, $assigned_project_ids_for_user) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="mobile_proj_<?= $uid ?>_<?= $project_id ?>"><?= escapeHtml($project_name) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <h6 class="mt-2">پروژه پیش‌فرض:</h6>
                            <select name="default_project" class="form-select form-select-sm default-project-select mb-2 w-100">
                                <option value="">-- بدون پیش‌فرض --</option>
                                <?php foreach ($all_projects_map as $project_id => $project_name): ?>
                                    <option value="<?= $project_id ?>" <?= ($user_item['default_project_id'] == $project_id) ? 'selected' : '' ?>
                                        <?= !in_array($project_id, $assigned_project_ids_for_user) ? 'disabled class="text-muted"' : '' ?>>
                                        <?= escapeHtml($project_name) ?> <?= !in_array($project_id, $assigned_project_ids_for_user) ? '(ابتدا تخصیص دهید)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary w-100 mt-1">ذخیره تنظیمات پروژه</button>
                        </form>
                    </div>

                    <div class="user-card-actions mt-3">
                        <a href="profile.php?id=<?= $uid ?>" class="btn btn-info btn-sm w-100 mb-1"><i class="fas fa-user-edit me-1"></i> پروفایل</a>

                        <form method="post" action="admin.php" class="action-form w-100 mb-1">
                            <input type="hidden" name="user_id_action" value="<?= $uid ?>">
                            <?php if ($user_item['is_active']): ?>
                                <button type="submit" name="action" value="deactivate" class="btn btn-warning btn-sm w-100"><i class="fas fa-user-slash me-1"></i> غیرفعال کردن</button>
                            <?php else: ?>
                                <button type="submit" name="action" value="activate" class="btn btn-success btn-sm w-100"><i class="fas fa-user-check me-1"></i> فعال کردن</button>
                            <?php endif; ?>
                        </form>

                        <form method="post" action="admin.php" class="action-form w-100 mb-1">
                            <input type="hidden" name="user_id_action" value="<?= $uid ?>">
                            <select name="action" class="form-select form-select-sm role-select w-100" onchange="this.form.submit()" title="تغییر نقش کاربر">
                                <option value="">نقش فعلی: <?= $user_role_translated ?></option>
                                <?php
                                $roles_available_mobile = [
                                    'admin' => 'مدیر',
                                    'supervisor' => 'سرپرست',
                                    'user' => 'کاربر',
                                    'planner' => 'طراح',
                                    'cnc_operator' => 'اپراتور CNC',
                                    'superuser' => 'سوپریوزر',
                                    'guest' => 'مهمان',
                                    'cat' => 'پیمانکار آتیه نما',
                                    'car' => 'پیمانکار آرانسج',
                                    'coa' => 'پیمانکار عمران آذرستان',
                                    'crs' => 'پیمانکار شرکت ساختمانی رس'
                                ];
                                if ($_SESSION['role'] === 'superuser') $roles_available_mobile['superuser'] = 'سوپریوزر';
                                foreach ($roles_available_mobile as $role_key => $role_name):
                                    if ($role_key === $user_item['role']) continue;
                                    if ($role_key === 'superuser' && $_SESSION['role'] !== 'superuser') continue;
                                    if ($user_item['role'] === 'superuser' && $role_key !== 'superuser' && $_SESSION['role'] !== 'superuser') continue;
                                ?>
                                    <option value="make_<?= $role_key ?>">تغییر به: <?= escapeHtml($role_name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>

                        <form method="post" action="admin.php" class="action-form w-100 mb-1">
                            <input type="hidden" name="user_id_action" value="<?= $uid ?>">
                            <?php if ($user_item['can_chat_with_guests']): ?>
                                <button type="submit" name="action" value="disallow_guest_chat" class="btn btn-outline-secondary btn-sm w-100" title="لغو اجازه چت مهمان"><i class="fas fa-comments-slash me-1"></i> لغو چت مهمان</button>
                            <?php else: ?>
                                <button type="submit" name="action" value="allow_guest_chat" class="btn btn-outline-success btn-sm w-100" title="دادن اجازه چت مهمان"><i class="fas fa-comments me-1"></i> اجازه چت مهمان</button>
                            <?php endif; ?>
                        </form>

                        <?php if ($_SESSION['user_id'] != $uid): ?>
                            <form method="post" action="admin.php" class="action-form w-100">
                                <input type="hidden" name="user_id_action" value="<?= $uid ?>">
                                <button type="submit" name="action" value="delete_user" class="btn btn-danger btn-sm w-100"
                                    onclick="return confirm('آیا مطمئن هستید که می‌خواهید این کاربر و تمام دسترسی‌های پروژه‌ او را حذف کنید؟ این عمل قابل بازگشت نیست.')">
                                    <i class="fas fa-trash-alt me-1"></i> حذف کاربر
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

    <?php
    // Assuming footer.php is in the same directory or an 'includes' subdirectory
    require_once __DIR__ . '/footer_common.php';
    ?>