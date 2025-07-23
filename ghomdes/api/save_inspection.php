<?php
// /public_html/ghom/api/save_inspection.php (WORKFLOW-COMPLIANT VERSION)

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
require_once __DIR__ . '/../includes/jdf.php';

// --- Helper Function to convert Jalali date string to Gregorian for DB ---
function toGregorian($jalaliDate)
{
    if (empty($jalaliDate) || !is_string($jalaliDate)) return null;
    $parts = explode('/', trim($jalaliDate));
    if (count($parts) !== 3) return null;
    if (function_exists('jalali_to_gregorian')) {
        return implode('-', jalali_to_gregorian((int)$parts[0], (int)$parts[1], (int)$parts[2]));
    }
    return null;
}

// --- Main execution starts ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid request method.']));
}

secureSession();
if (!isLoggedIn()) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => 'Forbidden']));
}

$pdo = null;
try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    $fullElementId = $_POST['elementId'];
    $userRole = $_SESSION['role'];
    $userId = $_SESSION['user_id'];
    $stagesData = json_decode($_POST['stages'] ?? '[]', true);

    if (empty($stagesData)) {
        throw new Exception("No stage data was submitted.");
    }

    // --- Parse Element ID and Part Name ---
    $baseElementId = $fullElementId;
    $partName = null;
    $parts = explode('-', $fullElementId);
    $valid_parts = ['face', 'up', 'down', 'left', 'right', 'default'];
    if (count($parts) > 1 && in_array(strtolower(end($parts)), $valid_parts)) {
        $partName = array_pop($parts);
        $baseElementId = implode('-', $parts);
    }

    // Prepare the main insertion statement for the 'inspections' table
    $stmt_inspection = $pdo->prepare(
        "INSERT INTO inspections (
            element_id, part_name, stage_id, user_id, 
            overall_status, inspection_date,
            contractor_status, contractor_date,
            created_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    // Prepare the statement for the checklist item answers
    $stmt_items = $pdo->prepare(
        "INSERT INTO inspection_data (inspection_id, item_id, item_status, item_value) 
         VALUES (?, ?, ?, ?)"
    );

    // --- Loop through each stage submitted from the form ---
    foreach ($stagesData as $stageId => $stageData) {

        // Always insert a new record for this submission
        $stmt_inspection->execute([
            $baseElementId,
            $partName,
            $stageId,
            $userId,
            $stageData['overall_status'] ?? null,
            toGregorian($stageData['inspection_date'] ?? null),
            $stageData['contractor_status'] ?? null,
            toGregorian($stageData['contractor_date'] ?? null),
        ]);

        $inspectionId = $pdo->lastInsertId();

        // Save the detailed checklist answers for this new inspection record
        if ($inspectionId && !empty($stageData['items'])) {
            foreach ($stageData['items'] as $item) {
                $stmt_items->execute([
                    $inspectionId,
                    $item['itemId'],
                    $item['status'] ?? 'N/A',
                    $item['value'] ?? ''
                ]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'اطلاعات با موفقیت ذخیره شد.']);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    error_log("API Save Error in save_inspection.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
}
