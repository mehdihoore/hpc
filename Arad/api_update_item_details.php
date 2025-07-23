<?php
require_once __DIR__ . '/../../sercon/bootstrap.php';
header('Content-Type: application/json');
secureSession();

if (!isLoggedIn() || !in_array($_SESSION['role'], ['admin', 'supervisor', 'superuser', 'planner'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$itemId = $data['itemId'] ?? null;
$components = $data['components'] ?? [];
$files_to_add = $data['files'] ?? [];
$files_to_delete = $data['files_to_delete'] ?? [];
$status_sent = $data['status_sent'] ?? 0;
$status_made = $data['status_made'] ?? 0;

if (!$itemId || !is_numeric($itemId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid Item ID.']);
    exit();
}

$pdo = getProjectDBConnection();
$pdo->beginTransaction();

try {
    // 1. Update main item statuses
    $stmtUpdate = $pdo->prepare("UPDATE hpc_items SET status_sent = ?, status_made = ? WHERE id = ?");
    $stmtUpdate->execute([$status_sent, $status_made, $itemId]);

    // 2. Delete all old components and insert new ones
    $stmtDeleteComp = $pdo->prepare("DELETE FROM item_components WHERE hpc_item_id = ?");
    $stmtDeleteComp->execute([$itemId]);
    if (!empty($components)) {
        $stmtInsertComp = $pdo->prepare("INSERT INTO item_components (hpc_item_id, component_type, component_name, attribute_1, attribute_2, attribute_3, component_quantity, unit, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($components as $c) {
            $attr1 = $c['attributes']['نام'] ?? $c['attributes']['نوع'] ?? $c['attributes']['شکل'] ?? $c['attributes']['قطر'] ?? $c['attributes']['سایز'] ?? null;
            $attr2 = $c['attributes']['طول'] ?? $c['attributes']['ابعاد'] ?? $c['attributes']['وزن در متر'] ?? $c['attributes']['تعداد'] ?? null;
            $attr3 = $c['attributes']['ضخامت'] ?? $c['attributes']['وزن'] ?? null;
            $stmtInsertComp->execute([$itemId, $c['type'], $c['name'], $attr1, $attr2, $attr3, $c['quantity'], $c['unit'], $c['notes']]);
        }
    }

    // 3. Delete marked files from DB and server
    if (!empty($files_to_delete)) {
        $stmtSelectFiles = $pdo->prepare("SELECT file_path FROM item_files WHERE id = ? AND hpc_item_id = ?");
        $stmtDeleteFile = $pdo->prepare("DELETE FROM item_files WHERE id = ?");
        foreach ($files_to_delete as $fileId) {
            $stmtSelectFiles->execute([$fileId, $itemId]);
            $file = $stmtSelectFiles->fetch(PDO::FETCH_ASSOC);
            if ($file) {
                // Delete from server storage
                $filePathOnServer = __DIR__ . '/uploads/item_documents/' . $file['file_path'];
                if (file_exists($filePathOnServer)) {
                    unlink($filePathOnServer);
                }
                // Delete from DB
                $stmtDeleteFile->execute([$fileId]);
            }
        }
    }

    // 4. Add new files (move from temp to permanent)
    if (!empty($files_to_add)) {
        $stmtFile = $pdo->prepare("INSERT INTO item_files (hpc_item_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)");
        $permanentUploadDir = __DIR__ . '/uploads/item_documents/';
        if (!is_dir($permanentUploadDir)) {
            mkdir($permanentUploadDir, 0777, true);
        }
        foreach ($files_to_add as $file) {
            $tempFilePath = $file['path'];
            if (strpos(realpath($tempFilePath), sys_get_temp_dir()) === 0 && file_exists($tempFilePath)) { // Security check
                $permanentFileName = uniqid() . '-' . basename(preg_replace('/[^A-Za-z0-9\.\-\_]/', '', $file['name']));
                $permanentFilePath = $permanentUploadDir . $permanentFileName;
                if (rename($tempFilePath, $permanentFilePath)) {
                    $stmtFile->execute([$itemId, $file['name'], $permanentFileName, $file['type']]);
                }
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    logError("API update details failed for item $itemId: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
