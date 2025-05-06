<?php
// --- PHP Section ---

ini_set('display_errors', 1);
error_reporting(E_ALL);
$responseSent = false;
ob_start();


require_once __DIR__ . '/../../sercon/config.php';
require_once 'includes/jdf.php'; // For jdate(), jalali_to_gregorian()
require_once 'includes/functions.php'; // Needs secureSession, get_user_permissions, escapeHtml, log_activity, translate_status, get_status_color, formatJalaliDateOrEmpty, secure_file_upload

// --- connectDB() in config.php MUST set charset=utf8mb4 ---
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// --- Check Login AFTER session started ---
if (!isset($_SESSION['user_id'])) {
    // Redirect happens before header.php is included - OK
    header('Location: login.php');
    exit();
}
secureSession();


$pdo = connectDB();
$message = '';
$error = ''; // Renamed $pageError to $error for consistency
$userId = $_SESSION['user_id'];

// --- Get Permissions ---
$stmtRole = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmtRole->execute([$userId]);
$userRole = $stmtRole->fetchColumn();
$userPermissions = get_user_permissions($userRole);

// --- Get Search and Filter Parameters ---
$searchAddress = filter_input(INPUT_GET, 'address', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$statusFilter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$prioritizationFilter = filter_input(INPUT_GET, 'prioritization', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$sortOrder = isset($_GET['sort']) && $_GET['sort'] == 'past' ? 'DESC' : 'ASC';

// --- Set Default Filters ---
if (empty($statusFilter)) $statusFilter = 'all';
if (empty($prioritizationFilter)) $prioritizationFilter = 'P1';


// --- Handle AJAX POST Requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $response = ['success' => false, 'error' => 'Invalid request.']; // Use 'error' key for consistency

    // Ensure DB connection (might have been closed on previous error)
    if (!$pdo) { try { $pdo = connectDB(); } catch (Exception $e) { /* Handle connection error if critical */ } }

    // Wrap entire POST handling in try-catch
    try {
        if (!$pdo) { throw new Exception("Database connection failed."); } // Check connection
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Ensure exceptions are on

        // --- File Upload ---
        if ($action === 'upload_file') {
            // ... (Keep existing file upload logic - Seems OK) ...
             $panelId = filter_input(INPUT_POST, 'panel_id', FILTER_VALIDATE_INT);
             $orderType = filter_input(INPUT_POST, 'order_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
             if (!($userPermissions['upload_files'] ?? false)) { $response['error'] = "You do not have permission."; }
             elseif (!$panelId || !in_array($orderType, ['east', 'west'])) { $response['error'] = "Invalid panel/order."; }
             elseif (empty($_FILES['design_file']['name'])) { $response['error'] = "Please select file."; }
             else {
                 $baseUploadDir = POLYSTYRENE_UPLOAD_PATH; // Use constant
                 if (!is_dir($baseUploadDir) && !mkdir($baseUploadDir, 0755, true)) { $response['error'] = "Error creating base dir!"; }
                 else {
                     $panelDir = $baseUploadDir . $panelId . '/';
                     if (!is_dir($panelDir) && !mkdir($panelDir, 0755, true)) { $response['error'] = "Error creating panel dir!";}
                     else {
                         $uploadDir = $panelDir . $orderType . '/';
                         if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) { $response['error'] = "Error creating order dir!"; }
                         else {
                            $uploadResult = secure_file_upload($_FILES['design_file'], ALLOWED_FILE_TYPES, $uploadDir);
                            if ($uploadResult['success']) {
                                $response['success'] = true;
                                $response['message'] = "فایل آپلود شد: " . escapeHtml(basename($uploadResult['file_path'])); // Use message key
                                $response['file_path'] = 'uploads/polystyrene/' . $panelId . '/' . $orderType . '/' . basename($uploadResult['file_path']);
                                $response['file_name'] = $uploadResult['file_name'];
                                log_activity($userId, 'file_upload', "Panel ID: $panelId, File: " . $uploadResult['file_name']);
                            } else { $response['error'] = $uploadResult['error']; }
                         }
                     }
                 }
             }
             $responseSent = true;
        }
        // --- File Delete ---
        elseif ($action === 'delete_file') {
            // ... (Keep existing file delete logic - Seems OK) ...
             $filePath = filter_input(INPUT_POST, 'file_path', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
             if (!($userPermissions['delete_all'] ?? false)) { $response['error'] = "You do not have permission."; }
             else {
                 $filePath = str_replace('..', '', ltrim($filePath, '/'));
                 $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $filePath;
                 $allowedDir = realpath(POLYSTYRENE_UPLOAD_PATH);
                 $realFullPath = realpath($fullPath);
                 if ($realFullPath && $allowedDir && strpos($realFullPath, $allowedDir) === 0 && file_exists($realFullPath)) {
                    if (is_writable($realFullPath) && unlink($realFullPath)) {
                        $response['success'] = true; $response['message'] = 'فایل پاک شد.'; // Use message key
                        log_activity($userId, 'file_delete', "File: " . $filePath);
                    } else { $response['error'] = 'فایل پاک نشد یا مجوز کافی نیست!'; }
                 } else { $response['error'] = 'فایل یافت نشد یا مسیر نامعتبر است'; }
             }
             $responseSent = true;
        }
        // --- Status Update ---
        elseif ($action === 'update_status') {
             // ... (Keep existing status update logic - Seems OK) ...
             $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
             $newStatus = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
             if (!($userPermissions['update_status'] ?? false)) { $response['error'] = "No permission."; }
             elseif ($orderId && $newStatus && in_array($newStatus, ['pending', 'ordered', 'in_production', 'produced', 'delivered'])) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE polystyrene_orders SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newStatus, $orderId]);
                $pdo->commit();
                $response = ['success' => true, 'message' => "وضعیت به روز شد.", 'new_status_text' => translate_status($newStatus), 'new_status_color' => get_status_color($newStatus)];
                log_activity($userId, 'status_update', "Order ID: $orderId, New Status: $newStatus");
             } else { $response['error'] = "Invalid ID or status."; }
             $responseSent = true;
        }
        // --- Date Update (Using User's Explicit Conversion Logic) ---
        elseif ($action === 'update_dates') {
            $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
            // Get JALALI date strings directly from POST (sent by input fields)
            $productionStartDateJalali = filter_input(INPUT_POST, 'production_start_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $productionEndDateJalali = filter_input(INPUT_POST, 'production_end_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
             // --- *** REMOVED required_delivery_date & actual_delivery_date *** ---
            // $requiredDeliveryDate = filter_input(INPUT_POST, 'required_delivery_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            // $actualDeliveryDate = filter_input(INPUT_POST, 'actual_delivery_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            // Check permissions (Simplified)
            if (!($userPermissions['edit_all'] ?? false) && !($userRole === 'cnc_operator' && ($userPermissions['update_dates'] ?? false))) {
                $response['error'] = "You do not have permission to update production dates.";
            }
            elseif (!$orderId) {
                $response['error'] = "Invalid order ID.";
            } else {
                // Assume jalali_to_gregorian function exists and handles validation/errors
                if (!function_exists('jalali_to_gregorian')) {
                     throw new Exception("Date conversion function 'jalali_to_gregorian' is missing.");
                }

                // --- Convert Dates using provided logic ---
                $conversionError = false;
                $errorMessage = '';
                $productionStartDateGregorian = null;
                $productionEndDateGregorian = null;
                // --- *** REMOVED required/actual delivery date variables *** ---
                // $requiredDeliveryDateGregorian = null;
                // $actualDeliveryDateGregorian = null;

                try {
                     // Convert Production Start Date
                     $productionStartDateGregorian = !empty($productionStartDateJalali) ? jalali_to_gregorian(
                         explode('/', $productionStartDateJalali)[0],
                         explode('/', $productionStartDateJalali)[1],
                         explode('/', $productionStartDateJalali)[2],
                         '-' // Expect YYYY-MM-DD output
                     ) : null;
                      if (!empty($productionStartDateJalali) && !$productionStartDateGregorian) {
                           throw new Exception("Conversion failed for start date: $productionStartDateJalali");
                       }

                     // Convert Production End Date
                     $productionEndDateGregorian = !empty($productionEndDateJalali) ? jalali_to_gregorian(
                         explode('/', $productionEndDateJalali)[0],
                         explode('/', $productionEndDateJalali)[1],
                         explode('/', $productionEndDateJalali)[2],
                         '-' // Expect YYYY-MM-DD output
                     ) : null;
                      if (!empty($productionEndDateJalali) && !$productionEndDateGregorian) {
                          throw new Exception("Conversion failed for end date: $productionEndDateJalali");
                      }

                     // --- *** REMOVED required/actual delivery date conversion *** ---

                } catch (Exception $e) { // Catch errors from explode or jdf
                    $conversionError = true;
                    $errorMessage = "خطا در تبدیل تاریخ: " . $e->getMessage(); // Set error message
                    error_log("Date Conversion Error: " . $e->getMessage());
                }
                // --- End Conversion ---

                if (!$conversionError) {
                    $pdo->beginTransaction();
                    try {
                        // --- *** UPDATED SQL to only set production dates *** ---
                        $stmt = $pdo->prepare("UPDATE polystyrene_orders SET production_start_date = ?, production_end_date = ?, updated_at = NOW() WHERE id = ?");
                        // Bind only production GREGORIAN dates
                        $stmt->execute([$productionStartDateGregorian, $productionEndDateGregorian, $orderId]);
                        // --- ******************************************** ---
                        $pdo->commit();

                        $response['success'] = true;
                        $response['message'] = "تاریخ‌های تولید با موفقیت به روز شدند.";
                        // Return Gregorian dates that were saved
                        $response['updated_dates_gregorian'] = [
                            'production_start_date' => $productionStartDateGregorian,
                            'production_end_date'   => $productionEndDateGregorian,
                            // Removed other dates
                        ];
                        log_activity($userId, 'date_update', "Order ID: $orderId, Production Dates Updated (Start: " . ($productionStartDateGregorian ?? 'NULL') . ", End: " . ($productionEndDateGregorian ?? 'NULL') . ")");

                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) { $pdo->rollBack(); }
                        error_log("DB Error during date update for Order ID $orderId: " . $e->getMessage());
                        $response['error'] = "خطای پایگاه داده هنگام بروزرسانی تاریخ‌ها.";
                        $conversionError = true; // Mark error to prevent sending success response
                    }
                } else {
                     // If conversion error occurred, set the response error
                     $response['error'] = $errorMessage;
                }
            }
            $responseSent = true; // Mark response needed
        }
        // --- Order Create ---
        elseif ($action === 'create_order') {
            // ... (Keep existing order create logic - Seems OK) ...
             $panelId = filter_input(INPUT_POST, 'panel_id', FILTER_VALIDATE_INT);
             $orderType = filter_input(INPUT_POST, 'order_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
             if ($panelId && !empty($orderType) && in_array($orderType, ['east', 'west'])) {
                $pdo->beginTransaction();
                $stmtNum = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(order_number, 5) AS UNSIGNED)) FROM polystyrene_orders");
                $stmtNum->execute(); $maxNum = $stmtNum->fetchColumn();
                $nextNum = ($maxNum === null) ? 1 : $maxNum + 1;
                $orderNum = "ORD-" . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
                $stmtIns = $pdo->prepare("INSERT INTO polystyrene_orders (hpc_panel_id, order_type, status, order_number, created_at) VALUES (?, ?, 'pending', ?, NOW())");
                if ($stmtIns->execute([$panelId, $orderType, $orderNum])) {
                    $orderId = $pdo->lastInsertId();
                    $pdo->commit();
                    $response = ['success' => true, 'message' => "سفارش ایجاد شد!", 'order_id' => $orderId, 'order_number' => $orderNum];
                    log_activity($userId, 'order_create', "Panel ID: $panelId, Type: $orderType, Num: $orderNum");
                } else { $pdo->rollBack(); $response['error'] = "ایجاد سفارش موفقیت آمیز نبود!"; }
            } else { $response['error'] = "Invalid panel/order type"; }
            $responseSent = true;
        }else {
            // Unknown action for POST
            $response['error'] = 'Unknown POST action requested.';
            $responseSent = true;
       }

    } catch (PDOException | Exception $e) { // Catch errors during POST handling
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log("Error in Polystyrene POST handling: " . $e->getMessage());
        $response = ['success' => false, 'error' => 'An internal error occurred: ' . $e->getMessage()];
        $responseSent = true; // Ensure JSON is sent on error
    } finally {
        // Send JSON response if it was marked for sending
        if ($responseSent) {
            if (ob_get_level() > 0) {
                 // Clear buffer ONLY if sending JSON to prevent HTML leakage
                 ob_end_clean();
            }
            // Ensure Content-Type header is set before output
            if (!headers_sent()) {
                 header('Content-Type: application/json; charset=utf-8');
                 // Set appropriate HTTP status code for errors
                 if (!$response['success']) {
                     http_response_code(isset($response['permission_error']) ? 403 : (isset($response['validation_error']) ? 400 : 500));
                 }
             }
             // Check again before echoing, just in case
             if (!headers_sent()) {
                 echo json_encode($response);
             } else {
                 // Fallback if headers already sent somehow (e.g., error before ob_start)
                 error_log("Headers already sent before JSON output in polystyrene_management.php");
                 // Avoid echoing JSON if headers are sent, as it will mix with HTML
             }
            exit; // Stop script after AJAX
        }
    }
} // End POST handling

