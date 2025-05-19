<?php
// panel_detail.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');

// --- Your existing setup code ---
require_once __DIR__ . '/../../sercon/bootstrap.php'; // Adjust path if needed
// require_once __DIR__ .'/includes/jdf.php'; // If needed for display, otherwise not for this logic
secureSession();
$expected_project_key = 'fereshteh';
$current_project_config_key = $_SESSION['current_project_config_key'] ?? null;

if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}
if ($current_project_config_key !== $expected_project_key) {
    logError("Access violation: {$expected_project_key} map accessed with project {$current_project_config_key}. User: {$_SESSION['user_id']}");
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}
$allowed_roles = ['admin', 'supervisor', 'superuser', 'planner']; // Add other roles as needed
if (!in_array($_SESSION['role'], $allowed_roles)) {
    logError("Unauthorized role '{$_SESSION['role']}' attempt on {$expected_project_key} map. User: {$_SESSION['user_id']}");
    header('Location: dashboard.php?msg=unauthorized');
    exit();
}
$user_id = $_SESSION['user_id'];
$pdo = null;
try {
    $pdo = getProjectDBConnection();
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key} map: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}
// Get current user's role and ID for checks later
$currentUserRole = $_SESSION['role'] ?? '';
$currentUserId = $_SESSION['user_id'] ?? 0;


