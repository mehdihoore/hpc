<?php
// ghom/api/get_element_data.php (FINAL CORRECTED VERSION)

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
require_once __DIR__ . '/jdf.php';

secureSession();
if (!isLoggedIn() || !isset($_SESSION['current_project_config_key']) || $_SESSION['current_project_config_key'] !== 'ghom') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$fullElementId = filter_input(INPUT_GET, 'element_id', FILTER_DEFAULT);
$elementType = filter_input(INPUT_GET, 'element_type', FILTER_DEFAULT);

if (empty($fullElementId) || empty($elementType)) {
    http_response_code(400);
    echo json_encode(['error' => 'Element ID and Type are required']);
    exit();
}

try {
    $pdo = getProjectDBConnection('ghom');

    // --- BUG FIX: ADDED THE CORRECT PARSING LOGIC ---
    // This logic must exactly match the logic in `save_inspection.php`.
    $baseElementId = $fullElementId;
    $partName = null;
    $parts = explode('-', $fullElementId);

    // Define the valid part names in lowercase for case-insensitive matching
    $valid_parts = ['face', 'top-face', 'bottom-face', 'left-face', 'right-face', 'up', 'down', 'left', 'right', 'default'];

    if (count($parts) > 1 && in_array(strtolower(end($parts)), $valid_parts)) {
        // If the last part is valid, pop it off to be used as `part_name`
        $partName = array_pop($parts);
        // The rest is the base ID
        $baseElementId = implode('-', $parts);
    } else {
        // This is a non-GFRC element (like Glass), so part_name is NULL
        $partName = null;
    }
    // --- END OF BUG FIX ---

    // Now, find the inspection using the correctly parsed base ID and part name.
    // The `<=>` operator is NULL-safe, which is crucial here.
    $stmt_inspection = $pdo->prepare(
        "SELECT * FROM inspections WHERE element_id = ? AND part_name <=> ? ORDER BY inspection_id DESC LIMIT 1"
    );
    $stmt_inspection->execute([$baseElementId, $partName]);
    $inspection = $stmt_inspection->fetch(PDO::FETCH_ASSOC);

    // Convert Gregorian dates from DB to Jalali for display in the form
    if ($inspection) {
        if (!empty($inspection['inspection_date']) && function_exists('jdate')) {
            $inspection['inspection_date_jalali'] = jdate('Y/m/d', strtotime($inspection['inspection_date']));
        }
        if (!empty($inspection['contractor_date']) && function_exists('jdate')) {
            $inspection['contractor_date_jalali'] = jdate('Y/m/d', strtotime($inspection['contractor_date']));
        }
    }

    // Get the checklist template questions for the given element type.
    $stmt_template = $pdo->prepare("SELECT template_id FROM checklist_templates WHERE element_type = ? AND is_active = TRUE LIMIT 1");
    $stmt_template->execute([$elementType]);
    $templateInfo = $stmt_template->fetch(PDO::FETCH_ASSOC);

    $items = [];
    if ($templateInfo) {
        if ($inspection) {
            // If an inspection exists, get its saved data, joined with the template questions and stages.
            $stmt_items = $pdo->prepare("
                SELECT ci.item_id, ci.item_text, ci.stage, id.item_status, id.item_value 
                FROM checklist_items ci 
                LEFT JOIN inspection_data id ON ci.item_id = id.item_id AND id.inspection_id = :inspection_id 
                WHERE ci.template_id = :template_id 
                ORDER BY ci.item_order, ci.item_id
            ");
            $stmt_items->execute([':inspection_id' => $inspection['inspection_id'], ':template_id' => $templateInfo['template_id']]);
            $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // If no inspection exists yet, get the blank questions from the template.
            $stmt_template_items = $pdo->prepare("
                SELECT item_id, item_text, stage, 'N/A' as item_status, '' as item_value 
                FROM checklist_items 
                WHERE template_id = ? 
                ORDER BY item_order, item_id
            ");
            $stmt_template_items->execute([$templateInfo['template_id']]);
            $items = $stmt_template_items->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    echo json_encode([
        'elementId' => $fullElementId,
        'inspectionData' => $inspection ?: null,
        'items' => $items
    ]);
} catch (Exception $e) {
    logError("API Error in get_element_data.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred.']);
}
