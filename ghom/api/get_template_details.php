<?php
// ghom/api/get_template_details.php (FINAL, CORRECTED VERSION)

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();

// --- Security and Validation ---
if (!in_array($_SESSION['role'], ['admin', 'superuser'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

$template_id = $_GET['id'] ?? null;
if (!$template_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Template ID is required.']);
    exit();
}

try {
    $pdo = getProjectDBConnection('ghom');

    // Get the main template info
    $stmt_template = $pdo->prepare("SELECT * FROM checklist_templates WHERE template_id = ?");
    $stmt_template->execute([$template_id]);
    $template = $stmt_template->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        http_response_code(404);
        echo json_encode(['error' => 'Template not found.']);
        exit();
    }

    // ===================================================================
    // START: THE CORRECTED QUERY FOR ITEMS
    // It now selects ALL necessary columns, including is_critical and item_weight.
    // ===================================================================
    $stmt_items = $pdo->prepare(
        "SELECT 
            item_id, 
            item_text, 
            stage, 
            passing_status, 
            is_critical,      -- Fetches the critical status
            item_weight       -- Fetches the item weight
         FROM checklist_items 
         WHERE template_id = ? 
         ORDER BY item_order, item_id"
    );
    $stmt_items->execute([$template_id]);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
    // ===================================================================
    // END: CORRECTED QUERY
    // ===================================================================


    // Get all unique stage names for the autocomplete datalist
    $stmt_stages = $pdo->prepare(
        "SELECT DISTINCT stage FROM checklist_items WHERE template_id = ? AND stage IS NOT NULL AND stage != '' ORDER BY stage"
    );
    $stmt_stages->execute([$template_id]);
    $existing_stages = $stmt_stages->fetchAll(PDO::FETCH_COLUMN, 0);


    // Send the complete data back to the frontend
    echo json_encode([
        'template' => $template,
        'items' => $items,
        'existing_stages' => $existing_stages
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
