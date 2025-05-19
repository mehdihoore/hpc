<?php
// export_excel.php (CSV Version - No Composer Needed)
ini_set('display_errors', 1); // Enable error display for debugging
error_reporting(E_ALL);
ob_start(); // Start output buffering to catch stray output/errors
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once __DIR__ . '/includes/jdf.php';
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
$report_key = 'new_panels'; // HARDCODED FOR THIS FILE
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/new_panels.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// --- Mappings & Available Columns (MUST match hpc_panels_manager.php) ---
$status_map_persian = [
    'pending' => 'در انتظار',
    'polystyrene' => 'قالب فوم',
    'Mesh' => 'مش بندی',
    'Concreting' => 'قالب‌بندی/بتن ریزی',
    'Assembly' => 'فیس کوت',
    'completed' => 'تکمیل شده',
];
$packing_status_map_persian = [
    'pending' => 'در انتظار',
    'assigned' => 'تخصیص یافته',
    'shipped' => 'ارسال شده'
];
$polystyrene_map_persian = [0 => 'انجام نشده', 1 => 'انجام شده'];
$available_columns = [
    'row_num' => 'شماره ردیف',
    'address' => 'آدرس',
    'type' => 'نوع',
    'area' => 'مساحت (m²)',
    'width' => 'عرض (mm)',
    'length' => 'طول (mm)',
    'formwork_type' => 'نوع قالب',
    'Proritization' => 'اولویت',
    'latest_activity' => 'آخرین فعالیت', // NEW
    'assigned_date' => 'تاریخ تولید',
    'polystyrene' => 'پلی استایرن',
    'mesh_end_time' => 'پایان مش',
    'concrete_end_time' => 'پایان بتن',
    'assembly_end_time' => 'پایان فیس کوت',
    'inventory_status' => 'موجودی/ارسال شده', // NEW
    'packing_status' => 'وضعیت بسته بندی',
    'shipping_date' => 'تاریخ ارسال', // NEW
];
$csv_default_columns = [ // Renamed for clarity
    'row_num',
    'address',
    'type',
    'area',
    'width',
    'length', // Include dimensions
    'latest_activity',
    'assigned_date',
    'concrete_end_time',
    'inventory_status',
    'packing_status',
    'shipping_date'
];


// --- Helper Functions (Copy if needed) ---

function getUserPreferences($user_id, $page, $preference_type = 'columns', $default_value = null)
{
    global $pdo; // Ensure $pdo is accessible
    try {
        // Add check if $pdo is valid
        if (!isset($pdo) || !$pdo instanceof PDO) {
            error_log("getUserPreferences called but PDO connection is not available.");
            return $default_value;
        }
        $stmt = $pdo->prepare("SELECT preferences FROM user_preferences WHERE user_id = ? AND page = ? AND preference_type = ?");
        $stmt->execute([$user_id, $page, $preference_type]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && !empty($result['preferences'])) {
            return $result['preferences']; // Return the string 'col1,col2,...'
        }
        return $default_value;
    } catch (PDOException $e) {
        error_log("Error getting user preferences (User: $user_id, Page: $page, Type: $preference_type): " . $e->getMessage());
        return $default_value; // Return default on error
    }
}
$latest_activity_map_persian = [
    'assembly_done' => 'فیس کوت انجام شد',
    'concrete_done' => 'بتن ریزی انجام شد',
    'mesh_done' => 'مش بندی انجام شد',
    'pending' => 'شروع نشده / در انتظار',
];

// Add the list of columns needing Persian date formatting
$persian_date_columns = ['assigned_date', 'mesh_end_time', 'concrete_end_time', 'assembly_end_time', 'shipping_date']; // Added shipping_date
// --- Get Selected Columns ---
$selected_columns = []; // Initialize
$current_user_id = $_SESSION['user_id'] ?? null; // Ensure current_user_id is set

// 1. Try to load from User Preferences for the *manager* page
if ($current_user_id) {
    $user_columns_pref_string = getUserPreferences($current_user_id, 'hpc_panels_manager', 'columns', null);
    if (!empty($user_columns_pref_string)) {
        $selected_columns = explode(',', $user_columns_pref_string);
        if (count($selected_columns) === 1 && $selected_columns[0] === '') {
            $selected_columns = [];
        } else {
            error_log("[export_excel] Using columns from DB preferences for user {$current_user_id}.");
        }
    }
} else {
    error_log("[export_excel] Cannot load user preferences: user_id not found in session.");
}

// 2. Fallback to URL parameter if DB prefs weren't found or were empty
if (empty($selected_columns)) {
    $selected_columns_str_url = trim($_GET['cols'] ?? '');
    if (!empty($selected_columns_str_url)) {
        $selected_columns = explode(',', $selected_columns_str_url);
        error_log("[export_excel] Using columns from URL parameter ('cols').");
    }
}

