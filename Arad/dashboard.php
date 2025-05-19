<?php

//dashboard.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once 'includes/jdf.php';
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


$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/dashboard.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// Get user role and permissions
$allowed_roles = ['admin', 'supervisor', 'planner', 'cnc_operator', 'superuser', 'user'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    logError("Unauthorized role '{$_SESSION['role']}' attempt on {$expected_project_key}/dashboard.php. User: {$_SESSION['user_id']}");
    header('Location: dashboard.php?msg=unauthorized'); // Redirect within project
    exit();
}

$pageTitle = "داشبورد گزارشات";
$today = jdate('Y/m/d');

// Fetch total panels count
$stmt = $pdo->query("SELECT COUNT(*) FROM hpc_panels");
$totalPanels = $stmt->fetchColumn();

// Fetch panels in progress (not completed and not shipped)
$stmt = $pdo->query("SELECT COUNT(*) FROM hpc_panels WHERE status != 'completed' AND status != 'pending' AND packing_status !='shipped'");
$inProgressPanels = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM hpc_panels WHERE status != 'pending' AND concrete_end_time IS NOT NULL");
$concretepanels = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(area) FROM hpc_panels WHERE status != 'pending' AND concrete_end_time IS NOT NULL");
$concretepanelsarea = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM hpc_panels WHERE status != 'pending' AND assembly_end_time IS NOT NULL");
$facecoutpanels = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(area) FROM hpc_panels WHERE status != 'pending' AND assembly_end_time IS NOT NULL");
$facecoutpanelsarea = $stmt->fetchColumn();
// Fetch panels in progress (not completed and not shipped)
$stmt = $pdo->query("SELECT COUNT(*) FROM hpc_panels WHERE  status = 'pending' AND packing_status !='shipped'");
$pendingPanels = $stmt->fetchColumn();
//Fetch panels in assigned date 
$stmt = $pdo->query("SELECT COUNT(*) FROM hpc_panels WHERE status = 'pending' AND assigned_date IS NOT NULL");
$assignedpanels = $stmt->fetchColumn();
// Fetch completed panels
$stmt = $pdo->query("SELECT COUNT(*) FROM hpc_panels WHERE status = 'completed'");
$completedPanels = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT SUM(area) FROM hpc_panels WHERE status = 'completed'");
$completedPanelsarea = $stmt->fetchColumn();



// Fetch shipped panels
$stmt = $pdo->query("SELECT COUNT(*) FROM hpc_panels WHERE packing_status = 'shipped'");
$shippedPanels = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT SUM(area) FROM hpc_panels WHERE packing_status = 'shipped'");
$areashippedPanels = $stmt->fetchColumn();
$existPanels = $concretepanels - $shippedPanels;
$statusCaseSql = "
    CASE
        WHEN packing_status = 'shipped' THEN 'shipped'
        WHEN status = 'completed' THEN 'completed' -- Check explicit completed status first
        WHEN assembly_end_time IS NOT NULL THEN 'Assembly' -- Then check latest step completion
        WHEN concrete_end_time IS NOT NULL THEN 'Concreting'
        WHEN mesh_end_time IS NOT NULL THEN 'Mesh'
        WHEN status = 'pending' AND assigned_date IS NULL THEN 'pending'       -- Explicit pending
        WHEN status = 'pending' AND assigned_date IS NOT NULL THEN 'assign' -- Default assigned but not started to pending
              -- Default everything else to pending
    END
";
$statusCaseSqlF = "
    CASE
        WHEN packing_status = 'shipped' THEN 'shipped'
        WHEN status = 'completed' THEN 'completed' -- Check explicit completed status first
        WHEN assembly_end_time IS NOT NULL THEN 'Assembly' -- Then check latest step completion
        WHEN concrete_end_time IS NOT NULL THEN 'Concreting'
        WHEN mesh_end_time IS NOT NULL THEN 'Mesh'
        
        WHEN status = 'pending' AND assigned_date IS NOT NULL THEN 'assign' -- Default assigned but not started to pending
              -- Default everything else to pending
    END
";

$currentStatusSql = "
    CASE
        WHEN packing_status = 'shipped' THEN 'shipped'
        WHEN status = 'completed' THEN 'completed' -- Explicitly completed takes precedence
        WHEN assembly_end_time IS NULL AND assembly_start_time IS NOT NULL THEN 'Assembly' -- Currently in Assembly
        WHEN concrete_end_time IS NULL AND concrete_start_time IS NOT NULL THEN 'Concreting' -- Currently in Concreting
        WHEN mesh_end_time IS NULL AND mesh_start_time IS NOT NULL THEN 'Mesh' -- Currently in Mesh
        WHEN status = 'pending' AND assigned_date IS NULL THEN 'pending'
        WHEN status = 'pending' AND assigned_date IS NOT NULL THEN 'assign' -- Default for assigned but not started/flagged
        
    END
";
/* $statusLabels = [
    'pending' => 'در انتظار',

    'mesh' => 'مش بندی',
    'Concreting' => 'قالب‌بندی/بتن ریزی',
    'Assembly' => 'فیس کوت',
    'completed' => 'تکمیل شده',
]; */
$statusLabels =  ['برنامه‌ریزی نشده', 'برنامه‌ریزی شده', 'مش بندی', 'قالب‌بندی/بتن‌ریزی', 'فیس کوت', 'تکمیل', 'حمل به کارگاه'];
$persianLabels = ['برنامه‌ریزی نشده', 'برنامه‌ریزی شده', 'مش بندی', 'قالب‌بندی/بتن‌ریزی', 'فیس کوت', 'تکمیل', 'حمل به کارگاه'];
$statusCounts = [];
$allStatuses = ['pending', 'assign',  'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped'];
$statusColors = [
    'pending' => '#bdc3c7',
    'assign' => '#95a5a6',
    'mesh' => '#9b59b6',
    'Concreting' => '#e74c3c',
    'Assembly' => '#f1c40f',
    'completed' => '#2ecc71',
    'shipped' => '#2980b9',
];
$statusCountsData = []; // Use an associative array
try {
    $sql = "SELECT ({$statusCaseSql}) as current_status, COUNT(*) as count
            FROM hpc_panels
            GROUP BY current_status";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Initialize counts for all target statuses
    $statusCountsData = array_fill_keys($allStatuses, 0);

    // Populate with actual counts
    foreach ($rows as $row) {
        if (isset($statusCountsData[$row['current_status']])) {
            $statusCountsData[$row['current_status']] = (int)$row['count'];
        }
        // You might want to log or handle unexpected statuses here
    }
    $statusChartCounts = [];
    foreach ($allStatuses as $statusKey) { // Iterate using the defined order
        $statusChartCounts[] = $statusCountsData[$statusKey] ?? 0;
    }
} catch (PDOException $e) {
    error_log("Error fetching status distribution data: " . $e->getMessage());
    $statusCountsData = array_fill_keys($allStatuses, 0); // Fallback to zeros
}

// Prepare data for the pie chart JS (ensure order matches $persianLabels)
$statusChartCounts = [];
foreach ($allStatuses as $statusKey) {
    $statusChartCounts[] = $statusCountsData[$statusKey] ?? 0;
}
// --- End Status Distribution ---

// --- Monthly Production Chart Data (Corrected) ---
// Define the current Persian year and month labels.
$months = [

    ['label' => 'فروردین', 'start' => '2025-03-21', 'end' => '2025-04-19'],
    ['label' => 'اردیبهشت', 'start' => '2025-04-20', 'end' => '2025-05-19'],
    ['label' => 'خرداد',  'start' => '2025-05-20', 'end' => '2025-06-18'],
    ['label' => 'تیر',    'start' => '2025-06-19', 'end' => '2025-07-18'],
    ['label' => 'مرداد',  'start' => '2025-07-19', 'end' => '2025-08-17'],
    ['label' => 'شهریور', 'start' => '2025-08-18', 'end' => '2025-09-16'],
    ['label' => 'مهر',    'start' => '2025-09-17', 'end' => '2025-10-16'],
    ['label' => 'آبان',   'start' => '2025-10-17', 'end' => '2025-11-15'],
    ['label' => 'آذر',    'start' => '2025-11-16', 'end' => '2025-12-15'],
    ['label' => 'دی',     'start' => '2025-12-16', 'end' => '2026-01-14'],

];
$persianMonthsDefinition = [
    ['label' => 'فروردین', 'start' => '2025-03-21', 'end' => '2025-04-20'], // 31 days
    ['label' => 'اردیبهشت', 'start' => '2025-04-21', 'end' => '2025-05-21'], // 31 days
    ['label' => 'خرداد',  'start' => '2025-05-22', 'end' => '2025-06-21'], // 31 days
    ['label' => 'تیر',    'start' => '2025-06-22', 'end' => '2025-07-22'], // 31 days
    ['label' => 'مرداد',  'start' => '2025-07-23', 'end' => '2025-08-22'], // 31 days
    ['label' => 'شهریور', 'start' => '2025-08-23', 'end' => '2025-09-22'], // 31 days
    ['label' => 'مهر',    'start' => '2025-09-23', 'end' => '2025-10-22'], // 30 days
    ['label' => 'آبان',   'start' => '2025-10-23', 'end' => '2025-11-21'], // 30 days
    ['label' => 'آذر',    'start' => '2025-11-22', 'end' => '2025-12-21'], // 30 days
    ['label' => 'دی',     'start' => '2025-12-22', 'end' => '2026-01-20'], // 30 days (Year changes!)
    ['label' => 'بهمن',   'start' => '2026-01-21', 'end' => '2026-02-19'], // 30 days
    ['label' => 'اسفند',  'start' => '2026-02-20', 'end' => '2026-03-20'], // Usually 29 days, 30 in leap year. This end date is for non-leap. Adjust if needed.
];
// The steps we want to track
$steps = ['pending', 'assign', 'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped'];

// Prepare arrays to hold the final counts
$monthLabels = []; // Persian month labels (e.g. بهمن, اسفند, ...)
$monthlyData = [];
foreach ($steps as $step) {
    $monthlyData[$step] = [];
}

foreach ($persianMonthsDefinition as $m) { // Use the new definition array
    // Store the month label (e.g. بهمن, اسفند, ...)
    $monthLabels[] = $m['label'];

    // Build one query that calculates all step counts within this range
    $sql = "
            SELECT
                SUM(CASE WHEN status = 'pending' AND assigned_date IS NULL THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'pending' AND assigned_date IS NOT NULL THEN 1 ELSE 0 END) AS assign,
                SUM(CASE WHEN assembly_end_time IS NOT NULL THEN 1 ELSE 0 END) AS Assembly,
                SUM(CASE WHEN mesh_end_time IS NOT NULL THEN 1 ELSE 0 END) AS Mesh,
                SUM(CASE WHEN concrete_end_time IS NOT NULL THEN 1 ELSE 0 END) AS Concreting,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN packing_status = 'shipped' THEN 1 ELSE 0 END) AS shipped
            FROM hpc_panels
            WHERE assigned_date >= :start
            AND assigned_date <= :end
        ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start' => $m['start'],
        ':end'   => $m['end']
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Ensure counts are not smaller than the completed count
    $completedCount = (int)($row['completed'] ?? 0);
    foreach (['Mesh', 'Concreting', 'Assembly'] as $step) {
        if ((int)($row[$step] ?? 0) < $completedCount) {
            $row[$step] = $completedCount;
        }
    }

    // For each step, push the count into the monthlyData array
    foreach ($steps as $step) {
        $monthlyData[$step][] = (int)($row[$step] ?? 0);
    }
}
$productionChartLabels = [];
$productionChartData = []; // This will be structured differently now {step => [day1_count, day2_count...]}


// Average processing time for each phase
$timeData = [
    // Define keys for phases you CAN calculate
    'mesh_time' => 0,       // Time from polystyrene=1 to mesh_end_time
    'concreting_time' => 0, // Time from mesh_end_time to concrete_end_time
    'assembly_time' => 0,   // Time from concrete_end_time to assembly_end_time
    'completion_time' => 0, // Time from assembly_end_time to status='completed' (using updated_at)
    'total_production_time' => 0 // Time from assigned_date to status='completed' (using updated_at)
];

