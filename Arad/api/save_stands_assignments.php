<?php
$config_path = __DIR__ . '/../../../sercon/config_fereshteh.php'; // Adjust path
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    die('Config file not found.');
}
secureSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superuser')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON data from the request
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['truck_id']) || !isset($input['shipment_id']) || !isset($input['assignments'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid data provided']);
    exit();
}

$truck_id = (int)$input['truck_id'];
$shipment_id = (int)$input['shipment_id'];
$assignments = $input['assignments'];

// Validate inputs
if ($truck_id <= 0 || $shipment_id <= 0 || empty($assignments)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid truck, shipment or assignments']);
    exit();
}

try {
    $pdo = connectDB();
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Delete existing assignments for this truck and shipment
    $stmt_delete = $pdo->prepare("
        DELETE FROM panel_stand_assignments 
        WHERE truck_id = ? AND shipment_id = ?
    ");
    $stmt_delete->execute([$truck_id, $shipment_id]);
    
    // Insert new assignments
    $stmt_insert = $pdo->prepare("
        INSERT INTO panel_stand_assignments 
        (panel_id, stand_id, truck_id, shipment_id, position, created_by) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($assignments as $assignment) {
        $panel_id = (int)$assignment['panel_id'];
        $stand_id = (int)$assignment['stand_id'];
        $position = (int)$assignment['position'];
        
        // Verify that the panel belongs to this truck
        $stmt_verify = $pdo->prepare("
            SELECT id FROM hpc_panels WHERE id = ? AND truck_id = ?
        ");
        $stmt_verify->execute([$panel_id, $truck_id]);
        $panel = $stmt_verify->fetch(PDO::FETCH_ASSOC);
        
        if (!$panel) {
            throw new Exception("Panel ID $panel_id does not belong to truck ID $truck_id");
        }
        
        $stmt_insert->execute([
            $panel_id,
            $stand_id,
            $truck_id,
            $shipment_id,
            $position,
            $_SESSION['user_id']
        ]);
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Return success response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Panel assignments saved successfully',
        'count' => count($assignments)
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logError("Error saving panel assignments: " . $e->getMessage());
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
