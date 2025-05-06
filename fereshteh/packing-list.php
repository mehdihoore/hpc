<?php
// packing-list.php - Refactored Version

// --- Basic Setup, Dependencies, Session Auth ---
ini_set('display_errors', 1); // Keep for debugging during development
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../sercon/config_fereshteh.php'; // Adjust path if needed
require_once __DIR__ . '/includes/jdf.php';      // Ensure jdf functions are available
require_once __DIR__ . '/includes/functions.php'; // For secureSession

secureSession(); // Start session securely

// Authentication Check (Adapt roles as needed)
$allowed_roles = ['admin', 'superuser', 'supervisor', 'planner', 'user', 'receiver'];
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Location: /login'); // Redirect to your login page
    exit('Access Denied.');
}
$current_user_id = $_SESSION['user_id'];
$report_key = 'packing_list'; // Unique key for these settings

// DB Connection
try {
    $pdo = connectDB(); // Use your existing connection function
} catch (PDOException $e) {
    error_log("DB Connection failed in packing-list.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده.");
}

// --- PHP Helper Functions (Only those needed for display) ---
// Add gregorianToShamsi if not globally available or in functions.php
// (Using jdate from jdf.php is generally preferred if available)
// Add sanitize_css_value and sanitize_color if not globally available
function sanitize_css_value($value, $default = '', $allow_auto = false)
{
    $value = trim($value);
    if ($allow_auto && strtolower($value) === 'auto') return 'auto';
    if (preg_match('/^(\d+(\.\d+)?(pt|px|em|%|mm)|#[0-9a-fA-F]{3,6}|[a-zA-Z]+)$/', $value)) { // Added mm
        return $value;
    }
    return $default;
}
function sanitize_color($value, $default = '#000000')
{
    $value = trim($value);
    if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $value) || filter_var($value, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^[a-zA-Z]+$/']])) {
        return $value;
    }
    return $default;
}

// --- Load Print Settings ---
$default_print_settings = [
    'print_header' => "لیست بارگیری پنل‌های HPC\nشرکت آلومنیوم شیشه تهران\nپروژه فرشته",
    'print_footer' => "تاریخ چاپ: " . jdate('Y/m/d'),
    'print_signature_area' => "<td>تهیه کننده</td><td>راننده کامیون</td><td>تحویل گیرنده (سایت)</td>",
    'show_logos' => true,
    'custom_header_html' => '',
    'custom_footer_html' => '',
    'rows_per_page' => 15,
    'header_font_size' => '11pt',
    'header_font_color' => '#000000',
    'footer_font_size' => '8pt',
    'footer_font_color' => '#333333',
    'thead_font_size' => '8pt',
    'thead_font_color' => '#000000',
    'thead_bg_color' => '#e8e8e8',
    'tbody_font_size' => '7pt',
    'tbody_font_color' => '#000000',
    'row_min_height' => 'auto',

    // ---> UPDATED Column Widths (Now 8 columns) <---
    'col_width_row_num'   => '4%',
    'col_width_zone'      => '5%',
    'col_width_stand_num' => '4%', // NEW: Stand Number Width
    'col_width_address'   => '26%', // Adjusted
    'col_width_type'      => '10%', // Adjusted
    'col_width_length'    => '8%',  // Adjusted
    'col_width_width'     => '8%',  // Adjusted
    'col_width_area'      => '9%',  // Adjusted
    'col_width_desc'      => '26%', // Adjusted
    // -----------------------------------------> Total = 100%
    'factory_address' => " مشهد. کیلومتر ۲۰ جاده مشهد چناران. شهرک صنعتی فناوری برتر. صنعت ۱۰. یک قطعه مانده به انتها. شرکت الومینیوم‌شیشه تهران",
    'footer_preparer_name' => 'معین کریمی نژاد',
    'footer_receiver_name' => 'علی پیر حیاتی'
];

$user_print_settings = $default_print_settings; // Start with defaults

try {
    $stmt = $pdo->prepare("SELECT * FROM user_print_settings WHERE user_id = ? AND report_key = ?");
    $stmt->execute([$current_user_id, $report_key]);
    $db_settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($db_settings) {
        $db_settings_filtered = array_filter($db_settings, function ($value) {
            return $value !== null;
        });
        // ---> MERGE DEFAULTS FIRST to ensure all keys exist <---
        $user_print_settings = array_merge($default_print_settings, $db_settings_filtered);
        $user_print_settings['show_logos'] = isset($user_print_settings['show_logos']) ? (bool)$user_print_settings['show_logos'] : true;
        $user_print_settings['rows_per_page'] = isset($user_print_settings['rows_per_page']) ? max(5, (int)$user_print_settings['rows_per_page']) : $default_print_settings['rows_per_page'];
    }
} catch (PDOException $e) {
    error_log("Error loading print settings for user {$current_user_id}, report {$report_key}: " . $e->getMessage());
}

// --- Process Settings Form Submission ---
$show_settings_form = isset($_GET['settings']) && $_GET['settings'] == '1';
$settings_saved_success = false;
$settings_save_error = null;

