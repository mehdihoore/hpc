<?php
// public_html/login.php
require_once __DIR__ . '/../sercon/bootstrap.php'; // Use the new bootstrap

secureSession(); // Initializes session and applies security measures

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // --- Input Sanitization ---
    // Consider using htmlspecialchars or a more robust library for output,
    // but for DB comparison, ensure it matches what's stored.
    // FILTER_SANITIZE_STRING is deprecated in PHP 8. Use htmlspecialchars for output.
    // For input to DB, often raw is fine if using prepared statements.
    // $username = filter_var($username, FILTER_SANITIZE_STRING); // Deprecated

    // --- CSRF Token Verification ---
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        logError("CSRF token mismatch in login.php. User: " . $username);
        $error = "درخواست نامعتبر است.";
    } else {
        try {
            $pdo = getCommonDBConnection(); // Connect to hpc_common for users and login_attempts

            $ip = $_SERVER['REMOTE_ADDR'];
            // Define LOGIN_LOCKOUT_TIME and LOGIN_ATTEMPTS_LIMIT in bootstrap.php or ensure they are available
            $lockout_time = defined('LOGIN_LOCKOUT_TIME') ? LOGIN_LOCKOUT_TIME : 900;
            $attempts_limit = defined('LOGIN_ATTEMPTS_LIMIT') ? LOGIN_ATTEMPTS_LIMIT : 5;

            $stmt = $pdo->prepare("SELECT COUNT(*) as attempts, MAX(attempt_time) as last_attempt
                                   FROM login_attempts
                                   WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([$ip, $lockout_time]);
            $result = $stmt->fetch();

            if ($result['attempts'] >= $attempts_limit) {
                $error = "تعداد تلاش‌های ورود بیش از حد مجاز است. لطفاً بعداً دوباره امتحان کنید.";
            } else {
                // Fetch user from hpc_common.users
                $stmt = $pdo->prepare("SELECT id, username, password_hash, role, is_active, first_name, last_name FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    if (!$user['is_active']) {
                        $error = "حساب کاربری شما در انتظار فعال‌سازی است.";
                    } else {
                        // Regenerate session ID *after* successful login and *before* setting sensitive session data:
                        if (session_status() == PHP_SESSION_ACTIVE) {
                            session_regenerate_id(true);
                            $_SESSION['last_regen'] = time(); // Update last_regen time
                        }

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['first_name'] = $user['first_name'];
                        $_SESSION['last_name'] = $user['last_name'];
                        $_SESSION['last_activity'] = time(); // Update last activity

                        // Clear login attempts from hpc_common.login_attempts
                        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                        $stmt->execute([$ip]);

                        // Log login activity to hpc_common.activity_log (project_id will be null for general login)
                        log_activity($user['id'], $user['username'], 'login', 'User logged in successfully');

                        // *** NEW REDIRECT ***
                        header('Location: select_project.php'); // Redirect to project selection page
                        exit();
                    }
                } else {
                    // Record failed attempt in hpc_common.login_attempts
                    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, attempt_time) VALUES (?, NOW())");
                    $stmt->execute([$ip]);
                    logError("Failed login attempt for username: " . $username . " from IP: " . $ip);
                    $error = "نام کاربری یا رمز عبور شما درست نیست.";
                }
            }
        } catch (PDOException $e) {
            logError("Database error in login.php: " . $e->getMessage());
            $error = "خطایی در پایگاه داده رخ داد! لطفاً بعداً دوباره تلاش کنید.";
        } catch (Exception $e) { // Catch other general exceptions from bootstrap
            logError("General error in login.php: " . $e->getMessage());
            $error = "یک خطای سیستمی رخ داده است. لطفاً با پشتیبانی تماس بگیرید.";
        }
    }
}

