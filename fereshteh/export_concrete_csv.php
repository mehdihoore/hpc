<?php
// export_concrete_csv.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once 'includes/jdf.php';


// --- Database Connection ---
secureSession(); // Initializes session and security checks

// Determine which project this instance of the file belongs to.
// This is simple hardcoding based on folder. More complex routing could derive this.
$current_file_path = __DIR__;
$expected_project_key = null;
if (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Fereshteh') !== false) {
    $expected_project_key = 'fereshteh';
} elseif (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Arad') !== false) {
    $expected_project_key = 'arad';
} else {
    // If the file is somehow not in a recognized project folder, handle error
    logError("admin_panel_search.php accessed from unexpected path: " . $current_file_path);
    die("خطای پیکربندی: پروژه قابل تشخیص نیست.");
}


// --- Authorization ---
// 1. Check if logged in
if (!isLoggedIn()) {
    header('Location: /login.php'); // Redirect to common login
    exit();
}
// 2. Check if user has selected ANY project
if (!isset($_SESSION['current_project_config_key'])) {
    logError("Access attempt to {$expected_project_key}/admin_panel_search.php without project selection. User ID: " . $_SESSION['user_id']);
    header('Location: /select_project.php'); // Redirect to project selection
    exit();
}
// 3. Check if the selected project MATCHES the folder this file is in
if ($_SESSION['current_project_config_key'] !== $expected_project_key) {
    logError("Project context mismatch. Session has '{$_SESSION['current_project_config_key']}', expected '{$expected_project_key}'. User ID: " . $_SESSION['user_id']);
    // Maybe redirect to select_project or show an error specific to context mismatch
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/export_concrete_csv.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// --- Helper Functions (Copy from concrete_tests_manager.php) ---

function calculate_age($prod_g, $break_g)
{
    if (empty($prod_g) | empty($break_g)) return null;
    try {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prod_g) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $break_g)) return null;
        $p = new DateTime($prod_g);
        $b = new DateTime($break_g);
        if ($b < $p) return null;
        return $p->diff($b)->days;
    } catch (Exception $e) {
        error_log("Err calc age: " . $e->getMessage());
        return null;
    }
}
function calculate_strength($force_kg, $dim_l, $dim_w)
{
    if ($dim_l <= 0 || $dim_w <= 0 || $force_kg <= 0) return null;
    return ($force_kg * 9.80665) / ($dim_l * $dim_w);
}

// --- Filter Processing (MUST EXACTLY MATCH concrete_tests_manager.php) ---
$search_query = "";
$search_params = [];
$sql_conditions = [];
$filter_rejected = $_GET['filter_rejected'] ?? '';
$filter_sample_code = trim($_GET['filter_sample'] ?? '');
$filter_age = trim($_GET['filter_age'] ?? '');
$filter_date_from_j = trim($_GET['filter_date_from'] ?? '');
$filter_date_to_j = trim($_GET['filter_date_to'] ?? '');

// Date From Filter
if (!empty($filter_date_from_j)) {
    $jds = toLatinDigitsPhp($filter_date_from_j);
    $jp = explode('/', $jds);
    if (count($jp) === 3) {
        $ga = jalali_to_gregorian(intval($jp[0]), intval($jp[1]), intval($jp[2]));
        if ($ga) {
            $gs = sprintf('%04d-%02d-%02d', $ga[0], $ga[1], $ga[2]);
            if (DateTime::createFromFormat('Y-m-d', $gs)) {
                $sql_conditions[] = "production_date >= ?"; // Add condition
                $search_params[] = $gs;                  // Add corresponding parameter
                error_log("Filter Added: production_date >= $gs");
            }
        }
    }
}
// Date To Filter
if (!empty($filter_date_to_j)) {
    $jds = toLatinDigitsPhp($filter_date_to_j);
    $jp = explode('/', $jds);
    if (count($jp) === 3) {
        $ga = jalali_to_gregorian(intval($jp[0]), intval($jp[1]), intval($jp[2]));
        if ($ga) {
            $gs = sprintf('%04d-%02d-%02d', $ga[0], $ga[1], $ga[2]);
            if (DateTime::createFromFormat('Y-m-d', $gs)) {
                $sql_conditions[] = "production_date <= ?"; // Add condition
                $search_params[] = $gs;                  // Add corresponding parameter
                error_log("Filter Added: production_date <= $gs");
            }
        }
    }
}
// Sample Code Filter
if (!empty($filter_sample_code)) {
    $sql_conditions[] = "sample_code LIKE ?";
    $search_params[] = "%" . $filter_sample_code . "%";
}
// Age Filter
if ($filter_age !== '' && is_numeric($filter_age)) {
    $sql_conditions[] = "sample_age_at_break = ?";
    $search_params[] = intval($filter_age);
}
// Rejected Status Filter
if ($filter_rejected === '0' || $filter_rejected === '1') {
    $sql_conditions[] = "is_rejected = ?";
    $search_params[] = intval($filter_rejected);
}

