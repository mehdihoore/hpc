<?php
// profile.php
require_once __DIR__ . '/../sercon/config.php';
require_once 'includes/functions.php';

secureSession();
$requested_user_id = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['user_id'];

// Check if user has permission to view this profile
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); // Redirect to login page if not logged in
    exit();
}

// If user is not a superuser and trying to view someone else's profile
if ($_SESSION['role'] !== 'superuser' && $_SESSION['user_id'] != $requested_user_id) {
    // Redirect them to their own profile
    header('Location: profile.php'); // This will use default which is their own ID
    exit();
}

$pageTitle = 'ویرایش پروفایل';
require_once 'header.php';

$message = '';
$error = '';

// Define allowed image types and max size
$allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
$max_size = 5 * 1024 * 1024; // 5MB
$upload_dir = 'uploads/avatars/';

// Create upload directory if it doesn't exist
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

try {
    $pdo = connectDB();
    
    // Fetch current user data
    $stmt = $pdo->prepare("SELECT username, email, first_name, last_name, phone_number, avatar_path FROM users WHERE id = ?");
    $stmt->execute([$requested_user_id]);  // Use $requested_user_id here
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        die("خطا در بازیابی اطلاعات کاربر.");
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle avatar upload
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar'];
            
            // Validate file type
            $file_type = mime_content_type($file['tmp_name']);
            if (!in_array($file_type, $allowed_types)) {
                $error = "فرمت فایل مجاز نیست. لطفاً از فرمت‌های JPG، PNG یا GIF استفاده کنید.";
            }
            // Validate file size
            elseif ($file['size'] > $max_size) {
                $error = "حجم فایل بیش از حد مجاز است (حداکثر 5 مگابایت).";
            }
            else {
                // Generate unique filename
                $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $new_filename = uniqid('avatar_') . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;
                
                // Move and process uploaded file
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    // Delete old avatar if exists
                    if ($user['avatar_path'] && file_exists($user['avatar_path'])) {
                        unlink($user['avatar_path']);
                    }
                    
                    // Update database with new avatar path
                    $updateAvatar = $pdo->prepare("UPDATE users SET avatar_path = ? WHERE id = ?");
                    $updateAvatar->execute([$upload_path, $requested_user_id]);
                    $user['avatar_path'] = $upload_path;
                    $message = "تصویر پروفایل با موفقیت به‌روزرسانی شد.";
                } else {
                    $error = "خطا در آپلود فایل.";
                }
            }
        }

        // Rest of the profile update logic
        $first_name = filter_var(trim($_POST['first_name']), FILTER_SANITIZE_STRING);
        $last_name = filter_var(trim($_POST['last_name']), FILTER_SANITIZE_STRING);
        $phone_number = filter_var(trim($_POST['phone_number']), FILTER_SANITIZE_STRING);
        $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        $updateFields = [];
        $params = [];
        
        // Validate email change
        if ($email && $email !== $user['email']) {
            $checkEmail = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
            $checkEmail->execute([$email, $requested_user_id]);
            if ($checkEmail->fetchColumn() > 0) {
                $error = "این ایمیل قبلاً ثبت شده است.";
            } else {
                $updateFields[] = "email = ?";
                $params[] = $email;
            }
        }
        
        // Add other fields to update
        if ($first_name !== $user['first_name']) {
            $updateFields[] = "first_name = ?";
            $params[] = $first_name;
        }
        
        if ($last_name !== $user['last_name']) {
            $updateFields[] = "last_name = ?";
            $params[] = $last_name;
        }
        
        if ($phone_number !== $user['phone_number']) {
            $updateFields[] = "phone_number = ?";
            $params[] = $phone_number;
        }
        
        // Handle password change
        if (!empty($new_password)) {
            $verifyStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $verifyStmt->execute([$requested_user_id]);
            $current_hash = $verifyStmt->fetchColumn();
            
            if (!password_verify($current_password, $current_hash)) {
                $error = "رمز عبور فعلی اشتباه است.";
            } elseif ($new_password !== $confirm_password) {
                $error = "رمز عبور جدید و تکرار آن مطابقت ندارند.";
            } elseif (strlen($new_password) < 8) {
                $error = "رمز عبور جدید باید حداقل ۸ کاراکتر باشد.";
            } else {
                $updateFields[] = "password_hash = ?";
                $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            }
        }
        
        // Update user data if there are changes and no errors
        if (!empty($updateFields) && empty($error)) {
            $params[] = $requested_user_id;
            $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
            
            $updateStmt = $pdo->prepare($sql);
            if ($updateStmt->execute($params)) {
                $message = "پروفایل شما با موفقیت به‌روزرسانی شد.";
                // Refresh user data
                $stmt->execute([$requested_user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error = "خطا در به‌روزرسانی پروفایل.";
            }
        }
    }
    
} catch (PDOException $e) {
    logError("Database error in profile.php: " . $e->getMessage());
    $error = "خطا در ارتباط با پایگاه داده.";
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
    <style>
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
        }
        
        body {
            font-family: 'Vazir', sans-serif;
            background-color: #f8f9fa;
        }
        
        .profile-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .avatar-container {
            text-align: center;
            margin-bottom: 2rem;
        }

        .avatar-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 1rem;
            border: 3px solid #e9ecef;
        }

        .avatar-upload {
            position: relative;
            margin: 1rem auto;
        }
    </style>
