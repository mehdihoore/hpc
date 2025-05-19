<?php
// concrete_tests_print.php


// --- Basic Setup, Dependencies, Session, Auth, DB Connection ---
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
// 4. Check user role access for this specific page

// --- End Authorization ---

if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();
// Authentication Check (Still important)
$allroles = ['admin', 'supervisor', 'planner', 'cnc_operator', 'superuser', 'user'];
$authroles = ['admin', 'supervisor'];
$readonlyroles = ['planner', 'cnc_operator', 'user'];

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $allroles)) {
    header('Location: login.php');
    exit('Access Denied.');
}
$current_user_id = $_SESSION['user_id']; // Get current user ID
$report_key = 'concrete_tests_print';
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/concrete_tests_print.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// --- PHP Helper Functions (Only those needed for display) ---

// --- Load Print Settings ---
// Default settings
$default_print_settings = [
    'print_header' => "گزارش تست‌های بتن\nشرکت آلومنیوم شیشه تهران",
    'print_footer' => "تاریخ چاپ: " . jdate('Y/m/d'),
    'print_signature_area' => "<td>مسئول آزمایشگاه</td><td>کنترل کیفیت</td><td>مدیر فنی</td>",
    'show_logos' => true,
    'custom_header_html' => '',
    'custom_footer_html' => '',
    'rows_per_page' => 12,
    // Defaults for new style settings
    'header_font_size' => '14pt',
    'header_font_color' => '#000000',
    'footer_font_size' => '10pt',
    'footer_font_color' => '#333333',
    'thead_font_size' => '10pt',
    'thead_font_color' => '#000000',
    'thead_bg_color' => '#eeeeee',
    'tbody_font_size' => '10pt',
    'tbody_font_color' => '#000000',
    'row_min_height' => 'auto',
    'col_width_prod_date' => '13%',
    'col_width_sample_code' => '15%',
    'col_width_break_date' => '13%',
    'col_width_age' => '7%',
    'col_width_dimensions' => '27%',
    'col_width_strength' => '15%',
    'col_width_status' => '10%',
];

$user_print_settings = $default_print_settings; // Start with defaults
// Try to load user settings from DB
try {
    $stmt = $pdo->prepare("SELECT * FROM user_print_settings WHERE user_id = ? AND report_key = ?");
    $stmt->execute([$current_user_id, $report_key]); // <-- ADD $report_key here
    $db_settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($db_settings) {
        // Ensure all default keys exist before merging
        $db_settings = array_merge($default_print_settings, $db_settings);
        $db_settings_filtered = array_filter($db_settings, function ($value) {
            return $value !== null;
        });
        $user_print_settings = array_merge($default_print_settings, $db_settings_filtered);
        // Cast types
        $user_print_settings['show_logos'] = (bool)($user_print_settings['show_logos']);
        $user_print_settings['rows_per_page'] = (int)($user_print_settings['rows_per_page']);
    }
} catch (PDOException $e) {
    error_log("Error loading print settings for user {$current_user_id}, report {$report_key}: " . $e->getMessage());
    // Keep using defaults if loading fails
}

$show_settings_form = isset($_GET['settings']) && $_GET['settings'] == '1';
$settings_saved_success = false;
$settings_save_error = null;

