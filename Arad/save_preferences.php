<?php
// save_preferences.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once 'includes/jdf.php';
secureSession();
$expected_project_key = 'arad'; // HARDCODED FOR THIS FILE
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
// --- Authentication ---
$allroles = ['admin', 'supervisor', 'planner', 'cnc_operator', 'superuser', 'user']; // Adjust as needed
// Define roles that can VIEW this page
$viewRoles = ['admin', 'supervisor', 'planner', 'user', 'superuser']; // Example: Allow users to view
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $viewRoles)) {
    // Redirect to login or an access denied page
    header('Location: login.php');
    exit('Access Denied.');
}
$current_user_id = $_SESSION['user_id'];
// --- End Authorization ---
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/hpc_panels_manager.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// Validate input
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    empty($_POST['page']) ||
    empty($_POST['preference_type']) ||
    !isset($_POST['preferences'])
) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}



$user_id = $_SESSION['user_id'];
$page = $_POST['page'];
$preference_type = $_POST['preference_type'];
$preferences = $_POST['preferences'];

// Save preferences
try {
    // Check if preference exists
    $stmt = $pdo->prepare("SELECT id FROM user_preferences WHERE user_id = ? AND page = ? AND preference_type = ?");
    $stmt->execute([$user_id, $page, $preference_type]);
    $exists = $stmt->fetch();

    if ($exists) {
        // Update existing preference
        $stmt = $pdo->prepare("UPDATE user_preferences SET preferences = ?, updated_at = NOW() WHERE user_id = ? AND page = ? AND preference_type = ?");
        $stmt->execute([$preferences, $user_id, $page, $preference_type]);
    } else {
        // Insert new preference
        $stmt = $pdo->prepare("INSERT INTO user_preferences (user_id, page, preference_type, preferences, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$user_id, $page, $preference_type, $preferences]);
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error saving preferences: ' . $e->getMessage()]);
}
