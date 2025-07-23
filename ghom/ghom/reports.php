<?php
// public_html/ghom/reports.php (FINAL, ADAPTED FOR YOUR SCHEMA & CORRECTED)

// Ensure these files are correctly included and configure your database connection.
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once __DIR__ . '/includes/jdf.php';

secureSession();
if (!isLoggedIn()) {
    header('Location: /login.php?msg=login_required');
    exit();
}
if (!in_array($_SESSION['role'], ['admin', 'supervisor', 'user', 'superuser'])) {
    http_response_code(403);
    require 'Access_Denied.php';
    exit;
}
$pageTitle = "گزارشات پروژه قم";
require_once __DIR__ . '/header_ghom.php';

// This would normally be in your header file.
// require_once __DIR__ . '/header_ghom.php'; 

try {
    // This now uses your actual database connection function.
    $pdo = getProjectDBConnection('ghom');
    $pdo->exec("SET NAMES 'utf8mb4'");

    // Query 1: For the main dashboard
    $all_inspections_raw = $pdo->query("
        SELECT 
            i.inspection_id, i.element_id, i.part_name, e.element_type, e.zone_name, e.block, e.contractor,
            i.overall_status, i.contractor_status, i.inspection_date, i.contractor_date,
            u.first_name, u.last_name
        FROM inspections i
        JOIN elements e ON i.element_id = e.element_id
        LEFT JOIN hpc_common.users u ON i.user_id = u.id
        ORDER BY i.inspection_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Query 2: For the Stage Progress chart
    $stage_progress_raw = $pdo->query("
        SELECT
            e.zone_name, e.element_type, ci.stage, id.item_status,
            COUNT(id.id) as status_count
        FROM elements e
        JOIN inspections i ON e.element_id = i.element_id
        JOIN inspection_data id ON i.inspection_id = id.inspection_id
        JOIN checklist_items ci ON id.item_id = ci.item_id
        WHERE e.zone_name IS NOT NULL AND e.element_type IS NOT NULL AND ci.stage IS NOT NULL AND id.item_status IS NOT NULL
        GROUP BY e.zone_name, e.element_type, ci.stage, id.item_status
        ORDER BY e.zone_name, e.element_type, ci.item_order
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Query 3: For the new Flexible Report (by Block and Contractor)
    $flexible_report_raw = $pdo->query("
        SELECT
            e.block,
            e.contractor,
            e.element_type,
            i.overall_status,
            i.contractor_status,
            COUNT(DISTINCT i.inspection_id) as inspection_count
        FROM elements e
        JOIN inspections i ON e.element_id = i.element_id
        WHERE e.block IS NOT NULL AND e.block != '' AND e.contractor IS NOT NULL AND e.contractor != ''
        GROUP BY e.block, e.contractor, e.element_type, i.overall_status, i.contractor_status
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Query 4: For Coverage Charts
    $total_elements_by_zone = $pdo->query("SELECT zone_name, COUNT(element_id) as total_count FROM elements WHERE zone_name IS NOT NULL AND zone_name != '' GROUP BY zone_name")->fetchAll(PDO::FETCH_KEY_PAIR);
    $inspected_elements_by_zone = $pdo->query("SELECT e.zone_name, COUNT(DISTINCT e.element_id) as inspected_count FROM elements e JOIN inspections i ON e.element_id = i.element_id WHERE e.zone_name IS NOT NULL AND e.zone_name != '' GROUP BY e.zone_name")->fetchAll(PDO::FETCH_KEY_PAIR);
    $total_elements_by_block = $pdo->query("SELECT block, COUNT(element_id) as total_count FROM elements WHERE block IS NOT NULL AND block != '' GROUP BY block")->fetchAll(PDO::FETCH_KEY_PAIR);
    $inspected_elements_by_block = $pdo->query("SELECT e.block, COUNT(DISTINCT e.element_id) as inspected_count FROM elements e JOIN inspections i ON e.element_id = i.element_id WHERE e.block IS NOT NULL AND e.block != '' GROUP BY e.block")->fetchAll(PDO::FETCH_KEY_PAIR);
    $total_elements_overall = $pdo->query("SELECT COUNT(element_id) FROM elements")->fetchColumn();
    $inspected_elements_overall = $pdo->query("SELECT COUNT(DISTINCT element_id) FROM inspections")->fetchColumn();

    // 1. Prepare the main dataset for JavaScript
    $allInspectionsData = array_map(function ($row) {
        $final_status = 'در حال اجرا';
        if ($row['overall_status'] === 'OK') $final_status = 'تایید شده';
        elseif ($row['overall_status'] === 'Has Issues') $final_status = 'دارای ایراد';
        elseif ($row['contractor_status'] === 'Ready for Inspection') $final_status = 'آماده بازرسی';

        $inspector_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

        $contractor_days_passed = '---';
        if (!empty($row['contractor_date']) && $row['contractor_date'] !== '0000-00-00') {
            try {
                $today = new DateTime();
                $contractor_date_obj = new DateTime($row['contractor_date']);
                $interval = $today->diff($contractor_date_obj);
                if ($today > $contractor_date_obj) {
                    $contractor_days_passed = $interval->days . ' روز پیش';
                } else if ($interval->days == 0) {
                    $contractor_days_passed = 'موعد امروز';
                } else {
                    $contractor_days_passed = $interval->days . ' روز مانده';
                }
            } catch (Exception $e) {
                // Keep default value if date is invalid
            }
        }

        $inspection_date_formatted = '---';
        if (!empty($row['inspection_date']) && strpos($row['inspection_date'], '0000-00-00') === false) {
            $inspection_date_formatted = jdate('Y/m/d', strtotime($row['inspection_date']));
        }

        return [
            'element_id' => $row['element_id'],
            'part_name' => $row['part_name'] ?: '---',
            'element_type' => $row['element_type'],
            'zone_name' => $row['zone_name'] ?: 'N/A',
            'block' => $row['block'] ?: 'N/A',
            'final_status' => $final_status,
            'contractor' => $row['contractor'],
            'inspector' => $inspector_name ?: 'نامشخص',
            'inspection_date_raw' => $row['inspection_date'],
            'inspection_date' => $inspection_date_formatted,
            'contractor_days_passed' => $contractor_days_passed
        ];
    }, $all_inspections_raw);

    // 2. Prepare trend data
    $trendData = ['daily' => [], 'weekly' => [], 'monthly' => []];
    foreach ($all_inspections_raw as $row) {
        if (empty($row['inspection_date']) || strpos($row['inspection_date'], '0000-00-00') !== false) continue;
        $timestamp = strtotime($row['inspection_date']);
        $status = $row['overall_status'] ?: 'Pending';
        $day = jdate('Y-m-d', $timestamp);
        $week = jdate('o-W', $timestamp);
        $month = jdate('Y-m', $timestamp);
        $daily_counts[$day][$status] = ($daily_counts[$day][$status] ?? 0) + 1;
        $weekly_counts[$week][$status] = ($weekly_counts[$week][$status] ?? 0) + 1;
        $monthly_counts[$month][$status] = ($monthly_counts[$month][$status] ?? 0) + 1;
    }
    foreach (($daily_counts ?? []) as $date => $statuses) foreach ($statuses as $status => $count) $trendData['daily'][] = ['date' => $date, 'status' => $status, 'count' => $count];
    foreach (($weekly_counts ?? []) as $date => $statuses) foreach ($statuses as $status => $count) $trendData['weekly'][] = ['date' => $date, 'status' => $status, 'count' => $count];
    foreach (($monthly_counts ?? []) as $date => $statuses) foreach ($statuses as $status => $count) $trendData['monthly'][] = ['date' => $date, 'status' => $status, 'count' => $count];

    // 3. Prepare Stage Progress data
    $stageProgressData = [];
    foreach ($stage_progress_raw as $row) {
        $zone = $row['zone_name'];
        $type = $row['element_type'];
        $stage = $row['stage'];
        $status = $row['item_status'];
        $count = $row['status_count'];
        if (!isset($stageProgressData[$zone])) $stageProgressData[$zone] = [];
        if (!isset($stageProgressData[$zone][$type])) $stageProgressData[$zone][$type] = [];
        if (!isset($stageProgressData[$zone][$type][$stage])) $stageProgressData[$zone][$type][$stage] = [];
        $stageProgressData[$zone][$type][$stage][$status] = $count;
    }

    // 4. Prepare Flexible Report data
    $flexibleReportData = [];
    foreach ($flexible_report_raw as $row) {
        $final_status = 'در حال اجرا';
        if ($row['overall_status'] === 'OK') $final_status = 'تایید شده';
        elseif ($row['overall_status'] === 'Has Issues') $final_status = 'دارای ایراد';
        elseif ($row['contractor_status'] === 'Ready for Inspection') $final_status = 'آماده بازرسی';

        $block = $row['block'];
        $contractor = $row['contractor'];
        $type = $row['element_type'];
        $count = $row['inspection_count'];
        if (!isset($flexibleReportData[$block])) $flexibleReportData[$block] = [];
        if (!isset($flexibleReportData[$block][$contractor])) $flexibleReportData[$block][$contractor] = [];
        if (!isset($flexibleReportData[$block][$contractor][$type])) $flexibleReportData[$block][$contractor][$type] = [];
        $flexibleReportData[$block][$contractor][$type][$final_status] = ($flexibleReportData[$block][$contractor][$type][$final_status] ?? 0) + $count;
    }
    // 5. Prepare Coverage Data
    $coverageData = ['by_zone' => [], 'by_block' => [], 'overall' => []];
    foreach ($total_elements_by_zone as $zone => $total) {
        $coverageData['by_zone'][$zone] = ['total' => $total, 'inspected' => $inspected_elements_by_zone[$zone] ?? 0];
    }
    foreach ($total_elements_by_block as $block => $total) {
        $coverageData['by_block'][$block] = ['total' => $total, 'inspected' => $inspected_elements_by_block[$block] ?? 0];
    }
    $all_zones_stmt = $pdo->query("SELECT DISTINCT plan_file FROM elements WHERE plan_file IS NOT NULL AND plan_file != '' ORDER BY plan_file");
    $all_zones = $all_zones_stmt->fetchAll(PDO::FETCH_COLUMN);

    // 1. CORRECTED DATA QUERY: Fetches one row per inspection part, not per element.
    $all_data_stmt = $pdo->query("
    WITH LatestInspections AS (
        SELECT 
            i.*,
            ROW_NUMBER() OVER(PARTITION BY i.element_id, i.part_name ORDER BY i.created_at DESC, i.inspection_id DESC) as rn
        FROM inspections i
    )
    SELECT
        li.inspection_id, li.element_id, li.part_name,
        li.contractor_status, li.contractor_date, li.contractor_notes, li.contractor_attachments,
        li.overall_status, li.inspection_date, li.notes AS consultant_notes, li.attachments,
        e.element_type, e.plan_file, e.zone_name, e.axis_span, e.floor_level, e.contractor, e.block
    FROM LatestInspections li
    JOIN elements e ON li.element_id = e.element_id
    WHERE li.rn = 1
    ORDER BY li.created_at DESC;
");
    $all_inspection_data = $all_data_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group data by element type for the tabs
    $data_by_type = [];
    foreach ($all_inspection_data as $row) {
        $data_by_type[$row['element_type']][] = $row;
    }
    $coverageData['overall'] = ['total' => $total_elements_overall, 'inspected' => $inspected_elements_overall];
    $stmtr = $pdo->query("SELECT COUNT(*) FROM inspections WHERE contractor_status = 'Ready for Inspection'");
    $readyfi = $stmtr->fetchColumn();
    $stmtno = $pdo->query("SELECT COUNT(*) FROM inspections WHERE overall_status = 'Not OK'");
    $readyno = $stmtno->fetchColumn();
    $stmtok = $pdo->query("SELECT COUNT(*) FROM inspections WHERE overall_status = 'OK'");
    $readyok = $stmtok->fetchColumn();
    $stmtpen = $pdo->query("SELECT COUNT(*) FROM inspections WHERE overall_status = 'Pending'");
    $readypen = $stmtpen->fetchColumn();
    // Calculate global status counts from the detailed inspection data
    $global_status_counts = ['Ready for Inspection' => 0, 'OK' => 0, 'Not OK' => 0, 'Pending' => 0, 'Total' => count($all_inspection_data)];
    foreach ($all_inspection_data as $inspection) {
        $status = 'Pending';
        if (!empty($inspection['overall_status'])) $status = $inspection['overall_status'];
        elseif ($inspection['contractor_status'] === 'Ready for Inspection') $status = 'Ready for Inspection';
        if (isset($global_status_counts[$status])) $global_status_counts[$status]++;
    }
} catch (Exception $e) {
    error_log("Database Error in reports.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده. لطفا با پشتیبانی تماس بگیرید.");
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="/ghom/assets/js/chart.umd.min.js"></script>
    <link rel="stylesheet" href="/ghom/assets/css/jalalidatepicker.min.css" />
    <style>
        @font-face {
            font-family: "Samim";
            src: url("/ghom/assets/fonts/Samim-FD.woff2") format("woff2"),
                url("/ghom/assets/fonts/Samim-FD.woff") format("woff"),
                url("/ghom/assets/fonts/Samim-FD.ttf") format("truetype");
        }

        :root {
            --bg-color: #f8fafc;
            --text-color: #1e293b;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --primary: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --secondary: #64748b;
            --accent: #8b5cf6;
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
        }

        html.dark {
            --bg-color: #0f172a;
            --text-color: #f1f5f9;
            --card-bg: #1e293b;
            --border-color: #334155;
            --primary: #60a5fa;
            --success: #34d399;
            --warning: #fbbf24;
            --danger: #f87171;
            --secondary: #94a3b8;
            --accent: #a78bfa;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Samim", -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            direction: rtl;
            padding: 20px;
            line-height: 1.6;
            min-height: 100vh;
            background-image: radial-gradient(circle at 25% 25%, rgba(59, 130, 246, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(139, 92, 246, 0.05) 0%, transparent 50%);
        }

        .dashboard-grid {
            max-width: 1900px;
            margin: 0 auto;
            display: grid;
            gap: 30px;
        }

        .section-container {
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 30px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .section-container:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .section-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 20px;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--border-color);
        }

        .section-header h1,
        .section-header h2 {
            font-size: 1.8em;
            font-weight: 700;
            color: var(--text-color);
        }

        .theme-switcher {
            padding: 10px;
            cursor: pointer;
            border-radius: 12px;
            border: none;
            background: var(--bg-color);
            color: var(--text-color);
            font-size: 1.4em;
            transition: all 0.3s ease;
        }

        .theme-switcher:hover {
            transform: scale(1.1);
        }

        .kpi-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 25px;
        }

        .kpi-card {
            background: var(--bg-color);
            padding: 25px;
            border-radius: 12px;
            border-left: 5px solid var(--primary);
            transition: all 0.3s ease;
            text-align: center;
        }

        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
        }

        .kpi-card h3 {
            margin: 0 0 10px;
            font-size: 1em;
            font-weight: 600;
            color: var(--secondary);
        }

        .kpi-card .value {
            font-size: 2.5em;
            font-weight: 700;
            color: var(--text-color);
        }

        .kpi-card .details {
            font-size: 0.9em;
            color: var(--secondary);
            margin-top: 5px;
        }

        .kpi-card.ok {
            border-color: var(--success);
        }

        .kpi-card.ready {
            border-color: var(--warning);
        }

        .kpi-card.issues {
            border-color: var(--danger);
        }

        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            /* Mobile-friendly fix */
            gap: 30px;
            margin-top: 30px;
        }

        .chart-wrapper {
            background: var(--bg-color);
            padding: 25px;
            border-radius: 12px;
            height: 450px;
        }

        .chart-wrapper h3 {
            text-align: center;
            margin: 0 0 20px;
            font-size: 1.1em;
            font-weight: 600;
        }

        .date-trend-controls {
            text-align: center;
            margin-bottom: 20px;
        }

        .date-trend-controls button {
            padding: 8px 16px;
            margin: 0 5px;
            cursor: pointer;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-color);
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .date-trend-controls button.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .filter-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            margin-bottom: 8px;
            font-size: 0.9em;
            font-weight: 600;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: inherit;
        }

        .filter-group .btn {
            padding: 10px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            color: white;
            font-weight: bold;
            height: 40px;
            transition: background-color 0.2s;
        }

        .btn-secondary {
            background-color: var(--secondary);
        }

        .btn-secondary:hover {
            background-color: #7c8a9e;
        }

        .table-container {
            margin-top: 30px;
        }

        .table-container h3 {
            margin-bottom: 15px;
        }

        .table-wrapper {
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 15px;
            text-align: right;
            border-bottom: 1px solid var(--border-color);
        }

        thead th {
            background-color: var(--card-bg);
            position: sticky;
            top: 0;
            z-index: 1;
            cursor: pointer;
            user-select: none;
        }

        thead th.sort.asc::after,
        thead th.sort.desc::after {
            font-size: 0.8em;
        }

        thead th.sort.asc::after {
            content: " ▲";
        }

        thead th.sort.desc::after {
            content: " ▼";
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background-color: rgba(120, 120, 120, 0.05);
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 0.85em;
            color: white;
        }

        .scrollable-chart-container {
            position: relative;
            height: calc(100% - 60px);
            /* Adjust based on controls height */
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
    </style>
</head>

<body>
    <div class="dashboard-grid">
        <div class="table-container" style="margin-bottom: 20px; background-color: #e9f5ff;">
            <h2>مشاهده وضعیت کلی در نقشه</h2>
            <div class="filters">
                <div class="form-group"><label>1. انتخاب فایل نقشه:</label><select id="report-zone-select"><?php foreach ($all_zones as $zone): ?><option value="<?php echo escapeHtml($zone); ?>"><?php echo escapeHtml($zone); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>2. مشاهده همه المان‌ها با وضعیت:</label>
                    <button type="button" class="btn report-btn" data-status="Ready for Inspection" style="background-color:rgb(178, 220, 53);">آماده بازرسی (<?php echo number_format($readyfi); ?>)</button>
                    <button type=" button" class="btn report-btn" data-status="Not OK" style="background-color: #dc3545;">رد شده (<?php echo number_format($readyno); ?>)</button>
                    <button type="button" class="btn report-btn" data-status="OK" style="background-color: #28a745;">تایید شده (<?php echo number_format($readyok); ?>)</button>
                    <button type="button" class="btn report-btn" data-status="Pending" style="background-color:rgb(85, 40, 167);">قطعی نشده (<?php echo number_format($readypen); ?>)</button>
                    <button type="button" class="btn report-btn" data-status="all" style="background-color: #17a2b8;">همه وضعیت‌ها (<?php echo $global_status_counts['Total']; ?>)</button>
                </div>
            </div>
        </div>
        <!-- SECTION 1: STATIC OVERALL REPORT -->
        <div class="section-container">
            <div class="section-header">
                <h1>گزارش کلی پروژه</h1>
                <button class="theme-switcher">🌓</button>
            </div>
            <div class="kpi-container" id="static-kpi-container"></div>
            <div class="charts-container">
                <div class="chart-wrapper">
                    <h3>خلاصه وضعیت کلی</h3><canvas id="staticOverallProgressChart"></canvas>
                </div>
                <div class="chart-wrapper">
                    <h3>تفکیک نوع المان</h3><canvas id="staticProgressByTypeChart"></canvas>
                </div>
            </div>
        </div>

        <!-- SECTION 2: INSPECTION COVERAGE (NEW) -->
        <div class="section-container">
            <div class="section-header">
                <h2>پوشش بازرسی</h2>
            </div>
            <div class="kpi-container" style="grid-template-columns: 1fr; max-width: 400px; margin: 0 auto 30px auto;">
                <div class="kpi-card" id="overall-coverage-kpi">
                    <h3>پوشش کلی بازرسی</h3>
                    <p class="value" id="overall-coverage-value">0%</p>
                    <div class="details" id="overall-coverage-details">0 از 0 المان</div>
                </div>
            </div>
            <div class="charts-container">
                <div class="chart-wrapper">
                    <h3>پوشش بازرسی به تفکیک زون</h3><canvas id="coverageByZoneChart"></canvas>
                </div>
                <div class="chart-wrapper">
                    <h3>پوشش بازرسی به تفکیک بلوک</h3><canvas id="coverageByBlockChart"></canvas>
                </div>
            </div>
        </div>

        <!-- SECTION 3: STAGE PROGRESS REPORT -->
        <div class="section-container">
            <div class="section-header">
                <h2>گزارش پیشرفت مراحل</h2>
            </div>
            <div class="filter-bar" style="gap: 20px 30px;">
                <div class="filter-group"><label for="stage-filter-zone">انتخاب زون:</label><select id="stage-filter-zone">
                        <option value="">ابتدا یک زون انتخاب کنید</option>
                    </select></div>
                <div class="filter-group"><label for="stage-filter-type">انتخاب نوع المان:</label><select id="stage-filter-type" disabled>
                        <option value="">-</option>
                    </select></div>
            </div>
            <div class="chart-wrapper" style="height: 500px; width: 100%; margin-top: 30px;">
                <h3 id="stage-chart-title">برای مشاهده نمودار، یک زون و نوع المان انتخاب کنید</h3>
                <canvas id="stageProgressChart"></canvas>
            </div>
        </div>

        <!-- SECTION 4: FLEXIBLE BLOCK/CONTRACTOR REPORT -->
        <div class="section-container">
            <div class="section-header">
                <h2>گزارش جامع پیمانکاران و بلوک‌ها</h2>
            </div>
            <div class="filter-bar" style="gap: 20px 30px;">
                <div class="filter-group"><label for="flexible-filter-block">انتخاب بلوک:</label><select id="flexible-filter-block">
                        <option value="">ابتدا یک بلوک انتخاب کنید</option>
                    </select></div>
                <div class="filter-group"><label for="flexible-filter-contractor">انتخاب پیمانکار:</label><select id="flexible-filter-contractor" disabled>
                        <option value="">-</option>
                    </select></div>
            </div>
            <div class="chart-wrapper" style="height: 500px; width: 100%; margin-top: 30px;">
                <h3 id="flexible-chart-title">برای مشاهده نمودار، یک بلوک و پیمانکار انتخاب کنید</h3>
                <canvas id="flexibleReportChart"></canvas>
            </div>
        </div>

        <!-- SECTION 5: DATE TRENDS -->
        <div class="section-container">
            <div class="section-header">
                <h2>روند بازرسی‌ها در طول زمان</h2>
            </div>
            <div class="chart-wrapper" style="height: 500px; width: 100%;">
                <div class="date-trend-controls">
                    <button class="date-view-btn active" data-view="daily">روزانه</button>
                    <button class="date-view-btn" data-view="weekly">هفتگی</button>
                    <button class="date-view-btn" data-view="monthly">ماهانه</button>
                </div>
                <canvas id="dateTrendChart"></canvas>
            </div>
        </div>

        <!-- SECTION 6: DYNAMIC & FILTERABLE REPORT -->
        <div class="section-container">
            <div class="section-header">
                <h2>گزارشات پویا و فیلترها</h2>
            </div>
            <div class="filter-bar">
                <div class="filter-group"><label for="filter-search">جستجوی کلی:</label><input type="text" id="filter-search" placeholder="کد، نوع، زون..."></div>
                <div class="filter-group"><label for="filter-type">نوع المان:</label><select id="filter-type">
                        <option value="">همه</option>
                    </select></div>
                <div class="filter-group"><label for="filter-status">وضعیت:</label><select id="filter-status">
                        <option value="">همه</option>
                    </select></div>
                <div class="filter-group"><label for="filter-date-start">تاریخ از:</label><input type="text" id="filter-date-start" data-jdp></div>
                <div class="filter-group"><label for="filter-date-end">تاریخ تا:</label><input type="text" id="filter-date-end" data-jdp></div>
                <div class="filter-group"><button id="clear-filters-btn" class="btn btn-secondary">پاک کردن</button></div>
            </div>
            <hr style="border:none; border-top: 1px solid var(--border-color); margin: 30px 0;">
            <div class="kpi-container" id="filtered-kpi-container"></div>
            <div class="charts-container">
                <div class="chart-wrapper"><canvas id="filteredStatusChart"></canvas>
                    <h3>خلاصه وضعیت (فیلتر شده)</h3>
                </div>
                <div class="chart-wrapper"><canvas id="filteredTypeChart"></canvas>
                    <h3>تفکیک نوع (فیلتر شده)</h3>
                </div>
                <div class="chart-wrapper"><canvas id="filteredBlockChart"></canvas>
                    <h3>تفکیک بلوک (فیلتر شده)</h3>
                </div>
                <div class="chart-wrapper"><canvas id="filteredZoneChart"></canvas>
                    <h3>تفکیک زون (فیلتر شده)</h3>
                </div>
            </div>
            <div class="table-container">
                <h3>نتایج (<span id="table-result-count">0</span> رکورد)</h3>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th class="sort" data-sort="element_id">کد</th>
                                <th class="sort" data-sort="part_name">بخش</th>
                                <th class="sort" data-sort="element_type">نوع</th>
                                <th class="sort" data-sort="zone_name">زون</th>
                                <th class="sort" data-sort="block">بلوک</th>
                                <th class="sort" data-sort="final_status">وضعیت</th>
                                <th class="sort" data-sort="inspector">بازرس</th>
                                <th class="sort" data-sort="inspection_date">تاریخ بازرسی</th>
                                <th class="sort" data-sort="contractor_days_passed">فاصله از تاریخ پیمانکار</th>
                            </tr>
                        </thead>
                        <tbody id="dynamic-table-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="/ghom/assets/js/jalalidatepicker.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- DATA FROM PHP ---
            const allInspectionsData = <?php echo json_encode($allInspectionsData); ?>;
            const trendData = <?php echo json_encode($trendData); ?>;
            const stageProgressData = <?php echo json_encode($stageProgressData); ?>;
            const flexibleReportData = <?php echo json_encode($flexibleReportData); ?>;
            const coverageData = <?php echo json_encode($coverageData); ?>;
            let currentlyDisplayedData = [...allInspectionsData];
            let currentSort = {
                key: 'inspection_date',
                dir: 'desc'
            };

            // --- GLOBAL VARS ---
            const chartInstances = {};
            let statusColors = {},
                trendStatusColors = {},
                itemStatusColors = {};

            const domRefs = {
                htmlEl: document.documentElement,
                themeSwitcher: document.querySelector('.theme-switcher'),
                staticKpiContainer: document.getElementById('static-kpi-container'),
                filteredKpiContainer: document.getElementById('filtered-kpi-container'),
                searchInput: document.getElementById('filter-search'),
                typeSelect: document.getElementById('filter-type'),
                statusSelect: document.getElementById('filter-status'),
                startDateEl: document.getElementById('filter-date-start'),
                endDateEl: document.getElementById('filter-date-end'),
                clearFiltersBtn: document.getElementById('clear-filters-btn'),
                tableBody: document.getElementById('dynamic-table-body'),
                resultCountEl: document.getElementById('table-result-count'),
                tableHeaders: document.querySelectorAll('th.sort'),
                dateViewButtons: document.querySelectorAll('.date-view-btn'),
                stageZoneFilter: document.getElementById('stage-filter-zone'),
                stageTypeFilter: document.getElementById('stage-filter-type'),
                stageChartTitle: document.getElementById('stage-chart-title'),
                flexibleBlockFilter: document.getElementById('flexible-filter-block'),
                flexibleContractorFilter: document.getElementById('flexible-filter-contractor'),
                flexibleChartTitle: document.getElementById('flexible-chart-title'),
                overallCoverageValue: document.getElementById('overall-coverage-value'),
                overallCoverageDetails: document.getElementById('overall-coverage-details'),
            };

            // --- COLOR HELPER ---
            function getCssVar(varName) {
                return getComputedStyle(document.documentElement).getPropertyValue(varName).trim();
            }

            function updateChartColors() {
                statusColors = {
                    'تایید شده': getCssVar('--success'),
                    'دارای ایراد': getCssVar('--danger'),
                    'آماده بازرسی': getCssVar('--warning'),
                    'در حال اجرا': getCssVar('--secondary'),
                };
                trendStatusColors = {
                    'OK': getCssVar('--success'),
                    'Has Issues': getCssVar('--danger'),
                    'Ready for Inspection': getCssVar('--warning'),
                    'Pending': getCssVar('--secondary')
                };
                itemStatusColors = {
                    'OK': getCssVar('--success'),
                    'Not OK': getCssVar('--danger'),
                    'N/A': getCssVar('--secondary')
                };
            }

            // --- INITIALIZATION ---
            function initializeDashboard() {
                updateChartColors();
                setupTheme();
                setupFilters();
                setupStageFilters();
                setupFlexibleReportFilters();
                setupDatePickers();
                setupSorting();
                setupEventListeners();
                renderStaticSection(allInspectionsData);
                renderCoverageCharts();
                renderTrendChart('daily');
                updateFilteredSection(allInspectionsData);
                renderStageProgressChart();
                renderFlexibleReportChart();
            }

            // --- SETUP FUNCTIONS ---
            function setupTheme() {
                domRefs.themeSwitcher.addEventListener('click', () => {
                    domRefs.htmlEl.classList.toggle('dark');
                    setTimeout(() => {
                        updateChartColors();
                        renderStaticSection(allInspectionsData);
                        renderCoverageCharts();
                        renderTrendChart(document.querySelector('.date-view-btn.active').dataset.view);
                        updateFilteredSection(currentlyDisplayedData);
                        renderStageProgressChart();
                        renderFlexibleReportChart();
                    }, 100);
                });
            }

            function setupFilters() {
                const elementTypes = [...new Set(allInspectionsData.map(item => item.element_type))].filter(Boolean).sort();
                const statuses = [...new Set(allInspectionsData.map(item => item.final_status))].filter(Boolean).sort();
                elementTypes.forEach(type => domRefs.typeSelect.add(new Option(type, type)));
                statuses.forEach(status => domRefs.statusSelect.add(new Option(status, status)));
            }

            function setupStageFilters() {
                const zones = Object.keys(stageProgressData).sort();
                domRefs.stageZoneFilter.innerHTML = '<option value="">ابتدا یک زون انتخاب کنید</option>';
                zones.forEach(zone => domRefs.stageZoneFilter.add(new Option(zone, zone)));
                domRefs.stageZoneFilter.addEventListener('change', () => {
                    const selectedZone = domRefs.stageZoneFilter.value;
                    domRefs.stageTypeFilter.innerHTML = '<option value="">-</option>';
                    domRefs.stageTypeFilter.disabled = true;
                    if (selectedZone && stageProgressData[selectedZone]) {
                        const types = Object.keys(stageProgressData[selectedZone]).sort();
                        types.forEach(type => domRefs.stageTypeFilter.add(new Option(type, type)));
                        domRefs.stageTypeFilter.disabled = false;
                    }
                    renderStageProgressChart();
                });
                domRefs.stageTypeFilter.addEventListener('change', renderStageProgressChart);
            }

            function setupFlexibleReportFilters() {
                const blocks = Object.keys(flexibleReportData).sort();
                domRefs.flexibleBlockFilter.innerHTML = '<option value="">ابتدا یک بلوک انتخاب کنید</option>';
                blocks.forEach(block => domRefs.flexibleBlockFilter.add(new Option(block, block)));
                domRefs.flexibleBlockFilter.addEventListener('change', () => {
                    const selectedBlock = domRefs.flexibleBlockFilter.value;
                    domRefs.flexibleContractorFilter.innerHTML = '<option value="">-</option>';
                    domRefs.flexibleContractorFilter.disabled = true;
                    if (selectedBlock && flexibleReportData[selectedBlock]) {
                        const contractors = Object.keys(flexibleReportData[selectedBlock]).sort();
                        contractors.forEach(c => domRefs.flexibleContractorFilter.add(new Option(c, c)));
                        domRefs.flexibleContractorFilter.disabled = false;
                    }
                    renderFlexibleReportChart();
                });
                domRefs.flexibleContractorFilter.addEventListener('change', renderFlexibleReportChart);
            }



            function setupDatePickers() {
                if (typeof jalaliDatepicker !== 'undefined') {
                    jalaliDatepicker.startWatch({
                        selector: '[data-jdp]',
                        autoHide: true,
                        onSelect: applyAllFilters
                    });
                }
            }

            function setupSorting() {
                domRefs.tableHeaders.forEach(header => {
                    if (header.dataset.sort === currentSort.key) {
                        header.classList.add(currentSort.dir);
                    }
                    header.addEventListener('click', () => {
                        const key = header.dataset.sort;
                        currentSort.dir = (currentSort.key === key && currentSort.dir === 'desc') ? 'asc' : 'desc';
                        currentSort.key = key;
                        domRefs.tableHeaders.forEach(th => th.classList.remove('asc', 'desc'));
                        header.classList.add(currentSort.dir);
                        sortAndRenderTable(currentlyDisplayedData);
                    });
                });
            }

            function setupEventListeners() {
                ['input', 'change'].forEach(evt => {
                    domRefs.searchInput.addEventListener(evt, applyAllFilters);
                    domRefs.typeSelect.addEventListener(evt, applyAllFilters);
                    domRefs.statusSelect.addEventListener(evt, applyAllFilters);
                });
                domRefs.clearFiltersBtn.addEventListener('click', () => {
                    domRefs.searchInput.value = '';
                    domRefs.typeSelect.value = '';
                    domRefs.statusSelect.value = '';
                    domRefs.startDateEl.value = '';
                    domRefs.endDateEl.value = '';
                    applyAllFilters();
                });
                domRefs.dateViewButtons.forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        domRefs.dateViewButtons.forEach(b => b.classList.remove('active'));
                        e.target.classList.add('active');
                        renderTrendChart(e.target.dataset.view);
                    });
                });
            }
            // --- CORE LOGIC ---
            function applyAllFilters() {
                const search = domRefs.searchInput.value.toLowerCase();
                const type = domRefs.typeSelect.value;
                const status = domRefs.statusSelect.value;
                const startDate = (domRefs.startDateEl.datepicker && domRefs.startDateEl.value) ? new Date(domRefs.startDateEl.datepicker.gDate).getTime() : 0;
                const endDate = (domRefs.endDateEl.datepicker && domRefs.endDateEl.value) ? new Date(domRefs.endDateEl.datepicker.gDate).setHours(23, 59, 59, 999) : Infinity;

                const filteredData = allInspectionsData.filter(item => {
                    const itemDate = item.inspection_date_raw ? new Date(item.inspection_date_raw).getTime() : 0;
                    const matchesDate = !itemDate ? false : (itemDate >= startDate && itemDate <= endDate);
                    const matchesType = !type || item.element_type === type;
                    const matchesStatus = !status || item.final_status === status;
                    const matchesSearch = !search || Object.values(item).some(val => String(val).toLowerCase().includes(search));
                    return matchesDate && matchesType && matchesStatus && matchesSearch;
                });

                // Update the global variable for other functions to use
                currentlyDisplayedData = filteredData;
                // Update all dynamic components with the filtered data
                updateFilteredSection(filteredData);
            }


            function sortAndRenderTable(dataToSort) {
                const {
                    key,
                    dir
                } = currentSort;
                const direction = dir === 'asc' ? 1 : -1;

                const sortedData = [...dataToSort].sort((a, b) => {
                    let valA = a[key];
                    let valB = b[key];

                    if (key === 'inspection_date') {
                        valA = a.inspection_date_raw ? new Date(a.inspection_date_raw).getTime() : 0;
                        valB = b.inspection_date_raw ? new Date(b.inspection_date_raw).getTime() : 0;
                    }

                    if (valA == null || valA === '---') return 1 * direction;
                    if (valB == null || valB === '---') return -1 * direction;

                    if (typeof valA === 'string') {
                        return valA.localeCompare(valB, 'fa') * direction;
                    }
                    return (valA < valB ? -1 : valA > valB ? 1 : 0) * direction;
                });

                renderTable(sortedData);
            }

            // --- RENDER FUNCTIONS ---
            function renderStaticSection(data) {
                renderKPIs(data, domRefs.staticKpiContainer, false);
                renderDoughnutChart('staticOverallProgressChart', data);
                renderStackedBarChart('staticProgressByTypeChart', data, 'element_type');
            }


            function updateFilteredSection(data) {
                renderKPIs(data, domRefs.filteredKpiContainer, true);
                renderDoughnutChart('filteredStatusChart', data);
                renderStackedBarChart('filteredTypeChart', data, 'element_type');
                renderStackedBarChart('filteredBlockChart', data, 'block');
                renderStackedBarChart('filteredZoneChart', data, 'zone_name');
                domRefs.resultCountEl.textContent = data.length.toLocaleString('fa');
                // After all charts are updated, sort and render the table with the same filtered data
                sortAndRenderTable(data);
            }


            function renderKPIs(data, container, isFiltered) {
                const kpi = {
                    total: data.length,
                    ok: data.filter(d => d.final_status === 'تایید شده').length,
                    ready: data.filter(d => d.final_status === 'آماده بازرسی').length,
                    issues: data.filter(d => d.final_status === 'دارای ایراد').length,
                };
                container.innerHTML = `
                    <div class="kpi-card"><h3 >کل ${isFiltered ? '(فیلتر شده)' : ''}</h3><p class="value">${kpi.total.toLocaleString('fa')}</p></div>
                    <div class="kpi-card ok"><h3>تایید شده</h3><p class="value">${kpi.ok.toLocaleString('fa')}</p></div>
                    <div class="kpi-card ready"><h3>آماده بازرسی</h3><p class="value">${kpi.ready.toLocaleString('fa')}</p></div>
                    <div class="kpi-card issues"><h3>دارای ایراد</h3><p class="value">${kpi.issues.toLocaleString('fa')}</p></div>
                `;
            }


            function renderTable(data) {
                domRefs.tableBody.innerHTML = data.length === 0 ?
                    '<tr><td colspan="9" style="text-align:center; padding: 20px;">هیچ رکوردی یافت نشد.</td></tr>' :
                    data.map(row => `
                        <tr>
                            <td>${row.element_id}</td><td>${row.part_name}</td><td>${row.element_type}</td>
                            <td>${row.zone_name}</td><td>${row.block}</td>
                            <td><span class="status-badge" style="background-color:${statusColors[row.final_status] || getCssVar('--secondary')};">${row.final_status}</span></td>
                            <td>${row.inspector}</td><td>${row.inspection_date}</td><td>${row.contractor_days_passed}</td>
                        </tr>`).join('');
            }
            // --- CHARTING FUNCTIONS ---
            function getChartBaseOptions(tooltipCallbacks = {}) {
                const textColor = getCssVar('--text-color');
                const gridColor = getCssVar('--border-color');
                const isMobile = window.innerWidth < 768;
                Chart.defaults.font.family = 'Samim';
                return {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: isMobile ? 'top' : 'bottom',
                            labels: {
                                color: textColor,
                                font: {
                                    size: 12
                                },
                                boxWidth: 20
                            }
                        },
                        tooltip: {
                            bodyFont: {
                                family: 'Samim'
                            },
                            titleFont: {
                                family: 'Samim'
                            },
                            callbacks: tooltipCallbacks
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                color: textColor,
                                autoSkip: true,
                                maxRotation: 0,
                                minRotation: 0
                            },
                            grid: {
                                color: gridColor
                            },
                            stacked: false
                        },
                        y: {
                            ticks: {
                                color: textColor
                            },
                            grid: {
                                color: gridColor
                            },
                            beginAtZero: true,
                            stacked: false
                        }
                    }
                };
            }

            function renderChart(canvasId, config) {
                if (chartInstances[canvasId]) chartInstances[canvasId].destroy();
                const ctx = document.getElementById(canvasId)?.getContext('2d');
                if (ctx) chartInstances[canvasId] = new Chart(ctx, config);
            }

            function renderDoughnutChart(canvasId, data) {
                const counts = data.reduce((acc, item) => {
                    acc[item.final_status] = (acc[item.final_status] || 0) + 1;
                    return acc;
                }, {});
                const chartData = {
                    labels: Object.keys(counts),
                    datasets: [{
                        data: Object.values(counts),
                        backgroundColor: Object.keys(counts).map(status => statusColors[status]),
                        borderColor: getCssVar('--card-bg'),
                        borderWidth: 2,
                    }]
                };
                renderChart(canvasId, {
                    type: 'doughnut',
                    data: chartData,
                    options: getChartBaseOptions()
                });
            }

            function renderStackedBarChart(canvasId, data, groupBy) {
                const grouped = data.reduce((acc, item) => {
                    const key = item[groupBy] || 'نامشخص';
                    if (!acc[key]) acc[key] = {};
                    acc[key][item.final_status] = (acc[key][item.final_status] || 0) + 1;
                    return acc;
                }, {});
                const labels = Object.keys(grouped).sort();
                const datasets = Object.keys(statusColors).map(status => ({
                    label: status,
                    data: labels.map(label => grouped[label][status] || 0),
                    backgroundColor: statusColors[status],
                }));
                const options = getChartBaseOptions();
                options.scales.x.stacked = true;
                options.scales.y.stacked = true;
                renderChart(canvasId, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets
                    },
                    options
                });
            }

            function renderCoverageCharts() {
                // Overall KPI
                const overall = coverageData.overall;
                const percentage = overall.total > 0 ? ((overall.inspected / overall.total) * 100).toFixed(1) : 0;
                domRefs.overallCoverageValue.textContent = `${percentage}%`;
                domRefs.overallCoverageDetails.textContent = `${overall.inspected.toLocaleString('fa')} از ${overall.total.toLocaleString('fa')} المان`;

                // By Zone Chart
                renderCoverageBarChart('coverageByZoneChart', coverageData.by_zone);

                // By Block Chart
                renderCoverageBarChart('coverageByBlockChart', coverageData.by_block);
            }

            function renderCoverageBarChart(canvasId, data) {
                const labels = Object.keys(data).sort();
                const datasets = [{
                        label: 'المان‌های کل',
                        data: labels.map(l => data[l].total),
                        backgroundColor: getCssVar('--secondary') + '80'
                    },
                    {
                        label: 'المان‌های بازرسی شده',
                        data: labels.map(l => data[l].inspected),
                        backgroundColor: getCssVar('--primary')
                    }
                ];
                renderChart(canvasId, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets
                    },
                    options: getChartBaseOptions()
                });
            }

            function renderTrendChart(view) {
                const data = trendData[view] || [];
                const grouped = data.reduce((acc, item) => {
                    if (!acc[item.date]) acc[item.date] = {};
                    acc[item.date][item.status] = item.count;
                    return acc;
                }, {});

                const labels = Object.keys(grouped).sort();
                const datasets = Object.keys(trendStatusColors).map(status => ({
                    label: status,
                    data: labels.map(label => grouped[label][status] || 0),
                    borderColor: trendStatusColors[status],
                    backgroundColor: trendStatusColors[status] + '33', // Add transparency
                    fill: false,
                    tension: 0.2,
                }));

                const options = getChartBaseOptions();

                renderChart('dateTrendChart', {
                    type: 'line',
                    data: {
                        labels,
                        datasets
                    },
                    options
                });
            }

            function renderStageProgressChart() {
                const zone = domRefs.stageZoneFilter.value;
                const type = domRefs.stageTypeFilter.value;
                if (chartInstances['stageProgressChart']) chartInstances['stageProgressChart'].destroy();
                if (!zone || !type) {
                    domRefs.stageChartTitle.textContent = 'برای مشاهده نمودار، یک زون و نوع المان انتخاب کنید';
                    return;
                }
                const dataForChart = stageProgressData[zone]?.[type];
                if (!dataForChart || Object.keys(dataForChart).length === 0) {
                    domRefs.stageChartTitle.textContent = `داده‌ای برای ${type} در ${zone} یافت نشد`;
                    return;
                }
                domRefs.stageChartTitle.textContent = `پیشرفت مراحل برای ${type} در ${zone}`;
                const labels = Object.keys(dataForChart);
                const datasets = Object.keys(itemStatusColors).map(status => ({
                    label: status,
                    data: labels.map(stage => dataForChart[stage]?.[status] || 0),
                    backgroundColor: itemStatusColors[status],
                }));
                const options = getChartBaseOptions();
                options.scales.x.stacked = true;
                options.scales.y.stacked = true;

                renderChart('stageProgressChart', {
                    type: 'bar',
                    data: {
                        labels,
                        datasets
                    },
                    options
                });
            }

            function renderFlexibleReportChart() {
                const block = domRefs.flexibleBlockFilter.value;
                const contractor = domRefs.flexibleContractorFilter.value;

                if (chartInstances['flexibleReportChart']) chartInstances['flexibleReportChart'].destroy();

                if (!block || !contractor) {
                    domRefs.flexibleChartTitle.textContent = 'برای مشاهده نمودار، یک بلوک و پیمانکار انتخاب کنید';
                    return;
                }

                const dataForChart = flexibleReportData[block]?.[contractor];
                if (!dataForChart || Object.keys(dataForChart).length === 0) {
                    domRefs.flexibleChartTitle.textContent = `داده‌ای برای پیمانکار ${contractor} در بلوک ${block} یافت نشد`;
                    return;
                }

                domRefs.flexibleChartTitle.textContent = `وضعیت المان‌ها برای پیمانکار ${contractor} در بلوک ${block}`;

                const labels = Object.keys(dataForChart); // These are the element types
                const datasets = Object.keys(statusColors).map(status => ({
                    label: status,
                    data: labels.map(type => dataForChart[type]?.[status] || 0),
                    backgroundColor: statusColors[status],
                }));

                const tooltipCallbacks = {
                    label: function(context) {
                        const label = context.dataset.label || '';
                        const count = context.raw || 0;
                        const total = context.chart.data.datasets.reduce((sum, ds) => sum + (ds.data[context.dataIndex] || 0), 0);
                        const percentage = total > 0 ? ((count / total) * 100).toFixed(1) : 0;
                        return `${label}: ${count} (${percentage}%)`;
                    }
                };

                const options = getChartBaseOptions(tooltipCallbacks);
                options.scales.x.stacked = true;
                options.scales.y.stacked = true;


                renderChart('flexibleReportChart', {
                    type: 'bar',
                    data: {
                        labels,
                        datasets
                    },
                    options
                });
            }

            // --- START THE APP ---
            initializeDashboard();
            // Re-assign functions that were defined inside other functions to the global scope for clarity
            this.renderStaticSection = renderStaticSection;
            this.updateFilteredSection = updateFilteredSection;
            this.renderKPIs = renderKPIs;
            this.renderTable = renderTable;
            this.getChartBaseOptions = getChartBaseOptions;
            this.renderChart = renderChart;
            this.renderDoughnutChart = renderDoughnutChart;
            this.renderStackedBarChart = renderStackedBarChart;
            this.renderTrendChart = renderTrendChart;
            this.sortAndRenderTable = sortAndRenderTable;
            this.setupSorting = setupSorting;
            document.querySelectorAll('.report-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const planFile = document.getElementById('report-zone-select').value;
                    const statusToHighlight = this.dataset.status;
                    if (!planFile) {
                        alert('لطفا ابتدا یک فایل نقشه را انتخاب کنید.');
                        return;
                    }
                    let url = `/ghom/viewer.php?plan=${encodeURIComponent(planFile)}`;
                    if (statusToHighlight !== 'all') {
                        url += `&highlight_status=${encodeURIComponent(statusToHighlight)}`;
                    }
                    window.open(url, '_blank');
                });
            });
        });
    </script>
    <?php require_once 'footer.php'; ?>
</body>

</html>