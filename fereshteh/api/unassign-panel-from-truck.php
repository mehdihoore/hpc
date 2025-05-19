<?php
// api/unassign-panel-from-truck.php

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
