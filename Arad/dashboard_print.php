<?php
// dashboard_print.php
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
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// --- Authentication ---
if (!isset($_SESSION['user_id'])) { // Basic check
    header('Location: login.php');
    exit('دسترسی غیر مجاز! لطفاً وارد شوید.');
}

// --- Get POST Data ---
$filtersJson = $_POST['filters'] ?? '{}';
$chartImages = [];
foreach ($_POST as $key => $value) {
    if (strpos($key, 'chart_img_') === 0) {
        // Basic check: Does it look like base64 image data?
        if (preg_match('/^data:image\/(png|jpeg|gif);base64,/', $value)) {
            $chartKey = substr($key, strlen('chart_img_'));
            $chartImages[$chartKey] = $value; // Store the full base64 string
        } else {
            error_log("Invalid image data received for key: " . $key);
        }
    }
}
$filters = json_decode($filtersJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $filters = []; // Default to empty if JSON is invalid
    error_log("Invalid filters JSON received: " . $filtersJson);
}

// --- Prepare Filter Display String ---
$filterDisplayItems = []; // Initialize array to hold parts of the filter string


// Status Filter
if (isset($filters['status']) && $filters['status'] !== 'all' && $filters['status'] !== '') {
    $statusMapPrint = [
        'pending' => 'در انتظار',
        'polystyrene' => 'قالب‌/فوم',
        'Mesh' => 'مشبندی',
        'Concreting' => 'قالب‌بندی/بتن‌ریزی',
        'Assembly' => 'فیس کوت',
        'completed' => 'تکمیل شده',
        'shipped' => 'ارسال شده'
    ];
    $filterDisplayItems[] = "وضعیت: " . ($statusMapPrint[$filters['status']] ?? htmlspecialchars($filters['status']));
}

// Priority Filter
if (isset($filters['priority']) && $filters['priority'] !== 'all' && $filters['priority'] !== '') {
    $filterDisplayItems[] = "اولویت: " . htmlspecialchars($filters['priority']);
}

// Date From Filter - Check input format before converting
$shamsiFrom = '';
if (isset($filters['date_from']) && !empty($filters['date_from'])) {
    $gregorianDateFrom = toLatinDigitsPhp($filters['date_from']); // <<< CONVERT TO LATIN FIRST
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $gregorianDateFrom)) {   // <<< VALIDATE LATIN VERSION
        $shamsiFrom = gregorianToShamsi($gregorianDateFrom);         // <<< CONVERT LATIN VERSION
        if (!empty($shamsiFrom)) {
            $filterDisplayItems[] = "از تاریخ: " . htmlspecialchars($shamsiFrom);
        } else {
            error_log("Print Page: Failed to convert 'date_from' (after Latin conversion): " . $gregorianDateFrom);
        }
    } else {
        error_log("Print Page: Invalid format received/converted for 'date_from': " . $filters['date_from'] . " -> " . $gregorianDateFrom);
    }
}

// Date To Filter
$shamsiTo = '';
if (isset($filters['date_to']) && !empty($filters['date_to'])) {
    $gregorianDateTo = toLatinDigitsPhp($filters['date_to']); // <<< CONVERT TO LATIN FIRST
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $gregorianDateTo)) {   // <<< VALIDATE LATIN VERSION
        $shamsiTo = gregorianToShamsi($gregorianDateTo);         // <<< CONVERT LATIN VERSION
        if (!empty($shamsiTo)) {
            $filterDisplayItems[] = "تا تاریخ: " . htmlspecialchars($shamsiTo);
        } else {
            error_log("Print Page: Failed to convert 'date_to' (after Latin conversion): " . $gregorianDateTo);
        }
    } else {
        error_log("Print Page: Invalid format received/converted for 'date_to': " . $filters['date_to'] . " -> " . $gregorianDateTo);
    }
}

// Build final string
if (!empty($filterDisplayItems)) {
    $filterDisplayString = implode(' | ', $filterDisplayItems);
} else {
    $filterDisplayString = 'بدون فیلتر';
}