// --- Convert Date Filters (Only if not an AJAX response) ---
$startDateGregorian = null;
$endDateGregorian = null;
$dateFilterError = '';
if (!empty($startDateJalali)) {
    try {
        $startDateGregorian = jalali_to_gregorian(
            explode('/', $startDateJalali)[0] ?? null,
            explode('/', $startDateJalali)[1] ?? null,
            explode('/', $startDateJalali)[2] ?? null,
            '-'
        );
        if (!$startDateGregorian) throw new Exception("فرمت تاریخ شروع نامعتبر.");
    } catch (Exception $e) {
        $dateFilterError .= "خطا در تاریخ شروع: " . $e->getMessage() . " ";
        $startDateGregorian = null; // Reset on error
    }
}
if (!empty($endDateJalali)) {
    try {
        $endDateGregorian = jalali_to_gregorian(
            explode('/', $endDateJalali)[0] ?? null,
            explode('/', $endDateJalali)[1] ?? null,
            explode('/', $endDateJalali)[2] ?? null,
            '-'
        );
         if (!$endDateGregorian) throw new Exception("فرمت تاریخ پایان نامعتبر.");
         // Optional: Add time component if needed for BETWEEN end date
         // $endDateGregorian .= ' 23:59:59';
    } catch (Exception $e) {
        $dateFilterError .= "خطا در تاریخ پایان: " . $e->getMessage() . " ";
        $endDateGregorian = null; // Reset on error
    }
}


