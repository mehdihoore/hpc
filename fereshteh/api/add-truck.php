<?php
// api/add-truck.php

$config_path = __DIR__ . '/../../../sercon/config.php';
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    die('Config file not found.');
}
require_once '../includes/functions.php';

secureSession();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superuser')) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

// Basic validation
if (!isset($input['truck_number'], $input['capacity'], $input['destination']) ||  // Destination is now required
    empty(trim($input['truck_number'])) ||
    !is_numeric($input['capacity']) ||
    $input['capacity'] <= 0 ||
    empty(trim($input['destination']))) { // Destination is required
    echo json_encode(['success' => false, 'message' => 'Invalid input data.']);
    exit();
}

$truckNumber = trim($input['truck_number']);
$driverName = $input['driver_name'] ?? null;
$driverPhone = $input['driver_phone'] ?? null;
$capacity = (int)$input['capacity'];
$destination = trim($input['destination']); // Get and trim destination

try {
    $pdo = connectDB();

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