// --- AJAX Handling for Plan Check ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // --- ACTION: markPlanChecked ---
    if ($_POST['action'] === 'markPlanChecked') {
        header('Content-Type: application/json'); // Set JSON header

        // Role Check: Only Planner can approve
        if ($currentUserRole !== 'planner') {
            echo json_encode(['success' => false, 'message' => 'فقط کاربر برنامه‌ریز می‌تواند نقشه را تایید کند.']);
            exit();
        }

        $panelIdToCheck = isset($_POST['panelId']) ? filter_var($_POST['panelId'], FILTER_VALIDATE_INT) : 0;
        $checkerUserId = $currentUserId; // The logged-in planner doing the check

        if (!$panelIdToCheck || !$checkerUserId) {
            echo json_encode(['success' => false, 'message' => 'شناسه پنل یا کاربر نامعتبر']);
            exit();
        }

        try {

            $updateStmt = $pdo->prepare("
                    UPDATE hpc_panels
                    SET plan_checked = 1,
                        plan_checker_user_id = :userId
                    WHERE id = :panelId AND plan_checked = 0 -- Only update if currently unchecked
                ");
            $updateStmt->execute([
                ':userId' => $checkerUserId,
                ':panelId' => $panelIdToCheck
            ]);

            if ($updateStmt->rowCount() > 0) {
                // Fetch checker FULL NAME to return
                $checkerStmt = $pdo->prepare("SELECT first_name, last_name FROM hpc_common.users WHERE id = ?");
                $checkerStmt->execute([$checkerUserId]);
                $checkerNames = $checkerStmt->fetch(PDO::FETCH_ASSOC);
                // Combine first and last name, trim extra spaces, provide fallback
                $checkerFullName = trim(htmlspecialchars($checkerNames['first_name'] ?? '') . ' ' . htmlspecialchars($checkerNames['last_name'] ?? '')) ?: 'کاربر نامشخص';

                // Optional: Log this action
                // logActivity($checkerUserId, 'plan_checked', $panelIdToCheck);

                echo json_encode([
                    'success' => true,
                    'message' => 'نقشه با موفقیت تایید شد.',
                    'checkerFullName' => $checkerFullName // Send back full name
                ]);
            } else {
                // Check if it was already checked or ID was wrong
                $checkStmt = $pdo->prepare("SELECT plan_checked, plan_checker_user_id FROM hpc_panels WHERE id = ?");
                $checkStmt->execute([$panelIdToCheck]);
                $currentStatus = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($currentStatus && $currentStatus['plan_checked'] == 1) {
                    // Fetch existing checker full name
                    $checkerStmt = $pdo->prepare("SELECT first_name, last_name FROM hpc_common.users WHERE id = ?");
                    $checkerStmt->execute([$currentStatus['plan_checker_user_id']]);
                    $checkerNames = $checkerStmt->fetch(PDO::FETCH_ASSOC);
                    $checkerFullName = trim(htmlspecialchars($checkerNames['first_name'] ?? '') . ' ' . htmlspecialchars($checkerNames['last_name'] ?? '')) ?: 'کاربر نامشخص';

                    echo json_encode([
                        'success' => true, // Still success in a way, just no change made
                        'message' => 'نقشه قبلا تایید شده بود.',
                        'checkerFullName' => $checkerFullName
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'خطا در بروزرسانی یا پنل یافت نشد.']);
                }
            }
        } catch (PDOException $e) {
            logError("Database error checking plan: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'خطای پایگاه داده رخ داد.']);
        } finally {
            $pdo = null; // Close connection
        }
        exit(); // Important: Stop script execution after AJAX response
    } // --- END ACTION: markPlanChecked ---

    // --- ACTION: markPlanPending ---
    elseif ($_POST['action'] === 'markPlanPending') {
        header('Content-Type: application/json');

        // Role Check: Only Planner can revert
        if ($currentUserRole !== 'planner') {
            echo json_encode(['success' => false, 'message' => 'فقط کاربر برنامه‌ریز می‌تواند تایید را لغو کند.']);
            exit();
        }

        $panelIdToRevert = isset($_POST['panelId']) ? filter_var($_POST['panelId'], FILTER_VALIDATE_INT) : 0;

        if (!$panelIdToRevert) {
            echo json_encode(['success' => false, 'message' => 'شناسه پنل نامعتبر']);
            exit();
        }

        try {


            // Check assigned_date before reverting
            // Fetches the assigned_date. Returns NULL if the column is NULL, false if the row doesn't exist.
            $checkStmt = $pdo->prepare("SELECT assigned_date FROM hpc_panels WHERE id = ?");
            $checkStmt->execute([$panelIdToRevert]);
            $assignedDate = $checkStmt->fetchColumn();

            // Check if the query executed successfully and returned a row
            if ($assignedDate === false) {
                // Panel not found
                echo json_encode(['success' => false, 'message' => 'پنل یافت نشد.']);
                exit();
            }
            // Check if the assigned_date column is NOT NULL (meaning it has a date)
            elseif ($assignedDate !== null) {
                echo json_encode(['success' => false, 'message' => 'امکان لغو تایید وجود ندارد زیرا تاریخ شروع تولید برای پنل ثبت شده است.']);
                exit();
            }

            // Proceed with revert only if assigned_date IS NULL
            $updateStmt = $pdo->prepare("
                     UPDATE hpc_panels
                     SET plan_checked = 0,
                         plan_checker_user_id = NULL -- Clear the checker
                     WHERE id = :panelId AND plan_checked = 1 -- Only revert if currently checked
                 ");
            $updateStmt->execute([':panelId' => $panelIdToRevert]);

            if ($updateStmt->rowCount() > 0) {
                // Optional: Log this action
                // logActivity($currentUserId, 'plan_reverted', $panelIdToRevert);

                echo json_encode([
                    'success' => true,
                    'message' => 'تایید نقشه با موفقیت لغو شد.'
                ]);
            } else {
                // Maybe it was already reverted, or ID was wrong, or wasn't checked
                echo json_encode(['success' => false, 'message' => 'خطا در لغو تایید یا نقشه قبلا تایید نشده بود.']);
            }
        } catch (PDOException $e) {
            logError("Database error reverting plan check: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'خطای پایگاه داده رخ داد.']);
        } finally {
            $pdo = null; // Close connection
        }
        exit(); // Stop script execution
    } // --- END ACTION: markPlanPending ---
    elseif ($_POST['action'] === 'uploadNewSvg' && isset($_POST['panelId']) && isset($_FILES['newSvgFile'])) {
        header('Content-Type: application/json'); // Or redirect back with message

        // Role Check: Only Planner
        if ($currentUserRole !== 'planner') {
            // Using JSON response for consistency, could redirect too
            echo json_encode(['success' => false, 'message' => 'فقط کاربر برنامه‌ریز می‌تواند نقشه را تغییر دهد.']);
            exit();
        }

        $panelIdToUpdate = isset($_POST['panelId']) ? filter_var($_POST['panelId'], FILTER_VALIDATE_INT) : 0;
        $file = $_FILES['newSvgFile'];

        if (!$panelIdToUpdate || $file['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [ /* Standard upload error messages */];
            echo json_encode(['success' => false, 'message' => 'خطا در آپلود فایل: ' . ($uploadErrors[$file['error']] ?? 'Unknown error')]);
            exit();
        }

        try {


            // Fetch panel address and check assigned_date
            $checkStmt = $pdo->prepare("SELECT address, assigned_date FROM hpc_panels WHERE id = ?");
            $checkStmt->execute([$panelIdToUpdate]);
            $panelData = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$panelData) {
                echo json_encode(['success' => false, 'message' => 'پنل یافت نشد.']);
                exit();
            }

            // Check if assigned_date is set
            if ($panelData['assigned_date'] !== null) {
                echo json_encode(['success' => false, 'message' => 'امکان تغییر نقشه وجود ندارد زیرا تاریخ شروع تولید برای پنل ثبت شده است.']);
                exit();
            }

            // --- File Validation ---
            $allowedMimeType = 'image/svg+xml';
            $maxFileSize = 2 * 1024 * 1024; // 2 MB limit (adjust as needed)

            if ($file['type'] !== $allowedMimeType) {
                echo json_encode(['success' => false, 'message' => 'نوع فایل نامعتبر است. فقط فایل SVG مجاز است. (Type received: ' . htmlspecialchars($file['type']) . ')']);
                exit();
            }

            if ($file['size'] > $maxFileSize) {
                echo json_encode(['success' => false, 'message' => 'حجم فایل بیش از حد مجاز است (حداکثر 2 مگابایت).']);
                exit();
            }
            // Basic SVG content check (optional but recommended)
            $svgContent = file_get_contents($file['tmp_name']);
            if (strpos($svgContent, '<svg') === false || strpos($svgContent, '</svg>') === false) {
                echo json_encode(['success' => false, 'message' => 'محتوای فایل SVG نامعتبر به نظر می‌رسد.']);
                exit();
            }

            // --- File Saving ---
            $panelAddress = $panelData['address'];
            $filename = basename($panelAddress) . ".svg"; // Use panel address for filename
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . "/Fereshteh/svg_files/"; // Ensure this directory exists and is writable
            $destinationPath = $uploadDir . $filename;

            // Ensure directory exists
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0775, true)) { // Create recursively with appropriate permissions
                    throw new Exception("Failed to create SVG upload directory.");
                }
            }
            if (!is_writable($uploadDir)) {
                throw new Exception("SVG upload directory is not writable.");
            }


            // Move the uploaded file, overwriting the existing one
            if (move_uploaded_file($file['tmp_name'], $destinationPath)) {
                // Successfully uploaded and replaced
                // Optional: Update a 'plan_last_updated' timestamp in DB?
                // Optional: Log action
                // logActivity($currentUserId, 'svg_updated', $panelIdToUpdate);

                // Redirect back with success message to force refresh of SVG image
                // Adding a cache buster to the redirect ensures the browser reloads the SVG
                header("Location: panel_detail.php?id=" . $panelIdToUpdate . "&svg_updated=1&cb=" . time());
                exit();
            } else {
                throw new Exception("Failed to move uploaded SVG file.");
            }
        } catch (PDOException $e) {
            logError("Database error during SVG upload check: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'خطای پایگاه داده رخ داد.']);
        } catch (Exception $e) {
            logError("File system error during SVG upload: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'خطا در پردازش فایل: ' . $e->getMessage()]);
        } finally {
            $pdo = null;
        }
        exit();
    } // --- END ACTION: uploadNewSvg ---

} // --- End AJAX Handling ---
// Function to convert Gregorian to Shamsi date (ensure it's defined)
if (!function_exists('gregorianToShamsi')) {
    function gregorianToShamsi($date)
    {
        if (empty($date) || $date == '0000-00-00') return '';
        try {
            // Simple approach using DateTime (adjust format as needed)
            $dt = new DateTime($date);
            // Basic conversion logic - REPLACE with your accurate complex function or a library (like moment.php)
            // This is just a placeholder if your complex function isn't available here
            // return $dt->format('Y/m/d') . ' (Shamsi Approx)';
            // --- PASTE YOUR ACCURATE gregorianToShamsi function logic here ---
            // Example using the structure from your previous code:
            $year = $dt->format('Y');
            $month = $dt->format('m');
            $day = $dt->format('d');
            // ... (rest of your calculation logic) ...
            $gDays = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            $jDays = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
            $gy = $year - 1600;
            $gm = $month - 1;
            $gd = $day - 1;
            $gDayNo = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
            for ($i = 0; $i < $gm; ++$i) $gDayNo += $gDays[$i];
            if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) $gDayNo++;
            $gDayNo += $gd;
            $jDayNo = $gDayNo - 79;
            $jNp = floor($jDayNo / 12053);
            $jDayNo %= 12053;
            $jy = 979 + 33 * $jNp + 4 * floor($jDayNo / 1461);
            $jDayNo %= 1461;
            if ($jDayNo >= 366) {
                $jy += floor(($jDayNo - 1) / 365);
                $jDayNo = ($jDayNo - 1) % 365;
            }
            for ($i = 0; $i < 11 && $jDayNo >= $jDays[$i]; ++$i) $jDayNo -= $jDays[$i];
            $jm = $i + 1;
            $jd = $jDayNo + 1;
            return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
            // --- End of pasted logic ---

        } catch (Exception $e) {
            return 'تاریخ نامعتبر';
        }
    }
}


// --- Regular Page Load Logic ---
$panelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$panelId) {
    die("No panel ID provided");
}

