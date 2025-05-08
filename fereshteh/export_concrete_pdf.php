<?php
// export_concrete_pdf.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();


// --- Manual TCPDF Include ---
require_once('includes/libraries/TCPDF-main/tcpdf.php'); 


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
    logError("DB Connection failed in {$expected_project_key}/export_concrete_pdf.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
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
$pdf->SetCreator(PDF_CREATOR); $pdf->SetAuthor('Alumglass'); $pdf->SetTitle('Concrete Tests Report');

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