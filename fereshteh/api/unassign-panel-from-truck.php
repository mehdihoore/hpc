<?php
// api/unassign-panel-from-truck.php

// --- Includes and Setup ---
$config_path = __DIR__ . '/../../../sercon/config.php'; // Adjust path
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    die('Config file not found.');
}
require_once '../includes/functions.php';

secureSession();
// --- Role Check ---
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superuser'])) { // Example roles
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['panel_id']) || !filter_var($input['panel_id'], FILTER_VALIDATE_INT)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or missing Panel ID']);
    exit();
}

$panelId = (int)$input['panel_id'];
$userId = $_SESSION['user_id']; // Get user ID for logging

try {
    $pdo = connectDB();

    // ---> Start Transaction <---
    $pdo->beginTransaction();

    // 1. Update the panel itself
    $stmt_panel = $pdo->prepare("UPDATE hpc_panels SET truck_id = NULL, packing_status = 'pending' ,shipping_date = NULL , shipping_time = NULL WHERE id = ?");
    $stmt_panel->execute([$panelId]);
    $panel_rows_affected = $stmt_panel->rowCount();

    // ---> 2. Delete related stand assignments <---
    $stmt_stands = $pdo->prepare("DELETE FROM panel_stand_assignments WHERE panel_id = ?");
    $stmt_stands->execute([$panelId]);
    $stand_rows_affected = $stmt_stands->rowCount(); // How many stand assignments were deleted

    // ---> Commit Transaction <---
    $pdo->commit();

    // Log the action
    logError("Panel Unassigned: Panel ID {$panelId} unassigned from truck. {$stand_rows_affected} stand assignments removed. User ID: {$userId}");

    // Return success - Frontend JS will handle UI update
    // No need to send back all data with fetchAllTruckPanelData
    echo json_encode([
        'success' => true,
        'message' => 'Panel unassigned successfully.',
        'panel_rows_affected' => $panel_rows_affected, // Optional info for frontend
        'stand_rows_affected' => $stand_rows_affected  // Optional info for frontend
    ]);
} catch (PDOException $e) {
    // ---> Rollback Transaction on Error <---
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logError("Database error in unassign-panel-from-truck.php for panel {$panelId}: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error during unassignment.']);
} catch (Exception $e) {
    // ---> Rollback Transaction on general Error <---
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logError("General error in unassign-panel-from-truck.php for panel {$panelId}: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred during unassignment.']);
}
?>
