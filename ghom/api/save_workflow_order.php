<?php
// /public_html/ghom/api/save_workflow_order.php (FINAL, CORRECTED VERSION)

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';

secureSession();
if (!in_array($_SESSION['role'], ['admin', 'superuser'])) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => 'Access Denied']));
}

$data = json_decode(file_get_contents('php://input'), true);

// Validate the incoming payload structure
if (empty($data) || !isset($data['templates']) || !isset($data['stages'])) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid data structure received.']));
}

$pdo = null;
try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    // --- 1. Update Template-level data (Cost and Unit of Measure) ---
    $stmt_template = $pdo->prepare(
        "UPDATE checklist_templates SET unit_of_measure = :unit, cost_per_unit = :cost 
         WHERE template_id = :id"
    );
    foreach ($data['templates'] as $template) {
        if (isset($template['template_id'], $template['unit_of_measure'], $template['cost_per_unit'])) {
            $stmt_template->execute([
                ':unit' => $template['unit_of_measure'],
                ':cost' => $template['cost_per_unit'],
                ':id'   => $template['template_id']
            ]);
        }
    }

    // --- 2. Update Stage-level data (Display Order and Weight) ---
    $stmt_stage = $pdo->prepare(
        "UPDATE inspection_stages SET display_order = :order, weight = :weight 
         WHERE stage_id = :id AND template_id = :template_id"
    );
    foreach ($data['stages'] as $stage) {
        if (isset($stage['stage_id'], $stage['template_id'], $stage['display_order'], $stage['weight'])) {
            $stmt_stage->execute([
                ':order'       => $stage['display_order'],
                ':weight'      => $stage['weight'],
                ':id'          => $stage['stage_id'],
                ':template_id' => $stage['template_id']
            ]);
        }
    }

    // --- 3. Commit and Respond ---
    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'تمام تغییرات با موفقیت ذخیره شد.']);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Workflow Save Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای پایگاه داده: ' . $e->getMessage()]);
}
