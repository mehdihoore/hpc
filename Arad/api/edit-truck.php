<?php
// api/edit-truck.php
$config_path = __DIR__ . '/../../../sercon/config_fereshteh.php';
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    die('Config file not found.');
}
require_once '../includes/functions.php';

secureSession();

// Check permissions
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superuser')) {
    header('HTTP/1.1 401 Unauthorized'); // Better HTTP status code
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json'); // Set header only once

$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['truck_id'], $input['truck_number'], $input['capacity']) ||
    !is_numeric($input['truck_id']) ||  // Ensure truck_id is numeric
    empty(trim($input['truck_number'])) ||
    !is_numeric($input['capacity']) ||
    $input['capacity'] <= 0) {
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
    $pdo = connectDB();

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
?>