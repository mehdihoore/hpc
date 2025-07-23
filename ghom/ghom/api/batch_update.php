<?php
// /public_html/ghom/api/batch_update_status.php (CORRECTED AND FINAL VERSION)

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
require_once __DIR__ . '/jdf.php';

secureSession();
if (!in_array($_SESSION['role'], ['admin', 'supervisor', 'contractor', 'superuser'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
    exit();
}

function toGregorian($jalaliDate)
{
    if (empty($jalaliDate)) return null;
    $parts = explode('/', $jalaliDate);
    if (count($parts) === 3 && function_exists('jmktime')) {
        return date('Y-m-d', jmktime(0, 0, 0, $parts[1], $parts[2], $parts[0]));
    }
    return null;
}

$data = json_decode(file_get_contents('php://input'), true);

$elements_data = $data['elements_data'] ?? [];
$status = $data['status'] ?? null;
$notes = $data['notes'] ?? '';
$date_gregorian = toGregorian($data['date'] ?? null);
$userId = $_SESSION['user_id'];

if (empty($elements_data) || empty($status) || empty($date_gregorian)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'اطلاعات ناقص است (وضعیت، تاریخ، یا لیست المان‌ها ارسال نشده).']);
    exit();
}

try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    // BUG FIX: Use the NULL-safe operator `<=>` to correctly find records where part_name IS NULL.
    $stmt_find = $pdo->prepare("SELECT inspection_id FROM inspections WHERE element_id = ? AND part_name <=> ? ORDER BY created_at DESC, inspection_id DESC LIMIT 1");
    $stmt_update_inspection = $pdo->prepare("UPDATE inspections SET contractor_status = ?, contractor_notes = ?, contractor_date = ?, user_id = ? WHERE inspection_id = ?");
    $stmt_insert_inspection = $pdo->prepare("INSERT INTO inspections (element_id, part_name, contractor_status, contractor_notes, contractor_date, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $total_updates = 0;

    foreach ($elements_data as $element) {
        $baseElementId = $element['element_id'];

        // Use `null` for non-GFRC elements for better data consistency.
        $partsToInspect = [null];

        if ($element['element_type'] === 'GFRC' && !empty($element['panel_orientation'])) {
            if ($element['panel_orientation'] === 'Vertical') {
                $partsToInspect = ['face', 'left', 'right'];
            } elseif ($element['panel_orientation'] === 'Horizontal') {
                $partsToInspect = ['face', 'up', 'down'];
            } else {
                $partsToInspect = ['face'];
            }
        }

        foreach ($partsToInspect as $partName) {
            $stmt_find->execute([$baseElementId, $partName]);
            $inspectionId = $stmt_find->fetchColumn();

            if ($inspectionId) {
                $stmt_update_inspection->execute([$status, $notes, $date_gregorian, $userId, $inspectionId]);
            } else {
                $stmt_insert_inspection->execute([$baseElementId, $partName, $status, $notes, $date_gregorian, $userId]);
            }
            $total_updates++;
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => $total_updates . ' رکورد بازرسی با موفقیت به‌روزرسانی شد.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("API Error in batch_update_status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای پایگاه داده رخ داد. لطفا با پشتیبانی تماس بگیرید.']);
}
