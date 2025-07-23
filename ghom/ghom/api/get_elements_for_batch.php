<?php
// /public_html/ghom/api/get_elements_for_batch.php (NEW FILE)

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';

secureSession();
if (!in_array($_SESSION['role'], ['admin', 'supervisor', 'superuser'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$planFile = filter_input(INPUT_GET, 'plan_file', FILTER_DEFAULT);
$elementType = filter_input(INPUT_GET, 'element_type', FILTER_DEFAULT);

if (empty($planFile)) {
    http_response_code(400);
    echo json_encode(['error' => 'Plan File is required']);
    exit();
}

try {
    $pdo = getProjectDBConnection('ghom');

    $sql = "SELECT element_id, element_type, axis_span, floor_level, contractor, block, plan_file FROM elements WHERE plan_file = :plan_file";
    $params = [':plan_file' => $planFile];

    if (!empty($elementType)) {
        $sql .= " AND element_type = :element_type";
        $params[':element_type'] = $elementType;
    }

    $sql .= " ORDER BY element_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $elements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($elements);
} catch (Exception $e) {
    logError("API Error in get_elements_for_batch.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred.']);
}
