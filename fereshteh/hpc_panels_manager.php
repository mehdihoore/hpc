<?php
// hpc_panels_manager.php
// --- Basic Setup, Dependencies, Session, Auth, DB Connection ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once 'includes/jdf.php';
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
// --- Authentication ---
$allroles = ['admin', 'supervisor', 'planner', 'cnc_operator', 'superuser', 'user']; // Adjust as needed
// Define roles that can VIEW this page
$viewRoles = ['admin', 'supervisor', 'planner', 'user', 'superuser']; // Example: Allow users to view
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $viewRoles)) {
    // Redirect to login or an access denied page
    header('Location: login.php');
    exit('Access Denied.');
}
$current_user_id = $_SESSION['user_id'];
// --- End Authorization ---
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/hpc_panels_manager.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}


// --- Helper Functions ---
if (!function_exists('toLatinDigitsPhp')) {
    function toLatinDigitsPhp($num)
    {
        if ($num === null || !is_scalar($num)) return '';
        return str_replace(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], range(0, 9), strval($num));
    }
}
if (!function_exists('gregorianToShamsi')) {
    function gregorianToShamsi($date)
    {
        if (empty($date) || $date == '0000-00-00' || $date == null) return '';
        try {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $date)) {
                if ($date instanceof DateTime) $date = $date->format('Y-m-d');
                else {
                    return '';
                }
            }
            $date_part = explode(' ', $date)[0];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_part)) {
                return '';
            }
            list($year, $month, $day) = explode('-', $date_part);
            $year = (int)$year;
            $month = (int)$month;
            $day = (int)$day;
            if ($year <= 0 || $month <= 0 || $month > 12 || $day <= 0 || $day > 31 || !checkdate($month, $day, $year)) {
                return '';
            }
            $gDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
            $gy = $year - 1600;
            $gm = $month - 1;
            $gd = $day - 1;
            $gDayNo = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
            $gDayNo += $gDays[$gm];
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
            $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
            if (in_array($jy % 33, [1, 5, 9, 13, 17, 22, 26, 30])) $jDaysInMonth[11] = 30;
            for ($i = 0; $i < 12; $i++) {
                if ($jDayNo < $jDaysInMonth[$i]) break;
                $jDayNo -= $jDaysInMonth[$i];
            }
            $jm = $i + 1;
            $jd = $jDayNo + 1;
            if ($jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
                return '';
            }
            return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        } catch (Exception $e) {
            error_log("Error gregorianToShamsi date '$date': " . $e->getMessage());
            return '';
        }
    }
}

// --- Mappings for Enum/Boolean values ---
$status_map_persian = [
    'pending' => 'در انتظار',
    'polystyrene' => 'قالب فوم', // Corrected
    'Mesh' => 'مش بندی',          // Corrected
    'Concreting' => 'قالب‌بندی/بتن ریزی', // Corrected
    'Assembly' => 'فیس کوت',       // Corrected
    'completed' => 'تکمیل شده',
    // 'Formwork' was in your DB enum but not in your list, add if needed:
    // 'Formwork' => 'قالب بندی',
];
$packing_status_map_persian = [
    'pending' => 'در انتظار',
    'assigned' => 'تخصیص یافته',
    'shipped' => 'ارسال شده'
];
$polystyrene_map_persian = [
    0 => 'انجام نشده',
    1 => 'انجام شده'
];