// Initialize $panel variable
$panel = null;
$checkerUsername = null; // For storing checker username

try {


    // Get panel details (including new columns)
    $stmt = $pdo->prepare("
            SELECT
            hp.*,
            ft.type as formwork_type_name,
            ft.width as formwork_width,
            ft.max_length as formwork_max_length,
            ft.is_disabled as formwork_is_disabled,
            ft.in_repair as formwork_in_repair,
            ft.is_available as formwork_is_available,
            s1.dimension_1 as left_dim1, s1.dimension_2 as left_dim2, s1.dimension_3 as left_dim3, s1.dimension_4 as left_dim4, s1.dimension_5 as left_dim5,
            s1.left_panel as left_panel_full, s1.right_panel as right_panel_full,
            s2.dimension_1 as right_dim1, s2.dimension_2 as right_dim2, s2.dimension_3 as right_dim3, s2.dimension_4 as right_dim4, s2.dimension_5 as right_dim5,
            -- Fetch first and last name instead of username
            checker.first_name as plan_checker_fname,
            checker.last_name as plan_checker_lname
        FROM hpc_panels hp
        LEFT JOIN formwork_types ft ON hp.formwork_type = ft.type
        LEFT JOIN sarotah s1 ON hp.address = SUBSTRING_INDEX(s1.left_panel, '-(', 1)
        LEFT JOIN sarotah s2 ON hp.address = SUBSTRING_INDEX(s2.right_panel, '-(', 1)
        LEFT JOIN hpc_common.users checker ON hp.plan_checker_user_id = checker.id
        WHERE hp.id = ?
        ");
    $stmt->execute([$panelId]);
    $panel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$panel) {
        die("Panel not found");
    }

    // --- Construct Full Name ---
    $checkerFullName = null;
    if (!empty($panel['plan_checker_fname']) || !empty($panel['plan_checker_lname'])) {
        // Combine first and last name, trim extra spaces
        $checkerFullName = trim(htmlspecialchars($panel['plan_checker_fname'] ?? '') . ' ' . htmlspecialchars($panel['plan_checker_lname'] ?? ''));
    }
    // Fetch concrete test data for the panel's assigned date
    $processedConcreteTests = [
        'strength_1' => null,
        'rejected_1' => null,
        'code_1' => null,
        'strength_7' => null,
        'rejected_7' => null,
        'code_7' => null,
        'strength_28' => null,
        'rejected_28' => null,
        'code_28' => null,
    ]; // Initialize structure

    if (!empty($panel['assigned_date'])) {
        // Fetch ALL concrete test data for the panel's assigned date
        $concreteTestsStmt = $pdo->prepare("
            SELECT
                sample_code,
                sample_age_at_break, -- Age is crucial
                is_rejected,         -- Rejection status for this specific row
                strength_1_day,      -- Manually entered 1-day strength (if applicable)
                strength_7_day,      -- Manually entered 7-day strength (if applicable)
                strength_28_day,     -- Manually entered 28-day strength (if applicable)
                compressive_strength_mpa -- Calculated strength for this specific row/break
            FROM concrete_tests
            WHERE production_date = ?
            ORDER BY sample_age_at_break ASC -- Order by age
        ");
        $concreteTestsStmt->execute([$panel['assigned_date']]);
        $concreteTestRows = $concreteTestsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Process the fetched rows into the consolidated structure
        foreach ($concreteTestRows as $row) {
            $age = $row['sample_age_at_break'];
            $is_rejected = $row['is_rejected']; // Rejection status for this specific test
            $sample_code = $row['sample_code']; // Code for this specific sample

            if ($age == 1) {
                // Prioritize manually entered strength_1_day, fallback to calculated compressive_strength_mpa if needed
                $processedConcreteTests['strength_1'] = $row['strength_1_day'] ?? $row['compressive_strength_mpa'] ?? null;
                $processedConcreteTests['rejected_1'] = $is_rejected;
                $processedConcreteTests['code_1'] = $sample_code;
            } elseif ($age == 7) {
                $processedConcreteTests['strength_7'] = $row['strength_7_day'] ?? null; // Use specific 7-day field
                $processedConcreteTests['rejected_7'] = $is_rejected;
                $processedConcreteTests['code_7'] = $sample_code;
            } elseif ($age == 28) {
                $processedConcreteTests['strength_28'] = $row['strength_28_day'] ?? null; // Use specific 28-day field
                $processedConcreteTests['rejected_28'] = $is_rejected;
                $processedConcreteTests['code_28'] = $sample_code;
            }
            // Ignore other ages if any exist
        }
    }
    // --- End Fetch and Process Concrete Test Data ---

    // Construct SVG filename and check existence
    $filename = basename($panel['address']) . ".svg";
    $server_path = $_SERVER['DOCUMENT_ROOT'] . "/Fereshteh/svg_files/" . $filename; // Ensure correct path
    if (file_exists($server_path)) {
        $panel['svg_url'] = "/Fereshteh/svg_files/" . urlencode(basename($panel['address'])) . ".svg"; // URL encode filename part
    } else {
        error_log("SVG not found: " . $server_path); // Log if not found
        $panel['svg_url'] = "";
    }


    // West/East Address Parsing
    $addressParts = explode('-', $panel['address']);
    $westAddress = '';
    $eastAddress = '';
    if (count($addressParts) >= 3) {
        $direction = $addressParts[0];
        $westAddress = $direction . '-' . $addressParts[1];
        $eastAddress = $direction . '-' . $addressParts[2];
    }
} catch (PDOException $e) {
    logError("Database error in panel_detail.php: " . $e->getMessage()); // Assuming logError exists
    die("A database error occurred. Please try again.");
} catch (Exception $e) {
    die("An error occurred: " . $e->getMessage());
}

?>


<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>جزئیات پنل <?php echo htmlspecialchars($panel['address'] ?? 'Unknown'); ?></title>
    <link href="/assets/css/tailwind.min.css" rel="stylesheet">
    <style>
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
        }

        body {
            font-family: 'Vazir', sans-serif;
        }

        .no-date {
            color: #e53e3e;
        }

        .panel-svg {
            background-color: rgba(255, 252, 252, 0.97);
            position: relative;
        }

        .panel-svg-container {
            overflow: scroll;
            max-height: 600px;
        }

        .zoom-controls {
            position: absolute;
            top: 10px;
            left: 10px;
            display: flex;
            gap: 5px;
        }

        .zoom-btn {
            background: #fff;
            border: 1px solid #ccc;
            padding: 5px;
            cursor: pointer;
        }

        .zoom-btn:hover {
            background: #f0f0f0;
        }

        .zoom-level {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #fff;
            padding: 5px;
            border: 1px solid #ccc;
        }

        /* Styles for Plan Check Section */
        #planCheckSection {
            border-top: 1px solid #e5e7eb;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
        }

        .status-ok {
            color: #10b981;
        }

        /* green-600 */
        .status-pending {
            color: #f59e0b;
        }

        /* amber-500 */
        #planCheckMessage {
            font-size: 0.875rem;
            /* text-sm */
        }

        .checker-info {
            font-size: 0.875rem;
            color: #6b7280;
            margin-right: 5px;
        }

        /* gray-500 */

        /* Button Styles (align with your theme) */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.375rem;
            /* rounded-md */
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            gap: 0.5rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-success {
            background-color: #10b981;
            color: white;
        }

        /* green-500 */
        .btn-success:hover {
            background-color: #059669;
        }

        /* green-600 */
        .btn-warning {
            background-color: #f59e0b;
            color: white;
        }

        /* amber-500 */
        .btn-warning:hover {
            background-color: #d97706;
        }

        /* amber-600 */
        .message-success {
            color: #059669;
        }

        .message-error {
            color: #dc2626;
        }

        /* red-600 */
        /* Add your styles here */
        .status-passed {
            color: #10b981;
        }

        /* Green for passed */
        .status-rejected {
            color: #dc2626;
        }

        /* Red for rejected */
    </style>