// --- Define Chart Titles (Match the keys used in JS) ---
$chartTitles = [
    'filtered' => 'تعداد تجمعی پنل در آخرین وضعیت (فیلتر شده)',
    'daily_line' => 'روند روزانه تکمیل مراحل (تعداد روزانه)',
    'cumulative_line' => 'روند تجمعی تکمیل مراحل',
    'cumulative_step_bar' => 'تعداد تجمعی پنل در هر مرحله (فیلتر شده)',
    'status_pie' => 'وضعیت پنل‌ها ', // Non-filtered
    'weekly' => 'تولید هفتگی ',      // Non-filtered
    'time_avg' => 'زمان تکمیل مراحل (میانگین به روز - کلی)', // Non-filtered
    'step_progress' => 'پیشرفت مراحل تولید ',         // Non-filtered
    'monthly_prod' => 'روند تولید ماهانه ',          // Non-filtered
    'assigned_daily' => 'تخصیص پنل‌ها (روزانه - کلی)',     // Non-filtered
    'priority_status_percent' => 'درصد وضعیت پنل‌ها بر اساس اولویت ', // Non-filtered
    'priority_step_cumulative' => 'تعداد/درصد تجمعی مراحل بر اساس اولویت ' // Non-filtered
];
$chartsAffectedByFilters = [
    'filtered',
    'daily_line',
    'cumulative_line',
    'cumulative_step_bar'
];

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>چاپ داشبورد گزارشات</title>
    <style>
        /* --- Enhanced Base Print Styles & Fonts --- */
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
            font-weight: normal;
            font-style: normal;
        }

        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Bold.woff2') format('woff2');
            font-weight: bold;
            font-style: normal;
        }

        body {
            font-family: Vazir, Tahoma, sans-serif;
            direction: rtl;
            line-height: 1.4;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
            /* Light gray background for screen view */
            color: #000;
        }

        .print-page-container {
            width: 210mm;
            margin: 0 auto 1cm auto;
            padding: 1.5cm;
            box-sizing: border-box;
            page-break-after: always;
            border: double 4px #444;
            /* Darker double border for better B&W contrast */
            position: relative;
            min-height: 26.7cm;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            /* Subtle shadow for screen display */
            background-color: #fff;
        }

        .print-page-container:last-child {
            page-break-after: avoid;
        }

        .print-header {
            border: double 3px #333;
            /* Dark double border for header */
            border-radius: 2px;
            padding: 12px;
            margin-bottom: 20px;
            min-height: 60px;
            width: 100%;
            flex-shrink: 0;
            background-color: #f8f8f8;
            /* Very light gray background */
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .print-footer {
            width: 100%;
            font-size: 9pt;
            color: #000;
            /* Black text for better B&W clarity */
            border-top: double 3px #333;
            /* Dark double border for footer */
            padding-top: 8px;
            margin-top: auto;
            flex-shrink: 0;
            position: relative;
            height: 30px;
            box-sizing: border-box;
        }

        .header-table {
            width: 100%;
            border: none;
            table-layout: fixed;
            margin-bottom: 0;
        }

        .header-table td {
            border: none;
            vertical-align: middle;
            padding: 0 5px;
        }

        .chart-print-area {
            flex-grow: 1;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }

        .logo-cell {
            width: 20%;
        }

        .logo-left {
            text-align: right;
        }

        .logo-right {
            text-align: left;
        }

        .header-center {
            width: 60%;
            text-align: center;
            font-size: 16pt;
            /* Larger title for better prominence */
            font-weight: bold;
            line-height: 1.4;
            color: #000;
            /* Black text for better B&W clarity */
        }

        .print-header img {
            /* <<< CORRECTED SELECTOR */
            max-height: 45px;
            /* Keep desired max height */
            max-width: 180px;
            /* <<< ADDED: Limit width (adjust value as needed) */
            height: auto;
            /* <<< ADDED: Maintain aspect ratio */
            width: auto;
            /* Allow browser to determine width based on height/max-width */
            vertical-align: middle;
            /* Optional: Add a contrast boost for B&W printing */
            filter: contrast(1.1);
        }

        .chart-print-container {
            text-align: center;
            margin-bottom: 1.5cm;
            page-break-inside: avoid;
            border: double 3px #333;
            /* Dark double border for chart container */
            border-radius: 2px;
            padding: 20px;
            width: 90%;
            box-sizing: border-box;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            background-color: #fff;
        }

        .chart-print-container.priority-chart {
            width: 100%;
            /* Allow priority charts to use full width */
            max-width: 100%;
        }

        .chart-print-container.priority-chart img {
            max-height: 400mm;
            /* Allow priority charts to be taller if needed */
        }

        .chart-print-title {
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 10px;
            color: #000;
            /* Black text for better B&W clarity */
            padding-bottom: 5px;
            border-bottom: double 3px #aaa;
            /* Medium gray double border for title */
        }

        .chart-print-filters {
            font-size: 9pt;
            color: #000;
            /* Black text for better B&W clarity */
            margin-bottom: 15px;
            background-color: #f0f0f0;
            /* Light gray background */
            padding: 5px 8px;
            border-radius: 3px;
            border: none;
            /* Removed border */
            display: inline-block;
        }

        .chart-print-container img {
            max-width: 100%;
            max-height: 170mm;
            height: auto;
            display: block;
            margin: 0 auto;
            border-radius: 2px;
            /* Enhance contrast for B&W printing */
            filter: contrast(1.1);
        }

        .footer-text {
            text-align: center;
            font-size: 8pt;
            color: #000;
            /* Black text for better B&W clarity */
            margin-bottom: 3px;
        }

        .page-number {
            font-size: 8pt;
            color: #000;
            /* Black text for better B&W clarity */
            text-align: right;
            direction: ltr;
            position: absolute;
            bottom: 8px;
            right: 10px;
            padding: 2px 5px;
            background-color: transparent;
            /* Removed background */
            border: none;
            /* Removed border */
            border-radius: 0;
        }

        /* Print-specific styles */
        @media print {

            html,
            body {
                height: 100%;
                margin: 0;
                padding: 0;
            }

            .print-page-container {
                margin: 0;
                box-shadow: none;
                border: double 4px #000;
                /* Solid black double border for print */
                width: 100%;
                height: 100%;
                padding: 1.5cm;
                align-items: center;
                background-color: #fff;
            }

            @page {
                size: A4 portrait;
                margin: 1.5cm;
            }

            .print-header {
                border: double 3px #000;
                /* Solid black double border for print */
                background-color: #fff;
                box-shadow: none;
            }

            .print-footer {
                border-top: double 3px #000;
                /* Solid black double border for print */
            }

            .chart-print-container {
                border: double 3px #000;
                /* Solid black double border for print */
                box-shadow: none;
            }

            .chart-print-filters {
                background-color: transparent;
                /* Removed background */
                border: none;
                /* Removed border */
            }

            .page-number {
                background-color: transparent;
                border: none;
                /* Removed border */
            }

            /* Enhance image contrast for B&W printing */
            img {
                filter: contrast(1.2) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;

            }

            .chart-print-container.priority-chart {
                width: 100%;
                height: 100%;
                /* Ensure full width in print */
            }

            .chart-print-container.priority-chart img {
                width: 100%;
                height: 100%;
                /* Remove max-height limit for print if needed, let it flow */
                /* Alternatively, set a specific large height like 220mm */
            }
        }

        /* Media query for high-contrast or grayscale preference */
        @media (prefers-contrast: high),
        (prefers-color-scheme: light) {

            .print-page-container,
            .print-header,
            .print-footer,
            .chart-print-container {
                border-color: #000;
            }

            .chart-print-title {
                border-bottom-color: #000;
            }

            .chart-print-filters {
                border: none;
                /* Removed border */
                background-color: transparent;
            }

            .page-number {
                border: none;
                /* Removed border */
                background-color: transparent;
            }

            .header-center,
            .chart-print-title,
            .chart-print-filters,
            .page-number,
            .footer-text {
                color: #000;
            }
        }
    </style>
</head>

<body>

    <?php
    $chartIndex = 0;
    $totalCharts = count($chartImages);
    ?>

    <?php foreach ($chartImages as $key => $imgData): ?>
        <?php
        $chartIndex++;
        // Determine the filter subtitle string for THIS chart
        $subtitleString = ''; // Default to empty
        if (in_array($key, $chartsAffectedByFilters)) {
            // This chart IS affected by filters.
            // Check if any filters were actually applied (i.e., the display string is not the default 'no filter' message)
            if ($filterDisplayString !== 'بدون فیلتر') {
                // Filters were applied, show them
                $subtitleString = '(' . htmlspecialchars($filterDisplayString) . ')';
            } else {
                // Filters *could* apply, but none were selected. Show nothing or empty parens.
                // $subtitleString = '()'; // Option 1: Empty Parens
                $subtitleString = '';    // Option 2: Nothing (Recommended)
            }
            $containerClass = 'chart-print-container';
            if ($key === 'priority_status_percent' || $key === 'priority_step_cumulative') {
                $containerClass .= ' priority-chart';
            }
        }
        // else: Chart is not affected by filters, $subtitleString remains empty.

        ?>
        <div class="print-page-container">
            <!-- Header for each page -->
            <div class="print-header">
                <table class="header-table">
                    <tr>
                        <td class="logo-left"><img src="/assets/images/alumglass-farsi-logo-H40.png" alt="Logo"></td>
                        <td class="header-center"><?php echo nl2br(htmlspecialchars("داشبورد گزارشات\nپروژه آراد")); ?></td>
                        <td class="logo-right"><img src="/assets/images/hotelfereshteh1.png" alt="Logo"></td>
                    </tr>
                </table>
            </div>

            <!-- Chart Content Area -->
            <div class="chart-print-area">
                <div class="<?php echo $containerClass; ?>">
                    <div class="chart-print-title"><?php echo htmlspecialchars($chartTitles[$key] ?? 'نمودار'); ?></div>
                    <?php if (!empty($subtitleString)): ?>
                        <div class="chart-print-filters"><?php echo $subtitleString; ?></div>
                    <?php endif; ?>
                    <img src="<?php echo htmlspecialchars($imgData); ?>" alt="<?php echo htmlspecialchars($chartTitles[$key] ?? 'Chart'); ?>">
                </div>
            </div>

            <!-- Footer -->
            <div class="print-footer">
                <div class="footer-text"><?php echo nl2br(htmlspecialchars("تهیه شده توسط سیستم مدیریت شرکت آلومنیوم شیشه تهران")); ?></div>
                <div class="page-number">صفحه <?php echo $chartIndex; ?> از <?php echo $totalCharts; ?></div> <!-- Changed separator -->
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($chartImages)): ?>
        <div class="print-page-container">
            <div class="print-header">...</div>
            <p style="text-align:center; padding: 50px;">هیچ نموداری برای چاپ ارسال نشده است.</p>
            <div class="print-footer">...</div>
        </div>
    <?php endif; ?>

    <script type="text/javascript">
        // Automatically trigger print dialog once the page is loaded
        window.onload = function() {
            window.print();
        };
    </script>

</body>

</html>
<?php
ob_end_flush(); // Send output
?>