// --- CSRF Token Generation (for the form) ---
// secureSession() should have started the session
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به سیستم</title>
    <!-- Link to your shared assets -->
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/assets/images/favicon-96x96.png">
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
    <link rel="manifest" href="/assets/images/site.webmanifest">
    <link rel="stylesheet" href="/assets/css/login_styles.css"> <!-- Create a dedicated CSS file or use inline -->
    <style>
        /* Your existing login page CSS */
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir.eot');
            /* Make sure these paths are correct from web root */
            src: url('/assets/fonts/Vazir.eot?#iefix') format('embedded-opentype'),
                url('/assets/fonts/Vazir.woff2') format('woff2'),
                url('/assets/fonts/Vazir.woff') format('woff'),
                url('/assets/fonts/Vazir.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
        }

        /* ... (rest of your login CSS from previous example) ... */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Vazir', Tahoma, Arial, sans-serif;
            background: linear-gradient(135deg, #0a4d8c 0%, #042454 100%);
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            direction: rtl;
            overflow-x: hidden;
            position: relative;
        }

        .login-wrapper {
            display: flex;
            width: 80%;
            max-width: 1000px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        .login-form-container {
            flex: 1;
            padding: 40px;
            min-width: 320px;
        }

        .illustration-container {
            flex: 1.5;
            background: linear-gradient(135deg, #36a6ff 0%, #1a7bda 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .user-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            display: block;
            background-color: #0a4d8c;
            border-radius: 50%;
            padding: 15px;
        }

        h1 {
            font-size: 24px;
            color: #333;
            margin-bottom: 10px;
            text-align: center;
        }

        .login-subtitle {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
            text-align: center;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
        }

        .form-group input {
            width: 100%;
            padding: 12px 40px 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-family: 'Vazir', Tahoma, Arial, sans-serif;
            transition: border 0.3s;
        }

        .form-group input:focus {
            border-color: #0a4d8c;
            outline: none;
            box-shadow: 0 0 0 2px rgba(10, 77, 140, 0.1);
        }

        .input-icon {
            position: absolute;
            right: 15px;
            top: 40px;
            color: #aaa;
        }

        .btn-login {
            background: #0a4d8c;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 12px;
            width: 100%;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
            font-family: 'Vazir', Tahoma, Arial, sans-serif;
        }

        .btn-login:hover {
            background: #083b6a;
        }

        .register-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .register-link p {
            color: #666;
            font-size: 14px;
        }

        .register-link a {
            color: #0a4d8c;
            text-decoration: none;
            font-weight: bold;
        }

        .error {
            background-color: #fff2f2;
            color: #e74c3c;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-right: 4px solid #e74c3c;
            font-size: 14px;
            text-align: center;
        }

        .factory-svg {
            width: 100%;
            height: 100%;
        }

        @media (max-width: 768px) {
            .login-wrapper {
                flex-direction: column;
                width: 95%;
            }

            .illustration-container {
                height: 200px;
            }

            .login-form-container {
                padding: 30px 20px;
            }
        }
    </style>
</head>

<body>
    <div class="login-wrapper">
        <div class="login-form-container">
            <div class="user-avatar">
                <svg class="user-icon" viewBox="0 0 24 24" fill="white">
                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                </svg>
            </div>
            <h1>ورود به سیستم</h1>
            <p class="login-subtitle"> سامانه مدیریت آلومنیوم شیشه تهران </p>

            <?php if ($error): // Check if $error is set and not empty 
            ?>
                <div class="error"><?php echo escapeHtml($error); // Use escapeHtml from bootstrap 
                                    ?></div>
            <?php endif; ?>

            <form method="post" action="login.php"> <?php // Action can be empty or point to itself 
                                                    ?>
                <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="form-group">
                    <label for="username">نام کاربری</label>
                    <input type="text" id="username" name="username" required value="<?php echo escapeHtml($username ?? ''); ?>">
                    <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="#aaa">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                    </svg>
                </div>
                <div class="form-group">
                    <label for="password">رمز عبور</label>
                    <input type="password" id="password" name="password" required>
                    <svg class="input-icon" width="18" height="18" viewBox="0 0 24 24" fill="#aaa">
                        <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
                    </svg>
                </div>
                <button type="submit" class="btn-login">ورود</button>
            </form>
            <div class="register-link">
                <p>حساب کاربری ندارید؟ <a href="registration.php">ثبت نام کنید</a></p>
            </div>
        </div>
        <div class="illustration-container">
            <!-- Your SVG illustration -->
            <svg class="factory-svg" viewBox="0 0 800 600" xmlns="http://www.w3.org/2000/svg">
                <!-- Background gradient -->
                <defs>
                    <linearGradient id="bgGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#36a6ff" />
                        <stop offset="100%" stop-color="#1a7bda" />
                    </linearGradient>

                    <!-- Factory building pattern -->
                    <pattern id="gridPattern" width="20" height="20" patternUnits="userSpaceOnUse">
                        <rect width="20" height="20" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="1" />
                    </pattern>
                </defs>

                <!-- Background -->
                <rect x="0" y="0" width="800" height="600" fill="url(#bgGradient)" />

                <!-- Factory base elements -->
                <g id="factory" transform="translate(200, 100)">
                    <!-- Main factory building -->
                    <rect x="50" y="150" width="300" height="250" fill="#235a97" stroke="#1a4372" stroke-width="2" />
                    <rect x="60" y="160" width="280" height="240" fill="#2c6eb6" stroke="#1a4372" stroke-width="1" />
                    <rect x="60" y="160" width="280" height="240" fill="url(#gridPattern)" />

                    <!-- Factory roof -->
                    <polygon points="50,150 200,50 350,150" fill="#1a4372" stroke="#0c2340" stroke-width="2" />

                    <!-- ***** START: Corrected Background Rect and Factory Logo ***** -->

                    <!-- 1. Background Rectangle - Adjusted position and size -->
                    <rect id="factory-logo-bg"
                        x="80"
                        y="160"
                        width="150"
                        height="25"
                        fill="#33FFBB"
                        stroke="#1a4372"
                        stroke-width="3" />

                    <!-- 2. Image Element - Use same adjusted values -->
                    <image id="factory-logo-svg"
                        href="/assets/images/alumglass-farsi-logo-H40.png"
                        x="95"
                        y="160"
                        width="120"
                        height="25"
                        preserveAspectRatio="xMidYMid meet" />
                    <!-- ***** END: Corrected Background Rect and Factory Logo ***** -->
                    <!-- Chimney -->
                    <rect x="270" y="70" width="30" height="80" fill="#1a4372" stroke="#0c2340" stroke-width="2" />

                    <!-- Windows -->
                    <g id="windows">
                        <rect x="80" y="190" width="40" height="40" fill="#88ccff" stroke="#1a4372" stroke-width="2" rx="2" ry="2">
                            <animate attributeName="opacity" values="0.7;1;0.7" dur="3s" repeatCount="indefinite" />
                        </rect>
                        <rect x="140" y="190" width="40" height="40" fill="#88ccff" stroke="#1a4372" stroke-width="2" rx="2" ry="2">
                            <animate attributeName="opacity" values="0.8;1;0.8" dur="4s" repeatCount="indefinite" />
                        </rect>
                        <rect x="200" y="190" width="40" height="40" fill="#88ccff" stroke="#1a4372" stroke-width="2" rx="2" ry="2">
                            <animate attributeName="opacity" values="0.9;1;0.9" dur="5s" repeatCount="indefinite" />
                        </rect>
                        <rect x="260" y="190" width="40" height="40" fill="#88ccff" stroke="#1a4372" stroke-width="2" rx="2" ry="2">
                            <animate attributeName="opacity" values="0.7;1;0.7" dur="3.5s" repeatCount="indefinite" />
                        </rect>

                        <rect x="80" y="250" width="40" height="40" fill="#88ccff" stroke="#1a4372" stroke-width="2" rx="2" ry="2">
                            <animate attributeName="opacity" values="0.8;1;0.8" dur="4.5s" repeatCount="indefinite" />
                        </rect>
                        <rect x="140" y="250" width="40" height="40" fill="#88ccff" stroke="#1a4372" stroke-width="2" rx="2" ry="2">
                            <animate attributeName="opacity" values="0.9;1;0.9" dur="3s" repeatCount="indefinite" />
                        </rect>
                        <rect x="200" y="250" width="40" height="40" fill="#88ccff" stroke="#1a4372" stroke-width="2" rx="2" ry="2">
                            <animate attributeName="opacity" values="0.7;1;0.7" dur="4s" repeatCount="indefinite" />
                        </rect>
                        <rect x="260" y="250" width="40" height="40" fill="#88ccff" stroke="#1a4372" stroke-width="2" rx="2" ry="2">
                            <animate attributeName="opacity" values="0.8;1;0.8" dur="5s" repeatCount="indefinite" />
                        </rect>
                    </g>

                    <!-- Door -->
                    <rect x="170" y="330" width="60" height="70" fill="#1a4372" stroke="#0c2340" stroke-width="2" />
                    <rect x="175" y="335" width="50" height="65" fill="#0c2340" />
                    <circle cx="220" cy="365" r="3" fill="#aaccff" />

                    <!-- Smoke from chimney -->
                    <g id="smoke">
                        <circle cx="285" cy="65" r="8" fill="#ffffff" opacity="0.6">
                            <animate attributeName="cy" values="65;35;5;-25" dur="4s" repeatCount="indefinite" />
                            <animate attributeName="opacity" values="0.6;0.3;0" dur="4s" repeatCount="indefinite" />
                            <animate attributeName="r" values="8;12;16" dur="4s" repeatCount="indefinite" />
                        </circle>
                        <circle cx="285" cy="85" r="6" fill="#ffffff" opacity="0.5">
                            <animate attributeName="cy" values="85;55;25;-5" dur="4s" begin="1s" repeatCount="indefinite" />
                            <animate attributeName="opacity" values="0.5;0.3;0" dur="4s" begin="1s" repeatCount="indefinite" />
                            <animate attributeName="r" values="6;10;14" dur="4s" begin="1s" repeatCount="indefinite" />
                        </circle>
                    </g>
                </g>

                <!-- HPC Elements (servers, data connections) -->
                <g id="hpc-elements" transform="translate(300, 300)">
                    <!-- Main server rack -->
                    <rect x="150" y="-50" width="80" height="120" fill="#0c2340" stroke="#1a4372" stroke-width="2" rx="5" ry="5" />

                    <!-- Server units -->
                    <rect x="155" y="-45" width="70" height="15" fill="#235a97" stroke="#1a4372" stroke-width="1" rx="2" ry="2">
                        <animate attributeName="fill" values="#235a97;#3775c0;#235a97" dur="2s" repeatCount="indefinite" />
                    </rect>
                    <rect x="155" y="-25" width="70" height="15" fill="#235a97" stroke="#1a4372" stroke-width="1" rx="2" ry="2">
                        <animate attributeName="fill" values="#235a97;#3775c0;#235a97" dur="3s" repeatCount="indefinite" />
                    </rect>
                    <rect x="155" y="-5" width="70" height="15" fill="#235a97" stroke="#1a4372" stroke-width="1" rx="2" ry="2">
                        <animate attributeName="fill" values="#235a97;#3775c0;#235a97" dur="2.5s" repeatCount="indefinite" />
                    </rect>
                    <rect x="155" y="15" width="70" height="15" fill="#235a97" stroke="#1a4372" stroke-width="1" rx="2" ry="2">
                        <animate attributeName="fill" values="#235a97;#3775c0;#235a97" dur="1.5s" repeatCount="indefinite" />
                    </rect>
                    <rect x="155" y="35" width="70" height="15" fill="#235a97" stroke="#1a4372" stroke-width="1" rx="2" ry="2">
                        <animate attributeName="fill" values="#235a97;#3775c0;#235a97" dur="2s" repeatCount="indefinite" />
                    </rect>
                    <rect x="155" y="55" width="70" height="15" fill="#235a97" stroke="#1a4372" stroke-width="1" rx="2" ry="2">
                        <animate attributeName="fill" values="#235a97;#3775c0;#235a97" dur="3s" repeatCount="indefinite" />
                    </rect>

                    <!-- Blinking lights -->
                    <circle cx="165" cy="-37.5" r="2" fill="#ff0000">
                        <animate attributeName="opacity" values="1;0.3;1" dur="0.5s" repeatCount="indefinite" />
                    </circle>
                    <circle cx="165" cy="-17.5" r="2" fill="#00ff00">
                        <animate attributeName="opacity" values="1;0.3;1" dur="0.7s" repeatCount="indefinite" />
                    </circle>
                    <circle cx="165" cy="2.5" r="2" fill="#ff0000">
                        <animate attributeName="opacity" values="1;0.3;1" dur="0.3s" repeatCount="indefinite" />
                    </circle>
                    <circle cx="165" cy="22.5" r="2" fill="#00ff00">
                        <animate attributeName="opacity" values="1;0.3;1" dur="0.6s" repeatCount="indefinite" />
                    </circle>
                    <circle cx="165" cy="42.5" r="2" fill="#ff0000">
                        <animate attributeName="opacity" values="1;0.3;1" dur="0.4s" repeatCount="indefinite" />
                    </circle>
                    <circle cx="165" cy="62.5" r="2" fill="#00ff00">
                        <animate attributeName="opacity" values="1;0.3;1" dur="0.8s" repeatCount="indefinite" />
                    </circle>
                </g>

                <!-- Floating gear elements (representing manufacturing) -->

                <g id="gears">
                    <!-- Outer group for static scaling and translation -->
                    <g transform="translate(170, 170) scale(0.3)"> <!-- ADJUSTED SCALE (e.g., 0.2) -->

                        <!-- Inner group specifically for the rotation animation -->
                        <g>
                            <!-- Gear path data goes INSIDE the animation group -->
                            <g>
                                <g>
                                    <path d="m295.2,501h-78.3c-11.3,0-20.4-9.1-20.4-20.4v-29.5c-12.5-3.8-24.6-8.9-36.2-15.1l-20.9,20.9c-7.7,7.7-21.2,7.7-28.9,0l-55.4-55.4c-8-8-8-20.9 0-28.9l21-21c-6.1-11.5-11.1-23.6-14.9-36.1h-29.8c-11.3,0-20.4-9.1-20.4-20.4v-78.3c0-11.3 9.1-20.4 20.4-20.4h30.1c3.8-12.4 8.8-24.3 14.9-35.8l-21.3-21.3c-3.8-3.8-6-9-6-14.4 0-5.4 2.2-10.6 6-14.4l55.4-55.4c7.7-7.7 21.2-7.7 28.9,0l21.5,21.5c11.4-6.1 23.3-11 35.6-14.7v-30.5c0-11.3 9.1-20.4 20.4-20.4h78.3c11.3,0 20.4,9.1 20.4,20.4v30.4c12.3,3.8 24.2,8.7 35.6,14.7l21.5-21.5c8-8 20.9-8 28.9,0l55.4,55.4c3.8,3.8 6,9 6,14.4 0,5.4-2.2,10.6-6,14.4l-21.3,21.3c6.1,11.4 11.1,23.4 14.9,35.8h30.1c11.3,0 20.4,9.1 20.4,20.4v78.3c0,11.3-9.1,20.4-20.4,20.4h-29.8c-3.8,12.5-8.8,24.5-14.9,36.1l21,21c3.8,3.8 6,9 6,14.4 0,5.4-2.2,10.6-6,14.4l-55.4,55.4c-8,8-20.9,8-28.9,0l-20.9-20.9c-11.6,6.2-23.7,11.2-36.2,15.1v29.5c0,11.5-9.2,20.6-20.4,20.6zm-57.9-40.8h37.5v-24.8c0-9.6 6.7-17.9 16.1-19.9 18.9-4.1 36.8-11.6 53.2-22.1 8.1-5.2 18.7-4.1 25.5,2.7l17.6,17.6 26.5-26.5-17.7-17.7c-6.8-6.8-7.9-17.4-2.8-25.4 10.5-16.4 17.8-34.2 21.9-53.1 2-9.4 10.3-16.1 20-16.1h25.1v-37.5h-25.4c-9.6,0-17.9-6.7-19.9-16-4.1-18.8-11.5-36.6-22-52.8-5.2-8.1-4.1-18.7 2.7-25.5l18-18-26.5-26.5-18.1,17.9c-6.8,6.8-17.4,7.9-25.4,2.8-16.2-10.4-34-17.7-52.7-21.8-9.4-2-16.1-10.3-16.1-19.9v-25.8h-37.5v25.7c0,9.6-6.7,17.9-16.1,19.9-18.7,4.1-36.5,11.4-52.7,21.8-8.1,5.2-18.7,4-25.4-2.8l-18.1-18.1-26.5,26.5 18,18c6.8,6.8 7.9,17.4 2.7,25.5-10.5,16.2-17.9,34-22,52.8-2.1,9.4-10.4,16-19.9,16h-25.5v37.5h25.2c9.6,0 17.9,6.7 20,16.1 4.1,18.9 11.4,36.7 21.9,53.1 5.2,8.1 4,18.7-2.8,25.4l-17.7,17.7 26.5,26.5 17.6-17.6c6.8-6.8 17.4-7.9 25.5-2.7 16.4,10.5 34.2,18 53.2,22.1 9.4,2 16.1,10.3 16.1,19.9v25.1z" fill="#1a4372" stroke="#0c2340" stroke-width="2" />
                                </g>
                                <g>
                                    <path d="m256,377.1c-66.8,0-121.1-54.3-121.1-121.1 0-66.8 54.3-121.1 121.1-121.1 66.8,0 121.1,54.3 121.1,121.1 0,66.8-54.3,121.1-121.1,121.1zm0-201.4c-44.3,0-80.3,36-80.3,80.3 0,44.3 36,80.3 80.3,80.3 44.3,0 80.3-36 80.3-80.3 0-44.3-36-80.3-80.3-80.3z" fill="#0c2340" stroke="#1a4372" stroke-width="2" />
                                </g>
                            </g>

                            <!-- Animation applied to this inner group -->
                            <animateTransform attributeName="transform"
                                attributeType="XML"
                                type="rotate"
                                from="0 256 256"
                                to="360 256 256"
                                dur="20s"
                                repeatCount="indefinite" />
                        </g> <!-- End of inner animation group -->

                    </g> <!-- End of outer static transform group -->
                </g>

                <!-- Data lines representing HPC connections -->
                <g id="data-connections">
                    <!-- Data paths -->
                    <path d="M455,250 C480,230 500,270 525,250" stroke="#88ccff" stroke-width="2" fill="none">
                        <animate attributeName="stroke-dasharray" values="0,1000;1000,0" dur="10s" repeatCount="indefinite" />
                    </path>
                    <path d="M480,300 C505,280 525,320 550,300" stroke="#88ccff" stroke-width="2" fill="none">
                        <animate attributeName="stroke-dasharray" values="0,1000;1000,0" dur="8s" repeatCount="indefinite" />
                    </path>
                    <path d="M430,350 C455,330 475,370 500,350" stroke="#88ccff" stroke-width="2" fill="none">
                        <animate attributeName="stroke-dasharray" values="0,1000;1000,0" dur="12s" repeatCount="indefinite" />
                    </path>
                </g>

                <!-- Connection dots -->
                <circle cx="455" cy="250" r="4" fill="#00ff00">
                    <animate attributeName="opacity" values="1;0.5;1" dur="2s" repeatCount="indefinite" />
                </circle>
                <circle cx="525" cy="250" r="4" fill="#00ff00">
                    <animate attributeName="opacity" values="0.5;1;0.5" dur="2s" repeatCount="indefinite" />
                </circle>
                <circle cx="480" cy="300" r="4" fill="#00ff00">
                    <animate attributeName="opacity" values="0.7;1;0.7" dur="1.5s" repeatCount="indefinite" />
                </circle>
                <circle cx="550" cy="300" r="4" fill="#00ff00">
                    <animate attributeName="opacity" values="1;0.6;1" dur="1.5s" repeatCount="indefinite" />
                </circle>
                <circle cx="430" cy="350" r="4" fill="#00ff00">
                    <animate attributeName="opacity" values="0.6;1;0.6" dur="3s" repeatCount="indefinite" />
                </circle>
                <circle cx="500" cy="350" r="4" fill="#00ff00">
                    <animate attributeName="opacity" values="1;0.5;1" dur="3s" repeatCount="indefinite" />
                </circle>
            </svg>
        </div>
    </div>
</body>

</html>