</head>

<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold">جزئیات پنل <?php echo htmlspecialchars($panel['address'] ?? 'Unknown'); ?></h1>
            <a href="admin_panel_search.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">بازگشت</a>
        </div>

        <?php if ($panel): ?>
            <div class="bg-white rounded-lg shadow-md p-6">
                <!-- Basic Information -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div>
                        <h2 class="text-xl font-bold mb-4">اطلاعات اصلی</h2>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="font-semibold text-gray-600">آدرس:</p>
                                <p><?php echo htmlspecialchars($panel['address']); ?></p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">نوع:</p>
                                <p><?php echo htmlspecialchars($panel['type'] ?? 'نامشخص'); ?></p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">اولویت/زون:</p>
                                <p><?php echo htmlspecialchars($panel['Proritization']); ?></p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">وضعیت:</p>
                                <p class="capitalize">
                                    <?php
                                    switch ($panel['status']) {
                                        case 'pending':
                                            echo 'در انتظار';
                                            break;
                                        case 'polystyrene':
                                            echo 'قالب فوم';
                                            break; // Added polystyrene
                                        case 'Mesh':
                                            echo 'مش بندی';
                                            break;
                                        case 'Concreting':
                                            echo 'قالب‌بندی/بتن ریزی';
                                            break;
                                        case 'Assembly':
                                            echo 'فیس کوت';
                                            break; // Changed Mesh to Assembly
                                        case 'completed':
                                            echo 'تکمیل شده';
                                            break;
                                        case 'Cancelled':
                                            echo 'لغو شده';
                                            break;
                                        default:
                                            echo htmlspecialchars($panel['status']);
                                    }
                                    ?>
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">تاریخ شروع:</p>
                                <p class="<?php echo empty($panel['assigned_date']) ? 'no-date' : ''; ?>">
                                    <?php echo empty($panel['assigned_date']) ? 'مشخص نشده' : gregorianToShamsi($panel['assigned_date']); ?>
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">وضعیت ارسال:</p>
                                <p class="<?php echo empty($panel['packing_status']) ? 'text-gray-400 italic' : ''; ?>">
                                    <?php
                                    if (empty($panel['packing_status'])) {
                                        echo 'مشخص نشده';
                                    } else {
                                        switch ($panel['packing_status']) {
                                            case 'pending':
                                                echo 'در انتظار';
                                                break;
                                            case 'assigned':
                                                echo 'تخصیص داده شده';
                                                break;
                                            case 'shipped':
                                                echo 'ارسال شده';
                                                break;
                                            default:
                                                echo htmlspecialchars($panel['packing_status']);
                                        }
                                    }
                                    ?>
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">مساحت کل:</p>
                                <p><?php echo htmlspecialchars($panel['area']); ?> متر مربع</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">حجم:</p>
                                <p><?php echo number_format($panel['area'] * 0.03, 3); ?> متر مکعب</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">طول:</p>
                                <p><?php echo number_format($panel['length']); ?> میلیمتر</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">عرض:</p>
                                <p><?php echo number_format($panel['width']); ?> میلیمتر</p>
                            </div>
                        </div>

                    </div>

                    <?php // Right Column 
                    ?>

                    <?php // --- Display Concrete Test Results --- 
                    ?>
                    <div> <?php // Start the right column div 
                            ?>
                        <h2 class="text-xl font-semibold mb-4 text-gray-800 border-b pb-2">نتایج تست بتن <?php echo !empty($panel['assigned_date']) ? '(تاریخ تولید: ' . gregorianToShamsi($panel['assigned_date']) . ')' : ''; ?></h2>

                        <?php if (!empty($panel['assigned_date'])): // Only show if assigned_date exists 
                        ?>
                            <?php
                            // Check if any test data was actually processed
                            $hasTestData = $processedConcreteTests['strength_1'] !== null ||
                                $processedConcreteTests['strength_7'] !== null ||
                                $processedConcreteTests['strength_28'] !== null;

                            // Helper function for status display
                            if (!function_exists('getConcreteTestStatusInfo')) { // Prevent redeclaration
                                function getConcreteTestStatusInfo($isRejected)
                                {
                                    if ($isRejected === 1 || $isRejected === '1') { // Explicitly check for rejected value
                                        return ['class' => 'text-red-600 font-semibold', 'text' => '✗ مردود'];
                                    } elseif ($isRejected === 0 || $isRejected === '0') { // Explicitly check for passed
                                        return ['class' => 'text-green-600 font-semibold', 'text' => '✓ قبول'];
                                    } else { // Handle null or other unexpected values
                                        return ['class' => 'text-gray-500 italic', 'text' => 'نامشخص'];
                                    }
                                }
                            }

                            // Define the tests to display using the processed data keys
                            $testsToShow = [
                                ['day' => 1, 'label' => '۱ روزه', 'strength_key' => 'strength_1', 'reject_key' => 'rejected_1', 'code_key' => 'code_1'],
                                ['day' => 7, 'label' => '۷ روزه', 'strength_key' => 'strength_7', 'reject_key' => 'rejected_7', 'code_key' => 'code_7'],
                                ['day' => 28, 'label' => '۲۸ روزه', 'strength_key' => 'strength_28', 'reject_key' => 'rejected_28', 'code_key' => 'code_28'],
                            ];

                            $notRecordedText = '<span class="text-gray-400 italic text-xs">ثبت نشده</span>';
                            ?>

                            <?php if ($hasTestData || !empty($concreteTestRows)): // Show table if *any* data fetched or processed 
                            ?>
                                <div class="shadow-md rounded-lg overflow-hidden border border-gray-200">
                                    <table class="table-auto w-full text-sm">
                                        <thead class="bg-gray-100 text-gray-600 uppercase text-xs leading-normal">
                                            <tr>
                                                <th class="py-2 px-3 text-center border-b border-gray-300">سن تست</th>
                                                <th class="py-2 px-3 text-center border-b border-gray-300">مقاومت (MPa)</th>
                                                <th class="py-2 px-3 text-center border-b border-gray-300">کد نمونه</th>
                                                <th class="py-2 px-3 text-center border-b border-gray-300">وضعیت</th>
                                            </tr>
                                        </thead>
                                        <tbody class="text-gray-700">
                                            <?php foreach ($testsToShow as $testInfo): ?>
                                                <?php
                                                // Get data from the processed array
                                                $strengthValue = $processedConcreteTests[$testInfo['strength_key']] ?? null;
                                                $isRejected = $processedConcreteTests[$testInfo['reject_key']]; // Should be 0, 1, or null
                                                $sampleCode = $processedConcreteTests[$testInfo['code_key']] ?? null;

                                                // Initialize display variables
                                                $strengthDisplay = $notRecordedText;
                                                $statusDisplay = $notRecordedText; // Default status to "Not Recorded"
                                                $codeDisplay = $notRecordedText;
                                                $strengthDisplayClass = 'text-gray-500'; // Default class

                                                if ($strengthValue !== null) {
                                                    // --- If strength value EXISTS, THEN determine status and apply class ---
                                                    $strengthDisplay = htmlspecialchars(number_format((float)$strengthValue, 1));

                                                    $statusInfo = getConcreteTestStatusInfo($isRejected); // Get status based on the reject flag
                                                    $statusDisplay = '<span class="' . $statusInfo['class'] . '">' . $statusInfo['text'] . '</span>';
                                                    $strengthDisplayClass = $statusInfo['class']; // Apply status color class to strength

                                                    // Code should be displayed if available for this test age
                                                    $codeDisplay = $sampleCode ? htmlspecialchars($sampleCode) : $notRecordedText;
                                                } else {
                                                    // --- If strength value does NOT exist ---
                                                    // Status and Strength remain "Not Recorded" ($notRecordedText).
                                                    // Check if a sample code was recorded for this age even without strength.
                                                    $codeDisplay = $sampleCode ? htmlspecialchars($sampleCode) : $notRecordedText;
                                                }
                                                ?>
                                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                                    <td class="py-2 px-3 text-center border-r border-gray-200">
                                                        <?php echo $testInfo['label']; ?>
                                                    </td>
                                                    <td class="py-2 px-3 text-center border-r border-gray-200 <?php echo $strengthDisplayClass; // Apply class here 
                                                                                                                ?>">
                                                        <?php echo $strengthDisplay; ?>
                                                    </td>
                                                    <td class="py-2 px-3 text-center border-r border-gray-200">
                                                        <?php echo $codeDisplay; ?>
                                                    </td>
                                                    <td class="py-2 px-3 text-center">
                                                        <?php echo $statusDisplay; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: // assigned_date exists but no records found at all 
                            ?>
                                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-3 text-sm" role="alert">
                                    <p>هیچ نتیجه تستی برای تاریخ تولید این پنل (<?php echo gregorianToShamsi($panel['assigned_date']); ?>) در سیستم ثبت نشده است.</p>
                                </div>
                            <?php endif; // End $hasTestData check 
                            ?>

                        <?php else: // Panel assigned_date is empty 
                        ?>
                            <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-3 text-sm" role="alert">
                                <p>تاریخ شروع تولید برای این پنل ثبت نشده است. نتایج تست بتن پس از ثبت تاریخ تولید و انجام تست‌ها نمایش داده می‌شود.</p>
                            </div>
                        <?php endif; // End !empty($panel['assigned_date']) check 
                        ?>
                    </div> <?php // End the right column div 
                            ?>
                    <?php // --- End Display Concrete Test Results --- 
                    ?>

                    <!-- Formwork Details -->
                    <?php if (!empty($panel['formwork_type'])): ?>
                        <div>
                            <h2 class="text-xl font-bold mb-4">مشخصات قالب تخصیص یافته</h2>
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <p class="font-semibold text-gray-600">نوع قالب:</p>
                                    <p><?php echo htmlspecialchars($panel['formwork_type']); ?></p>
                                </div>
                                <!-- Add more formwork details here if needed from $panel variable -->
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div><!-- End Basic Information Grid -->

            <!-- Plan Check Section -->
            <?php if ($currentUserRole === 'planner'): // SECTION VISIBLE ONLY FOR PLANNER 
            ?>
                <div id="planCheckSection" data-panel-id="<?php echo $panelId; ?>" class="border-t pt-6 mt-6">
                    <h2 class="text-xl font-bold mb-4">بررسی و تایید نقشه و اطلاعات صفحه</h2>
                    <div class="flex items-center gap-4">
                        <p>وضعیت فعلی:
                            <span id="planStatusText" class="font-semibold <?php echo $panel['plan_checked'] ? 'status-ok' : 'status-pending'; ?>">
                                <?php echo $panel['plan_checked'] ? 'تایید شده' : 'در انتظار بررسی'; ?>
                            </span>
                            <?php if ($checkerFullName): ?>
                                <span class="checker-info">(توسط: <?php echo $checkerFullName; ?>)</span>
                            <?php endif; ?>
                        </p>

                        <?php // Show APPROVE button only if NOT checked AND user is planner 
                        ?>
                        <?php if (!$panel['plan_checked']): ?>
                            <button id="markPlanOkBtn" class="btn btn-success text-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                                تایید نقشه
                            </button>
                        <?php endif; ?>

                        <?php // Show REVERT button only if CHECKED, assigned_date is EMPTY, AND user is planner 
                        ?>
                        <?php if ($panel['plan_checked'] && empty($panel['assigned_date'])): ?>
                            <button id="markPlanPendingBtn" class="btn btn-warning text-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122" />
                                </svg> <?php // Example undo icon 
                                        ?>
                                لغو تایید
                            </button>
                        <?php endif; ?>

                        <span id="planCheckMessage" class="ml-4 text-sm"></span>
                    </div>
                </div>
            <?php elseif ($currentUserRole === 'admin' || $currentUserRole === 'supervisor'): // Optional: Show status for Admin/Supervisor 
            ?>
                <div id="planCheckSection" class="border-t pt-6 mt-6">
                    <h2 class="text-xl font-bold mb-4">وضعیت بررسی نقشه</h2>
                    <p>وضعیت فعلی:
                        <span id="planStatusText" class="font-semibold <?php echo $panel['plan_checked'] ? 'status-ok' : 'status-pending'; ?>">
                            <?php echo $panel['plan_checked'] ? 'تایید شده' : 'در انتظار بررسی'; ?>
                        </span>
                        <?php if ($checkerFullName): ?>
                            <span class="checker-info">(توسط: <?php echo $checkerFullName; ?>)</span>
                        <?php endif; ?>
                    </p>
                    <?php if ($panel['plan_checked'] && !empty($panel['assigned_date'])): ?>
                        <p class="text-sm text-gray-600 mt-1">(امکان لغو تایید وجود ندارد زیرا تاریخ شروع تولید ثبت شده است.)</p>
                    <?php endif; ?>
                </div>
            <?php endif; // End role checks for plan section visibility 
            ?>

            <!-- SVG Display and Actions -->
            <div class="mt-8 border-t pt-6">

                <?php if (!empty($panel['svg_url'])): // Check if SVG URL exists 
                ?>

                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold">نقشه پنل (SVG)</h2>
                        <div>
                            <!-- Print Button -->
                            <button id="printSvgBtn" class="btn btn-secondary bg-gray-500 hover:bg-gray-600 text-white text-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                </svg>
                                چاپ نقشه
                            </button>

                            <!-- Upload/Change SVG Form (Conditional) -->
                            <?php if ($currentUserRole === 'planner' && empty($panel['assigned_date'])): ?>
                                <form id="svgUploadForm" method="POST" action="panel_detail.php?id=<?php echo $panelId; ?>" enctype="multipart/form-data" class="inline-block ml-4">
                                    <input type="hidden" name="action" value="uploadNewSvg">
                                    <input type="hidden" name="panelId" value="<?php echo $panelId; ?>">
                                    <label for="newSvgFile" class="btn btn-secondary bg-blue-500 hover:bg-blue-600 text-white text-sm cursor-pointer">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                        </svg>
                                        تغییر نقشه (SVG)
                                    </label> <?php // Fixed missing closing tag 
                                                ?>
                                    <input type="file" name="newSvgFile" id="newSvgFile" accept="image/svg+xml" class="hidden" onchange="document.getElementById('svgUploadForm').submit();">
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Success/Error Message for SVG Upload -->
                    <?php if (isset($_GET['svg_updated']) && $_GET['svg_updated'] == 1): ?>
                        <div class="mb-4 p-3 bg-green-100 border border-green-400 text-green-700 rounded text-sm">نقشه SVG با موفقیت به‌روزرسانی شد.</div>
                    <?php endif; ?>
                    <!-- Add specific error messages from POST handler if needed -->


                    <div class="panel-svg mb-8 relative" id="svgContainer">
                        <div class="panel-svg-container border rounded bg-white shadow-inner" id="svgWrapper">
                            <!-- Added cache buster to src -->
                            <img src="<?php echo htmlspecialchars($panel['svg_url']); ?>?v=<?php echo time(); ?>"
                                alt="Panel SVG"
                                id="svgImage"
                                onerror="console.error('Failed to load SVG:', this.src); this.parentElement.innerHTML = '<p class=\'p-4 text-red-600\'>خطا در بارگذاری تصویر SVG.</p>';" />
                        </div>
                        <div class="zoom-controls">
                            <button class="zoom-btn" id="zoomIn" title="Zoom In"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4" />
                                </svg> </button>
                            <button class="zoom-btn" id="zoomOut" title="Zoom Out"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M4 8a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7A.5.5 0 0 1 4 8" />
                                </svg> </button>
                            <button class="zoom-btn" id="resetZoom" title="Reset Zoom"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z" />
                                    <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466" />
                                </svg> </button>
                        </div>
                        <div class="zoom-level text-xs" id="zoomLevel">100%</div>
                    </div>

                <?php else: // SVG URL is empty 
                ?>

                    <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded">
                        هنوز نقشه‌ای برای این پنل بارگذاری نشده است.
                        <!-- Upload Form (Show here if no SVG exists, for Planner, if date is null) -->
                        <?php if ($currentUserRole === 'planner' && empty($panel['assigned_date'])): ?>
                            <form id="svgUploadFormNoSvg" method="POST" action="panel_detail.php?id=<?php echo $panelId; ?>" enctype="multipart/form-data" class="inline-block ml-4 mt-2">
                                <input type="hidden" name="action" value="uploadNewSvg">
                                <input type="hidden" name="panelId" value="<?php echo $panelId; ?>">
                                <label for="newSvgFileNoSvg" class="btn btn-secondary bg-blue-500 hover:bg-blue-600 text-white text-sm cursor-pointer">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                    </svg>
                                    بارگذاری نقشه (SVG)
                                </label>
                                <input type="file" name="newSvgFile" id="newSvgFileNoSvg" accept="image/svg+xml" class="hidden" onchange="document.getElementById('svgUploadFormNoSvg').submit();">
                            </form>
                        <?php endif; ?>
                    </div>

                <?php endif; // End of the SVG URL check 
                ?>

            </div> <!-- End SVG Display and Actions Section -->

    </div> <?php // Close the main panel content div 
            ?>
