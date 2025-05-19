<?php
// api/edit-truck.php
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


if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

$current_user_id = $_SESSION['user_id']; // Get current user ID
$report_key = 'edit-truck'; // HARDCODED FOR THIS FILE
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/api/edit-truck.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superuser')) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}


header('Content-Type: application/json'); // Set header only once

$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (
    !isset($input['truck_id'], $input['truck_number'], $input['capacity']) ||
    !is_numeric($input['truck_id']) ||  // Ensure truck_id is numeric
    empty(trim($input['truck_number'])) ||
    !is_numeric($input['capacity']) ||
    $input['capacity'] <= 0
) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid required fields.']);
    exit();
}

$truckId = (int)$input['truck_id']; // Cast to integer (already done, but good practice)
$truckNumber = trim($input['truck_number']);
$driverName = $input['driver_name'] ?? null; // Use null coalescing operator
$driverPhone = $input['driver_phone'] ?? null;
$capacity = (int)$input['capacity']; // Cast to integer
$destination = $input['destination'] ?? null;

try {


    // 1. Check if the truck exists
    $stmt = $pdo->prepare("SELECT * FROM trucks WHERE id = ?");
    $stmt->execute([$truckId]);
    $existingTruck = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingTruck) {
        echo json_encode(['success' => false, 'message' => 'Truck not found.']);
        exit();
    }

    // 2. Check for changes (Optional, but good for efficiency)
    $updateNeeded = false;
    if ($truckNumber !== $existingTruck['truck_number']) $updateNeeded = true;
    if ($driverName !== $existingTruck['driver_name']) $updateNeeded = true;
    if ($driverPhone !== $existingTruck['driver_phone']) $updateNeeded = true;
    if ($capacity !== (int)$existingTruck['capacity']) $updateNeeded = true;  // Ensure int comparison
    if ($destination !== $existingTruck['destination']) $updateNeeded = true;

    // 3. Update only if needed
    if ($updateNeeded) {
        $stmt = $pdo->prepare("
            UPDATE trucks
            SET truck_number = ?, driver_name = ?, driver_phone = ?, capacity = ?, destination = ?
            WHERE id = ?
        ");
        $stmt->execute([$truckNumber, $driverName, $driverPhone, $capacity, $destination, $truckId]);
    }

    echo json_encode(['success' => true, 'message' => 'Truck updated successfully.']);
} catch (PDOException $e) {
    logError("Database error in edit-truck.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