// --- Fetch Data for Display (Counts and Panels) ---
$conditions = []; $params = []; $panels = []; $statusCounts = []; $prioritizations = [];// Initialize

try {
    if (!$pdo) { $pdo = connectDB(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);}

    // Base SQL query remains the same
    $baseSqlSelect = "SELECT hp.id AS panel_id, hp.address, hp.assigned_date AS assigned_date, hp.Proritization,
                       po_east.id AS east_order_id, po_east.order_number AS east_order_number, po_east.status AS east_status,
                       po_east.production_start_date AS east_production_start_date, po_east.production_end_date AS east_production_end_date,
                       po_west.id AS west_order_id, po_west.order_number AS west_order_number, po_west.status AS west_status,
                       po_west.production_start_date AS west_production_start_date, po_west.production_end_date AS west_production_end_date,
                       po_west.required_delivery_date AS west_required_delivery_date,
                       po_east.required_delivery_date AS east_required_delivery_date,
                       DATE(po_east.updated_at) AS east_order_updated_at,
                       DATE(po_west.updated_at) AS west_order_updated_at,
                       s.left_panel, s.right_panel "; // Add required_delivery_date if needed
    $baseSqlFromJoin = "FROM hpc_panels hp
                        LEFT JOIN polystyrene_orders po_east ON hp.id = po_east.hpc_panel_id AND po_east.order_type = 'east'
                        LEFT JOIN polystyrene_orders po_west ON hp.id = po_west.hpc_panel_id AND po_west.order_type = 'west'
                        LEFT JOIN sarotah s ON hp.address = SUBSTRING_INDEX(s.left_panel, '-(', 1)";

    // --- Apply Filters to WHERE Clause ---
    if (!empty($searchAddress)) { $conditions[] = "hp.address LIKE ?"; $params[] = "%$searchAddress%"; }
    if (!empty($prioritizationFilter) && $prioritizationFilter != 'all') { $conditions[] = "hp.Proritization = ?"; $params[] = $prioritizationFilter; }
    if (!empty($statusFilter) && $statusFilter != 'all') { $conditions[] = "(po_east.status = ? OR po_west.status = ?)"; $params[] = $statusFilter; $params[] = $statusFilter; }

    // --- Add Date Filter Condition ---
    if ($startDateGregorian && $endDateGregorian) {
         // Filter by required_delivery_date if available, otherwise by assigned_date
         $conditions[] = "(
                            (po_east.required_delivery_date BETWEEN :start_date AND :end_date)
                            OR
                            (po_west.required_delivery_date BETWEEN :start_date AND :end_date)
                            OR
                            (
                                (po_east.required_delivery_date IS NULL AND po_west.required_delivery_date IS NULL)
                                AND hp.assigned_date BETWEEN :start_date AND :end_date
                            )
                        )";
         $params[':start_date'] = $startDateGregorian;
         $params[':end_date'] = $endDateGregorian;
    } elseif ($startDateGregorian) { // Only start date
         $conditions[] = "(
                            (po_east.required_delivery_date >= :start_date)
                            OR
                            (po_west.required_delivery_date >= :start_date)
                            OR
                            (
                                (po_east.required_delivery_date IS NULL AND po_west.required_delivery_date IS NULL)
                                AND hp.assigned_date >= :start_date
                            )
                        )";
         $params[':start_date'] = $startDateGregorian;
    } elseif ($endDateGregorian) { // Only end date
        $conditions[] = "(
                            (po_east.required_delivery_date <= :end_date)
                            OR
                            (po_west.required_delivery_date <= :end_date)
                            OR
                            (
                                (po_east.required_delivery_date IS NULL AND po_west.required_delivery_date IS NULL)
                                AND hp.assigned_date <= :end_date
                            )
                        )";
         $params[':end_date'] = $endDateGregorian;
    }

    $whereClause = !empty($conditions) ? " WHERE " . implode(' AND ', $conditions) : "";

    // --- Fetch Panels ---
    $panelSql = $baseSqlSelect . $baseSqlFromJoin . $whereClause . " ORDER BY hp.id " . ($sortOrder === 'DESC' ? 'DESC' : 'ASC');
    $stmt = $pdo->prepare($panelSql);
    $stmt->execute($params);
    $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Get Counts Based on CURRENT Filters (including date) ---
    $countBaseSql = "SELECT COUNT(DISTINCT hp.id) " . $baseSqlFromJoin;
    $allCountSql = $countBaseSql . $whereClause;
    $allCountStmt = $pdo->prepare($allCountSql);
    $allCountStmt->execute($params);
    $totalFilteredPanels = $allCountStmt->fetchColumn() ?? 0;

    // Counts for Specific Statuses (including date filter)
    // Only count the statuses we want to display in the filter
    $statusesToCount = ['ordered', 'delivered']; // Reduced list
    $statusCounts = ['all' => $totalFilteredPanels]; // Start with the filtered total

    foreach ($statusesToCount as $status) {
        $statusConditions = $conditions; // Start with base filters (which include date if set)
        $statusConditions[] = "(po_east.status = ? OR po_west.status = ?)";
        $statusParams = $params; // Start with base params (which include date if set)
        $statusParams[] = $status; // Add status param 1
        $statusParams[] = $status; // Add status param 2

        $statusWhereClause = !empty($statusConditions) ? " WHERE " . implode(' AND ', $statusConditions) : "";
        $statusCountSql = $countBaseSql . $statusWhereClause;

        try {
            $statusStmt = $pdo->prepare($statusCountSql);
            $statusStmt->execute($statusParams);
            $statusCounts[$status] = $statusStmt->fetchColumn() ?? 0;
        } catch (PDOException $e) { /* ... error log ... */ $statusCounts[$status] = 0; }
    }

    // --- Get Available Prioritizations ---
    $stmtPrio = $pdo->query("SELECT DISTINCT Proritization FROM hpc_panels WHERE Proritization IS NOT NULL AND Proritization != '' ORDER BY Proritization");
    $prioritizations = $stmtPrio->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException | Exception $e) { /* ... error handling ... */ }

// --- Page Setup ---
$pageTitle = 'سیستم مدیریت سفارشات یونولیت';

// --- *** MOVE header require HERE *** ---
require_once 'header.php';
// --- *** End Move *** ---

