<?php
// /ghom/api/check_existing_for_stage.php (NEW FILE)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();

$data = json_decode(file_get_contents('php://input'), true);
$element_ids = $data['element_ids'] ?? [];
$stage_id = $data['stage_id'] ?? null;

if (empty($element_ids) || empty($stage_id)) {
    exit(json_encode(['count' => 0]));
}

try {
    $pdo = getProjectDBConnection('ghom');

    // Using FIND_IN_SET is a safe way to check against a list without complex placeholders
    $sql = "SELECT COUNT(DISTINCT element_id) FROM inspections 
            WHERE stage_id = ? AND FIND_IN_SET(element_id, ?)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$stage_id, implode(',', $element_ids)]);
    $count = $stmt->fetchColumn();

    echo json_encode(['count' => $count ?: 0]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("API Error in check_existing_for_stage.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database error.']);
}
