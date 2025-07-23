<?php
// /ghom/api/save_template.php (FINAL, SIMPLIFIED, AND ROBUST VERSION)

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();

if (!in_array($_SESSION['role'], ['admin', 'superuser'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
    exit();
}

try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    // --- 1. GET AND VALIDATE INPUT ---
    $template_id = $_POST['template_id'] ?? null;
    $template_name = trim($_POST['template_name'] ?? '');
    $element_type = trim($_POST['element_type'] ?? '');
    $item_texts = $_POST['item_texts'] ?? [];
    $item_stages = $_POST['item_stages'] ?? [];
    $item_passing_statuses = $_POST['item_passing_statuses'] ?? [];
    $item_weights = $_POST['item_weights'] ?? [];
    $item_is_critical = $_POST['item_is_critical'] ?? [];
    if (empty($template_name) || empty($element_type)) {
        throw new Exception('نام قالب و نوع المان الزامی است.');
    }

    // --- 2. SAVE OR UPDATE THE MAIN TEMPLATE ---
    if ($template_id) { // UPDATE
        $stmt = $pdo->prepare("UPDATE checklist_templates SET template_name = ?, element_type = ? WHERE template_id = ?");
        $stmt->execute([$template_name, $element_type, $template_id]);
    } else { // INSERT
        $stmt = $pdo->prepare("INSERT INTO checklist_templates (template_name, element_type) VALUES (?, ?)");
        $stmt->execute([$template_name, $element_type]);
        $template_id = $pdo->lastInsertId();
    }

    // --- 3. WIPE OLD DATA FOR THIS TEMPLATE (THE SAFEST METHOD) ---
    // This prevents all duplication and orphan issues permanently.
    $pdo->prepare("DELETE FROM inspection_stages WHERE template_id = ?")->execute([$template_id]);
    $pdo->prepare("DELETE FROM checklist_items WHERE template_id = ?")->execute([$template_id]);

    // --- 4. RE-INSERT STAGES AND ITEMS FROM SCRATCH ---

    // First, re-insert the unique stages in their new order
    $submitted_stages = [];
    foreach ($item_stages as $stage_name) {
        $trimmed_stage = trim($stage_name);
        if (!empty($trimmed_stage) && !in_array($trimmed_stage, $submitted_stages)) {
            $submitted_stages[] = $trimmed_stage;
        }
    }

    $stmt_stage = $pdo->prepare(
        "INSERT INTO inspection_stages (template_id, stage, display_order) VALUES (?, ?, ?)"
    );
    foreach ($submitted_stages as $index => $stage_name) {
        // This is a simple, clean insert with 3 parameters.
        $stmt_stage->execute([$template_id, $stage_name, $index]);
    }

    // Second, re-insert the checklist items
    $stmt_items = $pdo->prepare(
        "INSERT INTO checklist_items 
            (template_id, item_text, stage, item_order, passing_status, item_weight, is_critical) 
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($item_texts as $index => $text) {
        if (!empty(trim($text))) {
            $stage = trim($item_stages[$index] ?? 'Default');
            $passing_status = $item_passing_statuses[$index] ?? 'OK';
            $item_weight = $item_weights[$index] ?? 0;
            $is_critical = ($item_is_critical[$index] ?? '0') === '1' ? 1 : 0; // Sanitize to 1 or 0

            // The execute call now correctly provides all 7 parameters.
            $stmt_items->execute([
                $template_id,
                trim($text),
                $stage,
                $index,
                $passing_status,
                $item_weight,      // The 6th parameter
                $is_critical       // The 7th parameter
            ]);
        }
    }

    // --- 5. COMMIT AND RESPOND ---
    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'قالب با موفقیت ذخیره شد!']);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    // More specific error handling
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        echo json_encode(['status' => 'error', 'message' => 'خطا: برای هر نوع المان تنها یک قالب میتوان تعریف کرد.']);
    } else {
        // Send back the specific SQL error for easier debugging
        echo json_encode(['status' => 'error', 'message' => 'خطای پایگاه داده: ' . $e->getMessage()]);
    }
}
