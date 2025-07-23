<?php
header('Content-Type: application/json');
// *** CORRECTED PATH: ../../../ goes from api -> ghom -> public_html -> project root
require_once __DIR__ . '/../../../sercon/bootstrap.php';

secureSession();
if (!isLoggedIn() || $_SESSION['current_project_config_key'] !== 'ghom') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$elementId = filter_input(INPUT_GET, 'element_id', FILTER_DEFAULT);
$elementType = filter_input(INPUT_GET, 'element_type', FILTER_DEFAULT);

if (!$elementId || !$elementType) {
    http_response_code(400);
    echo json_encode(['error' => 'Element ID and Element Type are required']);
    exit();
}

try {
    $pdo = getProjectDBConnection('ghom');

    // Upsert Element: Create the element record if it doesn't exist yet.
    // This happens when a user clicks an element for the first time.
    $stmt_upsert = $pdo->prepare("
        INSERT INTO elements (element_id, element_type, zone_name, axis_span, floor_level, contractor, block)
        VALUES (:element_id, :element_type, :zone_name, :axis_span, :floor_level, :contractor, :block)
        ON DUPLICATE KEY UPDATE element_id = VALUES(element_id) -- A no-op to prevent errors on existing keys
    ");
    $stmt_upsert->execute([
        ':element_id'   => $elementId,
        ':element_type' => $elementType,
        ':zone_name'    => htmlspecialchars(trim((string)filter_input(INPUT_GET, 'zone_name', FILTER_DEFAULT)), ENT_QUOTES, 'UTF-8'),
        ':axis_span'    => htmlspecialchars(trim((string)filter_input(INPUT_GET, 'axis_span', FILTER_DEFAULT)), ENT_QUOTES, 'UTF-8'),
        ':floor_level'  => htmlspecialchars(trim((string)filter_input(INPUT_GET, 'floor_level', FILTER_DEFAULT)), ENT_QUOTES, 'UTF-8'),
        ':contractor'   => htmlspecialchars(trim((string)filter_input(INPUT_GET, 'contractor', FILTER_DEFAULT)), ENT_QUOTES, 'UTF-8'),
        ':block'        => htmlspecialchars(trim((string)filter_input(INPUT_GET, 'block', FILTER_DEFAULT)), ENT_QUOTES, 'UTF-8')
    ]);

    // Find the most recent inspection for this element
    $stmt = $pdo->prepare("
        SELECT i.inspection_id, i.notes
        FROM inspections i
        WHERE i.element_id = ?
        ORDER BY i.inspection_date DESC
        LIMIT 1
    ");
    $stmt->execute([$elementId]);
    $inspection = $stmt->fetch(PDO::FETCH_ASSOC);

    $items = [];
    // Get the template_id for the given element_type to find the correct questions
    $stmt_template_id = $pdo->prepare("SELECT template_id FROM checklist_templates WHERE element_type = ? AND is_active = TRUE LIMIT 1");
    $stmt_template_id->execute([$elementType]);
    $templateId = $stmt_template_id->fetchColumn();

    if ($inspection && $templateId) {
        // An inspection exists: Get its saved data, but join with the template to ensure all questions are present
        $stmt_items = $pdo->prepare("
            SELECT ci.item_id, ci.item_text, id.item_value
            FROM checklist_items ci
            LEFT JOIN inspection_data id ON ci.item_id = id.item_id AND id.inspection_id = :inspection_id
            WHERE ci.template_id = :template_id
            ORDER BY ci.item_order, ci.item_id
        ");
        $stmt_items->execute([':inspection_id' => $inspection['inspection_id'], ':template_id' => $templateId]);
        $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($templateId) {
        // No inspection yet: Get the blank checklist from the template
        $stmt_template_items = $pdo->prepare("
            SELECT item_id, item_text, '' as item_value
            FROM checklist_items
            WHERE template_id = ?
            ORDER BY item_order, item_id
        ");
        $stmt_template_items->execute([$templateId]);
        $items = $stmt_template_items->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'elementId' => $elementId,
        'inspectionData' => $inspection,
        'items' => $items
    ]);
} catch (Exception $e) {
    logError("API Error in get_element_data.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred.']);
}
