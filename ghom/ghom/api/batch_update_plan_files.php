<?php
// /public_html/ghom/api/batch_update_plan_files.php (NEW FILE)

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';

secureSession();
if (!in_array($_SESSION['role'], ['admin', 'supervisor', 'superuser'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

$planFile = $data['plan_file'] ?? null;
$elementIds = $data['element_ids'] ?? [];

if (empty($planFile) || empty($elementIds)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Plan file and element IDs are required.']);
    exit();
}

try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    // This is an optimization: only update rows that actually need it.
    $stmt = $pdo->prepare(
        "UPDATE elements SET plan_file = :plan_file WHERE element_id = :element_id AND plan_file IS NULL"
    );

    $updated_count = 0;
    foreach ($elementIds as $elementId) {
        // The frontend sends the pure base ID, so no parsing is needed here.
        $stmt->execute([
            ':plan_file' => $planFile,
            ':element_id' => $elementId
        ]);
        if ($stmt->rowCount() > 0) {
            $updated_count++;
        }
    }

    $pdo->commit();
    echo json_encode([
        'status' => 'success',
        'message' => "Processed " . count($elementIds) . " elements for {$planFile}. Updated {$updated_count} records."
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    logError("API Error in batch_update_plan_files.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