if (isset($_POST['save_print_settings'])) {
    $new_settings_data = [
        'user_id' => $current_user_id,
        'report_key' => $report_key,
        // ... (copy all general fields: print_header, footer, signature, logos, custom html, rows, fonts, colors) ...
        'print_header' => trim($_POST['print_header'] ?? ''),
        'print_footer' => trim($_POST['print_footer'] ?? ''),
        'print_signature_area' => trim($_POST['print_signature_area'] ?? ''),
        'show_logos' => isset($_POST['show_logos']) ? 1 : 0,
        'custom_header_html' => trim($_POST['custom_header_html'] ?? ''),
        'custom_footer_html' => trim($_POST['custom_footer_html'] ?? ''),
        'rows_per_page' => isset($_POST['rows_per_page']) ? max(5, (int)$_POST['rows_per_page']) : $default_print_settings['rows_per_page'],
        'header_font_size' => sanitize_css_value($_POST['header_font_size'] ?? '', $default_print_settings['header_font_size']),
        'header_font_color' => sanitize_color($_POST['header_font_color'] ?? '', $default_print_settings['header_font_color']),
        'footer_font_size' => sanitize_css_value($_POST['footer_font_size'] ?? '', $default_print_settings['footer_font_size']),
        'footer_font_color' => sanitize_color($_POST['footer_font_color'] ?? '', $default_print_settings['footer_font_color']),
        'thead_font_size' => sanitize_css_value($_POST['thead_font_size'] ?? '', $default_print_settings['thead_font_size']),
        'thead_font_color' => sanitize_color($_POST['thead_font_color'] ?? '', $default_print_settings['thead_font_color']),
        'thead_bg_color' => sanitize_color($_POST['thead_bg_color'] ?? '', $default_print_settings['thead_bg_color']),
        'tbody_font_size' => sanitize_css_value($_POST['tbody_font_size'] ?? '', $default_print_settings['tbody_font_size']),
        'tbody_font_color' => sanitize_color($_POST['tbody_font_color'] ?? '', $default_print_settings['tbody_font_color']),
        'row_min_height' => sanitize_css_value($_POST['row_min_height'] ?? '', $default_print_settings['row_min_height'], true),
        // ---> UPDATED Packing List Columns <---
        'col_width_row_num' => sanitize_css_value($_POST['col_width_row_num'] ?? '', $default_print_settings['col_width_row_num']),
        'col_width_zone'    => sanitize_css_value($_POST['col_width_zone'] ?? '', $default_print_settings['col_width_zone']), // Added
        'col_width_stand_num' => sanitize_css_value($_POST['col_width_stand_num'] ?? '', $default_print_settings['col_width_stand_num']),
        'col_width_address' => sanitize_css_value($_POST['col_width_address'] ?? '', $default_print_settings['col_width_address']),
        'col_width_type'    => sanitize_css_value($_POST['col_width_type'] ?? '', $default_print_settings['col_width_type']),
        'col_width_length'  => sanitize_css_value($_POST['col_width_length'] ?? '', $default_print_settings['col_width_length']),
        'col_width_width'   => sanitize_css_value($_POST['col_width_width'] ?? '', $default_print_settings['col_width_width']),
        'col_width_area'    => sanitize_css_value($_POST['col_width_area'] ?? '', $default_print_settings['col_width_area']),
        'col_width_desc'    => sanitize_css_value($_POST['col_width_desc'] ?? '', $default_print_settings['col_width_desc']),
        'factory_address' => trim($_POST['factory_address'] ?? $default_print_settings['factory_address']),
        'footer_preparer_name' => trim($_POST['footer_preparer_name'] ?? $default_print_settings['footer_preparer_name']),
        'footer_receiver_name' => trim($_POST['footer_receiver_name'] ?? $default_print_settings['footer_receiver_name'])
    ];


    // Prepare SQL for INSERT/UPDATE (ensure it includes all new columns)
    $sql_columns = array_keys($new_settings_data);
    $sql_placeholders = ':' . implode(', :', $sql_columns);
    $sql_update_parts = [];
    foreach ($sql_columns as $col) {
        if ($col !== 'user_id' && $col !== 'report_key') {
            $sql_update_parts[] = "`" . $col . "` = VALUES(`" . $col . "`)";
        }
    }
    $sql = "INSERT INTO user_print_settings (`" . implode('`, `', $sql_columns) . "`)
            VALUES (" . $sql_placeholders . ")
            ON DUPLICATE KEY UPDATE " . implode(', ', $sql_update_parts);

    try {
        $stmt = $pdo->prepare($sql);
        $executed = $stmt->execute($new_settings_data); // Use the full array
        if (!$executed) {
            $errorInfo = $stmt->errorInfo();
            $errorMessage = $errorInfo[2] ?? 'Unknown PDO error';
            error_log("PDO Execute failed saving packing list settings for user {$current_user_id}: " . print_r($errorInfo, true));
            $settings_save_error = "خطا در اجرای دستور پایگاه داده: " . htmlspecialchars($errorMessage);
            $show_settings_form = true;
            $settings_saved_success = false;
        } else {
            // Update current settings variable
            $user_print_settings = array_merge($default_print_settings, array_filter($new_settings_data, function ($v) {
                return $v !== null;
            }));
            $user_print_settings['show_logos'] = (bool)$user_print_settings['show_logos'];
            $user_print_settings['rows_per_page'] = (int)$user_print_settings['rows_per_page'];
            $settings_saved_success = true;
            $show_settings_form = false;
        }
    } catch (PDOException $e) {
        error_log("PDOException saving packing list settings for user $current_user_id: " . $e->getMessage());
        $settings_save_error = "خطا در ذخیره تنظیمات (PDOException).";
        $show_settings_form = true;
        $settings_saved_success = false;
    }

    if ($settings_saved_success) {
        // Redirect to the same page without 'settings=1' and without POST data
        $redirect_params = $_GET;
        unset($redirect_params['settings']); // Remove settings flag
        $redirect_url = strtok($_SERVER["REQUEST_URI"], '?') . (!empty($redirect_params) ? '?' . http_build_query($redirect_params) : '');
        header('Location: ' . $redirect_url);
        exit();
    }
}

// --- Fetch Actual Data for Packing List ---
if (!isset($_GET['truck_id'])) {
    die("خطا: شناسه کامیون مشخص نشده است.");
}
$truckId = (int)$_GET['truck_id'];
$truck = null;
$shipment = null;
$allPanels = [];
$totalPanels = 0;
$grandTotalArea = 0;

