<?php
// /public_html/ghom/api/get_plan_elements.php (WORKFLOW-AWARE VERSION)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();

$plan_file = $_GET['plan'] ?? null;
if (empty($plan_file)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Plan file parameter is required.']));
}

try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->exec("SET NAMES 'utf8mb4'");

    // This complex query finds the most recent, definitive status for each element.
    $sql = "
        WITH LatestInspections AS (
            SELECT 
                i.element_id,
                i.overall_status,
                i.contractor_status,
                ROW_NUMBER() OVER(PARTITION BY i.element_id, i.part_name ORDER BY i.created_at DESC, i.inspection_id DESC) as rn
            FROM inspections i
            JOIN elements e ON i.element_id = e.element_id
            WHERE e.plan_file = ?
        ),
        AggregatedStatus AS (
            SELECT
                element_id,
                -- The final status is determined by priority: Not OK > OK > Ready for Inspection > Pending
                MIN(
                    CASE 
                        WHEN overall_status = 'Not OK' THEN 1
                        WHEN overall_status = 'OK' THEN 2
                        WHEN contractor_status = 'Ready for Inspection' THEN 3
                        ELSE 4 
                    END
                ) as status_priority
            FROM LatestInspections
            WHERE rn = 1
            GROUP BY element_id
        )
        SELECT
            e.element_id, e.element_type, e.floor_level, e.axis_span,
            e.width_cm, e.height_cm, e.area_sqm, e.contractor, e.block, e.zone_name,
            CASE ag.status_priority
                WHEN 1 THEN 'Not OK'
                WHEN 2 THEN 'OK'
                WHEN 3 THEN 'Ready for Inspection'
                ELSE 'Pending'
            END as final_status
        FROM elements e
        LEFT JOIN AggregatedStatus ag ON e.element_id = ag.element_id
        WHERE e.plan_file = ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$plan_file, $plan_file]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $elements_with_data = [];
    foreach ($results as $row) {
        $elements_with_data[$row['element_id']] = [
            'type' => $row['element_type'],
            'floor' => $row['floor_level'],
            'axis' => $row['axis_span'],
            'width' => $row['width_cm'],
            'height' => $row['height_cm'],
            'area' => $row['area_sqm'],
            'status' => $row['final_status'] ?? 'Pending', // Fallback to Pending
            'contractor' => $row['contractor'],
            'block' => $row['block'],
            'zoneName' => $row['zone_name']
        ];
    }
    echo json_encode($elements_with_data);
} catch (Exception $e) {
    http_response_code(500);
    error_log("API Error get_plan_elements.php: " . $e->getMessage());
    exit(json_encode(['error' => 'Database query failed.']));
}
