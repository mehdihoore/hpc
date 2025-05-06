<?php
// export_excel.php (CSV Version - No Composer Needed)
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start(); // Start output buffering

require_once __DIR__ . '/../../sercon/config_fereshteh.php';
require_once 'includes/jdf.php'; // Include if needed for formatting

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// --- Authentication ---
if (!isset($_SESSION['user_id'])) {
    die('Authentication required.');
}

// --- Database Connection ---
try {
    $db_options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, $db_options);
} catch (PDOException $e) {
    logError("DB Connection failed in export_excel.php (CSV): " . $e->getMessage());
    die("Database connection error.");
}

// --- Mappings & Available Columns (MUST match hpc_panels_manager.php) ---
$status_map_persian = [
    'pending' => 'در انتظار', 'polystyrene' => 'قالب فوم', 'Mesh' => 'مش بندی',
    'Concreting' => 'قالب‌بندی/بتن ریزی', 'Assembly' => 'فیس کوت', 'completed' => 'تکمیل شده',
];
$packing_status_map_persian = [
    'pending' => 'در انتظار', 'assigned' => 'تخصیص یافته', 'shipped' => 'ارسال شده'
];
$polystyrene_map_persian = [ 0 => 'انجام نشده', 1 => 'انجام شده' ];
$available_columns = [
    'address' => 'آدرس', 'type' => 'نوع', 'area' => 'مساحت (m²)', 'width' => 'عرض (mm)',
    'length' => 'طول (mm)', 'formwork_type' => 'نوع قالب', 'Proritization' => 'اولویت',
    'status' => 'وضعیت', 'assigned_date' => 'تاریخ تخصیص', 'polystyrene' => 'پلی استایرن',
    'mesh_end_time' => 'پایان مش', 'concrete_end_time' => 'پایان بتن',
    'assembly_end_time' => 'پایان فیس کوت', 'packing_status' => 'وضعیت بسته بندی',
];
$default_columns = ['address', 'type', 'area', 'width', 'length', 'formwork_type', 'status', 'assigned_date', 'packing_status'];


// --- Helper Functions (Copy if needed) ---
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


// --- Get Selected Columns ---
$selected_columns_str = trim($_GET['cols'] ?? implode(',', $default_columns));
$selected_columns = !empty($selected_columns_str) ? explode(',', $selected_columns_str) : $default_columns;
$selected_columns = array_intersect($selected_columns, array_keys($available_columns));
if (empty($selected_columns)) $selected_columns = $default_columns;

// --- Rebuild Filters (Copy the EXACT logic from hpc_panels_manager.php) ---
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

// --- Fetch Data ---
$records = [];
try {
    // Select only the columns needed for export
    $cols_to_select_str = implode(',', array_unique(array_merge(['id'], $selected_columns))); // Ensure needed cols are selected
    $sql_export = "SELECT " . $cols_to_select_str . " FROM hpc_panels" . $where_clause . " ORDER BY Proritization DESC, assigned_date DESC, id DESC";

    $stmt_export = $pdo->prepare($sql_export);
    $stmt_export->execute($search_params);
    // No need to fetch all if memory is a concern, could process row by row
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
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// --- Write Headers ---
$header_row = [];
foreach ($selected_columns as $col_key) {
    $header_row[] = $available_columns[$col_key] ?? $col_key; // Use label or key
}
fputcsv($output, $header_row);

// --- Write Data Rows ---
foreach ($records as $record) {
    $data_row = [];
    foreach ($selected_columns as $col_key) {
        $value = $record[$col_key] ?? null;
        $formatted_value = $value; // Default

        // Apply formatting similar to the main page
         if ($value !== null) {
             if (in_array($col_key, ['assigned_date', 'mesh_end_time', 'concrete_end_time', 'assembly_end_time'])) {
                 $date_part = explode(' ', $value)[0];
                 $formatted_value = function_exists('gregorianToShamsi') ? gregorianToShamsi($date_part) : $date_part;
                  if(empty($formatted_value)) $formatted_value = '-';
             } elseif ($col_key === 'polystyrene') {
                 $formatted_value = $polystyrene_map_persian[$value] ?? $value;
             } elseif ($col_key === 'status') {
                 $formatted_value = $status_map_persian[$value] ?? $value;
             } elseif ($col_key === 'packing_status') {
                 $formatted_value = $packing_status_map_persian[$value] ?? $value;
             } elseif (is_numeric($value) && $col_key === 'area') {
                 // Keep numbers as numbers for CSV where possible, or format explicitly
                 // $formatted_value = number_format((float)$value, 3); // Use this if you NEED specific formatting in CSV
                 $formatted_value = (float)$value;
             } elseif (is_numeric($value) && ($col_key === 'width' || $col_key === 'length')) {
                 // $formatted_value = number_format((float)$value, 0);
                  $formatted_value = (float)$value;
             }
             // Convert potential arrays/objects just in case
             if (is_array($formatted_value) || is_object($formatted_value)) {
                  $formatted_value = json_encode($formatted_value);
             }
        } else {
            $formatted_value = ''; // Use empty string for null in CSV often preferred over '-'
        }
        $data_row[] = $formatted_value;
    }
    fputcsv($output, $data_row);
}

fclose($output);
exit;
?>