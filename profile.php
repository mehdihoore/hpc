<?php
// public_html/profile.php
$pageTitle = 'پروفایل کاربر'; // Set the page title first
require_once __DIR__ . '/../sercon/bootstrap.php'; // Use the new bootstrap FIRST
require_once __DIR__ . '/header_common.php'; // Use the common header

// secureSession() should have been called by header_common.php or bootstrap.php

// Determine the user ID to display/edit
$requested_user_id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : ($_SESSION['user_id'] ?? null);
$current_user_id = $_SESSION['user_id'] ?? null;
$current_user_role = $_SESSION['role'] ?? null;
$current_user_username = $_SESSION['username'] ?? 'Unknown';

// --- Authorization Checks ---
if (!$current_user_id) {
    header('Location: login.php?msg=login_required'); // Redirect if not logged in at all
    exit();
}

if (!$requested_user_id) {
    // If ID param is invalid and user is logged in, redirect to their own profile
    header('Location: profile.php');
    exit();
}

// Check if user has permission to view/edit this profile
// Regular users can only view/edit their own profile.
// Admins/Superusers can view/edit any profile.
$is_own_profile = ($current_user_id == $requested_user_id);
$can_edit_others = ($current_user_role === 'admin' || $current_user_role === 'superuser');

if (!$is_own_profile && !$can_edit_others) {
    // Trying to view someone else's profile without permission
    logError("Unauthorized profile view attempt. User ID: {$current_user_id} tried to view profile ID: {$requested_user_id}");
    // Redirect to their own profile instead of showing an error
    header('Location: profile.php');
    exit();
}
// --- End Authorization Checks ---


$message = '';
$error = '';
$user = null; // Initialize user variable

// --- Constants for Avatar Upload (assuming defined in bootstrap.php) ---
$allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
$max_size = 5 * 1024 * 1024; // 5MB
// AVATAR_UPLOAD_PATH_SYSTEM should be the full filesystem path (e.g., C:/xampp/htdocs/public_html/uploads/avatars/)
$avatar_system_path = defined('AVATAR_UPLOAD_PATH_SYSTEM') ? AVATAR_UPLOAD_PATH_SYSTEM : (PUBLIC_HTML_ROOT . '/uploads/avatars/');
// AVATAR_UPLOAD_DIR_PUBLIC should be the web-relative path (e.g., /uploads/avatars/)
$avatar_web_path_prefix = defined('AVATAR_UPLOAD_DIR_PUBLIC') ? AVATAR_UPLOAD_DIR_PUBLIC : '/uploads/avatars/';


// Ensure the upload directory exists and is writable
if (!is_dir($avatar_system_path)) {
    if (!mkdir($avatar_system_path, 0755, true)) {
        logError("Failed to create avatar upload directory: {$avatar_system_path}");
        $error = "خطای سیستمی: امکان ایجاد پوشه آپلود وجود ندارد.";
        // Display error but don't necessarily die, maybe avatar upload just won't work
    }
} elseif (!is_writable($avatar_system_path)) {
    logError("Avatar upload directory is not writable: {$avatar_system_path}");
    $error = "خطای سیستمی: امکان نوشتن در پوشه آپلود وجود ندارد.";
    // Display error
}