try {
    // Get truck info
    $stmt_truck = $pdo->prepare("SELECT * FROM trucks WHERE id = ?");
    $stmt_truck->execute([$truckId]);
    $truck = $stmt_truck->fetch(PDO::FETCH_ASSOC);
    if (!$truck) die("خطا: کامیون یافت نشد.");

    // Get shipment info (most recent)
    $stmt_shipment = $pdo->prepare("SELECT s.*, u.first_name, u.last_name FROM shipments s LEFT JOIN users u ON s.created_by = u.id WHERE s.truck_id = ? ORDER BY s.id DESC LIMIT 1");
    $stmt_shipment->execute([$truckId]);
    $shipment = $stmt_shipment->fetch(PDO::FETCH_ASSOC);

    // Get Panels
    $stmt_panels = $pdo->prepare("
        SELECT
            p.*,
            p.length / 1000 as length_m,
            p.width / 1000 as width_m,
            psa.stand_id  -- Select the stand_id from the assignments table
        FROM
            hpc_panels p
        LEFT JOIN
            panel_stand_assignments psa ON p.id = psa.panel_id AND psa.truck_id = :truck_id_psa -- Join on panel_id AND truck_id
        WHERE
            p.truck_id = :truck_id_p -- Filter panels by truck_id
        ORDER BY
            p.id -- Or psa.stand_id, psa.position if you prefer ordering by stand/position
    ");
    // Bind truckId twice for both conditions
    $stmt_panels->bindParam(':truck_id_psa', $truckId, PDO::PARAM_INT);
    $stmt_panels->bindParam(':truck_id_p', $truckId, PDO::PARAM_INT);
    $stmt_panels->execute();
    $allPanels = $stmt_panels->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals and format display area
    $totalPanels = count($allPanels);
    foreach ($allPanels as $key => $panel) {
        // Calculate area in square meters if 'area' column is missing/null
        $area_sqm = (!empty($panel['area']) && is_numeric($panel['area']) && $panel['area'] > 0)
            ? (float)$panel['area']
            : (($panel['length_m'] ?? 0) * ($panel['width_m'] ?? 0));
        $grandTotalArea += $area_sqm;
        $allPanels[$key]['display_area_sqm'] = $area_sqm; // Store for display
    }
} catch (PDOException $e) {
    error_log("Database error fetching packing list data: " . $e->getMessage());
    if ($show_settings_form) {
        // If already showing settings, display error there
        $settings_save_error = "خطا در بارگذاری داده‌های لیست بارگیری.";
    } else {
        // Otherwise, show error on print page itself
        die("خطا در بارگذاری داده‌های لیست بارگیری: " . $e->getMessage());
    }
}


// --- Render Functions (Adapted for Packing List) ---
function renderHeader($settings, $static_truck_info, $static_shipment_info)
{
    if (!empty($settings['custom_header_html'])) {
        return $settings['custom_header_html'];
    } else {
        // Use default header structure, pulling truck/shipment info
        $header = '<table class="header-table">';
        $header .= '<tr>';
        $showLogos = $settings['show_logos'] ?? true;

        // Left Logo (Project Logo)
        if ($showLogos) $header .= '<td class="logo-cell logo-left"><img src="/assets/images/alumglass-farsi-logo-H40.png" alt="Logo"></td>';
        else $header .= '<td style="width: 20%;"></td>'; // Placeholder

        // Center Text (Combine general header text with truck/shipment info)
        $headerText = $settings['print_header'] ?? ''; // General text from settings
        $header .= '<td class="header-center">';
        $header .= nl2br(htmlspecialchars($headerText));
        // Add Factory Address from settings
        $factoryAddress = $settings['factory_address'] ?? '';
        if (!empty($factoryAddress)) {
            $header .= '<div class="factory-address-print">' . htmlspecialchars($factoryAddress) . '</div>';
        }
        $header .= '</td>';

        // Right Logo (Company Logo)
        if ($showLogos) $header .= '<td class="logo-cell logo-right"><img src="/assets/images/hotelfereshteh1.png" alt="Logo"></td>';
        else $header .= '<td style="width: 20%;"></td>'; // Placeholder

        $header .= '</tr></table>';

        // Add Info Section below logos/text
        $header .= '<table class="info-section-layout-table avoid-break">'; // Use a new class
        $header .= '<tr>';
        // Left Cell (Column 1 - Packing/Truck Info)
        $header .= '<td class="info-layout-cell">';
        $header .= '<table class="table table-sm table-bordered info-sub-table">'; // The actual info table
        $header .= '<tr><th width="40%">شماره پکینگ لیست:</th><td>' . htmlspecialchars($static_shipment_info['packing_list_number'] ?? 'N/A') . '</td></tr>';
        $header .= '<tr><th>شماره کامیون:</th><td>' . htmlspecialchars($static_truck_info['truck_number'] ?? 'N/A') . '</td></tr>';
        $header .= '<tr><th>نام راننده:</th><td>' . htmlspecialchars($static_truck_info['driver_name'] ?? 'نامشخص') . '</td></tr>';
        $header .= '<tr><th>شماره تماس راننده:</th><td>' . htmlspecialchars($static_truck_info['driver_phone'] ?? 'نامشخص') . '</td></tr>';
        $header .= '</table>'; // End info-sub-table 1
        $header .= '</td>'; // End Left Cell
        // Right Cell (Column 2 - Date/Destination Info)
        $header .= '<td class="info-layout-cell">';
        $header .= '<table class="table table-sm table-bordered info-sub-table">'; // Sub-table 2
        $shipping_date_shamsi = !empty($static_shipment_info['shipping_date']) ? jdate('Y/m/d', strtotime($static_shipment_info['shipping_date'])) : 'نامشخص';
        $shipping_time_formatted = !empty($static_shipment_info['shipping_time']) ? date('H:i', strtotime($static_shipment_info['shipping_time'])) : 'نامشخص';

        // Date Row 
        $header .= '<tr><th width="40%">تاریخ ارسال:</th><td>' . $shipping_date_shamsi . '</td></tr>';
        // Time Row 
        $header .= '<tr><th>زمان ارسال:</th><td>' . $shipping_time_formatted . '</td></tr>';
        $header .= '<tr>';
        $header .= '<th>مقصد:</th>'; // Label cell (takes default width)
        // Data cell spans both columns, text aligned right (or center if preferred)
        $header .= '<td colspan="1" style="text-align: right; padding-right: 5px;">' . htmlspecialchars($static_truck_info['destination'] ?? 'نامشخص') . '</td>';
        $header .= '</tr>';
        $stands_sent_display = isset($static_shipment_info['stands_sent']) ? (int)$static_shipment_info['stands_sent'] : 0;
        $header .= '<tr><th>خرک‌های ارسالی:</th><td>' . $stands_sent_display . ' عدد</td></tr>';
        $header .= '</table>'; // End sub-table 2
        $header .= '</td>'; // End Right Cell
        $header .= '</tr>';
        $header .= '</table>'; // End info-section-layout-table

        // The rest of the function (return $header) remains the same.
        return $header;
    }
}

function renderFooter($settings, $static_truck_info, $static_shipment_info)
{
    global $default_print_settings; // Make defaults available if needed
    if (!empty($settings['custom_footer_html'])) {
        return $settings['custom_footer_html'];
    } else {
        $footerText = $settings['print_footer'] ?? '';
        $footer = '<div class="footer-text">' . nl2br(htmlspecialchars($footerText)) . '</div>';
        $signatureArea = $settings['print_signature_area'] ?? '';
        if (!empty($signatureArea)) {
            $footer .= '<table class="signature-table">';
            // Construct the header row from the signature area setting
            $headerRow = '<tr>';
            preg_match_all('/<td[^>]*>(.*?)<\/td>/i', $signatureArea, $matches);
            $numCols = 0;
            if (!empty($matches[1])) {
                $numCols = count($matches[1]);
                foreach ($matches[1] as $title) {
                    $headerRow .= '<th>' . htmlspecialchars(trim(strip_tags($title))) . '</th>';
                }
            }
            $headerRow .= '</tr>';
            $footer .= '<thead>' . $headerRow . '</thead>';

            // Add dynamic info row + signature box row
            $footer .= '<tbody>';
            $footer .= '<tr class="signature-info-row">'; // Row for names/dates
            // Column 1: Preparer (Use preparer name from settings if available, else default, else static user info)
            $preparer_display_name = $settings['footer_preparer_name'] ?? $default_print_settings['footer_preparer_name'];
            // Option: Fallback to logged-in user if setting is empty but static info exists
            if (empty(trim($preparer_display_name)) && !empty($static_shipment_info) && !empty(trim(($static_shipment_info['first_name'] ?? '') . ($static_shipment_info['last_name'] ?? '')))) {
                $preparer_display_name = htmlspecialchars(trim(($static_shipment_info['first_name'] ?? '') . ' ' . ($static_shipment_info['last_name'] ?? '')));
            } else {
                $preparer_display_name = htmlspecialchars($preparer_display_name); // Use setting/default
            }
            $footer .= '<td style="direction: rtl; text-align: right;"><p>نام: ' . $preparer_display_name . '</p><p>تاریخ: ' . jdate('Y/m/d') . '</p></td>';

            // Column 2: Driver
            $driver_name = htmlspecialchars($static_truck_info['driver_name'] ?? '');
            $footer .= '<td style="direction: rtl; text-align: right;"><p>نام: ' . $driver_name . '</p><p>تاریخ:</p></td>';

            // Column 3: Receiver (Use receiver name from settings, else default)
            $receiver_display_name = htmlspecialchars($settings['footer_receiver_name'] ?? $default_print_settings['footer_receiver_name']);
            $footer .= '<td style="direction: rtl; text-align: right;"><p>نام: ' . $receiver_display_name . '</p><p>تاریخ:</p></td>';
            // Add empty TDs if signature setting had more columns
            if ($numCols > 3) $footer .= str_repeat('<td><p>نام:</p><p>تاریخ:</p></td>', $numCols - 3);
            $footer .= '</tr>';
            $footer .= '<tr class="signature-box-row">'; // Row for signature boxes
            if ($numCols > 0) $footer .= str_repeat('<td><div class="signature-box"></div></td>', $numCols);
            $footer .= '</tr>';
            $footer .= '</tbody>';

            $footer .= '</table>';
        }
        return $footer;
    }
}


// Function to render table headers for Packing List
function renderPackingListTableHeaders()
{
    $sortable_columns = [
        'row_num' => 'ردیف', // Sort by original index initially
        'Proritization' => 'زون',
        'stand_id' => 'شماره خرک',
        'address' => 'آدرس',
        'type' => 'نوع',
        'length_m' => 'طول (متر)',
        'width_m' => 'عرض (متر)',
        'display_area_sqm' => 'مساحت (متر مربع)',
        // 'description' => 'توضیحات' // Description might not be ideal for sorting
    ];

    $thead = '<thead><tr>';
    $all_headers = ['ردیف', 'زون', 'شماره خرک', 'آدرس', 'نوع', 'طول (متر)', 'عرض (متر)', 'مساحت (متر مربع)', 'توضیحات'];
    $col_keys = ['row_num', 'Proritization', 'stand_id', 'address', 'type', 'length_m', 'width_m', 'display_area_sqm', 'description']; // Match keys

    foreach ($all_headers as $index => $header_text) {
        $key = $col_keys[$index];
        if (isset($sortable_columns[$key])) {
            $thead .= '<th class="sortable" data-column="' . htmlspecialchars($key) . '">'
                . htmlspecialchars($header_text)
                . ' <i class="bi bi-arrow-down-up sort-icon"></i></th>'; // Add sort icon placeholder
        } else {
            $thead .= '<th>' . htmlspecialchars($header_text) . '</th>'; // Not sortable
        }
    }
    $thead .= '</tr></thead>';
    return $thead;
}

$pageTitle = "چاپ لیست بارگیری - کامیون " . htmlspecialchars($truck['truck_number'] ?? '');

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <?php if (!$show_settings_form): // --- PRINT VIEW STYLES --- 
    ?>
        <style>
            /* --- Base Print Styles & Fonts --- */
            @font-face {
                font-family: 'Vazir';
                /* Using Vazir like concrete tests */
                src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
                /* Adjust path */
                font-weight: normal;
                font-style: normal;
            }

            @font-face {
                font-family: 'Vazir';
                src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
                /* Adjust path */
                font-weight: bold;
                font-style: normal;
            }

            * {
                box-sizing: border-box;
            }

            body {
                font-family: Vazir, Tahoma, sans-serif;
                direction: rtl;
                line-height: 1.3;
                /* Adjust line height for density */
                margin: 0;
                padding: 0;
                background-color: #fff;
                color: #000;
            }

            table {
                border-collapse: collapse;
                width: 100%;
            }

            th,
            td {
                border: 0.5pt solid #555;
                padding: 1mm 0.8mm;
                text-align: center;
                vertical-align: middle;
            }

            /* Reduced padding */
            th.sortable {
                cursor: pointer;
                position: relative;
                /* Needed for absolute icon positioning */
            }

            th.sortable:hover {
                background-color: #ddd;
                /* Slight hover effect */
            }

            .sort-icon {
                font-size: 0.8em;
                margin-right: 5px;
                /* Space icon from text */
                opacity: 0.5;
                transition: opacity 0.2s ease;
                /* Position icon (adjust as needed for your layout) */
                /* position: absolute;
            left: 5px;
            top: 50%;
            transform: translateY(-50%); */
            }

            th.sort-asc .sort-icon,
            th.sort-desc .sort-icon {
                opacity: 1;
            }

            th.sort-asc .sort-icon::before {
                content: "\f160";
                /* Bootstrap Icons: arrow-up */
            }

            th.sort-desc .sort-icon::before {
                content: "\f13e";
                /* Bootstrap Icons: arrow-down */
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
                /* Packing List Column Widths */
                --col-width-row-num: <?php echo htmlspecialchars($user_print_settings['col_width_row_num']); ?>;
                --col-width-zone: <?php echo htmlspecialchars($user_print_settings['col_width_zone']); ?>;
                --col-width-stand-num: <?php echo htmlspecialchars($user_print_settings['col_width_stand_num']); ?>;
                --col-width-address: <?php echo htmlspecialchars($user_print_settings['col_width_address']); ?>;
                --col-width-type: <?php echo htmlspecialchars($user_print_settings['col_width_type']); ?>;
                --col-width-length: <?php echo htmlspecialchars($user_print_settings['col_width_length']); ?>;
                --col-width-width: <?php echo htmlspecialchars($user_print_settings['col_width_width']); ?>;
                --col-width-area: <?php echo htmlspecialchars($user_print_settings['col_width_area']); ?>;
                --col-width-desc: <?php echo htmlspecialchars($user_print_settings['col_width_desc']); ?>;
            }

            /* --- Apply Variables --- */
            .print-page {
                width: 100%;
                position: relative;
                page-break-after: always;
                padding: 10mm;
                /* Use padding instead of @page margin for consistency */
                box-sizing: border-box;
                min-height: 297mm;
                /* Force A4 height */
            }

            .print-page:last-child {
                page-break-after: avoid;
            }

            .header {
                width: 100%;
                margin-bottom: 5mm;
                border-bottom: 1pt solid #ccc;
                padding-bottom: 3mm;
            }

            .footer {
                width: 100%;
                margin-top: 5mm;
                border-top: 1pt solid #ccc;
                padding-top: 3mm;
            }

            .header-table {
                width: 100%;
                border: none;
                margin-bottom: 0;
            }

            .header-table td {
                border: none;
                vertical-align: middle;
                padding: 1mm;
            }

            .logo-cell {
                width: 18%;
            }

            /* Adjust logo cell width */
            .logo-left {
                text-align: right;
            }

            .logo-right {
                text-align: left;
            }

            .header-center {
                width: 64%;
                text-align: center;
                line-height: 1.2;
                white-space: pre-wrap;
                font-size: var(--header-font-size);
                color: var(--header-font-color);
                font-weight: bold;
            }

            .header img {
                max-height: 45px;
                max-width: 100%;
            }

            /* Slightly smaller max logo height */
            .factory-address-print {
                font-size: 7pt;
                color: #444;
                margin-top: 1mm;
            }

            /* Style for factory address */

            /* Info Section Styles */
            .info-section {
                margin-top: 3mm;
                width: 100%;
            }


            /* Gutter padding */
            .info-sub-table {
                width: 100%;
                border: 0.5pt solid #aaa;
                font-size: 7pt;
            }

            .info-sub-table th,
            .info-sub-table td {
                border: 0.5pt solid #bbb;
                padding: 0.8mm;
            }

            .info-sub-table th {
                background-color: #f5f5f5;
                font-weight: bold;
                white-space: nowrap;
            }

            .footer-text {
                text-align: center;
                margin-bottom: 2mm;
                white-space: pre-wrap;
                font-size: var(--footer-font-size);
                color: var(--footer-font-color);
            }

            .signature-table {
                margin-top: 3mm;
                width: 100%;
                margin-bottom: 0;
            }

            .signature-table th {
                font-weight: bold;
                background-color: #f8f8f8;
                padding: 1mm;
                border: 0.5pt solid #ccc;
                font-size: var(--footer-font-size);
                color: var(--footer-font-color);
            }

            .signature-table td {
                vertical-align: top;
                padding: 0.5mm;
                border: 0.5pt solid #ccc;
            }

            /* Top align text in signature cells */
            .signature-table td p {
                font-size: calc(var(--footer-font-size) - 1pt);
                margin: 0 0 1mm 0;
            }

            /* Smaller font for name/date */
            .signature-info-row td {
                height: auto;
            }

            /* Let content define height */
            .signature-box-row td {
                height: 10mm;
            }

            /* Row just for signature boxes */
            .signature-box {
                border: none;
                height: 100%;
            }

            /* Make box fill cell height */

            .data-table {
                width: 100%;
                border: 0.5pt solid #333;
                margin-bottom: 1rem;
                table-layout: fixed;
            }

            .data-table th {
                padding: 1mm;
                border: 0.5pt solid #333;
                text-align: center;
                vertical-align: middle;
                font-size: var(--thead-font-size);
                color: var(--thead-font-color);
                background-color: var(--thead-bg-color);
                font-weight: bold;
            }

            .data-table td {
                padding: 0.8mm 0.5mm;
                border: 0.5pt solid #333;
                text-align: center;
                vertical-align: middle;
                font-size: var(--tbody-font-size);
                color: var(--tbody-font-color);
                height: var(--row-min-height);
                word-wrap: break-word;
            }

            /* Apply Packing List Column Widths */
            .data-table th:nth-child(1),
            .data-table td:nth-child(1) {
                width: var(--col-width-row-num);
            }

            /* Rownum */
            .data-table th:nth-child(2),
            .data-table td:nth-child(2) {
                width: var(--col-width-zone);
            }

            /* Zone */
            .data-table th:nth-child(3),
            .data-table td:nth-child(3) {
                width: var(--col-width-stand-num);
            }

            /* NEW: Stand Num */
            .data-table th:nth-child(4),
            .data-table td:nth-child(4) {
                width: var(--col-width-address);
                text-align: right;
            }

            /* Address */
            .data-table th:nth-child(5),
            .data-table td:nth-child(5) {
                width: var(--col-width-type);
            }

            /* Type */
            .data-table th:nth-child(6),
            .data-table td:nth-child(6) {
                width: var(--col-width-length);
            }

            /* Length */
            .data-table th:nth-child(7),
            .data-table td:nth-child(7) {
                width: var(--col-width-width);
            }

            /* Width */
            .data-table th:nth-child(8),
            .data-table td:nth-child(8) {
                width: var(--col-width-area);
            }

            /* Area */
            .data-table th:nth-child(9),
            .data-table td:nth-child(9) {
                width: var(--col-width-desc);
                text-align: right;
            }

            /* Desc */


            /* Right align */

            /* Footer for Panel Table (Totals) */
            .data-table tfoot th,
            .data-table tfoot td {
                background-color: var(--thead-bg-color);
                font-weight: bold;
                font-size: var(--thead-font-size);
                color: var(--thead-font-color);
            }

            .data-table tfoot th[colspan="7"] {
                text-align: left !important;
                padding-left: 3mm !important;
            }

            .grand-total-row th,
            .grand-total-row td {
                border-top: 1pt solid #666 !important;
            }

            /* Adjust alignment/padding */


            .page-number {
                text-align: center;
                margin-top: 2mm;
                font-size: var(--footer-font-size);
                color: var(--footer-font-color);
            }

            .info-section-layout-table {
                width: 100%;
                /* Span the container width */
                border: none;
                /* No outer border */
                border-collapse: collapse;
                margin-top: 4mm;
                /* Space below main header */
                margin-bottom: 4mm;
                /* Space before main data table */
                page-break-inside: avoid;
            }

            .info-section-layout-table td.info-layout-cell {
                width: 50%;
                /* Each column takes half the width */
                border: none;
                /* No borders on the layout cells */
                padding: 0 1.5mm;
                /* Add padding BETWEEN the two info tables */
                vertical-align: top;
                /* Align tables to the top */
            }

            /* Adjust padding for the first/last cell if needed to align with edges */
            .info-section-layout-table td.info-layout-cell:first-child {
                padding-right: 0;
                padding-left: 1.5mm;
            }

            .info-section-layout-table td.info-layout-cell:last-child {
                padding-left: 0;
                padding-right: 1.5mm;
            }

            /* --- Ensure styles for the INNER info tables are still present --- */
            .info-sub-table {
                width: 100%;
                /* Take full width of the layout cell */
                border-collapse: collapse;
                margin-bottom: 0;
                font-size: 6pt;
                /* Keep small */
                border: 0.5pt solid #999;
                /* Border for the sub-table */
            }

            .info-sub-table th,
            .info-sub-table td {
                border: 0.5pt solid #aaa;
                /* Internal cell borders */
                padding: 0.4mm;
                /* Keep minimal padding */
                vertical-align: middle;
                /* Center vertically */
                text-align: right;
                word-break: break-word;
            }

            .info-sub-table th {
                background-color: #f0f0f0 !important;
                font-weight: bold;
                white-space: nowrap;
                /* Prevent header text wrapping */
                text-align: center;
                /* Center header text */
            }

            .info-sub-table td {
                text-align: center;
                /* Center data */
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

                /* Use CSS padding on .print-page instead */
                /* Prevent header/footer from being controlled by @page */
                .header,
                .footer {
                    position: static;
                    width: 100%;
                }

                thead {
                    display: table-header-group;
                }

                /* Repeat table header */
                tfoot {
                    display: table-footer-group;
                }

                /* Repeat table footer (if applicable) */
                tr {
                    page-break-inside: avoid;
                }

                /* Try to keep rows together */
                .print-page {
                    padding: 10mm;
                }

                /* Apply desired margin as padding */
                th.sortable {
                    cursor: default;
                }

                /* No pointer on actual print */
                .sort-icon {
                    display: none;
                }

                /* Hide icons on print */
            }
        </style>
        <script>
            // --- Pagination Javascript ---
            // --- Replace the existing pagination-only JS ---
            document.addEventListener('DOMContentLoaded', function() {
                const rowsPerPageSetting = <?php echo $user_print_settings['rows_per_page'] ?? 15; ?>;
                const tableElement = document.getElementById('data-table');
                const contentArea = document.getElementById('content-area');
                const grandTotalAreaPHP = <?php echo $grandTotalArea; ?>;
                const totalPanelsPHP = <?php echo $totalPanels; ?>;

                // --- Get Panel Data (Make sure this matches PHP json_encode) ---
                // Ensure panelData contains keys matching data-column attributes
                let panelData = <?php echo json_encode($allPanels); ?>;
                // Add original index for initial row number sorting if needed
                panelData = panelData.map((panel, index) => ({
                    ...panel,
                    original_index: index
                }));


                // --- Sorting State ---
                let currentSortColumn = null;
                let currentSortDirection = 'asc'; // 'asc' or 'desc'

                // --- Essential Elements Check ---
                if (!tableElement || !contentArea || !panelData || panelData.length === 0) {
                    console.log('Sorting/Pagination: Essential elements or data missing.');
                    // Still show footer totals if table exists
                    const initialTfoot = tableElement?.querySelector('tfoot');
                    if (initialTfoot) {
                        const pageTotalAreaEl = initialTfoot.querySelector('#page-total-area');
                        const pageTotalCountEl = initialTfoot.querySelector('#page-total-count');
                        const grandTotalRowEl = initialTfoot.querySelector('.grand-total-row');
                        if (pageTotalAreaEl) pageTotalAreaEl.textContent = grandTotalAreaPHP.toFixed(2); // Grand total if only one page
                        if (pageTotalCountEl) pageTotalCountEl.textContent = totalPanelsPHP;
                        if (grandTotalRowEl) grandTotalRowEl.style.display = '';
                    }
                    const pageNumberDiv = contentArea?.querySelector('.page-number');
                    if (pageNumberDiv) pageNumberDiv.textContent = 'صفحه 1 از 1';
                    // Hide original table template if it exists
                    const originalTable = document.getElementById('data-table');
                    if (originalTable) originalTable.style.display = 'none';
                    return; // Exit if nothing to sort/paginate
                }

                const originalTheadHTML = tableElement.querySelector('thead')?.outerHTML || ''; // Keep thead HTML
                const originalTfootHTML = tableElement.querySelector('tfoot')?.outerHTML || ''; // Keep tfoot HTML

                // --- Render Functions (from PHP, needed in JS) ---
                const renderedHeaderHtml = <?php echo json_encode(renderHeader($user_print_settings, $truck ?? [], $shipment ?? [])); ?>;
                const renderedFooterHtml = <?php echo json_encode(renderFooter($user_print_settings, $truck ?? [], $shipment ?? [])); ?>;

                // --- Sorting Function ---
                function sortData(column) {
                    console.log(`Sorting by: ${column}`);
                    if (currentSortColumn === column) {
                        currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
                    } else {
                        currentSortDirection = 'asc';
                    }
                    currentSortColumn = column;

                    panelData.sort((a, b) => {
                        let valA = a[column];
                        let valB = b[column];

                        // --- Type Handling for Comparison ---
                        // Handle potential null/undefined explicitly
                        const aIsNull = valA === null || valA === undefined || valA === '';
                        const bIsNull = valB === null || valB === undefined || valB === '';

                        if (aIsNull && bIsNull) return 0;
                        if (aIsNull) return currentSortDirection === 'asc' ? -1 : 1; // Nulls first in asc
                        if (bIsNull) return currentSortDirection === 'asc' ? 1 : -1; // Nulls first in asc

                        // Numeric columns
                        if (['stand_id', 'length_m', 'width_m', 'display_area_sqm',  'original_index'].includes(column)) {
                            valA = parseFloat(valA) || 0; // Default to 0 if NaN
                            valB = parseFloat(valB) || 0;
                            return currentSortDirection === 'asc' ? valA - valB : valB - valA;
                        }
                        // String columns (using localeCompare for better sorting)
                        else if (typeof valA === 'string' && typeof valB === 'string') {
                            // Handle potentially numeric strings naturally
                            const comparison = valA.localeCompare(valB, 'fa', {
                                numeric: true,
                                sensitivity: 'base'
                            });
                            return currentSortDirection === 'asc' ? comparison : -comparison;
                        }
                        // Fallback for other types (less likely with current data)
                        else {
                            const comparison = (valA < valB) ? -1 : (valA > valB) ? 1 : 0;
                            return currentSortDirection === 'asc' ? comparison : -comparison;
                        }
                    });

                    updateSortIcons();
                    paginateAndRender(); // Re-paginate and render after sorting
                }

                // --- Update Sort Icons ---
                function updateSortIcons() {
                    document.querySelectorAll('th.sortable').forEach(th => {
                        th.classList.remove('sort-asc', 'sort-desc');
                        const icon = th.querySelector('.sort-icon');
                        if (icon) icon.className = 'bi bi-arrow-down-up sort-icon'; // Reset icon

                        if (th.dataset.column === currentSortColumn) {
                            th.classList.add(currentSortDirection === 'asc' ? 'sort-asc' : 'sort-desc');
                            if (icon) icon.className = `bi ${currentSortDirection === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down'} sort-icon`;
                        }
                    });
                }

                // --- Rebuild Table Body for a Page ---
                function buildTableBody(pageData) {
                    const tbody = document.createElement('tbody');
                    pageData.forEach((panel, indexOnPage) => {
                        const row = tbody.insertRow();
                        // --- DYNAMIC ROW NUMBER ---
                        const overallIndex = panel.original_index; // Or calculate based on full sorted array if needed
                        // For simplicity, using index relative to the full sorted list
                        const displayRowNumber = panelData.findIndex(p => p.original_index === overallIndex) + 1;

                        row.insertCell().textContent = displayRowNumber; // Use calculated row number
                        row.insertCell().textContent = panel['Proritization'] ?? '-';
                        row.insertCell().textContent = panel['stand_id'] ?? '-';
                        const addressCell = row.insertCell(); // Address (right-aligned via CSS)
                        addressCell.textContent = panel['address'] ?? '';
                        row.insertCell().textContent = panel['type'] ?? 'نامشخص';
                        row.insertCell().textContent = (panel['length_m'] !== null && !isNaN(panel['length_m'])) ? parseFloat(panel['length_m']).toFixed(2) : '0.00';
                        row.insertCell().textContent = (panel['width_m'] !== null && !isNaN(panel['width_m'])) ? parseFloat(panel['width_m']).toFixed(2) : '0.00';
                        row.insertCell().textContent = (panel['display_area_sqm'] !== null && !isNaN(panel['display_area_sqm'])) ? parseFloat(panel['display_area_sqm']).toFixed(2) : '0.00';
                        const descCell = row.insertCell(); // Description (right-aligned via CSS)
                        descCell.textContent = panel['description'] ?? '';
                    });
                    return tbody;
                }

                // --- Paginate and Render Function ---
                function paginateAndRender() {
                    console.log(`Pagination: Starting for ${panelData.length} rows, ${rowsPerPageSetting} per page.`);
                    contentArea.innerHTML = ''; // Clear container before rendering pages
                    let pageNum = 1;
                    const totalPages = Math.ceil(panelData.length / rowsPerPageSetting);

                    for (let i = 0; i < panelData.length; i += rowsPerPageSetting) {
                        const pageData = panelData.slice(i, i + rowsPerPageSetting);
                        console.log(`Pagination: Starting page ${pageNum} with ${pageData.length} rows`);

                        const currentPageDiv = document.createElement('div');
                        currentPageDiv.className = 'print-page';
                        if (pageNum > 1) currentPageDiv.classList.add('page-break');

                        // Add Header
                        const headerDiv = document.createElement('div');
                        headerDiv.className = 'header';
                        headerDiv.innerHTML = renderedHeaderHtml;
                        currentPageDiv.appendChild(headerDiv);

                        // Add Table Structure
                        const pageTable = document.createElement('table');
                        pageTable.className = 'data-table';
                        if (originalTheadHTML) pageTable.innerHTML = originalTheadHTML; // Add Headers

                        // --- BUILD TABLE BODY FOR THIS PAGE ---
                        const currentPageTableBody = buildTableBody(pageData);
                        pageTable.appendChild(currentPageTableBody);

                        // --- Add Footer and Calculate Page Totals ---
                        if (originalTfootHTML) {
                            const tfoot = document.createElement('tfoot');
                            tfoot.innerHTML = originalTfootHTML; // Clone template footer

                            let currentPageAreaSum = 0;
                            pageData.forEach(panel => {
                                currentPageAreaSum += parseFloat(panel.display_area_sqm || 0);
                            });

                            const pageAreaEl = tfoot.querySelector('#page-total-area');
                            const pageCountEl = tfoot.querySelector('#page-total-count');
                            if (pageAreaEl) pageAreaEl.textContent = currentPageAreaSum.toFixed(2);
                            if (pageCountEl) pageCountEl.textContent = pageData.length;

                            // Show grand total ONLY on the last page
                            const grandTotalRow = tfoot.querySelector('.grand-total-row');
                            if (grandTotalRow) {
                                grandTotalRow.style.display = (pageNum === totalPages) ? '' : 'none';
                            }
                            pageTable.appendChild(tfoot);
                        }
                        currentPageDiv.appendChild(pageTable);

                        // --- Add Notes (ONLY on last page) ---
                        if (pageNum === totalPages) {
                            const notesTemplate = document.querySelector('.notes-card-template');
                            if (notesTemplate) {
                                const notesClone = notesTemplate.firstElementChild.cloneNode(true);
                                currentPageDiv.appendChild(notesClone); // Append notes card
                            }
                        }


                        // Add Footer Structure
                        const footerDiv = document.createElement('div');
                        footerDiv.className = 'footer';
                        footerDiv.innerHTML = renderedFooterHtml; // Add signatures etc.
                        const pageNumberDiv = document.createElement('div');
                        pageNumberDiv.className = 'page-number';
                        pageNumberDiv.textContent = `صفحه ${pageNum} از ${totalPages}`; // Add page number
                        footerDiv.appendChild(pageNumberDiv);
                        currentPageDiv.appendChild(footerDiv);


                        contentArea.appendChild(currentPageDiv);
                        pageNum++;
                    }

                    // Add click listeners to the newly rendered headers
                    addSortListeners();

                    // Hide the original template table
                    const originalTable = document.getElementById('data-table');
                    if (originalTable) originalTable.style.display = 'none';

                    console.log(`Pagination: Finished creating ${totalPages} pages.`);
                }

                // --- Add Sort Listeners ---
                function addSortListeners() {
                    document.querySelectorAll('th.sortable').forEach(th => {
                        // Remove existing listener before adding new one to prevent duplicates
                        th.removeEventListener('click', handleSortClick);
                        th.addEventListener('click', handleSortClick);
                    });
                }

                function handleSortClick(event) {
                    const column = event.currentTarget.dataset.column;
                    if (column) {
                        sortData(column);
                    }
                }


                // --- Initial Render ---
                paginateAndRender();

            }); // End DOMContentLoaded
        </script>

    <?php else: // --- SETTINGS FORM VIEW --- 
    ?>
        <style>
            /* Paste the Settings Form CSS from concrete_tests_print.php here */
            body {
                font-family: Vazir, Tahoma, sans-serif;
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
                direction: ltr;
                /* LTR for values like hex, pt, % */
                text-align: left;
                box-sizing: border-box;
                font-size: 0.9em;
            }

            textarea {
                direction: rtl;
                text-align: right;
            }

            /* RTL for textareas */
            input[type="color"] {
                padding: 2px 5px;
                height: 38px;
                cursor: pointer;
            }

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
        <div class="container">
            <h1>تنظیمات چاپ لیست بارگیری</h1>
            <?php if ($settings_save_error): ?>
                <div class="error-message"><?php echo htmlspecialchars($settings_save_error); ?></div>
            <?php endif; ?>

            <form method="post" action="packing-list.php?<?php echo http_build_query($_GET); /* Keep truck_id etc */ ?>">
                <input type="hidden" name="save_print_settings" value="1">

                <fieldset class="form-section">
                    <legend>محتوای کلی</legend>
                    <div class="form-group">
                        <label for="factory_address_input">آدرس کارخانه (برای سربرگ):</label>
                        <input type="text" name="factory_address" id="factory_address_input" value="<?php echo htmlspecialchars($user_print_settings['factory_address']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="print_header_input">متن اصلی سربرگ:</label>
                        <textarea name="print_header" id="print_header_input"><?php echo htmlspecialchars($user_print_settings['print_header']); ?></textarea>
                        <div class="preview"><?php echo nl2br(htmlspecialchars($user_print_settings['print_header'])); ?></div>
                    </div>
                    <div class="form-group">
                        <label for="print_footer_input">پاورقی:</label>
                        <textarea name="print_footer" id="print_footer_input"><?php echo htmlspecialchars($user_print_settings['print_footer']); ?></textarea>
                        <div class="preview"><?php echo nl2br(htmlspecialchars($user_print_settings['print_footer'])); ?></div>
                    </div>
                    <div class="form-group">
                        <label for="print_signature_area_input">عناوین جدول امضاها (HTML):</label>
                        <textarea name="print_signature_area" id="print_signature_area_input" placeholder="مثال: <td>تهیه کننده</td><td>راننده</td>"><?php echo htmlspecialchars($user_print_settings['print_signature_area']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="footer_preparer_name_input">نام تهیه کننده (پاورقی):</label>
                        <input type="text" name="footer_preparer_name" id="footer_preparer_name_input" value="<?php echo htmlspecialchars($user_print_settings['footer_preparer_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="footer_receiver_name_input">نام تحویل گیرنده سایت (پاورقی):</label>
                        <input type="text" name="footer_receiver_name" id="footer_receiver_name_input" value="<?php echo htmlspecialchars($user_print_settings['footer_receiver_name']); ?>">
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="show_logos_input" name="show_logos" <?php echo $user_print_settings['show_logos'] ? 'checked' : ''; ?> value="1">
                        <label for="show_logos_input">نمایش لوگوها</label>
                    </div>

                </fieldset>

                <fieldset class="form-section">
                    <legend>استایل‌ها</legend>
                    <!-- Header Font -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="header_font_size">اندازه فونت سربرگ:</label>
                            <input type="text" name="header_font_size" id="header_font_size" value="<?php echo htmlspecialchars($user_print_settings['header_font_size']); ?>" placeholder="e.g., 11pt">
                        </div>
                        <div class="form-group">
                            <label for="header_font_color">رنگ فونت سربرگ:</label>
                            <input type="color" name="header_font_color" id="header_font_color" value="<?php echo htmlspecialchars($user_print_settings['header_font_color']); ?>">
                        </div>
                    </div>
                    <!-- Footer Font -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="footer_font_size">اندازه فونت پاورقی:</label>
                            <input type="text" name="footer_font_size" id="footer_font_size" value="<?php echo htmlspecialchars($user_print_settings['footer_font_size']); ?>" placeholder="e.g., 8pt">
                        </div>
                        <div class="form-group">
                            <label for="footer_font_color">رنگ فونت پاورقی:</label>
                            <input type="color" name="footer_font_color" id="footer_font_color" value="<?php echo htmlspecialchars($user_print_settings['footer_font_color']); ?>">
                        </div>
                    </div>
                    <hr style="margin: 15px 0; border-top: 1px dashed #eee;">
                    <!-- Table Head Font/Color -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="thead_font_size">اندازه فونت هدر جدول:</label>
                            <input type="text" name="thead_font_size" id="thead_font_size" value="<?php echo htmlspecialchars($user_print_settings['thead_font_size']); ?>" placeholder="e.g., 8pt">
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
                    <!-- Table Body Font/Color/Height -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="tbody_font_size">اندازه فونت بدنه جدول:</label>
                            <input type="text" name="tbody_font_size" id="tbody_font_size" value="<?php echo htmlspecialchars($user_print_settings['tbody_font_size']); ?>" placeholder="e.g., 7pt">
                        </div>
                        <div class="form-group">
                            <label for="tbody_font_color">رنگ فونت بدنه جدول:</label>
                            <input type="color" name="tbody_font_color" id="tbody_font_color" value="<?php echo htmlspecialchars($user_print_settings['tbody_font_color']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="row_min_height">حداقل ارتفاع ردیف:</label>
                            <input type="text" name="row_min_height" id="row_min_height" value="<?php echo htmlspecialchars($user_print_settings['row_min_height']); ?>" placeholder="e.g., 25px or auto">
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>عرض ستون‌های جدول پنل</legend>
                    <small>مقادیر را با % یا px یا mm وارد کنید...</small>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="col_width_row_num">ردیف:</label>
                            <input type="text" name="col_width_row_num" id="col_width_row_num" value="<?php echo htmlspecialchars($user_print_settings['col_width_row_num']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="col_width_zone">زون:</label>
                            <input type="text" name="col_width_zone" id="col_width_zone" value="<?php echo htmlspecialchars($user_print_settings['col_width_zone']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="col_width_stand_num">شماره خرک:</label>
                            <input type="text" name="col_width_stand_num" id="col_width_stand_num" value="<?php echo htmlspecialchars($user_print_settings['col_width_stand_num']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="col_width_address">آدرس:</label>
                            <input type="text" name="col_width_address" id="col_width_address" value="<?php echo htmlspecialchars($user_print_settings['col_width_address']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="col_width_type">نوع:</label>
                            <input type="text" name="col_width_type" id="col_width_type" value="<?php echo htmlspecialchars($user_print_settings['col_width_type']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="col_width_length">طول (متر):</label>
                            <input type="text" name="col_width_length" id="col_width_length" value="<?php echo htmlspecialchars($user_print_settings['col_width_length']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="col_width_width">عرض (متر):</label>
                            <input type="text" name="col_width_width" id="col_width_width" value="<?php echo htmlspecialchars($user_print_settings['col_width_width']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="col_width_area">مساحت (م.م.):</label>
                            <input type="text" name="col_width_area" id="col_width_area" value="<?php echo htmlspecialchars($user_print_settings['col_width_area']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="col_width_desc">توضیحات:</label>
                            <input type="text" name="col_width_desc" id="col_width_desc" value="<?php echo htmlspecialchars($user_print_settings['col_width_desc']); ?>">
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>صفحه‌بندی و سفارشی‌سازی پیشرفته</legend>
                    <div class="form-group">
                        <label for="rows_per_page_input">تعداد ردیف پنل در هر صفحه:</label>
                        <input type="number" id="rows_per_page_input" name="rows_per_page" min="5" max="50" value="<?php echo $user_print_settings['rows_per_page']; ?>">
                    </div>
                    <div class="form-group">
                        <label for="custom_header_html_input">HTML سفارشی سربرگ (اختیاری):</label>
                        <textarea name="custom_header_html" id="custom_header_html_input" placeholder="کد کامل HTML برای سربرگ خود را اینجا وارد کنید..."><?php echo htmlspecialchars($user_print_settings['custom_header_html']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="custom_footer_html_input">HTML سفارشی پاورقی (اختیاری):</label>
                        <textarea name="custom_footer_html" id="custom_footer_html_input" placeholder="کد کامل HTML برای پاورقی خود را اینجا وارد کنید..."><?php echo htmlspecialchars($user_print_settings['custom_footer_html']); ?></textarea>
                    </div>
                </fieldset>


                <div class="buttons">
                    <button type="submit">ذخیره تنظیمات و نمایش چاپ</button>
                    <?php // Create Cancel URL (removes 'settings' param)
                    $cancel_params = $_GET;
                    unset($cancel_params['settings']);
                    $cancel_url = strtok($_SERVER["REQUEST_URI"], '?') . (!empty($cancel_params) ? '?' . http_build_query($cancel_params) : ''); ?>
                    <a href="<?php echo htmlspecialchars($cancel_url); ?>"><button type="button" class="btn-secondary">انصراف</button></a>
                    <?php // Create Return URL (goes back to truck assignment)
                    $return_url = 'truck-assignment.php'; ?>
                    <a href="<?php echo htmlspecialchars($return_url); ?>"><button type="button" class="btn-secondary">بازگشت به تخصیص</button></a>
                </div>
            </form>
        </div>

    <?php endif; // End Settings Form View 
    ?>

    <?php if (!$show_settings_form): // --- PRINT VIEW BODY --- 
    ?>
        <div id="content-area">
            <!-- Initial structure JS will paginate -->
            <div class="print-page"> <!-- This outer wrapper might be removed by JS -->
                <div class="header">
                    <?php echo renderHeader($user_print_settings, $truck ?? [], $shipment ?? []); ?>
                </div>
                <!-- Removed static title div, now part of renderHeader -->
                <table id="data-table" class="data-table">
                    <?php echo renderPackingListTableHeaders(); ?>
                    <tbody>
                        <?php if (empty($allPanels)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 15px; color: #666;">هیچ پنلی به این کامیون تخصیص داده نشده است.</td>
                            </tr>
                            <?php else:
                            $rowNumber = 1; // Use overall row number for display
                            foreach ($allPanels as $panel): ?>
                                <tr>
                                    <td><?= $rowNumber++ ?></td>
                                    <td><?= htmlspecialchars($panel['Proritization'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($panel['stand_id'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($panel['address'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($panel['type'] ?? 'نامشخص') ?></td>
                                    <td><?= number_format($panel['length_m'] ?? 0, 2) ?></td>
                                    <td><?= number_format($panel['width_m'] ?? 0, 2) ?></td>
                                    <td><?= number_format($panel['display_area_sqm'] ?? 0, 2) ?></td>
                                    <td><?= htmlspecialchars($panel['description'] ?? '') ?></td>
                                </tr>
                        <?php endforeach; // End loop through all panels
                        endif; // End if empty allPanels
                        ?>
                    </tbody>
                    <?php // Display Table Footer with Totals (Will be cloned by JS) 
                    ?>
                    <tfoot>
                        <tr class="page-total-row"> <!-- Row for per-page totals -->
                            <th colspan="7" style="text-align:left !important;">جمع کل (صفحه):</th>
                            <th id="page-total-area">0.00</th> <!-- Placeholder ID -->
                            <th id="page-total-count">0</th> <!-- Placeholder ID -->
                        </tr>
                        <tr class="grand-total-row" style="display: none;"> <!-- Hidden Grand Total Row -->
                            <th colspan="7" style="text-align:left !important;">جمع کل (نهایی):</th>
                            <th><?php echo number_format($grandTotalArea, 2); ?> م.م.</th> <!-- PHP Grand Total -->
                            <th><?php echo $totalPanels; ?> پنل</th> <!-- PHP Grand Total -->
                        </tr>
                    </tfoot>
                </table>
                <?php // Add Notes section here if needed (JS needs to handle placing it on last page) 
                ?>
                <?php if ($shipment && !empty($shipment['notes'])): ?>
                    <div class="notes-card-template" style="display: none;"> <!-- Template for JS -->
                        <div class="card notes-card">
                            <div class="card-header">
                                <h5>یادداشت‌ها</h5>
                            </div>
                            <div class="card-body">
                                <p><?= nl2br(htmlspecialchars($shipment['notes'])) ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="footer">
                    <?php echo renderFooter($user_print_settings, $truck ?? [], $shipment ?? []); ?>
                    <div class="page-number"></div> <!-- Placeholder -->
                </div>
            </div><!-- End initial .print-page -->
        </div><!-- End #content-area -->

        <!-- Print Controls (No Print) -->
        <div class="no-print" style="position: fixed; bottom: 10px; left: 10px; background: rgba(255,255,255,0.8); padding: 10px; border-radius: 5px; box-shadow: 0 0 5px rgba(0,0,0,0.2); z-index: 100; display: flex; gap: 10px;">
            <button onclick="window.print();" style="padding: 8px 15px; background-color: #198754; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;"> <i class="bi bi-printer"></i> چاپ </button>
            <?php // Settings URL
            $settings_params = $_GET;
            $settings_params['settings'] = '1';
            $settings_url = strtok($_SERVER["REQUEST_URI"], '?') . '?' . http_build_query($settings_params); ?>
            <a href="<?php echo htmlspecialchars($settings_url); ?>" style="padding: 8px 15px; background-color: #0d6efd; color: white; text-decoration: none; border-radius: 4px; font-size: 12px;"> <i class="bi bi-gear"></i> تنظیمات </a>
            <?php // Return URL
            $return_url = 'truck-assignment.php'; // Always return to assignment page 
            ?>
            <a href="<?php echo htmlspecialchars($return_url); ?>" style="padding: 8px 15px; background-color: #6c757d; color: white; text-decoration: none; border-radius: 4px; font-size: 12px;"> <i class="bi bi-arrow-left-circle"></i> بازگشت </a>
        </div>
    <?php endif; // End Print View 
    ?>

    </body>

</html>
<?php ob_end_flush(); ?>