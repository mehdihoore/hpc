<?php
// save_preferences.php
require_once __DIR__ . '/../../sercon/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
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

// Connect to database
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error']);
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
