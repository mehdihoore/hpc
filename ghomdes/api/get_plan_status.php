<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';

secureSession();
if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$planFile = filter_input(INPUT_GET, 'plan_file', FILTER_DEFAULT);
if (empty($planFile)) {
    http_response_code(400);
    echo json_encode(['error' => 'Plan file name is required.']);
    exit();
}

try {
    $pdo = getProjectDBConnection('ghom');

    // **THIS IS THE KEY QUERY**
    // It joins elements with their inspections based on the plan file
    $sql = "
        SELECT e.element_id, i.contractor_status
        FROM elements e
        LEFT JOIN inspections i ON e.element_id = i.element_id
        WHERE e.plan_file = ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$planFile]);

    $statuses = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Use the contractor_status, default to 'Not Started' if NULL
        $statuses[$row['element_id']] = $row['contractor_status'] ?? 'Not Started';
    }

    echo json_encode($statuses);
} catch (Exception $e) {
    logError("Plan Status API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error while fetching plan status.']);
}
