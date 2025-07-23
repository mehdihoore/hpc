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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
        exit();
    }
    if (!isLoggedIn()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Access Forbidden.']);
        exit();
    }

    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    // --- ALWAYS INSERT A NEW HISTORICAL RECORD ---
    $fullElementId = $_POST['element_id'] . '-' . ($_POST['part_name'] ?? 'default');

    $stmt_insert = $pdo->prepare(
        "INSERT INTO inspections (
            element_id, part_name, user_id, 
            overall_status, inspection_date, notes, attachments, 
            contractor_status, contractor_date, contractor_notes, contractor_attachments,
            created_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt_insert->execute([
        $_POST['element_id'],
        $_POST['part_name'] ?? null,
        $_SESSION['user_id'],
        $_POST['overall_status'] ?? null,
        toGregorian($_POST['inspection_date'] ?? null),
        $_POST['notes'] ?? null,
        process_uploads('attachments', $fullElementId),
        $_POST['contractor_status'] ?? null,
        toGregorian($_POST['contractor_date'] ?? null),
        $_POST['contractor_notes'] ?? null,
        process_uploads('contractor_attachments', $fullElementId)
    ]);
    $inspectionId = $pdo->lastInsertId();

    // --- SAVE CHECKLIST DETAILS FOR THIS NEW HISTORICAL RECORD ---
    $checklistItems = json_decode($_POST['items'] ?? '[]', true);
    if ($inspectionId && !empty($checklistItems)) {
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
    echo json_encode(['status' => 'success', 'message' => 'سابقه بازرسی جدید با موفقیت ثبت شد.']);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    error_log("API History Save Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای پایگاه داده: ' . $e->getMessage()]);
}
