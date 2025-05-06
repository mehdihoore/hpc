<?php
// admin.php
require_once __DIR__ . '/../../sercon/config.php';
require_once 'includes/jdf.php';
require_once 'includes/functions.php';


secureSession();
error_log("admin.php accessed. User role: " . ($_SESSION['role'] ?? 'none') . ", User ID: " . ($_SESSION['user_id'] ?? 'none'));
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superuser')) {
    header('Location: /login'); // Use the route, not the file
    exit();
}

$pageTitle = 'پنل مدیریت';
require_once 'header.php';

$message = '';
$error = '';

try {
    $pdo = connectDB();
} catch (PDOException $e) {
    logError("Database connection error in admin.php: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'], $_POST['user_id'])) {
        $user_id = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
        if ($user_id === false) {
            $error = "شناسه کاربر نامعتبر است.";
        } else {
            $action = $_POST['action'];

            try {
                $sql = "";
                switch ($action) {
                    case 'activate':
                        $sql = "UPDATE users SET is_active = TRUE, activation_date = NOW() WHERE id = ?";
                        $params = [$user_id];
                        $message = "کاربر با موفقیت فعال شد.";
                        break;

                    case 'deactivate':
                        $sql = "UPDATE users SET is_active = FALSE WHERE id = ?";
                        $params = [$user_id];
                        $message = "کاربر با موفقیت غیرفعال شد.";
                        break;
                    case 'allow_guest_chat':
                        $sql = "UPDATE users SET can_chat_with_guests = 1 WHERE id = ?";
                        $params = [$user_id];
                        $message = "اجازه چت مهمان برای کاربر فعال شد.";
                        break;

                    case 'disallow_guest_chat':
                        $sql = "UPDATE users SET can_chat_with_guests = 0 WHERE id = ?";
                        $params = [$user_id];
                        $message = "اجازه چت مهمان برای کاربر غیرفعال شد.";
                        break;
                    case 'make_admin':
                        $sql = "UPDATE users SET role = 'admin' WHERE id = ?";
                        $params = [$user_id];
                        $message = "کاربر با موفقیت به مدیر تبدیل شد.";
                        break;

                    case 'make_supervisor':
                        $sql = "UPDATE users SET role = 'supervisor' WHERE id = ?";
                        $params = [$user_id];
                        $message = "کاربر با موفقیت به سرپرست تبدیل شد.";
                        break;

                    case 'make_user':
                        $sql = "UPDATE users SET role = 'user' WHERE id = ?";
                        $params = [$user_id];
                        $message = "کاربر با موفقیت به کاربر عادی تبدیل شد.";
                        break;
                    case 'make_guest':
                        $sql = "UPDATE users SET role = 'guest' WHERE id = ?";
                        $params = [$user_id];
                        $message = "کاربر با موفقیت به مهمان تبدیل شد.";
                        break;
                    case 'make_planner':
                        $sql = "UPDATE users SET role = 'planner' WHERE id = ?";
                        $params = [$user_id];
                        $message = "کاربر با موفقیت به طراح تبدیل شد.";
                        break;

                    case 'make_cnc_operator':
                        $sql = "UPDATE users SET role = 'cnc_operator' WHERE id = ?";
                        $params = [$user_id];
                        $message = "کاربر با موفقیت به اپراتور CNC تبدیل شد.";
                        break;

                    case 'delete_user':
                        if ($_SESSION['user_id'] == $user_id) {
                            $error = "شما نمی‌توانید حساب کاربری خود را حذف کنید.";
                            break;
                        }

                        // Check if this is the last admin
                        $checkAdminStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND id != ?");
                        $checkAdminStmt->execute([$user_id]);
                        $adminCount = $checkAdminStmt->fetchColumn();

                        // Check if the user we're trying to delete is an admin
                        $checkUserStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                        $checkUserStmt->execute([$user_id]);
                        $userRole = $checkUserStmt->fetchColumn();

                        if ($userRole === 'admin' && $adminCount === 0) {
                            $error = "این آخرین حساب مدیر است و نمی‌تواند حذف شود.";
                            break;
                        }

                        // Start transaction
                        $pdo->beginTransaction();
                        try {
                            // First, delete related activity logs
                            $deleteLogsStmt = $pdo->prepare("DELETE FROM activity_log WHERE user_id = ?");
                            $deleteLogsStmt->execute([$user_id]);

                            // Then delete the user
                            $deleteUserStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                            $deleteUserStmt->execute([$user_id]);

                            if ($deleteUserStmt->rowCount() > 0) {
                                $pdo->commit();
                                $message = "کاربر با موفقیت حذف شد.";
                                // Don't log this activity since we just deleted the activity_log entries
                            } else {
                                $pdo->rollBack();
                                $error = "کاربر مورد نظر یافت نشد.";
                            }
                        } catch (PDOException $e) {
                            $pdo->rollBack();
                            logError("Delete user transaction failed: " . $e->getMessage());
                            $error = "خطا در حذف کاربر. لطفا دوباره تلاش کنید.";
                        }
                        break;

                    case 'reset_password':
                        try {
                            $newPassword = bin2hex(random_bytes(8));
                            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                            $sql = "UPDATE users SET password_hash = ? WHERE id = ?";
                            $params = [$passwordHash, $user_id];

                            $stmt = $pdo->prepare($sql);
                            $stmt->execute($params);

                            if ($stmt->rowCount() > 0) {
                                // Get username for the message
                                $usernameStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                                $usernameStmt->execute([$user_id]);
                                $username = $usernameStmt->fetchColumn();

                                $message = "رمز عبور برای کاربر " . htmlspecialchars($username) . " بازنشانی شد. رمز عبور جدید: " . $newPassword;
                                log_activity($_SESSION['user_id'], 'reset_password', "User ID: $user_id");
                            } else {
                                $error = "خطا در بازنشانی رمز عبور. کاربر یافت نشد.";
                            }
                        } catch (Exception $e) {
                            logError("Password reset error: " . $e->getMessage());
                            $error = "خطا در بازنشانی رمز عبور. لطفا دوباره تلاش کنید.";
                        }
                        break;

                    default:
                        logError("Invalid action in admin.php: $action");
                        $error = "عملیات نامعتبر است.";
                        break;
                }

                // Execute the SQL if it's not empty and we don't have an error
                if (!empty($sql) && empty($error)) {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);

                    if ($stmt->rowCount() > 0 && empty($message)) {
                        $message = "عملیات با موفقیت انجام شد.";
                    }

                    if ($action != 'delete_user' && $action != 'reset_password') {
                        log_activity($_SESSION['user_id'], $action, "User ID: $user_id");
                    }
                }
            } catch (PDOException $e) {
                logError("Database error in admin.php during user action: " . $e->getMessage());
                $error = "خطایی در انجام عملیات رخ داد. لطفا دوباره تلاش کنید.";
            }
        }
    }
}

