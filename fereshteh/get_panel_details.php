<?php
require_once __DIR__ . '/../../sercon/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Authorization check (same as in panels.php)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$panelId = $_GET['panel_id'] ?? null; // Get panel_id from the query string

if (!$panelId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid panel ID']);
    exit();
}

try {
    $pdo = connectDB();

    $stmt = $pdo->prepare("
        SELECT pd.*, u.username as user_name, s.username as supervisor_name
        FROM hpc_panel_details pd
        LEFT JOIN users u ON pd.user_id = u.id
        LEFT JOIN users s ON pd.supervisor_id = s.id
        WHERE pd.panel_id = ?
    ");
    $stmt->execute([$panelId]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // Convert the result into a key-value map, similar to panels.php
    $panelDetails = [];
    foreach ($details as $detail) {
        $panelDetails[$detail['step_name']] = $detail;
        // Fetch attachments for this detail
            $stmtAttachments = $pdo->prepare("SELECT * FROM hpc_panel_attachments WHERE panel_detail_id = ?");
            $stmtAttachments->execute([$detail['id']]);
            $panelDetails[$detail['step_name']]['attachments'] = $stmtAttachments->fetchAll(PDO::FETCH_ASSOC);
    }


    // Return the data as JSON
    header('Content-Type: application/json');
    echo json_encode($panelDetails);
    exit;


} catch (PDOException $e) {
    error_log($e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit();
}