try {
    $pdo = getCommonDBConnection(); // Connect to hpc_common

    // --- Handle Form Submission ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) { // Only process POST if no initial errors

        // CSRF Check
        if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            logError("CSRF token mismatch in profile.php. User: {$current_user_username}, Target: {$requested_user_id}");
            $error = "درخواست نامعتبر است (CSRF). لطفاً دوباره تلاش کنید.";
        } else {

            // Fetch user data again before update to ensure consistency
            $stmt_fetch = $pdo->prepare("SELECT username, email, first_name, last_name, phone_number, avatar_path, password_hash FROM users WHERE id = ?");
            $stmt_fetch->execute([$requested_user_id]);
            $user_before_update = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

            if (!$user_before_update) {
                throw new Exception("کاربر مورد نظر برای به‌روزرسانی یافت نشد.");
            }

            $pdo->beginTransaction(); // Start transaction

            // 1. Handle Avatar Upload
            $new_avatar_db_path = $user_before_update['avatar_path']; // Keep old path unless successfully updated
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['avatar'];

                // Basic validation already happened, add more specific checks
                $file_type = mime_content_type($file['tmp_name']);
                if (!in_array($file_type, $allowed_types)) {
                    $error = "فرمت فایل تصویر مجاز نیست (فقط JPG، PNG، GIF).";
                } elseif ($file['size'] > $max_size) {
                    $error = "حجم فایل تصویر بیش از حد مجاز است (حداکثر 5MB).";
                } else {
                    // Generate unique filename
                    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $new_filename_base = 'avatar_' . $requested_user_id . '_' . time() . bin2hex(random_bytes(4));
                    $new_filename = $new_filename_base . '.' . $file_extension;
                    $upload_target_system_path = rtrim($avatar_system_path, '/') . '/' . $new_filename;
                    $upload_target_web_path = rtrim($avatar_web_path_prefix, '/') . '/' . $new_filename; // Path to store in DB

                    if (move_uploaded_file($file['tmp_name'], $upload_target_system_path)) {
                        // Successfully moved, update path for DB update
                        $new_avatar_db_path = $upload_target_web_path;

                        // Delete old avatar file if it exists and is different
                        $old_avatar_system_path = !empty($user_before_update['avatar_path']) ? (PUBLIC_HTML_ROOT . $user_before_update['avatar_path']) : null;
                        if ($old_avatar_system_path && $old_avatar_system_path !== $upload_target_system_path && file_exists($old_avatar_system_path)) {
                            if (!unlink($old_avatar_system_path)) {
                                logError("Could not delete old avatar: {$old_avatar_system_path}");
                                // Non-critical error, maybe set a flag or message
                            }
                        }
                        $message .= "تصویر پروفایل به‌روز شد. "; // Append message
                    } else {
                        logError("Failed to move uploaded avatar file to: {$upload_target_system_path}");
                        $error = "خطا در پردازش فایل آپلود شده.";
                    }
                }
            } // End Avatar Upload Handling

            // 2. Handle Profile Data Update (only if no upload error)
            if (empty($error)) {
                // Retrieve and trim inputs (don't sanitize with deprecated filters)
                $first_name = trim($_POST['first_name'] ?? '');
                $last_name = trim($_POST['last_name'] ?? '');
                $phone_number = trim($_POST['phone_number'] ?? '');
                $email_input = trim($_POST['email'] ?? '');
                $current_password = $_POST['current_password'] ?? ''; // Keep raw
                $new_password = $_POST['new_password'] ?? '';       // Keep raw
                $confirm_password = $_POST['confirm_password'] ?? ''; // Keep raw

                $updateFields = [];
                $params = [];

                // Add avatar path if it changed
                if ($new_avatar_db_path !== $user_before_update['avatar_path']) {
                    $updateFields[] = "avatar_path = ?";
                    $params[] = $new_avatar_db_path;
                }

                // Validate and add email change
                if (!empty($email_input) && $email_input !== $user_before_update['email']) {
                    if (!filter_var($email_input, FILTER_VALIDATE_EMAIL)) {
                        $error = "فرمت ایمیل وارد شده نامعتبر است.";
                    } else {
                        // Check email uniqueness
                        $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                        $checkEmail->execute([$email_input, $requested_user_id]);
                        if ($checkEmail->fetch()) {
                            $error = "این ایمیل قبلاً توسط کاربر دیگری ثبت شده است.";
                        } else {
                            $updateFields[] = "email = ?";
                            $params[] = $email_input;
                        }
                    }
                }

                // Add other fields if changed (basic validation can be added here too)
                if ($first_name !== $user_before_update['first_name']) {
                    if (mb_strlen($first_name) > 100) {
                        $error .= " نام بیش از حد طولانی است.";
                    } else {
                        $updateFields[] = "first_name = ?";
                        $params[] = $first_name;
                    }
                }
                if ($last_name !== $user_before_update['last_name']) {
                    if (mb_strlen($last_name) > 100) {
                        $error .= " نام خانوادگی بیش از حد طولانی است.";
                    } else {
                        $updateFields[] = "last_name = ?";
                        $params[] = $last_name;
                    }
                }
                if ($phone_number !== $user_before_update['phone_number']) {
                    if (!empty($phone_number) && !preg_match('/^[0-9\-\+\(\) ]{7,20}$/', $phone_number)) {
                        $error .= " شماره تلفن نامعتبر است.";
                    } else {
                        $updateFields[] = "phone_number = ?";
                        $params[] = $phone_number;
                    }
                }

                // Handle password change (only if new password is provided)
                if (!empty($new_password)) {
                    if (empty($current_password)) {
                        $error = "برای تغییر رمز عبور، رمز عبور فعلی الزامی است.";
                    } elseif (!password_verify($current_password, $user_before_update['password_hash'])) {
                        $error = "رمز عبور فعلی وارد شده اشتباه است.";
                    } elseif (strlen($new_password) < 8) {
                        $error = "رمز عبور جدید باید حداقل ۸ کاراکتر باشد.";
                    } elseif (!preg_match("/[A-Z]/", $new_password) || !preg_match("/[a-z]/", $new_password) || !preg_match("/[0-9]/", $new_password)) {
                        $error = "رمز عبور جدید باید شامل حروف بزرگ و کوچک لاتین و اعداد باشد.";
                    } elseif ($new_password !== $confirm_password) {
                        $error = "رمز عبور جدید و تکرار آن مطابقت ندارند.";
                    } else {
                        // All checks passed for password change
                        $updateFields[] = "password_hash = ?";
                        $params[] = password_hash($new_password, PASSWORD_DEFAULT);
                        $message .= "رمز عبور به‌روز شد. "; // Append message
                    }
                }

                // Perform Update if there are fields to update and no errors occurred
                if (!empty($updateFields) && empty($error)) {
                    $params[] = $requested_user_id; // Add the user ID for the WHERE clause
                    $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";

                    $updateStmt = $pdo->prepare($sql);
                    if ($updateStmt->execute($params)) {
                        $message .= "اطلاعات پروفایل ذخیره شد.";
                        log_activity($current_user_id, $current_user_username, 'update_profile', "Profile updated for User ID: {$requested_user_id}");
                        // No need to refetch user data here, transaction commit handles it.
                    } else {
                        $error = "خطا در ذخیره تغییرات پروفایل.";
                        logError("Failed to execute profile update SQL for User ID: {$requested_user_id}");
                    }
                } elseif (empty($updateFields) && empty($error) && !isset($_FILES['avatar'])) {
                    // No changes were detected if no avatar was uploaded either
                    $message = "هیچ تغییری برای ذخیره وجود نداشت.";
                }
            } // End profile data update block

            // Commit or Rollback Transaction
            if (empty($error)) {
                $pdo->commit();
                // Refresh user data AFTER successful commit to show updated info
                $stmt_refresh = $pdo->prepare("SELECT username, email, first_name, last_name, phone_number, avatar_path FROM users WHERE id = ?");
                $stmt_refresh->execute([$requested_user_id]);
                $user = $stmt_refresh->fetch(PDO::FETCH_ASSOC);
                if (!$user) throw new Exception("Failed to refetch user data after update."); // Should not happen
            } else {
                $pdo->rollBack();
                // Fetch original user data again if rollback happened
                $stmt_fetch_orig = $pdo->prepare("SELECT username, email, first_name, last_name, phone_number, avatar_path FROM users WHERE id = ?");
                $stmt_fetch_orig->execute([$requested_user_id]);
                $user = $stmt_fetch_orig->fetch(PDO::FETCH_ASSOC);
                if (!$user) die("خطا در بازیابی اطلاعات کاربر پس از خطا.");
            }
        } // End CSRF check else
    } // End POST request handling

    // --- Fetch Initial User Data (if not already fetched after POST/error) ---
    if ($user === null && empty($error)) {
        $stmt_initial_fetch = $pdo->prepare("SELECT username, email, first_name, last_name, phone_number, avatar_path FROM users WHERE id = ?");
        $stmt_initial_fetch->execute([$requested_user_id]);
        $user = $stmt_initial_fetch->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            // Handle case where user ID is invalid even on GET request
            logError("Invalid user ID requested in profile.php (GET): {$requested_user_id}");
            // Redirect to user's own profile or show a clean error
            if ($is_own_profile) {
                // This shouldn't happen if session user_id is valid
                header('Location: logout.php?msg=invalid_user');
                exit();
            } else {
                header('Location: admin.php?msg=user_not_found');
                exit(); // Redirect admin back
            }
        }
    }
} catch (PDOException $e) {
    logError("Database error processing profile.php for User ID {$requested_user_id}: " . $e->getMessage());
    $error = "خطا در ارتباط با پایگاه داده.";
    // Avoid showing detailed errors to the user in production
} catch (Exception $e) { // Catch other general exceptions
    logError("General error processing profile.php for User ID {$requested_user_id}: " . $e->getMessage());
    $error = "یک خطای سیستمی رخ داده است: " . $e->getMessage(); // Display specific error for now
}

