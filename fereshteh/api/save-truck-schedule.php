<?php
// api/save-truck-schedule.php


$config_path = __DIR__ . '/../../../sercon/config.php';
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    die('Config file not found.');
}
require_once '../includes/jdf.php';
require_once '../includes/functions.php';

secureSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superuser')) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

 if (isset($input['shipping_date_persian']) && !empty($input['shipping_date_persian'])) {
      $dateParts = explode('/', $input['shipping_date_persian']);
      if(count($dateParts) === 3){
        $gregorianDate = jalali_to_gregorian((int)$dateParts[0], (int)$dateParts[1], (int)$dateParts[2]);
        $input['shipping_date'] = $gregorianDate[0] . '-' . $gregorianDate[1] . '-' . $gregorianDate[2];
      }
    }

// Basic validation (including checking if the user_id is a valid integer)
if (
    !isset($input['truck_id'], $input['shipping_date'], $input['shipping_time'], $input['destination'], $input['status'])
    || !is_numeric($input['truck_id']) // Basic type checking
) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid required fields']);
    exit();
}

try {
    $pdo = connectDB();
    $shipmentId = isset($input['shipment_id']) ? (int)$input['shipment_id'] : null;

    if ($shipmentId) {
        // UPDATE existing shipment (No change to packing list number on update)
        $stmt = $pdo->prepare("
            UPDATE shipments
            SET shipping_date = ?, shipping_time = ?, destination = ?, status = ?, notes = ?, created_by = ?
            WHERE id = ? AND truck_id = ?
        ");
        $stmt->execute([
            $input['shipping_date'],
            $input['shipping_time'],
            $input['destination'],
            $input['status'],
            $input['notes'] ?? null,
            $_SESSION['user_id'],
            $shipmentId,
            $input['truck_id']
        ]);

         if ($stmt->rowCount() == 0) {
            echo json_encode(['success' => false, 'message' => 'Shipment not found or does not belong to the specified truck.']);
            exit();
        }
    } else {
        // INSERT new shipment
        // 1. Check if the truck already has a shipment
        $stmt = $pdo->prepare("SELECT id FROM shipments WHERE truck_id = ?");
        $stmt->execute([$input['truck_id']]);

        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Truck already has a scheduled shipment.']);
            exit();
        }

        // 2. Get the next packing list number (SAFELY)
        $stmt = $pdo->prepare("SELECT MAX(packing_list_number) AS max_packing_list_number FROM shipments");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextPackingListNumber = ($result['max_packing_list_number'] === null) ? 1 : (int)$result['max_packing_list_number'] + 1;


        // 3. Insert the new shipment WITH the packing list number
        $stmt = $pdo->prepare("
            INSERT INTO shipments (truck_id, shipping_date, shipping_time, destination, status, notes, created_by, packing_list_number)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $input['truck_id'],
            $input['shipping_date'],
            $input['shipping_time'],
            $input['destination'],
            $input['status'],
            $input['notes'] ?? null,
            $_SESSION['user_id'],
            $nextPackingListNumber // Include the packing list number
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Shipment saved successfully']);

} catch (PDOException $e) {
    logError("Database error in save-truck-shipment.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.  See server logs for details.']);
}
?>