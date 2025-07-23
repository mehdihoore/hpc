<?php
// /ghom/api/get_stages.php (FINAL)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();

$element_type = $_GET['type'] ?? null;
if (!$element_type) {
    exit(json_encode([]));
} // Return empty array if no type

try {
    $pdo = getProjectDBConnection('ghom');
    $stmt = $pdo->prepare(
        "SELECT s.stage_id, s.stage FROM inspection_stages s
         JOIN checklist_templates t ON s.template_id = t.template_id
         WHERE t.element_type = ? ORDER BY s.display_order ASC"
    );
    $stmt->execute([$element_type]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    exit(json_encode(['error' => 'Database error.']));
}
