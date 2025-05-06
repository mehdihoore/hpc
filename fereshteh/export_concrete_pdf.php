<?php
// export_concrete_pdf.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../../sercon/config.php';
require_once 'includes/jdf.php';
// --- Manual TCPDF Include ---
require_once('includes/libraries/TCPDF-main/tcpdf.php'); // <-- ADJUST PATH

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// --- Authentication ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'supervisor', 'superuser'])) { // Match manager roles
    die('Access Denied.');
}

// --- Database Connection ---
try {
    $db_options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, $db_options);
} catch (PDOException $e) {
    // logError("DB Connection failed in export_concrete_pdf.php: " . $e->getMessage());
    die("Database connection error.");
}

// --- Helper Functions (Copy from concrete_tests_manager.php) ---
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
// ... (Copy the ENTIRE filter processing block from export_concrete_csv.php above) ...
$search_query = "";
$search_params = [];
$sql_conditions = [];
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
    $sql_conditions[] = "sample_code LIKE ?";       // Add condition
    $search_params[] = "%" . $filter_sample_code . "%"; // Add corresponding parameter
    error_log("Filter Added: sample_code LIKE '%$filter_sample_code%'");
}
// << Age Filter >>
if ($filter_age !== '' && is_numeric($filter_age)) {
    $sql_conditions[] = "sample_age_at_break = ?"; // Exact match for age
    $search_params[] = intval($filter_age);        // Use integer value
    error_log("Filter Added: sample_age_at_break = $filter_age");
} elseif ($filter_age !== '') {
    // Log if a non-empty, non-numeric value was passed for age
    error_log("Invalid non-numeric value received for age filter: '$filter_age'");
}
// Rejected Status Filter - CORRECTED
if ($filter_rejected === '0' || $filter_rejected === '1') {
    $sql_conditions[] = "is_rejected = ?";          // CORRECT Condition
    $search_params[] = intval($filter_rejected);   // CORRECT Parameter
    error_log("Filter Added: is_rejected = $filter_rejected");
}
if (!empty($sql_conditions)) {
    $search_query = " WHERE " . implode(" AND ", $sql_conditions);
}

