<?php
// /public_html/ghom/api/get_zone_statuses.php (CORRECTED)

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();

if (!in_array($_SESSION['role'], ['admin', 'supervisor', 'superuser'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

// FIX: Expect 'zone_file' which is sent by the JavaScript
$zoneFile = filter_input(INPUT_GET, 'zone_file', FILTER_DEFAULT);

if (empty($zoneFile)) {
    http_response_code(400);
    // Send back the expected error message
    echo json_encode(['error' => 'Zone file is required.']);
    exit();
}

try {
    $pdo = getProjectDBConnection('ghom');

    // FIX: The query now correctly joins on svg_file_name
    $stmt = $pdo->prepare("
        SELECT 
            e.element_id as simple_id, -- Get the simple ID from the elements table
            i.element_id as full_id,   -- Get the full ID from the inspections table
            i.contractor_status, 
            i.overall_status,
            i.contractor_date 
        FROM elements e
        LEFT JOIN inspections i ON SUBSTRING_INDEX(i.element_id, '-', 1) = e.element_id
        WHERE e.svg_file_name = ?
    ");
    $stmt->execute([$zoneFile]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusMap = [];
    $readyItemsList = [];
    foreach ($results as $row) {
        // Use the simple SVG ID as the key for the map, which is what the frontend needs
        $statusMap[$row['simple_id']] = [
            'contractor' => $row['contractor_status'],
            'overall' => $row['overall_status']
        ];

        // If an item is ready, add its full ID and date to the list
        if ($row['contractor_status'] === 'Ready for Inspection' && $row['overall_status'] !== 'OK') {
            $readyItemsList[] = [
                'id' => $row['full_id'],
                'date' => $row['contractor_date']
            ];
        }
    }

    echo json_encode(['statusMap' => $statusMap, 'readyList' => $readyItemsList]);
} catch (Exception $e) {
    logError("API Error in get_zone_statuses.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred.']);
}
