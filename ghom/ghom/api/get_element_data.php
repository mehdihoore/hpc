<?php
// /public_html/ghom/api/get_element_data.php (FINAL, COMPLETE VERSION)

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
require_once __DIR__ . '/../includes/jdf.php'; // For jdate function

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isLoggedIn() || ($_SESSION['current_project_config_key'] ?? '') !== 'ghom') {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}

$fullElementId = filter_input(INPUT_GET, 'element_id', FILTER_DEFAULT);
$elementType = filter_input(INPUT_GET, 'element_type', FILTER_DEFAULT);

if (empty($fullElementId) || empty($elementType)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Element ID and Type are required']));
}

try {
    $pdo = getProjectDBConnection('ghom');

    // 1. PARSE THE INCOMING ID
    $baseElementId = $fullElementId;
    $partName = null;
    $parts = explode('-', $fullElementId);
    $valid_parts = ['face', 'up', 'down', 'left', 'right', 'default'];
    if (count($parts) > 1 && in_array(strtolower(end($parts)), $valid_parts)) {
        $partName = array_pop($parts);
        $baseElementId = implode('-', $parts);
    }

    // 2. GET THE CHECKLIST TEMPLATE (STAGES & ITEMS)
    // This query gets the full structure of the required checklist.
    $stmt_template = $pdo->prepare("
        SELECT t.template_id, s.stage_id, s.stage AS stage_name, i.item_id, i.item_text
        FROM checklist_templates t
        JOIN inspection_stages s ON t.template_id = s.template_id
        JOIN checklist_items i ON t.template_id = i.template_id AND s.stage = i.stage
        WHERE t.element_type = ?
        ORDER BY s.display_order, i.item_order
    ");
    $stmt_template->execute([$elementType]);
    $template_flat = $stmt_template->fetchAll(PDO::FETCH_ASSOC);

    // Structure the flat data into a nested array
    $template_structured = [];
    if ($template_flat) {
        foreach ($template_flat as $row) {
            // If the stage doesn't exist in our structure yet, create it
            if (!isset($template_structured[$row['stage_id']])) {
                $template_structured[$row['stage_id']] = [
                    'stage_id' => $row['stage_id'],
                    'stage_name' => $row['stage_name'],
                    'items' => []
                ];
            }
            // Add the item to the correct stage
            $template_structured[$row['stage_id']]['items'][] = [
                'item_id' => $row['item_id'],
                'item_text' => $row['item_text']
            ];
        }
    }

    // 3. GET THE FULL INSPECTION HISTORY FOR THIS ELEMENT PART
    $stmt_history = $pdo->prepare(
        "SELECT * FROM inspections WHERE element_id = ? AND part_name <=> ? ORDER BY created_at DESC"
    );
    $stmt_history->execute([$baseElementId, $partName]);
    $history_raw = $stmt_history->fetchAll(PDO::FETCH_ASSOC);

    $history_with_items = [];
    if ($history_raw) {
        $stmt_items = $pdo->prepare("SELECT * FROM inspection_data WHERE inspection_id = ?");
        foreach ($history_raw as $inspection) {
            // Convert dates to Jalali for display
            if (!empty($inspection['inspection_date'])) {
                $inspection['inspection_date_jalali'] = jdate('Y/m/d', strtotime($inspection['inspection_date']));
            }
            if (!empty($inspection['contractor_date'])) {
                $inspection['contractor_date_jalali'] = jdate('Y/m/d', strtotime($inspection['contractor_date']));
            }

            // Get the checklist answers for this specific historical inspection
            $stmt_items->execute([$inspection['inspection_id']]);
            $inspection['items'] = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
            $history_with_items[] = $inspection;
        }
    }

    // 4. SEND THE FINAL JSON RESPONSE
    echo json_encode([
        'template' => array_values($template_structured), // The checklist structure
        'history'  => $history_with_items // The full history of past inspections
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("API Error in get_element_data.php: " . $e->getMessage());
    exit(json_encode(['error' => 'Database query failed.', 'details' => $e->getMessage()]));
}
