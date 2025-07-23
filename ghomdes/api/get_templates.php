<?php
//ghom/api/get_templates.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();
// Admin check can be added here as well
try {
    $pdo = getProjectDBConnection('ghom');
    $stmt = $pdo->query("SELECT template_id, template_name, element_type FROM checklist_templates ORDER BY template_name");
    echo json_encode($stmt->fetchAll());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
