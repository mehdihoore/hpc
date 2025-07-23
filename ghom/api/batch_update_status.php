<?php
// ===================================================================
// START: GFRC Orientation Logic Update
// ===================================================================

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
require_once __DIR__ . '/../includes/jdf.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => 'Forbidden: وارد نشده‌اید.']));
}

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
try {
    $data = json_decode(file_get_contents('php://input'), true);
    $element_ids = $data['element_ids'] ?? [];
    $stage_id = $data['stage_id'] ?? null;
    $notes = $data['notes'] ?? null;
    $date = $data['date'] ?? null;
    $update_existing = $data['update_existing'] ?? false;

    $userRole = $_SESSION['role'];
    $userId = $_SESSION['user_id'];

    if (empty($element_ids) || !$stage_id) {
        throw new Exception("Element IDs and Stage ID are required.");
    }

    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    $processed_count = 0;

    foreach ($element_ids as $elementId) {
        // Find the latest inspection cycle for this element and stage
        $stmt_find = $pdo->prepare("SELECT * FROM inspections WHERE element_id = ? AND stage_id = ? ORDER BY inspection_cycle DESC LIMIT 1");
        $stmt_find->execute([$elementId, $stage_id]);
        $latest_inspection = $stmt_find->fetch(PDO::FETCH_ASSOC);

        // --- Logic to decide whether to process this element ---
        $should_process = false;
        if (!$latest_inspection) {
            // If no record exists, it's new and should be processed.
            $should_process = true;
        } else {
            // If a record exists, only process it if the "update existing" checkbox was checked.
            if ($update_existing) {
                $should_process = true;
            }
        }

        if (!$should_process) {
            continue; // Skip this element
        }

        // --- Create the contractor submission snapshot ---
        $snapshot = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id'   => $userId,
            'role'      => $userRole,
            'contractor_submission' => [
                'contractor_status' => 'Ready for Inspection',
                'contractor_date'   => jalali_to_gregorian_for_db($date),
                'notes'             => $notes,
                'attachments'       => [] // No attachments in batch mode
            ]
        ];

        if ($latest_inspection) {
            // --- UPDATE existing record ---
            $current_history = json_decode($latest_inspection['history_log'] ?? '[]', true) ?: [];
            $current_history[] = $snapshot;

            $sql = "UPDATE inspections SET history_log = ?, user_id = ?, contractor_status = ?, contractor_date = ? WHERE inspection_id = ?";
            $pdo->prepare($sql)->execute([
                json_encode($current_history, JSON_UNESCAPED_UNICODE),
                $userId,
                'Ready for Inspection',
                jalali_to_gregorian_for_db($date),
                $latest_inspection['inspection_id']
            ]);
        } else {
            // --- INSERT new record for cycle 1 ---
            $sql = "INSERT INTO inspections (element_id, stage_id, inspection_cycle, user_id, history_log, contractor_status, contractor_date) VALUES (?, ?, 1, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([
                $elementId,
                $stage_id,
                $userId,
                json_encode([$snapshot], JSON_UNESCAPED_UNICODE),
                'Ready for Inspection',
                jalali_to_gregorian_for_db($date)
            ]);
        }
        $processed_count++;
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => "تعداد $processed_count المان با موفقیت ثبت شد."]);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log("Batch Update API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'خطای پایگاه داده رخ داد: ' . $e->getMessage()]);
}

// ===================================================================
// END: GFRC Orientation Logic Update
// ===================================================================