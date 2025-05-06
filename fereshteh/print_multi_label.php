<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start(); // Start output buffering *before* any output
// header('Content-Type: text/html; charset=utf-8'); // Moved down

require_once __DIR__ . '/../../sercon/config_fereshteh.php';
require_once 'includes/jdf.php'; // For jdate()
require_once 'includes/functions.php'; // For escapeHtml etc.

// Configuration for QR Code Images
define('QRCODE_IMAGE_WEB_PATH', '/panel_qrcodes/'); // MUST end with a slash '/'
define('QRCODE_SERVER_BASE_PATH', $_SERVER['DOCUMENT_ROOT']); // Usually the web root

// --- Get Filter/Sort/Status Parameters ---
$prioritizationFilter = filter_input(INPUT_GET, 'prioritization', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$sortBy = filter_input(INPUT_GET, 'sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$statusFilter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$dateFromPersianFilter = filter_input(INPUT_GET, 'assigned_date_from_persian', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$dateToPersianFilter = filter_input(INPUT_GET, 'assigned_date_to_persian', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$dateFromFilter = filter_input(INPUT_GET, 'assigned_date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS); // Hidden field
$dateToFilter = filter_input(INPUT_GET, 'assigned_date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS);   // Hidden field


// ***** START: Add the provided helper function *****
if (!function_exists('toLatinDigitsPhp')) {
    /**
     * Converts Persian/Arabic numerals in a string to Latin numerals.
     * @param string|int|float|null $num The input string or number.
     * @return string The string with numerals converted to Latin digits.
     */
    function toLatinDigitsPhp($num)
    {
        if ($num === null || !is_scalar($num)) return '';
        return str_replace(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], range(0, 9), strval($num));
    }
}
// ***** END: Add the provided helper function *****

/**
 * Helper function to convert Persian (Jalali) date to Gregorian date
 * Assumes input $jalaliDate uses Latin numerals (e.g., '1403/01/20').
 * @param string $jalaliDate Persian date in Y/m/d format (with Latin digits).
 * @return string|null Gregorian date in Y-m-d format, or null on failure.
 */
function jalali_to_gregorian_date($jalaliDate)
{
    if (empty($jalaliDate)) return null;

    // Split Persian date components
    $dateParts = explode('/', $jalaliDate);

    if (count($dateParts) != 3) {
        error_log("Invalid date format passed to jalali_to_gregorian_date (expected Y/m/d with Latin digits): " . $jalaliDate);
        return null; // Invalid format
    }

    // Ensure components are numeric after potential conversion
    if (!is_numeric($dateParts[0]) || !is_numeric($dateParts[1]) || !is_numeric($dateParts[2])) {
        error_log("Non-numeric date parts after numeral conversion: " . $jalaliDate);
        return null;
    }

    $jYear = intval($dateParts[0]);
    $jMonth = intval($dateParts[1]);
    $jDay = intval($dateParts[2]);

    // Basic validation for Jalali date components
    if ($jYear < 1300 || $jYear > 1500 || $jMonth < 1 || $jMonth > 12 || $jDay < 1 || $jDay > 31) {
        error_log("Invalid Jalali date components: Year=$jYear, Month=$jMonth, Day=$jDay (Original input: $jalaliDate)");
        return null;
    }


    // Use jdf.php's function to convert to Gregorian
    // Ensure jdf.php's function can handle integer inputs
    try {
        $gregorian = jalali_to_gregorian($jYear, $jMonth, $jDay);
        // Check if the result is a valid array
        if (!is_array($gregorian) || count($gregorian) !== 3) {
            error_log("jalali_to_gregorian function returned invalid result for $jYear-$jMonth-$jDay");
            return null;
        }
        // Basic validation of Gregorian result
        if (!checkdate($gregorian[1], $gregorian[2], $gregorian[0])) {
            error_log("Invalid Gregorian date produced by conversion: {$gregorian[0]}-{$gregorian[1]}-{$gregorian[2]}");
            return null;
        }
    } catch (Exception $e) {
        error_log("Error during jdf conversion for $jYear-$jMonth-$jDay: " . $e->getMessage());
        return null; // Conversion failed
    }


    // Format as Y-m-d for MySQL
    return sprintf('%04d-%02d-%02d', $gregorian[0], $gregorian[1], $gregorian[2]);
}

// --- Convert Persian dates to Gregorian if they are provided ---
$gregorianDateFrom = null;
$gregorianDateTo = null;

if (!empty($dateFromPersianFilter)) {
    // ***** CORRECTED: Convert Persian numerals first *****
    $latinDateFrom = toLatinDigitsPhp($dateFromPersianFilter);
    // Convert Persian date (now with Latin digits) to Gregorian for database query
    $gregorianDateFrom = jalali_to_gregorian_date($latinDateFrom);
    if (!$gregorianDateFrom) {
        error_log("Failed to convert 'From Date': " . $dateFromPersianFilter . " (Attempted with: " . $latinDateFrom . ")");
    }
}

if (!empty($dateToPersianFilter)) {
    // ***** CORRECTED: Convert Persian numerals first *****
    $latinDateTo = toLatinDigitsPhp($dateToPersianFilter);
    // Convert Persian date (now with Latin digits) to Gregorian for database query
    $gregorianDateTo = jalali_to_gregorian_date($latinDateTo);
    // Add time to make it inclusive of the entire day
    if ($gregorianDateTo) { // Check conversion was successful
        $gregorianDateTo .= ' 23:59:59';
    } else {
        error_log("Failed to convert 'To Date': " . $dateToPersianFilter . " (Attempted with: " . $latinDateTo . ")");
    }
}


// --- Set Defaults ---
if (empty($prioritizationFilter)) $prioritizationFilter = 'all';
if (empty($sortBy)) $sortBy = 'concrete_desc';
if (empty($statusFilter)) $statusFilter = 'completed'; // <-- Default status filter

// --- Fetch Panel Data ---
$panels = [];
$error = null;
$availablePrioritizations = [];

try {
    $pdo = connectDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- Fetch Available Prioritizations ---
    $stmtPrio = $pdo->query("SELECT DISTINCT Proritization FROM hpc_panels WHERE Proritization IS NOT NULL AND Proritization != '' ORDER BY Proritization ASC");
    $availablePrioritizations = $stmtPrio->fetchAll(PDO::FETCH_COLUMN);

    // --- Build Main Query ---
    $conditions = [];
    $params = [];
    $orderBy = "";

    // Date conditions (using converted Gregorian dates)
    if (!empty($gregorianDateFrom)) {
        $conditions[] = "hp.assigned_date >= ?";
        $params[] = $gregorianDateFrom;
    }
    if (!empty($gregorianDateTo)) {
        $conditions[] = "hp.assigned_date <= ?";
        $params[] = $gregorianDateTo;
    }

    // Prioritization Filter
    if ($prioritizationFilter !== 'all' && !empty($prioritizationFilter)) {
        $conditions[] = "hp.Proritization = ?";
        $params[] = $prioritizationFilter;
    }

    // Status Filter
    switch ($statusFilter) {
        case 'completed':
            $conditions[] = "hp.status = 'completed'";
            break;
        case 'assembly':
            $conditions[] = "hp.status = 'Assembly'";
            break;
        case 'Concreting':
            $conditions[] = "hp.status = 'Concreting'";
            break;
        case 'Mesh':
            $conditions[] = "hp.status = 'Mesh'";
            break;
        case 'polystyrene':
            $conditions[] = "hp.polystyrene = 1";
            $conditions[] = "hp.status = 'polystyrene'";
            break;
        case 'pending':
            $conditions[] = "hp.status = 'pending'";
            break;
        case 'all':
        default:
            break;
    }

    // Sorting
    switch ($sortBy) {
        case 'concrete_asc':
            $orderBy = "ORDER BY hp.concrete_end_time ASC, hp.id ASC";
            break;
        case 'assembly_desc':
            $orderBy = "ORDER BY hp.assembly_end_time DESC, hp.id DESC";
            break;
        case 'assembly_asc':
            $orderBy = "ORDER BY hp.assembly_end_time ASC, hp.id ASC";
            break;
        case 'concrete_desc':
        default:
            $orderBy = "ORDER BY hp.concrete_end_time DESC, hp.id DESC";
            break;
    }

    // Construct the final SQL
    $sql = "SELECT
                hp.id, hp.address, hp.assigned_date, hp.status, hp.Proritization,
                hp.planned_finish_date, hp.formwork_end_time, hp.polystyrene,
                hp.mesh_end_time, hp.assembly_end_time, hp.concrete_start_time,
                hp.concrete_end_time, hp.width
            FROM hpc_panels hp";

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    $sql .= " " . $orderBy;

    // Execute Query
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "خطا در بارگذاری اطلاعات: " . $e->getMessage();
    error_log("Error on print_multi_label.php: " . $e->getMessage());
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo "Database Error: " . htmlspecialchars($error);
    exit;
}

// --- Send Header AFTER potential errors and DB connection ---
header('Content-Type: text/html; charset=utf-8');
if (ob_get_level() > 0) {
    ob_end_flush();
}

// --- Function getLatestShamsiDate remains unchanged ---
/**
 * Helper function to get the latest Shamsi date from a list of potential date strings.
 */
function getLatestShamsiDate(array $dateStrings): string
{
    $latestTimestamp = 0;
    foreach ($dateStrings as $dateStr) {
        if (!empty($dateStr) && $dateStr !== '0000-00-00' && $dateStr !== '0000-00-00 00:00:00') {
            $timestamp = strtotime($dateStr);
            if ($timestamp !== false && $timestamp > $latestTimestamp) {
                $latestTimestamp = $timestamp;
            }
        }
    }

    if ($latestTimestamp > 0) {
        if (function_exists('jdate')) {
            return jdate('Y/m/d', $latestTimestamp);
        } else {
            return date('Y/m/d', $latestTimestamp) . ' (Gregorian)';
        }
    } else {
        return '-';
    }
}

?>
<!DOCTYPE html>
<!-- The rest of your HTML, CSS, and JavaScript remains unchanged -->
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/assets/images/favicon-96x96.png">
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
    <title>چاپ گروهی برچسب پنل‌ها</title>
    <link rel="stylesheet" href="assets/css/persian-datepicker.min.css">

    <style>
        /* --- Base Styles --- */
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
        }

        body {
            font-family: 'Vazir', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #eee;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* --- Label Container & Wrapper --- */
        .panel-wrapper {
            page-break-inside: avoid;
            margin-bottom: 10mm;
            padding: 5mm 0;
            border-bottom: 1px dotted #ddd;
        }

        .label-container {
            width: 200mm;
            border: 1px dashed #ccc;
            margin: 0 auto 5mm auto;
            padding: 0;
            box-sizing: border-box;
            overflow: hidden;
            background-color: #fff;
            display: block;
        }

        .panel-wrapper .label-container:last-child {
            margin-bottom: 0;
        }


        .panel-selection-wrapper {
            text-align: center;
            padding: 5px 0;
            margin: 0 auto 5mm auto;
            width: 200mm;
        }

        .panel-selection-wrapper label {
            margin-right: 5px;
            font-size: 10pt;
            cursor: pointer;
        }

        /* --- Label Table --- */
        table.label-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .label-table th,
        .label-table td {
            border: 1px solid #333;
            padding: 1.5mm 2.5mm;
            vertical-align: middle;
            text-align: center;
            font-size: 10pt;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .label-table th {
            font-weight: bold;
            background-color: #f0f0f0 !important;
        }

        /* --- Table Rows and Cells --- */
        .header-row {
            height: 16mm;
        }

        .middle-row {
            height: 10mm;
        }

        .qr-code-cell {
            width: 22mm;
            padding: 1mm;
            vertical-align: middle;
        }

        .qr-code-cell img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: auto;
        }

        .logo-cell {
            vertical-align: middle;
            overflow: hidden;
        }

        .logo-cell img {
            /* General style for logos */
            max-height: 12mm;
            /* Set a common max height */
            vertical-align: middle;
            /* Align vertically */
            /* Removed max-width from here, will set on specific classes */
        }

        .logo-cell .logo-right {
            /* AlumGlass logo */
            float: right;
            /* Position to the right */
            max-width: 48%;
            /* Adjust width slightly */
            margin-left: 1mm;
            /* Prevent touching left item */
            margin-right: 1mm;
            /* Space from right border */
            max-height: 10mm;
            /* Specific max height if needed */
        }

        .logo-cell .logo-left {
            /* Hotel Fereshteh logo */
            float: left;
            /* Position to the left */
            max-width: 48%;
            /* Adjust width slightly */
            margin-right: 1mm;
            /* Prevent touching right item */
            margin-left: 1mm;
            /* Space from left border */
            /* max-height is inherited from .logo-cell img or can be set specifically */
        }


        .title-cell {
            font-size: 10pt;
            font-weight: bold;
            vertical-align: bottom;
            padding-bottom: 1mm;
        }

        .qc-pass-cell {
            background-image: repeating-linear-gradient(45deg, #a8dda8, #a8dda8 1px, #ffffff 1px, #ffffff 2px);
            background-size: 2px 2px;
            color: #000000 !important;
            font-weight: bold;
            font-size: 11pt;
            width: 40mm;
            line-height: 1.5;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .label-text {
            font-weight: bold;
            font-size: 11pt;
            color: #444;
            padding-left: 1mm;
            text-align: left;
            display: inline-block;
            margin-right: 5px;
        }

        .value-text {
            font-weight: bold;
            font-size: 12pt;
            text-align: right;
            padding-right: 1mm;
            vertical-align: middle;
        }

        .value-text .address-value {
            font-size: 11pt;
            font-weight: bold;
            display: inline-block;
        }


        .project-name {
            font-size: 10pt;
            font-weight: bold;
            text-align: right;
            padding-right: 2mm;
        }

        .date-value-cell {
            font-size: 10pt;
            font-weight: bold;
            direction: rtl;
            unicode-bidi: embed;
            text-align: right;
            padding-right: 2mm;
        }

        .date-label {
            font-size: 8pt;
            font-weight: normal;
            margin-left: 1mm;
        }

        .zone-width-cell {
            font-size: 10pt;
            font-weight: bold;
            text-align: center;
        }

        .zone-width-cell .date-label {
            font-size: 8pt;
            font-weight: normal;
        }

        /* --- Controls --- */
        .controls-container {
            background-color: #fff;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .controls-container label {
            margin-left: 5px;
            font-weight: bold;
            margin-right: 15px;
        }

        .controls-container select,
        .controls-container button {
            padding: 8px 12px;
            margin-right: 5px;
            margin-bottom: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
            font-family: 'Vazir', sans-serif;
            font-size: 14px;
            vertical-align: middle;
        }

        .controls-container button {
            background-color: #007bff;
            color: white;
            cursor: pointer;
            border: none;
        }

        .controls-container button:hover {
            background-color: #0056b3;
        }

        .controls-container span.panel-count {
            margin-left: 15px;
            font-size: 13px;
            color: #555;
        }

        .controls-container a {
            color: white;
            text-decoration: none;
        }

        #printSelectedBtn {
            background-color: #28a745;
        }

        #printSelectedBtn:hover {
            background-color: #218838;
        }

        #backButton {
            background-color: #f44336;
        }

        #backButton:hover {
            background-color: #d32f2f;
        }

        .date-filter-container {
            margin-top: 10px;
            margin-bottom: 10px;
        }

        .date-filter-container label {
            display: inline-block;
            margin-left: 5px;
            font-weight: bold;
        }

        .date-input {
            width: 120px;
            padding: 6px 10px;
            margin-right: 5px;
            margin-left: 15px;
            border-radius: 4px;
            border: 1px solid #ccc;
            font-family: 'Vazir', sans-serif;
            text-align: center;
            direction: ltr;
        }

        /* Persian datepicker customization */
        .datepicker-plot-area {
            font-family: 'Vazir', sans-serif !important;
        }

        .reset-date-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-family: 'Vazir', sans-serif;
            vertical-align: middle;
        }

        .reset-date-btn:hover {
            background-color: #5a6268;
        }

        /* --- Print Styles --- */
        @media print {
            body {
                margin: 0;
                padding: 0;
                background-color: #fff;
            }

            .no-print,
            .controls-container,
            .panel-selection-wrapper {
                display: none !important;
            }

            .panel-wrapper {
                margin-bottom: 0;
                padding: 0;
                border-bottom: none;
                page-break-inside: avoid !important;
            }

            .label-container {
                width: 180mm;
                border: 1px solid #000;
                margin: 1mm auto;
                display: block;
                page-break-inside: avoid !important;
            }

            .panel-wrapper .label-container:first-child {
                margin-bottom: 2mm;
            }

            .label-table th,
            .label-table td {
                padding: 1mm 1mm;
                font-size: 9pt;
            }

            .label-table th {
                background-color: #eee !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .value-text {
                font-size: 11pt;
            }

            .value-text .address-value {
                font-size: 10pt;
            }

            .label-text {
                font-size: 10pt;
            }

            .project-name {
                font-size: 9pt;
            }

            .date-value-cell {
                font-size: 9pt;
            }

            .zone-width-cell {
                font-size: 9pt;
            }

            .qc-pass-cell {
                background-image: repeating-linear-gradient(45deg, #a8dda8, #a8dda8 1px, #ffffff 1px, #ffffff 2px);
                font-weight: bold;
                font-size: 10pt;
                background-size: 2px 2px;
                color: #000000 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .logo-wrapper-dark img {
                max-height: 10mm;
                max-width: 70%;
            }

            body.print-selection-active .panel-wrapper {
                display: none !important;
            }

            body.print-selection-active .panel-wrapper.selected-for-print {
                display: block !important;
                page-break-inside: avoid !important;
            }

            /* @page { size: A4; margin: 10mm; } */
            .logo-cell .logo-right {
                max-height: 10mm;
                /* Adjust print size if needed */
                max-width: 45%;
            }

            .logo-cell .logo-left {
                max-height: 11mm;
                /* Adjust print size if needed */
                max-width: 45%;
            }

            /* Ensure floats work in print */
            .logo-cell {
                overflow: hidden;
                /* Helps contain floats */
            }
        }
    </style>
</head>

<body>

    <div class="controls-container no-print">
        <form method="GET" action="<?= basename(__FILE__) ?>" id="filterForm">
            <label for="statusFilterSelect">وضعیت:</label>
            <select name="status" id="statusFilterSelect" onchange="this.form.submit()">

                <option value="all" <?= ($statusFilter == 'all') ? 'selected' : '' ?>>همه وضعیت‌ها</option>
                <option value="completed" <?= ($statusFilter == 'completed') ? 'selected' : '' ?>>تکمیل شده</option>
                <option value="assembly" <?= ($statusFilter == 'assembly') ? 'selected' : '' ?>>فیس کوت</option>
                <option value="Concreting" <?= ($statusFilter == 'Concreting') ? 'selected' : '' ?>>بتن ریزی</option>
                <option value="Mesh" <?= ($statusFilter == 'Mesh') ? 'selected' : '' ?>>مش گذاری</option>
                <option value="polystyrene" <?= ($statusFilter == 'polystyrene') ? 'selected' : '' ?>>فوم گذاری</option>
                <option value="pending" <?= ($statusFilter == 'pending') ? 'selected' : '' ?>>در انتظار</option>
            </select>

            <label for="prioritizationFilterSelect">اولویت:</label>
            <select name="prioritization" id="prioritizationFilterSelect" onchange="this.form.submit()">
                <option value="all" <?= ($prioritizationFilter == 'all') ? 'selected' : '' ?>>همه اولویت‌ها</option>
                <?php foreach ($availablePrioritizations as $prio): ?>
                    <option value="<?= escapeHtml($prio) ?>" <?= ($prioritizationFilter == $prio) ? 'selected' : '' ?>><?= escapeHtml($prio) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="sortSelect">مرتب سازی:</label>
            <select name="sort" id="sortSelect" onchange="this.form.submit()">
                <option value="concrete_desc" <?= ($sortBy == 'concrete_desc') ? 'selected' : '' ?>>پایان بتن (جدیدترین)</option>
                <option value="concrete_asc" <?= ($sortBy == 'concrete_asc') ? 'selected' : '' ?>>پایان بتن (قدیمی ترین)</option>
                <option value="assembly_desc" <?= ($sortBy == 'assembly_desc') ? 'selected' : '' ?>>پایان فیس کوت (جدیدترین)</option>
                <option value="assembly_asc" <?= ($sortBy == 'assembly_asc') ? 'selected' : '' ?>>پایان فیس کوت (قدیمی ترین)</option>
                <!-- Add other sort options -->
            </select>
            <div class="date-filter-container">
                <label>تاریخ تولید:</label>
                <input type="text" id="dateFrom" name="assigned_date_from_persian" class="date-input" placeholder="از تاریخ"
                    value="<?= !empty($dateFromPersianFilter) ? htmlspecialchars($dateFromPersianFilter) : '' ?>">
                <input type="hidden" name="assigned_date_from" value="<?= !empty($dateFromFilter) ? htmlspecialchars($dateFromFilter) : '' ?>">

                <input type="text" id="dateTo" name="assigned_date_to_persian" class="date-input" placeholder="تا تاریخ"
                    value="<?= !empty($dateToPersianFilter) ? htmlspecialchars($dateToPersianFilter) : '' ?>">
                <input type="hidden" name="assigned_date_to" value="<?= !empty($dateToFilter) ? htmlspecialchars($dateToFilter) : '' ?>">

                <button type="button" id="applyDateFilter" onclick="document.getElementById('filterForm').submit();">اعمال فیلتر تاریخ</button>
                <button type="button" class="reset-date-btn" id="resetDateFilter">پاک کردن</button>
            </div>

            <button type="button" onclick="window.print();">چاپ همه</button>
            <button type="button" id="printSelectedBtn">چاپ منتخب</button>
            <button type="button" id="backButton"><a href="new_panels.php">بازگشت</a></button>
            <span class="panel-count">
                <strong><?= count($panels) ?></strong> پنل یافت شد /
                <strong><?= count($panels) * 2 ?></strong> برچسب
                (<?= $statusFilter != 'all' ? htmlspecialchars(($statusFilter == 'completed' ? 'تکمیل شده' : ($statusFilter == 'assembly' ? 'فیس کوت' : ($statusFilter == 'Concreting' ? 'بتن ریزی' : ($statusFilter == 'Mesh' ? 'مش گذاری' : ($statusFilter == 'polystyrene' ? 'فوم گذاری' : ($statusFilter == 'pending' ? 'در انتظار' : $statusFilter))))))) : 'همه' ?>)
                <?php if ($prioritizationFilter != 'all'): ?>
                    <span class="filter-detail"> - اولویت: <?= htmlspecialchars($prioritizationFilter) ?></span>
                <?php endif; ?>
                <?php if (!empty($dateFromPersianFilter) || !empty($dateToPersianFilter)): ?>
                    <span class="filter-detail"> - تاریخ:
                        <?= !empty($dateFromPersianFilter) ? htmlspecialchars($dateFromPersianFilter) : '' ?>
                        <?= (!empty($dateFromPersianFilter) && !empty($dateToPersianFilter)) ? ' تا ' : '' ?>
                        <?= !empty($dateToPersianFilter) ? htmlspecialchars($dateToPersianFilter) : '' ?>
                    </span>
                <?php endif; ?>
            </span>

        </form>
    </div>

    <?php if ($error): ?>
        <div style="padding: 20px; color: red; text-align: center; border: 1px solid red; margin: 20px; background: #ffeeee;">
            <strong>خطا:</strong> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php elseif (empty($panels)): ?>
        <div style="padding: 20px; text-align: center; margin: 20px;">
            هیچ پنلی با فیلترهای انتخاب شده یافت نشد.
        </div>
    <?php else: ?>
        <div class="labels-grid">
            <?php foreach ($panels as $panelData): ?>
                <?php
                // --- Get Common Data ---
                $currentPanelId = $panelData['id'];
                $currentPanelCode = $panelData['address'] ?? 'N/A';
                // $currentProject = $panelData['project_name'] ?? 'پروژه فرشته'; // Removed, using default below
                $currentProject = 'پروژه فرشته'; // USING HARDCODED DEFAULT
                $currentPanelzone = $panelData['Proritization'] ?? '-';
                $currentWidth = isset($panelData['width']) ? (int)$panelData['width'] . ' mm' : '-';

                // --- QR Code Logic ---
                $qrCodeFilename = "panel_qr_" . $currentPanelId . ".png";
                $qrCodeWebUrl = QRCODE_IMAGE_WEB_PATH . $qrCodeFilename;
                $qrCodeServerPath = QRCODE_SERVER_BASE_PATH . QRCODE_IMAGE_WEB_PATH . $qrCodeFilename;
                $currentQrCodeImageUrl = null;
                if (file_exists($qrCodeServerPath)) {
                    $mtime = @filemtime($qrCodeServerPath);
                    $currentQrCodeImageUrl = $qrCodeWebUrl . ($mtime ? '?v=' . $mtime : '');
                } else {
                    error_log("Missing QR Code Image: Panel ID $currentPanelId, Expected Path: $qrCodeServerPath");
                }

                // --- Date Logic ---
                // Use assigned_date directly for display, convert it to Shamsi
                $assignedGregorianDate = $panelData['assigned_date'] ?? null;
                $latestShamsiDate = '-';
                if (!empty($assignedGregorianDate) && $assignedGregorianDate !== '0000-00-00' && $assignedGregorianDate !== '0000-00-00 00:00:00') {
                    $timestamp = strtotime($assignedGregorianDate);
                    if ($timestamp !== false && function_exists('jdate')) {
                        $latestShamsiDate = jdate('Y/m/d', $timestamp);
                    } elseif ($timestamp !== false) {
                        $latestShamsiDate = date('Y/m/d', $timestamp) . ' (G)'; // Fallback
                    }
                }
                // Old logic using getLatestShamsiDate is removed as we only care about assigned_date here
                // $dateColumnsToCheck = [ $panelData['assigned_date'] ?? null ];
                // $latestShamsiDate = getLatestShamsiDate(array_filter($dateColumnsToCheck));

                // --- Address Parsing ---
                $label1_address_display = $currentPanelCode;
                $label2_address_display = $currentPanelCode;
                $part2 = '';
                $part3 = '';

                if ($currentPanelCode !== 'N/A' && strpos($currentPanelCode, '-') !== false) {
                    $addrParts = explode('-', $currentPanelCode);
                    if (count($addrParts) >= 3) {
                        $part2 = trim($addrParts[1]);
                        $part3 = trim($addrParts[2]);
                        $label1_address_display = $currentPanelCode . ' (' . $part2 . ')';
                        $label2_address_display = $currentPanelCode . ' (' . $part3 . ')';
                    } elseif (count($addrParts) == 2) {
                        $part2 = trim($addrParts[1]);
                        $label1_address_display = $currentPanelCode . ' (' . $part2 . ')';
                        $label2_address_display = $currentPanelCode; // Fallback for 2nd label
                    }
                }
                ?>

                <!-- Wrapper for Checkbox and BOTH Labels -->
                <div class="panel-wrapper" data-panel-id="<?= $currentPanelId ?>">
                    <div class="panel-selection-wrapper no-print">
                        <input type="checkbox" class="panel-select-checkbox" value="<?= $currentPanelId ?>" id="select-panel-<?= $currentPanelId ?>">
                        <label for="select-panel-<?= $currentPanelId ?>">انتخاب برای چاپ (هر دو برچسب <?= htmlspecialchars($currentPanelCode) ?>)</label>
                    </div>

                    <!-- ### Generate Label 1 ### -->
                    <div class="label-container label-instance-1">
                        <table class="label-table">
                            <tbody>
                                <tr class="header-row">
                                    <td class="qr-code-cell" rowspan="2">
                                        <?php if ($currentQrCodeImageUrl): ?>
                                            <img src="<?= htmlspecialchars($currentQrCodeImageUrl) ?>" alt="QR Code Panel <?= htmlspecialchars($currentPanelId) ?>">
                                        <?php else: ?>
                                            <span style="font-size: 7pt; color: red; display:inline-block; padding-top:5mm;">QR یافت نشد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="logo-cell" colspan="3">
                                        <img src="/assets/images/alumglass-farsi-logo-H40.png" alt="AlumGlass Logo" class="logo-right">

                                        <img src="/assets/images/hotelfereshteh1.png" alt="hotelfereshteh Logo" class="logo-left">

                                    </td>
                                    <td class="qc-pass-cell" rowspan="2"> QC<br>کنترل شد </td>
                                </tr>
                                <tr class="header-row">
                                    <td class="title-cell" colspan="3">برچسب کنترل کیفی محصول</td>
                                </tr>
                                <tr class="middle-row">
                                    <td colspan="1" class="project-name"><?= htmlspecialchars($currentProject) ?></td>
                                    <td colspan="1" class="date-value-cell">
                                        <span class="date-label">تاریخ تولید:</span>
                                        <?= htmlspecialchars($latestShamsiDate) ?>
                                    </td>
                                    <td colspan="1" class="zone-width-cell">
                                        <span class="date-label">Zone:</span> <?= htmlspecialchars($currentPanelzone) ?>
                                         
                                        <span class="date-label">W:</span> <?= htmlspecialchars($currentWidth) ?>
                                    </td>
                                    <td colspan="2" class="value-text">
                                        <span class="label-text">آدرس پنل:</span>
                                        <span class="address-value"><?= htmlspecialchars($label1_address_display) ?></span> <!-- Use Label 1 Address -->
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- End Label 1 HTML -->

                    <!-- ### Generate Label 2 ### -->
                    <div class="label-container label-instance-2">
                        <table class="label-table">
                            <tbody>
                                <tr class="header-row">
                                    <td class="qr-code-cell" rowspan="2">
                                        <?php if ($currentQrCodeImageUrl): ?>
                                            <img src="<?= htmlspecialchars($currentQrCodeImageUrl) ?>" alt="QR Code Panel <?= htmlspecialchars($currentPanelId) ?>">
                                        <?php else: ?>
                                            <span style="font-size: 7pt; color: red; display:inline-block; padding-top:5mm;">QR یافت نشد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="logo-cell" colspan="3">
                                        <img src="/assets/images/alumglass-farsi-logo-H40.png" alt="AlumGlass Logo" class="logo-right">

                                        <img src="/assets/images/hotelfereshteh1.png" alt="hotelfereshteh Logo" class="logo-left">

                                    </td>
                                    <td class="qc-pass-cell" rowspan="2"> QC<br>کنترل شد </td>
                                </tr>
                                <tr class="header-row">
                                    <td class="title-cell" colspan="3">برچسب کنترل کیفی محصول</td>
                                </tr>
                                <tr class="middle-row">
                                    <td colspan="1" class="project-name"><?= htmlspecialchars($currentProject) ?></td>
                                    <td colspan="1" class="date-value-cell">
                                        <span class="date-label">تاریخ تولید:</span>
                                        <?= htmlspecialchars($latestShamsiDate) ?>
                                    </td>
                                    <td colspan="1" class="zone-width-cell">
                                        <span class="date-label">Zone:</span> <?= htmlspecialchars($currentPanelzone) ?>
                                         
                                        <span class="date-label">W:</span> <?= htmlspecialchars($currentWidth) ?>
                                    </td>
                                    <td colspan="2" class="value-text">
                                        <span class="label-text">آدرس پنل:</span>
                                        <span class="address-value"><?= htmlspecialchars($label2_address_display) ?></span> <!-- Use Label 2 Address -->
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- End Label 2 HTML -->

                </div>
                <!-- End Panel Wrapper -->

            <?php endforeach; ?>
        </div> <!-- End Labels Grid -->
    <?php endif; ?>

    <script>
        // Javascript remains the same - it correctly handles the wrapper selection
        document.addEventListener('DOMContentLoaded', function() {
            const printSelectedBtn = document.getElementById('printSelectedBtn');
            const checkboxes = document.querySelectorAll('.panel-select-checkbox');
            const panelWrappers = document.querySelectorAll('.panel-wrapper');

            if (printSelectedBtn) {
                printSelectedBtn.addEventListener('click', function() {
                    const selectedPanelIds = [];
                    checkboxes.forEach(checkbox => {
                        if (checkbox.checked) {
                            selectedPanelIds.push(checkbox.value);
                        }
                    });

                    if (selectedPanelIds.length === 0) {
                        alert('لطفاً حداقل یک پنل را برای چاپ انتخاب کنید.');
                        return;
                    }

                    panelWrappers.forEach(wrapper => {
                        const panelId = wrapper.getAttribute('data-panel-id');
                        if (selectedPanelIds.includes(panelId)) {
                            wrapper.classList.add('selected-for-print');
                        } else {
                            wrapper.classList.remove('selected-for-print');
                        }
                    });

                    document.body.classList.add('print-selection-active');
                    window.print();

                    const cleanup = () => {
                        document.body.classList.remove('print-selection-active');
                        panelWrappers.forEach(wrapper => wrapper.classList.remove('selected-for-print'));
                        if (window.onafterprint !== undefined) {
                            window.removeEventListener('afterprint', cleanup);
                            window.onafterprint = null;
                        }
                    };

                    if (window.onafterprint !== undefined) {
                        window.addEventListener('afterprint', cleanup, {
                            once: true
                        });
                    } else {
                        setTimeout(cleanup, 1500);
                        console.warn("Using fallback timer for print cleanup.");
                    }
                });
            }
        });
    </script>
    <script src="/assets/js/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/persian-date.min.js"></script>
    <script src="/assets/js/persian-datepicker.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Persian datepicker
            $("#dateFrom, #dateTo").persianDatepicker({
                format: 'YYYY/MM/DD',
                autoClose: true,
                initialValue: false,
                observer: true,
                calendar: {
                    persian: {
                        locale: 'fa', // Use Persian language/locale
                        leapYearMode: 'astronomical' // Use the accurate calculation method
                    }
                }
            });

            // Reset date filter button
            $("#resetDateFilter").click(function() {
                $("#dateFrom, #dateTo").val('');
                // No need to clear hidden fields specifically if PHP ignores them
                // $("input[name='assigned_date_from'], input[name='assigned_date_to']").val('');
                // Remove the date parameters and submit the form
                const url = new URL(window.location.href);
                url.searchParams.delete('assigned_date_from_persian');
                url.searchParams.delete('assigned_date_to_persian');
                // Also remove the hidden field params just in case
                url.searchParams.delete('assigned_date_from');
                url.searchParams.delete('assigned_date_to');
                window.location.href = url.toString();
            });
        });

       
    </script>
</body>

</html>