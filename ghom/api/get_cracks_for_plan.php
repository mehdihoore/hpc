<?php
// /ghom/api/get_cracks_for_plan.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();

if (!isLoggedIn()) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}

$plan_file = $_GET['plan'] ?? null;
if (!$plan_file) {
    http_response_code(400);
    exit(json_encode(['error' => 'Plan file is required.']));
}

try {
    $pdo = getProjectDBConnection('ghom');

    // This query finds all saved drawings for elements within the specified plan file.
    // It also fetches the geometry_json needed to correctly position the drawings.
    $sql = "
        SELECT 
            i.element_id, 
            e.geometry_json,
            id.item_value as drawing_json
        FROM inspection_data id
        JOIN inspections i ON id.inspection_id = i.inspection_id
        JOIN elements e ON i.element_id = e.element_id
        WHERE e.plan_file = ?
        AND id.item_value LIKE '{%\"version\"%}' -- Efficiently finds Fabric.js JSON
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$plan_file]);

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    http_response_code(500);
    exit(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
}
