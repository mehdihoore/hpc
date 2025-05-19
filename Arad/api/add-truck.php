<?php
// api/add-truck.php

ini_set('display_errors', 1); // Enable error display for debugging
error_reporting(E_ALL);
ob_start(); // Start output buffering to catch stray output/errors
require __DIR__ . '/../../../sercon/bootstrap.php';
require_once  __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/jdf.php';

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


if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

$current_user_id = $_SESSION['user_id']; // Get current user ID
$report_key = 'save-truck-schedule'; // HARDCODED FOR THIS FILE
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/api/save-truck-schedule.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superuser')) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

// Basic validation
if (
    !isset($input['truck_number'], $input['capacity'], $input['destination']) ||  // Destination is now required
    empty(trim($input['truck_number'])) ||
    !is_numeric($input['capacity']) ||
    $input['capacity'] <= 0 ||
    empty(trim($input['destination']))
) { // Destination is required
    echo json_encode(['success' => false, 'message' => 'Invalid input data.']);
    exit();
}

$truckNumber = trim($input['truck_number']);
$driverName = $input['driver_name'] ?? null;
$driverPhone = $input['driver_phone'] ?? null;
$capacity = (int)$input['capacity'];
$destination = trim($input['destination']); // Get and trim destination

try {


    // No need to check for duplicate truck numbers anymore

    // --- Destination Handling ---
    // Insert the destination into the 'destinations' table (if it doesn't exist)
    $stmt = $pdo->prepare("SELECT id FROM destinations WHERE name = ?");
    $stmt->execute([$destination]);
    $existingDest = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingDest) {
        $stmt = $pdo->prepare("INSERT INTO destinations (name, created_by) VALUES (?, ?)");
        $stmt->execute([$destination, $_SESSION['user_id']]);
        // We don't actually *need* the destination ID here, but it's good to have the logic.
    }

    // Insert the new truck, including the destination
    $stmt = $pdo->prepare("
        INSERT INTO trucks (truck_number, driver_name, driver_phone, capacity, destination, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$truckNumber, $driverName, $driverPhone, $capacity, $destination, $_SESSION['user_id']]);

    // Get the ID of the newly inserted truck
    $truckId = $pdo->lastInsertId();

    // Fetch the newly created truck (to return it in the response)
    $stmt = $pdo->prepare("SELECT * FROM trucks WHERE id = ?");
    $stmt->execute([$truckId]);
    $newTruck = $stmt->fetch(PDO::FETCH_ASSOC);


    // Return success and the new truck's data
    echo json_encode(['success' => true, 'message' => 'Truck added successfully.', 'truck' => $newTruck]);
} catch (PDOException $e) {
    logError("Database error in add-truck.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