if (isset($_POST['save_print_settings'])) {

    // Sanitize helper (basic example)
    function sanitize_css_value($value, $default = '', $allow_auto = false)
    {
        $value = trim($value);
        if ($allow_auto && strtolower($value) === 'auto') return 'auto';
        // Very basic check for common units or hex color
        if (preg_match('/^(\d+(\.\d+)?(pt|px|em|%)|#[0-9a-fA-F]{3,6}|[a-zA-Z]+)$/', $value)) {
            return $value;
        }
        return $default;
    }
    function sanitize_color($value, $default = '#000000')
    {
        $value = trim($value);
        if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $value) || filter_var($value, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^[a-zA-Z]+$/']])) { // Basic check for hex or name
            return $value;
        }
        return $default;
    }

    // Get submitted data and sanitize
    $new_settings_data = [
        'user_id' => $current_user_id,
        'report_key' => $report_key,
        'print_header' => trim($_POST['print_header'] ?? ''),
        'print_footer' => trim($_POST['print_footer'] ?? ''),
        'print_signature_area' => trim($_POST['print_signature_area'] ?? ''),
        'show_logos' => isset($_POST['show_logos']) ? 1 : 0,
        'custom_header_html' => trim($_POST['custom_header_html'] ?? ''), // Consider more robust HTML filtering if needed
        'custom_footer_html' => trim($_POST['custom_footer_html'] ?? ''), // Consider more robust HTML filtering if needed
        'rows_per_page' => isset($_POST['rows_per_page']) ? max(5, (int)$_POST['rows_per_page']) : $default_print_settings['rows_per_page'],
        // Sanitize new style settings
        'header_font_size' => sanitize_css_value($_POST['header_font_size'] ?? '', $default_print_settings['header_font_size']),
        'header_font_color' => sanitize_color($_POST['header_font_color'] ?? '', $default_print_settings['header_font_color']),
        'footer_font_size' => sanitize_css_value($_POST['footer_font_size'] ?? '', $default_print_settings['footer_font_size']),
        'footer_font_color' => sanitize_color($_POST['footer_font_color'] ?? '', $default_print_settings['footer_font_color']),
        'thead_font_size' => sanitize_css_value($_POST['thead_font_size'] ?? '', $default_print_settings['thead_font_size']),
        'thead_font_color' => sanitize_color($_POST['thead_font_color'] ?? '', $default_print_settings['thead_font_color']),
        'thead_bg_color' => sanitize_color($_POST['thead_bg_color'] ?? '', $default_print_settings['thead_bg_color']),
        'tbody_font_size' => sanitize_css_value($_POST['tbody_font_size'] ?? '', $default_print_settings['tbody_font_size']),
        'tbody_font_color' => sanitize_color($_POST['tbody_font_color'] ?? '', $default_print_settings['tbody_font_color']),
        'row_min_height' => sanitize_css_value($_POST['row_min_height'] ?? '', $default_print_settings['row_min_height'], true), // Allow 'auto'
        'col_width_prod_date' => sanitize_css_value($_POST['col_width_prod_date'] ?? '', $default_print_settings['col_width_prod_date']),
        'col_width_sample_code' => sanitize_css_value($_POST['col_width_sample_code'] ?? '', $default_print_settings['col_width_sample_code']),
        'col_width_break_date' => sanitize_css_value($_POST['col_width_break_date'] ?? '', $default_print_settings['col_width_break_date']),
        'col_width_age' => sanitize_css_value($_POST['col_width_age'] ?? '', $default_print_settings['col_width_age']),
        'col_width_dimensions' => sanitize_css_value($_POST['col_width_dimensions'] ?? '', $default_print_settings['col_width_dimensions']),
        'col_width_strength' => sanitize_css_value($_POST['col_width_strength'] ?? '', $default_print_settings['col_width_strength']),
        'col_width_status' => sanitize_css_value($_POST['col_width_status'] ?? '', $default_print_settings['col_width_status']),
    ];

    // Update SQL query to include all new columns
    $sql = "INSERT INTO user_print_settings (
                user_id, report_key, print_header, print_footer, print_signature_area, show_logos,
                custom_header_html, custom_footer_html, rows_per_page, header_font_size,
                header_font_color, footer_font_size, footer_font_color, thead_font_size,
                thead_font_color, thead_bg_color, tbody_font_size, tbody_font_color, row_min_height,
                col_width_prod_date, col_width_sample_code, col_width_break_date, col_width_age,
                col_width_dimensions, col_width_strength, col_width_status
            ) VALUES (
                :user_id, :report_key, :print_header, :print_footer, :print_signature_area, :show_logos,
                :custom_header_html, :custom_footer_html, :rows_per_page, :header_font_size,
                :header_font_color, :footer_font_size, :footer_font_color, :thead_font_size,
                :thead_font_color, :thead_bg_color, :tbody_font_size, :tbody_font_color, :row_min_height,
                :col_width_prod_date, :col_width_sample_code, :col_width_break_date, :col_width_age,
                :col_width_dimensions, :col_width_strength, :col_width_status
            )
            ON DUPLICATE KEY UPDATE
                print_header = VALUES(print_header), print_footer = VALUES(print_footer),
                print_signature_area = VALUES(print_signature_area), show_logos = VALUES(show_logos),
                custom_header_html = VALUES(custom_header_html), custom_footer_html = VALUES(custom_footer_html),
                rows_per_page = VALUES(rows_per_page), header_font_size = VALUES(header_font_size),
                header_font_color = VALUES(header_font_color), footer_font_size = VALUES(footer_font_size),
                footer_font_color = VALUES(footer_font_color), thead_font_size = VALUES(thead_font_size),
                thead_font_color = VALUES(thead_font_color), thead_bg_color = VALUES(thead_bg_color),
                tbody_font_size = VALUES(tbody_font_size), tbody_font_color = VALUES(tbody_font_color),
                row_min_height = VALUES(row_min_height),
                col_width_prod_date = VALUES(col_width_prod_date), col_width_sample_code = VALUES(col_width_sample_code),
                col_width_break_date = VALUES(col_width_break_date), col_width_age = VALUES(col_width_age),
                col_width_dimensions = VALUES(col_width_dimensions), col_width_strength = VALUES(col_width_strength),
                col_width_status = VALUES(col_width_status)";

    try {
        $stmt = $pdo->prepare($sql);
        $executed = $stmt->execute($new_settings_data);
        if (!$executed) {
            // Execution failed, log specific PDO error
            $errorInfo = $stmt->errorInfo();
            $errorMessage = $errorInfo[2] ?? 'Unknown PDO error';
            error_log("PDO Execute failed for user {$current_user_id}, report {$report_key}: " . print_r($errorInfo, true));
            $settings_save_error = "خطا در اجرای دستور پایگاه داده: " . htmlspecialchars($errorMessage);
            $show_settings_form = true; // Keep form open
            $settings_saved_success = false; // Ensure success flag is false
        } else {
            // Execution succeeded
            $rowCount = $stmt->rowCount(); // How many rows were affected (INSERT=1, UPDATE=1 if changed, UPDATE=0 if no change)
            error_log("PDO Execute success for user {$current_user_id}, report {$report_key}. Rows affected: {$rowCount}");

            // Update the settings variable for immediate display
            $user_print_settings = array_merge($default_print_settings, array_filter($new_settings_data, function ($v) {
                return $v !== null;
            }));
            $user_print_settings['show_logos'] = (bool)$user_print_settings['show_logos'];
            $user_print_settings['rows_per_page'] = (int)$user_print_settings['rows_per_page'];

            $settings_saved_success = true; // Set success flag
            $show_settings_form = false;    // Prepare to hide form (redirect will happen)
        }
    } catch (PDOException $e) {
        // Catch exceptions during prepare() or execute()
        error_log("PDOException saving print settings for user $current_user_id, report {$report_key}: " . $e->getMessage());
        $settings_save_error = "خطا در ذخیره تنظیمات (PDOException).";
        $show_settings_form = true; // Keep form open
        $settings_saved_success = false; // Ensure success flag is false
    }

    if ($settings_saved_success) {
        // Redirect to clean URL
        $redirect_params = $_GET;
        unset($redirect_params['settings']);
        $redirect_url = strtok($_SERVER["REQUEST_URI"], '?') . '?' . http_build_query($redirect_params);
        header('Location: ' . $redirect_url);
        exit();
    }
}



