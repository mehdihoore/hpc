<?php
// ===================================================================
// FINAL, PRODUCTION-READY save_inspection.php
// With clean logging for all operations.
// ===================================================================

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
require_once __DIR__ . '/../includes/jdf.php';

// --- Clean Logging Function ---
function write_log($message)
{
    // Defines a unique log file for each day.
    $log_file = __DIR__ . '/logs/save_inspection_' . date("Y-m-d") . '.log';
    // Ensure the logs directory exists.
    if (!file_exists(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0755, true);
    }
    $timestamp = date("Y-m-d H:i:s");
    $formatted_message = is_array($message) || is_object($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message;
    file_put_contents($log_file, "[$timestamp] " . $formatted_message . "\n", FILE_APPEND);
}

// Helper function to convert Jalali date string to Gregorian for DB
function toGregorian($jalaliDate)
{
    if (empty($jalaliDate) || !is_string($jalaliDate)) return null;
    $parts = explode('/', trim($jalaliDate));
    if (count($parts) !== 3) return null;
    if (function_exists('jalali_to_gregorian')) {
        return implode('-', jalali_to_gregorian((int)$parts[0], (int)$parts[1], (int)$parts[2]));
    }
    return null;
}

function appendToJsonColumn(PDO $pdo, int $inspectionId, string $columnName, array $newEntry)
{
    // 1. Get the current JSON value
    $stmt = $pdo->prepare("SELECT $columnName FROM inspections WHERE inspection_id = ?");
    $stmt->execute([$inspectionId]);
    $currentJson = $stmt->fetchColumn();

    // 2. Decode it into an array, or start with a fresh one
    $dataArray = !empty($currentJson) ? json_decode($currentJson, true) : [];
    if (!is_array($dataArray)) {
        $dataArray = [];
    }

    // 3. Add the new entry
    $dataArray[] = $newEntry;

    // 4. Encode it back and update the database
    $newJson = json_encode($dataArray, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("UPDATE inspections SET $columnName = ? WHERE inspection_id = ?");
    $stmt->execute([$newJson, $inspectionId]);
}
// Helper function to process and merge file uploads
function processAndMergeUploads(string $fileInputKey, string $elementId, ?string $existingAttachmentsJson): ?string
{
    $allPaths = ($existingAttachmentsJson) ? json_decode($existingAttachmentsJson, true) : [];
    if (!is_array($allPaths)) {
        $allPaths = [];
    }

    if (isset($_FILES[$fileInputKey]) && is_array($_FILES[$fileInputKey]['name'])) {
        $uploadDir = defined('MESSAGE_UPLOAD_PATH_SYSTEM') ? MESSAGE_UPLOAD_PATH_SYSTEM : 'uploads/';
        $uploadDirPublic = defined('MESSAGE_UPLOAD_DIR_PUBLIC') ? MESSAGE_UPLOAD_DIR_PUBLIC : '/uploads/';
        foreach ($_FILES[$fileInputKey]['tmp_name'] as $key => $tmpName) {
            if (is_uploaded_file($tmpName) && $_FILES[$fileInputKey]['error'][$key] === UPLOAD_ERR_OK) {
                $originalName = basename($_FILES[$fileInputKey]['name'][$key]);
                $safeFilename = "ghom_" . preg_replace('/[^a-zA-Z0-9._-]/', '_', $elementId) . "_" . time() . "_" . uniqid() . "." . pathinfo($originalName, PATHINFO_EXTENSION);
                if (move_uploaded_file($tmpName, $uploadDir . $safeFilename)) {
                    $allPaths[] = $uploadDirPublic . $safeFilename;
                    write_log("File Upload SUCCESS: Moved '$originalName' to '$safeFilename' for input '$fileInputKey'.");
                } else {
                    write_log("File Upload ERROR: Failed to move '$originalName'. Check permissions for '$uploadDir'.");
                }
            }
        }
    }
    return !empty($allPaths) ? json_encode(array_values($allPaths)) : null;
}


// --- Main execution starts ---
write_log("================== SAVE REQUEST START ==================");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid request method.']));
}
secureSession();
if (!isLoggedIn()) {
    http_response_code(403);
    write_log("SAVE FAILED: User not logged in.");
    exit(json_encode(['status' => 'error', 'message' => 'Forbidden']));
}

$pdo = null;
try {
    $fullElementId = $_POST['elementId'] ?? '';
    $userRole = $_SESSION['role'];
    $userId = $_SESSION['user_id'];
    $stagesData = json_decode($_POST['stages'] ?? '[]', true);

    write_log("User: {$userId} ({$userRole}) | Element: {$fullElementId}");
    write_log("Received Stages Data: " . json_encode($stagesData, JSON_UNESCAPED_UNICODE));
    if (!empty($_FILES)) {
        write_log("Received Files: " . json_encode(array_keys($_FILES)));
    }

    if (empty($stagesData)) {
        throw new Exception("No stage data was submitted or JSON was invalid.");
    }

    $pdo = getProjectDBConnection('ghom');
    $pdo->beginTransaction();

    $baseElementId = $fullElementId;
    $partName = null;
    $parts = explode('-', $fullElementId);
    if (count($parts) > 1 && in_array(strtolower(end($parts)), ['face', 'up', 'down', 'left', 'right', 'default'])) {
        $partName = array_pop($parts);
        $baseElementId = implode('-', $parts);
    }

    foreach ($stagesData as $stageId => $stageData) {
        write_log("--- Processing Stage ID: $stageId ---");

        $stmt_find = $pdo->prepare("SELECT * FROM inspections WHERE element_id = ? AND part_name <=> ? AND stage_id = ? LIMIT 1");
        $stmt_find->execute([$baseElementId, $partName, $stageId]);
        $existing_inspection = $stmt_find->fetch(PDO::FETCH_ASSOC);

        if ($existing_inspection) {
            $inspectionId = $existing_inspection['inspection_id'];
            write_log("  Action: UPDATE existing record ID: $inspectionId");

            $params = [];

            if ($userRole === 'admin' || $userRole === 'superuser') {
                $params['overall_status'] = $stageData['overall_status'] ?? null;
                $params['inspection_date'] = toGregorian($stageData['inspection_date'] ?? null);
                $params['notes'] = $stageData['notes'] ?? null;
                $params['attachments'] = processAndMergeUploads('attachments', $fullElementId, $existing_inspection['attachments']);
            }

            if (in_array($userRole, ['cat', 'car', 'coa', 'crs']) || $userRole === 'superuser') {
                $params['contractor_status'] = $stageData['contractor_status'] ?? null;
                $params['contractor_date'] = toGregorian($stageData['contractor_date'] ?? null);
                $params['contractor_notes'] = $stageData['contractor_notes'] ?? null;
                $params['contractor_attachments'] = processAndMergeUploads('contractor_attachments', $fullElementId, $existing_inspection['contractor_attachments']);
            }

            if (!empty($params)) {
                $params['user_id'] = $userId; // Always update who made the last change
                $update_fields = [];
                foreach ($params as $key => $value) {
                    $update_fields[] = "$key = :$key";
                }

                $sql = "UPDATE inspections SET " . implode(', ', $update_fields) . " WHERE inspection_id = :inspection_id";
                $params['inspection_id'] = $inspectionId; // Add ID for WHERE clause

                write_log("  SQL (UPDATE): " . $sql);
                write_log("  PARAMS: " . json_encode($params));
                $pdo->prepare($sql)->execute($params);
            } else {
                write_log("  No data fields to update for this user role.");
            }
        } else {
            write_log("  Action: INSERT new record.");
            $params = [
                'element_id' => $baseElementId,
                'part_name' => $partName,
                'stage_id' => $stageId,
                'user_id' => $userId,
                'overall_status' => $stageData['overall_status'] ?? null,
                'inspection_date' => toGregorian($stageData['inspection_date'] ?? null),
                'notes' => $stageData['notes'] ?? null,
                'attachments' => processAndMergeUploads('attachments', $fullElementId, null),
                'contractor_status' => $stageData['contractor_status'] ?? null,
                'contractor_date' => toGregorian($stageData['contractor_date'] ?? null),
                'contractor_notes' => $stageData['contractor_notes'] ?? null,
                'contractor_attachments' => processAndMergeUploads('contractor_attachments', $fullElementId, null)
            ];
            $columns = implode(', ', array_keys($params));
            $placeholders = ':' . implode(', :', array_keys($params));
            $sql = "INSERT INTO inspections ($columns) VALUES ($placeholders)";

            write_log("  SQL (INSERT): " . $sql);
            write_log("  PARAMS: " . json_encode($params));
            $pdo->prepare($sql)->execute($params);
            $inspectionId = $pdo->lastInsertId();
        }

        if ($inspectionId && isset($stageData['items'])) {
            write_log("  Updating checklist items for inspection ID: $inspectionId");
            $pdo->prepare("DELETE FROM inspection_data WHERE inspection_id = ?")->execute([$inspectionId]);
            $stmt_insert_item = $pdo->prepare("INSERT INTO inspection_data (inspection_id, item_id, item_status, item_value) VALUES (?, ?, ?, ?)");
            foreach ($stageData['items'] as $item) {
                $stmt_insert_item->execute([$inspectionId, $item['itemId'], $item['status'] ?? 'N/A', $item['value'] ?? '']);
            }
        }
    }

    $pdo->commit();
    write_log("SUCCESS: Transaction committed.");
    echo json_encode(['status' => 'success', 'message' => 'اطلاعات با موفقیت ذخیره شد.']);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    $error_message = "File: {$e->getFile()} | Line: {$e->getLine()} | Message: {$e->getMessage()}";
    write_log("FATAL ERROR: " . $error_message);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای داخلی سرور رخ داد. لطفا با پشتیبانی تماس بگیرید.']);
}