// Final check if user data could be loaded at all
if ($user === null) {
    echo "<div class='container'><div class='alert alert-danger'>امکان بارگذاری اطلاعات کاربر وجود ندارد. " . escapeHtml($error) . "</div></div>";
    require_once __DIR__ . '/footer_common.php';
    exit();
}

// Ensure CSRF token is set for the form
if (session_status() == PHP_SESSION_ACTIVE && !isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'] ?? '';

?>

<!-- Start HTML content (already inside <div class="container content-area"> from header) -->
<div class="profile-container">
    <h1 class="mb-4"><?= escapeHtml($pageTitle) ?> <?= ($is_own_profile ? '' : ' برای کاربر: ' . escapeHtml($user['username'])) ?></h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= escapeHtml($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= nl2br(escapeHtml($error)) // Use nl2br if errors might have newlines 
                                        ?></div>
    <?php endif; ?>

    <form method="POST" action="profile.php?id=<?= (int)$requested_user_id // Ensure ID is in action URL 
                                                ?>" class="needs-validation" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrf_token); ?>">

        <div class="avatar-container">
            <?php
            // Determine the correct web path for the avatar
            $avatar_display_path = '/assets/images/default-avatar.jpg'; // Default
            if (!empty($user['avatar_path'])) {
                // Assuming avatar_path is stored like /uploads/avatars/file.jpg
                $avatar_display_path = escapeHtml($user['avatar_path']);
            }
            ?>
            <img src="<?= $avatar_display_path ?>"
                alt="تصویر پروفایل"
                class="avatar-preview mb-2"
                id="avatarPreview">

            <div class="avatar-upload">
                <label for="avatar" class="form-label">تغییر تصویر پروفایل (اختیاری)</label>
                <input type="file"
                    class="form-control <?php if (strpos($error, 'تصویر') !== false) echo 'is-invalid'; ?>"
                    id="avatar"
                    name="avatar"
                    accept=".jpg,.jpeg,.png,.gif"> <?php // More specific accepts 
                                                    ?>
                <div class="form-text text-muted">حداکثر حجم فایل: 5 مگابایت. فرمت‌های مجاز: JPG، PNG، GIF</div>
                <?php if (strpos($error, 'تصویر') !== false): ?>
                    <div class="invalid-feedback">خطایی در فایل تصویر وجود دارد.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="username" class="form-label">نام کاربری</label>
                <input type="text" class="form-control" id="username" value="<?= escapeHtml($user['username']) ?>" disabled readonly>
                <div class="form-text text-muted">نام کاربری قابل تغییر نیست.</div>
            </div>
            <div class="col-md-6">
                <label for="email" class="form-label">ایمیل</label>
                <input type="email" class="form-control <?php if (strpos($error, 'ایمیل') !== false) echo 'is-invalid'; ?>" id="email" name="email" value="<?= escapeHtml($user['email']) ?>" required>
                <div class="invalid-feedback">لطفا یک ایمیل معتبر وارد کنید.</div>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="first_name" class="form-label">نام</label>
                <input type="text" class="form-control <?php if (strpos($error, 'نام') !== false && strpos($error, 'خانوادگی') === false) echo 'is-invalid'; ?>" id="first_name" name="first_name" value="<?= escapeHtml($user['first_name']) ?>">
                <div class="invalid-feedback">نام نمی‌تواند خالی باشد.</div>
            </div>
            <div class="col-md-6">
                <label for="last_name" class="form-label">نام خانوادگی</label>
                <input type="text" class="form-control <?php if (strpos($error, 'نام خانوادگی') !== false) echo 'is-invalid'; ?>" id="last_name" name="last_name" value="<?= escapeHtml($user['last_name']) ?>">
                <div class="invalid-feedback">نام خانوادگی نمی‌تواند خالی باشد.</div>
            </div>
        </div>

        <div class="mb-3">
            <label for="phone_number" class="form-label">شماره تلفن (اختیاری)</label>
            <input type="tel" class="form-control <?php if (strpos($error, 'تلفن') !== false) echo 'is-invalid'; ?>" id="phone_number" name="phone_number" value="<?= escapeHtml($user['phone_number']) ?>" pattern="^[0-9\-\+\(\) ]{7,20}$">
            <div class="invalid-feedback">لطفا شماره تلفن معتبر وارد کنید.</div>
        </div>

        <hr class="my-4">

        <h3 class="mb-3">تغییر رمز عبور (فقط در صورت نیاز)</h3>
        <div class="mb-3">
            <label for="current_password" class="form-label">رمز عبور فعلی</label>
            <input type="password" class="form-control <?php if (strpos($error, 'فعلی اشتباه') !== false) echo 'is-invalid'; ?>" id="current_password" name="current_password" autocomplete="current-password">
            <div class="form-text text-muted">برای تنظیم رمز عبور جدید، رمز فعلی خود را وارد کنید.</div>
            <?php if (strpos($error, 'فعلی اشتباه') !== false || strpos($error, 'فعلی الزامی') !== false): ?>
                <div class="invalid-feedback d-block"><?= escapeHtml(strstr($error, 'فعلی')); // Show specific password error 
                                                        ?></div>
            <?php endif; ?>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="new_password" class="form-label">رمز عبور جدید</label>
                <input type="password" class="form-control <?php if (strpos($error, 'جدید') !== false) echo 'is-invalid'; ?>" id="new_password" name="new_password" autocomplete="new-password">
                <div class="invalid-feedback">رمز عبور جدید باید حداقل 8 کاراکتر و شامل حروف و اعداد باشد.</div>
            </div>
            <div class="col-md-6">
                <label for="confirm_password" class="form-label">تکرار رمز عبور جدید</label>
                <input type="password" class="form-control <?php if (strpos($error, 'مطابقت ندارند') !== false) echo 'is-invalid'; ?>" id="confirm_password" name="confirm_password" autocomplete="new-password">
                <div class="invalid-feedback">تکرار رمز عبور باید با رمز عبور جدید یکسان باشد.</div>
            </div>
            <?php if (strpos($error, 'مطابقت ندارند') !== false): ?>
                <div class="col-12 invalid-feedback d-block text-danger"><?= escapeHtml(strstr($error, 'مطابقت ندارند')); ?></div>
            <?php endif; ?>
            <?php if (strpos($error, 'جدید باید') !== false): ?>
                <div class="col-12 invalid-feedback d-block text-danger"><?= escapeHtml(strstr($error, 'جدید باید')); ?></div>
            <?php endif; ?>
        </div>

        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
            <button type="submit" class="btn btn-primary px-4"> <i class="fas fa-save me-2"></i> ذخیره تغییرات</button>
            <a href="profile.php" class="btn btn-secondary px-4"> <i class="fas fa-times me-2"></i> لغو</a>
        </div>
    </form>
</div> <!-- /.profile-container -->

<!-- JavaScript includes should be in footer_common.php -->
<script>
    // Keep your existing JS for validation and avatar preview
    // Form validation (Bootstrap 5)
    (function() {
        'use strict'
        var forms = document.querySelectorAll('.needs-validation')
        Array.prototype.slice.call(forms)
            .forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    // You might want to add password match check here before submission
                    var newPass = document.getElementById('new_password');
                    var confirmPass = document.getElementById('confirm_password');
                    if (newPass && confirmPass && newPass.value && newPass.value !== confirmPass.value) {
                        confirmPass.setCustomValidity("Passwords Don't Match"); // Trigger validation msg
                        event.preventDefault()
                        event.stopPropagation()
                    } else if (confirmPass) {
                        confirmPass.setCustomValidity(''); // Clear potential previous error
                    }

                    form.classList.add('was-validated')
                }, false)
            })
    })()

    // Avatar preview
    const avatarInput = document.getElementById('avatar');
    const avatarPreview = document.getElementById('avatarPreview');
    if (avatarInput && avatarPreview) {
        avatarInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Basic client-side type check (optional, server-side is essential)
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('فرمت فایل نامعتبر است. لطفاً JPG، PNG یا GIF انتخاب کنید.');
                    avatarInput.value = ''; // Clear the input
                    return;
                }
                // Basic client-side size check (optional, server-side is essential)
                const maxSize = 5 * 1024 * 1024; // 5MB
                if (file.size > maxSize) {
                    alert('حجم فایل بیش از 5 مگابایت است.');
                    avatarInput.value = ''; // Clear the input
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(event) {
                    avatarPreview.src = event.target.result;
                }
                reader.readAsDataURL(file);
            } else {
                // If no file selected or selection cleared, maybe revert to original?
                // avatarPreview.src = '<?= escapeHtml($avatar_display_path) ?>'; // Revert if needed
            }
        });
    }
</script>

<?php
require_once __DIR__ . '/footer_common.php'; // Include the common footer
?>