<?php
// export_dashboard_pdf.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once 'includes/jdf.php';
require_once 'includes/functions.php';
require_once('includes/libraries/TCPDF-main/tcpdf.php');
secureSession();
$expected_project_key = 'arad'; // HARDCODED FOR THIS FILE
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
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$allroles = ['admin', 'supervisor', 'planner', 'cnc_operator', 'superuser', 'user'];
$authroles = ['admin', 'supervisor', 'superuser'];
$readonlyroles = ['planner', 'cnc_operator', 'user'];

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $allroles)) {
    header('Location: login.php');
    exit('Access Denied.');
}
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/concrete_tests.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// --- Status Definition Variables (COPY FROM dashboard.php) ---
$statusCaseSql = "
    CASE
        WHEN packing_status = 'shipped' THEN 'shipped'
        WHEN status = 'completed' THEN 'completed' -- Check explicit completed status first
        WHEN assembly_end_time IS NOT NULL THEN 'Assembly' -- Then check latest step completion
        WHEN concrete_end_time IS NOT NULL THEN 'Concreting'
        WHEN mesh_end_time IS NOT NULL THEN 'Mesh'
        WHEN polystyrene = 1 THEN 'polystyrene'     -- Then check intermediate steps
        WHEN status = 'pending' THEN 'pending'       -- Explicit pending
        WHEN assigned_date IS NOT NULL THEN 'pending' -- Default assigned but not started to pending
        ELSE 'pending'                                -- Default everything else to pending
    END
";
$allStatuses = ['pending', 'polystyrene', 'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped'];
$persianLabels = ['آغاز نشده', 'قالب فوم', 'مش بندی', 'قالب‌بندی/بتن‌ریزی', 'فیس کوت', 'تکمیل', 'حمل به کارگاه'];

// --- Helper Functions (COPY FROM dashboard.php) ---

// --- getFilteredData Function (COPY THE EXACT FUNCTION from dashboard.php) ---
function getFilteredData($pdo, $statusFilter, $dateFrom, $dateTo, $priority)
{
    global $statusCaseSql; // Access the global variable

    $dateFrom = convertPersianToGregorian($dateFrom);
    $dateTo = convertPersianToGregorian($dateTo);

    $conditions = [];
    $params = [];

    // Date conditions
    if ($dateFrom) {
        $conditions[] = "assigned_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $conditions[] = "assigned_date <= ?";
        $params[] = $dateTo;
    }

    // Priority condition
    if ($priority !== null && $priority !== 'all' && $priority !== '') {
        if ($priority === 'other') {
            $conditions[] = "(Proritization IS NULL OR Proritization = '')";
            // No parameter needed
        } else {
            $conditions[] = "Proritization = ?";
            $params[] = $priority;
        }
    }

    // Status condition (applied *after* grouping if filtering by calculated status)
    $havingCondition = "";
    if ($statusFilter !== null && $statusFilter !== 'all' && $statusFilter !== '') {
        $havingCondition = "HAVING current_status = ?"; // Use HAVING for aggregated/calculated columns
        $params[] = $statusFilter; // Add status to params *last*
    }


    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

    $sql = "SELECT ({$statusCaseSql}) as current_status, COUNT(*) as count
            FROM hpc_panels
            {$whereClause}
            GROUP BY current_status
            {$havingCondition}"; // Apply HAVING clause if needed

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getFilteredData: " . $e->getMessage() . " SQL: " . $sql . " Params: " . print_r($params, true));
        return []; // Return empty on error
    }
}
// --- END COPIED CODE ---

// Get Filters from GET parameters
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : null;
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : null;
$priority = isset($_GET['priority']) ? $_GET['priority'] : 'all';

// Fetch the filtered summary data
$filteredData = getFilteredData($pdo, $status, $dateFrom, $dateTo, $priority);

// Process into counts
$statusCounts = array_fill_keys($allStatuses, 0);
$totalCount = 0;
foreach ($filteredData as $row) {
    if (isset($statusCounts[$row['current_status']])) {
        $count = (int)$row['count'];
        $statusCounts[$row['current_status']] = $count;
        $totalCount += $count;
    }
}

// --- Create PDF ---
class MYPDF extends TCPDF
{
    public function Header()
    {
        $this->SetFont('dejavusans', 'B', 12);
        $this->Cell(0, 10, 'خلاصه داشبورد پنل‌ها', 0, false, 'C');
        $this->Ln(5);
        $this->SetFont('dejavusans', '', 8);
        $this->Cell(0, 10, 'تاریخ گزارش: ' . gregorianToShamsi(date('Y-m-d')) . ' ' . date('H:i:s'), 0, false, 'C');
    }
    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('dejavusans', 'I', 8);
        $this->Cell(0, 10, 'صفحه ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C');
    }
}

$pdf = new MYPDF('P', PDF_UNIT, 'A4', true, 'UTF-8', false); // Portrait
$pdf->SetCreator('Your App');
$pdf->SetAuthor($_SESSION['first_name'] ?? 'User');
$pdf->SetTitle('Dashboard Summary');
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetMargins(15, 25, 15); // L, T, R (More top margin for header)
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(TRUE, 25); // Bottom margin
$pdf->SetFont('dejavusans', '', 10);
$pdf->setRTL(true);
$pdf->AddPage();

// --- Build HTML Content ---
$html = '<h2>خلاصه وضعیت پنل‌ها (فیلتر شده)</h2>';

// Display Filters Applied
$filterDesc = "<b>فیلترهای اعمال شده:</b> ";
$filtersApplied = [];
if ($status != 'all') {
    $filtersApplied[] = "وضعیت: " . ($persianLabels[array_search($status, $allStatuses)] ?? htmlspecialchars($status));
}
if ($priority != 'all') {
    $filtersApplied[] = "اولویت: " . htmlspecialchars($priority);
}
if ($dateFrom) {
    $filtersApplied[] = "از تاریخ: " . htmlspecialchars(gregorianToShamsi($dateFrom));
}
if ($dateTo) {
    $filtersApplied[] = "تا تاریخ: " . htmlspecialchars(gregorianToShamsi($dateTo));
}
if (empty($filtersApplied)) {
    $filtersApplied[] = "هیچ فیلتری اعمال نشده";
}
$html .= '<p style="font-size: 9pt; color: #333;">' . implode(' | ', $filtersApplied) . '</p>';


$html .= '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width: 90%; margin: 15px auto;">';
$html .= '<thead style="background-color:#eaeaea; font-weight:bold;"><tr><th style="width:60%;">وضعیت</th><th style="width:40%;">تعداد</th></tr></thead>';
$html .= '<tbody>';

foreach ($allStatuses as $key) {
    $label = $persianLabels[array_search($key, $allStatuses)] ?? $key;
    $count = $statusCounts[$key] ?? 0;
    $html .= '<tr><td>' . htmlspecialchars($label) . '</td><td>' . number_format($count) . '</td></tr>';
}
// Add total row
$html .= '<tr style="font-weight:bold; background-color:#f0f0f0;"><td>مجموع</td><td>' . number_format($totalCount) . '</td></tr>';
$html .= '</tbody></table>';

// --- Write HTML to PDF ---
$pdf->writeHTML($html, true, false, true, false, '');

// --- Output PDF ---
$filename = "dashboard_summary_" . date('Ymd_His') . ".pdf";
ob_end_clean();
$pdf->Output($filename, 'D'); // Force download
exit;
