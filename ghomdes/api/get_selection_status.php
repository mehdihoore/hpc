<?php
// /public_html/ghom/api/get_selection_status.php (NEW FILE)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();

$element_ids = json_decode(file_get_contents('php://input'), true)['element_ids'] ?? [];

if (empty($element_ids)) {
    exit(json_encode([]));
}

try {
    $pdo = getProjectDBConnection('ghom');
    $placeholders = implode(',', array_fill(0, count($element_ids), '?'));

    // This query finds the highest-ordered stage that has been marked "OK" for each element
    $sql = "
        SELECT 
            i.element_id, 
            MAX(ws.display_order) as last_passed_order
        FROM inspections i
        JOIN inspection_stages ws ON i.stage_id = ws.stage_id
        WHERE i.element_id IN ($placeholders) AND i.status = 'OK'
        GROUP BY i.element_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($element_ids);
    $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode($results);
} catch (Exception $e) {
    http_response_code(500);
    error_log("API Error in get_selection_status.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database error.']);
}