// 3. Fallback to CSV Defaults if DB and URL provided no columns
if (empty($selected_columns)) {
    $selected_columns = $csv_default_columns; // Use CSV defaults
    error_log("[export_excel] Using CSV default columns.");
}

// --- Final Validation ---
// (Keep the validation logic exactly as in the print/pdf script)
$validated_columns = [];
foreach ($selected_columns as $col_key) {
    if (isset($available_columns[trim($col_key)])) {
        $validated_columns[] = trim($col_key);
    } else {
        error_log("[export_excel] Warning: Column '{$col_key}' from preferences/URL is not in available_columns, skipping.");
    }
}
$selected_columns = $validated_columns;

// Final fallback check
if (empty($selected_columns)) {
    error_log("[export_excel] Columns empty after validation, falling back to *validated* CSV defaults.");
    $selected_columns = array_intersect($csv_default_columns, array_keys($available_columns));
    if (empty($selected_columns)) {
        error_log("[export_excel] CRITICAL: CSV default columns are also invalid or empty after validation.");
        $selected_columns = ['address']; // Absolute fallback
    }
}
// $selected_columns is now ready
// --- Rebuild Filters (Copy the EXACT logic from hpc_panels_manager.php) ---
// --- Rebuild Filters (Copy the EXACT logic from hpc_panels_manager.php) ---
$search_params = [];
$sql_conditions = [];

