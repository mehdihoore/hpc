<?php
// /public_html/ghom/api/save_workflow_order.php (CORRECTED)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';

secureSession();
if (!isLoggedIn()) {
    header('Location: /login.php?msg=login_required');
    exit();
}
if (!in_array($_SESSION['role'], ['admin', 'superuser'])) {
    http_response_code(403);
    require 'Access_Denied.php';
    exit;
}
if (!in_array($_SESSION['role'], ['admin', 'superuser'])) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => 'Access Denied']));
}

$data = json_decode(file_get_contents('php://input'), true);
if (empty($data) || !is_array($data)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid data.']));
}

$pdo = null;
try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO inspection_stages (template_id, stage, display_order) 
         VALUES (:template_id, :stage, :display_order)
         ON DUPLICATE KEY UPDATE display_order = VALUES(display_order)"
    );

    foreach ($data as $stage) {
        if (isset($stage['template_id'], $stage['stage_name'], $stage['display_order'])) {
            $stmt->execute([
                ':template_id'   => $stage['template_id'],
                ':stage'         => $stage['stage_name'], // The database column is 'stage'
                ':display_order' => $stage['display_order']
            ]);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'Order saved successfully.']);
} catch (Exception $e) {
    if ($pdo ?? false) $pdo->rollBack();
    error_log("Workflow Save Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}
