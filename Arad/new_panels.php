<?php
ini_set('display_errors', 1); // Enable error display for debugging
error_reporting(E_ALL);
ob_start(); // Start output buffering to catch stray output/errors

require_once __DIR__ . '/../../sercon/config_fereshteh.php';
require_once 'includes/jdf.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function is_ajax_request() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}


if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    if (is_ajax_request()) {
        // AJAX request with invalid session: Send 401 Unauthorized
        http_response_code(401);
        header('Content-Type: application/json');
        if(ob_get_level() > 0) ob_end_clean(); // Clean buffer
        echo json_encode(['success' => false, 'error' => 'session_expired', 'message' => 'جلسه منقضی شده! لطفاً دوباره وارد شوید.']);
        exit();
    } else {
        // Regular page load with invalid session: Redirect
        header('Location: login.php');
        exit();
    }
}

// --- Date Handling ---
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$currentDate = new DateTime($date);
$yesterday = (clone $currentDate)->modify('-1 day')->format('Y-m-d');
$tomorrow  = (clone $currentDate)->modify('+1 day')->format('Y-m-d');
$jalaliDateForDisplay = jdate('Y/m/d', strtotime($date), '', 'Asia/Tehran');

// --- Define Status Sequence and Consolidated Steps ---
// Status => Previous Status (for Undo)
$statusSequence = [
    'polystyrene' => 'pending',      // Undo Polystyrene goes back to pending
     'Mesh' => 'polystyrene',         // Undo Mesh goes back to Polystyrene
     'Concreting' => 'Mesh',
     'Assembly' => 'Concreting',
     'completed' => 'Assembly',
     'Cancelled' => null
 ];
 
// Helper to get the status that precedes a given status
function getPreviousStatus($currentStatus, $sequence) {
    return $sequence[$currentStatus] ?? 'pending';
}

// Consolidated Step Configuration
// Key: action name, Value: details
$consolidatedStepsConfig = [
    'do_polystyrene' => [
        'label' => '0- قالب فوم',
        'status_required' => 'pending',
        'status_after' => 'polystyrene',
        'updates' => ['polystyrene' => 1], // Example update - adjust if needed
        'columns_to_clear_on_undo' => ['polystyrene']
    ],
    'do_mesh' => [
        'label' => '1- مش بندی',
        'status_required' => 'polystyrene',     // Must be pending to start
        'status_after' => 'Mesh',          // Set status to Mesh when done
        'updates' => ['mesh_start_time' => 'NOW()', 'mesh_end_time' => 'DATE_ADD(NOW(), INTERVAL 1 HOUR)'],
        'columns_to_clear_on_undo' => ['mesh_start_time', 'mesh_end_time']
    ],
    'do_formwork_concrete' => [
        'label' => '2- قالب بندی/بتن',
        'status_required' => 'Mesh',       // Must be Mesh to start
        'status_after' => 'Concreting',    // Set status to Concreting when done
        'updates' => ['formwork_start_time' => 'NOW()', 'concrete_start_time' => 'NOW()', 'formwork_end_time' => 'DATE_ADD(NOW(), INTERVAL 1 HOUR)', 'concrete_end_time' => 'DATE_ADD(NOW(), INTERVAL 1 HOUR)'],
        'columns_to_clear_on_undo' => ['formwork_start_time', 'concrete_start_time', 'formwork_end_time', 'concrete_end_time']
    ],
    'do_face_coat' => [
        'label' => '3- فیس کوت',
        'status_required' => 'Concreting', // Must be Concreting to start
        'status_after' => 'Assembly',      // Set status to Assembly when done (using Assembly for face coat)
        'updates' => ['assembly_start_time' => 'NOW()', 'assembly_end_time' => 'DATE_ADD(NOW(), INTERVAL 1 HOUR)'],
        'columns_to_clear_on_undo' => ['assembly_start_time', 'assembly_end_time']
    ],
    'complete_production' => [
        'label' => '4- پایان تولید',
        'status_required' => 'Assembly',   // Must be Assembly to start
        'status_after' => 'completed',     // Set status to completed when done
        'updates' => [], // *** CHANGED: Only update status via main logic ***
        'columns_to_clear_on_undo' => [] // *** CHANGED: Undo only needs to revert status ***
    ]
];
$allStatusesInOrder = array_column($consolidatedStepsConfig, 'status_after');


// Define QC Checklist Items
$qcChecklistItems = [
    // Step Name => Label
    'qc_mold_cleaning_oiling' => 'نظافت قالب و اجرای روغن قالب',
    'qc_mesh_health' => 'سلامت مش',
    'qc_embedded_parts_placement' => 'جانمایی قطعات مدفون',
    'qc_foam_placement' => 'جایگذاری صحیح یونولیت ها',
    'qc_spacer_placement' => 'قراردادن اسپیسر و حصول اطمینان از فاصله مش از بک',
    'qc_dimensional_control_mold_foam' => 'کنترل ابعادی قالب و یونولیت',
    'qc_placement' => 'جانمایی', // Needs more context - جانمایی of what? Assuming overall placement check.
    'qc_mix_design_usage' => 'استفاده از طرح اختلاط مناسب',
    // 'qc_mold_cleaning' => 'نظافت قالب', // Duplicate? Handled in first item. Can keep if needed.
    'qc_proper_curing' => 'عمل آوری مناسب',
    'qc_labeling' => 'لیبل گذاری',
    'qc_dimensional_control_final' => 'کنترل ابعادی نهایی',
    'qc_face_coat_execution' => 'اجرای صحیح فیس کوت',
    'qc_panel_image_upload' => 'آپلود و نمایش تصویر پنل QC شده' // File upload step
];
$qcStepNames = array_keys($qcChecklistItems); // Get just the names