// --- Define Available Columns for Display/Selection ---
$available_columns = [
    'address' => 'آدرس',
    'type' => 'نوع',
    'area' => 'مساحت (m²)',
    'width' => 'عرض (mm)',   // Updated Unit
    'length' => 'طول (mm)',  // Updated Unit
    'formwork_type' => 'نوع قالب', // Added
    'Proritization' => 'اولویت',
    'status' => 'وضعیت',
    'assigned_date' => 'تاریخ تخصیص',
    'polystyrene' => 'پلی استایرن',
    'mesh_end_time' => 'پایان مش',
    'concrete_end_time' => 'پایان بتن',
    'assembly_end_time' => 'پایان فیس کوت',
    'packing_status' => 'وضعیت بسته بندی',
];
$distinct_types = [];
$distinct_priorities = [];
try {
    // Fetch distinct 'type' values
    $stmt_types = $pdo->query("SELECT DISTINCT type FROM hpc_panels WHERE type IS NOT NULL AND type != '' ORDER BY type ASC");
    $distinct_types = $stmt_types->fetchAll(PDO::FETCH_COLUMN, 0);

    // Fetch distinct 'Proritization' values (Example if you wanted dynamic priorities)
    $stmt_priorities = $pdo->query("SELECT DISTINCT Proritization FROM hpc_panels WHERE Proritization IS NOT NULL AND Proritization != '' ORDER BY Proritization ASC");
    $distinct_priorities = $stmt_priorities->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (PDOException $e) {
    error_log("Error fetching distinct filter values: " . $e->getMessage());
    // Don't halt execution, dropdowns might just be less populated
}

// Define fixed priorities
$defined_priorities = [];
for ($i = 1; $i <= 15; $i++) {
    $defined_priorities[] = 'P' . $i;
}
// --- Handle Column Selection ---
$default_columns = ['address', 'type', 'area', 'width', 'length', 'formwork_type', 'status', 'assigned_date', 'packing_status']; // Sensible defaults
$user_columns = getUserPreferences($current_user_id, 'hpc_panels_manager', 'columns', implode(',', $default_columns));
// Parse the columns from saved preferences if present
if (!empty($user_columns)) {
    $selected_columns = explode(',', $user_columns);
} else {
    // Fall back to URL parameter or defaults
    $selected_columns_str = trim($_GET['cols'] ?? implode(',', $default_columns));
    $selected_columns = !empty($selected_columns_str) ? explode(',', $selected_columns_str) : $default_columns;
}
$selected_columns_str = trim($_GET['cols'] ?? implode(',', $default_columns));
$selected_columns = !empty($selected_columns_str) ? explode(',', $selected_columns_str) : $default_columns;
// Validate selected columns against available columns
$selected_columns = array_intersect($selected_columns, array_keys($available_columns));
if (empty($selected_columns)) $selected_columns = $default_columns; // Fallback if validation fails
// --- Process Filters (from $_GET) ---
$search_params = [];
$sql_conditions = [];

// Text Filters (Address only now)
if (!empty($_GET["filter_address"])) {
    $value = trim($_GET["filter_address"]);
    $sql_conditions[] = "`address` LIKE :address"; // Use backticks
    $search_params[":address"] = "%" . $value . "%";
}

// Number Range Filters
$range_filters = ['area', 'width', 'length'];
foreach ($range_filters as $filter_key) {
    if (!empty($_GET["filter_{$filter_key}_min"])) {
        $value = filter_var($_GET["filter_{$filter_key}_min"], FILTER_VALIDATE_FLOAT);
        if ($value !== false) {
            $sql_conditions[] = "`$filter_key` >= :{$filter_key}_min";
            $search_params[":{$filter_key}_min"] = $value;
        }
    }
    if (!empty($_GET["filter_{$filter_key}_max"])) {
        $value = filter_var($_GET["filter_{$filter_key}_max"], FILTER_VALIDATE_FLOAT);
        if ($value !== false) {
            $sql_conditions[] = "`$filter_key` <= :{$filter_key}_max";
            $search_params[":{$filter_key}_max"] = $value;
        }
    }
}

// Exact Match / Enum Filters
$exact_match_filters = ['type', 'Proritization', 'status', 'packing_status', 'polystyrene']; // Add type and Proritization
foreach ($exact_match_filters as $filter_key) {
    // Check if filter is set and not an empty string
    if (isset($_GET["filter_$filter_key"]) && $_GET["filter_$filter_key"] !== '') {
        $value = $_GET["filter_$filter_key"];
        // Basic validation for polystyrene
        if ($filter_key == 'polystyrene' && ($value === '0' || $value === '1')) {
            $sql_conditions[] = "`$filter_key` = :$filter_key";
            $search_params[":$filter_key"] = $value;
        } elseif ($filter_key != 'polystyrene') { // For type, Proritization, status, packing_status
            $sql_conditions[] = "`$filter_key` = :$filter_key";
            $search_params[":$filter_key"] = $value;
        }
    }
}


// Date Range Filters
$date_filters = ['assigned_date', 'mesh_end_time', 'concrete_end_time', 'assembly_end_time'];
foreach ($date_filters as $filter_key) {
    $date_from_j = $_GET["filter_{$filter_key}_from"] ?? '';
    $date_to_j = $_GET["filter_{$filter_key}_to"] ?? '';

    if (!empty($date_from_j)) {
        $jds = toLatinDigitsPhp(trim($date_from_j));
        $jp = explode('/', $jds);
        if (count($jp) === 3) {
            $ga = jalali_to_gregorian(intval($jp[0]), intval($jp[1]), intval($jp[2]));
            if ($ga) {
                $gs = sprintf('%04d-%02d-%02d', $ga[0], $ga[1], $ga[2]);
                // For datetime columns, compare against the date part
                $column_name = ($filter_key == 'assigned_date') ? $filter_key : "DATE(`$filter_key`)";
                if (DateTime::createFromFormat('Y-m-d', $gs)) {
                    $sql_conditions[] = "$column_name >= :{$filter_key}_from";
                    $search_params[":{$filter_key}_from"] = $gs;
                }
            }
        }
    }
    if (!empty($date_to_j)) {
        $jds = toLatinDigitsPhp(trim($date_to_j));
        $jp = explode('/', $jds);
        if (count($jp) === 3) {
            $ga = jalali_to_gregorian(intval($jp[0]), intval($jp[1]), intval($jp[2]));
            if ($ga) {
                $gs = sprintf('%04d-%02d-%02d', $ga[0], $ga[1], $ga[2]);
                $column_name = ($filter_key == 'assigned_date') ? $filter_key : "DATE(`$filter_key`)";
                if (DateTime::createFromFormat('Y-m-d', $gs)) {
                    $sql_conditions[] = "$column_name <= :{$filter_key}_to";
                    $search_params[":{$filter_key}_to"] = $gs;
                }
            }
        }
    }
}

// --- Build Final Query ---
$where_clause = "";
if (!empty($sql_conditions)) {
    $where_clause = " WHERE " . implode(" AND ", $sql_conditions);
}

// --- Fetch Main Data ---
$records = [];
try {
    // Select all columns initially, filtering/display is handled later
    $sql_main = "SELECT * FROM hpc_panels" . $where_clause . " ORDER BY Proritization DESC, assigned_date DESC, id DESC"; // Example order
    $stmt_main = $pdo->prepare($sql_main);
    $stmt_main->execute($search_params);
    $records = $stmt_main->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching HPC panels: " . $e->getMessage());
    $_SESSION['error_message'] = "خطا در بارگذاری داده‌های پنل‌ها.";
}

// --- Fetch Status Counts (Applying the SAME filters) ---
$status_counts = [];
$packing_status_counts = [];
try {
    // Status Counts
    $sql_status_count = "SELECT status, COUNT(*) as count FROM hpc_panels" . $where_clause . " GROUP BY status";
    $stmt_status_count = $pdo->prepare($sql_status_count);
    $stmt_status_count->execute($search_params);
    $status_results = $stmt_status_count->fetchAll(PDO::FETCH_KEY_PAIR); // Fetch as key=>value (status=>count)
    // Map to Persian keys for display
    foreach ($status_results as $status_en => $count) {
        $status_fa = $status_map_persian[$status_en] ?? $status_en; // Fallback to English if no map
        $status_counts[$status_fa] = $count;
    }

    // Packing Status Counts
    $sql_packing_count = "SELECT packing_status, COUNT(*) as count FROM hpc_panels" . $where_clause . " GROUP BY packing_status";
    $stmt_packing_count = $pdo->prepare($sql_packing_count);
    $stmt_packing_count->execute($search_params);
    $packing_results = $stmt_packing_count->fetchAll(PDO::FETCH_KEY_PAIR);
    // Map to Persian keys for display
    foreach ($packing_results as $pack_status_en => $count) {
        $pack_status_fa = $packing_status_map_persian[$pack_status_en] ?? $pack_status_en;
        $packing_status_counts[$pack_status_fa] = $count;
    }
} catch (PDOException $e) {
    error_log("Error fetching status counts: " . $e->getMessage());
    // Don't stop the page, just counts might be missing
}
/**
 * Get user preferences from database
 * 
 * @param int $user_id User ID
 * @param string $page Page identifier
 * @param string $preference_type Type of preference (columns, filters, etc)
 * @param mixed $default_value Default value if no preference is found
 * @return mixed User preferences or default value
 */
function getUserPreferences($user_id, $page, $preference_type = 'columns', $default_value = null)
{
    global $pdo;
    try {
        if (!isset($pdo) || !$pdo instanceof PDO) {
            throw new RuntimeException("Database connection is not initialized.");
        }
        $stmt = $pdo->prepare("SELECT preferences FROM user_preferences WHERE user_id = ? AND page = ? AND preference_type = ?");
        $stmt->execute([$user_id, $page, $preference_type]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && !empty($result['preferences'])) {
            return $result['preferences'];
        }
        return $default_value;
    } catch (PDOException $e) {
        error_log("Error getting user preferences: " . $e->getMessage());
        return $default_value;
    }
}

$pageTitle = "مدیریت پنل‌های HPC";
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/assets/images/favicon-96x96.png">
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico"> <!-- For older IE -->
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png"> <!-- For Apple devices -->
    <link rel="manifest" href="/assets/images/site.webmanifest"> <!-- PWA manifest (optional) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.rtl.min.css" />

    <style>
        /* --- General Page Styles (Keep As Is) --- */
        body {
            font-family: Vazirmatn, Tahoma, sans-serif;
            background-color: #f8f9fa;
            direction: rtl;
            font-size: 0.85rem;
        }

        .main-container {
            max-width: 95%;
            margin: 1rem auto;
            padding: 1rem;
        }

        .card {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, .075);
        }

        .card-header {
            background-color: #ffffff;
            border-bottom: 1px solid #dee2e6;
            font-weight: 600;
            font-size: 0.9rem;
            padding: 0.75rem 1.25rem;
        }

        .card-body {
            padding: 1.25rem;
        }

        .form-label {
            margin-bottom: 0.3rem;
            font-size: 0.75rem;
            color: #6c757d;
            display: block;
        }

        .form-control,
        .form-select,
        .select2-selection {
            font-size: 0.8rem;
            border-radius: 0.25rem;
            min-height: calc(1.5em + 0.75rem + 2px);
            padding: 0.375rem 0.75rem;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }

        .table th,
        .table td {
            text-align: center;
            vertical-align: middle;
            padding: 0.5rem;
            font-size: 0.8rem;
            border-color: #dee2e6;
            white-space: nowrap;
        }

        .table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .btn {
            font-size: 0.8rem;
            padding: 0.3rem 0.6rem;
        }

        .btn-sm {
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
        }

        /* Keep for general small buttons */
        .loading {
            position: fixed;
            inset: 0;
            background-color: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1060;
            display: none;
        }

        .counts-area {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 1rem;
            padding: 10px;
            background-color: #e9ecef;
            border-radius: 5px;
            font-size: 0.8rem;
        }

        .counts-area span {
            background-color: #fff;
            padding: 5px 10px;
            border-radius: 15px;
            border: 1px solid #ccc;
        }

        .counts-area strong {
            margin-left: 5px;
        }

        .select2-container--bootstrap-5 .select2-selection {
            min-height: calc(1.5em + 0.75rem + 2px);
            padding: 0.375rem 0.75rem;
            font-size: 0.8rem;
        }

        .select2-container--bootstrap-5 .select2-selection--multiple .select2-selection__choice {
            background-color: #0d6efd;
            color: white;
            border: none;
            padding: 2px 5px;
            font-size: 0.75rem;
        }

        .select2-container--bootstrap-5 .select2-selection--multiple .select2-selection__choice__remove {
            color: rgba(255, 255, 255, 0.7);
        }

        .table-responsive {
            max-height: 70vh;
        }

        .datepicker-plot-area {
            font-family: Vazirmatn, Tahoma !important;
        }

        /* --- Column Selector Styles (Button-Based) --- */

        /* Styles for the containers holding the lists */
        #selected-columns,
        #available-columns {
            /* background-color: #f8f9fa; */
            /* Already set inline/via card */
            padding: 10px;
            /* min-height/max-height/overflow set inline - Keep or move here */
            /* min-height: 200px; max-height: 300px; overflow-y: auto; */
            transition: background-color 0.2s ease;
            /* Optional */
            border: 1px solid #dee2e6;
            /* Ensure border is visible */
            border-radius: 0.25rem;
            /* Match other elements */
        }

        /* Styles for individual items within the lists */
        .column-list .column-item {
            display: flex;
            /* Already added via class in HTML */
            justify-content: space-between;
            /* Already added via class in HTML */
            align-items: center;
            /* Already added via class in HTML */
            padding: 0.25rem 0.5rem;
            /* Adjusted padding */
            margin-bottom: 3px;
            /* Spacing between items */
            background-color: #fff;
            border-bottom: 1px solid #eee;
            /* Use lighter border */
            transition: background-color 0.2s ease;
        }

        .column-list .column-item:last-child {
            border-bottom: none;
            /* Remove border for last item */
        }

        .column-list .column-item .col-label {
            flex-grow: 1;
            /* Allow label to take available space */
            margin-left: 10px;
            /* RTL: Use margin-left for space before buttons */
            font-size: 0.8rem;
            text-align: right;
            /* Ensure text aligns right */
        }

        /* Style for selected items */
        #selected-columns .column-item {
            background-color: #e9ecef;
            /* Use a slightly different background */
        }

        /* Optional: Highlight on hover */
        .column-list .column-item:hover {
            background-color: #dee2e6;
        }

        /* Adjust button padding/margins if needed */
        .column-list .btn-group-sm>.btn,
        .column-list .btn-sm {
            padding: 0.1rem 0.3rem;
            /* Make buttons smaller */
            font-size: 0.7rem;
        }

        .column-list .btn-group {
            flex-shrink: 0;
            /* Prevent button group from shrinking */
        }

        /* --- REMOVED Obsolete Drag-and-Drop Styles --- */
        /*
            .draggable-column {...}
            .draggable-column .bi-grip-vertical {...}
            .sortable-ghost {...}
            .sortable-chosen {...}
            .sortable-drag {...}
            .being-dragged {...}
            .drag-over {...}
            .min-height-50 {...} - Replaced by inline styles or specific container styles
            Styles for #selected-columns/#available-columns related to flex/gap/wrap
        */
    </style>