// Text Filters (Address only now)
if (!empty($_GET["filter_address"])) {
    $value = trim($_GET["filter_address"]);
    $sql_conditions[] = "`address` LIKE :address";
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
$exact_match_filters = ['type', 'Proritization', /*'status',*/ 'packing_status', 'polystyrene']; // Removed 'status'
foreach ($exact_match_filters as $filter_key) {
    if (isset($_GET["filter_$filter_key"]) && $_GET["filter_$filter_key"] !== '') {
        $value = $_GET["filter_$filter_key"];
        if ($filter_key == 'polystyrene' && ($value === '0' || $value === '1')) {
            $sql_conditions[] = "`$filter_key` = :$filter_key";
            $search_params[":$filter_key"] = $value;
        } elseif ($filter_key != 'polystyrene') {
            $sql_conditions[] = "`$filter_key` = :$filter_key";
            $search_params[":$filter_key"] = $value;
        }
    }
}

// Handle NEW "Inventory Status" Filter
if (isset($_GET["filter_inventory_status"]) && $_GET["filter_inventory_status"] !== '') {
    $inv_status_filter = $_GET["filter_inventory_status"];
    if ($inv_status_filter === 'موجود') {
        $sql_conditions[] = "(concrete_end_time IS NOT NULL AND concrete_end_time != '0000-00-00 00:00:00' AND (packing_status IS NULL OR packing_status != 'shipped'))";
    } elseif ($inv_status_filter === 'ارسال شده') {
        $sql_conditions[] = "(packing_status = 'shipped')";
    }
}

// Handle NEW "Latest Activity" Filter
if (isset($_GET["filter_latest_activity"]) && $_GET["filter_latest_activity"] !== '') {
    $activity_filter = $_GET["filter_latest_activity"];
    $null_date_check = "IS NULL OR %s = '0000-00-00 00:00:00'";
    $not_null_date_check = "IS NOT NULL AND %s != '0000-00-00 00:00:00'";

    if ($activity_filter === 'assembly_done') {
        $sql_conditions[] = sprintf("assembly_end_time $not_null_date_check", "assembly_end_time");
    } elseif ($activity_filter === 'concrete_done') {
        $sql_conditions[] = sprintf("concrete_end_time $not_null_date_check", "concrete_end_time") . " AND (" . sprintf("assembly_end_time $null_date_check", "assembly_end_time") . ")";
    } elseif ($activity_filter === 'mesh_done') {
        $sql_conditions[] = sprintf("mesh_end_time $not_null_date_check", "mesh_end_time") . " AND (" . sprintf("concrete_end_time $null_date_check", "concrete_end_time") . ")";
    } elseif ($activity_filter === 'pending') {
        $sql_conditions[] = "(" . sprintf("mesh_end_time $null_date_check", "mesh_end_time") . ")";
    }
}

// Date Range Filters
$date_filters = ['assigned_date', 'mesh_end_time', 'concrete_end_time', 'assembly_end_time', 'shipping_date']; // Added shipping_date
foreach ($date_filters as $filter_key) {
    // (Keep the inner logic for parsing Jalali dates the same)
    $date_from_j = $_GET["filter_{$filter_key}_from"] ?? '';
    $date_to_j = $_GET["filter_{$filter_key}_to"] ?? '';
    // --- Paste the full Jalali to Gregorian conversion logic here ---
    if (!empty($date_from_j)) {
        $jds = toLatinDigitsPhp(trim($date_from_j));
        $jp = explode('/', $jds);
        if (count($jp) === 3) {
            $ga = jalali_to_gregorian(intval($jp[0]), intval($jp[1]), intval($jp[2]));
            if ($ga) {
                $gs = sprintf('%04d-%02d-%02d', $ga[0], $ga[1], $ga[2]);
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
    // --- End of pasted date logic ---
}

// --- Build Final Query ---
$where_clause = "";
if (!empty($sql_conditions)) {
    $where_clause = " WHERE " . implode(" AND ", $sql_conditions);
}

// --- Fetch Data ---
$records = [];
try {
    // Select only the columns needed for export
    $sql_export = "SELECT * FROM hpc_panels" . $where_clause . " ORDER BY Proritization DESC, assigned_date DESC, id DESC";

    $stmt_export = $pdo->prepare($sql_export);
    $stmt_export->execute($search_params);
    $records = $stmt_export->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logError("Error fetching data for CSV export: " . $e->getMessage());
    die("Error fetching data.");
}

// --- Generate CSV Output ---
$filename = "hpc_panels_" . date('Ymd_His') . ".csv";

// Set Headers for CSV download
header('Content-Type: text/csv; charset=utf-8'); // Specify UTF-8
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Clear buffer before sending headers
ob_end_clean();

// Open output stream
$output = fopen('php://output', 'w');
if ($output === false) {
    die("Failed to open output stream.");
}

// Add UTF-8 BOM (Byte Order Mark) for better Excel compatibility with UTF-8 characters
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// --- Write Headers ---
$header_row = [];
foreach ($selected_columns as $col_key) {
    $header_row[] = $available_columns[$col_key] ?? $col_key; // Use label or key
}
fputcsv($output, $header_row);

// --- Write Data Rows ---
$row_number = 1; // Initialize row number
foreach ($records as $record) {
    $data_row = [];
    foreach ($selected_columns as $col_key) {
        $value = $record[$col_key] ?? null;
        $formatted_value = ''; // Default to empty string for null/unset in CSV

        // --- Handle Special Calculated Columns First ---
        if ($col_key === 'row_num') {
            $formatted_value = $row_number;
        } elseif ($col_key === 'inventory_status') {
            $concrete_end = !empty($record['concrete_end_time']) && $record['concrete_end_time'] != '0000-00-00 00:00:00';
            $packing_status = $record['packing_status'] ?? null;
            if ($packing_status === 'shipped') {
                $formatted_value = 'ارسال شده';
            } elseif ($concrete_end && $packing_status !== 'shipped') {
                $formatted_value = 'موجود';
            } // else remains empty string ''
        } elseif ($col_key === 'latest_activity') {
            $assembly_done = !empty($record['assembly_end_time']) && $record['assembly_end_time'] != '0000-00-00 00:00:00';
            $concrete_done = !empty($record['concrete_end_time']) && $record['concrete_end_time'] != '0000-00-00 00:00:00';
            $mesh_done = !empty($record['mesh_end_time']) && $record['mesh_end_time'] != '0000-00-00 00:00:00';

            if ($assembly_done) {
                $formatted_value = $latest_activity_map_persian['assembly_done'];
            } elseif ($concrete_done) {
                $formatted_value = $latest_activity_map_persian['concrete_done'];
            } elseif ($mesh_done) {
                $formatted_value = $latest_activity_map_persian['mesh_done'];
            } else {
                $formatted_value = $latest_activity_map_persian['pending'];
            }
        }
        // --- Handle Standard DB Columns ---
        elseif ($value !== null) {
            $formatted_value = $value; // Start with raw value

            // Use the $persian_date_columns array
            if (in_array($col_key, $persian_date_columns)) {
                $date_part = explode(' ', $value)[0];
                $formatted_value = function_exists('gregorianToShamsi') ? gregorianToShamsi($date_part) : $date_part;
                if (empty($formatted_value)) $formatted_value = ''; // Use empty string for invalid/empty dates
            } elseif ($col_key === 'polystyrene') {
                $formatted_value = $polystyrene_map_persian[$value] ?? $value;
            }
            // Removed old 'status' check
            elseif ($col_key === 'packing_status') {
                $formatted_value = $packing_status_map_persian[$value] ?? $value;
            } elseif (is_numeric($value) && ($col_key === 'area' || $col_key === 'width' || $col_key === 'length')) {
                // Keep as number for Excel - ensure it's float/int
                $formatted_value = is_int($value) ? (int)$value : (float)$value;
            }
            // Handle potential arrays/objects defensively
            if (is_array($formatted_value) || is_object($formatted_value)) {
                $formatted_value = json_encode($formatted_value); // Or handle differently
            }
        }
        // else $formatted_value remains '' for null database values

        $data_row[] = $formatted_value; // Add the final value to the row array
    }
    fputcsv($output, $data_row);
    $row_number++; // Increment row number
}

fclose($output);
exit;
