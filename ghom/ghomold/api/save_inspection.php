<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';

secureSession();
if (!isLoggedIn() || !isset($_SESSION['current_project_config_key']) || $_SESSION['current_project_config_key'] !== 'ghom') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit();
}

// Data comes from FormData, so we use $_POST
$elementId = $_POST['elementId'] ?? null;
$checklistItemsJson = $_POST['items'] ?? '[]';
$notes = $_POST['notes'] ?? '';
$inspectionDateJalali = $_POST['inspection_date'] ?? '';
$userId = $_SESSION['user_id'];

// --- CRITICAL VALIDATION ---
if (empty($elementId) || empty($checklistItemsJson)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'اطلاعات المان یا چک‌لیست ارسال نشده است.']);
    exit();
}
// Ensure a date is provided
if (empty($inspectionDateJalali)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'تاریخ بازرسی الزامی است.']);
    exit();
}

$checklistItems = json_decode($checklistItemsJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid items JSON format.']);
    exit();
}

// Convert Jalali date to Gregorian for DB storage
$inspectionDateGregorian = shamsiToGregorian($inspectionDateJalali);
if (!$inspectionDateGregorian) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'فرمت تاریخ نامعتبر است.']);
    exit();
}

// --- File Upload Handling (remains the same) ---
$uploadedFilePaths = [];
$uploadDir = MESSAGE_UPLOAD_PATH_SYSTEM;
$uploadDirPublic = MESSAGE_UPLOAD_DIR_PUBLIC;

if (isset($_FILES['attachments'])) {
    foreach ($_FILES['attachments']['tmp_name'] as $key => $tmpName) {
        if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
            $originalName = basename($_FILES['attachments']['name'][$key]);
            $fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $safeFilename = "ghom_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $elementId) . "_" . time() . "_" . uniqid() . "." . $fileExtension;
            $destination = $uploadDir . $safeFilename;
            if (move_uploaded_file($tmpName, $destination)) {
                $uploadedFilePaths[] = $uploadDirPublic . $safeFilename;
            }
        }
    }
}

try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO inspections (element_id, user_id, inspection_date, notes, attachments) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$elementId, $userId, $inspectionDateGregorian, $notes, count($uploadedFilePaths) > 0 ? json_encode($uploadedFilePaths) : null]);
    $inspectionId = $pdo->lastInsertId();

    // UPDATED: Now inserts status and value
    $stmt_data = $pdo->prepare(
        "INSERT INTO inspection_data (inspection_id, item_id, item_status, item_value) VALUES (?, ?, ?, ?)"
    );

    foreach ($checklistItems as $item) {
        if (isset($item['itemId'])) {
            $stmt_data->execute([
                $inspectionId,
                $item['itemId'],
                $item['status'] ?? null, // The new status field
                $item['value'] ?? ''
            ]);
        }
    }

    $pdo->commit();

    log_activity($userId, $_SESSION['username'], 'inspection_save', "Saved inspection for element: $elementId", $_SESSION['current_project_id']);
    echo json_encode(['status' => 'success', 'message' => 'بازرسی با موفقیت ذخیره شد.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    logError("API Error in save_inspection.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای داخلی سرور در زمان ذخیره‌سازی رخ داد.']);
}
