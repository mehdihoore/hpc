<?php
//api/save_stands_assignments.php
ini_set('display_errors', 1); // Enable error display for debugging
error_reporting(E_ALL);
ob_start(); // Start output buffering to catch stray output/errors
require_once '/../../../bootstrap.php'; // Include configuration file
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
$report_key = 'new_panels'; // HARDCODED FOR THIS FILE
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/new_panels.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
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
if ($truck_id <= 0 || $shipment_id <= 0 || !isset($assignments) || !is_array($assignments)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid truck ID, shipment ID, or assignments data structure. Assignments must be an array.']);
    exit();
}

try {


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
