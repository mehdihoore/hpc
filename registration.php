<?php
require_once __DIR__ . '/../sercon/config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: upload_panel.php'); // Or wherever logged-in users should go
    exit();
}

$error = '';
$success = '';

// --- CSRF Token Generation (for GET requests) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- CSRF Check ---
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        logError("CSRF token mismatch in registration.php");
        $error = "Invalid request. Please try again."; // Don't expose CSRF details
    } else {
        // --- Input Sanitization (ALWAYS do this) ---
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? '');

         //Sanitize:
        $username = filter_var($username, FILTER_SANITIZE_STRING);
        $first_name = filter_var($first_name, FILTER_SANITIZE_STRING);
        $last_name = filter_var($last_name, FILTER_SANITIZE_STRING);
        $phone_number = filter_var($phone_number, FILTER_SANITIZE_STRING);


        // --- Input Validation (Comprehensive) ---
        if (strlen($username) < 3 || strlen($username) > 50) {
            $error = "نام کاربری باید بین ۳ تا ۵۰ کاراکتر باشد";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "لطفاً یک آدرس ایمیل معتبر وارد کنید";
        } elseif (strlen($password) < 8) {
            $error = "رمز عبور باید حداقل ۸ کاراکتر باشد";
        } elseif (!preg_match("/[A-Z]/", $password)) {
            $error = "رمز عبور باید حداقل یک حرف بزرگ داشته باشد";
        } elseif (!preg_match("/[a-z]/", $password)) {
            $error = "رمز عبور باید حداقل یک حرف کوچک داشته باشد";
        } elseif (!preg_match("/[0-9]/", $password)) {
            $error = "رمز عبور باید حداقل یک عدد داشته باشد";
        } elseif ($password !== $confirm_password) {
            $error = "رمزهای عبور مطابقت ندارند";
        } elseif (empty($first_name) || empty($last_name)) {
            $error = "لطفاً نام و نام خانوادگی خود را وارد کنید.";
        } elseif (strlen($first_name) > 255 || strlen($last_name) > 255) {
            $error = 'نام و نام خانوادگی نباید بیشتر از ۲۵۵ کاراکتر باشد.';
        } elseif (strlen($phone_number) > 20) {
            $error = 'شماره تلفن نباید بیشتر از ۲۰ کاراکتر باشد.';
        } else {
          try{
                $pdo = connectDB(); // Use PDO

                // Check if username already exists (using PDO)
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]); // Use execute() with an array
                if ($stmt->fetch()) { // Use fetch() with PDO
                    $error = "نام کاربری قبلاً ثبت شده است";
                } else {
                    // Check if email already exists (using PDO)
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]); // Use execute() with an array
                    if ($stmt->fetch()) { // Use fetch() with PDO
                        $error = "ایمیل قبلاً ثبت شده است";
                    } else {
                         // Hash the password *before* storing it:
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);

                        // Create new user (using PDO)
                        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, phone_number, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, $phone_number]);

                        if ($stmt->rowCount() > 0) {  // Check if row was inserted
                            $success = "ثبت نام با موفقیت انجام شد! لطفاً منتظر تأیید مدیر باشید.";
                            header("refresh:2;url=login.php"); // redirect after 2 second
                        } else {
                            $error = "ثبت نام انجام نشد. لطفاً دوباره تلاش کنید.";
                        }
                    }
                }

            } catch (PDOException $e) {
                 logError("Database error in registration.php: " . $e->getMessage());
                 $error = "An error occurred. Please try again later.";
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
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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