// Get all users
try {
    $stmt = $pdo->prepare("SELECT id, username, email, first_name, last_name, role, is_active, created_at, activation_date, avatar_path, can_chat_with_guests  FROM users ORDER BY created_at DESC");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logError("Database error in admin.php while fetching users: " . $e->getMessage());
    $users = [];
    $error = "خطا در بازیابی لیست کاربران.";
}

// Helper function to translate roles to Persian
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
    ];
    return $roles[$role] ?? $role;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
    <!-- Add your custom CSS here -->
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

        @media (min-width: 769px) {
            .desktop-table {
                display: block;
            }

            .mobile-cards {
                display: none;
            }
        }
    </style>
</head>

<body>

    <div class="container admin-container">
        <div class="admin-header">
            <h1 class="admin-title"><i class="fas fa-user-shield"></i> <?= $pageTitle ?></h1>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i> <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i> <?= $error ?>
            </div>
        <?php endif; ?>
        <div class="desktop-table">
            <div class="users-table table-responsive">
                <table class="table table-hover">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><i class="fas fa-user me-2"></i> تصویر</th>
                                <th>نام کاربری</th>
                                <th>نام و نام خانوادگی</th>
                                <th>ایمیل</th>
                                <th>نقش</th>
                                <th>وضعیت</th>
                                <th>چت مهمان</th>
                                <th>تاریخ عضویت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="profile-pic-container">
                                            <?php
                                            $profilePicturePath = isset($user['avatar_path']) && !empty($user['avatar_path']) ? $user['avatar_path'] : '';
                                            // Check the avatar path

                                            // If the avatar path exists, use it; otherwise, use the default picture
                                            if (!empty($profilePicturePath) && file_exists($profilePicturePath)): ?>
                                                <img src="<?= $profilePicturePath ?>" alt="Profile Picture" class="profile-pic1">
                                            <?php else: ?>
                                                <img src="assets/images/default-avatar.jpg" alt="Default Profile Picture" class="profile-pic">
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= escapeHtml($user['username']) ?></td>
                                    <td><?= escapeHtml($user['first_name'] . ' ' . $user['last_name']) ?></td>
                                    <td><?= escapeHtml($user['email']) ?></td>
                                    <td>
                                        <?php
                                        $roleClass = 'role-user';
                                        switch ($user['role']) {
                                            case 'admin':
                                                $roleClass = 'role-admin';
                                                break;
                                            case 'superuser':
                                                $roleClass = 'role-superuser';
                                                break;
                                            case 'supervisor':
                                                $roleClass = 'role-supervisor';
                                                break;
                                            case 'planner':
                                                $roleClass = 'role-planner';
                                                break;
                                            case 'cnc_operator':
                                                $roleClass = 'role-cnc';
                                                break;
                                            case 'guest':
                                                $roleClass = 'role-guest';
                                                break;
                                        }
                                        ?>
                                        <span class="role-pill <?= $roleClass ?>"><?= escapeHtml(translate_role($user['role'])) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                            <span class="status-badge status-active"><i class="fas fa-check-circle me-1"></i> فعال</span>
                                        <?php else: ?>
                                            <span class="status-badge status-inactive"><i class="fas fa-times-circle me-1"></i> غیرفعال</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" style="display: inline;" class="action-form">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <?php if ($user['can_chat_with_guests']): ?>
                                                <span class="status-badge status-active">مجاز</span>
                                                <button type="submit" name="action" value="disallow_guest_chat" class="btn btn-warning btn-sm ms-1" title="غیرمجاز کردن چت مهمان">
                                                    <i class="fas fa-comment-slash"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="status-badge status-inactive">غیرمجاز</span>
                                                <button type="submit" name="action" value="allow_guest_chat" class="btn btn-success btn-sm ms-1" title="مجاز کردن چت مهمان">
                                                    <i class="fas fa-comments"></i>
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                    <td><?= escapeHtml(format_jalali_date($user['created_at'])) ?></td>
                                    <td>
                                        <div class="actions-container">
                                            <?php if ($_SESSION['role'] === 'superuser' || $_SESSION['role'] === 'admin' || $_SESSION['user_id'] === $user['id']): ?>
                                                <a href="profile.php?id=<?= $user['id'] ?>" class="btn btn-info btn-sm">
                                                    <i class="fas fa-user me-1"></i> پروفایل
                                                </a>
                                            <?php endif; ?>

                                            <!-- Activate/Deactivate -->
                                            <form method="post" style="display: inline;" class="action-form">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <?php if ($user['is_active']): ?>
                                                    <button type="submit" name="action" value="deactivate" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-ban me-1"></i> غیرفعال کردن
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" name="action" value="activate" class="btn btn-success btn-sm">
                                                        <i class="fas fa-check me-1"></i> فعال کردن
                                                    </button>
                                                <?php endif; ?>
                                            </form>

                                            <!-- Change Role -->
                                            <form method="post" style="display: inline;" class="action-form">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <select name="action" class="form-select form-select-sm role-select" onchange="this.form.submit()">
                                                    <option value="">تغییر نقش</option>
                                                    <option value="make_guest" <?= $user['role'] == 'guest' ? 'selected' : '' ?>>مهمان</option>
                                                    <option value="make_user" <?= $user['role'] == 'user' ? 'selected' : '' ?>>کاربر</option>
                                                    <option value="make_planner" <?= $user['role'] == 'planner' ? 'selected' : '' ?>>طراح</option>
                                                    <option value="make_cnc_operator" <?= $user['role'] == 'cnc_operator' ? 'selected' : '' ?>>اپراتور CNC</option>
                                                    <option value="make_supervisor" <?= $user['role'] == 'supervisor' ? 'selected' : '' ?>>سرپرست</option>
                                                    <?php if ($user['role'] != 'admin'): ?>
                                                        <option value="make_admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>مدیر</option>
                                                    <?php endif; ?>
                                                </select>
                                            </form>

                                            <!-- Reset Password -->
                                            <form method="post" style="display: inline;" class="action-form">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <button type="submit" name="action" value="reset_password" class="btn btn-secondary btn-sm"
                                                    onclick="return confirm('آیا مطمئن هستید که می‌خواهید رمز عبور این کاربر را بازنشانی کنید؟')">
                                                    <i class="fas fa-key me-1"></i> بازنشانی رمز
                                                </button>
                                            </form>

                                            <!-- Delete User -->
                                            <?php if ($_SESSION['user_id'] != $user['id']): // Prevent self-deletion 
                                            ?>
                                                <form method="post" style="display: inline;" class="action-form">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" name="action" value="delete_user" class="btn btn-danger btn-sm"
                                                        onclick="return confirm('آیا مطمئن هستید که می‌خواهید این کاربر را حذف کنید؟')">
                                                        <i class="fas fa-trash-alt me-1"></i> حذف
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
        <!-- Mobile Card View -->
        <div class="mobile-cards">
            <?php foreach ($users as $user): ?>
                <div class="user-card">
                    <div class="user-card-header">
                        <div class="profile-pic-container">
                            <?php
                            $profilePicturePath = isset($user['avatar_path']) && !empty($user['avatar_path']) ? $user['avatar_path'] : '';

                            // If the avatar path exists, use it; otherwise, use the default picture
                            if (!empty($profilePicturePath) && file_exists($profilePicturePath)): ?>
                                <img src="<?= $profilePicturePath ?>" alt="Profile Picture" class="profile-pic">
                            <?php else: ?>
                                <img src="assets/images/default-avatar.jpg" alt="Default Profile Picture" class="profile-pic">
                            <?php endif; ?>
                        </div>
                        <div class="user-card-info">
                            <h3 class="user-card-name"><?= escapeHtml($user['first_name'] . ' ' . $user['last_name']) ?></h3>
                            <p class="user-card-username"><?= escapeHtml($user['username']) ?></p>
                        </div>
                    </div>

                    <div class="user-card-details">
                        <p><span>ایمیل:</span> <?= escapeHtml($user['email']) ?></p>
                        <p><span>نقش:</span> <span class="role-pill <?= $roleClass ?>"><?= escapeHtml(translate_role($user['role'])) ?></span></p>
                        <p><span>وضعیت:</span>
                            <?php if ($user['is_active']): ?>
                                <span class="status-badge status-active"><i class="fas fa-check-circle me-1"></i> فعال</span>
                            <?php else: ?>
                                <span class="status-badge status-inactive"><i class="fas fa-times-circle me-1"></i> غیرفعال</span>
                            <?php endif; ?>
                        </p>
                        <p><span>چت مهمان:</span>
                            <?php if ($user['can_chat_with_guests']): ?>
                                <span class="status-badge status-active">مجاز</span>
                            <?php else: ?>
                                <span class="status-badge status-inactive">غیرمجاز</span>
                            <?php endif; ?>
                        </p>
                        <p><span>تاریخ عضویت:</span> <?= escapeHtml(format_jalali_date($user['created_at'])) ?></p>
                    </div>

                    <div class="user-card-actions">
                        <?php if ($_SESSION['role'] === 'superuser' || $_SESSION['role'] === 'admin' || $_SESSION['user_id'] === $user['id']): ?>
                            <a href="profile.php?id=<?= $user['id'] ?>" class="btn btn-info btn-sm">
                                <i class="fas fa-user me-1"></i> پروفایل
                            </a>
                        <?php endif; ?>

                        <!-- Activate/Deactivate -->
                        <form method="post" style="display: inline;" class="action-form">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <?php if ($user['is_active']): ?>
                                <button type="submit" name="action" value="deactivate" class="btn btn-warning btn-sm">
                                    <i class="fas fa-ban me-1"></i> غیرفعال کردن
                                </button>
                            <?php else: ?>
                                <button type="submit" name="action" value="activate" class="btn btn-success btn-sm">
                                    <i class="fas fa-check me-1"></i> فعال کردن
                                </button>
                            <?php endif; ?>
                        </form>
                        <form method="post" style="display: inline;" class="action-form">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <?php if ($user['can_chat_with_guests']): ?>
                                <button type="submit" name="action" value="disallow_guest_chat" class="btn btn-warning btn-sm" title="غیرمجاز کردن چت مهمان">
                                    <i class="fas fa-comment-slash me-1"></i> عدم اجازه مهمان
                                </button>
                            <?php else: ?>
                                <button type="submit" name="action" value="allow_guest_chat" class="btn btn-success btn-sm" title="مجاز کردن چت مهمان">
                                    <i class="fas fa-comments me-1"></i> اجازه مهمان
                                </button>
                            <?php endif; ?>
                        </form>
                        <!-- Change Role -->
                        <form method="post" style="display: inline;" class="action-form">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <select name="action" class="form-select form-select-sm role-select" onchange="this.form.submit()">
                                <option value="">تغییر نقش</option>
                                <option value="make_guest" <?= $user['role'] == 'guest' ? 'selected' : '' ?>>مهمان</option>
                                <option value="make_user" <?= $user['role'] == 'user' ? 'selected' : '' ?>>کاربر</option>
                                <option value="make_planner" <?= $user['role'] == 'planner' ? 'selected' : '' ?>>طراح</option>
                                <option value="make_cnc_operator" <?= $user['role'] == 'cnc_operator' ? 'selected' : '' ?>>اپراتور CNC</option>
                                <option value="make_supervisor" <?= $user['role'] == 'supervisor' ? 'selected' : '' ?>>سرپرست</option>
                                <?php if ($user['role'] != 'admin'): ?>
                                    <option value="make_admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>مدیر</option>
                                <?php endif; ?>
                            </select>
                        </form>

                        <!-- Reset Password -->
                        <form method="post" style="display: inline;" class="action-form">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <button type="submit" name="action" value="reset_password" class="btn btn-secondary btn-sm"
                                onclick="return confirm('آیا مطمئن هستید که می‌خواهید رمز عبور این کاربر را بازنشانی کنید؟')">
                                <i class="fas fa-key me-1"></i> بازنشانی رمز
                            </button>
                        </form>

                        <!-- Delete User -->
                        <?php if ($_SESSION['user_id'] != $user['id']): // Prevent self-deletion 
                        ?>
                            <form method="post" style="display: inline;" class="action-form">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <button type="submit" name="action" value="delete_user" class="btn btn-danger btn-sm"
                                    onclick="return confirm('آیا مطمئن هستید که می‌خواهید این کاربر را حذف کنید؟')">
                                    <i class="fas fa-trash-alt me-1"></i> حذف
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <script src="assets/js/jquery-3.6.0.min.js"></script>
        <script src="assets/js/bootstrap.bundle.min.js"></script>
        <?php require_once 'footer.php'; ?>
</body>

</html>