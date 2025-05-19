<?php
//api/get-destinations.php
// --- Configuration and Functions ---
ini_set('display_errors', 1); // Enable error display for debugging
error_reporting(E_ALL);
ob_start(); // Start output buffering to catch stray output/errors
require __DIR__ . '/../../../sercon/bootstrap.php';
require_once  __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/jdf.php';

secureSession();
$expected_project_key = 'fereshteh'; // HARDCODED FOR THIS FILE
$current_project_config_key = $_SESSION['current_project_config_key'] ?? null;

if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}
if ($current_project_config_key !== $expected_project_key) {
    logError("Concrete test manager accessed with incorrect project context. Session: {$current_project_config_key}, Expected: {$expected_project_key}, User: {$_SESSION['user_id']}");
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}




$current_user_id = $_SESSION['user_id']; // Get current user ID
$report_key = 'get-destinations'; // HARDCODED FOR THIS FILE
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/api/get-destinations.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superuser')) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

try {


    // Get distinct destinations from the DESTINATIONS table
    $stmt = $pdo->query("SELECT name FROM destinations ORDER BY name");
    $destinations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['success' => true, 'destinations' => $destinations]);
} catch (PDOException $e) {
    logError("Database error in get-destinations.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