// --- Process GET Filters (Copied from main page) ---
$search_query = "";
$search_params = [];
$sql_conditions = [];
$filter_rejected = $_GET['filter_rejected'] ?? '';
$filter_sample_code = trim($_GET['filter_sample'] ?? '');
$filter_age = trim($_GET['filter_age'] ?? '');
$filter_date_from_j = trim($_GET['filter_date_from'] ?? '');
$filter_date_to_j = trim($_GET['filter_date_to'] ?? '');

// Apply filters (ensure jalali_to_gregorian exists if using date filters)
if (!empty($filter_date_from_j)) {
    $jds = toLatinDigitsPhp($filter_date_from_j);
    $jp = explode('/', $jds);
    if (count($jp) === 3) {
        $ga = jalali_to_gregorian(intval($jp[0]), intval($jp[1]), intval($jp[2]));
        if ($ga) {
            $gs = sprintf('%04d-%02d-%02d', $ga[0], $ga[1], $ga[2]);
            if (DateTime::createFromFormat('Y-m-d', $gs)) {
                $sql_conditions[] = "production_date >= ?";
                $search_params[] = $gs;
            }
        }
    }
}
if (!empty($filter_date_to_j)) {
    $jds = toLatinDigitsPhp($filter_date_to_j);
    $jp = explode('/', $jds);
    if (count($jp) === 3) {
        $ga = jalali_to_gregorian(intval($jp[0]), intval($jp[1]), intval($jp[2]));
        if ($ga) {
            $gs = sprintf('%04d-%02d-%02d', $ga[0], $ga[1], $ga[2]);
            if (DateTime::createFromFormat('Y-m-d', $gs)) {
                $sql_conditions[] = "production_date <= ?";
                $search_params[] = $gs;
            }
        }
    }
}
if (!empty($filter_sample_code)) {
    $sql_conditions[] = "sample_code LIKE ?";
    $search_params[] = "%" . $filter_sample_code . "%";
}
// << Age Filter Logic >>
if ($filter_age !== '' && is_numeric($filter_age)) {
    $sql_conditions[] = "sample_age_at_break = ?";
    $search_params[] = intval($filter_age);
}
if ($filter_rejected === '0' || $filter_rejected === '1') {
    $sql_conditions[] = "is_rejected = ?";
    $search_params[] = intval($filter_rejected);
}
if (!empty($sql_conditions)) {
    $search_query = " WHERE " . implode(" AND ", $sql_conditions);
}

// --- Fetch Records (Using final corrected ORDER BY) ---
$records = []; // Initialize
try {
    $sql = "SELECT * FROM concrete_tests" . $search_query . " ORDER BY production_date DESC, sample_age_at_break ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($search_params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "خطا در بارگذاری داده ها: " . $e->getMessage(); // Show error directly on print page if fetch fails
    // No need for session messages here
}

// Group records for display
$grouped_records = [];
foreach ($records as $record) {
    $grouped_records[$record['production_date']][] = $record;
}

// Function to render the header
function renderHeader($settings)
{
    if (!empty($settings['custom_header_html'])) {
        return $settings['custom_header_html'];
    } else {
        $header = '<table class="header-table">';
        $header .= '<tr>';
        $showLogos = $settings['show_logos'] ?? true; // Default to true if not set
        if ($showLogos) {
            $header .= '<td class="logo-cell logo-left"><img src="/assets/images/alumglass-farsi-logo-H40.png" alt="Logo"></td>';
        }
        $headerText = $settings['print_header'] ?? ''; // Use correct key
        $header .= '<td class="header-center">' . nl2br(htmlspecialchars($headerText)) . '</td>';
        if ($showLogos) {
            $header .= '<td class="logo-cell logo-right"><img src="/assets/images/arad.png" alt="Logo"></td>';
        }
        // Add empty cells if logos are hidden only if the default structure is used
        elseif (!$showLogos && empty($settings['custom_header_html'])) {
            $header .= '<td class="logo-cell logo-left" style="width: 20%;"></td>'; // Empty placeholder with width
            $header .= '<td class="logo-cell logo-right" style="width: 20%;"></td>'; // Empty placeholder with width
        }
        $header .= '</tr>';
        $header .= '</table>';
        return $header;
    }
}
function renderFooter($settings)
{
    if (!empty($settings['custom_footer_html'])) {
        return $settings['custom_footer_html'];
    } else {
        $footerText = $settings['print_footer'] ?? ''; // Use correct key
        $footer = '<div class="footer-text">' . nl2br(htmlspecialchars($footerText)) . '</div>';
        $signatureArea = $settings['print_signature_area'] ?? ''; // Use correct key
        if (!empty($signatureArea)) {
            $footer .= '<table class="signature-table">';
            $footer .= '<thead><tr>' . $signatureArea . '</tr></thead>';
            $footer .= '<tbody><tr>';
            preg_match_all('/<td[^>]*>.*?<\/td>/i', $signatureArea, $matches);
            if (!empty($matches[0])) {
                $footer .= str_repeat('<td></td>', count($matches[0]));
            }
            $footer .= '</tr></tbody>';
            $footer .= '</table>';
        }
        return $footer;
    }
}


// Function to render table headers
function renderTableHeaders()
{
    return '<thead>
        <tr>
            <th>تاریخ تولید</th>
            <th>کد نمونه</th>
            <th>تاریخ شکست</th>
            <th>سن</th>
            <th>ابعاد</th>
            <th>مقاومت</th>
            <th>وضعیت</th>
        </tr>
    </thead>';
}

