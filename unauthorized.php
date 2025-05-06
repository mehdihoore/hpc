<?php
require_once __DIR__ . '/../sercon/config.php';

// No need to call secureSession() here, as we are already checking if session is set.

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
// No need to check for admin role here, as this page is shown if the user is NOT an admin.
?>
<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دسترسی غیرمجاز</title>
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
            height: 100vh;
            direction: rtl;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 400px;
        }
        h1 {
            color: #dc3545;
            margin-bottom: 20px;
        }
        p {
            color: #666;
            margin-bottom: 20px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>دسترسی غیرمجاز</h1>
        <p>متأسفیم، این صفحه فقط برای مدیران قابل دسترسی است.</p>
        <p>شما به عنوان: <?php echo htmlspecialchars($_SESSION['username']); ?> وارد شده‌اید.</p>
        <a href="logout.php" class="btn">خروج</a>
    </div>
</body>
</html>