<?php
// /public_html/ghom/api/get_element_data.php (REVISED AND SIMPLIFIED)

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
require_once __DIR__ . '/../includes/jdf.php';

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

    // Step 1: Get all user names to enrich the history log later
    $users_stmt = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) FROM hpc_common.users");
    $user_map = $users_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Step 2: Parse the element ID
    $baseElementId = $fullElementId;
    $partName = null;
    $parts = explode('-', $fullElementId);
    if (count($parts) > 1 && in_array(strtolower(end($parts)), ['face', 'up', 'down', 'left', 'right', 'default'])) {
        $partName = array_pop($parts);
        $baseElementId = implode('-', $parts);
    }

    // Step 3: Get the checklist template (stages and items)
    $stmt_template = $pdo->prepare("
        SELECT s.stage_id, s.stage AS stage_name, i.item_id, i.item_text
        FROM checklist_templates t
        JOIN inspection_stages s ON t.template_id = s.template_id
        JOIN checklist_items i ON t.template_id = i.template_id AND s.stage = i.stage
        WHERE t.element_type = ?
        ORDER BY s.display_order, i.item_order
    ");
    $stmt_template->execute([$elementType]);
    $template_flat = $stmt_template->fetchAll(PDO::FETCH_ASSOC);

    $template_structured = [];
    foreach ($template_flat as $row) {
        if (!isset($template_structured[$row['stage_id']])) {
            $template_structured[$row['stage_id']] = [
                'stage_id' => $row['stage_id'],
                'stage_name' => $row['stage_name'],
                'items' => []
            ];
        }
        $template_structured[$row['stage_id']]['items'][] = [
            'item_id' => $row['item_id'],
            'item_text' => $row['item_text']
        ];
    }

    // Step 4: Get ALL inspection records for this specific element/part
    $stmt_history = $pdo->prepare(
        "SELECT * FROM inspections WHERE element_id = ? AND part_name <=> ?"
    );
    $stmt_history->execute([$baseElementId, $partName]);
    $all_inspections = $stmt_history->fetchAll(PDO::FETCH_ASSOC);

    // Step 5: Process the records and enrich the history_log
    $processed_history = [];
    foreach ($all_inspections as $inspection) {
        // Enrich the history_log with user names
        $history_log = json_decode($inspection['history_log'] ?? '[]', true);
        if (is_array($history_log)) {
            foreach ($history_log as &$entry) {
                $userId = $entry['user_id'] ?? null;
                $entry['user_display_name'] = $user_map[$userId] ?? 'کاربر ناشناس';
            }
        }
        $inspection['history_log'] = $history_log; // Now it's an array, not a string

        // Add Jalali dates
        if (!empty($inspection['inspection_date'])) {
            $inspection['inspection_date_jalali'] = jdate("Y/m/d", strtotime($inspection['inspection_date']));
        }
        if (!empty($inspection['contractor_date'])) {
            $inspection['contractor_date_jalali'] = jdate("Y/m/d", strtotime($inspection['contractor_date']));
        }

        // Fetch checklist item data for the LATEST state
        $stmt_items = $pdo->prepare("SELECT * FROM inspection_data WHERE inspection_id = ?");
        $stmt_items->execute([$inspection['inspection_id']]);
        $inspection['items'] = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        $processed_history[] = $inspection;
    }

    // Final JSON Response
    echo json_encode([
        'template' => array_values($template_structured),
        'history'  => $processed_history
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("API Error in get_element_data.php: " . $e->getMessage());
    exit(json_encode(['error' => 'Database query failed.', 'details' => $e->getMessage()]));
}
