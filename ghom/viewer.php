<?php
// public_html/ghom/viewer.php (FINAL VERSION with Touch, Counts, and Flicker Fix)

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
// --- Persian Date Conversion Function ---
function toPersianDate($gregorian_date)
{
    if (empty($gregorian_date)) return null;
    $timestamp = strtotime($gregorian_date);
    if ($timestamp === false) return $gregorian_date;
    return jdate('Y/m/d', $timestamp, '', 'Asia/Tehran', 'fa');
}

// --- Get Parameters ---
$plan_file = $_GET['plan'] ?? null;
$highlight_type = $_GET['highlight'] ?? 'all';
$highlight_status = $_GET['highlight_status'] ?? 'all';

if (!$plan_file || !preg_match('/^[\w\.-]+\.svg$/i', $plan_file)) {
    http_response_code(400);
    die("Error: Invalid plan file name.");
}
$zone_name = 'نامشخص';
$contractor_name = 'نامشخص';
$block_name = 'نامشخص';
$elements_data = [];
// Initialize status counts
$status_counts = [
    'OK' => 0,
    'Not OK' => 0,
    'Ready for Inspection' => 0,
    'Pending' => 0
];
$overall_progress = 0.00;
// --- Fetch Element Data ---
try {
    $pdo = getProjectDBConnection('ghom');
    // ... inside the try { ... } block
    $stmt = $pdo->prepare("
        WITH LatestStageInspections AS (
            -- Step 1: Find the most recent inspection for EACH stage of EACH element
            SELECT
                i.element_id,
                i.stage_id,
                i.overall_status,
                i.contractor_status,
                i.inspection_date,
                i.notes,
                i.contractor_notes,
                i.contractor_date,
                ROW_NUMBER() OVER(PARTITION BY i.element_id, i.stage_id ORDER BY i.created_at DESC, i.inspection_id DESC) as rn
            FROM inspections i
        ),
        OverallElementStatus AS (
            -- Step 2: Determine the single most critical status for each element
            SELECT
                element_id,
                -- Priority: 1 (highest) to 4 (lowest)
                MIN(CASE
                    WHEN overall_status = 'Not OK' THEN 1
                    WHEN contractor_status = 'Ready for Inspection' THEN 2
                    WHEN overall_status = 'Pending' THEN 3
                    WHEN overall_status IS NULL THEN 3
                    ELSE 4
                END) as highest_priority_status,
                COUNT(element_id) as inspection_count -- Count how many stages have inspections
            FROM LatestStageInspections
            WHERE rn = 1
            GROUP BY element_id
        )
        -- Step 3: Join everything together
        SELECT
            e.element_id,
            e.geometry_json, -- CHANGED: Fetch the JSON geometry
            e.element_type,
            e.zone_name,
            e.floor_level,
            e.plan_file,
            e.area_sqm,
            e.width_cm,
            e.height_cm, 
            e.contractor, 
            e.block,
            COALESCE(s.inspection_count, 0) as inspection_count,
            -- Determine the final status string based on the priority
            CASE s.highest_priority_status
                WHEN 1 THEN 'Not OK'
                WHEN 2 THEN 'Ready for Inspection'
                WHEN 3 THEN 'Pending'
                WHEN 4 THEN 'OK'
                ELSE 'Pending' -- Default for elements with no inspections
            END as final_status
        FROM
            elements e
        LEFT JOIN OverallElementStatus s ON e.element_id = s.element_id
        WHERE
            e.plan_file = ?
            AND e.geometry_json IS NOT NULL AND JSON_VALID(e.geometry_json) -- CHANGED: Check for valid JSON
    ");

    $stmt->execute([$plan_file]);
    $elements_data_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process data and count statuses for the legend
    $elements_data = array_map(function ($el) use (&$status_counts) {
        $status = $el['final_status'] ?? 'Pending';
        if (array_key_exists($status, $status_counts)) {
            $status_counts[$status]++;
        }
        return $el;
    }, $elements_data_raw);

    if (!empty($elements_data_raw)) {
        // Get context from the first element (they should all be the same for one plan)
        $first_element = $elements_data_raw[0];
        $zone_name = $first_element['zone_name'] ?? basename($plan_file);
        $contractor_name = $first_element['contractor'] ?? 'نامشخص';
        $block_name = $first_element['block'] ?? 'نامشخص';

        // Process all elements for the legend counts
        $elements_data = array_map(function ($el) use (&$status_counts) {
            $status = $el['final_status'] ?? 'Pending';
            if (array_key_exists($status, $status_counts)) {
                $status_counts[$status]++;
            }
            return $el;
        }, $elements_data_raw);
    } else {
        // Handle case where no elements are found for the plan
        $zone_name = basename($plan_file);
    }
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// --- STYLING & SVG PREPARATION ---
$element_styles_config = [
    'GFRC' => '#FB0200',
    'GLASS' => '#2986cc',
    'Mullion' => 'rgba(128, 128, 128, 0.9)',
    'Transom' => 'rgba(169, 169, 169, 0.9)',
    'Bazshow' => 'rgba(169, 169, 169, 0.9)',
    'Zirsazi' => '#2464ee',
    'STONE' => '#4c28a1'
];
$inactive_fill = '#d3d3d3';

$style_block = "\n";


$svg_path = realpath(__DIR__ . '') . '/' . $plan_file;
if (!file_exists($svg_path)) {
    http_response_code(404);
    die("Error: SVG file not found.");
}
$svg_content = file_get_contents($svg_path);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Viewer: <?php echo escapeHtml($plan_file); ?></title>
    <style>
        @font-face {
            font-family: "Samim";
            src: url("/ghom/assets/fonts/Samim-FD.woff2") format("woff2");
        }

        body,
        html {
            margin: 0;
            padding: 0;
            font-family: 'Samim', sans-serif;
            height: 100vh;
            overflow: hidden;
            background-color: #f0f0f0;
        }

        .viewer-wrapper {
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        #viewer-toolbar {
            padding: 8px;
            background: #fff;
            border-bottom: 1px solid #ccc;
            text-align: left;
            flex-shrink: 0;
            direction: ltr;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap-reverse;
            gap: 10px;
        }

        #viewer-toolbar .controls {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
        }

        #viewer-toolbar button {
            padding: 8px 14px;
            margin: 0 4px;
            cursor: pointer;
            border-radius: 4px;
            border: 1px solid #ccc;
            background-color: #f8f9fa;
            font-size: 1rem;
        }

        #legend {
            padding: 5px 10px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 15px;
            flex-grow: 1;
        }

        #legend span {
            display: inline-flex;
            align-items: center;
            margin: 0;
            font-size: 13px;
        }

        .legend-color {
            display: inline-block;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            border: 1px solid #555;
            vertical-align: middle;
            margin-left: 6px;
        }

        .legend-count {
            font-weight: bold;
            margin-right: 4px;
            background-color: #e9ecef;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
        }

        #svg-container-wrapper {
            flex-grow: 1;
            overflow: hidden;
            background: #e9ecef;
            cursor: grab;
            position: relative;
            touch-action: none;
            /* Prevents default touch actions like scrolling */
        }

        #svg-container-wrapper.grabbing {
            cursor: grabbing;
        }

        #svg-container {
            width: 100%;
            height: 100%;
            position: relative;
        }

        #svg-container svg {
            width: 100%;
            height: 100%;
            transform-origin: 0 0;
            position: absolute;
            top: 0;
            left: 0;
        }

        .highlight-marker {
            stroke: #000;
            stroke-width: 1px;
            transition: transform 0.15s ease, stroke-width 0.15s ease;
            cursor: pointer;
            transform-origin: center;
        }

        .highlight-marker text {
            display: none !important;
        }

        .marker-hover {
            stroke-width: 2px;
            transform: scale(1.2);
        }

        .svg-tooltip {
            position: fixed;
            background-color: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            pointer-events: none;
            z-index: 10000;
            max-width: 350px;
            line-height: 1.6;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.15s ease-in-out;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            white-space: nowrap;
        }

        .tooltip-visible {
            opacity: 1;
            visibility: visible;
        }

        .svg-tooltip strong {
            color: #ffc107;
        }

        #loading,
        #filter-no-results-message {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            font-size: 18px;
            z-index: 1000;
        }

        #loading {
            top: 50%;
            transform: translate(-50%, -50%);
        }

        #filter-no-results-message {
            top: 20px;
            background-color: rgba(255, 193, 7, 0.9);
            color: #333;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            display: none;
        }

        @media print {

            /* --- 1. Hide only the interactive/unnecessary elements --- */
            #viewer-toolbar .controls,
            /* Hide zoom, print, download buttons */
            #viewer-toolbar #legend,
            /* Hide the interactive color legend */
            #stage-filter-panel,
            /* Hide the workflow filter panel */
            .svg-tooltip {
                /* Hide the hover tooltip */
                display: none !important;
            }

            /* --- 2. Ensure the main toolbar and the new header ARE visible --- */
            #viewer-toolbar {
                display: flex !important;
                justify-content: flex-end !important;
                /* Aligns header to the right */
                border-bottom: 2px solid #000 !important;
                /* Add a solid line for print */
                padding: 10px 0 !important;
                background-color: #fff !important;
            }

            #viewer-header-info {
                display: flex !important;
                /* Make sure the header itself is visible */
                width: 100%;
                justify-content: center;
                /* Center the header info on the printed page */
                font-size: 14pt !important;
            }

            #viewer-header-info strong {
                color: #000 !important;
                /* Use black for better print contrast */
            }

            /* --- 3. Keep all the other essential print styles --- */
            body {
                background-color: #fff !important;
            }

            .viewer-wrapper {
                height: 100vh;
                display: flex;
                flex-direction: column;
            }

            #svg-container-wrapper {
                flex-grow: 1;
                height: 70vh;
                overflow: visible;
                border: 1px solid #ccc !important;
            }

            #status-chart-container {
                flex-shrink: 0;
                margin-top: 20px;
                page-break-before: auto;
            }

            #svg-container svg {
                /* Reset the view to default for a clean print */
                transform: translate(0, 0) scale(1) !important;
                width: 100%;
                height: 100%;
            }

            .chart-bar,
            .detail-color,
            #svg-container svg path,
            #svg-container svg rect {
                /* Force colors to print */
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }

        #status-chart-container {
            width: 90%;
            max-width: 800px;
            /* A good max-width for the chart */
            margin: 20px auto;
            padding: 15px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .chart-title {
            text-align: center;
            font-weight: bold;
            margin-bottom: 15px;
            color: #333;
            font-size: 1.1em;
        }

        .chart-bar-wrapper {
            display: flex;
            width: 100%;
            height: 35px;
            /* A slightly taller bar */
            border-radius: 6px;
            overflow: hidden;
            /* This makes the corners of the child bars appear rounded */
            border: 1px solid #e0e0e0;
        }

        .chart-bar {
            height: 100%;
            transition: width 0.6s ease-in-out;
            /* A smoother transition */
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: bold;
            white-space: nowrap;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.4);
        }

        .chart-details-wrapper {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-around;
            /* Distributes items evenly */
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }

        .chart-detail {
            display: flex;
            align-items: center;
            margin: 5px 10px;
            font-size: 14px;
        }

        .detail-color {
            width: 14px;
            height: 14px;
            border-radius: 3px;
            /* Changed to a square for differentiation */
            margin-left: 8px;
            flex-shrink: 0;
            /* Prevents the color box from shrinking */
        }

        .detail-label {
            color: #555;
            margin-left: 4px;
        }

        .detail-value {
            color: #000;
            font-weight: bold;
            direction: ltr;
            /* Ensures numbers and parens are displayed correctly */
            text-align: left;
        }

        /* Button Styles */
        .btn {
            padding: 8px 15px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            color: white;
            font-family: inherit;
            font-size: 0.9em;
            transition: background-color 0.2s;
        }

        .btn-load {
            background-color: #007bff;
        }

        .btn-load:hover {
            background-color: #0069d9;
        }

        .btn-save {
            background-color: #28a745;
        }

        .btn-new {
            background-color: #6c757d;
        }

        .btn-back {
            background-color: #5a6268;
            text-decoration: none;
            display: inline-block;

        }

        path[data-status],
        rect[data-status] {
            transition: fill 0.3s ease-in-out;
        }

        #stage-filter-panel {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 1000;
            width: 300px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
            border: 1px solid #ccc;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            padding: 12px 15px;
            background-color: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            border-radius: 8px 8px 0 0;
        }

        .panel-header h4 {
            margin: 0;
            color: #333;
        }

        .panel-body {
            padding: 15px;
        }

        .panel-body .form-group {
            margin-bottom: 15px;
        }

        .panel-body label {
            font-weight: bold;
            font-size: 13px;
            display: block;
            margin-bottom: 5px;
        }

        .panel-body select {
            width: 100%;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }

        .panel-footer {
            padding: 10px 15px;
            background-color: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
        }

        .panel-footer button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* --- NEW INSPECTION COUNT INDICATOR --- */
        .inspection-count-marker {
            fill: rgba(255, 255, 255, 0.9);
            stroke: #333;
            stroke-width: 0.5px;
        }

        .inspection-count-text {
            fill: #000;
            font-size: 4px;
            /* Will be scaled with the view */
            font-weight: bold;
            text-anchor: middle;
            dominant-baseline: middle;
            pointer-events: none;
            /* Text should not block clicks */
        }

        #totals-display {
            display: flex;
            gap: 20px;
            /* Space between the two total items */
            background-color: #e9ecef;
            padding: 8px 15px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            margin: 0 auto;
            /* Helps center it within the flex toolbar */
        }

        .total-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .total-label {
            font-size: 13px;
            color: #495057;
            font-weight: bold;
        }

        .total-value {
            font-size: 15px;
            font-weight: bold;
            color: #0056b3;
            /* A distinct blue color */
            background-color: #fff;
            padding: 3px 8px;
            border-radius: 4px;
            min-width: 40px;
            text-align: center;
        }

        .panel-summary {
            padding: 10px 15px;
            background-color: #f0f8ff;
            /* A light blue to stand out */
            border-top: 1px solid #e0e0e0;
            border-bottom: 1px solid #e0e0e0;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            padding: 4px 0;
        }

        .summary-item strong {
            font-size: 14px;
            color: #0056b3;
        }

        #viewer-header-info {
            display: flex;
            gap: 25px;
            /* Adds space between items */
            padding: 5px 15px;
            font-size: 14px;
            color: #333;
            /* Pushes it to the far right in the LTR toolbar container */
            margin-right: auto;
            direction: rtl;
            /* Ensures text inside the header is right-to-left */
            flex-wrap: wrap;
            /* Allow wrapping on smaller screens */
        }

        #viewer-header-info span {
            white-space: nowrap;
            /* Prevents text from wrapping unnecessarily */
        }

        #viewer-header-info strong {
            color: #0056b3;
            /* Highlight the dynamic data */
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="viewer-wrapper">
        <div id="viewer-toolbar">
            <div id="viewer-header-info">
                <span>نقشه فعلی: <strong><?php echo escapeHtml($zone_name); ?></strong></span>
                <span>پیمانکار: <strong><?php echo escapeHtml($contractor_name); ?></strong></span>
                <span>بلوک: <strong><?php echo escapeHtml($block_name); ?></strong></span>
            </div>
            <div id="legend">
                <span><span class="legend-color" style="background-color: #28a745;"></span>تایید شده<span class="legend-count" id="count-ok">0</span></span>
                <span><span class="legend-color" style="background-color: #dc3545;"></span>رد شده<span class="legend-count" id="count-not-ok">0</span></span>
                <span><span class="legend-color" style="background-color: #ffc107;"></span>آماده بازرسی<span class="legend-count" id="count-ready">0</span></span>
                <span><span class="legend-color" style="background-color: #cccccc;"></span>در انتظار<span class="legend-count" id="count-pending">0</span></span>
            </div>
            <div id="totals-display">
                <div class="total-item">
                    <span class="total-label">تعداد کل:</span>
                    <span id="total-count" class="total-value">0</span>
                </div>
                <div class="total-item">
                    <span class="total-label">مساحت کل (m²):</span>
                    <span id="total-area" class="total-value">0.00</span>
                </div>
            </div>
            <div class="controls">
                <button id="zoom-in-btn">+</button> <button id="zoom-out-btn">-</button> <button id="zoom-reset-btn">ریست</button>
                <button id="print-btn">چاپ</button> <button id="download-btn">دانلود SVG</button><a href="/ghom/inspection_dashboard.php" class="btn btn-back">بازگشت به داشبورد</a>
            </div>
        </div>
        <div id="stage-filter-panel">
            <div class="panel-header">
                <h4>نمایش وضعیت مراحل</h4>
            </div>
            <div class="panel-body">
                <div class="form-group">
                    <label for="type-select">۱. نوع المان را انتخاب کنید:</label>
                    <select id="type-select">
                        <option value="">-- همه انواع --</option>
                        <!-- Options will be added by JS -->
                    </select>
                </div>
                <div id="type-summary-panel" class="panel-summary" style="display: none;">
                    <div class="summary-item">
                        <span>تعداد کل این نوع:</span>
                        <strong id="type-total-count">0</strong>
                    </div>
                    <div class="summary-item">
                        <span>مساحت کل این نوع (m²):</span>
                        <strong id="type-total-area">0.00</strong>
                    </div>
                </div>
                <div class="form-group">
                    <label for="stage-select">۲. وضعیت این مرحله را نمایش بده:</label>
                    <select id="stage-select" disabled>
                        <option value="">-- ابتدا نوع المان را انتخاب کنید --</option>
                    </select>
                </div>
            </div>
            <div class="panel-footer">
                <button id="apply-stage-filter-btn" class="btn btn-load" disabled>اعمال فیلتر</button>
                <button id="reset-view-btn" class="btn btn-back">نمایش وضعیت کلی</button>
            </div>
        </div>
        <div id="svg-container-wrapper">
            <div id="loading">در حال بارگذاری...</div>
            <div id="svg-container"></div>
            <div id="filter-no-results-message"></div>
            <div class="svg-tooltip"></div>
        </div>
        <div id="status-chart-container">
            <div class="chart-title">خلاصه وضعیت المان‌ها</div>

            <div class="chart-bar-wrapper">
                <div class="chart-bar" id="chart-bar-ok" style="background-color: #28a745;"></div>
                <div class="chart-bar" id="chart-bar-not-ok" style="background-color: #dc3545;"></div>
                <div class="chart-bar" id="chart-bar-ready" style="background-color: #ffc107;"></div>
                <div class="chart-bar" id="chart-bar-pending" style="background-color: #cccccc;"></div>
            </div>

            <div class="chart-details-wrapper">
                <div class="chart-detail" id="detail-ok">
                    <span class="detail-color" style="background-color: #28a745;"></span>
                    <span class="detail-label">تایید شده:</span>
                    <span class="detail-value">0 (0%)</span>
                </div>
                <div class="chart-detail" id="detail-not-ok">
                    <span class="detail-color" style="background-color: #dc3545;"></span>
                    <span class="detail-label">رد شده:</span>
                    <span class="detail-value">0 (0%)</span>
                </div>
                <div class="chart-detail" id="detail-ready">
                    <span class="detail-color" style="background-color: #ffc107;"></span>
                    <span class="detail-label">آماده بازرسی:</span>
                    <span class="detail-value">0 (0%)</span>
                </div>
                <div class="chart-detail" id="detail-pending">
                    <span class="detail-color" style="background-color: #cccccc;"></span>
                    <span class="detail-label">در انتظار:</span>
                    <span class="detail-value">0 (0%)</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        const ALL_ELEMENTS_DATA = <?php echo json_encode($elements_data); ?>;
        const STATUS_COUNTS = <?php echo json_encode($status_counts); ?>;
        const SVG_CONTENT = <?php echo json_encode($svg_content); ?>;
        const HIGHLIGHT_STATUS = <?php echo json_encode($highlight_status); ?>;

        const STATUS_COLORS = {
            'OK': '#28a745',
            'Not OK': '#dc3545',
            'Ready for Inspection': '#ffc107',
            'Pending': '#cccccc'
        };


        let scale = 1,
            panX = 0,
            panY = 0,
            svgElement = null,
            tooltip = null,
            tooltipTimeout = null;

        document.addEventListener('DOMContentLoaded', () => {
            const container = document.getElementById('svg-container');
            const loadingEl = document.getElementById('loading');

            container.innerHTML = SVG_CONTENT;
            svgElement = container.querySelector('svg');
            if (!svgElement) {
                loadingEl.textContent = 'خطا: فایل SVG بارگیری نشد';
                return;
            }

            loadingEl.style.display = 'none';
            tooltip = document.querySelector('.svg-tooltip');

            requestAnimationFrame(() => {
                initializeViewer(document.getElementById('svg-container-wrapper'));
            });
        });

        function initializeViewer(wrapper) {
            updateLegendCounts(STATUS_COUNTS);
            updateStatusChart(STATUS_COUNTS);
            setupPanAndZoom(wrapper);
            setupToolbar();
            setupGlobalTooltipHandling();
            setupStageFilterPanel();

            // Initial render with overall status by default
            renderElementColors(ALL_ELEMENTS_DATA, HIGHLIGHT_STATUS);
        }
        /**
         * The main rendering function. Finds SVG elements, colorizes them,
         * and calculates total count and area of visible elements.
         * @param {Array} elementsToRender - The data for elements to be colored.
         * @param {string} [filterStatus='all'] - Optional status to filter by.
         */
        function renderElementColors(elementsToRender, filterStatus = 'all') {
            console.log(`Rendering colors. Filtering by status: ${filterStatus}`);
            const msgDiv = document.getElementById('filter-no-results-message');
            msgDiv.style.display = 'none';

            let visibleElementsCount = 0;
            let visibleElementsArea = 0.0;

            const elementDataMap = new Map(elementsToRender.map(el => [el.element_id, el]));

            svgElement.querySelectorAll('path[id], rect[id]').forEach(el => {
                // Reset styles
                el.style.fill = 'rgba(108, 117, 125, 0.2)';
                el.style.display = 'none';
                el.style.cursor = 'default';
            });

            svgElement.querySelectorAll('path[id], rect[id]').forEach(el => {
                if (elementDataMap.has(el.id)) {
                    const element = elementDataMap.get(el.id);
                    const status = element.final_status || 'Pending';

                    if (filterStatus === 'all' || status === filterStatus) {
                        visibleElementsCount++;
                        visibleElementsArea += parseFloat(element.area_sqm || 0);

                        el.style.display = '';
                        el.style.fill = STATUS_COLORS[status] || STATUS_COLORS['Pending'];
                        el.style.cursor = 'pointer';

                        // --- NEW: Richer Tooltip Event Listener ---
                        el.addEventListener('mouseenter', () => {
                            let content = `<strong>شناسه:</strong> ${element.element_id}<br>` +
                                `<strong>نوع:</strong> ${element.element_type}<br>` +
                                `<strong>ابعاد (cm):</strong> ${element.width_cm || 'N/A'} x ${element.height_cm || 'N/A'}<br>` + // Added dimensions
                                `<strong>مساحت:</strong> ${element.area_sqm || 'N/A'} m²<br>` +
                                `<strong>وضعیت کلی:</strong> ${status}`;
                            showTooltip(content);
                            el.style.stroke = '#000';
                            el.style.strokeWidth = '1px';
                        });
                        el.addEventListener('mouseleave', () => {
                            hideTooltip();
                            el.style.stroke = '';
                            el.style.strokeWidth = '';
                        });
                    }
                }
            });

            document.getElementById('total-count').textContent = visibleElementsCount;
            document.getElementById('total-area').textContent = visibleElementsArea.toFixed(2);

            if (visibleElementsCount === 0 && filterStatus !== 'all') {
                msgDiv.textContent = `هیچ موردی با وضعیت "${filterStatus}" یافت نشد.`;
                msgDiv.style.display = 'block';
            }
        }
        /**
         * The main rendering function. Clears old markers and draws new ones.
         * @param {Array} elementsToRender - The array of element data to display.
         */
        function renderMarkers(elementsToRender) {
            // Clear any existing markers
            svgElement.querySelectorAll('.status-marker-group').forEach(g => g.remove());

            if (!elementsToRender) return;

            const fragment = document.createDocumentFragment();

            elementsToRender.forEach(element => {
                if (!element.geometry_json) return;

                let geometry;
                try {
                    geometry = JSON.parse(element.geometry_json);
                } catch (e) {
                    console.error(`Invalid JSON for element ${element.element_id}:`, element.geometry_json);
                    return;
                }

                if (!Array.isArray(geometry) || geometry.length === 0) return;

                // Calculate the center point for the marker
                const totalPoints = geometry.length;
                const sum = geometry.reduce((acc, point) => [acc[0] + point[0], acc[1] + point[1]], [0, 0]);
                const centerX = sum[0] / totalPoints;
                const centerY = sum[1] / totalPoints;

                const status = element.final_status || 'Pending';
                const color = STATUS_COLORS[status] || STATUS_COLORS['Pending'];
                const inspectionCount = element.inspection_count || 0;

                const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                group.classList.add('status-marker-group');

                // Main status circle
                const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                circle.setAttribute('cx', centerX);
                circle.setAttribute('cy', centerY);
                circle.setAttribute('r', 5); // Radius can be adjusted
                circle.setAttribute('fill', color);
                circle.setAttribute('stroke', '#333');
                circle.setAttribute('stroke-width', 0.5);
                circle.style.cursor = 'pointer';
                group.appendChild(circle);

                // Inspection count indicator
                if (inspectionCount > 0) {
                    const countCircle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    countCircle.setAttribute('cx', centerX + 4);
                    countCircle.setAttribute('cy', centerY - 4);
                    countCircle.setAttribute('r', 2.5);
                    countCircle.classList.add('inspection-count-marker');
                    group.appendChild(countCircle);

                    const countText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    countText.setAttribute('x', centerX + 4);
                    countText.setAttribute('y', centerY - 4);
                    countText.classList.add('inspection-count-text');
                    countText.textContent = inspectionCount;
                    group.appendChild(countText);
                }

                // --- Tooltip Event Listeners ---
                group.addEventListener('mouseenter', () => {
                    let content = `<strong>شناسه:</strong> ${element.element_id}<br>` +
                        `<strong>وضعیت:</strong> ${status}<br>` +
                        `<strong>تعداد بازرسی:</strong> ${inspectionCount}`;
                    showTooltip(content);
                    circle.style.strokeWidth = '1.5px';
                });
                group.addEventListener('mouseleave', () => {
                    hideTooltip();
                    circle.style.strokeWidth = '0.5px';
                });

                fragment.appendChild(group);
            });

            svgElement.appendChild(fragment);
        }

        function setupStageFilterPanel() {
            const typeSelect = document.getElementById('type-select');
            const stageSelect = document.getElementById('stage-select');
            const applyBtn = document.getElementById('apply-stage-filter-btn');
            const resetBtn = document.getElementById('reset-view-btn');

            const summaryPanel = document.getElementById('type-summary-panel');
            const typeCountEl = document.getElementById('type-total-count');
            const typeAreaEl = document.getElementById('type-total-area');

            // Populate the element type dropdown
            const types = [...new Set(ALL_ELEMENTS_DATA.map(el => el.element_type))];
            types.sort().forEach(type => {
                const option = document.createElement('option');
                option.value = type;
                option.textContent = type;
                typeSelect.appendChild(option);
            });

            // Event when a type is selected
            typeSelect.addEventListener('change', async () => {
                const selectedType = typeSelect.value;
                stageSelect.innerHTML = '<option value="">در حال بارگذاری...</option>';
                stageSelect.disabled = true;
                applyBtn.disabled = true;

                if (selectedType) {
                    const elementsOfType = ALL_ELEMENTS_DATA.filter(el => el.element_type === selectedType);
                    const totalCount = elementsOfType.length;
                    const totalArea = elementsOfType.reduce((sum, el) => sum + parseFloat(el.area_sqm || 0), 0);

                    typeCountEl.textContent = totalCount;
                    typeAreaEl.textContent = totalArea.toFixed(2);
                    summaryPanel.style.display = 'block';
                } else {
                    summaryPanel.style.display = 'none';
                }

                if (!selectedType) {
                    stageSelect.innerHTML = '<option value="">-- ابتدا نوع المان را انتخاب کنید --</option>';
                    return;
                }

                try {
                    const response = await fetch(`/ghom/api/get_stages.php?type=${selectedType}`);
                    const stages = await response.json();

                    stageSelect.innerHTML = '<option value="">-- یک مرحله را انتخاب کنید --</option>';
                    if (stages && stages.length > 0) {
                        stages.forEach(stage => {
                            const option = document.createElement('option');
                            option.value = stage.stage_id;
                            option.textContent = stage.stage;
                            stageSelect.appendChild(option);
                        });
                        stageSelect.disabled = false;
                    } else {
                        stageSelect.innerHTML = '<option value="">مرحله‌ای یافت نشد</option>';
                    }
                } catch (error) {
                    console.error("Error fetching stages:", error);
                    stageSelect.innerHTML = '<option value="">خطا در بارگذاری</option>';
                }
            });

            stageSelect.addEventListener('change', () => {
                applyBtn.disabled = !stageSelect.value;
            });

            // ===========================================================
            // START: THIS IS THE CORRECTED AND COMPLETE CLICK HANDLER
            // ===========================================================
            applyBtn.addEventListener('click', async () => {
                const type = typeSelect.value;
                const stageId = stageSelect.value;
                if (!type || !stageId) return;

                // Show a loading indicator and disable the button to prevent double-clicks
                document.getElementById('loading').style.display = 'block';
                applyBtn.disabled = true;
                applyBtn.textContent = 'در حال بارگذاری...';

                try {
                    // Fetch the data for the specific stage from the API
                    const response = await fetch(`/ghom/api/get_stage_specific_status.php?plan=<?php echo $plan_file; ?>&type=${type}&stage=${stageId}`);
                    if (!response.ok) {
                        throw new Error(`Network response was not ok: ${response.statusText}`);
                    }
                    const stageSpecificData = await response.json();

                    // Re-render the map using only the data for the selected stage
                    renderElementColors(stageSpecificData, 'all');

                } catch (error) {
                    console.error("Error fetching stage-specific data:", error);
                    alert('خطا در اعمال فیلتر. لطفا دوباره تلاش کنید.');
                } finally {
                    // Hide loading indicator and re-enable the button
                    document.getElementById('loading').style.display = 'none';
                    applyBtn.disabled = false;
                    applyBtn.textContent = 'اعمال فیلتر';
                }
            });
            // ===========================================================
            // END: CORRECTED CLICK HANDLER
            // ===========================================================

            // Reset button logic (this was already correct)
            resetBtn.addEventListener('click', () => {
                renderElementColors(ALL_ELEMENTS_DATA, 'all');
                typeSelect.value = '';
                stageSelect.innerHTML = '<option value="">-- ابتدا نوع المان را انتخاب کنید --</option>';
                stageSelect.disabled = true;
                applyBtn.disabled = true;
                summaryPanel.style.display = 'none';
            });
        }

        function updateLegendCounts(counts) {
            document.getElementById('count-ok').textContent = counts['OK'] || 0;
            document.getElementById('count-not-ok').textContent = counts['Not OK'] || 0;
            document.getElementById('count-ready').textContent = counts['Ready for Inspection'] || 0;
            document.getElementById('count-pending').textContent = counts['Pending'] || 0;
        }

        function setupGlobalTooltipHandling() {
            document.addEventListener('mousemove', e => {
                if (tooltip.classList.contains('tooltip-visible')) {
                    tooltip.style.left = `${e.clientX + 15}px`;
                    tooltip.style.top = `${e.clientY - 10}px`;
                }
            });
            document.getElementById('svg-container-wrapper').addEventListener('mouseleave', () => hideTooltip());
        }

        function showTooltip(content) {
            if (tooltipTimeout) clearTimeout(tooltipTimeout);
            tooltip.innerHTML = content;
            tooltip.classList.add('tooltip-visible');
        }

        function hideTooltip() {
            tooltipTimeout = setTimeout(() => {
                tooltip.classList.remove('tooltip-visible');
            }, 50);
        }

        function setupPanAndZoom(wrapper) {
            let isPanning = false,
                startX = 0,
                startY = 0;
            let initialPinchDistance = null;

            const updateTransform = () => {
                svgElement.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
            };

            const handlePanStart = (clientX, clientY) => {
                isPanning = true;
                wrapper.classList.add('grabbing');
                startX = clientX - panX;
                startY = clientY - panY;
            };

            const handlePanMove = (clientX, clientY) => {
                if (!isPanning) return;
                panX = clientX - startX;
                panY = clientY - startY;
                updateTransform();
            };

            const handlePanEnd = () => {
                isPanning = false;
                wrapper.classList.remove('grabbing');
                initialPinchDistance = null;
            };

            const getPinchDistance = (touches) => Math.hypot(touches[0].clientX - touches[1].clientX, touches[0].clientY - touches[1].clientY);

            const handlePinchStart = (touches) => {
                initialPinchDistance = getPinchDistance(touches);
            };

            const handlePinchMove = (touches) => {
                if (initialPinchDistance === null) return;
                const newDist = getPinchDistance(touches);
                const delta = newDist / initialPinchDistance;

                const rect = wrapper.getBoundingClientRect();
                const midX = (touches[0].clientX + touches[1].clientX) / 2;
                const midY = (touches[0].clientY + touches[1].clientY) / 2;
                const xs = (midX - rect.left - panX) / scale;
                const ys = (midY - rect.top - panY) / scale;

                const newScale = Math.max(0.1, Math.min(10, scale * delta));
                panX += xs * scale - xs * newScale;
                panY += ys * scale - ys * newScale;
                scale = newScale;

                updateTransform();
                initialPinchDistance = newDist; // Update for continuous zoom
            };

            // Mouse Events
            wrapper.addEventListener('mousedown', e => {
                if (e.target.closest('.highlight-marker')) return;
                handlePanStart(e.clientX, e.clientY);
                e.preventDefault();
            });
            wrapper.addEventListener('mousemove', e => handlePanMove(e.clientX, e.clientY));
            wrapper.addEventListener('mouseup', handlePanEnd);
            wrapper.addEventListener('wheel', e => {
                e.preventDefault();
                const r = wrapper.getBoundingClientRect();
                const xs = (e.clientX - r.left - panX) / scale;
                const ys = (e.clientY - r.top - panY) / scale;
                const d = e.deltaY > 0 ? 0.9 : 1.1;
                const newScale = Math.max(0.1, Math.min(10, scale * d));
                panX += xs * scale - xs * newScale;
                panY += ys * scale - ys * newScale;
                scale = newScale;
                updateTransform();
            });

            // Touch Events
            wrapper.addEventListener('touchstart', e => {
                if (e.target.closest('.highlight-marker')) return;
                e.preventDefault();
                if (e.touches.length === 1) handlePanStart(e.touches[0].clientX, e.touches[0].clientY);
                else if (e.touches.length === 2) handlePinchStart(e.touches);
            }, {
                passive: false
            });

            wrapper.addEventListener('touchmove', e => {
                e.preventDefault();
                if (e.touches.length === 1) handlePanMove(e.touches[0].clientX, e.touches[0].clientY);
                else if (e.touches.length === 2) handlePinchMove(e.touches);
            }, {
                passive: false
            });

            wrapper.addEventListener('touchend', e => {
                if (e.touches.length < 2) initialPinchDistance = null;
                if (e.touches.length === 0) handlePanEnd();
            });
        }

        // REPLACE your old addStatusCircles function with this one.
        function applyStatusStyles() {
            const msgDiv = document.getElementById('filter-no-results-message');
            msgDiv.style.display = 'none';
            let visibleElements = 0;

            // A map for status colors, now with more opacity
            const STATUS_COLORS = {
                'OK': 'rgba(40, 167, 69, 0.8)', // Green
                'Not OK': 'rgba(220, 53, 69, 0.8)', // Red
                'Ready for Inspection': 'rgba(255, 193, 7, 0.8)', // Yellow
                'Pending': 'rgba(108, 117, 125, 0.5)' // Grey
            };

            // First, reset all panel colors to a default "pending" state
            svgElement.querySelectorAll('path, rect').forEach(el => {
                if (el.id && el.id.startsWith('Z')) { // Target only elements with your generated IDs
                    el.style.setProperty('fill', STATUS_COLORS['Pending'], 'important');
                }
            });

            ELEMENTS_DATA.forEach(element => {
                if (!element.element_id) return;

                // Find the main panel element directly by its ID
                const panelElement = svgElement.getElementById(element.element_id);
                if (!panelElement) return;

                const status = (element.final_status || 'Pending').trim();
                const matchesFilter = (HIGHLIGHT_STATUS === 'all' || status === HIGHLIGHT_STATUS);

                // Add a data-status attribute for CSS and tooltips
                panelElement.dataset.status = status;

                if (matchesFilter) {
                    visibleElements++;
                    panelElement.style.display = '';

                    // Apply the status color directly to the panel's fill
                    const color = STATUS_COLORS[status] || STATUS_COLORS['Pending'];
                    panelElement.style.setProperty('fill', color, 'important');

                    // --- Attach Event Listeners for Tooltip ---
                    panelElement.addEventListener('mouseenter', e => {
                        let content = `<strong>شناسه:</strong> ${element.element_id}<br>` +
                            `<strong>وضعیت:</strong> ${status}<br>` +
                            `<strong>طبقه:</strong> ${element.floor_level || 'N/A'}`;
                        showTooltip(content);
                    });

                    panelElement.addEventListener('mouseleave', e => {
                        hideTooltip();
                    });

                } else {
                    // If it doesn't match the filter, hide it
                    panelElement.style.display = 'none';
                }
            });

            if (visibleElements === 0 && ELEMENTS_DATA.length > 0 && HIGHLIGHT_STATUS !== 'all') {
                msgDiv.textContent = `هیچ موردی با وضعیت "${HIGHLIGHT_STATUS}" یافت نشد.`;
                msgDiv.style.display = 'block';
            }
        }

        function setupToolbar() {
            const updateAndZoom = (newScale) => {
                scale = newScale;
                svgElement.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
            };
            document.getElementById('zoom-in-btn').onclick = () => updateAndZoom(Math.min(10, scale * 1.2));
            document.getElementById('zoom-out-btn').onclick = () => updateAndZoom(Math.max(0.1, scale / 1.2));
            document.getElementById('zoom-reset-btn').onclick = () => {
                panX = 0;
                panY = 0;
                updateAndZoom(1);
            };
            document.getElementById('print-btn').onclick = () => window.print();
            document.getElementById('download-btn').onclick = () => {
                try {
                    const originalTransform = svgElement.style.transform;
                    svgElement.style.transform = '';
                    const svgData = new XMLSerializer().serializeToString(svgElement);
                    const blob = new Blob([svgData], {
                        type: 'image/svg+xml;charset=utf-8'
                    });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = '<?php echo escapeHtml($plan_file); ?>';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    svgElement.style.transform = originalTransform;
                } catch (error) {
                    alert('خطا در دانلود فایل: ' + error.message);
                }
            };
        }

        function updateStatusChart() {
            const counts = STATUS_COUNTS;
            const total = (counts['OK'] || 0) + (counts['Not OK'] || 0) + (counts['Ready for Inspection'] || 0) + (counts['Pending'] || 0);

            const chartContainer = document.getElementById('status-chart-container');
            if (total === 0) {
                if (chartContainer) chartContainer.style.display = 'none';
                return;
            }

            if (chartContainer) chartContainer.style.display = 'block';

            const statusMap = {
                'ok': 'OK',
                'not-ok': 'Not OK',
                'ready': 'Ready for Inspection',
                'pending': 'Pending'
            };

            for (const [key, statusName] of Object.entries(statusMap)) {
                const count = counts[statusName] || 0;
                const percentage = (count / total) * 100;

                // Update the visual percentage bar
                const bar = document.getElementById(`chart-bar-${key}`);
                if (bar) {
                    bar.style.width = `${percentage}%`;
                    bar.title = `${statusName}: ${count} (${Math.round(percentage)}%)`;
                }

                // Update the new detailed text label
                const detailValue = document.querySelector(`#detail-${key} .detail-value`);
                if (detailValue) {
                    detailValue.textContent = `${count} (${Math.round(percentage)}%)`;
                }
            }
        }
    </script>
</body>

</html>