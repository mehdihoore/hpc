<?php
// api/save-truck-shipment.php
ini_set('display_errors', 1); // Enable error display for debugging
error_reporting(E_ALL);
ob_start(); // Start output buffering to catch stray output/errors
require __DIR__ . '/../../../sercon/bootstrap.php';
require_once  __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/jdf.php';

secureSession();
$expected_project_key = 'fereshteh'; // HARDCODED FOR THIS FILE
$current_project_config_key = $_SESSION['current_project_config_key'] ?? null;

if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}
if ($current_project_config_key !== $expected_project_key) {
    logError("Concrete test manager accessed with incorrect project context. Session: {$current_project_config_key}, Expected: {$expected_project_key}, User: {$_SESSION['user_id']}");
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}


if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

$current_user_id = $_SESSION['user_id']; // Get current user ID
$report_key = 'save-truck-schedule'; // HARDCODED FOR THIS FILE
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/api/save-truck-schedule.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superuser')) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$shipping_date_gregorian = null; // Initialize
if (isset($input['shipping_date_persian']) && !empty($input['shipping_date_persian'])) {
    $dateParts = explode('/', $input['shipping_date_persian']);
    if (count($dateParts) === 3) {
        list($jy, $jm, $jd) = array_map('intval', $dateParts);
        $gregorianDateArray = jalali_to_gregorian($jy, $jm, $jd); // Expects [y, m, d] array
        if (is_array($gregorianDateArray) && count($gregorianDateArray) === 3) {
            $shipping_date_gregorian = sprintf('%04d-%02d-%02d', $gregorianDateArray[0], $gregorianDateArray[1], $gregorianDateArray[2]);
            // Validate the converted date
            if (!checkdate((int)$gregorianDateArray[1], (int)$gregorianDateArray[2], (int)$gregorianDateArray[0])) {
                logError("Converted shipping date is invalid: " . $shipping_date_gregorian . " from Persian " . $input['shipping_date_persian']);
                $shipping_date_gregorian = null; // Invalidate if checkdate fails
            }
        } else {
            logError("jalali_to_gregorian failed for shipping date: " . $input['shipping_date_persian']);
            $shipping_date_gregorian = null;
        }
    } else {
        logError("Invalid Persian shipping date format: " . $input['shipping_date_persian']);
        $shipping_date_gregorian = null;
    }
} elseif (isset($input['shipping_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['shipping_date'])) {
    // Fallback: Use Gregorian if Persian not sent but Gregorian is present and valid format
    $datePartsCheck = explode('-', $input['shipping_date']);
    if (checkdate((int)$datePartsCheck[1], (int)$datePartsCheck[2], (int)$datePartsCheck[0])) {
        $shipping_date_gregorian = $input['shipping_date'];
    } else {
        logError("Invalid Gregorian shipping date provided directly: " . $input['shipping_date']);
    }
}

$stands_return_date_gregorian = null; // Initialize
// Check if the PERSIAN version is sent
if (isset($input['stands_return_date_persian']) && !empty($input['stands_return_date_persian'])) {
    $returnDateParts = explode('/', $input['stands_return_date_persian']);
    if (count($returnDateParts) === 3) {
        list($jy_ret, $jm_ret, $jd_ret) = array_map('intval', $returnDateParts);
        $gregorianReturnDateArray = jalali_to_gregorian($jy_ret, $jm_ret, $jd_ret);
        if (is_array($gregorianReturnDateArray) && count($gregorianReturnDateArray) === 3) {
            $stands_return_date_gregorian = sprintf('%04d-%02d-%02d', $gregorianReturnDateArray[0], $gregorianReturnDateArray[1], $gregorianReturnDateArray[2]);
            // Validate the converted date
            if (!checkdate((int)$gregorianReturnDateArray[1], (int)$gregorianReturnDateArray[2], (int)$gregorianReturnDateArray[0])) {
                logError("Converted return date is invalid: " . $stands_return_date_gregorian . " from Persian " . $input['stands_return_date_persian']);
                $stands_return_date_gregorian = null; // Invalidate
            }
        } else {
            logError("jalali_to_gregorian failed for return date: " . $input['stands_return_date_persian']);
            $stands_return_date_gregorian = null;
        }
    } else {
        logError("Invalid Persian return date format: " . $input['stands_return_date_persian']);
        $stands_return_date_gregorian = null;
    }
} elseif (isset($input['stands_return_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['stands_return_date'])) {
    // Fallback: Use Gregorian if Persian not sent but Gregorian is present and valid format
    $datePartsCheckRet = explode('-', $input['stands_return_date']);
    if (checkdate((int)$datePartsCheckRet[1], (int)$datePartsCheckRet[2], (int)$datePartsCheckRet[0])) {
        $stands_return_date_gregorian = $input['stands_return_date'];
    } else {
        logError("Invalid Gregorian return date provided directly: " . $input['stands_return_date']);
    }
}
// Basic validation (Remove destination)
if (
    !isset($input['truck_id'], $input['shipping_date'], $input['shipping_time'], $input['status'], $input['status'])
    || !is_numeric($input['truck_id'])
) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid required fields']);
    exit();
}

// Date Validation (Keep this as is)
if (
    !isset($input['truck_id'], $input['shipping_time'], $input['status']) ||
    empty($shipping_date_gregorian) || // Check the PROCESSED Gregorian date
    !is_numeric($input['truck_id'])
) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid required fields (Truck ID, Shipping Date, Time, Status)']);
    exit();
}
$stands_sent = isset($input['stands_sent']) && is_numeric($input['stands_sent'])
    ? max(0, (int)$input['stands_sent']) : 0;
$stands_returned = isset($input['stands_returned']) && is_numeric($input['stands_returned'])
    ? max(0, (int)$input['stands_returned']) : 0;



try {

    $shipmentId = isset($input['shipment_id']) ? (int)$input['shipment_id'] : null;

    if ($shipmentId) {
        // UPDATE existing shipment

        // 1. CHECK IF SHIPMENT EXISTS (and belongs to the truck)
        $stmt = $pdo->prepare("SELECT * FROM shipments WHERE id = ? AND truck_id = ?");
        $stmt->execute([$shipmentId, $input['truck_id']]);
        $existingShipment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingShipment) {
            echo json_encode(['success' => false, 'message' => 'Shipment not found or does not belong to the specified truck.']);
            exit();
        }

        // 2.  ONLY UPDATE IF THERE ARE CHANGES
        $updateNeeded = false;
        if ($input['shipping_date'] != $existingShipment['shipping_date']) $updateNeeded = true;
        if ($input['shipping_time'] != $existingShipment['shipping_time']) $updateNeeded = true;
        if ($input['status'] != $existingShipment['status']) $updateNeeded = true;
        if (($input['notes'] ?? null) != $existingShipment['notes']) $updateNeeded = true; // Handle potential NULL
        if ($stands_sent != (int)($existingShipment['stands_sent'] ?? 0)) $updateNeeded = true;
        if ($stands_returned != (int)($existingShipment['stands_returned'] ?? 0)) $updateNeeded = true;
        if ($stands_return_date_gregorian != $existingShipment['stands_return_date']) $updateNeeded = true;
        if ($updateNeeded) {
            // Removed 'destination = ?' from the query
            $stmt = $pdo->prepare("
                UPDATE shipments
                SET shipping_date = ?, shipping_time = ?, status = ?, notes = ?, stands_sent = ?, stands_returned = ?, stands_return_date = ?
                WHERE id = ? AND truck_id = ?
            ");
            $stmt->execute([
                $input['shipping_date'],
                $input['shipping_time'],
                $input['status'],
                $input['notes'] ?? null,
                $stands_sent,
                $stands_returned,
                $stands_return_date_gregorian,
                $shipmentId,
                $input['truck_id']
            ]);
        }

        // 3.  Handle Panel Status Updates (BOTH directions)
        if ($input['status'] === 'in_transit' || $input['status'] === 'delivered') {
            $stmt = $pdo->prepare("
                UPDATE hpc_panels
                SET packing_status = 'shipped', shipping_date = ?, shipping_time = ?, user_id = ?
                WHERE truck_id = ?
            ");
            $stmt->execute([$input['shipping_date'], $input['shipping_time'], $_SESSION['user_id'], $input['truck_id']]); // Set user_id

        } elseif ($existingShipment['status'] === 'in_transit' || $existingShipment['status'] === 'delivered') {
            $stmt = $pdo->prepare("
                UPDATE hpc_panels
                SET packing_status = 'assigned', shipping_date = NULL, shipping_time = NULL, user_id = ?
                WHERE truck_id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $input['truck_id']]); // Clear date/time, set user id

        }
    } else {
        // INSERT new shipment
        $stmt = $pdo->prepare("SELECT id FROM shipments WHERE truck_id = ?");
        $stmt->execute([$input['truck_id']]);

        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Truck already has a scheduled shipment.']);
            exit();
        }
        $stmtfindg = $pdo->prepare("SELECT stands_sent as laststandnumber FROM shipments WHERE packing_list_number = (SELECT MAX(packing_list_number) FROM shipments)");
        $stmtfindg->execute();
        $resultfindg = $stmtfindg->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT MAX(packing_list_number) AS max_packing_list_number FROM shipments");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextPackingListNumber = ($result['max_packing_list_number'] === null) ? 1 : (int)$result['max_packing_list_number'] + 1;
        $stmtg = $pdo->prepare("SELECT MAX(global_stand_number_offset) AS max_global FROM shipments");
        $stmtg->execute();
        $resultg = $stmtg->fetch(PDO::FETCH_ASSOC);

        $laststandsent = isset($resultfindg['laststandnumber']) ? (int)$resultfindg['laststandnumber'] : 0;
        $maxGlobal = isset($resultg['max_global']) ? (int)$resultg['max_global'] : 0;
        $globalStandNumberOffset = $maxGlobal + $laststandsent;

        // ---> UPDATED SQL (Added stands_sent column and placeholder) <---
        $stmt = $pdo->prepare("
        INSERT INTO shipments (
              truck_id, shipping_date, shipping_time, status, notes,
                created_by, packing_list_number, stands_sent,
                stands_returned, stands_return_date, global_stand_number_offset
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $input['truck_id'],
            $input['shipping_date'],
            $input['shipping_time'],
            $input['status'],
            $input['notes'] ?? null,
            $_SESSION['user_id'],
            $nextPackingListNumber,
            $stands_sent,
            $stands_returned,
            $stands_return_date_gregorian,
            $globalStandNumberOffset // Use max_global or default to 0
        ]);
        // Update panel status if shipment is in_transit or delivered
        if ($input['status'] === 'in_transit' || $input['status'] === 'delivered') {
            $stmt = $pdo->prepare("
                UPDATE hpc_panels
                SET packing_status = 'shipped', shipping_date = ?, shipping_time = ?, user_id = ?
                WHERE truck_id = ?
            ");
            $stmt->execute([$input['shipping_date'], $input['shipping_time'], $_SESSION['user_id'], $input['truck_id']]); // Set user_id
        }
    }

    echo json_encode(['success' => true, 'message' => 'Shipment saved successfully']);
} catch (PDOException $e) {
    logError("Database error in save-truck-shipment.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.  See server logs for details.']);
}
