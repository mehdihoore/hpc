<?php
// export_pdf.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start(); // Start output buffering

require_once __DIR__ . '/../../sercon/config.php';
require_once 'includes/jdf.php'; // For gregorianToShamsi
require_once('includes/libraries/TCPDF-main/tcpdf.php'); 
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
    logError("DB Connection failed in export_pdf.php: " . $e->getMessage());
    die("Database connection error.");
}

// --- Mappings & Available Columns (MUST match) ---
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
$default_columns = ['address', 'type', 'area', 'width', 'length', 'formwork_type', 'status', 'assigned_date', 'packing_status']; // Sensible defaults

// --- Helper Functions (Copy if needed) ---
if (!function_exists('toPersianDigits')) {
    function toPersianDigits($num)
    {
        if ($num === null) return '';
        $persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $latinDigits = range(0, 9);
        // Ensure input is treated as a string for replacement
        return str_replace($latinDigits, $persianDigits, (string)$num);
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
// ... (copy logic from export_excel.php) ...
$selected_columns_str = trim($_GET['cols'] ?? implode(',', $default_columns));
$selected_columns = !empty($selected_columns_str) ? explode(',', $selected_columns_str) : $default_columns;
$selected_columns = array_intersect($selected_columns, array_keys($available_columns));
if (empty($selected_columns)) $selected_columns = $default_columns;

// --- Rebuild Filters (Copy the EXACT logic from hpc_panels_manager.php) ---
// ... (copy logic from export_excel.php) ...
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
// ... (copy logic from export_excel.php) ...
$records = [];
try {
    $possible_export_cols = implode(',', array_keys($available_columns));
    $sql_export = "SELECT id, " . $possible_export_cols . " FROM hpc_panels" . $where_clause . " ORDER BY Proritization DESC, assigned_date DESC, id DESC";
    $stmt_export = $pdo->prepare($sql_export);
    $stmt_export->execute($search_params);
    $records = $stmt_export->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logError("Error fetching data for PDF export: " . $e->getMessage());
    die("Error fetching data.");
}


// --- Create PDF ---
class MYPDF extends TCPDF
{
    // Optional: Page header/footer
    public function Header()
    {
        $this->SetFont('dejavusans', 'B', 12);
        $this->Cell(0, 10, 'گزارش پنل‌های HPC', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(5);
        $this->SetFont('dejavusans', '', 8);
        $current_gregorian_date = date('Y-m-d');
        $shamsi_date = function_exists('gregorianToShamsi') ? gregorianToShamsi($current_gregorian_date) : date('Y/m/d');
        $report_date_str = 'تاریخ گزارش: ' . $shamsi_date . ' ' . date('H:i:s');
        $this->Cell(0, 10, $report_date_str, 0, false, 'C', 0, '', 0, false, 'M', 'M');
    }
    public function Footer()
    {
        $this->SetY(-15);
        // Crucial: Set font *every time* before drawing text in header/footer
        $this->SetFont('dejavusans', 'I', 8);

        // Get current page number and convert it manually
        $currentPageNum = $this->getAliasNumPage();
        $currentPagePersian = function_exists('toPersianDigits') ? toPersianDigits($currentPageNum) : $currentPageNum;

        // Get the alias string for total pages
        $totalPagesAlias = $this->getAliasNbPages();

        // Construct the string - using "az" is often safer for RTL rendering than "/"
        $footerText = ' ' . $currentPagePersian . ' / ' . $totalPagesAlias;

        // Output the cell. Ensure alignment is center.
        // Parameters: width, height, text, border, line feed, align, fill, link, stretch, ignore min height, calign, valign
        $this->Cell(0, 10, $footerText, 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

// create new PDF document
// Use 'L' for Landscape if table is wide
$pdf = new MYPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Your Application Name');
$pdf->SetTitle('HPC Panels Report');
$pdf->SetSubject('Filtered HPC Panels Data');

// set default header/footer data
$pdf->SetHeaderData('', 0, '', '', array(0, 0, 0), array(255, 255, 255));
$pdf->setFooterData(array(0, 0, 0), array(64, 64, 64));

// set header and footer fonts
$pdf->setHeaderFont(array('dejavusans', '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(array('dejavusans', '', PDF_FONT_SIZE_DATA));

// set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP + 5, PDF_MARGIN_RIGHT); // Increase top margin for header space
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Set font for Persian characters
$pdf->SetFont('dejavusans', '', 8); // Size 8 is small, adjust as needed

// Set RTL direction
$pdf->setRTL(true);

// Add a page
$pdf->AddPage();

// --- Build HTML Table ---
$html = '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">';
// Headers
$html .= '<thead style="background-color:#f2f2f2; font-weight:bold;"><tr>';
foreach ($selected_columns as $col_key) {
    $header_text = $available_columns[$col_key] ?? $col_key;
    $html .= '<th style="text-align:center;">' . htmlspecialchars($header_text) . '</th>';
}
$html .= '</tr></thead>';

// Data Rows
$html .= '<tbody>';
if (empty($records)) {
    $html .= '<tr><td colspan="' . count($selected_columns) . '" style="text-align:center;">رکوردی یافت نشد.</td></tr>';
} else {
    foreach ($records as $record) {
        $html .= '<tr>';
        foreach ($selected_columns as $col_key) {
            $value = $record[$col_key] ?? null;
            $formatted_value = '-'; // Default for null

            if ($value !== null) {
                $formatted_value = $value; // Start with raw value
                // Apply formatting similar to the main page
                if (in_array($col_key, ['assigned_date', 'mesh_end_time', 'concrete_end_time', 'assembly_end_time'])) {
                    $date_part = explode(' ', $value)[0];
                    $formatted_value = function_exists('gregorianToShamsi') ? gregorianToShamsi($date_part) : $date_part;
                    if (empty($formatted_value)) $formatted_value = '-';
                } elseif ($col_key === 'polystyrene') {
                    $formatted_value = $polystyrene_map_persian[$value] ?? $value;
                } elseif ($col_key === 'status') {
                    $formatted_value = $status_map_persian[$value] ?? $value;
                } elseif ($col_key === 'packing_status') {
                    $formatted_value = $packing_status_map_persian[$value] ?? $value;
                } elseif (is_numeric($value) && $col_key === 'area') {
                    $formatted_value = number_format((float)$value, 3);
                } elseif (is_numeric($value) && ($col_key === 'width' || $col_key === 'length')) {
                    $formatted_value = number_format((float)$value, 0);
                }
                // Always escape for HTML
                $formatted_value = htmlspecialchars((string)$formatted_value);
            }

            $html .= '<td style="text-align:center;">' . $formatted_value . '</td>';
        }
        $html .= '</tr>';
    }
}
$html .= '</tbody></table>';

// --- Write HTML to PDF ---
$pdf->writeHTML($html, true, false, true, false, '');


// --- Output PDF ---
$filename = "hpc_panels_" . date('Ymd_His') . ".pdf";

// Clear buffer before sending headers
ob_end_clean();

// Close and output PDF document
// 'D' force download, 'I' display in browser
$pdf->Output($filename, 'D');

exit;
?>