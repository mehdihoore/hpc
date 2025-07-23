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

function appendNoteToJson(PDO $pdo, int $inspectionId, string $columnName, string $noteText, int $userId, string $userRole)
{
    if (empty(trim($noteText))) {
        return; // Don't add empty notes
    }

    $stmt = $pdo->prepare("SELECT $columnName FROM inspections WHERE inspection_id = ?");
    $stmt->execute([$inspectionId]);
    $currentJson = $stmt->fetchColumn();

    $dataArray = !empty($currentJson) ? json_decode($currentJson, true) : [];
    if (!is_array($dataArray)) {
        $dataArray = [];
    }

    // Create the new entry with metadata
    $dataArray[] = [
        'user_id' => $userId,
        'role' => $userRole,
        'note' => $noteText,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    $newJson = json_encode($dataArray, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("UPDATE inspections SET $columnName = ? WHERE inspection_id = ?");
    $stmt->execute([$newJson, $inspectionId]);
    write_log("Appended new note to column '$columnName' for inspection ID: $inspectionId");
}
function processAndAppendUploads(PDO $pdo, int $inspectionId, string $columnName, string $fileInputKey, string $elementId, int $userId, string $userRole)
{
    if (empty($_FILES[$fileInputKey]['name'][0])) {
        return; // No new files to process
    }

    $stmt = $pdo->prepare("SELECT $columnName FROM inspections WHERE inspection_id = ?");
    $stmt->execute([$inspectionId]);
    $currentJson = $stmt->fetchColumn();

    $dataArray = !empty($currentJson) ? json_decode($currentJson, true) : [];
    if (!is_array($dataArray)) {
        $dataArray = [];
    }

    $uploadDir = defined('MESSAGE_UPLOAD_PATH_SYSTEM') ? MESSAGE_UPLOAD_PATH_SYSTEM : 'uploads/';
    $uploadDirPublic = defined('MESSAGE_UPLOAD_DIR_PUBLIC') ? MESSAGE_UPLOAD_DIR_PUBLIC : '/uploads/';

    foreach ($_FILES[$fileInputKey]['tmp_name'] as $key => $tmpName) {
        if (is_uploaded_file($tmpName) && $_FILES[$fileInputKey]['error'][$key] === UPLOAD_ERR_OK) {
            $originalName = basename($_FILES[$fileInputKey]['name'][$key]);
            $safeFilename = "ghom_" . preg_replace('/[^a-zA-Z0-9._-]/', '_', $elementId) . "_" . time() . "_" . uniqid() . "." . pathinfo($originalName, PATHINFO_EXTENSION);
            if (move_uploaded_file($tmpName, $uploadDir . $safeFilename)) {
                // Add the new file entry with metadata
                $dataArray[] = [
                    'user_id' => $userId,
                    'role' => $userRole,
                    'file_path' => $uploadDirPublic . $safeFilename,
                    'original_name' => $originalName,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                write_log("File Upload SUCCESS: Moved '$originalName' for input '$fileInputKey'.");
            }
        }
    }

    $newJson = json_encode($dataArray, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("UPDATE inspections SET $columnName = ? WHERE inspection_id = ?");
    $stmt->execute([$newJson, $inspectionId]);
    write_log("Appended new files to column '$columnName' for inspection ID: $inspectionId");
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
        $inspectionId = $existing_inspection['inspection_id'] ?? null;

        // Step 1: Ensure an inspection record exists. INSERT if it doesn't.
        if (!$inspectionId) {
            write_log("  Action: INSERT new record for stage.");
            $stmt_insert = $pdo->prepare("INSERT INTO inspections (element_id, part_name, stage_id, user_id) VALUES (?, ?, ?, ?)");
            $stmt_insert->execute([$baseElementId, $partName, $stageId, $userId]);
            $inspectionId = $pdo->lastInsertId();
            write_log("  New inspection record created with ID: $inspectionId");
        } else {
            write_log("  Action: Found existing record ID: $inspectionId");
        }

        // Step 2: Update the main status and date fields based on role.
        $update_params = [];
        $update_fields = [];
        if ($userRole === 'admin' || $userRole === 'superuser') {
            $update_fields[] = "overall_status = :status";
            $update_params[':status'] = $stageData['overall_status'] ?? null;
            $update_fields[] = "inspection_date = :date";
            $update_params[':date'] = toGregorian($stageData['inspection_date'] ?? null);
        }
        if (in_array($userRole, ['cat', 'car', 'coa', 'crs']) || $userRole === 'superuser') {
            $update_fields[] = "contractor_status = :c_status";
            $update_params[':c_status'] = $stageData['contractor_status'] ?? null;
            $update_fields[] = "contractor_date = :c_date";
            $update_params[':c_date'] = toGregorian($stageData['contractor_date'] ?? null);
        }
        if (!empty($update_fields)) {
            $update_fields[] = "user_id = :user_id";
            $update_params[':user_id'] = $userId;
            $sql = "UPDATE inspections SET " . implode(', ', $update_fields) . " WHERE inspection_id = :id";
            $update_params[':id'] = $inspectionId;
            write_log("  Updating main fields: " . $sql);
            $pdo->prepare($sql)->execute($update_params);
        }

        // Step 3: Append notes and files using the new helper functions.
        if ($userRole === 'admin' || $userRole === 'superuser') {
            appendNoteToJson($pdo, $inspectionId, 'notes', $stageData['notes'] ?? '', $userId, $userRole);
            processAndAppendUploads($pdo, $inspectionId, 'attachments', 'attachments', $fullElementId, $userId, $userRole);
        }
        if (in_array($userRole, ['cat', 'car', 'coa', 'crs']) || $userRole === 'superuser') {
            appendNoteToJson($pdo, $inspectionId, 'contractor_notes', $stageData['contractor_notes'] ?? '', $userId, $userRole);
            processAndAppendUploads($pdo, $inspectionId, 'contractor_attachments', 'contractor_attachments', $fullElementId, $userId, $userRole);
        }

        // Step 4: Update the checklist items.
        if (isset($stageData['items'])) {
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
