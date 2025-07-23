<?php
// public_html/ghom/api/save_inspection_from_dashboard.php (FINAL - SUPPORTS HISTORY & EDITS)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
require_once __DIR__ . '/../includes/jdf.php';

secureSession();

// --- HELPER FUNCTIONS (FROM YOUR CODE) ---
function jalali_to_gregorian_for_db($jalali_date)
{
    if (empty($jalali_date) || !is_string($jalali_date)) return null;
    $parts = explode('/', trim($jalali_date));
    if (count($parts) !== 3 || !is_numeric($parts[0]) || !is_numeric($parts[1]) || !is_numeric($parts[2])) return null;
    if (function_exists('jalali_to_gregorian')) {
        $g_date_array = jalali_to_gregorian((int)$parts[0], (int)$parts[1], (int)$parts[2]);
        return implode('-', $g_date_array);
    }
    return null;
}

function process_file_uploads($fileInputKey, $elementId)
{
    if (empty($_FILES[$fileInputKey]['tmp_name']) || !is_array($_FILES[$fileInputKey]['tmp_name'])) return null;
    $uploadedFilePaths = [];
    $uploadDir = defined('MESSAGE_UPLOAD_PATH_SYSTEM') ? MESSAGE_UPLOAD_PATH_SYSTEM : 'uploads/';
    $uploadDirPublic = defined('MESSAGE_UPLOAD_DIR_PUBLIC') ? MESSAGE_UPLOAD_DIR_PUBLIC : '/uploads/';

    foreach ($_FILES[$fileInputKey]['tmp_name'] as $key => $tmpName) {
        if (is_uploaded_file($tmpName) && $_FILES[$fileInputKey]['error'][$key] === UPLOAD_ERR_OK) {
            $originalName = basename($_FILES[$fileInputKey]['name'][$key]);
            $safeFilename = "ghom_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $elementId) . "_" . time() . "_" . uniqid() . "." . pathinfo($originalName, PATHINFO_EXTENSION);
            if (move_uploaded_file($tmpName, $uploadDir . $safeFilename)) {
                $uploadedFilePaths[] = $uploadDirPublic . $safeFilename;
            }
        }
    }
    return count($uploadedFilePaths) > 0 ? json_encode($uploadedFilePaths) : null;
}

function send_json_error($message, $code = 400)
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit();
}

// --- MAIN EXECUTION LOGIC ---
$pdo = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') send_json_error("Invalid request method.", 405);
    if (!isLoggedIn() || !in_array($_SESSION['role'], ['admin', 'supervisor'])) send_json_error("Access Forbidden.", 403);

    $role = $_SESSION['role'];
    $userId = $_SESSION['user_id'];

    // --- GATHER ALL DATA FROM FORM ---
    $editingInspectionId = $_POST['inspection_id'] ?? null;
    $elementId = $_POST['element_id'] ?? null;
    $partName = $_POST['part_name'] ?? 'N/A';
    $checklistItemsJson = $_POST['items'] ?? '[]';
    $checklistItems = json_decode($checklistItemsJson, true);

    if (empty($elementId)) send_json_error("Element ID is required.");

    $pdo = getProjectDBConnection('ghom');
    $pdo->exec("SET NAMES 'utf8mb4'");
    $pdo->beginTransaction();

    // --- DETERMINE ACTION: INSERT (NEW) OR UPDATE (EDIT) ---
    if ($editingInspectionId) {
        // --- UPDATE LOGIC ---
        $inspectionId = $editingInspectionId;
        $message = 'بازرسی با موفقیت ویرایش شد.';

        // Build dynamic update query based on role
        $update_fields = [];
        $params = [];

        if ($role === 'admin') {
            $consultantAttachmentsJson = process_file_uploads('attachments', $elementId);
            if ($consultantAttachmentsJson) {
                $update_fields[] = "attachments = JSON_MERGE_PRESERVE(IFNULL(attachments, '[]'), ?)";
                $params[] = $consultantAttachmentsJson;
            }
            $update_fields[] = "overall_status = ?";
            $params[] = $_POST['overall_status'] ?? null;
            $update_fields[] = "inspection_date = ?";
            $params[] = jalali_to_gregorian_for_db($_POST['inspection_date'] ?? null);
            $update_fields[] = "notes = ?";
            $params[] = $_POST['notes'] ?? null;
        } elseif ($role === 'supervisor') {
            $contractorAttachmentsJson = process_file_uploads('contractor_attachments', $elementId);
            if ($contractorAttachmentsJson) {
                $update_fields[] = "contractor_attachments = JSON_MERGE_PRESERVE(IFNULL(contractor_attachments, '[]'), ?)";
                $params[] = $contractorAttachmentsJson;
            }
            $update_fields[] = "contractor_status = ?";
            $params[] = $_POST['contractor_status'] ?? null;
            $update_fields[] = "contractor_date = ?";
            $params[] = jalali_to_gregorian_for_db($_POST['contractor_date'] ?? null);
            $update_fields[] = "contractor_notes = ?";
            $params[] = $_POST['contractor_notes'] ?? null;
        }

        if (!empty($update_fields)) {
            $update_fields[] = "user_id = ?";
            $params[] = $userId;
            $sql = "UPDATE inspections SET " . implode(', ', $update_fields) . " WHERE inspection_id = ?";
            $params[] = $inspectionId;
            $stmt_update = $pdo->prepare($sql);
            $stmt_update->execute($params);
        }

        // Delete old checklist results before inserting new ones
        $stmt_delete_results = $pdo->prepare("DELETE FROM inspection_data WHERE inspection_id = ?");
        $stmt_delete_results->execute([$inspectionId]);
    } else {
        // --- INSERT LOGIC ---
        $message = 'بازرسی جدید با موفقیت ثبت شد.';

        $stmt_insert = $pdo->prepare(
            "INSERT INTO inspections 
                (element_id, part_name, user_id, overall_status, inspection_date, notes, attachments, contractor_status, contractor_date, contractor_notes, contractor_attachments) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt_insert->execute([
            $elementId,
            $partName,
            $userId,
            $_POST['overall_status'] ?? null,
            jalali_to_gregorian_for_db($_POST['inspection_date'] ?? null),
            $_POST['notes'] ?? null,
            process_file_uploads('attachments', $elementId),
            $_POST['contractor_status'] ?? null,
            jalali_to_gregorian_for_db($_POST['contractor_date'] ?? null),
            $_POST['contractor_notes'] ?? null,
            process_file_uploads('contractor_attachments', $elementId)
        ]);
        $inspectionId = $pdo->lastInsertId();
    }

    // --- SAVE CHECKLIST DETAILS (THIS RUNS FOR BOTH INSERT AND UPDATE) ---
    if ($inspectionId && !empty($checklistItems) && is_array($checklistItems)) {
        $stmt_results = $pdo->prepare(
            "INSERT INTO inspection_data (inspection_id, item_id, item_status, item_value) VALUES (?, ?, ?, ?)"
        );
        foreach ($checklistItems as $item) {
            $stmt_results->execute([
                $inspectionId,
                $item['itemId'],
                $item['status'] ?? 'N/A',
                $item['value'] ?? ''
            ]);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => $message]);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    error_log("API Save Error: " . $e->getMessage());
    send_json_error("خطای پایگاه داده: " . $e->getMessage(), 500);
}
