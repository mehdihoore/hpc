<?php
// api/assign-panel-to-truck.php
$config_path = __DIR__ . '/../../../sercon/config_fereshteh.php';
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

if (!isset($input['panel_id'], $input['truck_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing panel_id or truck_id']);
    exit();
}

$panelId = (int)$input['panel_id'];
$truckId = (int)$input['truck_id'];

try {
    $pdo = connectDB();
    $pdo->beginTransaction();

    // Check if the panel is available (packing_status = 'pending' or NULL)
    $stmt = $pdo->prepare("SELECT id FROM hpc_panels WHERE id = ? AND (packing_status = 'pending' OR packing_status IS NULL)");
    $stmt->execute([$panelId]);
    if (!$stmt->fetch()) {
        throw new Exception("Panel is not available for assignment.");
    }

    // Check truck capacity
    $stmt = $pdo->prepare("SELECT t.capacity, COUNT(p.id) as current_count FROM trucks t LEFT JOIN hpc_panels p ON p.truck_id = t.id AND p.packing_status = 'assigned' WHERE t.id = ? GROUP BY t.id");
    $stmt->execute([$truckId]);
    $truck = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$truck) {
        throw new Exception("Truck not found");
    }

    if (($truck['current_count'] ?? 0) >= $truck['capacity']) {
        throw new Exception("Truck capacity exceeded");
    }

    // Update the panel's packing_status, truck_id, shipping_date, shipping_time, and user_id
    $stmt = $pdo->prepare("
        UPDATE hpc_panels
        SET packing_status = 'assigned', truck_id = ?, shipping_date = NULL, shipping_time = NULL, user_id = ?
        WHERE id = ?
    ");
    $stmt->execute([$truckId, $_SESSION['user_id'], $panelId]);

    if ($stmt->rowCount() > 0) {
        // Log the activity
        $stmt = $pdo->prepare("
            INSERT INTO activity_log 
            (user_id, username, activity_type, panel_id, step, action, details, timestamp) 
            VALUES (?, ?, 'panel_management', ?, 'assign', 'assign_to_truck', ?, NOW())
        ");

        $details = json_encode([
            'action' => 'Panel assigned to truck',
            'panel_id' => $panelId,
            'truck_id' => $truckId,
            'user_id' => $_SESSION['user_id']
        ]);
        $stmt->execute([$_SESSION['user_id'], $_SESSION['username'], $panelId, $details]);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } else {
        throw new Exception('Panel not found or update failed.');
    }

} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    logError("Error in assign-panel-to-truck.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>