$pageTitle = "چاپ گزارش تست‌های بتن";
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/persian-datepicker-dark.min.css">
    <!-- For icons -->

    <?php if (!$show_settings_form): ?>
        <style>
            /* --- Base Print Styles & Fonts --- */
            @font-face {
                font-family: 'Vazirmatn';
                src: url('/assets/fonts/Vazirmatn-Regular.woff2') format('woff2');
                font-weight: normal;
                font-style: normal;
            }

            @font-face {
                font-family: 'Vazirmatn';
                src: url('/assets/fonts/Vazirmatn-Bold.woff2') format('woff2');
                font-weight: bold;
                font-style: normal;
            }

            * {
                box-sizing: border-box;
            }

            body {
                font-family: Vazirmatn, Tahoma, sans-serif;
                direction: rtl;
                line-height: 1.4;
                margin: 0;
                padding: 0;
                background-color: #fff;
                color: #000;
            }

            table {
                border-collapse: collapse;
                width: 100%;
                margin-bottom: 1rem;
            }

            th,
            td {
                border: 1px solid #333;
                padding: 6px;
                text-align: center;
                vertical-align: middle;
            }

            /* --- Define CSS Variables from PHP Settings --- */
            :root {
                --header-font-size: <?php echo htmlspecialchars($user_print_settings['header_font_size']); ?>;
                --header-font-color: <?php echo htmlspecialchars($user_print_settings['header_font_color']); ?>;
                --footer-font-size: <?php echo htmlspecialchars($user_print_settings['footer_font_size']); ?>;
                --footer-font-color: <?php echo htmlspecialchars($user_print_settings['footer_font_color']); ?>;
                --thead-font-size: <?php echo htmlspecialchars($user_print_settings['thead_font_size']); ?>;
                --thead-font-color: <?php echo htmlspecialchars($user_print_settings['thead_font_color']); ?>;
                --thead-bg-color: <?php echo htmlspecialchars($user_print_settings['thead_bg_color']); ?>;
                --tbody-font-size: <?php echo htmlspecialchars($user_print_settings['tbody_font_size']); ?>;
                --tbody-font-color: <?php echo htmlspecialchars($user_print_settings['tbody_font_color']); ?>;
                --row-min-height: <?php echo htmlspecialchars($user_print_settings['row_min_height']); ?>;
                /* Column Widths */
                --col-width-prod-date: <?php echo htmlspecialchars($user_print_settings['col_width_prod_date']); ?>;
                --col-width-sample-code: <?php echo htmlspecialchars($user_print_settings['col_width_sample_code']); ?>;
                --col-width-break-date: <?php echo htmlspecialchars($user_print_settings['col_width_break_date']); ?>;
                --col-width-age: <?php echo htmlspecialchars($user_print_settings['col_width_age']); ?>;
                --col-width-dimensions: <?php echo htmlspecialchars($user_print_settings['col_width_dimensions']); ?>;
                --col-width-strength: <?php echo htmlspecialchars($user_print_settings['col_width_strength']); ?>;
                --col-width-status: <?php echo htmlspecialchars($user_print_settings['col_width_status']); ?>;
            }

            /* --- Apply Variables --- */
            .print-page {
                width: 100%;
                position: relative;
                page-break-after: always;
                padding: 1.5cm;
                box-sizing: border-box;
            }

            .print-page:last-child {
                page-break-after: avoid;
            }

            .header {
                width: 100%;
                margin-bottom: 20px;
                border-bottom: 1px solid #ccc;
                padding-bottom: 10px;
            }

            .footer {
                width: 100%;
                margin-top: 20px;
                border-top: 1px solid #ccc;
                padding-top: 10px;
            }

            .header-table {
                width: 100%;
                border: none;
                margin-bottom: 0;
            }

            .header-table td {
                border: none;
                vertical-align: middle;
                padding: 5px;
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
                line-height: 1.3;
                white-space: pre-wrap;
                font-size: var(--header-font-size);
                color: var(--header-font-color);
                font-weight: bold;
                /* Keep header bold */
            }

            .header img {
                max-height: 60px;
                max-width: 100%;
            }

            .footer-text {
                text-align: center;
                margin-bottom: 10px;
                white-space: pre-wrap;
                font-size: var(--footer-font-size);
                color: var(--footer-font-color);
            }

            .signature-table {
                margin-top: 15px;
                width: 100%;
                margin-bottom: 0;
            }

            .signature-table th {
                font-weight: bold;
                background-color: #f8f8f8;
                padding: 5px;
                border: 1px solid #ccc;
                font-size: var(--footer-font-size);
                color: var(--footer-font-color);
            }

            .signature-table td {
                height: 60px;
                vertical-align: bottom;
                padding: 5px;
                border: 1px solid #ccc;
            }

            .data-table {
                width: 100%;
                border: 1px solid #333;
                margin-bottom: 1rem;
                table-layout: fixed;
            }

            /* Added table-layout fixed */
            .data-table th {
                padding: 5px;
                border: 1px solid #333;
                text-align: center;
                vertical-align: middle;
                font-size: var(--thead-font-size);
                color: var(--thead-font-color);
                background-color: var(--thead-bg-color);
                font-weight: bold;
            }

            .data-table td {
                padding: 5px;
                border: 1px solid #333;
                text-align: center;
                vertical-align: middle;
                font-size: var(--tbody-font-size);
                color: var(--tbody-font-color);
                min-height: var(--row-min-height);
                height: var(--row-min-height);
                /* May need adjustment based on content */
                word-wrap: break-word;
                /* Help prevent overflow */
            }

            /* Apply Column Widths */
            .data-table th:nth-child(1),
            .data-table td:nth-child(1) {
                width: var(--col-width-prod-date);
            }

            .data-table th:nth-child(2),
            .data-table td:nth-child(2) {
                width: var(--col-width-sample-code);
            }

            .data-table th:nth-child(3),
            .data-table td:nth-child(3) {
                width: var(--col-width-break-date);
            }

            .data-table th:nth-child(4),
            .data-table td:nth-child(4) {
                width: var(--col-width-age);
            }

            .data-table th:nth-child(5),
            .data-table td:nth-child(5) {
                width: var(--col-width-dimensions);
            }

            .data-table th:nth-child(6),
            .data-table td:nth-child(6) {
                width: var(--col-width-strength);
            }

            .data-table th:nth-child(7),
            .data-table td:nth-child(7) {
                width: var(--col-width-status);
            }


            .badge {
                display: inline-block;
                padding: 2px 8px;
                font-weight: bold;
                border-radius: 4px;
                font-size: var(--tbody-font-size);
            }

            /* Inherit font size */
            .badge-success {
                background-color: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }

            .badge-danger {
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }

            .group-start td {
                border-top: 2px solid #333 !important;
            }

            .page-number {
                text-align: center;
                margin-top: 10px;
                font-size: var(--footer-font-size);
                color: var(--footer-font-color);
            }

            @media print {
                body {
                    print-color-adjust: exact;
                    -webkit-print-color-adjust: exact;
                    margin: 0;
                    padding: 0;
                }

                .page-break {
                    page-break-before: always;
                }

                .no-print {
                    display: none !important;
                }

                @page {
                    margin: 0;
                    size: A4;
                }

                thead {
                    display: table-header-group;
                }

                .header,
                .footer {
                    position: static;
                }

                .print-page {
                    padding: 1.5cm;
                }

                /* Ensure padding for print */
            }
        </style>
        <script>
            // --- Pagination Javascript ---
            // Uses the rows_per_page setting loaded via PHP
            document.addEventListener('DOMContentLoaded', function() {
                const rowsPerPageSetting = <?php echo $user_print_settings['rows_per_page'] ?? 12; ?>;
                const table = document.getElementById('data-table');
                const contentArea = document.getElementById('content-area');

                if (!table || !table.querySelector('tbody') || table.querySelector('tbody').rows.length === 0 || !contentArea) {
                    const pageNumberDiv = contentArea?.querySelector('.page-number');
                    if (pageNumberDiv) pageNumberDiv.textContent = 'صفحه 1 از 1';
                    return;
                }
                const tbody = table.querySelector('tbody');
                const originalRows = Array.from(tbody.querySelectorAll('tr'));

                if (originalRows.length <= rowsPerPageSetting) {
                    const pageNumberDiv = contentArea.querySelector('.page-number');
                    if (pageNumberDiv) pageNumberDiv.textContent = 'صفحه 1 از 1';
                    return;
                }
                contentArea.innerHTML = '';
                let pageNum = 1;
                let rowsOnCurrentPage = 0;
                let currentPageDiv = null;
                let currentPageTableBody = null;

                const renderedHeaderHtml = <?php echo json_encode(renderHeader($user_print_settings)); ?>;
                const renderedFooterHtml = <?php echo json_encode(renderFooter($user_print_settings)); ?>;

                function startNewPage(isFirstPage = false) {
                    currentPageDiv = document.createElement('div');
                    currentPageDiv.className = 'print-page';
                    if (!isFirstPage) {
                        currentPageDiv.classList.add('page-break');
                    }

                    const headerDiv = document.createElement('div');
                    headerDiv.className = 'header';
                    headerDiv.innerHTML = renderedHeaderHtml;
                    currentPageDiv.appendChild(headerDiv);
                    const titleDiv = document.createElement('div');
                    titleDiv.style.textAlign = 'center';
                    titleDiv.style.fontWeight = 'bold';
                    titleDiv.style.marginBottom = '15px';
                    titleDiv.style.fontSize = '14pt';
                    titleDiv.textContent = 'لیست تست‌ها';
                    currentPageDiv.appendChild(titleDiv);
                    const pageTable = document.createElement('table');
                    pageTable.className = 'data-table';
                    pageTable.innerHTML = table.querySelector('thead').outerHTML;
                    currentPageTableBody = document.createElement('tbody');
                    pageTable.appendChild(currentPageTableBody);
                    currentPageDiv.appendChild(pageTable);
                    contentArea.appendChild(currentPageDiv);
                    rowsOnCurrentPage = 0;
                }
                startNewPage(true);
                originalRows.forEach(row => {
                    if (rowsOnCurrentPage >= rowsPerPageSetting) {
                        const footerDiv = document.createElement('div');
                        footerDiv.className = 'footer';
                        footerDiv.innerHTML = renderedFooterHtml;
                        const pageNumberDiv = document.createElement('div');
                        pageNumberDiv.className = 'page-number';
                        footerDiv.appendChild(pageNumberDiv);
                        currentPageDiv.appendChild(footerDiv);
                        pageNum++;
                        startNewPage();
                    }
                    currentPageTableBody.appendChild(row.cloneNode(true));
                    rowsOnCurrentPage++;
                });
                const finalFooterDiv = document.createElement('div');
                finalFooterDiv.className = 'footer';
                finalFooterDiv.innerHTML = renderedFooterHtml;
                const finalPageNumberDiv = document.createElement('div');
                finalPageNumberDiv.className = 'page-number';
                finalFooterDiv.appendChild(finalPageNumberDiv);
                if (currentPageDiv) {
                    currentPageDiv.appendChild(finalFooterDiv);
                }

                const pageNumberElements = contentArea.querySelectorAll('.page-number');
                pageNumberElements.forEach((el, index) => {
                    el.textContent = 'صفحه ' + (index + 1) + ' از ' + pageNum;
                });
                table.style.display = 'none';
            });
        </script>
    <?php else: // --- Settings Form Styles --- 
    ?>
        <style>
            body {
                font-family: Vazirmatn, Tahoma, sans-serif;
                direction: rtl;
                line-height: 1.5;
                margin: 20px;
                background-color: #f8f9fa;
            }

            .container {
                max-width: 900px;
                margin: 20px auto;
                background: white;
                padding: 25px;
                border-radius: 8px;
                box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
            }

            h1 {
                color: #333;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
                margin-bottom: 25px;
                font-size: 1.5em;
            }

            .form-section {
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 1px dashed #eee;
            }

            .form-section:last-child {
                border-bottom: none;
                margin-bottom: 0;
                padding-bottom: 0;
            }

            .form-group {
                margin-bottom: 15px;
            }

            .form-row {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
                margin-bottom: 15px;
            }

            .form-row .form-group {
                flex: 1;
                min-width: 150px;
                margin-bottom: 0;
            }

            label {
                display: block;
                margin-bottom: 6px;
                font-weight: bold;
                font-size: 0.9em;
                color: #555;
            }

            input[type="text"],
            input[type="number"],
            input[type="color"],
            textarea {
                width: 100%;
                padding: 10px;
                border: 1px solid #ccc;
                border-radius: 4px;
                font-family: inherit;
                direction: rtl;
                box-sizing: border-box;
                font-size: 0.9em;
            }

            input[type="color"] {
                padding: 2px 5px;
                height: 38px;
                cursor: pointer;
            }

            /* Style color picker */
            textarea {
                min-height: 100px;
            }

            .checkbox-group {
                margin: 15px 0;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .checkbox-group input {
                width: auto;
            }

            .checkbox-group label {
                margin-bottom: 0;
                font-weight: normal;
            }

            button {
                background: #0d6efd;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 5px;
                cursor: pointer;
                font-size: 1em;
                font-family: inherit;
                transition: background-color 0.2s ease;
            }

            button:hover {
                background: #0b5ed7;
            }

            .btn-secondary {
                background: #6c757d;
            }

            .btn-secondary:hover {
                background: #5a6268;
            }

            .preview {
                background: #f8f8f8;
                border: 1px solid #ddd;
                padding: 10px;
                margin-top: 8px;
                border-radius: 4px;
                white-space: pre-wrap;
                font-size: smaller;
                min-height: 30px;
            }

            .buttons {
                margin-top: 30px;
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                padding-top: 20px;
                border-top: 1px solid #eee;
            }

            small {
                display: block;
                margin-top: 5px;
                color: #666;
                font-size: 0.85em;
            }

            .error-message {
                color: #721c24;
                border: 1px solid #f5c6cb;
                padding: 12px;
                margin-bottom: 20px;
                border-radius: 4px;
                background-color: #f8d7da;
            }

            legend {
                font-weight: bold;
                font-size: 1.1em;
                margin-bottom: 15px;
                color: #0d6efd;
            }
        </style>
    <?php endif; ?>
</head>

<body>
    <?php if ($show_settings_form): ?>
        <div class="container">
            <h1>تنظیمات چاپ</h1>
            <?php if ($settings_save_error): ?>
                <div class="error-message"><?php echo htmlspecialchars($settings_save_error); ?></div>
            <?php endif; ?>

            <form method="post" action="concrete_tests_print.php?<?php echo http_build_query($_GET); ?>">
                <input type="hidden" name="save_print_settings" value="1">

                <fieldset class="form-section">
                    <legend>محتوای کلی</legend>
                    <div class="form-group">
                        <label for="print_header_input">سربرگ:</label>
                        <textarea name="print_header" id="print_header_input"><?php echo htmlspecialchars($user_print_settings['print_header']); ?></textarea>
                        <div class="preview"><?php echo nl2br(htmlspecialchars($user_print_settings['print_header'])); ?></div>
                    </div>
                    <div class="form-group">
                        <label for="print_footer_input">پاورقی:</label>
                        <textarea name="print_footer" id="print_footer_input"><?php echo htmlspecialchars($user_print_settings['print_footer']); ?></textarea>
                        <div class="preview"><?php echo nl2br(htmlspecialchars($user_print_settings['print_footer'])); ?></div>
                    </div>
                    <div class="form-group">
                        <label for="print_signature_area_input">جدول امضاها (HTML):</label>
                        <textarea name="print_signature_area" id="print_signature_area_input" placeholder="مثال: <td>مسئول</td>"><?php echo htmlspecialchars($user_print_settings['print_signature_area']); ?></textarea>
                        <!-- Preview for signature -->
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="show_logos_input" name="show_logos" <?php echo $user_print_settings['show_logos'] ? 'checked' : ''; ?> value="1">
                        <label for="show_logos_input">نمایش لوگوها (در صورت عدم استفاده از هدر سفارشی)</label>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>استایل‌ها</legend>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="header_font_size">اندازه فونت سربرگ:</label>
                            <input type="text" name="header_font_size" id="header_font_size" value="<?php echo htmlspecialchars($user_print_settings['header_font_size']); ?>" placeholder="مثال: 14pt">
                        </div>
                        <div class="form-group">
                            <label for="header_font_color">رنگ فونت سربرگ:</label>
                            <input type="color" name="header_font_color" id="header_font_color" value="<?php echo htmlspecialchars($user_print_settings['header_font_color']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="footer_font_size">اندازه فونت پاورقی:</label>
                            <input type="text" name="footer_font_size" id="footer_font_size" value="<?php echo htmlspecialchars($user_print_settings['footer_font_size']); ?>" placeholder="مثال: 10pt">
                        </div>
                        <div class="form-group">
                            <label for="footer_font_color">رنگ فونت پاورقی:</label>
                            <input type="color" name="footer_font_color" id="footer_font_color" value="<?php echo htmlspecialchars($user_print_settings['footer_font_color']); ?>">
                        </div>
                    </div>
                    <hr style="margin: 20px 0; border-top: 1px dashed #eee;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="thead_font_size">اندازه فونت هدر جدول:</label>
                            <input type="text" name="thead_font_size" id="thead_font_size" value="<?php echo htmlspecialchars($user_print_settings['thead_font_size']); ?>" placeholder="مثال: 10pt">
                        </div>
                        <div class="form-group">
                            <label for="thead_font_color">رنگ فونت هدر جدول:</label>
                            <input type="color" name="thead_font_color" id="thead_font_color" value="<?php echo htmlspecialchars($user_print_settings['thead_font_color']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="thead_bg_color">رنگ پس‌زمینه هدر جدول:</label>
                            <input type="color" name="thead_bg_color" id="thead_bg_color" value="<?php echo htmlspecialchars($user_print_settings['thead_bg_color']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="tbody_font_size">اندازه فونت بدنه جدول:</label>
                            <input type="text" name="tbody_font_size" id="tbody_font_size" value="<?php echo htmlspecialchars($user_print_settings['tbody_font_size']); ?>" placeholder="مثال: 10pt">
                        </div>
                        <div class="form-group">
                            <label for="tbody_font_color">رنگ فونت بدنه جدول:</label>
                            <input type="color" name="tbody_font_color" id="tbody_font_color" value="<?php echo htmlspecialchars($user_print_settings['tbody_font_color']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="row_min_height">حداقل ارتفاع ردیف:</label>
                            <input type="text" name="row_min_height" id="row_min_height" value="<?php echo htmlspecialchars($user_print_settings['row_min_height']); ?>" placeholder="مثال: 30px یا auto">
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>عرض ستون‌های جدول</legend>
                    <small>مقادیر را با % یا px وارد کنید.</small>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="col_width_prod_date">تاریخ تولید:</label>
                            <input type="text" name="col_width_prod_date" id="col_width_prod_date" value="<?php echo htmlspecialchars($user_print_settings['col_width_prod_date']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="col_width_sample_code">کد نمونه:</label>
                            <input type="text" name="col_width_sample_code" id="col_width_sample_code" value="<?php echo htmlspecialchars($user_print_settings['col_width_sample_code']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="col_width_break_date">تاریخ شکست:</label>
                            <input type="text" name="col_width_break_date" id="col_width_break_date" value="<?php echo htmlspecialchars($user_print_settings['col_width_break_date']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="col_width_age">سن:</label>
                            <input type="text" name="col_width_age" id="col_width_age" value="<?php echo htmlspecialchars($user_print_settings['col_width_age']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="col_width_dimensions">ابعاد:</label>
                            <input type="text" name="col_width_dimensions" id="col_width_dimensions" value="<?php echo htmlspecialchars($user_print_settings['col_width_dimensions']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="col_width_strength">مقاومت:</label>
                            <input type="text" name="col_width_strength" id="col_width_strength" value="<?php echo htmlspecialchars($user_print_settings['col_width_strength']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="col_width_status">وضعیت:</label>
                            <input type="text" name="col_width_status" id="col_width_status" value="<?php echo htmlspecialchars($user_print_settings['col_width_status']); ?>">
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>صفحه‌بندی و سفارشی‌سازی پیشرفته</legend>
                    <div class="form-group">
                        <label for="rows_per_page_input">تعداد ردیف داده در هر صفحه:</label>
                        <input type="number" id="rows_per_page_input" name="rows_per_page" min="5" max="50" value="<?php echo $user_print_settings['rows_per_page']; ?>">
                        <small>این تنظیم ممکن است گروه‌های تاریخ تولید را در صفحات مختلف تقسیم کند.</small>
                    </div>
                    <div class="form-group">
                        <label for="custom_header_html_input">HTML سفارشی سربرگ (اختیاری):</label>
                        <textarea name="custom_header_html" id="custom_header_html_input" placeholder="کد کامل HTML برای سربرگ خود را اینجا وارد کنید..."><?php echo htmlspecialchars($user_print_settings['custom_header_html']); ?></textarea>
                        <small>اگر می‌خواهید سربرگ را کاملاً سفارشی کنید، کد HTML خود را اینجا وارد کنید.</small>
                    </div>
                    <div class="form-group">
                        <label for="custom_footer_html_input">HTML سفارشی پاورقی (اختیاری):</label>
                        <textarea name="custom_footer_html" id="custom_footer_html_input" placeholder="کد کامل HTML برای پاورقی خود را اینجا وارد کنید..."><?php echo htmlspecialchars($user_print_settings['custom_footer_html']); ?></textarea>
                        <small>اگر می‌خواهید پاورقی را کاملاً سفارشی کنید، کد HTML خود را اینجا وارد کنید.</small>
                    </div>
                </fieldset>


                <div class="buttons">
                    <button type="submit">ذخیره تنظیمات و نمایش چاپ</button>
                    <?php $cancel_params = $_GET;
                    unset($cancel_params['settings']);
                    $cancel_url = strtok($_SERVER["REQUEST_URI"], '?') . '?' . http_build_query($cancel_params); ?>
                    <a href="<?php echo htmlspecialchars($cancel_url); ?>"><button type="button" class="btn-secondary">انصراف</button></a>
                    <?php $return_page = in_array($_SESSION['role'], $authroles) ? 'concrete_tests.php' : 'concrete.php';
                    $return_query_string = http_build_query($cancel_params);
                    $return_url = $return_page . (!empty($return_query_string) ? '?' . $return_query_string : ''); ?>
                    <a href="<?php echo htmlspecialchars($return_url); ?>"><button type="button" class="btn-secondary">بازگشت به لیست</button></a>
                </div>
            </form>
        </div>

    <?php else: // Print View 
    ?>
        <div id="content-area">
            <div class="print-page"> <!-- Initial wrapper -->
                <div class="header">
                    <?php echo renderHeader($user_print_settings); ?>
                </div>
                <div style="text-align: center; font-weight: bold; margin-bottom: 15px; font-size: var(--header-font-size);"> <!-- Use variable -->
                    لیست تست‌ها
                </div>
                <table id="data-table" class="data-table">
                    <?php echo renderTableHeaders(); ?>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 15px; color: #666;">رکوردی یافت نشد.</td>
                            </tr>
                            <?php else:
                            foreach ($grouped_records as $prod_date => $group_records):
                                //$row_count = count($group_records);
                                $first_row_in_group = true;
                                foreach ($group_records as $record):
                                    $row_class = $record['is_rejected'] ? 'rejected' : '';
                                    if ($first_row_in_group) {
                                        $row_class .= ' group-start';
                                    }
                            ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td>
                                            <?php
                                            // Always display the production date for this group
                                            $prod_shamsi = gregorianToShamsi($prod_date);
                                            echo !empty($prod_shamsi) ? $prod_shamsi : '-';
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($record['sample_code']); ?></td>
                                        <td><?php $break_shamsi = gregorianToShamsi($record['break_date']);
                                            echo !empty($break_shamsi) ? $break_shamsi : '-'; ?></td>
                                        <td><?php echo htmlspecialchars($record['sample_age_at_break'] ?? '-'); ?></td>
                                        <td><?php echo ($record['dimension_l'] && $record['dimension_w'] && $record['dimension_h']) ? htmlspecialchars(number_format((float)$record['dimension_l'], 1)) . '×' . htmlspecialchars(number_format((float)$record['dimension_w'], 1)) . '×' . htmlspecialchars(number_format((float)$record['dimension_h'], 1)) : '-'; ?></td>

                                        <td>
                                            <?php /* Strength display logic... */
                                            $display_strength = '-';
                                            if ($record['sample_age_at_break'] == 1) {
                                                if ($record['strength_1_day'] !== null) {
                                                    $display_strength = "<div style='border-bottom: 1px solid #aaa; font-weight: bold; padding-bottom: 2px; margin-bottom: 2px;'>مقاومت 1 روزه</div><div>" . number_format((float)$record['strength_1_day'], 2) . "</div>";
                                                } elseif ($record['compressive_strength_mpa'] !== null) {
                                                    $display_strength = "<div style='border-bottom: 1px solid #aaa; font-weight: bold; padding-bottom: 2px; margin-bottom: 2px;'>مقاومت نمونه 1 روزه</div><div>" . number_format((float)$record['compressive_strength_mpa'], 2) . "</div>";
                                                }
                                            } elseif ($record['sample_age_at_break'] == 7 && $record['strength_7_day'] !== null) {
                                                $display_strength = "<div style='border-bottom: 1px solid #aaa; font-weight: bold; padding-bottom: 2px; margin-bottom: 2px;'>مقاومت 7 روزه</div><div>" . number_format((float)$record['strength_7_day'], 2) . "</div>";
                                            } elseif ($record['sample_age_at_break'] == 28 && $record['strength_28_day'] !== null) {
                                                $display_strength = "<div style='border-bottom: 1px solid #aaa; font-weight: bold; padding-bottom: 2px; margin-bottom: 2px;'>مقاومت 28 روزه</div><div>" . number_format((float)$record['strength_28_day'], 2) . "</div>";
                                            } elseif ($record['compressive_strength_mpa'] !== null) {
                                                $display_strength = "<div style='border-bottom: 1px solid #aaa; font-weight: bold; padding-bottom: 2px; margin-bottom: 2px;'>مقاومت</div><div>" . number_format((float)$record['compressive_strength_mpa'], 2) . "</div>";
                                            }
                                            echo $display_strength; ?>
                                        </td>
                                        <td><?php if ($record['is_rejected']): ?> <span class="badge badge-danger">مردود</span> <?php else: ?> <span class="badge badge-success">قبول</span> <?php endif; ?></td>
                                    </tr>
                        <?php
                                    $first_row_in_group = false; // Mark first row as done for the group border style
                                endforeach; // End loop through records in group
                            endforeach; // End loop through grouped dates
                        endif; // End if empty records
                        ?>
                    </tbody>
                </table>
                <div class="footer">
                    <?php echo renderFooter($user_print_settings); ?>
                    <div class="page-number"></div>
                </div>
            </div>
        </div>

        <!-- Print Controls -->
        <div class="no-print" style="position: fixed; bottom: 10px; left: 10px; background: rgba(255,255,255,0.8); padding: 10px; border-radius: 5px; box-shadow: 0 0 5px rgba(0,0,0,0.2); z-index: 100; display: flex; gap: 10px;">
            <button onclick="window.print();" style="padding: 8px 15px; background-color: #198754; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;"> <i class="bi bi-printer"></i> چاپ </button>
            <?php $settings_params = $_GET;
            $settings_params['settings'] = '1';
            $settings_url = strtok($_SERVER["REQUEST_URI"], '?') . '?' . http_build_query($settings_params); ?>
            <a href="<?php echo htmlspecialchars($settings_url); ?>" style="padding: 8px 15px; background-color: #0d6efd; color: white; text-decoration: none; border-radius: 4px; font-size: 12px;"> <i class="bi bi-gear"></i> تنظیمات </a>
            <?php $return_page = in_array($_SESSION['role'], $authroles) ? 'concrete_tests.php' : 'concrete.php';
            $return_params = $_GET;
            unset($return_params['settings']);
            $return_query_string = http_build_query($return_params);
            $return_url = $return_page . (!empty($return_query_string) ? '?' . $return_query_string : ''); ?>
            <a href="<?php echo htmlspecialchars($return_url); ?>" style="padding: 8px 15px; background-color: #6c757d; color: white; text-decoration: none; border-radius: 4px; font-size: 12px;"> <i class="bi bi-arrow-left-circle"></i> بازگشت </a>
        </div>
    <?php endif; ?>

</body>

</html>
<?php ob_end_flush(); ?>