// Combine conditions
if (!empty($sql_conditions)) {
    $search_query = " WHERE " . implode(" AND ", $sql_conditions);
}

// --- Fetch Data ---
try {
    // Select columns relevant for export
    $sql = "SELECT production_date, sample_code, break_date, sample_age_at_break,
                   dimension_l, dimension_w, dimension_h, max_force_kg,
                   compressive_strength_mpa, strength_1_day, strength_7_day, strength_28_day,
                   is_rejected, rejection_reason
            FROM concrete_tests" . $search_query . "
            ORDER BY production_date DESC, sample_age_at_break ASC, id ASC"; // Use same order as manager page

    $stmt = $pdo->prepare($sql);
    $stmt->execute($search_params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // logError("Error fetching data for CSV export: " . $e->getMessage());
    die("Error fetching data.");
}

// --- Generate CSV Output ---
$filename = "concrete_tests_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

ob_end_clean(); // Clear buffer

$output = fopen('php://output', 'w');
if ($output === false) {
    die("Failed to open output stream.");
}

// Add UTF-8 BOM
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// --- Write Headers ---
// Define headers manually for clarity
$header_row = [
    'تاریخ تولید',
    'کد نمونه',
    'تاریخ شکست',
    'سن',
    'ابعاد (LxWxH)',
    'نیرو Fx(kg)',
    'مقاومت محاسبه',
    'مقاومت ثبت ۱',
    'مقاومت ثبت ۷',
    'مقاومت ثبت ۲۸',
    'وضعیت',
    'دلیل مردودی'
];
fputcsv($output, $header_row);

// --- Write Data Rows ---
foreach ($records as $record) {
    $data_row = [];

    // Format data
    $prod_shamsi = gregorianToShamsi($record['production_date']);
    $break_shamsi = gregorianToShamsi($record['break_date']);
    $dimensions = ($record['dimension_l'] && $record['dimension_w'] && $record['dimension_h'])
        ? number_format((float)$record['dimension_l'], 1) . 'x' . number_format((float)$record['dimension_w'], 1) . 'x' . number_format((float)$record['dimension_h'], 1)
        : '-';
    // Use calculated strength if available, else empty
    $calculated_strength = ($record['compressive_strength_mpa'] !== null) ? number_format((float)$record['compressive_strength_mpa'], 2) : '';

    $status_text = $record['is_rejected'] ? 'مردود' : 'قبول';

    $data_row[] = !empty($prod_shamsi) ? $prod_shamsi : '-';
    $data_row[] = $record['sample_code'] ?? '-';
    $data_row[] = !empty($break_shamsi) ? $break_shamsi : '-';
    $data_row[] = $record['sample_age_at_break'] ?? '-';
    $data_row[] = $dimensions;
    $data_row[] = ($record['max_force_kg'] !== null) ? number_format((float)$record['max_force_kg'], 2) : ''; // Format force
    $data_row[] = $calculated_strength;
    $data_row[] = ($record['strength_1_day'] !== null) ? number_format((float)$record['strength_1_day'], 2) : '';
    $data_row[] = ($record['strength_7_day'] !== null) ? number_format((float)$record['strength_7_day'], 2) : '';
    $data_row[] = ($record['strength_28_day'] !== null) ? number_format((float)$record['strength_28_day'], 2) : '';
    $data_row[] = $status_text;
    $data_row[] = $record['rejection_reason'] ?? '';

    fputcsv($output, $data_row);
}

fclose($output);
exit;
?>