</head>

<body>
    <?php require_once 'header.php'; ?>
    <div class="loading" style="display: none;">
        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
    </div>

    <div class="main-container">
        <h4 class="mb-3"><?php echo htmlspecialchars($pageTitle); ?></h4>

        <!-- Messages Area -->
        <div id="messages">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                    <?php echo $_SESSION['success_message'];
                    unset($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                    <?php echo $_SESSION['error_message'];
                    unset($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filter Card -->
        <div class="card filter-card">
            <div class="card-header">
                <i class="bi bi-funnel-fill me-2"></i>فیلترها
            </div>
            <div class="card-body">
                <form method="get" id="filterForm">
                    <input type="hidden" name="cols" value="<?php echo htmlspecialchars(implode(',', $selected_columns)); ?>"> <!-- Keep selected columns -->
                    <div class="row g-2 mb-2">
                        <!-- Text Filters -->
                        <div class="col-md-2">
                            <label for="filter_address" class="form-label">آدرس</label>
                            <input type="text" class="form-control form-control-sm" id="filter_address" name="filter_address" value="<?php echo htmlspecialchars($_GET['filter_address'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="filter_type" class="form-label">نوع</label>
                            <select class="form-select form-select-sm" id="filter_type" name="filter_type">
                                <option value="">همه</option>
                                <?php foreach ($distinct_types as $type_option): ?>
                                    <option value="<?php echo htmlspecialchars($type_option); ?>" <?php echo (isset($_GET['filter_type']) && $_GET['filter_type'] === $type_option) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type_option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="filter_Proritization" class="form-label">اولویت</label>
                            <select class="form-select form-select-sm" id="filter_Proritization" name="filter_Proritization">
                                <option value="">همه</option>
                                <?php foreach ($defined_priorities as $priority_option): ?>
                                    <option value="<?php echo htmlspecialchars($priority_option); ?>" <?php echo (isset($_GET['filter_Proritization']) && $_GET['filter_Proritization'] === $priority_option) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($priority_option); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php /* Optional: Include any other distinct priorities found in DB if needed
                                             $other_db_priorities = array_diff($distinct_priorities, $defined_priorities);
                                             foreach ($other_db_priorities as $priority_option): ?>
                                                <option value="<?php echo htmlspecialchars($priority_option); ?>" <?php echo (isset($_GET['filter_Proritization']) && $_GET['filter_Proritization'] === $priority_option) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($priority_option); ?> (Other)
                                                </option>
                                             <?php endforeach; */ ?>
                            </select>
                        </div>

                        <!-- Enum/Boolean Filters -->
                        <div class="col-md-2">
                            <label for="filter_status" class="form-label">وضعیت</label>
                            <select class="form-select form-select-sm" id="filter_status" name="filter_status">
                                <option value="">همه</option>
                                <?php foreach ($status_map_persian as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] === $key) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="filter_packing_status" class="form-label">وضعیت بسته‌بندی</label>
                            <select class="form-select form-select-sm" id="filter_packing_status" name="filter_packing_status">
                                <option value="">همه</option>
                                <?php foreach ($packing_status_map_persian as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo (isset($_GET['filter_packing_status']) && $_GET['filter_packing_status'] === $key) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="filter_polystyrene" class="form-label">پلی استایرن</label>
                            <select class="form-select form-select-sm" id="filter_polystyrene" name="filter_polystyrene">
                                <option value="">همه</option>
                                <option value="0" <?php echo (isset($_GET['filter_polystyrene']) && $_GET['filter_polystyrene'] === '0') ? 'selected' : ''; ?>>انجام نشده</option>
                                <option value="1" <?php echo (isset($_GET['filter_polystyrene']) && $_GET['filter_polystyrene'] === '1') ? 'selected' : ''; ?>>انجام شده</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <!-- Number Range Filters -->
                        <div class="col-md-3">
                            <label class="form-label">مساحت</label>
                            <div class="input-group input-group-sm">
                                <input type="number" step="0.01" class="form-control" name="filter_area_min" placeholder="حداقل" value="<?php echo htmlspecialchars($_GET['filter_area_min'] ?? ''); ?>">
                                <span class="input-group-text">-</span>
                                <input type="number" step="0.01" class="form-control" name="filter_area_max" placeholder="حداکثر" value="<?php echo htmlspecialchars($_GET['filter_area_max'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">عرض</label>
                            <div class="input-group input-group-sm">
                                <input type="number" step="0.01" class="form-control" name="filter_width_min" placeholder="حداقل" value="<?php echo htmlspecialchars($_GET['filter_width_min'] ?? ''); ?>">
                                <span class="input-group-text">-</span>
                                <input type="number" step="0.01" class="form-control" name="filter_width_max" placeholder="حداکثر" value="<?php echo htmlspecialchars($_GET['filter_width_max'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">طول</label>
                            <div class="input-group input-group-sm">
                                <input type="number" step="0.01" class="form-control" name="filter_length_min" placeholder="حداقل" value="<?php echo htmlspecialchars($_GET['filter_length_min'] ?? ''); ?>">
                                <span class="input-group-text">-</span>
                                <input type="number" step="0.01" class="form-control" name="filter_length_max" placeholder="حداکثر" value="<?php echo htmlspecialchars($_GET['filter_length_max'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="row g-2">
                        <!-- Date Filters -->
                        <?php foreach ($date_filters as $df_key): ?>
                            <div class="col-md-3">
                                <label class="form-label"><?php echo htmlspecialchars($available_columns[$df_key]); ?> (از - تا)</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control datepicker" name="filter_<?php echo $df_key; ?>_from" value="<?php echo htmlspecialchars($_GET["filter_{$df_key}_from"] ?? ''); ?>" placeholder="YYYY/MM/DD">
                                    <span class="input-group-text">-</span>
                                    <input type="text" class="form-control datepicker" name="filter_<?php echo $df_key; ?>_to" value="<?php echo htmlspecialchars($_GET["filter_{$df_key}_to"] ?? ''); ?>" placeholder="YYYY/MM/DD">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>


                    <div class="d-flex justify-content-end mt-3">
                        <a href="hpc_panels_manager.php?cols=<?php echo htmlspecialchars(implode(',', $selected_columns)); ?>" class="btn btn-secondary btn-sm me-2"><i class="bi bi-x-lg"></i> پاک کردن فیلترها</a>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> اعمال فیلتر</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Column Selector -->
        <div class="card">
            <div class="card-header"><i class="bi bi-layout-three-columns me-2"></i>انتخاب و مرتب‌سازی ستون‌ها</div>
            <div class="card-body">
                <form method="get" id="columnForm">
                    <!-- Pass existing filters -->
                    <?php foreach ($_GET as $key => $value): if ($key != 'cols'): ?>
                            <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                    <?php endif;
                    endforeach; ?>

                    <div class="row">
                        <!-- Available Columns -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ستون‌های موجود:</label>
                            <div id="available-columns" class="border rounded p-2 column-list" style="min-height: 200px; max-height: 300px; overflow-y: auto;">
                                <?php
                                $unused_columns = array_diff(array_keys($available_columns), $selected_columns);
                                foreach ($unused_columns as $col_key):
                                ?>
                                    <div class="column-item d-flex justify-content-between align-items-center p-1 border-bottom" data-column="<?php echo $col_key; ?>">
                                        <span class="col-label"><?php echo htmlspecialchars($available_columns[$col_key]); ?></span>
                                        <button type="button" class="btn btn-sm btn-outline-success py-0 px-1 btn-add-col" title="افزودن">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Selected Columns -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">ستون‌های انتخاب شده (به ترتیب):</label>
                            <div id="selected-columns" class="border rounded p-2 column-list" style="min-height: 200px; max-height: 300px; overflow-y: auto;">
                                <?php foreach ($selected_columns as $col_key): if (isset($available_columns[$col_key])): // Ensure column still exists 
                                ?>
                                        <div class="column-item d-flex justify-content-between align-items-center p-1 border-bottom bg-light" data-column="<?php echo $col_key; ?>">
                                            <span class="col-label"><?php echo htmlspecialchars($available_columns[$col_key]); ?></span>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 btn-move-up" title="انتقال به بالا">
                                                    <i class="bi bi-arrow-up"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 btn-move-down" title="انتقال به پایین">
                                                    <i class="bi bi-arrow-down"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 btn-remove-col" title="حذف">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </div>
                                        </div>
                                <?php endif;
                                endforeach; ?>
                            </div>
                            <input type="hidden" name="cols" id="columnsInput" value="<?php echo htmlspecialchars(implode(',', $selected_columns)); ?>">
                        </div>
                    </div>


                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div>
                            <button type="button" class="btn btn-outline-secondary btn-sm me-2" id="resetColumns">
                                <i class="bi bi-arrow-counterclockwise"></i> بازنشانی به حالت پیش‌فرض
                            </button>
                            <span id="saveIndicator" class="ms-2 small"></span>
                        </div>
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="bi bi-check-lg"></i> اعمال تغییرات (بارگذاری مجدد)
                        </button>
                    </div>
                </form>
            </div>
        </div>


        <!-- Status Counts -->
        <div class="counts-area">
            <strong>تعداد کل پنل‌ها (فیلتر شده): <?php echo count($records); ?></strong> | 
            <strong>وضعیت:</strong>
            <?php if (!empty($status_counts)): ?>
                <?php foreach ($status_counts as $status => $count): ?>
                    <span><?php echo htmlspecialchars($status); ?>: <strong><?php echo $count; ?></strong></span>
                <?php endforeach; ?>
            <?php else: ?>
                <span>-</span>
            <?php endif; ?>
             | 
            <strong>بسته‌بندی:</strong>
            <?php if (!empty($packing_status_counts)): ?>
                <?php foreach ($packing_status_counts as $status => $count): ?>
                    <span><?php echo htmlspecialchars($status); ?>: <strong><?php echo $count; ?></strong></span>
                <?php endforeach; ?>
            <?php else: ?>
                <span>-</span>
            <?php endif; ?>
        </div>

        <!-- Data Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table me-2"></i>لیست پنل‌ها</span>
                <div class="btn-group btn-group-sm">
                    <?php
                    // Generate links including current filters AND selected columns
                    $export_params = $_GET; // Start with current filters
                    $export_params['cols'] = implode(',', $selected_columns); // Add selected columns
                    $query_string = http_build_query($export_params);

                    $print_url = 'hpc_panels_print.php?' . $query_string;
                    $excel_url = 'export_excel.php?' . $query_string;
                    $pdf_url = 'export_pdf.php?' . $query_string;
                    ?>
                    <a href="<?php echo htmlspecialchars($excel_url); ?>" target="_blank" class="btn btn-outline-success">
                        <i class="bi bi-file-earmark-excel me-1"></i> اکسل
                    </a>
                    <a href="<?php echo htmlspecialchars($pdf_url); ?>" target="_blank" class="btn btn-outline-danger">
                        <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                    </a>
                    <a href="<?php echo htmlspecialchars($print_url); ?>" target="_blank" class="btn btn-outline-secondary">
                        <i class="bi bi-printer me-1"></i> چاپ
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-sm mb-0">
                        <thead>
                            <tr>
                                <?php foreach ($selected_columns as $col_key): ?>
                                    <th><?php echo htmlspecialchars($available_columns[$col_key]); ?></th>
                                <?php endforeach; ?>

                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="<?php echo count($selected_columns); /* +1 if action col exists */ ?>" class="text-center text-muted py-3">رکوردی یافت نشد.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $record): ?>
                                    <tr>
                                        <?php foreach ($selected_columns as $col_key): ?>
                                            <td>
                                                <?php
                                                $value = $record[$col_key] ?? null;
                                                if ($value === null) {
                                                    echo '-';
                                                } elseif (in_array($col_key, ['assigned_date', 'mesh_end_time', 'concrete_end_time', 'assembly_end_time', 'shipping_date', 'planned_finish_date', 'formwork_end_time'])) {
                                                    // Format Dates (Handle datetime columns by taking date part)
                                                    $date_part = ($value) ? explode(' ', $value)[0] : null;
                                                    echo gregorianToShamsi($date_part) ?: '-';
                                                } elseif ($col_key === 'polystyrene') {
                                                    echo $polystyrene_map_persian[$value] ?? htmlspecialchars($value);
                                                } elseif ($col_key === 'status') {
                                                    echo $status_map_persian[$value] ?? htmlspecialchars($value);
                                                } elseif ($col_key === 'packing_status') {
                                                    echo $packing_status_map_persian[$value] ?? htmlspecialchars($value);
                                                } elseif (is_numeric($value) && $col_key === 'area') {
                                                    // Format area with 3 decimals
                                                    echo number_format((float)$value, 3);
                                                } elseif (is_numeric($value) && ($col_key === 'width' || $col_key === 'length')) {
                                                    // Display width/length as is (assuming whole mm) or format with 0 decimals
                                                    echo number_format((float)$value, 0);
                                                    // Or just: echo htmlspecialchars($value); if they are always integers
                                                } else {
                                                    echo htmlspecialchars($value);
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>

                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div> <!-- /main-container -->

    <?php require_once 'footer.php'; ?>

    <!-- JS Includes -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    // No SortableJS or DnD needed

    <script>
        // --- Utility Functions ---

        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func.apply(this, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Function to save user preferences (keeps existing fetch logic)
        function saveUserPreferences(preferencesType, preferences) {
            console.log('Attempting to save preferences:', preferencesType, preferences);
            const saveIndicator = document.getElementById('saveIndicator');
            if (saveIndicator) saveIndicator.innerHTML = '<i class="bi bi-hourglass-split text-muted"></i> Saving...';

            if (typeof preferences === 'object') {
                preferences = JSON.stringify(preferences);
            }

            var formData = new FormData();
            formData.append('page', 'hpc_panels_manager');
            formData.append('preference_type', preferencesType);
            formData.append('preferences', preferences);

            fetch('save_preferences.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Preferences saved successfully');
                        if (saveIndicator) saveIndicator.innerHTML = '<i class="bi bi-check-lg text-success"></i> Saved';
                    } else {
                        console.error('Error saving preferences:', data.message);
                        if (saveIndicator) saveIndicator.innerHTML = '<i class="bi bi-x-octagon-fill text-danger"></i> Error';
                    }
                })
                .catch(error => {
                    console.error('Fetch error saving preferences:', error);
                    if (saveIndicator) saveIndicator.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-warning"></i> Network Error';
                })
                .finally(() => {
                    setTimeout(() => {
                        if (saveIndicator) saveIndicator.innerHTML = '';
                    }, 3000);
                });
        }

        // Create a debounced version of the save function
        const debouncedSaveColumns = debounce(saveUserPreferences, 750);

        // Get PHP column data into JS
        const ALL_AVAILABLE_COLUMNS = <?php echo json_encode($available_columns); ?>;

        // --- Core Column Management Functions (Button-Based) ---

        function updateColumnsInputAndSave() {
            const selectedItems = document.querySelectorAll('#selected-columns .column-item');
            const columns = Array.from(selectedItems).map(item => item.dataset.column);
            const columnsValue = columns.join(',');
            document.getElementById('columnsInput').value = columnsValue;

            updateSelectedButtonStates();
            debouncedSaveColumns('columns', columnsValue); // Note: debouncedSaveColumns expects (type, value)
        }

        function updateSelectedButtonStates() {
            const selectedItems = document.querySelectorAll('#selected-columns .column-item');
            selectedItems.forEach((item, index) => {
                const upButton = item.querySelector('.btn-move-up');
                const downButton = item.querySelector('.btn-move-down');
                if (upButton) upButton.disabled = (index === 0);
                if (downButton) downButton.disabled = (index === selectedItems.length - 1);
            });
        }

        function createColumnItemHTML(colKey, isSelected) {
            const label = ALL_AVAILABLE_COLUMNS[colKey] || colKey;
            let buttonsHTML = '';
            let itemClass = 'column-item d-flex justify-content-between align-items-center p-1 border-bottom';

            if (isSelected) {
                itemClass += ' bg-light';
                buttonsHTML = `
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 btn-move-up" title="انتقال به بالا">
                            <i class="bi bi-arrow-up"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 btn-move-down" title="انتقال به پایین">
                             <i class="bi bi-arrow-down"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 btn-remove-col" title="حذف">
                             <i class="bi bi-x-lg"></i>
                        </button>
                    </div>`;
            } else {
                buttonsHTML = `
                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-1 btn-add-col" title="افزودن">
                        <i class="bi bi-plus-lg"></i>
                    </button>`;
            }

            return `
                <div class="${itemClass}" data-column="${colKey}">
                    <span class="col-label">${label}</span>
                    ${buttonsHTML}
                </div>`;
        }

        // --- Event Handlers (Button-Based) ---

        function handleColumnAction(event) {
            // Use event delegation: Find the button that was clicked
            const button = event.target.closest('button');
            if (!button) return;

            const columnItem = button.closest('.column-item');
            if (!columnItem) return;

            const colKey = columnItem.dataset.column;
            const availableContainer = document.getElementById('available-columns');
            const selectedContainer = document.getElementById('selected-columns');

            // Perform action based on button class
            if (button.classList.contains('btn-add-col')) {
                columnItem.remove();
                selectedContainer.insertAdjacentHTML('beforeend', createColumnItemHTML(colKey, true));
                updateColumnsInputAndSave();
            } else if (button.classList.contains('btn-remove-col')) {
                columnItem.remove();
                // Find correct position in available (optional, append is simpler)
                availableContainer.insertAdjacentHTML('beforeend', createColumnItemHTML(colKey, false));
                // TODO: Optionally sort availableContainer alphabetically or by original order?
                updateColumnsInputAndSave();
            } else if (button.classList.contains('btn-move-up')) {
                const previousItem = columnItem.previousElementSibling;
                if (previousItem) {
                    selectedContainer.insertBefore(columnItem, previousItem);
                    updateColumnsInputAndSave();
                }
            } else if (button.classList.contains('btn-move-down')) {
                const nextItem = columnItem.nextElementSibling;
                if (nextItem) {
                    // To move down, insert the *next* element *before* the current one
                    selectedContainer.insertBefore(nextItem, columnItem);
                    updateColumnsInputAndSave();
                }
            }
        }

        // --- Document Ready ---
        document.addEventListener('DOMContentLoaded', function() {

            // Initialize Datepickers
            $(".datepicker").persianDatepicker({
                format: 'YYYY/MM/DD',
                autoClose: true,
                initialValue: false,
                calendar: {
                    persian: {
                        locale: 'fa',
                        leapYearMode: 'astronomical'
                    }
                }
            });

            const availableContainer = document.getElementById('available-columns');
            const selectedContainer = document.getElementById('selected-columns');

            // --- Attach Event Listeners for Buttons ---
            availableContainer.addEventListener('click', handleColumnAction);
            selectedContainer.addEventListener('click', handleColumnAction);

            // --- Reset Button Logic ---
            document.getElementById('resetColumns').addEventListener('click', function() {
                const defaultColumns = <?php echo json_encode($default_columns); ?>;
                const defaultSet = new Set(defaultColumns);

                availableContainer.innerHTML = ''; // Clear lists
                selectedContainer.innerHTML = '';

                // Rebuild lists based on default
                for (const colKey in ALL_AVAILABLE_COLUMNS) {
                    if (ALL_AVAILABLE_COLUMNS.hasOwnProperty(colKey)) {
                        if (defaultSet.has(colKey)) {
                            // Add to selected list (will be sorted next)
                            selectedContainer.insertAdjacentHTML('beforeend', createColumnItemHTML(colKey, true));
                        } else {
                            availableContainer.insertAdjacentHTML('beforeend', createColumnItemHTML(colKey, false));
                        }
                    }
                }

                // Order selected items according to default order
                const sortedSelectedFragment = document.createDocumentFragment();
                defaultColumns.forEach(key => {
                    // Find the item we just added to selectedContainer
                    const item = selectedContainer.querySelector(`.column-item[data-column="${key}"]`);
                    if (item) {
                        sortedSelectedFragment.appendChild(item); // Add it to the fragment in the correct order
                    }
                });
                selectedContainer.innerHTML = ''; // Clear selected container again
                selectedContainer.appendChild(sortedSelectedFragment); // Append the correctly ordered items

                updateColumnsInputAndSave(); // Update hidden input, buttons state, and save
            });

            // --- Initial State Setup ---
            updateSelectedButtonStates(); // Set initial Up/Down button states correctly

        });
    </script>
</body>

</html>
<?php ob_end_flush(); ?>