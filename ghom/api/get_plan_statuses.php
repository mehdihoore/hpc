<?php
// /public_html/ghom/api/get_plan_statuses.php (FIXED N/A KEY WARNING)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';

secureSession();
if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

$plan_file = $_GET['plan'] ?? null;
if (empty($plan_file)) {
    http_response_code(400);
    echo json_encode(['error' => 'Plan file parameter is required.']);
    exit();
}

try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->exec("SET NAMES 'utf8mb4'");

    $stmt = $pdo->prepare(
        "SELECT i.element_id, i.overall_status, i.contractor_status 
         FROM inspections i 
         WHERE i.element_id IN (SELECT element_id FROM elements WHERE plan_file = ?)"
    );
    $stmt->execute([$plan_file]);
    $all_inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $element_statuses = [];
    $priority_map = ['Not OK' => 1, 'OK' => 2, 'Ready for Inspection' => 3, 'Pending' => 4];

    foreach ($all_inspections as $inspection) {
        // --- THIS IS THE CORRECTED LOGIC ---
        $status = 'Pending'; // Default status

        // 1. Check for a meaningful overall_status from the consultant ('OK' or 'Not OK')
        if (!empty($inspection['overall_status']) && $inspection['overall_status'] !== 'N/A') {
            $status = $inspection['overall_status'];
        }
        // 2. If no consultant status, check if the contractor has marked it as ready
        elseif ($inspection['contractor_status'] === 'Ready for Inspection') {
            $status = 'Ready for Inspection';
        }
        // 3. Otherwise, it remains 'Pending'

        $elementId = $inspection['element_id'];

        // Get the priority of the current final status for this element
        $current_priority = isset($element_statuses[$elementId]) ? $priority_map[$element_statuses[$elementId]] : 99;

        // Get the priority of the status from the current inspection record
        $new_priority = $priority_map[$status];

        // If the new status has a higher priority (a lower number), it becomes the final status for the element
        if ($new_priority < $current_priority) {
            $element_statuses[$elementId] = $status;
        }
    }

    echo json_encode($element_statuses);
} catch (Exception $e) {
    http_response_code(500);
    error_log("API Error in get_plan_statuses.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database query failed.']);
}