</head>
<body>
    <div class="profile-container">
        <h1 class="mb-4"><?= $pageTitle ?></h1>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?= $message ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST" class="needs-validation" enctype="multipart/form-data" novalidate>
            <div class="avatar-container">
                <img src="<?= $user['avatar_path'] ?: 'assets/images/default-avatar.jpg' ?>" 
                     alt="تصویر پروفایل" 
                     class="avatar-preview" 
                     id="avatarPreview">
                
                <div class="avatar-upload">
                    <label for="avatar" class="form-label">تغییر تصویر پروفایل</label>
                    <input type="file" 
                           class="form-control" 
                           id="avatar" 
                           name="avatar" 
                           accept="image/jpeg,image/png,image/gif">
                    <div class="form-text">حداکثر حجم فایل: 5 مگابایت. فرمت‌های مجاز: JPG، PNG، GIF</div>
                </div>
            </div>

            <!-- Rest of the form fields remain the same -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="username" class="form-label">نام کاربری</label>
                    <input type="text" class="form-control" id="username" value="<?= escapeHtml($user['username']) ?>" disabled>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">ایمیل</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= escapeHtml($user['email']) ?>" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="first_name" class="form-label">نام</label>
                    <input type="text" class="form-control" id="first_name" name="first_name" value="<?= escapeHtml($user['first_name']) ?>">
                </div>
                <div class="col-md-6">
                    <label for="last_name" class="form-label">نام خانوادگی</label>
                    <input type="text" class="form-control" id="last_name" name="last_name" value="<?= escapeHtml($user['last_name']) ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label for="phone_number" class="form-label">شماره تلفن</label>
                <input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?= escapeHtml($user['phone_number']) ?>">
            </div>
            
            <hr class="my-4">
            
            <h3 class="mb-3">تغییر رمز عبور</h3>
            <div class="mb-3">
                <label for="current_password" class="form-label">رمز عبور فعلی</label>
                <input type="password" class="form-control" id="current_password" name="current_password">
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="new_password" class="form-label">رمز عبور جدید</label>
                    <input type="password" class="form-control" id="new_password" name="new_password">
                </div>
                <div class="col-md-6">
                    <label for="confirm_password" class="form-label">تکرار رمز عبور جدید</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                </div>
            </div>
            
            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
                <a href="profile.php" class="btn btn-secondary">لغو</a>
            </div>
        </form>
    </div>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }
                        form.classList.add('was-validated')
                    }, false)
                })
        })()

        // Avatar preview
        document.getElementById('avatar').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('avatarPreview').src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>