// --- Fetch Data ---
try {
    $sql = "SELECT * FROM concrete_tests" . $search_query . "
            ORDER BY production_date DESC, sample_age_at_break ASC, id ASC"; // Use same order

    $stmt = $pdo->prepare($sql);
    $stmt->execute($search_params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // logError("Error fetching data for PDF export: " . $e->getMessage());
    die("Error fetching data.");
}

// --- Create PDF ---
class MYPDF extends TCPDF {
    public function Header() {
        $this->SetFont('dejavusans', 'B', 12);
        $this->Cell(0, 10, 'گزارش تست‌های بتن', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(5);
        $this->SetFont('dejavusans', '', 8);
        $current_gregorian_date = date('Y-m-d');
        $shamsi_date = function_exists('gregorianToShamsi') ? gregorianToShamsi($current_gregorian_date) : date('Y/m/d');
        $report_date_str = 'تاریخ گزارش: ' . $shamsi_date . ' ' . date('H:i:s');
        $this->Cell(0, 10, $report_date_str, 0, false, 'C', 0, '', 0, false, 'M', 'M');
    }
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('dejavusans', 'I', 8);
        $footerText = ' ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages();
        $this->Cell(0, 10, $footerText, 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

// Create new PDF document (Landscape might be better)
$pdf = new MYPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator(PDF_CREATOR); $pdf->SetAuthor('Your Application'); $pdf->SetTitle('Concrete Tests Report');

// Set header/footer data (using defaults from MYPDF class)
$pdf->setPrintHeader(true); $pdf->setPrintFooter(true);

// Set margins
$pdf->SetMargins(10, 20, 10); // L, T, R (Increased Top margin for header)
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, 15); // Margin bottom

// Set font
$pdf->SetFont('dejavusans', '', 7); // Use a smaller font for potentially wide tables

// Set RTL
$pdf->setRTL(true);

// Add a page
$pdf->AddPage();

// --- Build HTML Table ---
$html = '<style>
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #ccc; padding: 3px; text-align: center; font-size: 7pt; }
            th { background-color: #f2f2f2; font-weight: bold; }
            .rejected { background-color: #f8d7da; } /* Optional: highlight rejected rows */
         </style>';
$html .= '<table>';
// Headers
$html .= '<thead><tr>';
// Manually define headers matching the CSV for consistency
$headers = [
    'تاریخ تولید', 'کد نمونه', 'تاریخ شکست', 'سن', 'ابعاد (LxWxH)', 'نیرو Fx(kg)',
    'مقاومت محاسبه', 'مقاومت ثبت ۱', 'مقاومت ثبت ۷', 'مقاومت ثبت ۲۸', 'وضعیت', 'دلیل مردودی'
];
foreach ($headers as $header) {
    $html .= '<th>' . htmlspecialchars($header) . '</th>';
}
$html .= '</tr></thead>';

// Data Rows
$html .= '<tbody>';
if (empty($records)) {
     $html .= '<tr><td colspan="' . count($headers) . '">رکوردی یافت نشد.</td></tr>';
} else {
    foreach ($records as $record) {
        // Format data similarly to CSV
        $prod_shamsi = gregorianToShamsi($record['production_date']);
        $break_shamsi = gregorianToShamsi($record['break_date']);
        $dimensions = ($record['dimension_l'] && $record['dimension_w'] && $record['dimension_h'])
                      ? number_format((float)$record['dimension_l'], 1) . 'x' . number_format((float)$record['dimension_w'], 1) . 'x' . number_format((float)$record['dimension_h'], 1)
                      : '-';
        $calculated_strength = ($record['compressive_strength_mpa'] !== null) ? number_format((float)$record['compressive_strength_mpa'], 2) : '';
        $status_text = $record['is_rejected'] ? 'مردود' : 'قبول';
        $row_class = $record['is_rejected'] ? ' class="rejected"' : ''; // Add class for rejected rows

        $html .= '<tr' . $row_class . '>';
        $html .= '<td>' . htmlspecialchars(!empty($prod_shamsi) ? $prod_shamsi : '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($record['sample_code'] ?? '-') . '</td>';
        $html .= '<td>' . htmlspecialchars(!empty($break_shamsi) ? $break_shamsi : '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($record['sample_age_at_break'] ?? '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($dimensions) . '</td>';
        $html .= '<td>' . htmlspecialchars(($record['max_force_kg'] !== null) ? number_format((float)$record['max_force_kg'], 2) : '') . '</td>';
        $html .= '<td>' . htmlspecialchars($calculated_strength) . '</td>';
        $html .= '<td>' . htmlspecialchars(($record['strength_1_day'] !== null) ? number_format((float)$record['strength_1_day'], 2) : '') . '</td>';
        $html .= '<td>' . htmlspecialchars(($record['strength_7_day'] !== null) ? number_format((float)$record['strength_7_day'], 2) : '') . '</td>';
        $html .= '<td>' . htmlspecialchars(($record['strength_28_day'] !== null) ? number_format((float)$record['strength_28_day'], 2) : '') . '</td>';
        $html .= '<td>' . htmlspecialchars($status_text) . '</td>';
        $html .= '<td>' . htmlspecialchars($record['rejection_reason'] ?? '') . '</td>';
        $html .= '</tr>';
    }
}
$html .= '</tbody></table>';

// Write HTML to PDF
$pdf->writeHTML($html, true, false, true, false, '');

// --- Output PDF ---
$filename = "concrete_tests_" . date('Ymd_His') . ".pdf";
ob_end_clean(); // Clear buffer
$pdf->Output($filename, 'D'); // Force download
exit;
?>