// Flush buffer if needed before HTML starts (header.php might do this)
if (ob_get_level() > 0) { ob_end_flush(); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapeHtml($pageTitle) ?></title>
    <link href="assets/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="assets/css/persian-datepicker.min.css" rel="stylesheet">
    <style>
        @font-face {font-family:'Vazir';src:url('/assets/fonts/Vazir-Regular.woff2') format('woff2');}
        :root { --primary-color: #0d6efd; --bs-body-font-family: 'Vazir', sans-serif;}
        body {direction:rtl; text-align:right; font-family: var(--bs-body-font-family); margin:0; background:#f8f9fa; padding:1rem; font-size: 0.9rem;}
        .card {margin-bottom:1rem; border:1px solid #dee2e6; border-radius:0.375rem; box-shadow:0 1px 3px rgba(0,0,0,0.05); transition:transform 0.2s;}
        .card-body {padding:1rem 1.25rem;}
        .card-title {color:#0d6efd; margin-bottom:0.5rem; font-weight:600; font-size: 1.1rem;}
        .status-badge {padding:0.3em 0.6em; border-radius:0.25rem; font-size:0.8em; font-weight:500; display:inline-block; line-height: 1;}
        .order-number {font-weight:600; margin-bottom:0.5rem; color: #343a40; font-size: 0.95em;}
        .btn {border-radius:0.25rem; padding:0.375rem 0.75rem; font-weight:500; font-size: 0.875rem;}
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        .form-control, .form-select {border-radius:0.25rem; border:1px solid #ced4da; padding:0.375rem 0.75rem; text-align: right; font-size: 0.875rem;}
        .form-control-sm, .form-select-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        .form-control:focus, .form-select:focus {border-color:#86b7fe; box-shadow:0 0 0 0.25rem rgba(13,110,253,.25);}
        .alert {border-radius:0.25rem; margin-bottom:1rem;}
        .panel-sides {display:flex; flex-wrap: wrap; gap:1rem; margin-bottom:1rem;} /* Use flex-wrap */
        .panel-side {flex: 1 1 45%; min-width: 300px; padding:1rem; border:1px solid #dee2e6; border-radius:0.25rem; background-color: #fff;} /* Allow wrapping */
        .side-header {background-color:#e9ecef; padding:0.5rem 1rem; margin:-1rem -1rem 1rem -1rem; border-radius:0.25rem 0.25rem 0 0; border-bottom:1px solid #dee2e6; font-weight: 600;}
        .filter-bar {background-color:#fff; padding:1rem; border-radius:0.375rem; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:1.5rem;}
        .panel-highlight {border:2px solid #198754; box-shadow:0 0 8px rgba(25, 135, 84, 0.5);}
        /* --- REMOVED status-filter styles --- */
        .no-results {padding:2rem; text-align:center; background-color:#fff; border-radius:0.375rem; box-shadow:0 1px 3px rgba(0,0,0,0.05);}
        .upload-message {margin-top:0.5rem; font-size:0.85rem;}
        .datepicker { text-align: center !important; background-color: white !important; }
        .form-label { font-size: 0.8rem; margin-bottom: 0.2rem; color: #495057; font-weight: 500;}
        .file-list { list-style: none; padding: 0; margin-top: 0.5rem; font-size: 0.85em; }
        .file-list li { margin-bottom: 0.3rem; display: block;} /* Ensure li are block for wrapping */
        .file-list a { text-decoration: none; margin-left: 5px;}
        .file-list .btn-sm { vertical-align: middle; }
        .input-group .btn { border-radius: 0 0.25rem 0.25rem 0 !important; }
        .input-group .form-control { border-radius: 0.25rem 0 0 0.25rem !important;}
        .input-group .datepicker { border-radius: 0.25rem 0 0 0.25rem !important; border-right: 0; } /* Style datepicker in group */
        .input-group .input-group-text { background-color: #e9ecef; border-radius: 0 0.25rem 0.25rem 0; border-left: 0;} /* Calendar icon style */
    </style>
</head>
<body>
<div class="container">
    <div id="message-container">
        <?php if ($error): ?>
             <div class="alert alert-danger alert-dismissible fade show m-3" role="alert">
                 <?= escapeHtml($error) ?>
                 <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
             </div>
         <?php endif; ?>
         <?php if ($dateFilterError): // Display date conversion errors ?>
              <div class="alert alert-warning alert-dismissible fade show m-3" role="alert">
                  <?= escapeHtml($dateFilterError) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>
          <?php endif; ?>
    </div>

    <!-- Search and Filter Bar -->
    <div class="filter-bar mb-4">
        <form method="get" id="filterForm">
            <div class="row g-2 align-items-end">
                <!-- Address Search -->
                <div class="col-lg-3 col-md-6">
                    <label for="addressSearch" class="form-label">جستجو آدرس</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control form-control-sm" id="addressSearch" name="address" placeholder="بخشی از آدرس..." value="<?= escapeHtml($searchAddress ?? '') ?>">
                        <?php if (!empty($searchAddress)): ?> <a href="<?= strtok($_SERVER["REQUEST_URI"],'?') ?>?status=<?= urlencode($statusFilter) ?>&prioritization=<?= urlencode($prioritizationFilter) ?>&start_date=<?= urlencode($startDateJalali) ?>&end_date=<?= urlencode($endDateJalali) ?>" class="btn btn-outline-secondary" title="حذف فیلتر آدرس">x</a> <?php endif; ?>
                    </div>
                </div>
                 <!-- Prioritization Dropdown -->
                 <div class="col-lg-2 col-md-6">
                     <label for="prioritizationFilterSelect" class="form-label">اولویت</label>
                     <select name="prioritization" id="prioritizationFilterSelect" class="form-select form-select-sm" onchange="this.form.submit()">
                         <option value="all" <?= ($prioritizationFilter == 'all') ? 'selected' : '' ?>>همه</option>
                         <?php foreach ($prioritizations as $prio): ?> <option value="<?= escapeHtml($prio) ?>" <?= ($prioritizationFilter == $prio) ? 'selected' : '' ?>><?= escapeHtml($prio) ?></option> <?php endforeach; ?>
                     </select>
                 </div>
                 <!-- Status Dropdown -->
                 <div class="col-lg-2 col-md-6">
                     <label for="statusFilterSelect" class="form-label">وضعیت سفارش</label>
                     <select name="status" id="statusFilterSelect" class="form-select form-select-sm" onchange="this.form.submit()">
                         <!-- <option value="all" <?= ($statusFilter == 'all') ? 'selected' : '' ?>>همه (<?= $statusCounts['all'] ?? 0 ?>)</option> -->
                         <option value="ordered" <?= ($statusFilter == 'ordered') ? 'selected' : '' ?>>سفارش (<?= $statusCounts['ordered'] ?? 0 ?>)</option>
                         <option value="delivered" <?= ($statusFilter == 'delivered') ? 'selected' : '' ?>>تحویل (<?= $statusCounts['delivered'] ?? 0 ?>)</option>
                         <?php /* Removed other statuses:
                         <option value="pending" <?= ($statusFilter == 'pending') ? 'selected' : '' ?>>انتظار (<?= $statusCounts['pending'] ?? 0 ?>)</option>
                         <option value="in_production" <?= ($statusFilter == 'in_production') ? 'selected' : '' ?>>تولید (<?= $statusCounts['in_production'] ?? 0 ?>)</option>
                         <option value="produced" <?= ($statusFilter == 'produced') ? 'selected' : '' ?>>تولید شده (<?= $statusCounts['produced'] ?? 0 ?>)</option>
                         */ ?>
                     </select>
                 </div>
                 <!-- Date Range Filters -->
                 <div class="col-lg-2 col-md-6">
                    <label for="startDateFilter" class="form-label">از تاریخ</label>
                    <input type="text" class="form-control form-control-sm datepicker-filter" id="startDateFilter" name="start_date" placeholder="YYYY/MM/DD" value="<?= escapeHtml($startDateJalali ?? '') ?>">
                 </div>
                 <div class="col-lg-2 col-md-6">
                    <label for="endDateFilter" class="form-label">تا تاریخ</label>
                    <input type="text" class="form-control form-control-sm datepicker-filter" id="endDateFilter" name="end_date" placeholder="YYYY/MM/DD" value="<?= escapeHtml($endDateJalali ?? '') ?>">
                 </div>
                 <!-- Submit Button -->
                 <div class="col-lg-1 col-md-12 text-end">
                     <button type="submit" class="btn btn-primary btn-sm w-100">فیلتر</button>
                 </div>
                 <?php if(isset($_GET['sort'])): ?><input type="hidden" name="sort" value="<?= escapeHtml($_GET['sort']) ?>"><?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Panel Display Loop -->
    <?php if (empty($panels) && !$error): ?>
        <div class="no-results"><p>هیچ پنلی با فیلترهای انتخاب شده یافت نشد.</p><a href="<?= strtok($_SERVER["REQUEST_URI"],'?') ?>" class="btn btn-primary mt-2">پاک کردن همه فیلترها</a></div>    <?php elseif (!empty($panels)): ?>
        <?php foreach ($panels as $index => $panel):
          
            // ****** DEFINE $panelId HERE ******
            $panelId = $panel['panel_id']; // Assign the ID from the current panel array to the variable
            // ****** END DEFINITION ******

            $isHighlighted = !empty($searchAddress) && stripos($panel['address'], $searchAddress) !== false;
            // $panelId is now defined and can be used below
       
        ?>
             <div id="panel-<?= $panel['panel_id'] ?>" class="card mb-3 <?= (!empty($searchAddress) && stripos($panel['address'], $searchAddress) !== false) ? 'panel-highlight' : '' ?>">
             <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                    <h6 class="card-title mb-0">پنل: <?= escapeHtml($panel['address']) ?> <span class="text-muted small">(ID: <?= $panelId // $panelId is now guaranteed to be defined ?>)</span></h6>
                    <span class="badge bg-light text-dark border">اولویت: <?= escapeHtml($panel['Proritization'] ?? '-') ?></span>
                     </div>
                    <p class="text-muted">
                        تاریخ برنامه‌ریزی: <?= !empty($panel['assigned_date']) ? format_jalali_date($panel['assigned_date']) : 'نامشخص' ?>
                    </p>
                    <div class="panel-sides row g-3">
                        <!-- West Panel Side -->
                        <div class="panel-side col-md-6">
                            <div class="side-header"><h6 class="mb-0 small"><?= escapeHtml($panel['left_panel']) ?> </h6></div>
                             <p class="text-muted">
                        تاریخ تحویل درخواستی : <?= format_jalali_date($panel['west_required_delivery_date']) ?>
                                </p>
                                <p class="text-muted">
                                    تاریخ ایجاد سفارش : <?= format_jalali_date($panel['west_order_updated_at']) ?>
                            </p>
                            <div class="order-details-west" data-order-id="<?= $panel['west_order_id'] ?>">
                           
                            <?php if ($panel['west_order_id']):
                                $orderId = $panel['west_order_id'];
                                $orderType = 'west';
                                $orderData = $panel; // Use the main $panel array
                                $prefix = 'west_';
                                $currentPanelId = $panel['panel_id']; // Get ID specifically for this block if needed, or use $panel['panel_id'] directly
                            ?>
                                <p class="order-number">سفارش: <?= escapeHtml($orderData[$prefix.'order_number']) ?> <span class="text-muted small">(ID: <?= $orderId ?>)</span></p>

                                <!-- Status Section -->
                                <div class="mb-2">
                                    <!-- ... Status display ... -->
                                    <?php if (($userPermissions['update_status'] ?? false) && $userRole !== 'cnc_operator'): ?>
                                         <div class="mb-3">
                                    <div class="d-flex align-items-center mb-2 status-section-west">
                                        <span class="me-2">وضعیت:</span>
                                        <span class="badge bg-<?= get_status_color($panel['west_status']) ?>">
                                            <?= translate_status($panel['west_status']) ?>
                                        </span>
                                    </div>

                                    <?php if (($userPermissions['update_status'] ?? false) && $userRole !== 'cnc_operator'): ?>
                                        <form method="post" class="d-inline status-update-form">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?= $panel['west_order_id'] ?>">
                                             <input type="hidden" name="order_type" value="west">
                                            <select name="new_status" class="form-select form-select-sm w-auto">
                                                <option value="">-- تغییر وضعیت --</option>
                                                <option value="pending" <?= $panel['west_status'] == 'pending' ? 'selected' : '' ?>>در انتظار</option>
                                                <option value="ordered" <?= $panel['west_status'] == 'ordered' ? 'selected' : '' ?>>سفارش شده</option>
                                                <option value="delivered" <?= $panel['west_status'] == 'delivered' ? 'selected' : '' ?>>تحویل شده</option>
                                            </select>
                                            <button type="submit"  class="btn btn-info btn-sm">بروزرسانی</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                    <?php endif; ?>
                                </div>
                                <!-- Dates Section -->
                                <?php if (($userRole === 'cnc_operator' || ($userPermissions['edit_all'] ?? false))): ?>
                                    <!-- ... Date update form ... -->
                                <?php endif; ?>
                                <!-- Files Section -->
                                <div class="files-section mt-2">
                                    <div class="upload-message-container-<?= $orderType ?>"></div>
                                    <?php if ($userPermissions['upload_files'] ?? false): ?>
                                        <form method="post" enctype="multipart/form-data" class="mb-2 file-upload-form">
                                            <input type="hidden" name="action" value="upload_file">
                                            <input type="hidden" name="panel_id" value="<?= $panel['panel_id'] // *** USE $panel['panel_id'] *** ?>">
                                            <input type="hidden" name="order_type" value="<?= $orderType ?>">
                                            <label class="form-label small mb-1">آپلود فایل:</label>
                                            <div class="input-group input-group-sm">
                                                <input type="file" class="form-control design-file-input" name="design_file" required accept=".dxf,.dwg,.pdf,.zip">
                                                <button type="submit" class="btn btn-outline-primary upload-submit-btn">آپلود</button>
                                            </div>
                                            <div class="progress mt-1" style="height: 5px; display: none;"><div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>
                                        </form>
                                    <?php endif; ?>
                                    <div class="file-list-<?= $orderType ?>">
                                        <?php
                                        // *** USE $panel['panel_id'] for path ***
                                        $orderDir = POLYSTYRENE_UPLOAD_PATH . $panel['panel_id'] . '/' . $orderType . '/';
                                        if (is_dir($orderDir)):
                                            $files = array_diff(scandir($orderDir), ['.', '..']);
                                            if (!empty($files)):
                                        ?>
                                            <div class="mt-1">
                                                <h6 class="small d-inline-block me-2">فایل‌ها:</h6>
                                                <ul class="file-list d-inline-block small p-0 m-0" style="list-style: none;">
                                                <?php foreach ($files as $file):
                                                    // *** USE $panel['panel_id'] for path ***
                                                    $relativePath = 'uploads/polystyrene/' . $panel['panel_id'] . '/' . $orderType . '/' . $file;
                                                ?>
                                                    <li class="d-inline-block me-2" data-file="<?= escapeHtml($relativePath) ?>">
                                                        <a href="<?= escapeHtml($relativePath) ?>" download><?= escapeHtml($file) ?></a>
                                                        <?php if ($userPermissions['delete_all'] ?? false): ?>
                                                            <form method="post" class="d-inline file-delete-form">
                                                                <input type="hidden" name="action" value="delete_file">
                                                                <input type="hidden" name="file_path" value="<?= escapeHtml($relativePath) ?>">
                                                                <input type="hidden" name="panel_id" value="<?= $panel['panel_id'] // *** USE $panel['panel_id'] *** ?>">
                                                                <button type="submit" class="btn btn-link text-danger p-0 border-0 btn-sm" style="text-decoration: none;">(حذف)</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; endif; ?>
                                    </div>
                                </div>
                            <?php else: // No west order ?>
                                <?php if (($userPermissions['edit_all'] ?? false) || $userRole === 'planner'): ?>
                                    <form method="post" class="create-order-form">
                                        <input type="hidden" name="action" value="create_order">
                                        <input type="hidden" name="panel_id" value="<?= $panel['panel_id'] // *** USE $panel['panel_id'] *** ?>">
                                        <input type="hidden" name="order_type" value="west">
                                        <button type="submit" class="btn btn-success btn-sm">ایجاد سفارش </button>
                                    </form>
                                <?php else: echo '<span class="text-muted small">سفارشی ثبت نشده</span>'; endif; ?>
                            <?php endif; ?>
                            </div>
                        </div>

                        <!-- East Panel Side -->
                        <div class="panel-side col-md-6">
                             <div class="side-header"><h6 class="mb-0 small"><?= escapeHtml($panel['right_panel']) ?> </h6></div>
                              <p class="text-muted">
                        تاریخ تحویل درخواستی : <?= format_jalali_date($panel['east_required_delivery_date']) ?>
                                </p>
                                <p class="text-muted">
                                    تاریخ ایجاد سفارش : <?= format_jalali_date($panel['east_order_updated_at']) ?>
                            </p>                                  

                             <div class="order-details-east" data-order-id="<?= $panel['east_order_id'] ?>">
                             <?php if ($panel['east_order_id']):
                                 $orderId = $panel['east_order_id'];
                                 $orderType = 'east';
                                 $orderData = $panel; // Use the main $panel array
                                 $prefix = 'east_';
                                 $currentPanelId = $panel['panel_id']; // Get ID specifically for this block if needed, or use $panel['panel_id'] directly
                             ?>
                                 <p class="order-number">سفارش: <?= escapeHtml($orderData[$prefix.'order_number']) ?> <span class="text-muted small">(ID: <?= $orderId ?>)</span></p>
                                 <!-- Status Section -->
                                 <div class="mb-2">
                                     <!-- ... Status display ... -->
                                     <?php if (($userPermissions['update_status'] ?? false) && $userRole !== 'cnc_operator'): ?>
                                         <div class="mb-3">
                                    <div class="d-flex align-items-center mb-2 status-section-east">
                                        <span class="me-2">وضعیت:</span>
                                         <span class="badge bg-<?= get_status_color($panel['east_status']) ?>">
                                            <?= translate_status($panel['east_status']) ?>
                                        </span>
                                    </div>

                                    <?php if (($userPermissions['update_status'] ?? false) && $userRole !== 'cnc_operator'): ?>
                                     <form method="post" class="d-inline status-update-form">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?= $panel['east_order_id'] ?>">
                                            <input type="hidden" name="order_type" value="east">
                                            <select name="new_status" class="form-select form-select-sm w-auto">
                                                <option value="">-- تغییر وضعیت --</option>
                                                <option value="pending" <?= $panel['east_status'] == 'pending' ? 'selected' : '' ?>>در انتظار</option>
                                                <option value="ordered" <?= $panel['east_status'] == 'ordered' ? 'selected' : '' ?>>سفارش شده</option>

                                                <option value="delivered" <?= $panel['east_status'] == 'delivered' ? 'selected' : '' ?>>تحویل شده</option>
                                            </select>
                                            <button type="submit" class="btn btn-info btn-sm">به روز رسانی</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                     <?php endif; ?>
                                 </div>
                                 <!-- Dates Section -->
                                 <?php if (($userRole === 'cnc_operator' || ($userPermissions['edit_all'] ?? false))): ?>
                                     <!-- ... Date update form ... -->
                                 <?php endif; ?>
                                 <!-- Files Section -->
                                 <div class="files-section mt-2">
                                     <div class="upload-message-container-<?= $orderType ?>"></div>
                                     <?php if ($userPermissions['upload_files'] ?? false): ?>
                                         <form method="post" enctype="multipart/form-data" class="mb-2 file-upload-form">
                                             <input type="hidden" name="action" value="upload_file">
                                             <input type="hidden" name="panel_id" value="<?= $panel['panel_id'] // *** USE $panel['panel_id'] *** ?>">
                                             <input type="hidden" name="order_type" value="<?= $orderType ?>">
                                             <label class="form-label small mb-1">آپلود فایل:</label>
                                             <div class="input-group input-group-sm">
                                                 <input type="file" class="form-control design-file-input" name="design_file" required accept=".dxf,.dwg,.pdf,.zip">
                                                 <button type="submit" class="btn btn-outline-primary upload-submit-btn">آپلود</button>
                                             </div>
                                             <div class="progress mt-1" style="height: 5px; display: none;"><div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>
                                         </form>
                                     <?php endif; ?>
                                     <div class="file-list-<?= $orderType ?>">
                                        <?php
                                        // *** USE $panel['panel_id'] for path ***
                                        $orderDir = POLYSTYRENE_UPLOAD_PATH . $panel['panel_id'] . '/' . $orderType . '/';
                                        if (is_dir($orderDir)):
                                            $files = array_diff(scandir($orderDir), ['.', '..']);
                                            if (!empty($files)):
                                        ?>
                                             <div class="mt-1">
                                                 <h6 class="small d-inline-block me-2">فایل‌ها:</h6>
                                                 <ul class="file-list d-inline-block small p-0 m-0" style="list-style: none;">
                                                 <?php foreach ($files as $file):
                                                     // *** USE $panel['panel_id'] for path ***
                                                     $relativePath = 'uploads/polystyrene/' . $panel['panel_id'] . '/' . $orderType . '/' . $file;
                                                 ?>
                                                     <li class="d-inline-block me-2" data-file="<?= escapeHtml($relativePath) ?>">
                                                         <a href="<?= escapeHtml($relativePath) ?>" download><?= escapeHtml($file) ?></a>
                                                         <?php if ($userPermissions['delete_all'] ?? false): ?>
                                                             <form method="post" class="d-inline file-delete-form">
                                                                 <input type="hidden" name="action" value="delete_file">
                                                                 <input type="hidden" name="file_path" value="<?= escapeHtml($relativePath) ?>">
                                                                 <input type="hidden" name="panel_id" value="<?= $panel['panel_id'] // *** USE $panel['panel_id'] *** ?>">
                                                                 <button type="submit" class="btn btn-link text-danger p-0 border-0 btn-sm" style="text-decoration: none;">(حذف)</button>
                                                             </form>
                                                         <?php endif; ?>
                                                     </li>
                                                 <?php endforeach; ?>
                                                 </ul>
                                             </div>
                                        <?php endif; endif; ?>
                                     </div>
                                 </div>
                             <?php else: // No east order ?>
                                <?php if (($userPermissions['edit_all'] ?? false) || $userRole === 'planner'): ?>
                                    <form method="post" class="create-order-form">
                                        <input type="hidden" name="action" value="create_order">
                                        <input type="hidden" name="panel_id" value="<?= $panel['panel_id'] // *** USE $panel['panel_id'] *** ?>">
                                        <input type="hidden" name="order_type" value="east">
                                        <button type="submit" class="btn btn-success btn-sm">ایجاد سفارش </button>
                                    </form>
                                <?php else: echo '<span class="text-muted small">سفارشی ثبت نشده</span>'; endif; ?>
                             <?php endif; ?>
                             </div>
                         </div>
                    </div> <!-- End panel-sides -->
                </div> <!-- End card-body -->
            </div> <!-- End card -->
        <?php endforeach; ?>
    <?php endif; ?>

</div> <!-- End Container -->

<!-- Include Footer -->
<?php require_once 'footer.php'; ?>


<!-- JavaScript -->
<script src="assets/js/jquery-3.6.0.min.js"></script>
<script src="assets/js/persian-date.min.js"></script>
<script src="assets/js/persian-datepicker.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script> <!-- For Bootstrap alerts -->

<script>
    // --- Status Filter Click Handler ---
    function setStatusFilter(status) {
        document.getElementById('statusInput').value = status;
        document.getElementById('filterForm').submit();
    }

    // --- Helper function for JS Date Formatting (placeholder) ---
     function formatJalaliDateOrEmptyJS(gregorianDate) {
        if (!gregorianDate) return '';
        if (typeof persianDate !== 'undefined' && gregorianDate) {
             try {
                 const parts = gregorianDate.split('-');
                 // Ensure persianDate constructor gets numbers
                 return new persianDate([parseInt(parts[0]), parseInt(parts[1]), parseInt(parts[2])]).format('YYYY/MM/DD');
             } catch(e) { console.error("JS Date Format Error:", e); return gregorianDate; }
        }
        return gregorianDate;
    }
     // Simple HTML escape for displayMessage & dynamic list items
     function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') return ''; // Handle non-strings
         return unsafe.replace(/&/g, "&").replace(/</g, "<").replace(/>/g, ">").replace(/"/g, '"').replace(/'/g, "'");
     }


    $(document).ready(function() {
        // --- Datepicker Initialization ---
        if (typeof $.fn.persianDatepicker === 'function') {
            const commonDatepickerOptions = {
                format: 'YYYY/MM/DD',
                autoClose: true,
                persianNumbers: false,   // Use LATIN numbers in input value for consistency
                initialValue: false,
                toolbox: { calendarSwitch: { enabled: false } },
                observer: true,
                calendar: { persian: { locale: 'fa', leapYearMode: 'astronomical' } },
                 onSelect: function(unix) {
                    // Set the value AND trigger change
                    // The picker instance is 'this'
                    $(this.el).val(this.toLocale('fa').format(this.options.format)); // Ensure latin digits if needed
                    $(this.el).trigger('change');
                 }
             };
             const filterDatepickerOptions = { // Specific options for filter inputs
                format: 'YYYY/MM/DD',
                autoClose: true,
                persianNumbers: true, // Use persian numbers for display in filter
                initialValue: false,
                toolbox: { calendarSwitch: { enabled: false } },
                observer: true,
                calendar: { persian: { locale: 'fa', leapYearMode: 'astronomical' } }
             };

            $('.datepicker').each(function() {
                 const initialVal = $(this).val() ? true : false;
                 $(this).persianDatepicker({
                     ...commonDatepickerOptions,
                     initialValue: initialVal
                 });
             });
             // Initialize datepickers for FILTER BAR
            $('.datepicker-filter').each(function() {
                 const initialVal = $(this).val() ? true : false;
                 $(this).persianDatepicker({
                     ...filterDatepickerOptions, // Use filter-specific options
                     initialValue: initialVal
                 });
             });
        } else {
            console.error("PersianDatepicker jQuery plugin not found!");
        }

        // --- AJAX Form Handlers ---
        function performAjaxAction(form, action, successCallback) {
            var formData = new FormData(form);
            if (!formData.has('action')) { formData.append('action', action); }
            var currentParams = new URLSearchParams(window.location.search);
            var url = '<?= $_SERVER["PHP_SELF"] ?>?' + currentParams.toString();

            $.ajax({
                url: url, type: 'POST', data: formData,
                processData: false, contentType: false, dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        if (successCallback) { successCallback(response, form); }
                        displayMessage(response.message || 'عملیات موفق.', 'success');
                    } else {
                        displayMessage(response.error || 'خطای ناشناخته.', 'danger');
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error:", status, error, xhr.responseText);
                    var errorMsg = "خطا در ارتباط: " + error;
                    try { var serverResponse = JSON.parse(xhr.responseText); if(serverResponse && serverResponse.error) errorMsg += " - " + serverResponse.error; } catch(e) {}
                    displayMessage(errorMsg, 'danger');
                },
                complete: function(xhr, status) {
                     var $submitButton = $(form).find('button[type="submit"]');
                     // Check if original text data exists before trying to access it
                     var originalText = $submitButton.data('original-text') || 'Submit';
                     $submitButton.prop('disabled', false).text(originalText);
                 }
            });
        }

        function displayMessage(message, type) {
             var messageContainer = $('#message-container');
             var alertClass = (type === 'success') ? 'alert-success' : 'alert-danger';
             var messageHtml = `<div class="alert ${alertClass} alert-dismissible fade show m-3" role="alert"> ${escapeHtml(message)} <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button> </div>`;
             messageContainer.html(messageHtml);
             $('html, body').animate({ scrollTop: 0 }, 300);
         }


        // --- Event Handler Bindings ---

        // File Upload (Using XHR for Progress)
        $(document).on('submit', '.file-upload-form', function(e) {
             e.preventDefault();
             var form = this; var $form = $(form); var formData = new FormData(form);
             var orderType = $form.find('input[name="order_type"]').val();
             var $messageContainer = $form.closest('.files-section').find('.upload-message-container-' + orderType);
             var $fileListContainer = $form.closest('.files-section').find('.file-list-' + orderType);
             var $submitButton = $form.find('.upload-submit-btn');
             var $progressBarContainer = $form.find('.progress');
             var $progressBar = $progressBarContainer.find('.progress-bar');
             var $fileInput = $form.find('.design-file-input');

             if ($fileInput[0].files.length === 0) { displayMessage('لطفا فایلی را برای آپلود انتخاب کنید.', 'danger'); return; }

             $submitButton.prop('disabled', true).data('original-text', $submitButton.text()).text('در حال آپلود...');
             $progressBarContainer.show(); $progressBar.width('0%').attr('aria-valuenow', 0).text(''); $messageContainer.empty();

             var xhr = new XMLHttpRequest();
             xhr.upload.addEventListener('progress', function(event) { if (event.lengthComputable) { var percentComplete = Math.round((event.loaded / event.total) * 100); $progressBar.width(percentComplete + '%').attr('aria-valuenow', percentComplete); /* Removed text % */ } }, false);
             xhr.addEventListener('load', function(event) {
                 $progressBarContainer.hide(); $submitButton.prop('disabled', false).text($submitButton.data('original-text') || 'آپلود');
                 if (xhr.status >= 200 && xhr.status < 300) {
                      try {
                          var response = JSON.parse(xhr.responseText);
                          if (response.success) {
                              var $ul = $fileListContainer.find('ul.file-list'); if ($ul.length === 0) { $fileListContainer.html('<h6 class="small mt-2">فایل‌ها:</h6><ul class="file-list small p-0 m-0" style="list-style: none;"></ul>'); $ul = $fileListContainer.find('ul.file-list'); }
                              var listItem = `<li class="d-inline-block me-2" data-file="${escapeHtml(response.file_path)}"><a href="${escapeHtml(response.file_path)}" download>${escapeHtml(response.file_name)}</a> <form method="post" class="d-inline file-delete-form"><input type="hidden" name="action" value="delete_file"><input type="hidden" name="file_path" value="${escapeHtml(response.file_path)}"><input type="hidden" name="panel_id" value="${$form.find('input[name="panel_id"]').val()}"><button type="submit" class="btn btn-link text-danger p-0 border-0 btn-sm" style="text-decoration: none;">(حذف)</button></form></li>`;
                              $ul.append(listItem); $fileInput.val('');
                              $messageContainer.html(`<p class="text-success small">${escapeHtml(response.message)}</p>`); setTimeout(() => { $messageContainer.empty(); }, 5000);
                          } else { displayMessage(response.error || 'خطا در آپلود فایل.', 'danger'); }
                      } catch (e) { console.error("Error parsing server response:", e, xhr.responseText); displayMessage('پاسخ غیرمنتظره از سرور دریافت شد.', 'danger'); }
                  } else { console.error("Upload failed with status:", xhr.status, xhr.statusText); displayMessage(`خطا در آپلود (${xhr.status}: ${xhr.statusText})`, 'danger'); }
             });
             xhr.addEventListener('error', function(event) { $progressBarContainer.hide(); $submitButton.prop('disabled', false).text($submitButton.data('original-text') || 'آپلود'); displayMessage('خطای شبکه.', 'danger'); console.error("Network error:", event); });
             xhr.addEventListener('abort', function(event) { $progressBarContainer.hide(); $submitButton.prop('disabled', false).text($submitButton.data('original-text') || 'آپلود'); displayMessage('آپلود لغو شد.', 'warning'); });
             var url = '<?= $_SERVER["PHP_SELF"] ?>?<?= http_build_query($_GET) ?>'; xhr.open('POST', url, true); xhr.send(formData);
         });

        // File Delete
        $(document).on('submit', '.file-delete-form', function(e) {
             e.preventDefault(); if (!confirm('آیا از حذف این فایل مطمئن هستید؟')) return;
             var form = this; var $listItem = $(form).closest('li'); var $submitButton = $(form).find('button[type="submit"]'); $submitButton.prop('disabled', true);
             performAjaxAction(form, 'delete_file', function(response) { $listItem.remove(); });
        });

        // Status Update
        $(document).on('submit', '.status-update-form', function(e) {
             e.preventDefault(); var form = this; var $form = $(form); var orderType = $form.find('input[name="order_type"]').val(); var $statusSection = $form.closest('.mb-2').find('.status-section-' + orderType); var $submitButton = $form.find('button[type="submit"]'); $submitButton.prop('disabled', true).data('original-text', $submitButton.text()).text('...');
             performAjaxAction(form, 'update_status', function(response) { $statusSection.find('.badge').text(response.new_status_text).removeClass(function(i, c){ return (c.match (/(^|\s)bg-\S+/g) || []).join(' '); }).addClass('bg-' + response.new_status_color); $form.find('select[name="new_status"]').val(''); });
        });

        // Date Update
        $(document).on('submit', '.date-update-form', function(e) {
             e.preventDefault(); var form = this; var $form = $(form); var $submitButton = $form.find('button[type="submit"]'); $submitButton.prop('disabled', true).data('original-text', $submitButton.text()).text('...');
             performAjaxAction(form, 'update_dates', function(response, submittedForm) {
                 console.log("Dates updated via AJAX:", response);
                 // Optional: Update displayed Jalali date based on returned Gregorian
                 if (response.success && response.updated_dates_gregorian) {
                     var startDateGreg = response.updated_dates_gregorian.production_start_date;
                     var endDateGreg = response.updated_dates_gregorian.production_end_date;
                     $(submittedForm).find('.production_start_date').val(formatJalaliDateOrEmptyJS(startDateGreg));
                     $(submittedForm).find('.production_end_date').val(formatJalaliDateOrEmptyJS(endDateGreg));
                     // Re-initialize picker on these specific fields if needed after update
                     // $(submittedForm).find('.datepicker').persianDatepicker('destroy').persianDatepicker(commonDatepickerOptions);
                 }
             });
        });

        // Create Order
        $(document).on('submit', '.create-order-form', function(e) {
             e.preventDefault(); var form = this; var $form = $(form); var panelId = $form.find('input[name="panel_id"]').val(); var $submitButton = $form.find('button[type="submit"]'); $submitButton.prop('disabled', true).data('original-text', $submitButton.text()).text('...');
             performAjaxAction(form, 'create_order', function(response) {
                 var $panelCard = $("#panel-" + panelId); var currentScroll = $(window).scrollTop();
                 $panelCard.load(window.location.href + " #panel-" + panelId + " > *", function() {
                      // Re-initialize ALL datepickers within the reloaded card
                      $panelCard.find('.datepicker').each(function() { $(this).persianDatepicker(commonDatepickerOptions); });
                      $(window).scrollTop(currentScroll);
                  });
             });
        });

    }); // End document ready
</script>
</body>
</html>