try {
    $pdo = connectDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Ensure PDO throws exceptions

    // --- Fetch Panels ---
    $stmt = $pdo->prepare("
        SELECT p.id, p.address, p.type, p.assigned_date, p.status, p.user_id,
               p.mesh_start_time, p.mesh_end_time,
               p.formwork_start_time, p.formwork_end_time,
               p.concrete_start_time, p.concrete_end_time,
               p.assembly_start_time, p.assembly_end_time,
               p.cancel_count, p.last_cancel_time
        FROM hpc_panels p
        WHERE DATE(p.assigned_date) = ?
        ORDER BY p.address
    ");
    $stmt->execute([$date]);
    $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Fetch Panel QC Details for all panels on the page ---
    $panelQcDetails = [];
    if (!empty($panels)) {
        $panelIds = array_column($panels, 'id');
        $placeholders = implode(',', array_fill(0, count($panelIds), '?'));

        // *** Add pd.notes to the SELECT list ***
        $sqlDetails = "
            SELECT pd.*, pd.notes, pa.id as attachment_id, pa.file_path, pa.original_filename,
                   u.username as user_name, s.username as supervisor_name
            FROM hpc_panel_details pd
            LEFT JOIN hpc_panel_attachments pa ON pa.panel_detail_id = pd.id
            LEFT JOIN users u ON pd.user_id = u.id
            LEFT JOIN users s ON pd.supervisor_id = s.id
            WHERE pd.panel_id IN ($placeholders)
        ";
        // **************************************

        $stmtDetails = $pdo->prepare($sqlDetails);
        $stmtDetails->execute($panelIds);
        $detailsResult = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

        // Organize details - Add 'notes' to the array
        foreach ($detailsResult as $detail) {
            $pId = $detail['panel_id'];
            $sName = $detail['step_name'];
            if (!isset($panelQcDetails[$pId])) {
                $panelQcDetails[$pId] = [];
            }
            if (!isset($panelQcDetails[$pId][$sName])) {
                 $panelQcDetails[$pId][$sName] = [
                     'id' => $detail['id'],
                     'is_completed' => $detail['is_completed'],
                     'value' => $detail['value'],
                     'status' => $detail['status'],
                     'rejection_reason' => $detail['rejection_reason'],
                     'notes' => $detail['notes'], // *** Add notes here ***
                     'user_id' => $detail['user_id'],
                     // ... rest of the fields ...
                     'attachments' => []
                 ];
             }
             // Attachment logic remains the same
             if ($detail['attachment_id']) {
                 $panelQcDetails[$pId][$sName]['attachments'][] = [
                     'id' => $detail['attachment_id'],
                     'file_path' => $detail['file_path'],
                     'original_filename' => $detail['original_filename']
                 ];
            }
        }
    }




    // --- Handle POST Actions ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Ensure session didn't expire *between* page load and the POST request
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
             // It MUST be AJAX if it gets here for POST after the top check
             http_response_code(401); // Unauthorized
             header('Content-Type: application/json');
             if(ob_get_level() > 0) ob_end_clean();
             echo json_encode(['success' => false, 'error' => 'session_expired', 'message' => 'Session expired before action. Please log in again.']);
             exit();
        }
        // ******** START: ROLE CHECK ********
    $allowedEditRoles = ['admin', 'supervisor'];
    if (!in_array($_SESSION['role'], $allowedEditRoles)) {
             http_response_code(403); // Forbidden
             header('Content-Type: application/json');
             if(ob_get_level() > 0) ob_end_clean();
             echo json_encode(['success' => false, 'error' => 'forbidden', 'message' => 'شما اجازه انجام این عملیات را ندارید.']);
             exit();
        }
    // ******** END: ROLE CHECK ********
        $response = ['success' => false, 'message' => 'Invalid request.']; // Default
        // --- *** NEW: Handle QC Status Check Action *** ---
        if (isset($_POST['action']) && $_POST['action'] === 'check_qc_completion' && isset($_POST['panel_id'])) {
            $panelId = $_POST['panel_id'];
            $allQcComplete = false; // Assume false initially

            try {
                 $qcPlaceholders = implode(',', array_fill(0, count($qcStepNames), '?'));
                 $sqlCheck = "SELECT COUNT(*) FROM hpc_panel_details
                             WHERE panel_id = ?
                             AND step_name IN ($qcPlaceholders)
                             AND status = 'completed' AND is_completed = 1"; // Check for completed status

                 $params = array_merge([$panelId], $qcStepNames);
                 $stmtCheck = $pdo->prepare($sqlCheck);
                 $stmtCheck->execute($params);
                 $completedCount = (int) $stmtCheck->fetchColumn();

                 if ($completedCount === count($qcStepNames)) {
                     $allQcComplete = true;
                 }
                 $response = ['success' => true, 'all_qc_complete' => $allQcComplete];

            } catch (PDOException $e) {
                 error_log("DB Action Error (Check QC): " . $e->getMessage());
                 $response = ['success' => false, 'message' => 'Database error checking QC status: ' . $e->getMessage()];
            }
            // Output JSON and exit for this specific action
             if (ob_get_level() > 0) ob_end_clean();
             header('Content-Type: application/json');
             echo json_encode($response);
             exit;
        }
        // --- A) Handle Main Progress Steps (action, undo_action, cancel_panel) ---
        elseif (isset($_POST['action'], $_POST['panel_id']) && !isset($_POST['step_name']) && $_POST['action'] !== 'save_qc_note') {
            $panelId = $_POST['panel_id'];
            $action  = $_POST['action'];
            $userId = $_SESSION['user_id'];

            $pdo->beginTransaction();
            try {
                // --- Handle Step Progression ---
                 if (isset($consolidatedStepsConfig[$action])) {
                     $logic = $consolidatedStepsConfig[$action];
                     $setClauses = []; $params = [];
                     foreach ($logic['updates'] as $column => $value) {
                          if ($value === 'NOW()' || (substr($value, 0, strlen('DATE_ADD')) === 'DATE_ADD')) {
                             $setClauses[] = "`" . $column . "` = " . $value;
                         } else { $setClauses[] = "`" . $column . "` = ?"; $params[] = $value; }
                     }
                      if ($action === 'complete_production') {
                 $setClauses[] = "planned_finish_date = NOW()"; // Add the date update directly
                 // No parameters needed for NOW()
             }
                     $setClauses[] = "status = ?"; $params[] = $logic['status_after'];
                     $params[] = $panelId; // WHERE clause

                     $sql = "UPDATE hpc_panels SET " . implode(', ', $setClauses) . " WHERE id = ?";
                     $stmt = $pdo->prepare($sql);
                     $stmt->execute($params);
                     $panelUpdateSuccess = $stmt->execute($params);
                     if ($panelUpdateSuccess) {
                        // --- *** NEW: Update polystyrene_orders if action is do_polystyrene *** ---
                        if ($action === 'do_polystyrene') {
                            // IMPORTANT: Verify this column name relationship!
                            // Assuming 'panel_id' in polystyrene_orders links to 'id' in hpc_panels
                            $stmtPoly = $pdo->prepare("
                                UPDATE polystyrene_orders
                                SET status = 'delivered', production_end_date = NOW()
                                WHERE hpc_panel_id = ?
                            ");
                            $polyUpdateSuccess = $stmtPoly->execute([$panelId]);
                            if(!$polyUpdateSuccess) {
                                error_log("Failed to update polystyrene_orders for panel_id: " . $panelId);
                                // Decide if this should cause a rollback: throw new PDOException("Failed to update polystyrene order status.");
                             } else {
                                error_log("Polystyrene order update attempted for panel_id: " . $panelId . ". Rows affected: " . $stmtPoly->rowCount());
                             }
                         }
                         // --- *** END: Polystyrene Update *** ---

                        $response = ['success' => true, 'new_status' => $logic['status_after']];
                    } else {
                        // This part might not be reached if execute throws an exception on failure
                        throw new PDOException("Failed to update hpc_panels for action: " . $action);
                    }

                }
                  // --- C) *** NEW: Handle Save Note Action *** ---
        elseif (isset($_POST['action']) && $_POST['action'] === 'save_qc_note' && isset($_POST['panel_id'], $_POST['step_name'], $_POST['notes'])) {
            $panelId = $_POST['panel_id'];
            $stepName = $_POST['step_name'];
            $notes = $_POST['notes'];
             if(empty(trim($notes))) $notes = null; // Treat empty as null
            $userId = $_SESSION['user_id'];

            // We only update the notes and updated_at timestamp
             $pdo->beginTransaction();
             try {
                  // Check if detail record exists, create if not (basic version)
                  $stmtCheck = $pdo->prepare("SELECT id FROM hpc_panel_details WHERE panel_id = ? AND step_name = ?");
                  $stmtCheck->execute([$panelId, $stepName]);
                  $detailId = $stmtCheck->fetchColumn();
                    if (!$detailId) {
                                        $stmtInsert = $pdo->prepare("INSERT INTO hpc_panel_details (panel_id, step_name, is_completed, status, notes, user_id, created_at, updated_at) VALUES (?, ?, 0, 'pending', ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                      $stmtInsert->execute([$panelId, $stepName, $notes, $userId]);
                  }else {
                     $stmtUpdate = $pdo->prepare("UPDATE hpc_panel_details SET notes = ?, user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE panel_id = ? AND step_name = ?");
                     $stmtUpdate->execute([$notes, $userId, $panelId, $stepName]);
                 }
                  $pdo->commit();
                 $response = ['success' => true, 'message' => 'یادداشت ذخیره شد.', 'qc_notes' => $notes];


             }  catch (PDOException $e) {
                 $pdo->rollBack(); // Rollback on error
                 error_log("DB Action Error (Save Note): " . $e->getMessage());
                 $response = ['success' => false, 'message' => 'Database error saving note: ' . $e->getMessage()];
             }

        }
                 // --- Handle Undo Actions ---
                  elseif (substr($action, 0, strlen('undone_')) === 'undone_') {
                     $originalAction = str_replace('undone_', '', $action);
                     if (isset($consolidatedStepsConfig[$originalAction])) {
                        $logic = $consolidatedStepsConfig[$originalAction];
                        $targetStatus = $logic['status_after']; // Status to undo FROM
                        $previousStatus = getPreviousStatus($targetStatus, $statusSequence);
                        $columnsToClear = $logic['columns_to_clear_on_undo'];

                        $setClauses = [];
                        foreach ($columnsToClear as $col) { $setClauses[] = "`" . $col . "` = NULL"; }
                        $setClauses[] = "status = ?";

                        $sql = "UPDATE hpc_panels SET " . implode(', ', $setClauses) . " WHERE id = ? AND status = ?";
                        $stmt = $pdo->prepare($sql);
                        $success = $stmt->execute([$previousStatus, $panelId, $targetStatus]);

                        if ($success && $stmt->rowCount() > 0) {
                            $response = ['success' => true, 'new_status' => $previousStatus];
                        } else {
                            $response = ['success' => false, 'message' => 'Status changed or step not last completed.'];
                            $pdo->rollBack();
                            ob_end_clean(); // Clean buffer before outputting JSON
                            header('Content-Type: application/json');
                            echo json_encode($response);
                            exit;
                        }
                     } else { $response['message'] = "Undo logic not found."; }
                 }
                  // --- Handle Cancel Panel ---
                 elseif ($action === 'cancel_panel') {
                    $panelUpdateSql = "UPDATE hpc_panels
                    SET mesh_start_time=NULL,
                        mesh_end_time=NULL,
                        formwork_start_time=NULL,
                        formwork_end_time=NULL,
                        concrete_start_time=NULL,
                        concrete_end_time=NULL,
                        assembly_start_time=NULL,
                        assembly_end_time=NULL,
                        assigned_date=NULL,
                        planned_finish_date=NULL,
                        planned_finish_user_id=NULL,
                        status='pending',
                        polystyrene = 0, -- Also clear polystyrene marker if applicable
                        last_cancel_time = NOW(),  -- <<< ADDED: Record cancel time
                        cancel_count = cancel_count + 1 -- <<< ADDED: Increment count
                    WHERE id = ?";
                    $stmtPanel = $pdo->prepare($panelUpdateSql);
                    $panelSuccess = $stmtPanel->execute([$panelId]);
                    $detailsDeleteSql = "DELETE FROM hpc_panel_details WHERE panel_id = ?";
                    $stmtDetails = $pdo->prepare($detailsDeleteSql);
                    $detailsSuccess = $stmtDetails->execute([$panelId]); // Attempt to delete details
                     // Also delete attachments associated with those details (optional but good practice)
                    // This requires finding detail IDs first if needed, or rely on cascade delete if set up in DB.
                    // For simplicity here, we just delete the details.

                    if ($panelSuccess) {
                        $response = ['success' => true, 'new_status' => 'Cancelled'];
                    } else { throw new PDOException("Failed to cancel panel."); }
                 }
                 else {
                    $response['message'] = "Action not recognized.";
                    // Don't commit if action wasn't recognized
                    $pdo->rollBack(); // Explicit rollback for unrecognized action within transaction block
                    // Output JSON response and exit (already handled below)
                    if (ob_get_level() > 0) ob_end_clean();
                    header('Content-Type: application/json');
                    echo json_encode($response);
                    exit;
                }

               $pdo->commit(); // COMMIT TRANSACTION if all steps succeeded

           }catch (PDOException $e) {
                $pdo->rollBack();
                error_log("DB Action Error (Progress/Undo/Cancel): " . $e->getMessage());
                $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            }

        // --- B) Handle QC Detail Checklist Updates ---
            } elseif (isset($_POST['step_name'], $_POST['panel_id'])) {
                $panelId = $_POST['panel_id'];
                $stepName = $_POST['step_name'];
                // Determine initial completion state based on non-file POST data
                $isCompleted = isset($_POST['is_completed']) && $_POST['is_completed'] === 'true' ? 1 : 0;
                $value = $_POST['value'] ?? null;
                $status = $_POST['status'] ?? 'pending';
                $rejectionReason = ($_POST['rejection_reason'] ?? null);
                if(empty(trim($rejectionReason))) $rejectionReason = null;
                $notes = ($_POST['notes'] ?? null);// *** Get notes from POST ***
                // Treat empty string as null for notes
                if(empty(trim($notes))) $notes = null;
                $supervisorId = ($_SESSION['role'] === 'supervisor' || $_SESSION['role'] === 'admin') ? $_SESSION['user_id'] : null;
                $userId = $_SESSION['user_id'];
                $atLeastOneFileUploaded = false; // Flag to track successful uploads

                $pdo->beginTransaction();
                try {
                    // Update/Insert the main QC Detail Step first (e.g., to mark it started/rejected/undone)
                    // If files are uploaded successfully later, we'll update it again to completed.
                     $stmt = $pdo->prepare("
                        INSERT INTO hpc_panel_details (panel_id, step_name, is_completed, value, status, rejection_reason, notes, user_id, supervisor_id, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ON DUPLICATE KEY UPDATE
                            is_completed = VALUES(is_completed), value = VALUES(value), status = VALUES(status),
                            rejection_reason = VALUES(rejection_reason), notes = VALUES(notes), -- Add notes update
                            user_id = VALUES(user_id), supervisor_id = VALUES(supervisor_id), updated_at = CURRENT_TIMESTAMP
                    ");
                     // Use initially determined status/completion. Will be updated if files are uploaded.
                     $stmt->execute([$panelId, $stepName, $isCompleted, $value, $status, $rejectionReason, $notes, $userId, $supervisorId]);
                     $panelDetailId = $pdo->lastInsertId();
                     if ($panelDetailId == 0) {
                         $stmtId = $pdo->prepare("SELECT id FROM hpc_panel_details WHERE panel_id = ? AND step_name = ?");
                         $stmtId->execute([$panelId, $stepName]);
                         $panelDetailId = $stmtId->fetchColumn();
                     }

                     if(!$panelDetailId && $stepName === 'qc_panel_image_upload' && isset($_FILES['qc_files'])) {
                        // We need a detail ID to associate files with, even if it's the first time.
                        // The above INSERT should have created it. If not, something is wrong.
                        throw new Exception("Failed to get or create panel detail ID for QC image step.");
                     }


                    // --- *** MODIFIED File Upload Handling for Multiple Files *** ---
                    if ($stepName === 'qc_panel_image_upload' && isset($_FILES['qc_files'])) // Check for 'qc_files' (plural suggested name from JS)
                    {
                        // Define and Check/Create QCDocs Subdirectory (same as before)
                        $qcUploadSubDir = 'QCDocs';
                        $qcUploadDirPath = rtrim(UPLOAD_DIR, '/') . '/' . $qcUploadSubDir . '/';
                        if (!is_dir($qcUploadDirPath)) { if (!mkdir($qcUploadDirPath, 0775, true)) { throw new Exception("Failed to create QC upload subdirectory: " . $qcUploadDirPath); } }
                        if (!is_writable($qcUploadDirPath)) { throw new Exception("QC Upload subdirectory is not writable: " . $qcUploadDirPath); }

                        // Check if $_FILES['qc_files']['name'] is an array (multiple files) or string (single file)
                        $files = $_FILES['qc_files'];
                        $numFiles = is_array($files['name']) ? count($files['name']) : ($files['error'] === UPLOAD_ERR_OK ? 1 : 0);
                        $uploadedFilePaths = []; // Store paths of successfully uploaded files

                        for ($i = 0; $i < $numFiles; $i++) {
                            $fileName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                            $fileTmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                            $fileError = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                            $fileSize = is_array($files['size']) ? $files['size'][$i] : $files['size'];
                            $fileType = is_array($files['type']) ? $files['type'][$i] : $files['type']; // Get type

                            // Skip if there's an upload error for this specific file
                            if ($fileError !== UPLOAD_ERR_OK) {
                                error_log("Skipping file upload due to error: " . $fileError . " for file " . $fileName);
                                // Optionally: Collect error messages to send back to the user
                                continue; // Move to the next file
                            }

                            $originalFilename = basename($fileName);
                            $fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
                            $uniqueFilename = uniqid('qc_' . $panelId . '_', true) . '.' . $fileExtension;
                            $filePath = $qcUploadDirPath . $uniqueFilename;

                            // --- Validation ---
                            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                            if (!in_array($fileType, $allowedTypes)) {
                                error_log("Invalid file type: " . $fileType . " for file " . $originalFilename); continue;
                            }
                            $maxSize = 5 * 1024 * 1024; // 5 MB
                            if ($fileSize > $maxSize) {
                                error_log("File size exceeds limit for file " . $originalFilename); continue;
                            }
                            // --- End Validation ---

                            if (move_uploaded_file($fileTmpName, $filePath)) {
                                // *** CHANGE: DO NOT DELETE existing attachments ***
                                // Insert new attachment record
                                $stmtAttach = $pdo->prepare("
                                    INSERT INTO hpc_panel_attachments (panel_detail_id, file_path, file_type, original_filename, user_id)
                                    VALUES (?, ?, ?, ?, ?)
                                ");
                                // Handle created_at based on your previous choice
                                $stmtAttach->execute([$panelDetailId, $filePath, $fileType, $originalFilename, $userId]);
                                $atLeastOneFileUploaded = true; // Mark success
                                $uploadedFilePaths[] = $filePath; // Store path for potential response

                            } else {
                                error_log('Failed to move uploaded file: '.$originalFilename.'. Temp: '.$fileTmpName.' Dest: '.$filePath);
                                // Optionally: Collect error messages
                            }
                        } // End for loop through files

                        // If at least one file was uploaded successfully, mark the QC step as completed
                        if ($atLeastOneFileUploaded) {
                            $stmtUpdateDetail = $pdo->prepare("UPDATE hpc_panel_details SET is_completed = 1, status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                            $stmtUpdateDetail->execute([$panelDetailId]);
                             $status = 'completed'; // Update status variable for response
                             $isCompleted = 1;      // Update completion variable for response
                        }

                    } // End multi-file upload handling check

                    $pdo->commit();
                     // Include newly uploaded file info in response if needed, or just success
                     $response = [
                         'success' => true,
                         'message' => $atLeastOneFileUploaded ? 'File(s) uploaded and QC step updated.' : 'جزییات کنترل کیفی آپدیت شد.',
                         'panel_detail_id' => $panelDetailId,
                         'qc_status' => $status, // Return the final status
                         'qc_is_completed' => $isCompleted, // Return the final completion state
                         'qc_notes' => $notes // Optionally return notes for UI update
                     ];


                } catch (Exception $e) { // Catch Exception or PDOException
                    $pdo->rollBack();
                    error_log("DB/File Action Error (QC Detail): " . $e->getMessage());
                    $userMessage = ($e instanceof PDOException) ? 'Database error.' : $e->getMessage();
                    $response = ['success' => false, 'message' => 'Error saving QC detail: ' . $userMessage];
                }
            } // End QC Detail Handling



       if (ob_get_level() > 0) { // Check if buffer is active
             ob_end_clean();
         }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    } // End POST check

} catch (PDOException $e) { // Catch connection errors
    ob_end_clean(); // Clean buffer
    error_log("Database Connection/Fetch Error: " . $e->getMessage());
    // Don't die here if it's just fetching, show the page structure with an error message

      if (is_ajax_request()) { // Send JSON error for AJAX DB errors too
         http_response_code(500); // Internal Server Error
         if(ob_get_level() > 0) ob_end_clean();
         header('Content-Type: application/json');
         echo json_encode(['success' => false, 'error' => 'db_error', 'message' => 'Database error occurred.']);
         exit();
     } else {
         $pageError = "خطا در ارتباط با پایگاه داده.";
     }
    //die("خطا در ارتباط با پایگاه داده.");
} catch (Exception $e) { // Catch other general errors during setup
    ob_end_clean();
    error_log("General Error: " . $e->getMessage());
     if (is_ajax_request()) { // Send JSON error for AJAX general errors
         http_response_code(500);
         if(ob_get_level() > 0) ob_end_clean();
         header('Content-Type: application/json');
         echo json_encode(['success' => false, 'error' => 'general_error', 'message' => 'A system error occurred.']);
         exit();
     } else {
        $pageError = "خطای سیستمی رخ داده است.";
     }
}


// --- Helper Functions (PHP) ---
function getStatusText($status) {
    $translations = [
        'pending' => 'در انتظار',
        'polystyrene' => 'قالب فوم', // <<<== Add translation
        'Mesh' => 'مش بندی',
        'Formwork' => 'قالب بندی',
        'Concreting' => 'بتن ریزی',
        'Assembly' => 'فیس کوت',
        'completed' => 'تکمیل شده',
        'Cancelled' => 'لغو شده',
    ];
    return $translations[$status] ?? $status;
}
function getStatusClass($status) {
    // Style based on the actual DB status
     switch ($status) {
        case 'pending': return 'bg-gray-100 text-gray-800';
        case 'polystyrene': return 'bg-teal-100 text-teal-800'; // <<<== Add class (choose colors)
        case 'Mesh': return 'bg-blue-100 text-blue-800';
        case 'Formwork': return 'bg-yellow-100 text-yellow-700'; // Original color
        case 'Concreting': return 'bg-yellow-200 text-yellow-800'; // Use a distinct yellow
        case 'Assembly': return 'bg-purple-100 text-purple-800'; // Use purple for Assembly/Face Coat
        case 'completed': return 'bg-green-100 text-green-800';
        case 'Cancelled': return 'bg-red-100 text-red-800 font-semibold';
        default: return 'bg-gray-100 text-gray-800';
    }
}
ob_end_flush(); 
 
?>
<?php
$pageTitle = "پیگیری مراحل ساخت پنل";
require_once 'header.php'; // Ensure header doesn't output anything before this point
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link href="assets/css/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="assets/css/pikaday.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.css">
    <style>
        @font-face { font-family: 'Vazir'; src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2'); }
        body { font-family: 'Vazir', sans-serif; }
        .modal { transition: opacity 0.3s ease; opacity: 0; visibility: hidden; position: fixed; inset: 0; background-color: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 50; }
        .modal.show { opacity: 1; visibility: visible; }
        .modal-content { background-color: white; padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); max-width: 90%; width: 500px; }
        #panel-details-container { background-color: #f0f4f8; border: 1px solid #dae1e7; border-radius: 0.375rem; padding: 1rem; margin-bottom: 1rem; display: none; position: relative; }
        #panel-details-container .close-details { position: absolute; top: 0.5rem; left: 0.5rem; cursor: pointer; font-weight: bold; color: #555; padding: 0.25rem 0.5rem; border-radius: 0.25rem; background-color: #eee; border: 1px solid #ccc; line-height: 1; }
        #panel-details-container .close-details:hover { background-color:#ddd; }

        /* Action Buttons Styling */
        .action-button-group { display: inline-flex; align-items: center; margin-right: 4px; margin-bottom: 4px; border: 1px solid #ccc; border-radius: 0.25rem; overflow: hidden; }
        .step-button { padding: 0.3rem 0.6rem; font-size: 0.8rem; border-right: 1px solid #ccc; background-color: #e0e0e0; color: #555; transition: background-color 0.2s ease; min-width: 90px; text-align: center; }
        .step-button:last-child { border-right: none; }
        .undone-button { padding: 0.3rem 0.5rem; font-size: 0.8rem; background-color: #fff3cd; color: #856404; transition: background-color 0.2s ease; }
        .undone-button:hover:not(:disabled) { background-color: #ffeeba; }
        .action-button-group .step-button.enabled { background-color: #0d6efd; color: white; cursor: pointer; }
        .action-button-group .step-button.enabled:hover { background-color: #0b5ed7; }
        .action-button-group .step-button.completed { background-color: #198754; color: white; cursor: default; }
        .action-button-group .step-button.disabled { background-color: #e9ecef; color: #adb5bd; cursor: not-allowed; }
        .action-button-group .undone-button.disabled { background-color: #e9ecef; color: #adb5bd; cursor: not-allowed; }

        /* QC, Cancel Buttons */
        .qc-button, .cancel-button { padding: 0.3rem 0.75rem; font-size: 0.875rem; border-radius: 0.25rem; cursor: pointer; transition: background-color 0.2s ease; margin-left: 6px; }
        .qc-button { background-color: #6f42c1; color: white; }
        .qc-button:hover:not(:disabled) { background-color: #5a31a4; }
        .qc-button.disabled { background-color: #e2d9f3; color: #49396a; cursor: not-allowed; }
        .cancel-button { background-color: #dc3545; color: white; }
        .cancel-button:hover:not(:disabled) { background-color: #c82333; }
        .cancel-button.disabled { background-color: #f8d7da; color: #721c24; cursor: not-allowed; }

        /* Inline QC Details Section */
        .qc-details-section { border: 1px solid #e2d9f3; background-color: #faf8ff; padding: 10px; margin-top: 10px; border-radius: 4px; }
        .qc-step-item { margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee; display: flex; flex-wrap: wrap; align-items: center; gap: 5px; }
        .qc-step-item:last-child { border-bottom: none; }
        .qc-step-item label { font-weight: 500; min-width: 200px; flex-basis: 250px; flex-grow: 1;}
        .qc-step-item .qc-actions button { font-size: 0.75rem; padding: 2px 6px; border-radius: 3px; }
        .qc-ok-btn { background-color: #28a745; color: white; } .qc-ok-btn:hover:not(:disabled) { background-color: #218838;}
        .qc-undone-btn { background-color: #ffc107; color: black; } .qc-undone-btn:hover:not(:disabled) { background-color: #e0a800;}
        .qc-reject-btn { background-color: #dc3545; color: white; } .qc-reject-btn:hover:not(:disabled) { background-color: #c82333;}
        .qc-actions button:disabled { background-color: #ccc; cursor: not-allowed; }
        .qc-rejection-reason { color: red; font-size: 0.8em; width: 100%; margin-top: 4px; margin-right: 10px; /* Indent slightly */ }
        .qc-attachments a { color: blue; text-decoration: underline; font-size: 0.8em; margin-left: 5px; }
         /* File input styling */
        .qc-file-input-label { background-color: #17a2b8; color: white; padding: 3px 8px; border-radius: 3px; cursor: pointer; display: inline-block; font-size: 0.75rem; }
        .qc-file-input-label:hover { background-color: #138496; }
        .hidden-qc-file-input { display: none; }
        .qc-attachment-display span { font-size: 0.8em; margin-right: 5px; color: #555; }
        .qc-delete-file-btn { background-color: #e74c3c; color: white; border: none; padding: 1px 4px; font-size: 0.7em; cursor: pointer; border-radius: 2px; margin-right: 5px; }

         /* Rejection Modal */
        #rejectionModal textarea { width: 100%; min-height: 80px; border: 1px solid #ccc; padding: 5px; border-radius: 4px; margin-bottom: 10px;}
 #datePickerInput {
            padding: 0.5rem 1rem;
            border: 1px solid #ccc;
            border-radius: 0.375rem; /* rounded-md */
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* shadow-sm */
            cursor: pointer;
            text-align: center;
            min-width: 150px;
            background-color: white;
        }

        /* Ensure Pikaday calendar appears above other elements */
        .pika-single {
            z-index: 9999 !important;
        }
         /* Make calendar RTL and use Vazir font */
         .pika-lendar {
             direction: rtl;
             font-family: 'Vazir', sans-serif !important; /* Force Vazir */
             float: none; /* Override default float for better positioning */
             margin: 5px auto; /* Center if needed */
         }
         .pika-lendar th, .pika-lendar td {
              font-family: 'Vazir', sans-serif !important; /* Force Vazir */
         }
         .pika-select select{
              font-family: 'Vazir', sans-serif !important;
         }
         .qc-note-toggle { cursor: pointer; color: #6c757d; /* gray */ font-size: 0.9em; margin-right: 10px; }
        .qc-note-toggle:hover { color: #0d6efd; /* blue */ }
        .qc-note-area { width: 100%; margin-top: 5px; border-top: 1px dashed #ccc; padding-top: 5px; }
        .qc-note-area textarea { width: 98%; display: block; margin: 0 auto; border: 1px solid #ced4da; border-radius: 0.25rem; padding: 0.375rem 0.75rem; font-size: 0.8rem; min-height: 50px; }
        .qc-note-display { font-size: 0.8em; color: #343a40; background-color: #f8f9fa; border: 1px solid #e9ecef; padding: 5px 8px; border-radius: 3px; margin-top: 5px; width: 100%; white-space: pre-wrap; /* Preserve line breaks */ }
        .qc-note-display strong { color: #0056b3; } /* Optional: Highlight user who added note */

    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-4">
            <h1 class="text-2xl font-semibold text-gray-700"><?php echo htmlspecialchars($pageTitle); ?></h1>
            <span class="mx-2 text-gray-400"></span>
            <div class="flex items-center">
                <button type="button" style="background-color: #f44336; color: white; border: none; padding: 10px 20px; border-radius: 5px; font-family: 'Vazir', sans-serif; font-size: 14px; cursor: pointer; text-decoration: none;">
                 <a href="print_multi_label.php" style="color: white; text-decoration: none;">چاپ برچسبها</a>
             </button>
                </div>  
            <div class="flex gap-4 items-center">
                <a href="?date=<?php echo $yesterday; ?>" id="prevDayLink" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">روز قبل</a>

                <!-- Input for Persian Datepicker -->
                <input type="text" id="persianDatePickerTrigger" readonly
                       class="persian-datepicker-input bg-white px-4 py-2 rounded shadow border border-gray-300 text-center cursor-pointer"
                       value="<?php echo htmlspecialchars($jalaliDateForDisplay); ?>"
                       data-gregorian-date="<?php echo $date; ?>" />
                 <!-- We might need a hidden input to store the Gregorian value if the picker doesn't handle it -->
                 <input type="hidden" id="gregorianDateValue" value="<?php echo $date; ?>">


                <a href="?date=<?php echo $tomorrow; ?>" id="nextDayLink" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">روز بعد</a>
            </div>
        </div>

         <!-- Container for Panel Detail Report (shown on same page via Address link) -->
         <div id="panel-details-container"></div>

          <?php if (isset($pageError)): ?>
             <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                 <strong class="font-bold">خطا!</strong>
                 <span class="block sm:inline"><?php echo htmlspecialchars($pageError); ?></span>
             </div>
         <?php endif; ?>


        <div class="bg-white rounded-lg shadow-md overflow-x-auto">
            <table class="min-w-full" id="panels-table">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">آدرس</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نوع</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">وضعیت فعلی</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[650px]">عملیات</th>
                    </tr>
                </thead>
                 <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($panels) && !isset($pageError)): ?>
                        <tr><td colspan="4" class="px-6 py-4 text-center text-gray-500">هیچ پنلی برای این تاریخ یافت نشد.</td></tr>
                        <?php
// Define $canEditPHP early in the panel loop
                        $canEditPHP = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'supervisor']);
                        ?>
                    <?php elseif (!empty($panels)): ?>
                        <?php foreach ($panels as $panel):
                            // --- Get Panel Data for the Row ---
                            $panelId = $panel['id'];
                            $currentStatus = $panel['status'] ?? 'pending';
                            $isCancelled = ($currentStatus === 'Cancelled');
                            $cancelCount = $panel['cancel_count'] ?? 0; // Get cancel count
                            $lastCancelTime = $panel['last_cancel_time'] ?? null; // Get last cancel time
                            $currentStatusIndex = array_search($currentStatus, $allStatusesInOrder);
                            if ($currentStatus === 'pending') $currentStatusIndex = -1;
                         ?>
                         <!-- Start of the Table Row for this Panel -->
                        <tr data-panel-id="<?php echo $panelId; ?>" class="<?php echo $isCancelled ? 'opacity-60 bg-red-50' : ''; ?>">

                            <!-- Column 1: آدرس -->
                            <td class="px-4 py-4 whitespace-nowrap">
                                <a href="#" class="text-blue-600 hover:text-blue-900 panel-detail-link" data-panel-id="<?php echo $panelId; ?>">
                                    <?php echo htmlspecialchars($panel['address']); ?>
                                </a>
                            </td>

                            <!-- Column 2: نوع -->
                            <td class="px-4 py-4 whitespace-nowrap">
                                <?php echo htmlspecialchars($panel['type'] ?? '-'); ?>
                            </td>

                            <!-- Column 3: وضعیت فعلی -->
                            <td class="px-4 py-4 whitespace-nowrap panel-status-cell">
                                <span class="status-badge px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo getStatusClass($currentStatus); ?>">
                                    <?php echo htmlspecialchars(getStatusText($currentStatus)); ?>
                                </span>
                            </td>
                            
                            <!-- Column 4: عملیات (Contains Main Buttons and Hidden QC) -->
                            <td class="px-4 py-4 whitespace-nowrap action-cell">
                            <?php if ($cancelCount > 0): ?>
                                    <div class="text-xs text-orange-600 mb-1 italic" title="<?php echo $lastCancelTime ? 'آخرین لغو: ' . htmlspecialchars(jdate('Y/m/d H:i', strtotime($lastCancelTime))) : ' قبلاً لغو شده'; ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline-block mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                          <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        قبلاً <?php echo htmlspecialchars($cancelCount); ?> بار لغو شده
                                    </div>
                                <?php endif; ?>
                                <!-- *** END CANCELLATION NOTICE *** -->

                                <div class="main-actions flex flex-wrap gap-2 items-center mb-2">
                                    <?php
                                    // Define $canEditPHP early in the panel loop
                                    $canEditPHP = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'supervisor']);
                                    ?>  
                                    <?php foreach ($consolidatedStepsConfig as $stepAction => $step):
                                        // --- Button Logic Calculation (remains the same) ---
                                        $stepStatusAfter = $step['status_after'];
                                        $stepStatusRequired = $step['status_required'];
                                        $stepStatusIndex = array_search($stepStatusAfter, $allStatusesInOrder);
                                        $isStepCompleted = ($currentStatusIndex !== false && $stepStatusIndex !== false && $currentStatusIndex >= $stepStatusIndex);
                                        if ($currentStatus === 'completed' && $stepAction === 'complete_production') $isStepCompleted = true;
                                        $isStepEnabled = ($currentStatus === $stepStatusRequired) && !$isStepCompleted && !$isCancelled;
                                        $isUndoEnabled = ($currentStatus === $stepStatusAfter) && !$isCancelled;

                                        // --- *** NEW CONDITION for FINAL STEP UNDO *** ---
                                        $isFinalStep = ($stepAction === 'complete_production');
                                        $disableFinalUndo = $isFinalStep; // Disable undo specifically for the final step
                                        // --- *** END NEW CONDITION *** ---
                                        // *** FINAL Check: Combine status logic AND canEditPHP ***
                                        $isStepButtonDisabled = !$isStepEnabled || $isCancelled || !$canEditPHP; // Must meet status req AND be editable
                                        $isUndoButtonDisabled = !$isUndoEnabled || $disableFinalUndo || $isCancelled || !$canEditPHP; // Must meet status req AND be editable
                                        $stepButtonClass = 'step-button ';
                                        if ($isStepCompleted) $stepButtonClass .= 'completed';
                                        elseif ($isStepEnabled && !$isCancelled) $stepButtonClass .= 'enabled'; // Visually enabled if status matches (even if !canEditPHP)
                                        else $stepButtonClass .= 'disabled';
                                        // Add extra disabled look if user cannot edit but status is right
                                        if ($isStepEnabled && !$isCancelled && !$canEditPHP) {
                                            $stepButtonClass = 'step-button disabled'; // Force visual disable
                                        }

                                        $undoneButtonClass = 'undone-button ';
                                            // Visual class based on status primarily
                                            if ($isUndoEnabled && !$disableFinalUndo && !$isCancelled) {
                                                // Keep it visually active based on status
                                            } else {
                                                $undoneButtonClass .= 'disabled';
                                            }
                                            // Force visual disable if user cannot edit
                                            if ($isUndoEnabled && !$disableFinalUndo && !$isCancelled && !$canEditPHP) {
                                                $undoneButtonClass .= ' disabled'; // Ensure disabled class if cannot edit
                                            }
                                            ?>
                                            <div class="action-button-group">
                                                    <button class="<?php echo $stepButtonClass; ?>" data-panel-id="<?php echo $panelId; ?>" data-action="<?php echo $stepAction; ?>"
                                                        <?php echo $isStepButtonDisabled ? 'disabled' : ''; /* Actual disabled state */ ?>>
                                                        <?php echo htmlspecialchars($step['label']); ?>
                                                    </button>
                                                    <button class="<?php echo $undoneButtonClass; ?>" data-panel-id="<?php echo $panelId; ?>" data-action="undone_<?php echo $stepAction; ?>" title="لغو مرحله: <?php echo htmlspecialchars($step['label']); ?>"
                                                        <?php echo $isUndoButtonDisabled ? 'disabled' : ''; /* Actual disabled state */ ?>>
                                                        ↺
                                                    </button>
                                                </div>
                                    <?php endforeach; ?>

                                    <!-- QC Toggle Button -->
                                    <button class="qc-button <?php echo ($isCancelled /* || !$canEditPHP */ ) ? 'disabled' : ''; // Don't disable toggle itself based on role, content inside will be disabled ?>" data-panel-id="<?php echo $panelId; ?>" <?php echo ($isCancelled) ? 'disabled' : ''; ?>>
                                        کنترل کیفی
                                    </button>
                                    <!-- Cancel Button -->
                                    <button class="cancel-button <?php echo ($isCancelled || !$canEditPHP) ? 'disabled' : ''; // MUST check role ?>" data-panel-id="<?php echo $panelId; ?>" data-action="cancel_panel" <?php echo ($isCancelled || !$canEditPHP) ? 'disabled' : ''; ?>>
                                        لغو پنل
                                    </button>
                                </div>
                                <!-- Hidden Inline QC Details Section -->
                                <div class="qc-details-section hidden" data-panel-id="<?php echo $panelId; ?>">
                                    <h4 class="text-md font-semibold mb-3 text-purple-800">جزئیات کنترل کیفی</h4>
                                    <?php foreach ($qcChecklistItems as $qcStepName => $qcLabel):
                                        $detail = $panelQcDetails[$panelId][$qcStepName] ?? null;
                                        $isQcCompleted = $detail && $detail['is_completed'] == 1;
                                        $qcStatus = $detail['status'] ?? 'pending';
                                        $qcRejectionReason = $detail['rejection_reason'] ?? null;
                                        $attachments = $detail['attachments'] ?? [];
                                        $isImageUploadStep = ($qcStepName === 'qc_panel_image_upload');
                                        $qcDetailId = $detail['id'] ?? null; // Get the detail ID if it exists
                                        $qcNotes = $detail['notes'] ?? null; // *** Get notes ***
                                        $isQcItemDisabled = !$canEditPHP; // Base disable state for all QC items if user can't edit
                                    ?>
                                    <div class="qc-step-item" 
                                    data-step-name="<?php echo $qcStepName; ?>" 
                                    data-panel-detail-id="<?php echo $qcDetailId; ?>"
                                    data-current-qc-status="<?php echo $qcStatus; ?>">
                                    
                                        <label for="qc_chk_<?php echo $panelId . '_' . $qcStepName; ?>">
                                            <?php echo htmlspecialchars($qcLabel); ?>
                                        </label>
                                        <div class="qc-actions flex-grow flex justify-end items-center gap-2">
                                             <!-- Note Toggle Button/Icon -->
                                            <span class="qc-note-toggle" title="افزودن/ویرایش یادداشت">📝</span>
                                            <?php if ($isImageUploadStep): ?>
                                                <div class="qc-attachment-display">
                                                   <?php if (!empty($attachments)): ?>
                                                         <span>فایل فعلی:</span>
                                                        <?php foreach ($attachments as $att): ?>
                                                            <a href="<?php echo htmlspecialchars(str_replace($_SERVER['DOCUMENT_ROOT'], '', $att['file_path'])); /* Make relative path */ ?>" target="_blank" title="<?php echo htmlspecialchars($att['original_filename']); ?>">
                                                                <?php echo htmlspecialchars(mb_strimwidth($att['original_filename'], 0, 20, "...")); ?>
                                                            </a>
                                                             <!-- Add delete button if needed -->
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                 </div>
                                                   <label class="qc-file-input-label <?php echo $isQcItemDisabled ? 'disabled bg-gray-400 cursor-not-allowed opacity-70' : ''; ?>">
                                                        <?php echo empty($attachments) ? 'آپلود تصاویر' : 'افزودن تصاویر'; ?> <!-- Changed Label -->
                                                        <input type="file" class="hidden-qc-file-input" name="qc_file_<?php echo $panelId . '_' . $qcStepName; ?>" accept="image/jpeg,image/png,image/gif" multiple <?php echo $isQcItemDisabled ? 'disabled' : ''; ?>> <!-- ADDED multiple -->
                                                    </label>
                                            <?php endif; ?>

                                            <button class="qc-ok-btn" data-status="completed" <?php echo ($qcStatus === 'completed' || $isQcItemDisabled) ? 'disabled' : ''; ?>>تایید</button>
                                            <button class="qc-undone-btn" data-status="pending" <?php echo ($qcStatus === 'pending' || $isQcItemDisabled) ? 'disabled' : ''; ?>>انجام نشده</button>
                                             <?php
                                                $canReject = ($_SESSION['role'] === 'supervisor' || $_SESSION['role'] === 'admin');
                                                $isRejectButtonDisabled = ($qcStatus === 'rejected' || $isQcItemDisabled || !$canReject);
                                                ?>
                                            <?php if ($canReject): // Only show if supervisor/admin role ?>
                                                <button class="qc-reject-btn" data-status="rejected" <?php echo $isRejectButtonDisabled ? 'disabled' : ''; ?>>رد</button>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($qcRejectionReason): ?>
                                        <div class="qc-rejection-reason">
                                            <strong>دلیل رد:</strong> <?php echo htmlspecialchars($qcRejectionReason); ?>
                                            <?php if ($detail['supervisor_name']) echo " (توسط: " . htmlspecialchars($detail['supervisor_name']) . ")"; ?>
                                        </div>
                                        <?php endif; ?>
                                          <div class="qc-note-display <?php echo empty($qcNotes) ? 'hidden' : ''; ?>" style="width:100%;">
                                            <?php if(!empty($qcNotes)) echo nl2br(htmlspecialchars($qcNotes)); // Display existing note with line breaks ?>
                                        </div>
                                         <!-- *** QC Note Input Area (Initially hidden) *** -->
                                        <div class="qc-note-area hidden" style="width:100%;">
                                            <textarea class="qc-note-textarea" placeholder="یادداشت خود را اینجا وارد کنید..." <?php echo $isQcItemDisabled ? 'disabled' : ''; ?>><?php echo htmlspecialchars($qcNotes ?? ''); ?></textarea>
                                             <!-- Optional: Add a separate save button for notes, or save with main actions -->
                                            <div class="text-left mt-1"> <!-- Align button to the left -->
                                                <button class="qc-save-note-btn bg-blue-500 hover:bg-blue-700 text-white text-xs py-1 px-2 rounded" data-action="save_qc_note" <?php echo $isQcItemDisabled ? 'disabled' : ''; ?>>
                                                    ذخیره یادداشت
                                                </button>
                                            </div>
                                        </div>
                                         <!-- Hidden input to store value if needed in future -->
                                         <input type="hidden" class="qc-value-input" value="<?php echo htmlspecialchars($detail['value'] ?? ''); ?>">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <!-- End Hidden Inline QC Details Section -->

                            </td>
                        </tr>
                        <?php endforeach; ?>
                     <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Operation Modal (Success/Error for Progress Steps) -->
    <div id="operationModal" class="modal">
        <div class="modal-content"> <h3 id="modalTitle" class="text-lg font-bold mb-4"></h3> <p id="modalMessage"></p> <div class="mt-4 flex justify-end"> <button onclick="closeOperationModal()" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">بستن</button> </div> </div>
    </div>
     <!-- Rejection Modal (for QC Checklist) -->
    <div id="rejectionModal" class="modal">
        <div class="modal-content">
            <h3 class="text-lg font-bold mb-4">ثبت دلیل رد کردن</h3>
            <textarea id="rejectionReasonInput" placeholder="لطفا دلیل رد کردن این آیتم کنترل کیفی را وارد نمایید..."></textarea>
            <input type="hidden" id="rejectPanelId">
            <input type="hidden" id="rejectStepName">
            <div class="mt-4 flex justify-end gap-2">
                <button onclick="submitRejection()" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">ثبت رد</button>
                <button onclick="closeRejectionModal()" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">انصراف</button>
            </div>
        </div>
    </div>
<link rel="stylesheet" href="assets/css/persian-datepicker.min.css">
<script src="assets/js/jquery-3.6.0.min.js"></script>
<script src="assets/js/persian-datepicker.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/jquery-3.6.0.min.js"></script>
<script src="/assets/js/moment.min.js"></script>
<script src="/assets/js/moment-jalaali.js"></script>
<script src="/assets/js/persian-date.min.js"></script>
<script src="/assets/js/persian-datepicker.min.js"></script>
<script src="/assets/js/mobile-detect.min.js"></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js'></script>
<script src="assets/js/pikaday.js"></script>

    <script>
        (function() {
            // Modals and Containers
            const operationModal = document.getElementById('operationModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');
            const panelDetailsContainer = document.getElementById('panel-details-container'); // For Report
            const rejectionModal = document.getElementById('rejectionModal'); // For QC Reject
            const tableBody = document.querySelector('#panels-table tbody');
             // ******** START: Get Role Info ********
            const userRole = <?php echo json_encode($_SESSION['role'] ?? 'guest'); ?>;
            const canEdit = (userRole === 'admin' || userRole === 'supervisor');
            // ******** END: Get Role Info ********
            // State
            let detailsPanelId = null; // Tracks which report is shown
            let activeQcPanelId = null; // Tracks which inline QC is shown

            // --- Modal Functions ---
            function showOperationModal(title, message, isSuccess = true) { /* ... (same as before) ... */
                 modalTitle.textContent = title; modalMessage.textContent = message; modalTitle.className = isSuccess ? 'text-lg font-bold mb-4 text-green-700' : 'text-lg font-bold mb-4 text-red-700'; operationModal.classList.add('show');
            }
            window.closeOperationModal = function() { operationModal.classList.remove('show'); }
            function showRejectionModal(panelId, stepName) {
                 document.getElementById('rejectPanelId').value = panelId;
                 document.getElementById('rejectStepName').value = stepName;
                 document.getElementById('rejectionReasonInput').value = ''; // Clear previous reason
                 rejectionModal.classList.add('show');
             }
            window.closeRejectionModal = function() { rejectionModal.classList.remove('show'); }
            function hidePanelDetailsReport() { panelDetailsContainer.style.display = 'none'; panelDetailsContainer.innerHTML = ''; detailsPanelId = null; }

            // --- Helper Functions ---
            function getStatusTextJS(status) {
                const translations = {
                    'pending':'در انتظار',
                    'polystyrene': 'قالب فوم', // <<<== Add translation
                    'Mesh':'مش بندی',
                    'Formwork':'قالب بندی',
                    'Concreting':'بتن ریزی',
                    'Assembly':'فیس کوت',
                    'completed':'تکمیل شده',
                    'Cancelled':'لغو شده'
                };
                return translations[status] || status;
            }
            
            function getStatusClassJS(status) {
                switch(status){
                    case 'pending': return 'bg-gray-100 text-gray-800';
                    case 'polystyrene': return 'bg-teal-100 text-teal-800'; // <<<== Add class
                    case 'Mesh': return 'bg-blue-100 text-blue-800';
                    case 'Formwork': return 'bg-yellow-100 text-yellow-700';
                    case 'Concreting': return 'bg-yellow-200 text-yellow-800';
                    case 'Assembly': return 'bg-purple-100 text-purple-800';
                    case 'completed': return 'bg-green-100 text-green-800';
                    case 'Cancelled': return 'bg-red-100 text-red-800 font-semibold';
                    default: return 'bg-gray-100 text-gray-800';
                }
            }
        // Pass the UPDATED PHP config to JS
        const consolidatedStepsConfigJS = <?php echo json_encode(array_values($consolidatedStepsConfig)); ?>;
        const allStatusesInOrderJS = <?php echo json_encode($allStatusesInOrder); ?>; 

             // --- Main UI Update Function ---
                          // --- Main UI Update Function ---
        function updateRowUI(panelId, newStatus) {
                 // Find the table row for the specific panel
                 const row = document.querySelector(`tr[data-panel-id="${panelId}"]`);
                 if (!row) {
                    // If the row doesn't exist (e.g., after cancellation and page refresh might remove it), exit.
                    console.warn(`updateRowUI: Row not found for panelId ${panelId}`);
                    return;
                 }

                 // Determine overall row state based on the NEW status
                 const isCancelled = (newStatus === 'Cancelled'); // Is the panel actually cancelled? (DB state might be 'pending' after cancel action)
                 // If the DB status is 'pending' after cancel, we might need another way to know it *was* cancelled for styling,
                 // perhaps check the cancel button's disabled state IF the button itself exists reliably.
                 // For now, assume newStatus reflects the intended state for UI.
                 row.classList.toggle('opacity-60', isCancelled);
                 row.classList.toggle('bg-red-50', isCancelled);

                 // Update the status badge display in the third column
                 const statusCell = row.querySelector('.panel-status-cell .status-badge');
                 if (statusCell) {
                     statusCell.textContent = getStatusTextJS(newStatus); // Use JS helper for text
                     // Use JS helper for class, ensuring all necessary classes are included
                     statusCell.className = `status-badge px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${getStatusClassJS(newStatus)}`;
                 } else {
                    console.warn(`updateRowUI: Status badge not found for panelId ${panelId}`);
                 }

                 // Get the index of the new status in the defined order (from PHP)
                 const currentStatusIndex = allStatusesInOrderJS.indexOf(newStatus);
                 // Get the full configuration object (associative array/object from PHP)
                 const stepsConfigObject = <?php echo json_encode($consolidatedStepsConfig); ?>;

                 // --- Update each action button group within the row ---
                 row.querySelectorAll('.action-button-group').forEach(group => {
                    // Find the main action button and the undo button within this group
                    const stepButton = group.querySelector('.step-button');
                    const undoneButton = group.querySelector('.undone-button');

                    // Defensive check: Ensure both buttons were found
                    if (!stepButton || !undoneButton) return;

                    // Get the action name (e.g., 'do_mesh') from the button's data attribute
                    const stepAction = stepButton.dataset.action;
                    const stepConfig = stepsConfigObject[stepAction];
                    if (!stepAction || !stepConfig) return;
                    // --- Calculate button states based on current stepConfig and the panel's newStatus ---
                    const stepStatusAfter = stepConfig.status_after;    // Status the panel gets *after* this step
                    const stepStatusRequired = stepConfig.status_required; // Status required *before* this step
                    const stepStatusIndex = allStatusesInOrderJS.indexOf(stepConfig.status_after); // Index of the status this step achieves

                    // Check if this specific step is considered completed based on the panel's overall newStatus
                    const isStepCompleted = (
                        (currentStatusIndex !== -1 && stepStatusIndex !== -1 && currentStatusIndex >= stepStatusIndex) ||
                        (newStatus === 'completed' && stepAction === 'complete_production') // Handle final 'completed' state
                    );
                    const isStepPossible = (newStatus === stepConfig.status_required) && !isStepCompleted && !isCancelled;
                    const isUndoPossible = (newStatus === stepConfig.status_after) && !isCancelled;
                    const isFinalStep = (stepAction === 'complete_production');
                    // Check if the main action button for this step should be clickable
                    

                    // Check if the panel's current status matches the status *this step produces* (required for undo)



                    const disableUndo = isFinalStep; // Business rule: disable undo for the final step

                    const isStepEnabled = isStepPossible && canEdit;
                    // Determine final state for the undo button
                    const isUndoEnabled = isUndoPossible && !disableUndo && canEdit; // Undo is possible if the current status matches the step's status after, and it's not cancelled
                    // --- End Calculation ---

                    // --- Apply states to the Main Step Button ---
                    stepButton.classList.remove('completed', 'enabled', 'disabled'); // Clear previous styling
                    let stepBtnClass = 'disabled'; // Default to disabled visually
                     if (isStepCompleted) {
                            stepBtnClass = 'completed';
                        } else if (isStepPossible) { // Status allows it
                            stepBtnClass = canEdit ? 'enabled' : 'disabled'; // Visually enabled only if editable
                        }
                    stepButton.classList.add(stepBtnClass);
                    // Set the actual 'disabled' property to match enable/disable logic
                    stepButton.disabled = !isStepEnabled; // Actual disabled state

                    // --- Apply states to the Undo Button ---
                    undoneButton.classList.remove('disabled'); // Clear previous styling
                    let undoBtnClass = 'disabled'; // Default to disabled visually
                    if (isUndoPossible && !disableUndo) { // Status allows it
                        undoBtnClass = canEdit ? '' : 'disabled'; // Visually enabled only if editable
                    }
                    if (undoBtnClass === '') {
                            undoneButton.classList.remove('disabled'); // Ensure class is removed if enabled
                        } else {
                            undoneButton.classList.add('disabled'); // Ensure class is added if disabled
                        }
                        undoneButton.disabled = !isUndoEnabled; // Actual disabled state
            
                    // Set the actual 'disabled' property
                    

                 }); // --- End of forEach loop for button groups ---

                 // --- Update QC Button State ---
                 const qcButton = row.querySelector('.qc-button');
                if (qcButton) {
                        // Only disable if cancelled. Let content inside handle edit permissions.
                        qcButton.disabled = isCancelled;
                        qcButton.classList.toggle('disabled', isCancelled);
                    }

                 // --- Update Cancel Button State ---
                 const cancelButton = row.querySelector('.cancel-button');
                 if (cancelButton) {
                        // Disabled if cancelled OR user cannot edit
                        cancelButton.disabled = isCancelled || !canEdit;
                        cancelButton.classList.toggle('disabled', isCancelled || !canEdit);
                    }

                 // --- Hide QC details if panel was cancelled ---
                 // Checks if the *new status* implies cancellation and if this panel's QC was open
                if (activeQcPanelId === panelId) {
                    const qcSection = row.querySelector('.qc-details-section');
                    if (qcSection && !qcSection.classList.contains('hidden')) {
                        updateQcSectionEditability(qcSection, canEdit); // Pass the global edit permission
                    }
                }
             } // --- End of updateRowUI function ---
              // --- NEW Helper: Update editability of elements WITHIN a QC section ---
    function updateQcSectionEditability(qcSectionElement, isEditable) {
        const canRejectJS = (userRole === 'admin' || userRole === 'supervisor'); // Role check for reject

        qcSectionElement.querySelectorAll('.qc-step-item').forEach(item => {
            // Get the current status stored on the item element
            const currentItemStatus = item.dataset.currentQcStatus || 'pending'; // Default to 'pending' if unset

            // File Input (handles only edit permission)
            const fileInput = item.querySelector('.hidden-qc-file-input');
            const fileLabel = item.querySelector('.qc-file-input-label');
            const isFileDisabled = !isEditable; // Files depend only on role
            if (fileInput) fileInput.disabled = isFileDisabled;
            if (fileLabel) {
                fileLabel.classList.toggle('disabled', !isEditable);
                fileLabel.classList.toggle('bg-gray-400', !isEditable);
                fileLabel.classList.toggle('cursor-not-allowed', !isEditable);
                fileLabel.classList.toggle('opacity-70', !isEditable);
            }

            // Action Buttons (OK, Undone, Reject) - COMBINE isEditable AND status checks
            item.querySelectorAll('.qc-ok-btn, .qc-undone-btn, .qc-reject-btn').forEach(btn => {
                let btnDisabled = !isEditable; // Start with edit permission check

                // Add status-based check
                if (btn.classList.contains('qc-ok-btn')) {
                btnDisabled = btnDisabled || (currentItemStatus === 'completed');
            } else if (btn.classList.contains('qc-undone-btn')) {
                btnDisabled = btnDisabled || (currentItemStatus === 'pending');
            } else if (btn.classList.contains('qc-reject-btn')) {
                btnDisabled = btnDisabled || (currentItemStatus === 'rejected') || !canRejectJS;
            }
            btn.disabled = btnDisabled;
            });

            // Note Textarea and Save Button (handles only edit permission)
            const noteTextarea = item.querySelector('.qc-note-textarea');
            const saveNoteBtn = item.querySelector('.qc-save-note-btn');
            const isNoteDisabled = !isEditable; // Notes depend only on role
            if (noteTextarea) noteTextarea.disabled = isNoteDisabled;
            if (saveNoteBtn) saveNoteBtn.disabled = isNoteDisabled;

        });
    }

             // --- Generic Action Handler (Progress, Undo, Cancel) ---
             async function handleMainActionClick(button) { 
                if (!canEdit) { console.warn("Edit attempt denied by role."); return; } // Guard against unauthorized access
                const panelId = button.dataset.panelId; 
                const action = button.dataset.action;
                const panelAddressElement = button.closest('tr')?.querySelector('td:first-child a');
                const panelIdentifier = panelAddressElement ? panelAddressElement.textContent.trim() : 
                    `ID ${panelId}`;
                    let originalText = button.innerHTML;
                    if (action === 'complete_production') {
                   console.log(`Checking QC status for Panel ${panelIdentifier} before completing...`);
                   button.disabled = true;

                   button.innerHTML = 'بررسی QC...';
                   const checkFormData = new FormData();
                   checkFormData.append('action', 'check_qc_completion');
                   checkFormData.append('panel_id', panelId);

                   try {
                       const response = await fetch(window.location.href, { method: 'POST', body: checkFormData });
                       if (!response.ok) { throw new Error(`HTTP error ${response.status}`); }
                       const contentType = response.headers.get("content-type");
                        if (!(contentType && contentType.indexOf("application/json") !== -1)) {
                            const text = await response.text();
                            throw new Error("Unexpected response format (QC Check): "+ text);
                        }
                       const data = await response.json();

                       if (data.success && data.all_qc_complete) {
                           console.log(`QC check passed for Panel ${panelIdentifier}. Proceeding with completion.`);
                           // Restore button text before confirmation
                           button.innerHTML = originalText;
                           // Now continue with the original confirmation and action logic
                       } else {
                           console.log(`QC check failed for Panel ${panelIdentifier}.`);
                           showOperationModal(
                               'خطا',
                               `برای تکمیل تولید پنل "${panelIdentifier}"، تمام مراحل کنترل کیفی باید ابتدا تایید (OK) شده باشند.`,
                               false
                           );
                           // Re-enable button and restore text
                           button.disabled = false;
                           button.innerHTML = originalText;
                           return; // Stop processing
                       }
                   } catch (error) {
                       console.error('QC Check Fetch Error:', error);
                       showOperationModal('خطا', `خطا در بررسی وضعیت کنترل کیفی: ${error.message}`, false);
                       button.disabled = false;
                       button.innerHTML = originalText; // Restore original text on error
                       return; // Stop processing
                   }// --- *** End QC Check *** ---
                   // If QC check passed, execution continues below...
               }
               // --- *** End QC Check *** ---
               let confirmMsg = `می‌خواهید مرحله "${button.textContent.trim() || 'Undo'}" برای پنل ${panelIdentifier}? آپدیت کنید؟`; // Use panelIdentifier
                if (action === 'cancel_panel') confirmMsg = `هشدار! آیا مطمئن هستید که می‌خواهید پنل ${panelIdentifier} را لغو کنید؟ تمام پیشرفت‌ها و جزئیات کنترل کیفی حذف خواهند شد. این عملیات قابل بازگشت نیست.`;
                else if (action.startsWith('undone_')) confirmMsg = `آیا می‌خواهید آخرین مرحله تکمیل شده برای پنل ${panelIdentifier} را لغو کنید؟`;

                
                if (!confirm(confirmMsg)) {
                    if (action === 'complete_production') {
                        button.disabled = false; // Re-enable QC check button if user cancels confirmation
                        button.innerHTML = originalText; // Restore original text
                    }
                    return;
               }

                // --- Proceed with Action ---
               button.disabled = true; // Ensure it's disabled again
               const textBeforeWait = button.innerHTML; // Get text again in case it changed
               button.innerHTML = 'صبر کنید...'; // Wait...

               const formData = new FormData();
               formData.append('panel_id', panelId);
               formData.append('action', action);

               fetch(window.location.href, { method: 'POST', body: formData })
               .then(response => { // Check for non-JSON response first
                   if (response.status === 401) { // Unauthorized (Session Expired)
                        throw new Error('session_expired');
                    }
                    if (response.status === 403) { // Forbidden (Role incorrect)
                        // Try to get the JSON message from the server
                        return response.json().then(data => {
                            throw new Error(data.message || 'forbidden'); // Throw specific error
                        }).catch(() => { // Fallback if response wasn't JSON
                            throw new Error('forbidden');
                        });
                    }
                    // Check for other non-OK statuses (like 500 Internal Server Error)
                    if (!response.ok) {
                        throw new Error(`HTTP error ${response.status}`);
                    }
                     const contentType = response.headers.get("content-type");
                    if (contentType && contentType.indexOf("application/json") !== -1) {
                        return response.json();
                    } else {
                        // If content type is wrong even with 2xx status, something is odd
                        return response.text().then(text => {
                            console.warn("Received non-JSON response:", text); // Log it
                            throw new Error("Unexpected response format (Expected JSON)");
                        });
                    }
                })
                .then(data => {
                    if (data && data.success) {
                        //showOperationModal('موفق', `عملیات با موفقیت انجام شد!`, true); // Success message
                        updateRowUI(panelId, data.new_status);
                       if (action === 'cancel_panel' && detailsPanelId === panelId) hidePanelDetailsReport();
                       if (action === 'cancel_panel' && activeQcPanelId === panelId) {
                           const row = document.querySelector(`tr[data-panel-id="${panelId}"]`);
                           const qcSection = row?.querySelector('.qc-details-section');
                           if(qcSection) qcSection.classList.add('hidden');
                           activeQcPanelId = null;
                       }
                    } else { throw new Error(data.message || 'Unknown error'); }
                })
                .catch(error => { 
                            console.error('Fetch Error:', error); // Log the actual error
        let userMessage = `خطا در انجام عملیات: ${error.message}`;
        let needsLogin = false;

        // --- Specific Error Handling ---
        if (error.message === 'session_expired') {
            userMessage = 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید.';
            needsLogin = true;
        } else if (error.message === 'forbidden') {
            userMessage = 'شما اجازه انجام این عملیات را ندارید.';
        } else if (error.message.startsWith('HTTP error')) {
             userMessage = 'خطا در ارتباط با سرور رخ داد.'; // Generic server error
        } else if (error.message.includes("Unexpected response format")) {
            userMessage = 'پاسخ دریافتی از سرور نامعتبر است.'; // Catchall for format errors
        }
        // else: Keep the original error.message for other cases

        showOperationModal('خطا', userMessage, false);

        if (needsLogin) {
            // Redirect to login page after showing the message
            setTimeout(() => {
                window.location.href = 'login.php'; // Or your actual login page URL
            }, 2500); // Wait 2.5 seconds
        }
    })
                .finally(() => {
                    const row = document.querySelector(`tr[data-panel-id="${panelId}"]`);
                    // Check the button's state *after* updateRowUI might have run
                    const currentButton = row?.querySelector(`.step-button[data-action="${action}"]`); // Find the button again

                    if (currentButton) {
                  // Maybe check its current status based on UI before re-enabling?
                  // For simplicity, just restore text if it wasn't session error
                   button.innerHTML = textBeforeWait; // Restore text (use original button reference)
                   button.disabled = false; // Cautiously re-enable
             }
                     // updateRowUI should handle the final correct button state based on newStatus
               });
           } // End handleMainActionClick


             // --- QC Detail Action Handler ---
              function handleQcAction(panelId, stepName, newStatus, rejectionReason = null, files = null) { // files is now a FileList or null
                 if (!canEdit) {
                        console.warn("QC action denied by role.");
                        // show a message to the user
                        showOperationModal('خطا', 'شما اجازه انجام این عملیات را ندارید.', false);
                        return;
                    } // Guard

                const formData = new FormData();
                formData.append('panel_id', panelId);
                formData.append('step_name', stepName);
                formData.append('status', newStatus);
                formData.append('is_completed', newStatus === 'completed' ? 'true' : 'false');
                if (rejectionReason !== null) formData.append('rejection_reason', rejectionReason);

                // *** Append multiple files if provided ***
                if (files && files.length > 0) {
                    for (let i = 0; i < files.length; i++) {
                        // IMPORTANT: Use the same name with [] for PHP to create an array
                        formData.append('qc_files[]', files[i]);
                    }
                     // Set status/completed flag explicitly when files are being uploaded
                     formData.set('status', 'completed'); // Override status to completed
                     formData.set('is_completed', 'true'); // Override completion
                     newStatus = 'completed'; // Update local status for UI update
                }
                // *****************************************

                 // Find the specific QC item element
                const qcItemElement = document.querySelector(`.qc-details-section[data-panel-id="${panelId}"] .qc-step-item[data-step-name="${stepName}"]`);
                const noteTextarea = qcItemElement?.querySelector('.qc-note-textarea');
                const currentNotesInTextarea = noteTextarea ? noteTextarea.value : null;
                formData.append('notes', currentNotesInTextarea ?? ''); 
                const actionButtons = qcItemElement?.querySelectorAll('.qc-actions button');
                actionButtons?.forEach(btn => btn.disabled = true);

                let errorOccurred = false; // Flag to track errors for finally block
                 // qcItemElement?.classList.add('saving');

                 fetch(window.location.href, { method: 'POST', body: formData })
                 .then(response => {
                        if (response.status === 401) { throw new Error('session_expired'); }
                        if (response.status === 403) {
                            return response.json().then(data => { throw new Error(data.message || 'forbidden'); }).catch(() => { throw new Error('forbidden'); });
                        }
                        if (!response.ok) { throw new Error(`HTTP error ${response.status}`); }

                        // Check content type
                        const contentType = response.headers.get("content-type");
                        if (contentType && contentType.indexOf("application/json") !== -1) {
                            return response.json();
                        } else {
                            return response.text().then(text => {
                                console.warn("Received non-JSON response for QC Action:", text);
                                throw new Error("Unexpected response format (Expected JSON)");
                            });
                        }
                    })
        .then(data => {
                    if (data && data.success) {
                // --- Success ---
                if (stepName === 'qc_panel_image_upload') {
                    fetchAndRenderQcAttachments(panelId, stepName); // Refresh attachments if files were potentially uploaded
                }
                // Update UI for the specific item
                updateQcItemUI(
                    panelId,
                    stepName,
                    data.qc_status ?? newStatus,
                    rejectionReason, // Only relevant if status is 'rejected'
                    data.qc_notes    // Use notes from server response
                );

                if (rejectionReason) closeRejectionModal(); // Close modal if rejection was submitted

                // Optional: Hide note textarea if it was open and action wasn't rejection
                // const noteArea = qcItemElement?.querySelector('.qc-note-area');
                // if (!rejectionReason && noteArea && !noteArea.classList.contains('hidden')) {
                //     // noteArea.classList.add('hidden');
                // }
            } else {
                // Handle application-level errors (data.success === false)
                throw new Error(data.message || 'Failed to save QC detail.');
            }
        })
        .catch(error => {
            errorOccurred = true; // Mark that an error happened
            console.error('QC Save Error:', error);
            let userMessage = `خطا در ذخیره جزئیات کنترل کیفی: ${error.message}`;
            let needsLogin = false;

            if (error.message === 'session_expired') {
                userMessage = 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید.';
                needsLogin = true;
            } else if (error.message === 'forbidden') {
                 userMessage = 'شما اجازه انجام این عملیات را ندارید.';
            } else if (error.message.startsWith('HTTP error')) {
                userMessage = 'خطا در ارتباط با سرور رخ داد.';
            } else if (error.message.includes("Unexpected response format")) {
                userMessage = 'پاسخ دریافتی از سرور نامعتبر است.';
            }

            showOperationModal('خطای QC', userMessage, false);

            if (needsLogin) {
                setTimeout(() => { window.location.href = 'login.php'; }, 2500);
            }
        })
        .finally(() => {
            // qcItemElement?.classList.remove('saving');
            // Re-enable buttons for THIS item *if* no error occurred or error wasn't session expiry
             if (!errorOccurred || (errorOccurred && error.message !== 'session_expired')) {
                 // Re-fetch the item and buttons in case DOM changed? Safer.
                 const finalQcItem = document.querySelector(`.qc-details-section[data-panel-id="${panelId}"] .qc-step-item[data-step-name="${stepName}"]`);
                 const finalActionButtons = finalQcItem?.querySelectorAll('.qc-actions button');

                 // Use updateQcSectionEditability logic to reset disabled state correctly based on current status and role
                 if (finalQcItem) {
                      const qcSection = finalQcItem.closest('.qc-details-section');
                      if (qcSection) {
                           // Create a temporary single-item section to pass to the function
                           const tempContainer = document.createElement('div');
                           tempContainer.appendChild(finalQcItem.cloneNode(true)); // Clone to avoid side effects
                           updateQcSectionEditability(tempContainer, canEdit);
                           // Now apply the calculated disabled states from the clone back to the original
                            const originalButtons = finalQcItem.querySelectorAll('.qc-actions button');
                            const clonedButtons = tempContainer.querySelectorAll('.qc-actions button');
                            if(originalButtons.length === clonedButtons.length) {
                                originalButtons.forEach((btn, index) => {
                                    btn.disabled = clonedButtons[index].disabled;
                                });
                            }
                      }
                 }
             }
             // If session expired, buttons remain disabled until reload/login.
        });
}
             // --- ** NEW ** Fetch and Render Attachments for a specific QC Item ---
             function fetchAndRenderQcAttachments(panelId, stepName) {
                 const item = document.querySelector(`.qc-details-section[data-panel-id="${panelId}"] .qc-step-item[data-step-name="${stepName}"]`);
                 const displayDiv = item?.querySelector('.qc-attachment-display');
                 const fileInputLabel = item?.querySelector('.qc-file-input-label');
                 if (!item || !displayDiv || !fileInputLabel) return; // Element not found

                 // Fetch ONLY attachments for this specific detail step
                 fetch(`get_panel_files.php?panel_id=${panelId}&step_name=${stepName}`) // Modify get_panel_files if needed to filter by step_name
                 // --- OR --- fetch ALL details again and filter here (simpler for now)
                 .then(response => response.ok ? response.json() : Promise.reject('Failed to fetch files'))
                 .then(data => {
                     displayDiv.innerHTML = ''; // Clear previous content
                     let hasAttachments = false;
                     if (data.success && data.files) {
                          // Filter files for the current step (if get_panel_files doesn't support filtering)
                          const stepFiles = data.files.filter(f => f.step === stepName);

                          if (stepFiles.length > 0) {
                               hasAttachments = true;
                               displayDiv.innerHTML = '<span>فایل‌های فعلی:</span> ';
                               stepFiles.forEach(att => {
                                  const link = document.createElement('a');
                                  link.href = att.path; // Use relative path from response
                                  link.target = '_blank';
                                  link.title = escapeHtml(att.name);
                                  link.style.margin = '0 3px';
                                   // Add image thumbnail if it's an image
                                   if (att.type.startsWith('image/')) {
                                        link.innerHTML = `<img src="${att.path}" alt="${escapeHtml(att.name)}" style="height: 20px; display: inline-block; vertical-align: middle; border: 1px solid #ccc;">`;
                                        link.onclick = (e) => { e.preventDefault(); showLightbox(att.path); };
                                   } else {
                                        link.textContent = '🔗'; // Simple link icon
                                   }
                                  displayDiv.appendChild(link);
                                   // TODO: Add delete button here if needed in the future
                               });
                           }
                      }
                     // Update upload button text
                     fileInputLabel.textContent = hasAttachments ? 'افزودن تصاویر' : 'آپلود تصاویر';
                 })
                 .catch(error => {
                     console.error("Error refreshing attachments:", error);
                     displayDiv.innerHTML = '<span class="text-red-500 text-xs">Error loading files</span>';
                     fileInputLabel.textContent = 'آپلود تصاویر'; // Reset label
                 });
             }



            // --- Update UI for a Single QC Item ---
            function updateQcItemUI(panelId, stepName, newStatus, rejectionReason, notes) {
                const item = document.querySelector(`.qc-details-section[data-panel-id="${panelId}"] .qc-step-item[data-step-name="${stepName}"]`);
                if (!item) return;

                 // Update button disabled states
                 item.querySelector('.qc-ok-btn').disabled = (newStatus === 'completed');
                 item.querySelector('.qc-undone-btn').disabled = (newStatus === 'pending');
                 item.dataset.currentQcStatus = newStatus;
                 const rejectBtn = item.querySelector('.qc-reject-btn');
                 if (rejectBtn) rejectBtn.disabled = (newStatus === 'rejected');

                  // Update rejection reason display
                 const reasonDiv = item.querySelector('.qc-rejection-reason');
                 if (reasonDiv) {
                     if (newStatus === 'rejected' && rejectionReason) {
                         reasonDiv.innerHTML = `<strong>دلیل رد:</strong> ${escapeHtml(rejectionReason)}`; // Basic escapeHtml needed
                         reasonDiv.style.display = 'block';
                     } else {
                         reasonDiv.style.display = 'none';
                         reasonDiv.innerHTML = '';
                     }
                 }
                  // *** Update Note Display Area ***
                 const noteDisplayDiv = item.querySelector('.qc-note-display');
                 const noteTextarea = item.querySelector('.qc-note-textarea'); // Also update the textarea value
                 
                 if (noteDisplayDiv && noteTextarea) {
                    const actualNotes = notes ?? '';
                    if (actualNotes && actualNotes.trim() !== '') {
                         // *** FIX TYPO: Remove extra 'n' ***
                         noteDisplayDiv.innerHTML = escapeHtml(actualNotes).replace(/\n/g, '<br>');
                         noteDisplayDiv.classList.remove('hidden');
                         noteTextarea.value = actualNotes; // Sync textarea
                     } else {
                         noteDisplayDiv.classList.add('hidden');
                         noteDisplayDiv.innerHTML = '';
                         noteTextarea.value = ''; // Clear textarea
                     }
                 }
                 
                // NOTE: File display is now updated by fetchAndRenderQcAttachments
                 // We don't need to handle newFileUrl here anymore.
             }
              // Basic HTML escaping
            function escapeHtml(unsafe) {
                if (!unsafe) return '';
                 return unsafe
                      .replace(/&/g, "&")
                      .replace(/</g, "<")
                      .replace(/>/g, ">")
                      .replace(/"/g, "&quot;")
                      .replace(/'/g, "'");
             }


             // --- Submit Rejection Reason (from Modal) ---
             window.submitRejection = function() {
                 const reason = document.getElementById('rejectionReasonInput').value;
                 const panelId = document.getElementById('rejectPanelId').value;
                 const stepName = document.getElementById('rejectStepName').value;
                 if (!reason.trim()) { alert('لطفا دلیل رد را وارد کنید.'); return; }
                 handleQcAction(panelId, stepName, 'rejected', reason);
             }


            // --- Event Listeners Setup ---
            function setupEventListeners() {
                 // 1. Main Action Buttons (Progress, Undo, Cancel)
                 tableBody.querySelectorAll('.step-button, .undone-button, .cancel-button').forEach(button => {
                     // Remove previous listener to avoid duplicates if called again
                     button.removeEventListener('click', handleMainButtonClick);
                     if (!button.disabled) { // Only add listener if initially enabled
                         button.addEventListener('click', handleMainButtonClick);
                     }
                 });

                 // 2. QC Toggle Button
                tableBody.querySelectorAll('.qc-button').forEach(button => {
                     button.removeEventListener('click', handleQcToggleClick);
                     if (!button.disabled) {
                         button.addEventListener('click', handleQcToggleClick);
                     }
                 });

                 // 3. Panel Detail Report Link (Address)
                tableBody.querySelectorAll('.panel-detail-link').forEach(link => {
                     link.removeEventListener('click', handleReportLinkClick);
                     link.addEventListener('click', handleReportLinkClick);
                 });

                 // 4. Inline QC Actions (using Event Delegation on table body)
                 tableBody.removeEventListener('click', handleInlineQcActionClick);
                 tableBody.addEventListener('click', handleInlineQcActionClick);

                 // 5. Inline QC File Input Change (using Event Delegation)
                 tableBody.removeEventListener('change', handleInlineQcFileChange);
                 tableBody.addEventListener('change', handleInlineQcFileChange);
                 tableBody.querySelectorAll('.view-files-btn').forEach(button => {
                     button.removeEventListener('click', handleViewFilesClick);
                     if (!button.disabled) {
                         button.addEventListener('click', handleViewFilesClick);
                     }
                     });
                     // Initial rendering of attachments for visible QC sections on page load (if any were open)
                 document.querySelectorAll('.qc-details-section:not(.hidden)').forEach(section => {
                     const panelId = section.dataset.panelId;
                     section.querySelectorAll('.qc-step-item[data-step-name="qc_panel_image_upload"]').forEach(item => {
                         fetchAndRenderQcAttachments(panelId, 'qc_panel_image_upload');
                     });
                 });
             }
            

             // --- Event Handler Functions (to be used in setupEventListeners) ---
            function handleMainButtonClick() { handleMainActionClick(this); }

             function handleQcToggleClick() {
                 const panelId = this.dataset.panelId;
                 const qcSection = document.querySelector(`.qc-details-section[data-panel-id="${panelId}"]`);
                 if (!qcSection) return;

                 // Close any other open QC section
                 if (activeQcPanelId && activeQcPanelId !== panelId) {
                     const otherQcSection = document.querySelector(`.qc-details-section[data-panel-id="${activeQcPanelId}"]`);
                     if (otherQcSection) otherQcSection.classList.add('hidden');
                 }

                 // Toggle current section
                 qcSection.classList.toggle('hidden');
                 activeQcPanelId = qcSection.classList.contains('hidden') ? null : panelId;
                  // ***** If section is now visible, update its editability based on role *****
        if (!qcSection.classList.contains('hidden')) {
             updateQcSectionEditability(qcSection, canEdit);
        }
                 // ***** If section is now hidden, reset activeQcPanelId *****
             }

            function handleReportLinkClick(e) {
                 e.preventDefault();
                 const panelId = this.dataset.panelId;
                 // Close inline QC if open
                 if(activeQcPanelId){
                    const otherQcSection = document.querySelector(`.qc-details-section[data-panel-id="${activeQcPanelId}"]`);
                    if (otherQcSection) otherQcSection.classList.add('hidden');
                    activeQcPanelId = null;
                 }

                 const url = `panel_detail_report.php?id=${panelId}`;
                 panelDetailsContainer.innerHTML = '<p class="text-center p-4">Loading Report...</p>';
                 panelDetailsContainer.style.display = 'block';
                 detailsPanelId = panelId;

                 fetch(url)
                 .then(response => { if (!response.ok) { throw new Error(`Error: ${response.statusText}`); } return response.text(); })
                 .then(html => { if(detailsPanelId === panelId) { panelDetailsContainer.innerHTML = html; const closeBtn = document.createElement('button'); closeBtn.textContent = '×'; closeBtn.className = 'close-details'; closeBtn.title='Close Details'; closeBtn.onclick = hidePanelDetailsReport; panelDetailsContainer.appendChild(closeBtn); } })
                 .catch(error => { console.error('Fetch Report Error:', error); if(detailsPanelId === panelId) { panelDetailsContainer.innerHTML = `<p class="text-center p-4 text-red-600">Error loading report: ${error.message}</p><button class="close-details" onclick="document.getElementById('panel-details-container').style.display='none'">×</button>`; } });
             }

            function handleInlineQcActionClick(event) {
                const target = event.target;
                const isActionableButton = target.matches('.qc-ok-btn, .qc-undone-btn, .qc-reject-btn, .qc-save-note-btn');
                const isFileInputLabel = target.matches('.qc-file-input-label'); // Clicking label triggers input
                 if ((isActionableButton || isFileInputLabel) && !canEdit) {
                        console.warn("Inline QC action/upload trigger denied by role.");
                        event.preventDefault(); // Prevent label from triggering disabled input
                        return;
                    }
                 // Check if the clicked element is one of the QC action buttons
                  if (target.matches('.qc-ok-btn, .qc-undone-btn, .qc-reject-btn')) {
                     const qcItem = target.closest('.qc-step-item');
                     const qcSection = target.closest('.qc-details-section');
                     if (!qcItem || !qcSection) return;
                     const panelId = qcSection.dataset.panelId;
                     const stepName = qcItem.dataset.stepName;
                     const newStatus = target.dataset.status;

                     if (newStatus === 'rejected') {
                         showRejectionModal(panelId, stepName);
                     } else {
                         // Pass null for files when just changing status/notes
                         handleQcAction(panelId, stepName, newStatus, null, null);
                     }
                 }
                 // --- Handle Note Toggle Button ---
                 else if (target.matches('.qc-note-toggle')) {
                     const qcItem = target.closest('.qc-step-item');
                     if (!qcItem) return;
                     const noteArea = qcItem.querySelector('.qc-note-area');
                     const noteDisplay = qcItem.querySelector('.qc-note-display');
                     if (noteArea) {
                         noteArea.classList.toggle('hidden');
                         // Optional: Focus textarea when shown
                         if (!noteArea.classList.contains('hidden')) {
                             noteArea.querySelector('.qc-note-textarea')?.focus();
                         }
                         // Optional: Hide display when editing? Or keep it visible?
                         if (noteDisplay && !noteArea.classList.contains('hidden')) {
                             // noteDisplay.classList.add('hidden'); // Keep display visible unless empty
                         } else if (noteDisplay && noteDisplay.textContent.trim() !== '') {
                              noteDisplay.classList.remove('hidden'); // Show display if has content and not editing
                         }
                     }
                     return; // <-- *** ADDED: Stop further processing for toggle clicks ***
                }
                else if (target.matches('.qc-save-note-btn')) {
                    handleSaveNoteClick(target); // Call the specific handler
                    return; // <-- *** ADDED: Stop further processing for save clicks ***
                }
          // --- Handle QC Status Buttons (OK, Undone, Reject) ---
          else if (target.matches('.qc-ok-btn, .qc-undone-btn, .qc-reject-btn')) {
                    const qcItem = target.closest('.qc-step-item');
                    const qcSection = target.closest('.qc-details-section');
                    if (!qcItem || !qcSection) return;
                    const panelId = qcSection.dataset.panelId;
                    const stepName = qcItem.dataset.stepName;
                    const newStatus = target.dataset.status;

                    if (newStatus === 'rejected') {
                        showRejectionModal(panelId, stepName);
                    } else {
                        // Pass null for files when just changing status
                        handleQcAction(panelId, stepName, newStatus, null, null);
                    }
                    // No return needed here as handleQcAction handles async logic
                }
           } // End handleInlineQcActionClick

function handleSaveNoteClick(button) {
    if (!canEdit) { console.warn("Save note denied by role."); return; } // Guard
     // --- Keep these definitions here ---
     const qcItem = button.closest('.qc-step-item'); // Use qcItem consistently
     const qcSection = button.closest('.qc-details-section');
     if (!qcItem || !qcSection) return;

     const panelId = qcSection.dataset.panelId;
     const stepName = qcItem.dataset.stepName;
     const noteTextarea = qcItem.querySelector('.qc-note-textarea');
     const notes = noteTextarea ? noteTextarea.value : '';
     // --- End definitions ---


     console.log(`Saving note for Panel ${panelId}, Step ${stepName}`);

     button.disabled = true;
     button.textContent = 'در حال ذخیره...';

     const formData = new FormData();
     formData.append('action', 'save_qc_note');
     formData.append('panel_id', panelId);
     formData.append('step_name', stepName);
     formData.append('notes', notes);
     let errorOccurred = false; // Flag for finally block
     fetch(window.location.href, { method: 'POST', body: formData })
        .then(response => {
            // Check for specific HTTP status codes FIRST
            if (response.status === 401) { throw new Error('session_expired'); }
            if (response.status === 403) {
                 return response.json().then(data => { throw new Error(data.message || 'forbidden'); }).catch(() => { throw new Error('forbidden'); });
            }
            if (!response.ok) { throw new Error(`HTTP error ${response.status}`); }

            // Check content type
            const contentType = response.headers.get("content-type");
            if (contentType && contentType.indexOf("application/json") !== -1) {
                return response.json();
            } else {
                return response.text().then(text => {
                    console.warn("Received non-JSON response for Save Note:", text);
                    throw new Error("Unexpected response format (Expected JSON)");
                });
            }
        })
        .then(data => {
            if (data && data.success) {
                // --- Success ---
                updateQcItemNotesUI(panelId, stepName, data.qc_notes); // Update note display

                // Hide the textarea after successful save
                const noteArea = qcItem.querySelector('.qc-note-area');
                noteArea?.classList.add('hidden');

                // Show the display area if it now has content
                const noteDisplayDiv = qcItem.querySelector('.qc-note-display');
                if (noteDisplayDiv && data.qc_notes && data.qc_notes.trim() !== '') {
                    noteDisplayDiv.classList.remove('hidden');
                }
                // Optional: Show success message?
                // showOperationModal('موفق', 'یادداشت ذخیره شد.', true);

            } else {
                // Handle application-level errors (data.success === false)
                throw new Error(data.message || 'Failed to save note.');
            }
        })
        .catch(error => {
            errorOccurred = true; // Mark error
            console.error('Save Note Error:', error);
            let userMessage = `خطا در ذخیره یادداشت: ${error.message}`;
            let needsLogin = false;

            if (error.message === 'session_expired') {
                userMessage = 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید.';
                needsLogin = true;
            } else if (error.message === 'forbidden') {
                 userMessage = 'شما اجازه انجام این عملیات را ندارید.';
            } else if (error.message.startsWith('HTTP error')) {
                userMessage = 'خطا در ارتباط با سرور رخ داد.';
            } else if (error.message.includes("Unexpected response format")) {
                userMessage = 'پاسخ دریافتی از سرور نامعتبر است.';
            }

            showOperationModal('خطای یادداشت', userMessage, false);

            if (needsLogin) {
                setTimeout(() => { window.location.href = 'login.php'; }, 2500);
            }
        })
        .finally(() => {
            // Re-enable the save button ONLY if it wasn't a session error
            if (errorOccurred && error.message === 'session_expired') {
                // Keep button disabled, user needs to log in
                button.textContent = originalButtonText; // Restore text but keep disabled
            } else {
                 // If successful OR other error, re-enable
                 button.disabled = false;
                 button.textContent = originalButtonText;
            }
        });
} // End handleSaveNoteClick

              // --- *** NEW: Function to update ONLY the notes UI part *** ---
             function updateQcItemNotesUI(panelId, stepName, notes) {
                  const item = document.querySelector(`.qc-details-section[data-panel-id="${panelId}"] .qc-step-item[data-step-name="${stepName}"]`);
                 if (!item) return;
                 const noteDisplayDiv = item.querySelector('.qc-note-display');
                 const noteTextarea = item.querySelector('.qc-note-textarea');

                 if (noteDisplayDiv && noteTextarea) {
                     if (notes && notes.trim() !== '') {
                         noteDisplayDiv.innerHTML = escapeHtml(notes).replace(/\n/g, '<br>');
                         noteDisplayDiv.classList.remove('hidden');
                         noteTextarea.value = notes;
                     } else {
                         noteDisplayDiv.classList.add('hidden');
                         noteDisplayDiv.innerHTML = '';
                         noteTextarea.value = '';
                     }
                 }
             } // End updateQcItemNotesUI

            function handleInlineQcFileChange(event) {
                 const target = event.target;
                 if (target.matches('.hidden-qc-file-input')) {
                    if (!canEdit) { // Guard before processing file change
                            console.warn("File change denied by role.");
                            target.value = ''; // Clear selection if user somehow triggered it
                            return;
                        }
                     const qcItem = target.closest('.qc-step-item');
                     const qcSection = target.closest('.qc-details-section');
                     if (!qcItem || !qcSection) return;

                     const panelId = qcSection.dataset.panelId;
                     const stepName = qcItem.dataset.stepName;
                     const files = target.files; // This is a FileList

                     if (files && files.length > 0 && stepName === 'qc_panel_image_upload') {
                          // Basic validation for *all* selected files (optional but good UX)
                          const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                          let allValid = true;
                          for(let i=0; i<files.length; i++) {
                              if (!allowedTypes.includes(files[i].type)) {
                                   alert(`فایل نامعتبر (${escapeHtml(files[i].name)}). فقط JPG, PNG, GIF مجاز است.`);
                                   allValid = false;
                                   break; // Stop checking
                              }
                              // Add size check if needed
                          }

                          if(allValid) {
                               // Call handler to upload ALL selected files and mark step completed
                               handleQcAction(panelId, stepName, 'completed', null, files); // Pass the FileList
                          } else {
                               target.value = ''; // Clear selection if any file is invalid
                          }
                      }
                       // Reset file input after processing to allow selecting the same file again if needed
                       // Note: This might clear the selection immediately visually, which could be slightly confusing. Test UX.
                       // target.value = '';
                  }

            }
                function toLatinDigits(persianOrArabicNumber) {
             if (persianOrArabicNumber === null || typeof persianOrArabicNumber === 'undefined') return '';
                 const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
                 const arabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
                 const latinDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
                 let result = String(persianOrArabicNumber);
                 for (let i = 0; i < 10; i++) {
                     result = result.replace(new RegExp(persianDigits[i], 'g'), latinDigits[i]);
                     result = result.replace(new RegExp(arabicDigits[i], 'g'), latinDigits[i]);
                 }
                 return result;
            }
        // --- ************************************************************************* ---

        // --- Initialize Persian Date Picker ---
 const datePickerInput = document.getElementById('persianDatePickerTrigger');
        if (datePickerInput && typeof $.fn.persianDatepicker === 'function' && typeof moment === 'function') {

            // Configure moment-jalaali dialect (doesn't hurt, but we won't rely on its digit parsing now)
            try { moment.loadPersian({ dialect: 'persian-modern' }); } catch(e) {}

            // Initialize the datepicker
             $(datePickerInput).persianDatepicker({
                    format: 'YYYY/MM/DD',
                    autoClose: true,
                    initialValue: true,
                    initialValueType: 'persian',
                    observer: true,
                     calendar: {
                persian: {
                    locale: 'fa',             // Use Persian language/locale
                    leapYearMode: 'astronomical' // Use the accurate calculation method
                }
            },
                onSelect: function(unixTimestamp) {
                    // Use setTimeout to ensure the input value is definitely updated by the picker
                    setTimeout(() => {
                        const selectedJalaliDatePersianDigits = datePickerInput.value;
                        console.log("Reading Jalali value (potentially Persian digits):", selectedJalaliDatePersianDigits);

                         if (selectedJalaliDatePersianDigits) {
                                try {
                                    const selectedJalaliDateLatinDigits = toLatinDigits(selectedJalaliDatePersianDigits);
                                    console.log("Converted to Latin digits:", selectedJalaliDateLatinDigits);
                                     const parsedMoment = moment(selectedJalaliDateLatinDigits, 'jYYYY/jMM/jDD');
                                    // 2. Check if parsing was successful
                                    if (parsedMoment.isValid()) {
                                        // 3. Explicitly set the time to something safe like noon in the current timezone
                                        //    This avoids issues around midnight transitions.
                                        parsedMoment.hour(12).minute(0).second(0).millisecond(0);

                                        // 4. Format to Gregorian YYYY-MM-DD. This should now correctly reflect the intended date.
                                        const gregorianDateStr = parsedMoment.format('YYYY-MM-DD');

                                        console.log("Parsed Moment Object:", parsedMoment.toString()); // Log the moment object
                                        console.log("Final Gregorian Date for URL:", gregorianDateStr);

                                        // 5. Redirect
                                        window.location.href = `?date=${gregorianDateStr}`;
                                    
                                    // ******************************************

                                    } else {
                                         throw new Error(`Moment.js could not parse the date: ${selectedJalaliDateLatinDigits}`);
                                    }
                                } catch (e) {
                                    console.error("Error converting/parsing Jalali string:", e);
                                    alert('خطا در تبدیل تاریخ از ورودی. لطفا دوباره تلاش کنید.');
                                }
                            } else {
                                console.error("Input value is empty after selection.");
                                alert('خطا در خواندن تاریخ انتخاب شده.');
                            }
                        }, 10);
                }
            }); // End persianDatepicker initialization

        } else {
            // ... (Error logging if picker/jQuery/moment not found) ...
            if(!datePickerInput) console.error("Element #persianDatePickerTrigger not found.");
            if(typeof $.fn.persianDatepicker !== 'function') console.warn("jQuery persianDatepicker function is not available.");
            if(typeof moment !== 'function') console.warn("Moment.js is not available.");
        }
        // --- ** END: Initialize Date Picker ** ---
            

            // --- Initial Setup ---
             setupEventListeners(); // Call once on page load
             document.querySelectorAll('.qc-details-section:not(.hidden)').forEach(section => {
         updateQcSectionEditability(section, canEdit);
     });

        })(); // End of IIFE
    </script>

<?php require_once 'footer.php'; ?>
</body>
</html>