try {

    // ASSUMPTION: 'updated_at' column reflects when status was set to 'completed'. Adjust if wrong.

    $queries = [

        'mesh_time' => "SELECT AVG(TIMESTAMPDIFF(SECOND, assigned_date, mesh_end_time)) FROM hpc_panels WHERE mesh_end_time IS NOT NULL AND mesh_end_time >= assigned_date", // Using assigned_date as proxy start
        'concreting_time' => "SELECT AVG(TIMESTAMPDIFF(SECOND, assigned_date, concrete_end_time)) FROM hpc_panels WHERE mesh_end_time IS NOT NULL AND concrete_end_time IS NOT NULL AND concrete_end_time >= assigned_date",
        'assembly_time' => "SELECT AVG(TIMESTAMPDIFF(SECOND, concrete_end_time, assembly_end_time)) FROM hpc_panels WHERE concrete_end_time IS NOT NULL AND assembly_end_time IS NOT NULL AND assembly_end_time >= concrete_end_time",
        'completion_time' => "SELECT AVG(TIMESTAMPDIFF(SECOND, assigned_date, (SELECT MAX(updated_at) FROM hpc_panel_details WHERE hpc_panel_details.panel_id = hpc_panels.id AND hpc_panel_details.status = 'completed'))) FROM hpc_panels WHERE assembly_end_time IS NOT NULL AND assigned_date IS NOT NULL", // Time from Assembly End to Newest Updated_at in hpc_panel_details
        'total_production_time' => "SELECT AVG(TIMESTAMPDIFF(SECOND, assigned_date, assembly_end_time)) FROM hpc_panels WHERE assigned_date IS NOT NULL AND assembly_end_time >= assigned_date" // Total time Assigned -> Completed
    ];

    foreach ($queries as $key => $sql) {
        $stmt = $pdo->query($sql);
        if ($stmt) {
            $avgSeconds = (float)$stmt->fetchColumn();
            // Convert seconds to days (or hours/minutes as needed)
            $timeData[$key] = ($avgSeconds > 0) ? round($avgSeconds / (60 * 60 * 24), 3) : 0; // Convert to Days
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching time data: " . $e->getMessage());
}


// --- Weekly Production Chart Data (Corrected - Again!) ---
$weeklyProduction = ['labels' => [], 'completed' => [], 'in_progress' => []];
$currentDate = new DateTime();  // Start with *today*
$startDate = clone $currentDate;
$startDate->modify('-6 weeks'); // Go back 6 weeks

while ($startDate < $currentDate) {
    $weekNumber = (int)$startDate->format('W'); // Get week number
    $year = (int)$startDate->format('Y');      // Get *Gregorian* year

    // Calculate start of the week (Monday) using setISODate
    $startOfWeek = clone $startDate;
    $startOfWeek->setISODate($year, $weekNumber);
    $weekLabel = jdate('Y/m/d', $startOfWeek->getTimestamp()); // Jalali label

    // Only add the week if it's not already in the labels
    if (!in_array($weekLabel, $weeklyProduction['labels'])) {
        $weeklyProduction['labels'][] = $weekLabel;

        // Calculate end of the week (Sunday)
        $endOfWeek = clone $startOfWeek;
        $endOfWeek->modify('+6 days');  // Add 6 days to get to Sunday

        $startOfWeekSQL = $startOfWeek->format('Y-m-d');
        $endOfWeekSQL = $endOfWeek->format('Y-m-d');

        // completed count for the week
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM hpc_panels WHERE status = 'completed' AND assigned_date >= ? AND assigned_date <= ?");
        $stmt->execute([$startOfWeekSQL, $endOfWeekSQL]);
        $weeklyProduction['completed'][] = (int)$stmt->fetchColumn();

        // In-progress count (excluding 'pending', 'completed', 'shipped')
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM hpc_panels WHERE status NOT IN ('pending','completed') AND assigned_date >= ? AND assigned_date <= ?");
        $stmt->execute([$startOfWeekSQL, $endOfWeekSQL]);
        $weeklyProduction['in_progress'][] = (int)$stmt->fetchColumn();
    }
    $startDate->modify('+1 day'); // go to next day
}


// --- Step Counts ---
// --- Step Progress Counts (Corrected) ---
$stepProgressCounts = array_fill_keys($allStatuses, 0); // Use target status keys
try {
    // Query counts for panels having reached each stage
    $stmt = $pdo->query("
        SELECT
            SUM(CASE WHEN status = 'pending' AND assigned_date IS NULL THEN 1 ELSE 0 END) as pending_reached, -- All panels are at least pending
            SUM(CASE WHEN status = 'pending' AND assigned_date IS NOT NULL THEN 1 ELSE 0 END) as assign_reached,
            SUM(CASE WHEN mesh_end_time IS NOT NULL OR {$statusCaseSql} IN ('Concreting', 'Assembly', 'completed', 'shipped') THEN 1 ELSE 0 END) as Mesh_reached,
            SUM(CASE WHEN concrete_end_time IS NOT NULL OR {$statusCaseSql} IN ('Assembly', 'completed', 'shipped') THEN 1 ELSE 0 END) as Concreting_reached,
            SUM(CASE WHEN assembly_end_time IS NOT NULL OR {$statusCaseSql} IN ('completed', 'shipped') THEN 1 ELSE 0 END) as Assembly_reached,
            SUM(CASE WHEN status = 'completed' OR {$statusCaseSql} = 'shipped' THEN 1 ELSE 0 END) as completed_reached,
            SUM(CASE WHEN packing_status = 'shipped' THEN 1 ELSE 0 END) as shipped_reached
        FROM hpc_panels
    ");
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($counts) {
        $stepProgressCounts['pending']     = (int)$counts['pending_reached'];
        $stepProgressCounts['assign']     = (int)$counts['assign_reached'];
        $stepProgressCounts['Mesh']        = (int)$counts['Mesh_reached'];
        $stepProgressCounts['Concreting']  = (int)$counts['Concreting_reached'];
        $stepProgressCounts['Assembly']    = (int)$counts['Assembly_reached'];
        $stepProgressCounts['completed']   = (int)$counts['completed_reached'];
        $stepProgressCounts['shipped']     = (int)$counts['shipped_reached'];
    }
    $stepChartData = [];
    foreach ($allStatuses as $statusKey) { // Iterate using the defined order
        $stepChartData[] = $stepProgressCounts[$statusKey] ?? 0;
    }
} catch (PDOException $e) {
    error_log("Error fetching step progress data: " . $e->getMessage());
    // Keep counts as 0
}

// Prepare data for the chart JS (ensure order matches $persianLabels)
$stepChartData = [];
foreach ($allStatuses as $statusKey) {
    $stepChartData[] = $stepProgressCounts[$statusKey] ?? 0;
}
// --- End Step Progress Counts ---

function convertPersianToGregorian($date)
{
    $persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $gregorianNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($persianNumbers, $gregorianNumbers, $date);
}



function getFilteredData($pdo, $statusFilter, $dateFrom, $dateTo, $priority)
{
    global $statusCaseSqlF; // Access the global variable

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

    $sql = "SELECT ({$statusCaseSqlF}) as current_status, COUNT(*) as count
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
function getLineChartData($pdo, $dateFrom, $dateTo, $priority)
{
    global $statusCaseSql; // Access global variables
    $persianLabels = ['برنامه‌ریزی شده',  'مش بندی', 'قالب‌بندی/بتن‌ریزی', 'فیس کوت', 'تکمیل', 'حمل به کارگاه'];
    $statusCounts = [];
    $allStatuses = ['assign', 'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped'];
    $statusColors = [

        'assign' => '#95a5a6',
        'mesh' => '#9b59b6',
        'Concreting' => '#e74c3c',
        'Assembly' => '#f1c40f',
        'completed' => '#2ecc71',
        'shipped' => '#2980b9',
    ];
    $dateFrom = convertPersianToGregorian($dateFrom);
    $dateTo = convertPersianToGregorian($dateTo);

    $conditions = [];
    $params = []; // Use named parameters

    // Date conditions (MANDATORY for this query)
    if (!$dateFrom || !$dateTo) {
        error_log("Daily Line chart requires both start and end dates.");
        return []; // Return empty if dates are missing
    }
    $conditions[] = "assigned_date >= :dateFrom";
    $params[':dateFrom'] = $dateFrom;
    $conditions[] = "assigned_date <= :dateTo";
    $params[':dateTo'] = $dateTo;

    // Priority condition
    if ($priority !== null && $priority !== 'all' && $priority !== '') {
        if ($priority === 'other') {
            $conditions[] = "(Proritization IS NULL OR Proritization = '')";
        } else {
            $conditions[] = "Proritization = :priority";
            $params[':priority'] = $priority;
        }
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

    // Pivot the data directly in SQL using named parameters for statuses
    $selectCases = [];
    foreach ($allStatuses as $sKey) {
        $paramName = ":status_" . str_replace(['-', ' '], '_', $sKey); // Ensure safe param name
        $selectCases[] = "SUM(CASE WHEN ({$statusCaseSql}) = {$paramName} THEN 1 ELSE 0 END) as `{$sKey}`";
        $params[$paramName] = $sKey;
    }
    $selectClause = implode(",\n", $selectCases);

    $sql = "SELECT
                DATE(assigned_date) as day,
                {$selectClause}
            FROM hpc_panels
            {$whereClause}
            GROUP BY DATE(assigned_date)
            ORDER BY day ASC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $dailyRawData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Reformat for easier JS consumption (optional but can be helpful)
        $chartJsData = []; // [status => [{x: date, y: count}]]
        foreach ($allStatuses as $statusKey) {
            $chartJsData[$statusKey] = [];
            foreach ($dailyRawData as $row) {
                $chartJsData[$statusKey][] = [
                    'x' => $row['day'], // Date string
                    'y' => (int)($row[$statusKey] ?? 0) // Daily count for this status
                ];
            }
        }
        return $chartJsData; // Return formatted data

    } catch (PDOException $e) {
        error_log("Error in getLineChartData (Daily): " . $e->getMessage() . " SQL: " . $sql . " Params: " . print_r($params, true));
        return [];
    }
}

function getCumulativeStepCountsFiltered($pdo, $dateFrom, $dateTo, $priority)
{
    $persianLabels = ['برنامه‌ریزی شده', 'مش بندی', 'قالب‌بندی/بتن‌ریزی', 'فیس کوت', 'تکمیل', 'حمل به کارگاه'];
    $statusCounts = [];
    $allStatuses = ['assign', 'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped'];
    $statusColors = [

        'assign' => '#95a5a6',

        'mesh' => '#9b59b6',
        'Concreting' => '#e74c3c',
        'Assembly' => '#f1c40f',
        'completed' => '#2ecc71',
        'shipped' => '#2980b9',
    ];

    $dateFrom = convertPersianToGregorian($dateFrom);
    $dateTo = convertPersianToGregorian($dateTo);

    $conditions = [];
    $params = [];

    // Date conditions
    if ($dateFrom) {
        $conditions[] = "assigned_date >= :dateFrom";
        $params[':dateFrom'] = $dateFrom;
    }
    if ($dateTo) {
        $conditions[] = "assigned_date <= :dateTo";
        $params[':dateTo'] = $dateTo;
    }
    // Priority condition
    if ($priority !== null && $priority !== 'all' && $priority !== '') {
        if ($priority === 'other') {
            $conditions[] = "(Proritization IS NULL OR Proritization = '')";
        } else {
            $conditions[] = "Proritization = :priority";
            $params[':priority'] = $priority;
        }
    }
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

    $stepProgressCounts = array_fill_keys($allStatuses, 0);
    try {
        // Re-use the logic from the initial step count calculation, but apply the WHERE clause
        $sql = "
            SELECT
                
                SUM(CASE WHEN status = 'pending' AND assigned_date IS NOT NULL THEN 1 ELSE 0 END) as assign_reached,
                SUM(CASE WHEN mesh_end_time IS NOT NULL THEN 1 ELSE 0 END) as Mesh_reached,
                SUM(CASE WHEN concrete_end_time IS NOT NULL  THEN 1 ELSE 0 END) as Concreting_reached,
                SUM(CASE WHEN assembly_end_time IS NOT NULL THEN 1 ELSE 0 END)as Assembly_reached,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_reached,
                SUM(CASE WHEN packing_status = 'shipped' THEN 1 ELSE 0 END) as shipped_reached
            FROM hpc_panels
            {$whereClause}
         ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($counts) {
            // Map results to standard keys

            $stepProgressCounts['assign']     = (int)$counts['assign_reached'];
            $stepProgressCounts['Mesh']        = (int)$counts['Mesh_reached'];
            $stepProgressCounts['Concreting']  = (int)$counts['Concreting_reached'];
            $stepProgressCounts['Assembly']    = (int)$counts['Assembly_reached'];
            $stepProgressCounts['completed']   = (int)$counts['completed_reached'];
            $stepProgressCounts['shipped']     = (int)$counts['shipped_reached'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching filtered cumulative step data: " . $e->getMessage());
    }
    // Return data ordered by $allStatuses
    $orderedData = [];
    foreach ($allStatuses as $key) {
        $orderedData[] = $stepProgressCounts[$key] ?? 0;
    }
    return $orderedData; // Return just the array of counts
}


// --- Assigned Per Day ---
$stmt = $pdo->prepare("SELECT assigned_date, COUNT(*) as count FROM hpc_panels WHERE assigned_date IS NOT NULL GROUP BY assigned_date ORDER BY assigned_date");
$stmt->execute();
$assignedPerDay = ['labels' => [], 'data' => []];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $assignedPerDay['labels'][] = jdate('Y/m/d', strtotime($row['assigned_date'])); // Jalali
    $assignedPerDay['data'][] = (int)$row['count'];
}




// Handle AJAX filter requests  (Keep this, but it's not used for initial load)
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($isAjax && isset($_GET['action']) && $_GET['action'] == 'get_daily_month_data' && isset($_GET['month_index'])) {
    $monthIndex = (int)$_GET['month_index'];

    // Validate month index
    if ($monthIndex >= 0 && $monthIndex < count($persianMonthsDefinition)) {
        $selectedMonth = $persianMonthsDefinition[$monthIndex];
        $startDate = $selectedMonth['start'];
        $endDate = $selectedMonth['end'];

        // The steps we want to track daily
        $steps = ['assign',  'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped']; // Match JS steps if different

        // --- The Simple Query for Daily Data ---
        // Which date defines the "production" for a day?
        // Let's assume 'assigned_date' for simplicity, but you might want concrete_end_time or assembly_end_time
        // Adjust the DATE() function and WHERE clause field accordingly.
        $sql = "
            SELECT
                DATE(assigned_date) as day,
                SUM(CASE WHEN status = 'pending' AND assigned_date IS NOT NULL THEN 1 ELSE 0 END) AS assign_count,
                SUM(CASE WHEN mesh_end_time IS NOT NULL THEN 1 ELSE 0 END) AS Mesh_count,
                SUM(CASE WHEN concrete_end_time IS NOT NULL THEN 1 ELSE 0 END) AS Concreting_count,
                SUM(CASE WHEN assembly_end_time IS NOT NULL THEN 1 ELSE 0 END) AS Assembly_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN packing_status = 'shipped' THEN 1 ELSE 0 END) AS shipped_count
            FROM hpc_panels
            WHERE assigned_date >= :start_date AND assigned_date <= :end_date
            GROUP BY DATE(assigned_date)
            ORDER BY day ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
        $dailyRawData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- Process data for the chart ---
        $chartLabels = []; // Persian day numbers: ['۱', '۲', ..., '۳۱']
        $chartDatasets = []; // [{label: 'Step', data: [day1, day2,...]}, ...]

        // Initialize datasets structure
        $stepLabels = ['برنامه‌ریزی شده', 'مش بندی', 'قالب‌بندی/بتن‌ریزی', 'فیس کوت', 'تکمیل', 'حمل به کارگاه']; // Match JS if needed
        $stepColors = [ // Match the colors used in JS for consistency
            'assign' => '#95a5a6',
            'Mesh' => '#9b59b6',
            'Concreting' => '#e74c3c',
            'Assembly' => '#f1c40f',
            'completed' => '#2ecc71',
            'shipped' => '#2980b9',
        ];
        $tempDataHolder = [];
        foreach ($steps as $index => $stepKey) {
            $tempDataHolder[$stepKey] = []; // Will store counts per day for this step
            $chartDatasets[$index] = [
                'label' => $stepLabels[$index] ?? $stepKey, // Use Persian label
                'data' => [],
                'borderColor' => $stepColors[$stepKey] ?? '#cccccc',
                'fill' => false,
                'tension' => 0.1
            ];
        }

        // Create a map of Gregorian date -> counts from the query
        $dbResultsMap = [];
        foreach ($dailyRawData as $row) {
            $dbResultsMap[$row['day']] = $row;
        }

        // Iterate through the date range of the selected month
        $current = new DateTime($startDate);
        $end = new DateTime($endDate);

        $interval = new DateInterval('P1D');
        $period = new DatePeriod($current, $interval, $end->modify('+1 day')); // Use DatePeriod for clarity
        foreach ($period as $dt) { // Loop using DatePeriod object
            $gregorianDateStr = $dt->format('Y-m-d');

            // ***** CORRECTED LABEL GENERATION *****
            // Use jdate with 'Y/m/d' format for the full date
            $fullPersianDate = jdate('Y/m/d', $dt->getTimestamp());
            $chartLabels[] = $fullPersianDate; // Add the FULL Persian date to labels
            // ***** END CORRECTION *****

            // Get counts for this day (or 0 if no data) (Keep this part as is)
            $dayData = $dbResultsMap[$gregorianDateStr] ?? null;

            // Populate data for each step's dataset (Keep this part as is)
            foreach ($steps as $index => $stepKey) {
                $countKey = $stepKey . '_count';
                $chartDatasets[$index]['data'][] = $dayData ? (int)$dayData[$countKey] : 0;
            }

            // No need to manually modify $current inside the loop when using DatePeriod
        }

        // --- Prepare response ---
        $response = [
            'success' => true,
            'labels' => $chartLabels,
            'datasets' => $chartDatasets,
            'monthLabel' => $selectedMonth['label'] // Send back month name for title update
        ];
    } else {
        // Invalid month index
        $response = ['success' => false, 'message' => 'Invalid month selected.'];
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit(); // Important: Stop script execution after AJAX response
}
// --- END: Specific AJAX Handler ---
if ($isAjax) {
    $status = isset($_GET['status']) ? $_GET['status'] : 'all';
    $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : null;
    $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : null;
    $priority = isset($_GET['priority']) ? $_GET['priority'] : 'all';

    // --- Calculate Filtered Summary Counts ---
    $filteredData = getFilteredData($pdo, $status, $dateFrom, $dateTo, $priority);
    $statusCounts = array_fill_keys($allStatuses, 0);
    foreach ($filteredData as $row) {
        if (isset($statusCounts[$row['current_status']])) {
            $statusCounts[$row['current_status']] = (int)$row['count'];
        }
    }

    // --- Calculate Cumulative Line Chart Data ---
    // It's okay if dates are null, the function handles it and returns []
    $lineDataCumulative = getLineChartDataCumulative($pdo, $dateFrom, $dateTo, $priority);

    // --- Calculate Daily Line Chart Data ---
    // It's okay if dates are null, the function handles it and returns []
    $lineDataDaily = getLineChartData($pdo, $dateFrom, $dateTo, $priority); // Call for daily data

    // --- Calculate Cumulative Step Counts for Bar Chart ---
    $cumulativeStepCounts = getCumulativeStepCountsFiltered($pdo, $dateFrom, $dateTo, $priority);


    // --- Combine ALL data into the response ---
    $response = [
        'statusCounts' => $statusCounts,
        'cumulativeLineData' => $lineDataCumulative,
        'dailyLineData' => $lineDataDaily,           // Include daily data
        'cumulativeStepCounts' => $cumulativeStepCounts
    ];

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

$persianLabelsF = ['برنامه‌ریزی شده', 'مش بندی', 'قالب‌بندی/بتن‌ریزی', 'فیس کوت', 'تکمیل', 'حمل به کارگاه'];
$statusColorsF = [

    'assign' => '#95a5a6',
    'mesh' => '#9b59b6',
    'Concreting' => '#e74c3c',
    'Assembly' => '#f1c40f',
    'completed' => '#2ecc71',
    'shipped' => '#2980b9',
];
function getLineChartDataCumulative($pdo, $dateFrom, $dateTo, $priority)
{

    $statusCounts = [];
    $allStatuses = ['assign',  'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped'];


    $dateFrom = convertPersianToGregorian($dateFrom);
    $dateTo = convertPersianToGregorian($dateTo);

    // --- Input Validation ---
    if (!$dateFrom || !$dateTo) {
        error_log("Line chart cumulative requires both start and end dates.");
        return [];
    }
    $startDate = new DateTime($dateFrom);
    $endDate = new DateTime($dateTo);
    if ($startDate > $endDate) {
        error_log("Line chart start date is after end date.");
        return [];
    }

    // --- Build Base Query Conditions ---
    $conditions = [];
    $params = [];
    $conditions[] = "assigned_date <= :dateTo_outer"; // Consider panels assigned up to the end date
    $params[':dateTo_outer'] = $dateTo;
    // Add dateFrom filter if needed (optional, depends if you want history before the start date)
    // $conditions[] = "assigned_date >= :dateFrom_outer";
    // $params[':dateFrom_outer'] = $dateFrom;

    // Priority condition
    if ($priority !== null && $priority !== 'all' && $priority !== '') {
        if ($priority === 'other') {
            $conditions[] = "(Proritization IS NULL OR Proritization = '')";
        } else {
            $conditions[] = "Proritization = :priority";
            $params[':priority'] = $priority;
        }
    }
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";



    // --- Fetch Panel Milestone Dates ---
    $panelMilestones = [];
    try {
        // Select relevant dates and status indicators
        $sqlPanel = "SELECT
                        p.id,
                        p.assigned_date,
                        p.mesh_end_time,
                        p.concrete_end_time,
                        p.assembly_end_time,
                        p.status,
                        p.packing_status,
                        p.shipping_date,
                        
                        p.status = 'pending' AND assigned_date IS NOT NULL AS is_assign, 

                        (SELECT MAX(pd.updated_at)
                           FROM hpc_panel_details pd
                          WHERE pd.panel_id = p.id AND pd.is_completed = 1) as calculated_completion_date
                    FROM hpc_panels p -- Alias the main table as 'p'
                    {$whereClause}"; // Apply filters

        $stmtPanel = $pdo->prepare($sqlPanel);
        $stmtPanel->execute($params); // Execute with main params
        $panelData = $stmtPanel->fetchAll(PDO::FETCH_ASSOC);

        // Store milestone completion dates per panel
        foreach ($panelData as $panel) {
            $panelId = $panel['id'];
            $milestones = [];

            // 'pending' is when it's assigned
            if ($panel['is_assign']) {
                $milestones['assign'] = substr($panel['assigned_date'], 0, 10);
            }



            // Other milestones based on _end_time
            if ($panel['mesh_end_time']) $milestones['Mesh'] = substr($panel['mesh_end_time'], 0, 10);
            if ($panel['concrete_end_time']) $milestones['Concreting'] = substr($panel['concrete_end_time'], 0, 10);
            if ($panel['assembly_end_time']) $milestones['Assembly'] = substr($panel['assembly_end_time'], 0, 10);

            // 'completed' - Use assembly_end_time or updated_at when status='completed'
            if ($panel['calculated_completion_date']) { // Check if subquery returned a date
                $milestones['completed'] = substr($panel['calculated_completion_date'], 0, 10);
            } elseif ($panel['status'] == 'completed') { // Fallback to status if subquery is empty but status is set
                // Try to use assembly_end_time as a proxy if available
                if ($panel['assembly_end_time']) {
                    $milestones['completed'] = substr($panel['assembly_end_time'], 0, 10);
                }
                // If you had an 'updated_at' on hpc_panels, that could be another fallback.
            }
            if ($panel['packing_status'] == 'shipped') {
                if ($panel['shipping_date']) {
                    $milestones['shipped'] = substr($panel['shipping_date'], 0, 10);
                } elseif (isset($milestones['completed'])) { // Fallback: Use completion date if available
                    $milestones['shipped'] = $milestones['completed'];
                }
            }

            $panelMilestones[$panelId] = $milestones;
        }
    } catch (PDOException $e) {
        error_log("Error fetching panel milestone data: " . $e->getMessage());
        return []; // Return empty on error
    }


    // --- Calculate Cumulative Counts ---
    $dailyCounts = [];     // [date][status] => count completed ON this day
    $cumulativeCounts = []; // [date][status] => count completed BY this day

    // Initialize daily counts for the date range
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($startDate, $interval, $endDate->modify('+1 day')); // Include end date

    foreach ($period as $dt) {
        $currentDateStr = $dt->format('Y-m-d');
        $dailyCounts[$currentDateStr] = array_fill_keys($allStatuses, 0);
    }

    // Populate daily counts based on milestone dates
    foreach ($panelMilestones as $panelId => $milestones) {
        foreach ($allStatuses as $statusKey) {
            if (isset($milestones[$statusKey])) {
                $completionDate = $milestones[$statusKey];
                if (isset($dailyCounts[$completionDate])) { // Only count if within our date range
                    $dailyCounts[$completionDate][$statusKey]++;
                }
            }
        }
    }

    // Calculate cumulative counts
    $previousDayCounts = array_fill_keys($allStatuses, 0);
    foreach ($period as $dt) {
        $currentDateStr = $dt->format('Y-m-d');
        $todayCounts = $dailyCounts[$currentDateStr];
        $cumulativeToday = [];
        foreach ($allStatuses as $statusKey) {
            $cumulativeToday[$statusKey] = $previousDayCounts[$statusKey] + $todayCounts[$statusKey];
        }
        $cumulativeCounts[$currentDateStr] = $cumulativeToday;
        $previousDayCounts = $cumulativeToday; // Set up for next iteration
    }


    // --- Format for Chart.js ---
    $chartJsData = []; // [status => [{x: date, y: count}]]
    foreach ($allStatuses as $statusKey) {
        $chartJsData[$statusKey] = [];
        foreach ($cumulativeCounts as $date => $counts) {
            $chartJsData[$statusKey][] = [
                'x' => $date,
                'y' => $counts[$statusKey] ?? 0
            ];
        }
    }

    return $chartJsData; // Return the Chart.js formatted data
}

$priorityStatusData = [];
$allPriorities = []; // Collect all priorities found
$allStatuses = ['pending', 'assign', 'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped']; // Use consistent status keys
$allStatusesF = ['assign',  'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped'];

try {
    $stmt = $pdo->query("
        SELECT
            COALESCE(Proritization, 'نامشخص' COLLATE utf8mb4_unicode_ci) as priority_group,
            ({$statusCaseSql}) as current_status, -- Use the standard CASE statement
            COUNT(*) as count
        FROM hpc_panels
        GROUP BY priority_group, current_status
        ORDER BY priority_group, FIELD(current_status,'assign', 'pending', 'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped')
    ");

    $rawPriorityData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process into a structure suitable for Chart.js (e.g., stacked bar)
    $processedPriorityData = [];
    $priorityTotals = [];       // [priority => total_count]
    $allPriorities = [];
    foreach ($rawPriorityData as $row) {
        $pGroup = $row['priority_group'];
        $cStatus = $row['current_status'];
        $count = (int)$row['count'];

        if (!isset($processedPriorityData[$pGroup])) {
            $processedPriorityData[$pGroup] = array_fill_keys($allStatuses, 0);
            $priorityTotals[$pGroup] = 0;
            $allPriorities[] = $pGroup;
        }
        if (isset($processedPriorityData[$pGroup][$cStatus])) {
            $processedPriorityData[$pGroup][$cStatus] = $count;
        }
        $priorityTotals[$pGroup] += $count;
    }
    $allPriorities = array_unique($allPriorities);
    // Optionally sort priorities (e.g., P1, P2... then 'نامشخص')
    usort($allPriorities, function ($a, $b) {
        if ($a === 'نامشخص') return 1;
        if ($b === 'نامشخص') return -1;
        return strnatcmp($a, $b); // Natural sort for P1, P2... P10
    });



    // Prepare for Chart.js
    $priorityStatusChartLabels = $allPriorities;
    $priorityStatusChartDatasetsPercent = []; // Use this variable name
    $statusColorsPriority = [ /* Define colors if different from main status chart */
        'pending' => '#bdc3c7',
        'assign' => '#95a5a6',
        'mesh' => '#9b59b6',
        'Concreting' => '#e74c3c',
        'Assembly' => '#f1c40f',
        'completed' => '#2ecc71',
        'shipped' => '#2980b9',
    ];
    $statusLabelsPersianPriority = [ /* Define labels */
        'pending' => 'در برنامه‌ریزی نشده',
        'assign' => 'برنامه‌ریزی شده',
        'Mesh' => 'مش بندی',
        'Concreting' => 'قالب‌بندی/بتن ریزی',
        'Assembly' => 'فیس کوت',
        'completed' => 'تکمیل شده',
        'shipped' => 'ارسال شده'
    ];

    foreach ($allStatuses as $statusKey) {
        $percentageDataPoints = [];
        foreach ($priorityStatusChartLabels as $priorityLabel) {
            // Check if priority exists in processed data AND totals
            $count = $processedPriorityData[$priorityLabel][$statusKey] ?? 0;
            $total = $priorityTotals[$priorityLabel] ?? 0; // Get total for THIS priority
            $percentage = ($total > 0) ? round(($count / $total) * 100, 1) : 0;
            $percentageDataPoints[] = $percentage;
        }

        $priorityStatusChartDatasetsPercent[] = [ // Add to the correct array
            'label' => $statusLabelsPersianPriority[$statusKey] ?? $statusKey,
            'data' => $percentageDataPoints,
            'backgroundColor' => $statusColorsPriority[$statusKey] ?? '#cccccc',
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching priority status data: " . $e->getMessage());
    $processedPriorityData = [];
    $allPriorities = [];
    $priorityStatusChartLabels = [];
    $priorityStatusChartDatasetsPercent = []; // Ensure empty on error
}
$priorityStepCounts_Count = []; // [priority => [status_key => count]]
$priorityStepCounts_Percent = []; // [priority => [status_key => percentage]]
$priorityTotalsForSteps = [];   // [priority => total_panels_in_priority]
$allPrioritiesForSteps = [];

try {
    // This query groups by priority and calculates the cumulative count for each step
    $sql = "
        SELECT
            COALESCE(p.Proritization, 'نامشخص' COLLATE utf8mb4_unicode_ci) as priority_group,
            COUNT(p.id) as total_priority_count, -- Total panels for this priority
            -- Cumulative Counts for each step reaching the milestone
            SUM(CASE WHEN assigned_date IS NOT NULL THEN 1 ELSE 0 END) as assign_reached,
            SUM(CASE WHEN p.mesh_end_time IS NOT NULL OR ({$statusCaseSql}) IN ('Concreting', 'Assembly', 'completed', 'shipped') THEN 1 ELSE 0 END) as Mesh_reached,
            SUM(CASE WHEN p.concrete_end_time IS NOT NULL OR ({$statusCaseSql}) IN ('Assembly', 'completed', 'shipped') THEN 1 ELSE 0 END) as Concreting_reached,
            SUM(CASE WHEN p.assembly_end_time IS NOT NULL OR ({$statusCaseSql}) IN ('completed', 'shipped') THEN 1 ELSE 0 END) as Assembly_reached,
            SUM(CASE WHEN p.status = 'completed' OR ({$statusCaseSql}) = 'shipped' THEN 1 ELSE 0 END) as completed_reached,
            SUM(CASE WHEN p.packing_status = 'shipped' THEN 1 ELSE 0 END) as shipped_reached,

            SUM(CASE WHEN assigned_date IS NULL THEN 1 ELSE 0 END) as pending_reached
        FROM hpc_panels p
        GROUP BY priority_group
        ORDER BY FIELD(priority_group, 'P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8', 'P9', 'P10', 'P11', 'P12', 'P13', 'P14', 'P15', 'نامشخص') -- Ensure consistent order
    "; // Added FIELD ordering

    $stmt = $pdo->query($sql);
    $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process raw data
    foreach ($rawData as $row) {
        $pGroup = $row['priority_group'];
        $total = (int)$row['total_priority_count'];

        $allPrioritiesForSteps[] = $pGroup;
        $priorityTotalsForSteps[$pGroup] = $total;

        $counts = [];
        $percentages = [];

        foreach ($allStatuses as $statusKey) {
            $reachedKey = $statusKey . '_reached'; // Matches the aliases in the SQL query
            $count = (int)($row[$reachedKey] ?? 0); // Get count for this step having been reached

            $counts[$statusKey] = $count;
            $percentages[$statusKey] = ($total > 0) ? round(($count / $total) * 100, 1) : 0;
        }
        $priorityStepCounts_Count[$pGroup] = $counts;
        $priorityStepCounts_Percent[$pGroup] = $percentages;
    }

    // Sort priorities again just in case GROUP BY didn't guarantee order fully
    usort($allPriorities, function ($a, $b) {
        if ($a === 'نامشخص') return 1;
        if ($b === 'نامشخص') return -1;
        return strnatcmp($a, $b); // Natural sort for P1, P2... P10
    });
} catch (PDOException $e) {
    error_log("Error fetching priority cumulative step data: " . $e->getMessage());
    // Initialize empty on error
    $priorityStepCounts_Count = [];
    $priorityStepCounts_Percent = [];
    $allPrioritiesForSteps = [];
}

// --- Prepare Datasets for Chart.js ---
$priorityCumulChartLabels = $allPrioritiesForSteps;
$priorityCumulChartDatasets_Count = []; // For absolute counts
$priorityCumulChartDatasets_Percent = []; // For percentages

// We need datasets where each dataset is a STAGE, and data points are priorities
foreach ($allStatuses as $statusKey) {
    $countDataPoints = [];
    $percentageDataPoints = [];

    foreach ($priorityCumulChartLabels as $priorityLabel) {
        $countDataPoints[] = $priorityStepCounts_Count[$priorityLabel][$statusKey] ?? 0;
        $percentageDataPoints[] = $priorityStepCounts_Percent[$priorityLabel][$statusKey] ?? 0;
    }

    $priorityCumulChartDatasets_Count[] = [
        'label' => $persianLabels[array_search($statusKey, $allStatuses)] ?? $statusKey, // Use Persian Label
        'data' => $countDataPoints,
        'backgroundColor' => $statusColors[$statusKey] ?? '#cccccc', // Use standard status colors
    ];

    $priorityCumulChartDatasets_Percent[] = [
        'label' => $persianLabels[array_search($statusKey, $allStatuses)] ?? $statusKey, // Use Persian Label
        'data' => $percentageDataPoints,
        'backgroundColor' => $statusColors[$statusKey] ?? '#cccccc', // Use standard status colors
    ];
}
// --- End Priority Cumulative Step Counts ---

// Include header
include('header.php');
?>
<style>
    body.dark-theme {
        background-color: #1f1f1f;
        color: #e0e0e0;
    }

    body.dark-theme .dashboard-container {
        background-color: #2a2a2a;
    }

    body.dark-theme .stat-card,
    body.dark-theme .chart-container,
    body.dark-theme .filters {
        background-color: #3a3a3a;
        color: #e0e0e0;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
    }

    body.dark-theme .stat-card h3 {
        color: #b0b0b0;
    }

    body.dark-theme .stat-value {
        color: #4a9bd4;
    }

    body.dark-theme .chart-container h2 {
        color: #e0e0e0;
        border-bottom-color: #555;
    }

    body.dark-theme .filter-group label {
        color: #b0b0b0;
    }

    body.dark-theme .filter-group select,
    body.dark-theme .filter-group input {
        background-color: #2a2a2a;
        color: #e0e0e0;
        border-color: #555;
    }

    body.dark-theme .header {
        background-color: #2c3e50;
    }

    /* Base Styles */
    @font-face {
        font-family: 'Vazir';
        src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
    }

    body {
        font-family: 'Vazir', sans-serif;
        margin: 0;
        padding: 20px;
        background: #f5f5f5;
    }


    .dashboard-container {
        max-width: 1200px;
        margin: auto;
        padding: 20px;
    }

    .header {
        background-color: #34495e;
        color: white;
        padding: 15px 20px;
        border-radius: 5px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .header h1 {
        margin: 0;
        font-size: 24px;
    }

    .date {
        font-size: 14px;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .stat-card {
        background-color: white;
        border-radius: 5px;
        padding: 20px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        text-align: center;
    }

    .stat-card h3 {
        margin-top: 0;
        color: #555;
        font-size: 16px;
    }

    .stat-value {
        font-size: 28px;
        font-weight: bold;
        color: #2980b9;
        margin: 10px 0;
    }

    .chart-container {
        background-color: white;
        border-radius: 5px;
        padding: 20px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }

    .chart-container h2 {
        margin-top: 0;
        color: #333;
        font-size: 18px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eee;
    }

    .chart-box {
        position: relative;
        width: 100%;
        /* Use 100% width */
        height: 300px;
        /* Keep the height as is */
        margin: 0;
        /* Remove any margins */
        padding: 0;
        /* Remove any padding */
    }

    .chart-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .chart-grid3 {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        /* Force 3 equal-width columns */
        gap: 20px;
        margin-bottom: 20px;
    }

    /* Optional: Ensure responsiveness */
    @media (max-width: 1024px) {
        .chart-grid3 {
            grid-template-columns: repeat(2, 1fr);
            /* 2 columns on medium screens */
        }
    }

    @media (max-width: 768px) {
        .chart-grid3 {
            grid-template-columns: 1fr;
            /* Single column on small screens */
        }
    }

    .filters {
        background-color: white;
        border-radius: 5px;
        padding: 15px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
    }

    .filter-group {
        flex: 1;
        min-width: 150px;
    }

    .filter-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: #555;
    }

    .filter-group select,
    .filter-group input {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
    }

    button {
        background-color: #2980b9;
        color: white;
        border: none;
        padding: 8px 15px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: bold;
    }

    button:hover {
        background-color: #3498db;
    }

    @media (max-width: 768px) {

        .stats-grid,
        .chart-grid {
            grid-template-columns: 1fr;
        }

        .filter-group {
            width: 100%;
        }
    }

    .form-check-inline {
        margin-left: 10px;
        /* Add some space */
    }

    .form-check-input.chart-print-select {
        cursor: pointer;
        border: 1px solid #adb5bd;
        /* Make border visible */
    }

    .form-check-label.small {
        font-size: 0.75rem;
        /* Smaller label */
        cursor: pointer;
        color: #6c757d;
    }

    .month-selector-container {
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .month-selector-container label {
        font-weight: bold;
        color: #555;
        /* Adjust color for dark theme if necessary */
    }

    body.dark-theme .month-selector-container label {
        color: #b0b0b0;
    }

    body.dark-theme .month-selector-container select {
        background-color: #2a2a2a;
        color: #e0e0e0;
        border-color: #555;
    }

    @media print {
        body {
            background-color: #fff !important;
            /* Ensure white background */
            color: #000 !important;
            /* Ensure black text */
            margin: 1cm;
            /* Add print margins */
            padding: 0;
            font-size: 9pt;
            /* Smaller font for print */
        }

        /* Hide elements not needed for print */
        .no-print,
        /* Buttons with this class */
        .filters,
        /* Filter section */
        header.top-header,
        /* Main site header */
        nav.nav-container,
        button,
        /* Main site navigation */
        #themeToggleBtn,
        /* Theme toggle button if you added one */
        footer

        /* Main site footer if included by footer.php */
            {
            display: none !important;
            visibility: hidden !important;
        }

        .form-check.no-print {
            display: none !important;
        }

        /* Show print-specific elements if you add them later */
        .print-only {
            display: block !important;
            visibility: visible !important;
        }

        /* Adjust dashboard layout for print */
        .dashboard-container {
            max-width: 100%;
            /* Use full width */
            margin: 0;
            padding: 0;
            box-shadow: none;
            border: none;
        }

        .header {
            /* Dashboard header */
            background-color: #eee !important;
            /* Light background */
            color: #000 !important;
            box-shadow: none;
            border-bottom: 1px solid #ccc;
            border-radius: 0;
            print-color-adjust: exact;
            /* Try to force background print */
            -webkit-print-color-adjust: exact;
        }

        .header h1 {
            font-size: 16pt;
        }

        .date {
            font-size: 9pt;
        }


        .stats-grid {
            grid-template-columns: repeat(3, 1fr);
            /* Arrange stats in 3 columns */
            page-break-inside: avoid;
            /* Try not to break stats cards */
        }

        .stat-card {
            box-shadow: none;
            border: 1px solid #ccc;
            padding: 10px;
        }

        .stat-card h3 {
            font-size: 10pt;
        }

        .stat-value {
            font-size: 14pt;
        }

        .chart-grid,
        .chart-grid3 {
            grid-template-columns: 1fr;
            /* Stack charts vertically */
            gap: 0.5cm;
            /* Space between charts */
            page-break-inside: avoid;
            /* Try to keep chart containers together */
        }

        .chart-container {
            box-shadow: none;
            border: 1px solid #ccc;
            padding: 15px;
            page-break-inside: avoid;
            /* Try not to break inside a chart */
            margin-bottom: 0.5cm;
        }

        .chart-container h2 {
            font-size: 12pt;
            border-bottom: 1px solid #ccc;
        }

        .chart-box {
            height: 250px;
            /* Slightly smaller height for print */
            width: 100% !important;
            /* Force width */
        }

        /* Attempt to make charts render better (might not always work) */
        canvas {
            max-width: 100% !important;
            max-height: 100% !important;
            height: auto !important;
            width: auto !important;
            /* Let container control width */
            page-break-inside: avoid !important;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        a[href]:after {
            content: none !important;
            /* Don't show URLs */
        }
    }
</style>
<!-- Dashboard HTML -->
<div class="dashboard-container">
    <div class="header">
        <h1><?php echo $pageTitle; ?></h1>
        <div class="date">تاریخ: <span><?php echo $today; ?></span></div>
    </div>

    <div class="filters">
        <div class="filter-group">
            <label for="status-filter">وضعیت:</label>
            <select id="status-filter">
                <option value="all">همه</option>
                <option value="pending">در انتظار</option>
                <option value="Mesh">مشبندی</option>
                <option value="Concreting">قالب‌بندی/بتن‌ریزی</option>
                <option value="Assembly">فیس کوت</option>
                <option value="completed">تکمیل شده</option>
                <option value="shipped">ارسال شده</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="priority-filter">اولویت:</label>
            <select id="priority-filter">
                <option value="all">همه</option>
                <?php for ($p = 1; $p <= 15; $p++): ?>
                    <option value="P<?php echo $p; ?>">P<?php echo $p; ?></option>
                <?php endfor; ?>
                <!-- Add option for other/null if needed -->
                <!-- <option value="other">Other/None</option> -->
            </select>
        </div>
        <div class="filter-group">
            <label for="date-from">از تاریخ:</label>
            <input type="text" id="date-from" class="persianDatepicker" readonly>
        </div>
        <div class="filter-group">
            <label for="date-to">تا تاریخ:</label>
            <input type="text" id="date-to" class="persianDatepicker" readonly>
        </div>
        <div class="filter-group" style="display: flex; align-items: flex-end;">
            <button id="apply-filters">اعمال فیلتر</button>
        </div>
        <!-- Export Buttons -->
        <div class="filter-group" style="display: flex; align-items: flex-end; gap: 5px;">

            <button id="print-dashboard" class="no-print">
                <i class="bi bi-printer me-1"></i> چاپ
            </button>
            <button id="prepare-print-page" class="no-print">
                <i class="bi bi-file-image me-1"></i>صفحه چاپ
            </button>
        </div>
    </div>
    <div class="chart-grid">
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center">
                <h2>تعداد تجمعی پنل در آخرین وضعیت </h2>
                <!-- Add Checkbox -->
                <div class="form-check form-check-inline no-print">
                    <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_filtered" data-chart-key="filtered" checked>
                    <label class="form-check-label small" for="printCheck_filtered">چاپ؟</label>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="filtered-chart"></canvas>
            </div>
        </div>
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center">
                <h2>روند روزانه تکمیل مراحل (تعداد روزانه)</h2>
                <!-- Add Checkbox -->
                <div class="form-check form-check-inline no-print">
                    <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_daily_line" data-chart-key="daily_line" checked>
                    <label class="form-check-label small" for="printCheck_daily_line">چاپ؟</label>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="line-chart"></canvas>
            </div>
        </div>
    </div>
    <div class="chart-grid"> <!-- Or chart-grid3 if you want -->
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center">
                <h2>روند تجمعی تکمیل مراحل</h2>
                <!-- Add Checkbox -->
                <div class="form-check form-check-inline no-print">
                    <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_cumulative_line" data-chart-key="cumulative_line" checked>
                    <label class="form-check-label small" for="printCheck_cumulative_line">چاپ؟</label>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="cumulative-line-chart"></canvas>
            </div>
        </div>
        <!-- You could add another chart here if needed -->
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center">
                <h2>تعداد تجمعی پنل در هر مرحله </h2>
                <!-- Add Checkbox -->
                <div class="form-check form-check-inline no-print">
                    <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_cumulative_step_bar" data-chart-key="cumulative_step_bar" checked>
                    <label class="form-check-label small" for="printCheck_cumulative_step_bar">چاپ؟</label>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="cumulative-step-bar-chart"></canvas>
            </div>
        </div>
    </div>


    <div class="stats-grid">
        <div class="stat-card">
            <h3>کل پنل‌ها</h3>
            <div class="stat-value"><?php echo number_format($totalPanels); ?> عدد</div>
        </div>
        <div class="stat-card">
            <h3>در حال پردازش</h3>
            <div class="stat-value"><?php echo number_format($inProgressPanels); ?> عدد</div>
        </div>
        <div class="stat-card">
            <h3>پنل‌های بتن ریزی شده</h3>
            <div class="stat-value"><?php echo number_format((float)$concretepanels); ?> عدد</div>
            <div class="stat-value"><?php echo number_format((float)$concretepanelsarea, 2); ?> متر مربع</div>
        </div>
        <div class="stat-card">
            <h3>پنل‌های فیس کوت خورده</h3>
            <div class="stat-value"><?php echo number_format((float)$facecoutpanels); ?> عدد</div>
            <div class="stat-value"><?php echo number_format((float)$facecoutpanelsarea, 2); ?> متر مربع</div>
        </div>
        <div class="stat-card">
            <h3>تکمیل شده</h3>
            <div class="stat-value"><?php echo number_format($completedPanels); ?> عدد</div>
            <div class="stat-value"><?php echo number_format((float)$completedPanelsarea, 2); ?> متر مربع</div>
        </div>
        <div class="stat-card">
            <h3>ارسال شده</h3>
            <div class="stat-value"><?php echo number_format($shippedPanels); ?> عدد</div>
            <div class="stat-value"><?php echo number_format((float)$areashippedPanels, 2); ?> متر مربع</div>
        </div>
        <div class="stat-card">
            <h3>پنل‌های موجود در کارگاه</h3>
            <div class="stat-value"><?php echo number_format($existPanels); ?> عدد</div>

        </div>
        <div class="stat-card">
            <h3>پنل‌ها برنامه‌ریزی شده</h3>
            <div class="stat-value"><?php echo number_format($assignedpanels); ?> عدد</div>
        </div>
        <div class="stat-card">
            <h3>پنل‌های در انتظار</h3>
            <div class="stat-value"><?php echo number_format($pendingPanels); ?> عدد</div>

        </div>
    </div>

    <div class="chart-grid">
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center">
                <h2>وضعیت پنل‌ها</h2>
                <div class="form-check form-check-inline no-print">
                    <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_status_pie" data-chart-key="status_pie" checked>
                    <label class="form-check-label small" for="printCheck_status_pie">چاپ؟</label>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="status-chart"></canvas>
            </div>
        </div>
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center">
                <h2>تولید هفتگی</h2>
                <div class="form-check form-check-inline no-print">
                    <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_weekly" data-chart-key="weekly" checked>
                    <label class="form-check-label small" for="printCheck_weekly">چاپ؟</label>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="weekly-chart"></canvas>
            </div>
        </div>

    </div>

    <div class="chart-grid">
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center">
                <h2>زمان تکمیل مراحل (میانگین به روز)</h2>
                <div class="form-check form-check-inline no-print">
                    <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_time_avg" data-chart-key="time_avg" checked>
                    <label class="form-check-label small" for="printCheck_time_avg">چاپ؟</label>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="time-chart"></canvas>
            </div>
        </div>
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center">
                <h2>مراحل تولید</h2>
                <div class="form-check form-check-inline no-print">
                    <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_step_progress" data-chart-key="step_progress" checked>
                    <label class="form-check-label small" for="printCheck_step_progress">چاپ؟</label>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="step-chart"></canvas>
            </div>
        </div>
    </div>
    <div class="chart-grid"> <!-- Grid containing ONLY the Monthly Production chart -->
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Add ID and change initial text -->
                <h2 id="production-chart-title">روند تولید روزانه (ماه را انتخاب کنید)</h2>
                <div class="form-check form-check-inline no-print">
                    <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_monthly_prod" data-chart-key="monthly_prod" checked>
                    <label class="form-check-label small" for="printCheck_monthly_prod">چاپ؟</label>
                </div>
            </div>
            <!-- Add the month selector dropdown container -->
            <div class="month-selector-container">
                <label for="persian-month-selector">انتخاب ماه:</label>
                <select id="persian-month-selector" class="form-select form-select-sm" style="width: auto;">
                    <option value="">-- ماه --</option>
                    <?php
                    // Make sure $persianMonthsDefinition is defined earlier in the PHP code
                    if (isset($persianMonthsDefinition) && is_array($persianMonthsDefinition)) {
                        foreach ($persianMonthsDefinition as $index => $month):
                    ?>
                            <option value="<?php echo $index; ?>">
                                <?php echo htmlspecialchars($month['label']); ?>
                            </option>
                    <?php
                        endforeach;
                    } else {
                        // Optional: Output a placeholder if the array isn't defined
                        echo '<option value="" disabled>خطا: لیست ماه‌ها تعریف نشده است</option>';
                    }
                    ?>
                </select>
            </div>
            <div class="chart-box">
                <canvas id="production-chart"></canvas>
            </div>
        </div>
    </div>
    <div class="chart-grid">
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center">
                <h2>تخصیص پنل‌ها (روزانه)</h2>
                <div class="form-check form-check-inline no-print">
                    <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_assigned_daily" data-chart-key="assigned_daily" checked>
                    <label class="form-check-label small" for="printCheck_assigned_daily">چاپ؟</label>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="assigned-per-day-chart"></canvas>
            </div>
        </div>
    </div>
    <div class="chart-grid">
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center">
                <h2>وضعیت پنل‌ها بر اساس اولویت</h2>
                <div class="form-check form-check-inline no-print">
                    <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_priority_status_percent" data-chart-key="priority_status_percent" checked>
                    <label class="form-check-label small" for="printCheck_priority_status_percent">چاپ؟</label>
                </div>
            </div>
            <div class="chart-box">
                <canvas id="priority-status-chart" height="800"></canvas>
            </div>
        </div>
    </div>
    <div class="chart-container">
        <div class="d-flex justify-content-between align-items-center">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <!-- Keep title and toggle buttons -->
                <h2>تعداد / درصد تجمعی مراحل بر اساس اولویت</h2>
                <div class="btn-group btn-group-sm" role="group" id="priorityStepToggle"> <!-- Changed ID -->
                    <input type="radio" class="btn-check" name="priorityStepView" id="priorityStepCountView" value="count" autocomplete="off" checked>
                    <label class="btn btn-outline-secondary" for="priorityStepCountView">تعداد</label>

                    <input type="radio" class="btn-check" name="priorityStepView" id="priorityStepPercentView" value="percent" autocomplete="off">
                    <label class="btn btn-outline-secondary" for="priorityStepPercentView">درصد</label>
                </div>
            </div>
            <div class="form-check form-check-inline no-print">
                <input class="form-check-input chart-print-select" type="checkbox" id="printCheck_priority_step_cumulative" data-chart-key="priority_step_cumulative" checked>
                <label class="form-check-label small" for="printCheck_priority_step_cumulative">چاپ؟</label>
            </div>
        </div>
        <div class="chart-box">
            <canvas id="priority-step-cumulative-chart" height="800"></canvas> <!-- Changed ID -->
        </div>
    </div>
</div>
<!-- <div class="chart-container">
    <h2>پنل‌های تکمیل شده</h2>
    <div class="chart-box">
        <canvas id="completed-chart"></canvas>
    </div>
</div> -->
<script src="https://cdn.jsdelivr.net/npm/moment@2.29.1/moment.min.js"></script>
<!-- Load Moment-Jalaali for Persian date support -->
<script src="https://cdn.jsdelivr.net/npm/moment-jalaali@0.9.2/build/moment-jalaali.js"></script>
<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- Load Chart.js adapter for Moment.js -->
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-moment@1.0.0"></script>
<script src="assets/js/persian-date.min.js"></script>
<script src="assets/js/persian-datepicker.min.js"></script>
<link rel="stylesheet" href="/assets/css/persian-datepicker-dark.min.css">
<script>
    moment.loadPersian({
        dialect: 'persian-modern',
        usePersianDigits: true
    });
</script>

<script>
    const ALL_STATUSES = <?php echo json_encode($allStatuses); ?>;
    const PERSIAN_LABELS = <?php echo json_encode($persianLabels, JSON_UNESCAPED_UNICODE); ?>;
    const STATUS_COLORS = <?php echo json_encode($statusColors); ?>;
    document.addEventListener('DOMContentLoaded', () => {
        const themeToggleBtn = document.createElement('button');
        themeToggleBtn.textContent = 'تغییر تم';
        themeToggleBtn.style.position = 'fixed';
        themeToggleBtn.style.top = '10px';
        themeToggleBtn.style.right = '10px';
        themeToggleBtn.style.zIndex = '1000';
        document.body.appendChild(themeToggleBtn);

        themeToggleBtn.addEventListener('click', () => {
            document.body.classList.toggle('dark-theme');
            localStorage.setItem('theme', document.body.classList.contains('dark-theme') ? 'dark' : 'light');
        });

        // Check for saved theme
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            document.body.classList.add('dark-theme');
        }

        const ALL_STATUSES = <?php echo json_encode($allStatuses); ?>;
        const ALL_STATUSESF = <?php echo json_encode($allStatusesF); ?>;
        const PERSIAN_LABELS = <?php echo json_encode($persianLabels, JSON_UNESCAPED_UNICODE); ?>;
        const PERSIAN_LABELSF = <?php echo json_encode($persianLabelsF, JSON_UNESCAPED_UNICODE); ?>;
        const STATUS_COLORSF = <?php echo json_encode($statusColorsF); ?>;
        const STATUS_COLORS = <?php echo json_encode($statusColors); ?>;
        const serverStatuses = ['pending', 'assign', 'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped'];
        const persianLabels = [
            'برنامه‌ریزی نشده',
            'برنامه‌ریزی شده',
            'در حال مشبندی',
            'در حال قالب بندی/بتن‌ریزی',
            'در حال فیس کوت',
            'تکمیل',
            'حمل به کارگاه'
        ];
        // Initialize Persian Datepicker
        $(".persianDatepicker").persianDatepicker({
            format: 'YYYY/MM/DD',
            autoClose: true,
            initialValue: false,
            calendar: {
                persian: {
                    locale: 'fa',
                    leapYearMode: 'astronomical'
                }
            },
            onSelect: function(unix) {
                // Convert to proper format for filtering when selected
                const date = new persianDate(unix);
                const formatted = date.toCalendar('gregorian').format('YYYY-MM-DD');

                this.model.inputElement.dataset.gregorian = formatted;
            }
        });
        moment.loadPersian({
            dialect: 'persian-modern', // or 'persian' depending on your preference
            usePersianDigits: true
        });
        const filteredCtx = document.getElementById('filtered-chart').getContext('2d');
        const filteredChart = new Chart(filteredCtx, {
            type: 'bar',
            data: {
                labels: [
                    'برنامه‌ریزی شده',
                    'در حال مشبندی',
                    'در حال قالب بندی/بتن‌ریزی',
                    'در حال فیس کوت',
                    'تکمیل',
                    'حمل به کارگاه'
                ],

                datasets: [{
                    label: 'تعداد پنل',
                    data: [], // Initial data
                    backgroundColor: [
                        '#95a5a6', '#3498db', '#9b59b6', '#e74c3c', '#f1c40f', '#2ecc71', '#2980b9'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'تعداد تجمعی پنل در آخرین وضعیت',
                        font: {
                            size: 16,
                            family: 'Vazir'
                        }
                    },
                    legend: {
                        labels: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                }
            }
        });
        // --- NEW Cumulative Step Bar Chart Initialization ---
        const cumulativeStepCtx = document.getElementById('cumulative-step-bar-chart').getContext('2d');
        const cumulativeStepBarChart = new Chart(cumulativeStepCtx, {
            type: 'bar',
            data: {
                labels: [

                    'برنامه‌ریزی شده',
                    'در حال مشبندی',
                    'در حال قالب بندی/بتن‌ریزی',
                    'در حال فیس کوت',
                    'تکمیل',
                    'حمل به کارگاه'
                ],
                datasets: [{
                    label: 'تعداد تجمعی پنل',
                    data: [], // Initially empty
                    backgroundColor: [
                        '#95a5a6', '#3498db', '#9b59b6', '#e74c3c', '#f1c40f', '#2ecc71', '#2980b9'
                    ], // Use global const values
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'تعداد تجمعی پنل در هر مرحله ',
                        font: {
                            size: 16,
                            family: 'Vazir'
                        }
                    },
                    legend: {
                        display: true
                    }, // Hide legend for single dataset
                    tooltip: {
                        titleFont: {
                            family: 'Vazir'
                        },
                        bodyFont: {
                            family: 'Vazir'
                        }
                    }
                }
            }
        });

        const cumulativeCtx = document.getElementById('cumulative-line-chart').getContext('2d');
        const cumulativeLineChart = new Chart(cumulativeCtx, {
            type: 'line',
            data: {
                // Labels are dates, handled by the time scale, no need for static labels here
                datasets: ALL_STATUSESF.map((statusKey, index) => ({
                    label: PERSIAN_LABELSF[index] || statusKey, // Use Persian label
                    borderColor: STATUS_COLORSF[statusKey] || '#cccccc', // Use color map
                    fill: false,
                    data: [], // Initially empty, format [{x: 'YYYY-MM-DD', y: count}, ...]
                    tension: 0.1 // Optional: slight curve
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            parser: 'YYYY-MM-DD',
                            unit: 'day',
                            tooltipFormat: 'jYYYY/jM/jD', // Jalaali tooltip
                            displayFormats: {
                                day: 'jM/jD' // Jalaali axis ticks (short format)
                            }
                        },
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        title: { // Optional Y-axis title
                            display: true,
                            text: 'تعداد تجمعی پنل‌ها',
                            font: {
                                family: 'Vazir'
                            }
                        },
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'روند تجمعی تکمیل مراحل', // Chart title
                        font: {
                            size: 16,
                            family: 'Vazir'
                        }
                    },
                    legend: {
                        labels: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    tooltip: {
                        titleFont: {
                            family: 'Vazir'
                        },
                        bodyFont: {
                            family: 'Vazir'
                        }
                        // Tooltip will show date and count by default
                    }
                }
            }
        });


        const lineCtx = document.getElementById('line-chart').getContext('2d');
        const lineChart = new Chart(lineCtx, { // Use the 'lineChart' variable name
            type: 'line',
            data: {
                datasets: ALL_STATUSESF.map((statusKey, index) => ({ // Use global consts
                    label: PERSIAN_LABELSF[index] || statusKey,
                    borderColor: STATUS_COLORSF[statusKey] || '#cccccc',
                    fill: false,
                    data: [], // Initially empty [{x: 'date', y: count}]
                    tension: 0.1
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'time', // important: time axis
                        time: {
                            // The format of your raw data. For example, "2025-03-05"
                            parser: 'YYYY-MM-DD',
                            unit: 'day',
                            // Persian date in the tooltip
                            tooltipFormat: 'jYYYY/jM/jD',
                            // Persian date on the axis ticks
                            displayFormats: {
                                day: 'jYYYY/jM/jD'
                            }
                        },
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'روند روزانه تکمیل مراحل (آخرین وضعیت)',
                        font: {
                            size: 16,
                            family: 'Vazir'
                        }
                    },
                    legend: {
                        labels: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                }
            }
        });

        // Priority Status Chart (Stacked Bar)
        const priorityStatusCtx = document.getElementById('priority-status-chart').getContext('2d');
        const priorityStatusChart = new Chart(priorityStatusCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($priorityStatusChartLabels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: <?php echo json_encode($priorityStatusChartDatasetsPercent, JSON_UNESCAPED_UNICODE); ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'x', // Priorities on X axis
                scales: {
                    y: {
                        beginAtZero: true,
                        stacked: true, // Enable stacking
                        max: 100, // <<< SET MAX to 100 for Percentage
                        ticks: {
                            font: {
                                family: 'Vazir'
                            },
                            stepSize: 5,
                            // Add percentage sign to Y-axis ticks
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    },
                    x: {
                        stacked: true, // Enable stacking
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'درصد وضعیت پنل‌ها بر اساس اولویت',
                        font: {
                            size: 16,
                            family: 'Vazir'
                        }
                    },
                    legend: {
                        labels: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    tooltip: {
                        titleFont: {
                            family: 'Vazir'
                        },
                        bodyFont: {
                            family: 'Vazir'
                        },
                        // Add percentage sign to tooltips
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    // Format the value from the dataset (which is already a percentage)
                                    label += context.parsed.y.toFixed(1) + '%';
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });



        function exportDashboardData(format) {
            const statusFilter = document.getElementById('status-filter').value;
            const dateFromElement = document.getElementById('date-from');
            const dateToElement = document.getElementById('date-to');
            const priorityFilter = document.getElementById('priority-filter').value || 'all';

            const dateFromGregorian = dateFromElement.dataset.gregorian || '';
            const dateToGregorian = dateToElement.dataset.gregorian || '';

            const params = new URLSearchParams({
                status: statusFilter,
                priority: priorityFilter,
                format: format // Specify format
            });
            if (dateFromGregorian) {
                params.append('date_from', dateFromGregorian);
            }
            if (dateToGregorian) {
                params.append('date_to', dateToGregorian);
            }

            // Redirect to the appropriate export script
            const exportUrl = (format === 'pdf') ? 'export_dashboard_pdf.php' : 'export_dashboard_csv.php';
            window.location.href = `${exportUrl}?${params.toString()}`;
        }
        // ----- Data Fetching Functions for Polystyrene Orders -----
        // Mapping: English status keys and corresponding Persian labels.
        const psEnglishKeys = ['pending', 'ordered', 'in_production', 'delivered'];
        const psPersianLabels = ['برنامه‌ریزی نشده', 'سفارش شده', 'در حال تولید', 'تحویل شده'];

        // Status Chart
        const statusCtx = document.getElementById('status-chart').getContext('2d');
        const statusChart = new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode($persianLabels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode($statusChartCounts); ?>,
                    backgroundColor: <?php echo json_encode(array_values($statusColors)); ?>,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                    }
                }
            }
        });

        // Production Chart (Monthly)

        const monthLabels = <?php echo json_encode($monthLabels, JSON_UNESCAPED_UNICODE); ?>;
        const monthlyData = <?php echo json_encode($monthlyData, JSON_UNESCAPED_UNICODE); ?>;

        // Steps in the same order used in PHP
        const steps = ['pending', 'assign', 'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped'];

        // Assign a color to each step
        const colors = {
            'pending': '#bdc3c7',
            'assign': '#95a5a6',
            'Mesh': '#e74c3c',
            'Concreting': '#f1c40f',
            'Assembly': '#9b59b6',
            'completed': '#2ecc71',
            'shipped': '#2980b9'
        };

        // Build Chart.js datasets
        const datasets = steps.map((step, index) => ({
            label: persianLabels[index],
            data: monthlyData[step],
            fill: false,
            borderColor: colors[step],
            tension: 0.1
        }));

        const productionCtx = document.getElementById('production-chart').getContext('2d');
        // Define steps and colors matching PHP AJAX response
        const productionSteps = ['assign', 'Mesh', 'Concreting', 'Assembly', 'completed', 'shipped'];
        const productionStepLabels = ['برنامه‌ریزی شده', 'مش بندی', 'قالب‌بندی/بتن‌ریزی', 'فیس کوت', 'تکمیل', 'حمل به کارگاه'];
        const productionStepColors = {
            'assign': '#95a5a6',
            'Mesh': '#9b59b6',
            'Concreting': '#e74c3c',
            'Assembly': '#f1c40f',
            'completed': '#2ecc71',
            'shipped': '#2980b9'
        };

        const productionChart = new Chart(productionCtx, {
            type: 'line',
            data: {
                labels: [], // Initially empty - will be populated with Persian day numbers
                datasets: productionSteps.map((stepKey, index) => ({
                    label: productionStepLabels[index] || stepKey,
                    data: [], // Initially empty
                    borderColor: productionStepColors[stepKey] || '#cccccc',
                    fill: false,
                    tension: 0.1
                }))
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'تعداد پنل',
                            font: {
                                family: 'Vazir'
                            }
                        },
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    x: { // X-axis represents days of the month
                        title: {
                            display: true,
                            text: 'روز ماه',
                            font: {
                                family: 'Vazir'
                            }
                        },
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        // Text is set dynamically later
                        text: 'روند تولید روزانه (ماه را انتخاب کنید)',
                        font: {
                            size: 16,
                            family: 'Vazir'
                        }
                    },
                    legend: {
                        labels: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    tooltip: {
                        titleFont: {
                            family: 'Vazir'
                        },
                        bodyFont: {
                            family: 'Vazir'
                        },
                        callbacks: { // Show day number in tooltip title
                            title: function(tooltipItems) {
                                // The label *is* the full Persian date now
                                if (tooltipItems.length > 0) {
                                    return tooltipItems[0].label; // Display the full date label
                                }
                                return '';
                            }
                        }
                    }
                }
            }
        });

        // Weekly Production Chart
        const weeklyCtx = document.getElementById('weekly-chart').getContext('2d');
        const weeklyChart = new Chart(weeklyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($weeklyProduction['labels']); ?>,
                datasets: [{
                        label: 'تکمیل شده',
                        data: <?php echo json_encode($weeklyProduction['completed']); ?>,
                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'در حال پیشرفت',
                        data: <?php echo json_encode($weeklyProduction['in_progress']); ?>,
                        backgroundColor: 'rgba(255, 193, 7, 0.7)',
                        borderColor: 'rgba(255, 193, 7, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'مقایسه تولید هفتگی',
                        font: {
                            size: 16,
                            family: 'Vazir'
                        }
                    },
                    legend: {
                        labels: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                }
            }
        });

        // Time Chart
        const timeCtx = document.getElementById('time-chart').getContext('2d');
        const timeChart = new Chart(timeCtx, {
            type: 'bar',
            data: {
                // UPDATE LABELS to reflect calculable phases
                labels: ['زمان مش بندی', 'زمان بتن\u200Cریزی', 'زمان فیس کوت', 'زمان تکمیل نهایی', 'زمان کل تولید'],
                datasets: [{
                    label: 'میانگین زمان (روز)',
                    // UPDATE data to match calculated keys
                    data: [
                        <?php echo $timeData['mesh_time']; ?>,
                        <?php echo $timeData['concreting_time']; ?>,
                        <?php echo $timeData['assembly_time']; ?>,
                        <?php echo $timeData['completion_time']; ?>,
                        <?php echo $timeData['total_production_time']; ?>
                    ],
                    backgroundColor: [ // Adjust colors if needed
                        '#9b59b6', '#e74c3c', '#f1c40f', '#2ecc71', '#34495e'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'میانگین زمان هر مرحله',
                        font: {
                            size: 16,
                            family: 'Vazir'
                        }
                    },
                    legend: {
                        labels: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                }
            }
        });



        // New Step Count Chart
        const stepCtx = document.getElementById('step-chart').getContext('2d');
        const stepChart = new Chart(stepCtx, {
            type: 'bar', // Choose a suitable chart type
            data: {
                labels: <?php echo json_encode($persianLabels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    label: 'تعداد پنل‌',
                    data: <?php echo json_encode($stepChartData); ?>,
                    backgroundColor: <?php echo json_encode(array_values($statusColors)); ?>,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'مراحل تولید',
                        font: {
                            size: 16,
                            family: 'Vazir'
                        }
                    },

                    legend: {
                        position: 'top',
                        labels: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                }
            }
        });

        // Assigned Per Day Chart
        const assignedPerDayCtx = document.getElementById('assigned-per-day-chart').getContext('2d');
        const assignedPerDayChart = new Chart(assignedPerDayCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($assignedPerDay['labels']); ?>,
                datasets: [{
                    label: 'تعداد پنل‌های تخصیص داده شده',
                    data: <?php echo json_encode($assignedPerDay['data']); ?>,
                    borderColor: '#3498db',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {

                    x: {
                        ticks: {
                            font: {
                                family: 'Vazir'
                            },
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'پنل‌های تخصیص داده شده (روزانه)',
                        font: {
                            size: 16,
                            family: 'Vazir'
                        }
                    },
                    legend: {
                        labels: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                }
            }
        });

        const priorityStepCtx = document.getElementById('priority-step-cumulative-chart').getContext('2d'); // New ID

        // Store both datasets prepared by PHP
        const priorityStepDataCount = <?php echo json_encode($priorityCumulChartDatasets_Count, JSON_UNESCAPED_UNICODE); ?>;
        const priorityStepDataPercent = <?php echo json_encode($priorityCumulChartDatasets_Percent, JSON_UNESCAPED_UNICODE); ?>;

        const priorityStepCumulativeChart = new Chart(priorityStepCtx, {
            type: 'bar', // Grouped bar chart
            data: {
                labels: <?php echo json_encode($priorityCumulChartLabels, JSON_UNESCAPED_UNICODE); ?>, // Priorities on X-axis
                datasets: priorityStepDataCount // Start with Count view
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'x',
                scales: {
                    y: { // Y-axis for Counts initially
                        beginAtZero: true,
                        // stacked: false, // <<<< IMPORTANT: NOT stacked for this chart type
                        title: {
                            display: true,
                            text: 'تعداد پنل در مرحله یا قبل‌تر',
                            font: {
                                family: 'Vazir'
                            }
                        },
                        ticks: {
                            font: {
                                family: 'Vazir'
                            },
                            stepSize: 5
                        }
                    },
                    x: {
                        // stacked: false, // <<<< IMPORTANT: NOT stacked
                        ticks: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'تعداد تجمعی مراحل بر اساس اولویت',
                        font: {
                            size: 16,
                            family: 'Vazir'
                        }
                    },
                    legend: {
                        labels: {
                            font: {
                                family: 'Vazir'
                            }
                        }
                    },
                    tooltip: {
                        titleFont: {
                            family: 'Vazir'
                        },
                        bodyFont: {
                            family: 'Vazir'
                        },
                        callbacks: { // Tooltip for Count
                            label: function(context) {
                                let value = context.parsed.y;
                                if (value === null || value === 0) return null; // Optional: hide zero counts
                                let label = context.dataset.label || ''; // Stage name
                                if (label) {
                                    label += ': ';
                                }
                                label += value;
                                return label;
                            }
                        }
                    }
                }
            }
        });
        const monthSelector = document.getElementById('persian-month-selector');
        const productionChartTitleElement = document.getElementById('production-chart-title');

        if (monthSelector) {
            monthSelector.addEventListener('change', function() {
                const selectedMonthIndex = this.value;

                if (selectedMonthIndex === "") {
                    // Reset chart if '-- ماه --' is selected
                    productionChart.data.labels = [];
                    productionChart.data.datasets.forEach(dataset => {
                        dataset.data = [];
                    });
                    if (productionChartTitleElement) {
                        productionChartTitleElement.textContent = 'روند تولید روزانه (ماه را انتخاب کنید)';
                    }
                    productionChart.options.plugins.title.text = 'روند تولید روزانه (ماه را انتخاب کنید)';
                    productionChart.update();
                    return; // Stop processing
                }

                // Show loading state (optional)
                if (productionChartTitleElement) {
                    productionChartTitleElement.textContent = 'در حال بارگذاری داده‌های ماه...';
                }

                fetch(`dashboard.php?action=get_daily_month_data&month_index=${selectedMonthIndex}`, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            productionChart.data.labels = data.labels; // Update labels (Persian day numbers)
                            // Update datasets - Ensure the order matches
                            productionChart.data.datasets.forEach((chartDataset, index) => {
                                // Find the matching dataset from the response
                                const responseDataset = data.datasets.find(ds => ds.label === chartDataset.label);
                                if (responseDataset) {
                                    chartDataset.data = responseDataset.data;
                                } else {
                                    // Fallback or clear if label doesn't match (shouldn't happen if PHP/JS labels are synced)
                                    chartDataset.data = new Array(data.labels.length).fill(0);
                                    console.warn(`Dataset mismatch for label: ${chartDataset.label}`);
                                }
                            });


                            // Update chart title
                            const newTitle = `روند تولید روزانه - ${data.monthLabel}`;
                            if (productionChartTitleElement) {
                                productionChartTitleElement.textContent = newTitle;
                            }
                            productionChart.options.plugins.title.text = newTitle;

                            productionChart.update(); // Redraw the chart
                        } else {
                            console.error("Error fetching daily data:", data.message);
                            alert(`خطا در بارگذاری داده‌های ماه: ${data.message || 'خطای نامشخص'}`);
                            if (productionChartTitleElement) {
                                productionChartTitleElement.textContent = 'خطا در بارگذاری';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching daily month data:', error);
                        alert('خطا در ارتباط با سرور برای دریافت داده‌های ماهانه.');
                        if (productionChartTitleElement) {
                            productionChartTitleElement.textContent = 'خطا در ارتباط';
                        }
                    });
            });
        }
        // --- Toggle Event Listener for Priority STEP Chart ---
        const stepToggleGroup = document.getElementById('priorityStepToggle'); // New ID
        if (stepToggleGroup) {
            stepToggleGroup.addEventListener('change', function(event) {
                const selectedView = event.target.value; // 'count' or 'percent'
                const chart = priorityStepCumulativeChart; // Target the correct chart instance

                if (selectedView === 'percent') {
                    chart.data.datasets = priorityStepDataPercent;
                    chart.options.scales.y.max = 100;
                    chart.options.scales.y.title.text = 'درصد پنل‌ها در مرحله یا قبل‌تر';
                    chart.options.scales.y.ticks.stepSize = 5;
                    chart.options.plugins.tooltip.callbacks.label = function(context) {
                        let value = context.parsed.y;
                        if (value === null || value === 0) return null; // Optional: hide zero percent
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        label += value.toFixed(1) + '%';
                        return label;
                    };
                    chart.options.plugins.title.text = 'درصد تجمعی مراحل بر اساس اولویت';

                } else { // 'count' view
                    chart.data.datasets = priorityStepDataCount;
                    chart.options.scales.y.max = undefined;
                    chart.options.scales.y.title.text = 'تعداد پنل در مرحله یا قبل‌تر';
                    chart.options.scales.y.ticks.stepSize = 5;
                    chart.options.plugins.tooltip.callbacks.label = function(context) {
                        let value = context.parsed.y;
                        if (value === null || value === 0) return null; // Optional: hide zero counts
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        label += value;
                        return label;
                    };
                    chart.options.plugins.title.text = 'تعداد تجمعی مراحل بر اساس اولویت';
                }
                chart.update();
            });
        }


        // Filter functionality
        // --- Update both charts on filter application ---
        // --- Filter Button Listener (Single Fetch) ---
        document.getElementById('apply-filters').addEventListener('click', function() {
            const statusFilter = document.getElementById('status-filter').value;
            const dateFromElement = document.getElementById('date-from');
            const dateToElement = document.getElementById('date-to');
            const priorityFilter = document.getElementById('priority-filter').value || 'all';

            const dateFromGregorian = dateFromElement.dataset.gregorian || '';
            const dateToGregorian = dateToElement.dataset.gregorian || '';

            console.log('Applying Filters:', {
                status: statusFilter,
                from: dateFromGregorian,
                to: dateToGregorian,
                priority: priorityFilter
            });

            const params = new URLSearchParams({
                status: statusFilter,
                priority: priorityFilter
            });
            if (dateFromGregorian) params.append('date_from', dateFromGregorian);
            if (dateToGregorian) params.append('date_to', dateToGregorian);

            // --- Make ONE Fetch Call ---
            fetch(`dashboard.php?${params.toString()}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    console.log('Combined AJAX Response Data:', data);

                    // ----- Update Filtered Bar Chart -----
                    const statusCounts = data.statusCounts || {};
                    const counts = ALL_STATUSESF.map(key => statusCounts[key] || 0);
                    if (filteredChart) { // Check if chart instance exists
                        filteredChart.data.labels = PERSIAN_LABELSF;
                        filteredChart.data.datasets[0].data = counts;
                        filteredChart.update();
                        console.log('Filtered Bar Chart Updated.');
                    }

                    // ----- Update Cumulative Line Chart -----
                    const cumulativeData = data.cumulativeLineData || {};
                    if (cumulativeLineChart) { // Check if chart instance exists
                        ALL_STATUSES.forEach((statusKey, index) => {
                            if (cumulativeLineChart.data.datasets[index]) {
                                cumulativeLineChart.data.datasets[index].data = cumulativeData[statusKey] || [];
                            }
                        });
                        cumulativeLineChart.update();
                        console.log('Cumulative Line Chart Updated.');
                    }

                    // ----- Update Daily Line Chart -----
                    const dailyData = data.dailyLineData || {};
                    if (lineChart) { // Check if chart instance exists
                        ALL_STATUSES.forEach((statusKey, index) => {
                            if (lineChart.data.datasets[index]) {
                                lineChart.data.datasets[index].data = dailyData[statusKey] || [];
                            }
                        });
                        lineChart.update();
                        console.log('Daily Line Chart Updated.');
                    }

                    // ----- Update Cumulative Step Bar Chart -----
                    const cumulativeStepCounts = data.cumulativeStepCounts || [];
                    if (cumulativeStepBarChart && cumulativeStepBarChart.data.datasets[0]) { // Check instance and dataset
                        cumulativeStepBarChart.data.datasets[0].data = cumulativeStepCounts;
                        cumulativeStepBarChart.update();
                        console.log('Cumulative Step Bar Chart Updated.');
                    }

                    // NOTE: We don't update the Priority-Status % chart here,
                    // as it shows overall distribution, not filtered results.
                    // If you *did* want it filtered, you'd need to add that data
                    // to the PHP response and update priorityStatusChart here.

                })
                .catch(error => {
                    console.error('Error fetching/updating dashboard data:', error);
                    alert('خطا در دریافت اطلاعات داشبورد.');
                });
        });

        // --- Print Button Listener ---
        document.getElementById('print-dashboard').addEventListener('click', function() {
            window.print(); // Trigger browser's print dialog
        });

        // --- Existing Export Button Listeners ---
        function prepareAndSubmitPrintForm() {
            console.log("Preparing print page...");
            // Show a simple loading indicator (optional)
            const prepButton = document.getElementById('prepare-print-page');
            if (!prepButton) return; // Safety check
            const originalText = prepButton.innerHTML;
            prepButton.disabled = true;
            prepButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> آماده‌سازی...';
            const chartInstanceMap = {
                'filtered': filteredChart,
                'daily_line': lineChart,
                'cumulative_line': cumulativeLineChart,
                'cumulative_step_bar': cumulativeStepBarChart,
                'status_pie': statusChart,
                'weekly': weeklyChart,
                'time_avg': timeChart,
                'step_progress': stepChart, // The overall step bar chart
                'monthly_prod': productionChart,
                'assigned_daily': assignedPerDayChart,
                'priority_status_percent': priorityStatusChart,
                'priority_step_cumulative': priorityStepCumulativeChart // The toggle-able one
                // Add any other charts here with their key and variable name
            };
            try {
                // 1. Get Filter Values (Keep this part)
                const statusFilter = document.getElementById('status-filter').value;
                // ... (get priority, dates) ...
                const dateFromGregorian = document.getElementById('date-from').dataset.gregorian || '';
                const dateToGregorian = document.getElementById('date-to').dataset.gregorian || '';

                // 2. Get SELECTED Chart Images
                const selectedChartImages = {};
                const selectedCheckboxes = document.querySelectorAll('.chart-print-select:checked');

                // --- Check if any chart is selected ---
                if (selectedCheckboxes.length === 0) {
                    alert('لطفاً حداقل یک نمودار را برای چاپ انتخاب کنید.');
                    prepButton.disabled = false; // Re-enable button
                    prepButton.innerHTML = originalText;
                    return; // Stop processing
                }
                // --- End check ---


                selectedCheckboxes.forEach(checkbox => {
                    const chartKey = checkbox.dataset.chartKey;
                    const chartInstance = chartInstanceMap[chartKey];

                    if (chartInstance && typeof chartInstance.toBase64Image === 'function') {
                        try {
                            selectedChartImages[chartKey] = chartInstance.toBase64Image();
                        } catch (imgError) {
                            console.error(`Error converting chart "${chartKey}" to image:`, imgError);
                        }
                    } else {
                        console.warn(`Chart instance for key "${chartKey}" not found or invalid.`);
                    }
                });

                // 3. Create Hidden Form (Keep this part)
                const form = document.createElement('form');
                // ... (set method, action, target, style) ...
                form.method = 'POST';
                form.action = 'dashboard_print.php';
                form.target = '_blank';
                form.style.display = 'none';


                // 4. Add Filter Data to Form (Keep this part)
                const filtersInput = document.createElement('input');
                // ... (set type, name, value with JSON.stringify) ...
                filtersInput.type = 'hidden';
                filtersInput.name = 'filters';
                filtersInput.value = JSON.stringify({
                    status: statusFilter,
                    priority: document.getElementById('priority-filter').value || 'all',
                    date_from: dateFromGregorian,
                    date_to: dateToGregorian
                });
                form.appendChild(filtersInput);


                // 5. Add ONLY SELECTED Chart Image Data to Form
                for (const key in selectedChartImages) { // Iterate selected images
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `chart_img_${key}`;
                    input.value = selectedChartImages[key];
                    form.appendChild(input);
                }

                // 6. Append Form and Submit (Keep this part)
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);

                console.log("Print form submitted with selected charts.");

            } catch (error) {
                console.error("Error preparing print page:", error);
                alert("خطا در آماده‌سازی صفحه چاپ.");
            } finally {
                // Restore button state after a short delay
                setTimeout(() => {
                    prepButton.disabled = false;
                    prepButton.innerHTML = originalText;
                }, 1000);
            }
        }

        // --- Attach Listener to the New Button ---
        const printButton = document.getElementById('prepare-print-page');
        if (printButton) {
            printButton.addEventListener('click', prepareAndSubmitPrintForm);
        } else {
            console.error("Prepare Print Page button not found");
        }

    }); // End DOMContentLoaded
</script>
<?php
// Include footer
include('footer.php');
?>