<?php else: ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
        پنلی پیدا نشد!
    </div>
<?php endif; ?>
</div>


<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- SVG Zoom/Pan Script ---
        const svgContainer = document.getElementById('svgContainer');
        if (svgContainer) { // Only run if SVG container exists
            const wrapper = document.getElementById('svgWrapper');
            const img = document.getElementById('svgImage');
            const zoomInBtn = document.getElementById('zoomIn');
            const zoomOutBtn = document.getElementById('zoomOut');
            const resetZoomBtn = document.getElementById('resetZoom');
            const zoomLevelDisplay = document.getElementById('zoomLevel');

            if (!img) return; // Exit if SVG image isn’t found

            let scale = 1;
            let panning = false;
            let pointX = 0;
            let pointY = 0;
            let start = {
                x: 0,
                y: 0
            };
            let initialDistance = 0;
            let initialScale = 1;

            function updateZoomLevel() {
                /* ... same as before ... */
                zoomLevelDisplay.style.display = 'block';
                zoomLevelDisplay.textContent = `${Math.round(scale * 100)}%`;
                setTimeout(() => {
                    zoomLevelDisplay.style.display = 'none';
                }, 1500);
            }

            function setTransform() {
                /* ... same as before ... */
                img.style.transformOrigin = '0 0'; // Set origin for consistent scaling
                img.style.transform = `translate(${pointX}px, ${pointY}px) scale(${scale})`;
            }

            function resetTransform() {
                /* ... same as before ... */
                scale = 1;
                pointX = 0;
                pointY = 0;
                setTransform();
                updateZoomLevel();
                // container.classList.remove('zoomed'); // Class may not be needed
            }

            // --- Event Listeners for Zoom/Pan ---
            if (wrapper && img && zoomInBtn && zoomOutBtn && resetZoomBtn && zoomLevelDisplay) {
                wrapper.addEventListener('wheel', (e) => {
                    /* ... same as before ... */
                    e.preventDefault();
                    const rect = wrapper.getBoundingClientRect();
                    const xs = (e.clientX - rect.left - pointX) / scale; // Adjust for wrapper offset
                    const ys = (e.clientY - rect.top - pointY) / scale; // Adjust for wrapper offset
                    const delta = e.deltaY > 0 ? 0.8 : 1.25; // Consistent zoom step
                    const oldScale = scale;
                    scale = Math.min(Math.max(0.2, scale * delta), 10); // Adjust limits if needed
                    // Adjust pointX/Y based on cursor position relative to the image origin
                    pointX += xs * oldScale - xs * scale;
                    pointY += ys * oldScale - ys * scale;
                    setTransform();
                    updateZoomLevel();
                });
                // Add other listeners: touchstart, touchmove (for pinch zoom) - KEEP THESE if needed
                wrapper.addEventListener('touchstart', (e) => {
                    /* ... same as before ... */
                    if (e.touches.length === 2) {
                        /* Pinch zoom init */
                    } else if (e.touches.length === 1) {
                        /* Pan init */
                        panning = true; // Use panning flag for touch too
                        start = {
                            x: e.touches[0].clientX - pointX,
                            y: e.touches[0].clientY - pointY
                        };
                    }
                });
                wrapper.addEventListener('touchmove', (e) => {
                    /* ... same as before ... */
                    if (e.touches.length === 2) {
                        /* Pinch zoom move */
                    } else if (e.touches.length === 1 && panning) {
                        /* Pan move */
                        e.preventDefault(); // Prevent page scroll while panning image
                        pointX = e.touches[0].clientX - start.x;
                        pointY = e.touches[0].clientY - start.y;
                        setTransform();
                    }
                });
                wrapper.addEventListener('touchend', (e) => {
                    if (e.touches.length < 2) {
                        panning = false;
                    } // Reset panning on touchend
                });

                wrapper.addEventListener('mousedown', (e) => {
                    /* ... same as before ... */
                    e.preventDefault();
                    panning = true;
                    start = {
                        x: e.clientX - pointX,
                        y: e.clientY - pointY
                    };
                    wrapper.style.cursor = 'grabbing'; // Change cursor
                });
                wrapper.addEventListener('mousemove', (e) => {
                    /* ... same as before ... */
                    if (panning) {
                        e.preventDefault();
                        pointX = e.clientX - start.x;
                        pointY = e.clientY - start.y;
                        setTransform();
                    }
                });
                wrapper.addEventListener('mouseup', () => {
                    panning = false;
                    wrapper.style.cursor = 'grab';
                });
                wrapper.addEventListener('mouseleave', () => {
                    panning = false;
                    wrapper.style.cursor = 'grab';
                }); // Reset cursor on leave

                zoomInBtn.addEventListener('click', () => {
                    /* ... same as before ... */
                    const rect = wrapper.getBoundingClientRect();
                    const centerXs = (rect.width / 2 - pointX) / scale; // Zoom towards center
                    const centerYs = (rect.height / 2 - pointY) / scale;
                    const oldScale = scale;
                    scale = Math.min(scale * 1.25, 10);
                    pointX += centerXs * oldScale - centerXs * scale;
                    pointY += centerYs * oldScale - centerYs * scale;
                    setTransform();
                    updateZoomLevel();
                });
                zoomOutBtn.addEventListener('click', () => {
                    /* ... same as before ... */
                    const rect = wrapper.getBoundingClientRect();
                    const centerXs = (rect.width / 2 - pointX) / scale;
                    const centerYs = (rect.height / 2 - pointY) / scale;
                    const oldScale = scale;
                    scale = Math.max(scale * 0.8, 0.2);
                    pointX += centerXs * oldScale - centerXs * scale;
                    pointY += centerYs * oldScale - centerYs * scale;
                    setTransform();
                    updateZoomLevel();
                });
                resetZoomBtn.addEventListener('click', resetTransform);
                wrapper.style.cursor = 'grab'; // Initial cursor
            }
        } // End if(svgContainer)

        // --- Plan Check Script ---
        const markOkBtn = document.getElementById('markPlanOkBtn');
        const statusTextEl = document.getElementById('planStatusText');
        const messageSpan = document.getElementById('planCheckMessage');
        const planCheckSection = document.getElementById('planCheckSection');
        const checkerInfoSpan = planCheckSection ? planCheckSection.querySelector('.checker-info') : null; // Get the checker info span
        const markPendingBtn = document.getElementById('markPlanPendingBtn');
        if (markOkBtn && planCheckSection && statusTextEl && messageSpan) {
            markOkBtn.addEventListener('click', function() {
                const panelId = planCheckSection.getAttribute('data-panel-id');
                messageSpan.textContent = 'در حال پردازش...';
                messageSpan.className = 'ml-4 text-sm text-gray-500'; // Neutral color
                markOkBtn.disabled = true;
                // Using a simple text indicator for loading
                markOkBtn.innerHTML = '<span class="animate-spin inline-block w-4 h-4 border-2 border-current border-t-transparent rounded-full" role="status" aria-label="loading"></span> صبر کنید...';

                fetch('', { // POST to the same page (panel_detail.php)
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest' // Good practice for AJAX
                        },
                        body: new URLSearchParams({
                            action: 'markPlanChecked', // Correct action
                            panelId: panelId
                        })
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            messageSpan.textContent = data.message;
                            messageSpan.className = 'ml-4 message-success text-sm'; // Use success class
                            statusTextEl.textContent = 'تایید شده';
                            statusTextEl.className = 'font-semibold status-ok'; // Use OK class
                            markOkBtn.style.display = 'none'; // Hide button after success

                            // Add/Update checker info if provided
                            if (data.checkerFullName && checkerInfoSpan) {
                                checkerInfoSpan.textContent = `(توسط: ${data.checkerFullName})`;
                                checkerInfoSpan.style.display = 'inline'; // Ensure it's visible
                            } else if (checkerInfoSpan) {
                                checkerInfoSpan.style.display = 'none'; // Hide if no username returned
                            }

                            // Optionally show the revert button if applicable (or rely on page reload)
                            // Note: Checking panel.assigned_date here requires passing it from PHP or another fetch
                            // Easiest might be to just let the page reload or handle revert button visibility purely in PHP on next load.
                            // If markPendingBtn exists (was rendered by PHP), potentially show it now
                            // if (markPendingBtn) {
                            //    markPendingBtn.style.display = 'inline-flex';
                            // }


                        } else {
                            messageSpan.textContent = 'خطا: ' + (data.message || 'Unknown error');
                            messageSpan.className = 'ml-4 message-error text-sm'; // Use error class
                            markOkBtn.disabled = false; // Re-enable on error
                            // Reset button text/icon (Replace with your actual icon SVG if needed)
                            markOkBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"> <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /> </svg> تایید نقشه';
                        }
                    })
                    .catch(error => {
                        console.error('Error marking plan checked:', error);
                        messageSpan.textContent = 'خطا در ارتباط با سرور.';
                        messageSpan.className = 'ml-4 message-error text-sm';
                        markOkBtn.disabled = false;
                        markOkBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"> <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /> </svg> تایید نقشه';
                    });
            });
        }
        // ****** END OF ADDED LISTENER ******
        if (markPendingBtn && planCheckSection && statusTextEl && messageSpan) {
            markPendingBtn.addEventListener('click', function() {
                const panelId = planCheckSection.getAttribute('data-panel-id');
                if (!confirm('آیا مطمئن هستید که می‌خواهید تایید نقشه را لغو کنید؟')) {
                    return;
                }

                messageSpan.textContent = 'در حال پردازش...';
                messageSpan.className = 'ml-4 text-sm text-gray-500';
                markPendingBtn.disabled = true;
                markPendingBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> صبر کنید...';

                fetch('', { // POST to the same page
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: new URLSearchParams({
                            action: 'markPlanPending', // Send the correct action
                            panelId: panelId
                        })
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            messageSpan.textContent = data.message;
                            messageSpan.className = 'ml-4 message-success';
                            statusTextEl.textContent = 'در انتظار بررسی'; // Update status text
                            statusTextEl.className = 'font-semibold status-pending'; // Use pending class
                            markPendingBtn.style.display = 'none'; // Hide revert button

                            // Clear checker info
                            if (checkerInfoSpan) {
                                checkerInfoSpan.textContent = '';
                                checkerInfoSpan.style.display = 'none';
                            }

                            // Show the 'Approve' button again (Find it first)
                            const approveBtn = document.getElementById('markPlanOkBtn');
                            if (approveBtn) {
                                approveBtn.style.display = 'inline-flex'; // Show approve button
                                approveBtn.disabled = false; // Ensure it's enabled
                                approveBtn.innerHTML = '<i class="fas fa-check-circle mr-2"></i> تایید نقشه'; // Reset text
                            } else {
                                // If approve button wasn't initially rendered (e.g., page loaded when already approved)
                                // You might need to dynamically create and add it here, or just rely on a page refresh
                                // For simplicity, let's assume it exists or refresh is acceptable after revert.
                                console.warn("Approve button not found after revert.");
                            }

                        } else {
                            messageSpan.textContent = 'خطا: ' + (data.message || 'Unknown error');
                            messageSpan.className = 'ml-4 message-error';
                            markPendingBtn.disabled = false; // Re-enable on error
                            markPendingBtn.innerHTML = '<i class="fas fa-undo mr-2"></i> لغو تایید'; // Reset button text
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        messageSpan.textContent = 'خطا در ارتباط با سرور.';
                        messageSpan.className = 'ml-4 message-error';
                        markPendingBtn.disabled = false;
                        markPendingBtn.innerHTML = '<i class="fas fa-undo mr-2"></i> لغو تایید';
                    });
            });
        }
        // --- Print SVG Script ---
        const printBtn = document.getElementById('printSvgBtn');
        const svgImageToPrint = document.getElementById('svgImage'); // Get the img element

        if (printBtn && svgImageToPrint) {
            printBtn.addEventListener('click', function() {
                const svgUrl = svgImageToPrint.src; // Get the URL from the img tag
                if (!svgUrl) {
                    alert('منبع نقشه SVG یافت نشد.');
                    return;
                }

                // Option 1: Print SVG directly if browser supports it well
                // Create a new window/iframe, load SVG, and print
                // const printWindow = window.open('', '_blank');
                // printWindow.document.write(`
                //     <html>
                //     <head><title>Print SVG</title>
                //     <style>
                //         @media print {
                //             body { margin: 0; }
                //             img { max-width: 100%; height: auto; display: block; }
                //         }
                //     </style>
                //     </head>
                //     <body>
                //         <img src="${svgUrl}" onload="window.print(); setTimeout(window.close, 100);" onerror="alert('Failed to load SVG for printing.'); window.close();">
                //     </body>
                //     </html>
                // `);
                // printWindow.document.close(); // Important for some browsers

                // Option 2: Fetch SVG content and print (More reliable for complex SVGs/styling)
                fetch(svgUrl)
                    .then(response => {
                        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                        return response.text();
                    })
                    .then(svgContent => {
                        const printWindow = window.open('', '_blank');
                        printWindow.document.write(`
                        <html>
                        <head>
                          <title>چاپ نقشه - <?php echo htmlspecialchars($panel['address'] ?? ''); ?></title>
                          <style>
                             @media print {
                                @page { size: A3 landscape; margin: 10mm; } /* Example: A3 Landscape */
                                body { margin: 0; font-family: 'Vazir', sans-serif; } /* Use your font */
                                svg { max-width: 100%; max-height: 95vh; display: block; margin: auto; } /* Center and scale */
                             }
                             body { font-family: 'Vazir', sans-serif; }
                             svg { max-width: 100%; height: auto; display: block; border: 1px solid #eee;} /* Show border for preview */
                           </style>
                        </head>
                        <body>
                           <h1>نقشه پنل: <?php echo htmlspecialchars($panel['address'] ?? ''); ?></h1>
                           <hr>
                           ${svgContent}
                           <script>
                              // Use timeout to ensure SVG renders before printing
                              setTimeout(() => {
                                 window.print();
                                 setTimeout(window.close, 200); // Close after printing dialog
                              }, 500); // Adjust delay if needed
                           <\/script>
                        </body>
                        </html>`);
                        printWindow.document.close();
                    })
                    .catch(error => {
                        console.error('Error fetching/printing SVG:', error);
                        alert('خطا در بارگیری یا چاپ نقشه SVG.');
                    });
            });
        }
    }); // End DOMContentLoaded
</script>
</body>

</html>