<?php
// public_html/registration.php
require_once __DIR__ . '/../sercon/bootstrap.php'; // Use the new bootstrap

initializeSession(); // Initialize session for CSRF and other uses. Call before any session access.
// secureSession(); // You can call secureSession() if you want the full security checks on this page too,
// but initializeSession() is enough for CSRF if user is not logged in yet.

// If user is already logged in, redirect them away from registration.
// The target should ideally be the project selection page or their dashboard if a project is already selected.
if (isLoggedIn()) {
    if (isset($_SESSION['current_project_id']) && isset($_SESSION['current_project_base_path'])) {

        header('Location: ' . rtrim($_SESSION['current_project_base_path'], '/') . '/admin_panel_search.php');
    } else {
        header('Location: select_project.php'); // No project selected yet
    }
    exit();
}

$error = '';
$success = '';
$username_val = ''; // To repopulate form
$email_val = '';
$first_name_val = '';
$last_name_val = '';
$phone_number_val = '';

// --- CSRF Token Generation (for GET requests, if not already set by initializeSession) ---
// initializeSession() should handle starting the session.
// The CSRF token is generated for the form on the login page (GET request).
// For registration page, it should also be generated on GET if not present.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- CSRF Check ---
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        logError("CSRF token mismatch in registration.php");
        $error = "درخواست نامعتبر است. لطفاً دوباره تلاش کنید.";
    } else {
        // --- Input Retrieval ---
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? ''; // Keep raw for password checks
        $confirm_password = $_POST['confirm_password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? '');

        // Store for repopulating form on error
        $username_val = $username;
        $email_val = $email;
        $first_name_val = $first_name;
        $last_name_val = $last_name;
        $phone_number_val = $phone_number;

        // --- Input Validation (Comprehensive) ---
        if (mb_strlen($username) < 3 || mb_strlen($username) > 50) { // Use mb_strlen for multibyte strings
            $error = "نام کاربری باید بین ۳ تا ۵۰ کاراکتر باشد.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "لطفاً یک آدرس ایمیل معتبر وارد کنید.";
        } elseif (strlen($password) < 8) { // strlen is fine for password byte length
            $error = "رمز عبور باید حداقل ۸ کاراکتر باشد.";
        } elseif (!preg_match("/[A-Z]/", $password)) {
            $error = "رمز عبور باید حداقل یک حرف بزرگ لاتین داشته باشد.";
        } elseif (!preg_match("/[a-z]/", $password)) {
            $error = "رمز عبور باید حداقل یک حرف کوچک لاتین داشته باشد.";
        } elseif (!preg_match("/[0-9]/", $password)) {
            $error = "رمز عبور باید حداقل یک عدد داشته باشد.";
        } elseif (!preg_match('/[\'^£$%&*()}{@#~?><>,|=_+¬-]/', $password)) { // Optional: Special character
            $error = "رمز عبور باید حداقل یک کاراکتر خاص داشته باشد.";
        } elseif ($password !== $confirm_password) {
            $error = "رمزهای عبور مطابقت ندارند.";
        } elseif (empty($first_name) || empty($last_name)) {
            $error = "لطفاً نام و نام خانوادگی خود را وارد کنید.";
        } elseif (mb_strlen($first_name) > 100 || mb_strlen($last_name) > 100) { // Adjusted length, use mb_strlen
            $error = 'نام و نام خانوادگی نباید بیشتر از ۱۰۰ کاراکتر باشد.';
        } elseif (!empty($phone_number) && !preg_match('/^[0-9\-\+\(\) ]{7,20}$/', $phone_number)) { // Basic phone validation
            $error = 'شماره تلفن وارد شده معتبر نیست.';
        } else {
            try {
                // All users are registered in the hpc_common database
                $pdo = getCommonDBConnection();

                // Check if username already exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $error = "این نام کاربری قبلاً ثبت شده است.";
                } else {
                    // Check if email already exists
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $error = "این ایمیل قبلاً ثبت شده است.";
                    } else {
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                        $defaultRole = 'guest'; // Or 'user' - new users are 'guest' or 'user' and inactive by default.
                        // Admins will activate them and assign a proper role.

                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, phone_number, role, is_active, created_at)
                                               VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())"); // is_active = 0 (false)
                        $stmt->execute([
                            $username,
                            $email,      // Already sanitized with FILTER_SANITIZE_EMAIL
                            $password_hash,
                            $first_name, // Prepared statements handle further SQL injection risk
                            $last_name,
                            $phone_number,
                            $defaultRole
                        ]);

                        if ($stmt->rowCount() > 0) {
                            $new_user_id = $pdo->lastInsertId();

                            // OPTIONAL: Automatically assign to a default project (e.g., Fereshteh project_id = 1)
                            // This requires an admin to have set up project_id 1 in the 'projects' table.
                            /*
                            $default_project_id_for_new_users = 1; // Example: Fereshteh
                            $stmt_assign = $pdo->prepare("INSERT IGNORE INTO user_projects (user_id, project_id) VALUES (?, ?)");
                            $stmt_assign->execute([$new_user_id, $default_project_id_for_new_users]);
                            */

                            $pdo->commit();

                            log_activity(null, $username, 'register_success', "User registered, pending activation. ID: " . $new_user_id);
                            $success = "ثبت نام با موفقیت انجام شد! لطفاً منتظر تأیید مدیر برای فعال‌سازی حساب خود باشید.";
                            // Clear form values on success
                            $username_val = $email_val = $first_name_val = $last_name_val = $phone_number_val = '';

                            // Consider redirecting after a delay or showing a success message on the same page.
                            // header("refresh:3;url=login.php");
                            // exit();
                        } else {
                            $pdo->rollBack();
                            $error = "ثبت نام انجام نشد. لطفاً دوباره تلاش کنید.";
                            logError("User registration failed for: " . $username);
                        }
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                logError("Database error in registration.php: " . $e->getMessage());
                $error = "خطایی در پایگاه داده رخ داد. لطفاً بعداً تلاش کنید.";
            } catch (Exception $e) { // Catch other general exceptions
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                logError("General error in registration.php: " . $e->getMessage());
                $error = "یک خطای سیستمی رخ داده است.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ثبت نام - HPC Factory</title>
    <style>
        @font-face {
            font-family: 'Vazir';
            src: url('https://cdn.jsdelivr.net/gh/rastikerdar/vazir-font/Vazir.woff2') format('woff2');
        }

        body {
            font-family: 'Vazir', Arial, sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            direction: rtl;
        }

        .register-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            margin: 20px;
        }

        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
        }

        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .btn {
            background: #007bff;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 4px;
            width: 100%;
            cursor: pointer;
            font-size: 16px;
        }

        .btn:hover {
            background: #0056b3;
        }

        .error {
            color: #dc3545;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #dc3545;
            border-radius: 4px;
            background: #f8d7da;
        }

        .success {
            color: #28a745;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #28a745;
            border-radius: 4px;
            background: #d4edda;
        }

        .password-requirements {
            font-size: 0.9em;
            color: #666;
            margin-top: 5px;
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
        }

        .login-link a {
            color: #007bff;
            text-decoration: none;
        }

        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="register-container">
        <h1>ایجاد حساب کاربری</h1>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="post" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-group">
                <label for="username">نام کاربری</label>
                <input type="text" id="username" name="username" required
                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                <div class="password-requirements">باید بین ۳ تا ۵۰ کاراکتر باشد</div>
            </div>

            <div class="form-group">
                <label for="first_name">نام</label>
                <input type="text" id="first_name" name="first_name" required
                    value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="last_name">نام خانوادگی</label>
                <input type="text" id="last_name" name="last_name" required
                    value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="phone_number">شماره تلفن</label>
                <input type="text" id="phone_number" name="phone_number"
                    value="<?php echo isset($_POST['phone_number']) ? htmlspecialchars($_POST['phone_number']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="email">ایمیل</label>
                <input type="email" id="email" name="email" required
                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="password">رمز عبور</label>
                <input type="password" id="password" name="password" required>
                <div class="password-requirements">
                    باید حداقل ۸ کاراکتر، یک حرف بزرگ، یک حرف کوچک و یک عدد داشته باشد
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password">تأیید رمز عبور</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit" class="btn">ثبت نام</button>
        </form>

        <div class="login-link">
            قبلاً ثبت‌نام کرده‌اید؟ <a href="login.php">ورود به حساب کاربری</a>
        </div>
    </div>
</body>

</html>