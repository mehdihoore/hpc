<?php
// /ghom/api/batch_update_status.php (CORRECTED)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
require_once __DIR__ . '/../includes/jdf.php'; // Required for jalali_to_gregorian

secureSession();

// THIS FUNCTION WAS MISSING, CAUSING THE FATAL ERROR
function jalali_to_gregorian_for_db($jalali_date)
{
    if (empty($jalali_date)) return null;
    $parts = array_map('intval', explode('/', trim($jalali_date)));
    if (count($parts) !== 3) return null;
    if (function_exists('jalali_to_gregorian')) {
        $g_date_array = jalali_to_gregorian($parts[0], $parts[1], $parts[2]);
        return implode('-', $g_date_array);
    }
    return null;
}

$data = json_decode(file_get_contents('php://input'), true);

$elements_data = $data['elements_data'] ?? [];
$stage_id = $data['stage_id'] ?? null;
$notes = $data['notes'] ?? '';
$date_gregorian = jalali_to_gregorian_for_db($data['date'] ?? null);
$userId = $_SESSION['user_id'];

if (empty($elements_data) || empty($stage_id) || empty($date_gregorian)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'اطلاعات ناقص است (مرحله، تاریخ، یا لیست المان‌ها ارسال نشده).']));
}

try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    // Prepare a statement to COUNT previous attempts
    $stmt_count = $pdo->prepare(
        "SELECT COUNT(*) FROM inspections WHERE element_id = ? AND part_name <=> ? AND stage_id = ?"
    );

    // Prepare the INSERT statement
    $stmt_insert = $pdo->prepare(
        "INSERT INTO inspections 
            (element_id, part_name, stage_id, user_id, status, contractor_status, contractor_notes, contractor_date, inspection_date) 
         VALUES (?, ?, ?, ?, 'Ready for Inspection', 'Ready for Inspection', ?, ?, ?)"
    );

    $submitted_count = 0;
    $skipped_elements = [];

    foreach ($elements_data as $element) {
        $baseElementId = $element['element_id'];
        $partsToInspect = ($element['element_type'] === 'GFRC') ? ['face', 'left', 'right', 'up', 'down'] : ['N/A'];

        foreach ($partsToInspect as $partName) {
            // 1. Count existing attempts for this specific part and stage
            $stmt_count->execute([$baseElementId, $partName, $stage_id]);
            $attempts = $stmt_count->fetchColumn();

            // 2. Check if the attempt limit has been reached
            if ($attempts < 3) {
                // 3. If limit is not reached, insert the new record
                $stmt_insert->execute([$baseElementId, $partName, $stage_id, $userId, $notes, $date_gregorian, $date_gregorian]);
                $submitted_count++;
            } else {
                // 4. If limit is reached, add it to a skipped list
                if (!in_array($baseElementId, $skipped_elements)) {
                    $skipped_elements[] = $baseElementId;
                }
            }
        }
    }

    $pdo->commit();

    // Build a helpful response message
    $message = "عملیات با موفقیت انجام شد. تعداد {$submitted_count} رکورد بازرسی ثبت شد.";
    if (!empty($skipped_elements)) {
        $message .= "\n\nتوجه: المان‌های زیر به دلیل رسیدن به حد مجاز ۳ بار بازرسی، ثبت نشدند: \n" . implode(', ', $skipped_elements);
    }

    echo json_encode(['status' => 'success', 'message' => $message]);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log("Batch Update API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'خطای پایگاه داده رخ داد.']);
}
