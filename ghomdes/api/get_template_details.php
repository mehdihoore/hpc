<?php
// ghom/api/get_template_details.php (CORRECTED)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();
if (!isLoggedIn()) {
    header('Location:/../login.php?msg=login_required');
    exit();
}
if (!in_array($_SESSION['role'], ['admin', 'superuser'])) {
    http_response_code(403);
    require '/../Access_Denied.php';
    exit;
}
// Assuming superuser should also have access.
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

    // Get the main template info (this part is unchanged)
    $stmt_template = $pdo->prepare("SELECT * FROM checklist_templates WHERE template_id = ?");
    $stmt_template->execute([$template_id]);
    $template = $stmt_template->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        http_response_code(404);
        echo json_encode(['error' => 'Template not found.']);
        exit();
    }

    // Get all items for that template (this part is unchanged)
    // Note: your schema uses `item_order`, so we use that here.
    $stmt_items = $pdo->prepare("SELECT item_id, item_text, stage FROM checklist_items WHERE template_id = ? ORDER BY item_order, item_id");
    $stmt_items->execute([$template_id]);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    // --- THIS IS THE NEW, REQUIRED LOGIC ---
    // Get all unique, non-empty stage names for this template to populate the autocomplete.
    $stmt_stages = $pdo->prepare(
        "SELECT DISTINCT stage FROM checklist_items WHERE template_id = ? AND stage IS NOT NULL AND stage != '' ORDER BY stage"
    );
    $stmt_stages->execute([$template_id]);
    // Fetch the results as a simple array of strings, e.g., ["Stage 1", "Stage 2"]
    $existing_stages = $stmt_stages->fetchAll(PDO::FETCH_COLUMN, 0);
    // --- END OF NEW LOGIC ---

    // Add the new 'existing_stages' array to the final JSON output
    echo json_encode([
        'template' => $template,
        'items' => $items,
        'existing_stages' => $existing_stages
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
