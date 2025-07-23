<?php
//ghom/api/save_template.php (ENHANCED WITH STAGE SAVING)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();
if (!isLoggedIn()) {
    header('Location: /../login.php?msg=login_required');
    exit();
}
if (!in_array($_SESSION['role'], ['admin', 'superuser'])) {
    http_response_code(403);
    require '/../Access_Denied.php';
    exit;
}
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
    exit();
}

$template_id = $_POST['template_id'] ?? null;
$template_name = trim($_POST['template_name'] ?? '');
$element_type = trim($_POST['element_type'] ?? '');
$item_texts = $_POST['item_texts'] ?? [];
$item_stages = $_POST['item_stages'] ?? []; // The new array for stages

if (empty($template_name) || empty($element_type)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'نام قالب و نوع المان الزامی است.']);
    exit();
}

try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    if ($template_id) { // UPDATE
        $stmt = $pdo->prepare("UPDATE checklist_templates SET template_name = ?, element_type = ? WHERE template_id = ?");
        $stmt->execute([$template_name, $element_type, $template_id]);
        $pdo->prepare("DELETE FROM checklist_items WHERE template_id = ?")->execute([$template_id]);
    } else { // INSERT
        $stmt = $pdo->prepare("INSERT INTO checklist_templates (template_name, element_type) VALUES (?, ?)");
        $stmt->execute([$template_name, $element_type]);
        $template_id = $pdo->lastInsertId();
    }

    // Insert all items along with their stages
    $stmt_items = $pdo->prepare("INSERT INTO checklist_items (template_id, item_text, stage, item_order) VALUES (?, ?, ?, ?)");
    foreach ($item_texts as $index => $text) {
        if (!empty(trim($text))) {
            $stage = trim($item_stages[$index] ?? ''); // Get the corresponding stage
            $stmt_items->execute([$template_id, trim($text), $stage, ($index + 1) * 10]);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'قالب با موفقیت ذخیره شد!']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    if ($e->getCode() == '23000') { // Catches unique constraint violation
        echo json_encode(['status' => 'error', 'message' => 'خطا: برای هر نوع المان تنها یک قالب میتوان تعریف کرد. لطفا از نوع المان دیگری استفاده کنید.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'خطای پایگاه داده: ' . $e->getMessage()]);
    }
}
