<?php
session_start(); // Start the session

// --- LOG LOGOUT ACTIVITY ---
require_once __DIR__ . '/../sercon/config.php';
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
        ]
    );
   if (isset($_SESSION['user_id'], $_SESSION['username'])) { // Check if logged in.
        $logStmt = $pdo->prepare("INSERT INTO activity_log (user_id, username, activity_type) VALUES (?, ?, 'logout')");
        $logStmt->execute([$_SESSION['user_id'], $_SESSION['username']]);
   }
} catch (PDOException $e) {
     // Log the error, don't expose it to the user
     error_log("Database error in logout: " . $e->getMessage());
     // Optionally show a generic error to the user
}
// --- END LOG ACTIVITY ---
// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to the login page (or any other page you want)
header('Location: login.php'); // Replace 'login.php' with your login page
exit();
?>