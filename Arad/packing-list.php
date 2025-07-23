<?php
// packing-list.php - Refactored Version

// --- Basic Setup, Dependencies, Session Auth ---
ini_set('display_errors', 1); // Keep for debugging during development
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../sercon/bootstrap.php'; // Adjust path if needed
require_once __DIR__ . '/includes/jdf.php';      // Ensure jdf functions are available
require_once __DIR__ . '/includes/functions.php'; // For secureSession

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


if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

$current_user_id = $_SESSION['user_id']; // Get current user ID

// DB Connection (Read-only needed)
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/packing_list.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// Authentication Check (Adapt roles as needed)
$allowed_roles = ['admin', 'superuser', 'supervisor', 'planner', 'user', 'receiver'];
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Location: /login'); // Redirect to your login page
    exit('Access Denied.');
}
$report_key = 'packing_list'; // Unique key for these settings

// --- PHP Helper Functions ---

/**
 * NEW: Translates specific database values into Persian for display.
 * @param string $type The category of value to translate (e.g., 'zone', 'type').
 * @param string $value The value from the database.
 * @return string The translated Persian string or the original value if not found.
 */
function translateValue($type, $value)
{
    // You can expand these arrays with more translations as needed
    $translations = [
        'zone' => [
            'zone 1' => 'زون ۱',
            'zone 2' => 'زون ۲',
            'zone 3' => 'زون ۳',
            'zone 4' => 'زون ۴',
            'zone 5' => 'زون ۵',
            'zone 6' => 'زون ۶',
            'zone 7' => 'زون ۷',
            'zone 8' => 'زون ۸',
            'zone 9' => 'زون ۹',
            'zone 10' => 'زون ۱۰',
            'zone 11' => 'زون ۱۱',
            'zone 12' => 'زون ۱۲',
            'zone 13' => 'زون ۱۳',
            'zone 14' => 'زون ۱۴',
            'zone 15' => 'زون ۱۵',
            'zone 16' => 'زون ۱۶',
            'zone 17' => 'زون ۱۷',
            'zone 18' => 'زون ۱۸',
            'zone 19' => 'زون ۱۹',
            'zone 20' => 'زون ۲۰',
            'zone 21' => 'زون ۲۱',
            'zone 22' => 'زون ۲۲',
            'zone 23' => 'زون ۲۳',
            'zone 24' => 'زون ۲۴',
            'zone 25' => 'زون ۲۵',
            'zone 26' => 'زون ۲۶',
            'zone 27' => 'زون ۲۷',
            'zone 28' => 'زون ۲۸',
            'zone 29' => 'زون ۲۹',
            'zone 30' => 'زون ۳۰',
        ],
        'type' => [
            'terrace edge' => 'لبه تراس',
            'wall panel'   => 'پنل دیواری', // Example
            // Add other panel types here
        ]
    ];

    return $translations[$type][$value] ?? $value; // Return original value if no translation found
}


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

    // ---> Column Widths (Now 10 columns) <---
    'col_width_row_num' => '3%',
    'col_width_zone' => '5%',
    'col_width_floor' => '5%', // NEW: Floor column
    'col_width_stand_num' => '4%',
    'col_width_address' => '24%', // Mapped from full_address_identifier
    'col_width_type' => '8%',
    'col_width_length' => '7%',
    'col_width_width' => '7%',
    'col_width_area' => '8%',
    'col_width_desc' => '29%',
    // -----------------------------------------> Total = 100%

    // NEW: Column Visibility Settings
    'visible_columns' => json_encode([
        'row_num',
        'zone',
        'floor',
        'stand_num',
        'full_address_identifier',
        'type',
        'length_m',
        'width_m',
        'area',
        'desc'
    ]),

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
        $user_print_settings = array_merge($default_print_settings, $db_settings_filtered);
        $user_print_settings['show_logos'] = isset($user_print_settings['show_logos']) ? (bool)$user_print_settings['show_logos'] : true;
        $user_print_settings['rows_per_page'] = isset($user_print_settings['rows_per_page']) ? max(5, (int)$user_print_settings['rows_per_page']) : $default_print_settings['rows_per_page'];
    }
} catch (PDOException $e) {
    error_log("Error loading print settings for user {$current_user_id}, report {$report_key}: " . $e->getMessage());
}

// NEW: Decode visible columns from settings, fallback to default if invalid
$visible_columns = json_decode($user_print_settings['visible_columns'], true);
if (!is_array($visible_columns)) {
    $visible_columns = json_decode($default_print_settings['visible_columns'], true);
}


// --- Process Settings Form Submission ---
$show_settings_form = isset($_GET['settings']) && $_GET['settings'] == '1';
$settings_saved_success = false;
$settings_save_error = null;

if (isset($_POST['save_print_settings'])) {
    // NEW: Handle visible columns submission
    $submitted_visible_columns = $_POST['visible_columns'] ?? [];
    // Ensure it's an array of strings
    $visible_columns_json = json_encode(array_values($submitted_visible_columns));

    $new_settings_data = [
        'user_id' => $current_user_id,
        'report_key' => $report_key,
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
        // Column widths
        'col_width_row_num' => sanitize_css_value($_POST['col_width_row_num'] ?? '', $default_print_settings['col_width_row_num']),
        'col_width_zone' => sanitize_css_value($_POST['col_width_zone'] ?? '', $default_print_settings['col_width_zone']),
        'col_width_floor' => sanitize_css_value($_POST['col_width_floor'] ?? '', $default_print_settings['col_width_floor']), // NEW
        'col_width_stand_num' => sanitize_css_value($_POST['col_width_stand_num'] ?? '', $default_print_settings['col_width_stand_num']),
        'col_width_address' => sanitize_css_value($_POST['col_width_address'] ?? '', $default_print_settings['col_width_address']),
        'col_width_type' => sanitize_css_value($_POST['col_width_type'] ?? '', $default_print_settings['col_width_type']),
        'col_width_length' => sanitize_css_value($_POST['col_width_length'] ?? '', $default_print_settings['col_width_length']),
        'col_width_width' => sanitize_css_value($_POST['col_width_width'] ?? '', $default_print_settings['col_width_width']),
        'col_width_area' => sanitize_css_value($_POST['col_width_area'] ?? '', $default_print_settings['col_width_area']),
        'col_width_desc' => sanitize_css_value($_POST['col_width_desc'] ?? '', $default_print_settings['col_width_desc']),
        // NEW: Visible columns
        'visible_columns' => $visible_columns_json,
        // Other fields
        'factory_address' => trim($_POST['factory_address'] ?? $default_print_settings['factory_address']),
        'footer_preparer_name' => trim($_POST['footer_preparer_name'] ?? $default_print_settings['footer_preparer_name']),
        'footer_receiver_name' => trim($_POST['footer_receiver_name'] ?? $default_print_settings['footer_receiver_name'])
    ];


    // Prepare SQL for INSERT/UPDATE
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
        $executed = $stmt->execute($new_settings_data);
        if (!$executed) {
            $errorInfo = $stmt->errorInfo();
            $errorMessage = $errorInfo[2] ?? 'Unknown PDO error';
            error_log("PDO Execute failed saving packing list settings for user {$current_user_id}: " . print_r($errorInfo, true));
            $settings_save_error = "خطا در اجرای دستور پایگاه داده: " . htmlspecialchars($errorMessage);
            $show_settings_form = true;
        } else {
            // Redirect to the same page without 'settings=1' and without POST data
            $redirect_params = $_GET;
            unset($redirect_params['settings']); // Remove settings flag
            $redirect_url = strtok($_SERVER["REQUEST_URI"], '?') . (!empty($redirect_params) ? '?' . http_build_query($redirect_params) : '');
            header('Location: ' . $redirect_url);
            exit();
        }
    } catch (PDOException $e) {
        error_log("PDOException saving packing list settings for user $current_user_id: " . $e->getMessage());
        $settings_save_error = "خطا در ذخیره تنظیمات (PDOException).";
        $show_settings_form = true;
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
    $stmt_shipment = $pdo->prepare("SELECT s.*, u.first_name, u.last_name FROM shipments s LEFT JOIN alumglas_hpc_common.users u ON s.created_by = u.id WHERE s.truck_id = ? ORDER BY s.id DESC LIMIT 1");
    $stmt_shipment->execute([$truckId]);
    $shipment = $stmt_shipment->fetch(PDO::FETCH_ASSOC);

    // Get Panels - UPDATED QUERY
    $stmt_panels = $pdo->prepare("
        SELECT
            p.*,
            p.full_address_identifier, -- UPDATED: Use full_address_identifier
            p.floor,                   -- NEW: Get floor data
            p.length / 1000 as length_m,
            p.width / 1000 as width_m,
            psa.stand_id
        FROM
            hpc_panels p
        LEFT JOIN
            panel_stand_assignments psa ON p.id = psa.panel_id AND psa.truck_id = :truck_id_psa
        WHERE
            p.truck_id = :truck_id_p
        ORDER BY
            p.id
    ");
    $stmt_panels->bindParam(':truck_id_psa', $truckId, PDO::PARAM_INT);
    $stmt_panels->bindParam(':truck_id_p', $truckId, PDO::PARAM_INT);
    $stmt_panels->execute();
    $allPanels = $stmt_panels->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals, format display area, and apply translations
    $totalPanels = count($allPanels);
    foreach ($allPanels as $key => $panel) {
        // Calculate area in square meters
        $area_sqm = (!empty($panel['area']) && is_numeric($panel['area']) && $panel['area'] > 0)
            ? (float)$panel['area']
            : (($panel['length_m'] ?? 0) * ($panel['width_m'] ?? 0));
        $grandTotalArea += $area_sqm;
        $allPanels[$key]['display_area_sqm'] = $area_sqm;

        // NEW: Add translated values for display
        $allPanels[$key]['zone_display'] = translateValue('zone', $panel['Proritization']);
        $allPanels[$key]['type_display'] = translateValue('type', $panel['type']);
    }
} catch (PDOException $e) {
    error_log("Database error fetching packing list data: " . $e->getMessage());
    if ($show_settings_form) {
        $settings_save_error = "خطا در بارگذاری داده‌های لیست بارگیری.";
    } else {
        die("خطا در بارگذاری داده‌های لیست بارگیری: " . $e->getMessage());
    }
}


// --- Render Functions ---
function renderHeader($settings, $static_truck_info, $static_shipment_info)
{
    if (!empty($settings['custom_header_html'])) {
        return $settings['custom_header_html'];
    }
    // ... (The rest of the renderHeader function is largely unchanged)
    $header = '<table class="header-table">';
    $header .= '<tr>';
    $showLogos = $settings['show_logos'] ?? true;
    if ($showLogos) $header .= '<td class="logo-cell logo-left"><img src="/assets/images/alumglass-farsi-logo-H40.png" alt="Logo"></td>';
    else $header .= '<td class="logo-cell logo-left"><img src="/assets/images/alumglass-farsi-logo-H40.png" alt="Logo"></td>';
    $headerText = $settings['print_header'] ?? '';
    $header .= '<td class="header-center">';
    $header .= nl2br(htmlspecialchars($headerText));
    $factoryAddress = $settings['factory_address'] ?? '';
    if (!empty($factoryAddress)) {
        $header .= '<div class="factory-address-print">' . htmlspecialchars($factoryAddress) . '</div>';
    }
    $header .= '</td>';
    if ($showLogos) $header .= '<td class="logo-cell logo-right"><img src="/assets/images/arad.png" alt="Logo"></td>';
    else $header .= '<td class="logo-cell logo-right"><img src="/assets/images/arad.png" alt="Logo"></td>';
    $header .= '</tr></table>';
    $header .= '<table class="info-section-layout-table avoid-break">';
    $header .= '<tr>';
    $header .= '<td class="info-layout-cell">';
    $header .= '<table class="table table-sm table-bordered info-sub-table">';
    $header .= '<tr><th width="40%">شماره پکینگ لیست:</th><td>' . htmlspecialchars($static_shipment_info['packing_list_number'] ?? 'N/A') . '</td></tr>';
    $header .= '<tr><th>شماره کامیون:</th><td>' . htmlspecialchars($static_truck_info['truck_number'] ?? 'N/A') . '</td></tr>';
    $header .= '<tr><th>نام راننده:</th><td>' . htmlspecialchars($static_truck_info['driver_name'] ?? 'نامشخص') . '</td></tr>';
    $header .= '<tr><th>شماره تماس راننده:</th><td>' . htmlspecialchars($static_truck_info['driver_phone'] ?? 'نامشخص') . '</td></tr>';
    $header .= '</table>';
    $header .= '</td>';
    $header .= '<td class="info-layout-cell">';
    $header .= '<table class="table table-sm table-bordered info-sub-table">';
    $shipping_date_shamsi = !empty($static_shipment_info['shipping_date']) ? jdate('Y/m/d', strtotime($static_shipment_info['shipping_date'])) : 'نامشخص';
    $shipping_time_formatted = !empty($static_shipment_info['shipping_time']) ? date('H:i', strtotime($static_shipment_info['shipping_time'])) : 'نامشخص';
    $header .= '<tr><th width="40%">تاریخ ارسال:</th><td>' . $shipping_date_shamsi . '</td></tr>';
    $header .= '<tr><th>زمان ارسال:</th><td>' . $shipping_time_formatted . '</td></tr>';
    $header .= '<tr>';
    $header .= '<th>مقصد:</th>';
    $header .= '<td colspan="1" style="text-align: right; padding-right: 5px;">' . htmlspecialchars($static_truck_info['destination'] ?? 'نامشخص') . '</td>';
    $header .= '</tr>';
    $stands_sent_display = isset($static_shipment_info['stands_sent']) ? (int)$static_shipment_info['stands_sent'] : 0;
    $header .= '<tr><th>خرک‌های ارسالی:</th><td>' . $stands_sent_display . ' عدد</td></tr>';
    $header .= '</table>';
    $header .= '</td>';
    $header .= '</tr>';
    $header .= '</table>';
    return $header;
}
function renderFooter1($settings, $static_truck_info, $static_shipment_info)
{
    // A simple, hardcoded HTML footer as requested.
    $footer_html = '
    <table class="signature-table" style="width: 100%; border-collapse: collapse; margin-top: 10px;">
        <thead>
            <tr>
                <th style="border: 1px solid #ccc; padding: 5px; background-color: #f2f2f2;">تهیه کننده</th>
                <th style="border: 1px solid #ccc; padding: 5px; background-color: #f2f2f2;">راننده کامیون</th>
                <th style="border: 1px solid #ccc; padding: 5px; background-color: #f2f2f2;">تحویل گیرنده (سایت)</th>
            </tr>
        </thead>
        <tbody>
            <tr class="signature-info-row">
                <td style="border: 1px solid #ccc; padding: 5px; text-align: right; vertical-align: top;">
                    <p style="margin: 0; font-size: 9pt;">نام:</p>
                    <p style="margin: 5px 0 0 0; font-size: 9pt;">تاریخ:</p>
                </td>
                <td style="border: 1px solid #ccc; padding: 5px; text-align: right; vertical-align: top;">
                    <p style="margin: 0; font-size: 9pt;">نام:</p>
                    <p style="margin: 5px 0 0 0; font-size: 9pt;">تاریخ:</p>
                </td>
                <td style="border: 1px solid #ccc; padding: 5px; text-align: right; vertical-align: top;">
                    <p style="margin: 0; font-size: 9pt;">نام:</p>
                    <p style="margin: 5px 0 0 0; font-size: 9pt;">تاریخ:</p>
                </td>
            </tr>
            <tr class="signature-box-row">
                <td style="border: 1px solid #ccc; height: 50px;"></td>
                <td style="border: 1px solid #ccc; height: 50px;"></td>
                <td style="border: 1px solid #ccc; height: 50px;"></td>
            </tr>
        </tbody>
    </table>';

    return $footer_html;
}
function renderFooter($settings, $static_truck_info, $static_shipment_info)
{
    global $default_print_settings;
    if (!empty($settings['custom_footer_html'])) return $settings['custom_footer_html'];
    $footerText = $settings['print_footer'] ?? '';
    $footer = '<div class="footer-text">' . nl2br(htmlspecialchars($footerText)) . '</div>';
    $signatureArea = $settings['print_signature_area'] ?? '';
    if (!empty($signatureArea)) {
        $footer .= '<table class="signature-table">';
        preg_match_all('/<td[^>]*>(.*?)<\/td>/i', $signatureArea, $matches);
        $numCols = !empty($matches[1]) ? count($matches[1]) : 0;
        $headerRow = '<tr>';
        if ($numCols > 0) foreach ($matches[1] as $title) $headerRow .= '<th>' . htmlspecialchars(trim(strip_tags($title))) . '</th>';
        $headerRow .= '</tr>';
        $footer .= '<thead>' . $headerRow . '</thead>';
        $footer .= '<tbody><tr class="signature-info-row">';
        $preparer_name = $settings['footer_preparer_name'] ?? $default_print_settings['footer_preparer_name'];
        $receiver_name = $settings['footer_receiver_name'] ?? $default_print_settings['footer_receiver_name'];
        $driver_name = $static_truck_info['driver_name'] ?? '';
        $footer .= '<td style="direction: rtl; text-align: right;"><p>نام: ' . htmlspecialchars($preparer_name) . '</p><p>تاریخ: ' . jdate('Y/m/d') . '</p></td>';
        $footer .= '<td style="direction: rtl; text-align: right;"><p>نام: ' . htmlspecialchars($driver_name) . '</p><p>تاریخ:</p></td>';
        $footer .= '<td style="direction: rtl; text-align: right;"><p>نام: ' . htmlspecialchars($receiver_name) . '</p><p>تاریخ:</p></td>';
        if ($numCols > 3) $footer .= str_repeat('<td><p>نام:</p><p>تاریخ:</p></td>', $numCols - 3);
        $footer .= '</tr><tr class="signature-box-row">';
        if ($numCols > 0) $footer .= str_repeat('<td><div class="signature-box"></div></td>', $numCols);
        $footer .= '</tr></tbody></table>';
    }
    return $footer;
}

/**
 * NEW/REFACTORED: Renders table headers based on visibility settings.
 * Applies widths directly to headers.
 * @param array $settings The user's print settings.
 * @param array $visible_columns An array of column keys that should be displayed.
 * @return string The generated HTML for the <thead>.
 */
function renderPackingListTableHeaders($settings, $visible_columns)
{
    // Master list of all possible columns and their properties
    $all_possible_columns = [
        'row_num' => ['label' => 'ردیف', 'sort_key' => 'original_index', 'width_key' => 'col_width_row_num'],
        'zone' => ['label' => 'زون', 'sort_key' => 'Proritization', 'width_key' => 'col_width_zone'],
        'floor' => ['label' => 'طبقه', 'sort_key' => 'floor', 'width_key' => 'col_width_floor'],
        'stand_num' => ['label' => 'شماره خرک', 'sort_key' => 'stand_id', 'width_key' => 'col_width_stand_num'],
        'full_address_identifier' => ['label' => 'آدرس', 'sort_key' => 'full_address_identifier', 'width_key' => 'col_width_address'], // Note the width key mapping
        'type' => ['label' => 'نوع', 'sort_key' => 'type', 'width_key' => 'col_width_type'],
        'length_m' => ['label' => 'طول (متر)', 'sort_key' => 'length_m', 'width_key' => 'col_width_length'],
        'width_m' => ['label' => 'عرض (متر)', 'sort_key' => 'width_m', 'width_key' => 'col_width_width'],
        'area' => ['label' => 'مساحت (م.م)', 'sort_key' => 'display_area_sqm', 'width_key' => 'col_width_area'],
        'desc' => ['label' => 'توضیحات', 'sort_key' => null, 'width_key' => 'col_width_desc']
    ];

    $thead = '<thead><tr>';

    foreach ($all_possible_columns as $key => $column_info) {
        // Only render the header if its key is in the visible_columns array
        if (in_array($key, $visible_columns)) {
            $width_key = $column_info['width_key'];
            $width_style = isset($settings[$width_key]) ? 'width: ' . htmlspecialchars($settings[$width_key]) . ';' : '';
            $style_attr = !empty($width_style) ? ' style="' . $width_style . '"' : '';

            if ($column_info['sort_key']) {
                $thead .= '<th class="sortable"' . $style_attr . ' data-column="' . htmlspecialchars($column_info['sort_key']) . '">'
                    . htmlspecialchars($column_info['label'])
                    . ' <i class="bi bi-arrow-down-up sort-icon"></i></th>';
            } else {
                $thead .= '<th' . $style_attr . '>' . htmlspecialchars($column_info['label']) . '</th>';
            }
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

    <?php if (!$show_settings_form) : // --- PRINT VIEW STYLES --- 
    ?>
        <style>
            /* --- Base Print Styles & Fonts --- */
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

            * {
                box-sizing: border-box;
            }

            body {
                font-family: Vazir, Tahoma, sans-serif;
                direction: rtl;
                line-height: 1.3;
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

            th.sortable {
                cursor: pointer;
                position: relative;
            }

            th.sortable:hover {
                background-color: #ddd;
            }

            .sort-icon {
                font-size: 0.8em;
                margin-right: 5px;
                opacity: 0.5;
                transition: opacity 0.2s ease;
            }

            th.sort-asc .sort-icon,
            th.sort-desc .sort-icon {
                opacity: 1;
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
            }

            /* --- Apply Variables & General Layout --- */
            .print-page {
                width: 100%;
                position: relative;
                page-break-after: always;
                padding: 10mm;
                box-sizing: border-box;
                min-height: 297mm;
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

            .factory-address-print {
                font-size: 7pt;
                color: #444;
                margin-top: 1mm;
            }

            .info-section-layout-table {
                width: 100%;
                border: none;
                border-collapse: collapse;
                margin-top: 4mm;
                margin-bottom: 4mm;
                page-break-inside: avoid;
            }

            .info-section-layout-table td.info-layout-cell {
                width: 50%;
                border: none;
                padding: 0 1.5mm;
                vertical-align: top;
            }

            .info-sub-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 0;
                font-size: 6pt;
                border: 0.5pt solid #999;
            }

            .info-sub-table th,
            .info-sub-table td {
                border: 0.5pt solid #aaa;
                padding: 0.4mm;
                vertical-align: middle;
                text-align: right;
                word-break: break-word;
            }

            .info-sub-table th {
                background-color: #f0f0f0 !important;
                font-weight: bold;
                white-space: nowrap;
                text-align: center;
            }

            .info-sub-table td {
                text-align: center;
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

            .signature-table td p {
                font-size: calc(var(--footer-font-size) - 1pt);
                margin: 0 0 1mm 0;
            }

            .signature-box-row td {
                height: 10mm;
            }

            /* --- Data Table --- */
            .data-table {
                width: 100%;
                border: 0.5pt solid #333;
                margin-bottom: 1rem;
                table-layout: fixed;
                /* IMPORTANT for dynamic columns */
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

            /* REMOVED: nth-child width selectors. Width is now applied inline from PHP. */

            /* Specific alignment for certain columns */
            .data-table td.align-right {
                text-align: right;
                padding-right: 2mm;
            }

            .data-table tfoot th,
            .data-table tfoot td {
                background-color: var(--thead-bg-color);
                font-weight: bold;
                font-size: var(--thead-font-size);
                color: var(--thead-font-color);
            }

            .data-table tfoot th[colspan] {
                text-align: left !important;
                padding-left: 3mm !important;
            }

            .grand-total-row th,
            .grand-total-row td {
                border-top: 1pt solid #666 !important;
            }

            .page-number {
                text-align: center;
                margin-top: 2mm;
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

                .header,
                .footer {
                    position: static;
                    width: 100%;
                }

                thead {
                    display: table-header-group;
                }

                tfoot {
                    display: table-footer-group;
                }

                tr {
                    page-break-inside: avoid;
                }

                .print-page {
                    padding: 10mm;
                }

                th.sortable {
                    cursor: default;
                }

                .sort-icon {
                    display: none;
                }
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const rowsPerPageSetting = <?php echo $user_print_settings['rows_per_page'] ?? 15; ?>;
                const tableElement = document.getElementById('data-table');
                const contentArea = document.getElementById('content-area');
                const grandTotalAreaPHP = <?php echo $grandTotalArea; ?>;
                const totalPanelsPHP = <?php echo $totalPanels; ?>;

                // NEW: Get visible columns from PHP
                const visibleColumns = <?php echo json_encode($visible_columns); ?>;

                let panelData = <?php echo json_encode($allPanels); ?>;
                panelData = panelData.map((panel, index) => ({
                    ...panel,
                    original_index: index
                }));


                let currentSortColumn = null;
                let currentSortDirection = 'asc';

                if (!tableElement || !contentArea || !panelData || panelData.length === 0) {
                    console.log('Sorting/Pagination: Essential elements or data missing.');
                    const initialTfoot = tableElement?.querySelector('tfoot');
                    if (initialTfoot) {
                        const pageTotalAreaEl = initialTfoot.querySelector('#page-total-area');
                        const pageTotalCountEl = initialTfoot.querySelector('#page-total-count');
                        const grandTotalRowEl = initialTfoot.querySelector('.grand-total-row');
                        if (pageTotalAreaEl) pageTotalAreaEl.textContent = grandTotalAreaPHP.toFixed(2);
                        if (pageTotalCountEl) pageTotalCountEl.textContent = totalPanelsPHP;
                        if (grandTotalRowEl) grandTotalRowEl.style.display = '';
                    }
                    const pageNumberDiv = contentArea?.querySelector('.page-number');
                    if (pageNumberDiv) pageNumberDiv.textContent = 'صفحه 1 از 1';
                    const originalTable = document.getElementById('data-table');
                    if (originalTable) originalTable.style.display = 'none';
                    return;
                }

                const originalTheadHTML = tableElement.querySelector('thead')?.outerHTML || '';
                const originalTfootHTML = tableElement.querySelector('tfoot')?.outerHTML || '';
                const renderedHeaderHtml = <?php echo json_encode(renderHeader($user_print_settings, $truck ?? [], $shipment ?? [])); ?>;
                const renderedFooterHtml = <?php echo json_encode(renderFooter($user_print_settings, $truck ?? [], $shipment ?? [])); ?>;

                function sortData(column) {
                    if (currentSortColumn === column) {
                        currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
                    } else {
                        currentSortDirection = 'asc';
                    }
                    currentSortColumn = column;

                    panelData.sort((a, b) => {
                        let valA = a[column];
                        let valB = b[column];
                        const aIsNull = valA === null || valA === undefined || valA === '';
                        const bIsNull = valB === null || valB === undefined || valB === '';

                        if (aIsNull && bIsNull) return 0;
                        if (aIsNull) return currentSortDirection === 'asc' ? -1 : 1;
                        if (bIsNull) return currentSortDirection === 'asc' ? 1 : -1;

                        if (['stand_id', 'floor', 'length_m', 'width_m', 'display_area_sqm', 'original_index'].includes(column)) {
                            valA = parseFloat(valA) || 0;
                            valB = parseFloat(valB) || 0;
                            return currentSortDirection === 'asc' ? valA - valB : valB - valA;
                        } else if (typeof valA === 'string' && typeof valB === 'string') {
                            const comparison = valA.localeCompare(valB, 'fa', {
                                numeric: true,
                                sensitivity: 'base'
                            });
                            return currentSortDirection === 'asc' ? comparison : -comparison;
                        } else {
                            const comparison = (valA < valB) ? -1 : (valA > valB) ? 1 : 0;
                            return currentSortDirection === 'asc' ? comparison : -comparison;
                        }
                    });

                    updateSortIcons();
                    paginateAndRender();
                }

                function updateSortIcons() {
                    document.querySelectorAll('th.sortable').forEach(th => {
                        th.classList.remove('sort-asc', 'sort-desc');
                        const icon = th.querySelector('.sort-icon');
                        if (icon) icon.className = 'bi bi-arrow-down-up sort-icon';

                        if (th.dataset.column === currentSortColumn) {
                            th.classList.add(currentSortDirection === 'asc' ? 'sort-asc' : 'sort-desc');
                            if (icon) icon.className = `bi ${currentSortDirection === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down'} sort-icon`;
                        }
                    });
                }

                // REFACTORED to handle dynamic columns
                function buildTableBody(pageData) {
                    const tbody = document.createElement('tbody');
                    pageData.forEach((panel) => {
                        const row = tbody.insertRow();
                        const displayRowNumber = panelData.findIndex(p => p.original_index === panel.original_index) + 1;

                        const columnData = {
                            row_num: displayRowNumber,
                            zone: panel['zone_display'] ?? '-',
                            floor: panel['floor'] ?? '-',
                            stand_num: panel['stand_id'] ?? '-',
                            full_address_identifier: panel['full_address_identifier'] ?? '',
                            type: panel['type_display'] ?? 'نامشخص',
                            length_m: (panel['length_m'] !== null && !isNaN(panel['length_m'])) ? parseFloat(panel['length_m']).toFixed(2) : '0.00',
                            width_m: (panel['width_m'] !== null && !isNaN(panel['width_m'])) ? parseFloat(panel['width_m']).toFixed(2) : '0.00',
                            area: (panel['display_area_sqm'] !== null && !isNaN(panel['display_area_sqm'])) ? parseFloat(panel['display_area_sqm']).toFixed(2) : '0.00',
                            desc: panel['description'] ?? ''
                        };

                        visibleColumns.forEach(key => {
                            const cell = row.insertCell();
                            cell.textContent = columnData[key];
                            if (key === 'full_address_identifier' || key === 'desc') {
                                cell.classList.add('align-right');
                            }
                        });
                    });
                    return tbody;
                }

                function paginateAndRender() {
                    contentArea.innerHTML = '';
                    let pageNum = 1;
                    const totalPages = Math.ceil(panelData.length / rowsPerPageSetting);

                    for (let i = 0; i < panelData.length; i += rowsPerPageSetting) {
                        const pageData = panelData.slice(i, i + rowsPerPageSetting);
                        const currentPageDiv = document.createElement('div');
                        currentPageDiv.className = 'print-page';
                        if (pageNum > 1) currentPageDiv.classList.add('page-break');

                        const headerDiv = document.createElement('div');
                        headerDiv.className = 'header';
                        headerDiv.innerHTML = renderedHeaderHtml;
                        currentPageDiv.appendChild(headerDiv);

                        const pageTable = document.createElement('table');
                        pageTable.className = 'data-table';
                        if (originalTheadHTML) pageTable.innerHTML = originalTheadHTML;

                        const currentPageTableBody = buildTableBody(pageData);
                        pageTable.appendChild(currentPageTableBody);

                        if (originalTfootHTML) {
                            const tfoot = document.createElement('tfoot');
                            tfoot.innerHTML = originalTfootHTML;

                            let currentPageAreaSum = pageData.reduce((sum, panel) => sum + parseFloat(panel.display_area_sqm || 0), 0);

                            const pageAreaEl = tfoot.querySelector('#page-total-area');
                            const pageCountEl = tfoot.querySelector('#page-total-count');
                            if (pageAreaEl) pageAreaEl.textContent = currentPageAreaSum.toFixed(2);
                            if (pageCountEl) pageCountEl.textContent = pageData.length;

                            // NEW: Dynamic colspan for totals
                            const visibleColumnCount = visibleColumns.length;
                            const totalLabelCell = tfoot.querySelector('.page-total-row th[colspan]');
                            const grandTotalLabelCell = tfoot.querySelector('.grand-total-row th[colspan]');
                            if (totalLabelCell) totalLabelCell.colSpan = Math.max(1, visibleColumnCount - 2);
                            if (grandTotalLabelCell) grandTotalLabelCell.colSpan = Math.max(1, visibleColumnCount - 2);


                            const grandTotalRow = tfoot.querySelector('.grand-total-row');
                            if (grandTotalRow) {
                                grandTotalRow.style.display = (pageNum === totalPages) ? '' : 'none';
                            }
                            pageTable.appendChild(tfoot);
                        }
                        currentPageDiv.appendChild(pageTable);

                        if (pageNum === totalPages) {
                            const notesTemplate = document.querySelector('.notes-card-template');
                            if (notesTemplate) {
                                const notesClone = notesTemplate.firstElementChild.cloneNode(true);
                                currentPageDiv.appendChild(notesClone);
                            }
                        }

                        const footerDiv = document.createElement('div');
                        footerDiv.className = 'footer';
                        footerDiv.innerHTML = renderedFooterHtml;
                        const pageNumberDiv = document.createElement('div');
                        pageNumberDiv.className = 'page-number';
                        pageNumberDiv.textContent = `صفحه ${pageNum} از ${totalPages}`;
                        footerDiv.appendChild(pageNumberDiv);
                        currentPageDiv.appendChild(footerDiv);

                        contentArea.appendChild(currentPageDiv);
                        pageNum++;
                    }

                    addSortListeners();
                    const originalTable = document.getElementById('data-table');
                    if (originalTable) originalTable.style.display = 'none';
                }

                function addSortListeners() {
                    document.querySelectorAll('th.sortable').forEach(th => {
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

                paginateAndRender();
            });
        </script>

    <?php else : // --- SETTINGS FORM VIEW --- 
    ?>
        <style>
            /* Basic styles for the settings form */
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
                box-sizing: border-box;
                font-size: 0.9em;
            }

            input[type="text"],
            input[type="number"] {
                direction: ltr;
                text-align: left;
            }

            textarea {
                direction: rtl;
                text-align: right;
                min-height: 100px;
            }

            .checkbox-group {
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .checkbox-group-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 10px;
            }

            .checkbox-group-grid label {
                font-weight: normal;
                display: flex;
                align-items: center;
                gap: 5px;
            }

            button {
                background: #0d6efd;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 5px;
                cursor: pointer;
                font-size: 1em;
            }

            .btn-secondary {
                background: #6c757d;
            }

            .buttons {
                margin-top: 30px;
                display: flex;
                gap: 10px;
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

            small {
                display: block;
                margin-top: 5px;
                color: #666;
                font-size: 0.85em;
            }
        </style>
        <div class="container">
            <h1>تنظیمات چاپ لیست بارگیری</h1>
            <?php if ($settings_save_error) : ?>
                <div class="error-message"><?php echo htmlspecialchars($settings_save_error); ?></div>
            <?php endif; ?>

            <form method="post" action="packing-list.php?<?php echo http_build_query($_GET); ?>">
                <input type="hidden" name="save_print_settings" value="1">

                <!-- NEW: Column Visibility Section -->
                <fieldset class="form-section">
                    <legend>انتخاب ستون‌های قابل نمایش</legend>
                    <div class="form-group checkbox-group-grid">
                        <?php
                        $all_columns_map = [
                            'row_num' => 'ردیف',
                            'zone' => 'زون',
                            'floor' => 'طبقه',
                            'stand_num' => 'شماره خرک',
                            'full_address_identifier' => 'آدرس',
                            'type' => 'نوع',
                            'length_m' => 'طول',
                            'width_m' => 'عرض',
                            'area' => 'مساحت',
                            'desc' => 'توضیحات'
                        ];
                        foreach ($all_columns_map as $key => $label) {
                            $checked = in_array($key, $visible_columns) ? 'checked' : '';
                            echo "<div><label><input type='checkbox' name='visible_columns[]' value='{$key}' {$checked}> " . htmlspecialchars($label) . "</label></div>";
                        }
                        ?>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>محتوای کلی</legend>
                    <div class="form-group">
                        <label for="factory_address_input">آدرس کارخانه (برای سربرگ):</label>
                        <input type="text" name="factory_address" id="factory_address_input" value="<?php echo htmlspecialchars($user_print_settings['factory_address']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="print_header_input">متن اصلی سربرگ:</label>
                        <textarea name="print_header" id="print_header_input"><?php echo htmlspecialchars($user_print_settings['print_header']); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="print_footer_input">پاورقی:</label>
                        <textarea name="print_footer" id="print_footer_input"><?php echo htmlspecialchars($user_print_settings['print_footer']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="print_signature_area_input">عناوین جدول امضاها (HTML):</label>
                        <textarea name="print_signature_area" id="print_signature_area_input" placeholder="مثال: <td>تهیه کننده</td><td>راننده</td>"><?php echo htmlspecialchars($user_print_settings['print_signature_area']); ?></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="footer_preparer_name_input">نام تهیه کننده:</label><input type="text" name="footer_preparer_name" id="footer_preparer_name_input" value="<?php echo htmlspecialchars($user_print_settings['footer_preparer_name']); ?>"></div>
                        <div class="form-group"><label for="footer_receiver_name_input">نام تحویل گیرنده:</label><input type="text" name="footer_receiver_name" id="footer_receiver_name_input" value="<?php echo htmlspecialchars($user_print_settings['footer_receiver_name']); ?>"></div>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="show_logos_input" name="show_logos" value="1" <?php echo $user_print_settings['show_logos'] ? 'checked' : ''; ?>>
                        <label for="show_logos_input">نمایش لوگوها</label>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>عرض ستون‌های جدول</legend>
                    <small>مقادیر را با % یا px یا mm وارد کنید. مجموع درصدها بهتر است 100% باشد.</small>
                    <div class="form-row">
                        <div class="form-group"><label for="col_width_row_num">ردیف:</label><input type="text" name="col_width_row_num" value="<?php echo htmlspecialchars($user_print_settings['col_width_row_num']); ?>"></div>
                        <div class="form-group"><label for="col_width_zone">زون:</label><input type="text" name="col_width_zone" value="<?php echo htmlspecialchars($user_print_settings['col_width_zone']); ?>"></div>
                        <div class="form-group"><label for="col_width_floor">طبقه:</label><input type="text" name="col_width_floor" value="<?php echo htmlspecialchars($user_print_settings['col_width_floor']); ?>"></div>
                        <div class="form-group"><label for="col_width_stand_num">شماره خرک:</label><input type="text" name="col_width_stand_num" value="<?php echo htmlspecialchars($user_print_settings['col_width_stand_num']); ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="col_width_address">آدرس:</label><input type="text" name="col_width_address" value="<?php echo htmlspecialchars($user_print_settings['col_width_address']); ?>"></div>
                        <div class="form-group"><label for="col_width_type">نوع:</label><input type="text" name="col_width_type" value="<?php echo htmlspecialchars($user_print_settings['col_width_type']); ?>"></div>
                        <div class="form-group"><label for="col_width_length">طول:</label><input type="text" name="col_width_length" value="<?php echo htmlspecialchars($user_print_settings['col_width_length']); ?>"></div>
                        <div class="form-group"><label for="col_width_width">عرض:</label><input type="text" name="col_width_width" value="<?php echo htmlspecialchars($user_print_settings['col_width_width']); ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="col_width_area">مساحت:</label><input type="text" name="col_width_area" value="<?php echo htmlspecialchars($user_print_settings['col_width_area']); ?>"></div>
                        <div class="form-group"><label for="col_width_desc">توضیحات:</label><input type="text" name="col_width_desc" value="<?php echo htmlspecialchars($user_print_settings['col_width_desc']); ?>"></div>
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
                            <label for="row_min_height">حداقل ارتفاع ردیف (Row Height):</label>
                            <input type="text" name="row_min_height" id="row_min_height" value="<?php echo htmlspecialchars($user_print_settings['row_min_height']); ?>" placeholder="e.g., 25px or auto">
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-section">
                    <legend>صفحه‌بندی و سفارشی‌سازی پیشرفته</legend>
                    <div class="form-group">
                        <label for="rows_per_page_input">تعداد ردیف پنل در هر صفحه (Rows Per Page):</label>
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
                <!-- Other settings fieldsets (styles, advanced) can be added here as before -->

                <div class="buttons">
                    <button type="submit">ذخیره تنظیمات و نمایش چاپ</button>
                    <?php
                    $cancel_params = $_GET;
                    unset($cancel_params['settings']);
                    $cancel_url = strtok($_SERVER["REQUEST_URI"], '?') . (!empty($cancel_params) ? '?' . http_build_query($cancel_params) : ''); ?>
                    <a href="<?php echo htmlspecialchars($cancel_url); ?>"><button type="button" class="btn-secondary">انصراف</button></a>
                </div>
            </form>
        </div>

    <?php endif; // End Settings Form View 
    ?>

    <?php if (!$show_settings_form) : // --- PRINT VIEW BODY --- 
    ?>
        <div id="content-area">
            <!-- This initial structure acts as a template for the JS pagination -->
            <div class="print-page">
                <div class="header">
                    <?php echo renderHeader($user_print_settings, $truck ?? [], $shipment ?? []); ?>
                </div>
                <table id="data-table" class="data-table">
                    <?php echo renderPackingListTableHeaders($user_print_settings, $visible_columns); ?>
                    <tbody>
                        <?php if (empty($allPanels)) : ?>
                            <tr>
                                <td colspan="<?php echo count($visible_columns); ?>" style="text-align: center; padding: 15px;">هیچ پنلی تخصیص داده نشده است.</td>
                            </tr>
                            <?php else :
                            $rowNumber = 1;
                            foreach ($allPanels as $panel) : ?>
                                <tr>
                                    <?php if (in_array('row_num', $visible_columns)) echo "<td>" . $rowNumber++ . "</td>"; ?>
                                    <?php if (in_array('zone', $visible_columns)) echo "<td>" . htmlspecialchars($panel['zone_display']) . "</td>"; ?>
                                    <?php if (in_array('floor', $visible_columns)) echo "<td>" . htmlspecialchars($panel['floor'] ?? '-') . "</td>"; ?>
                                    <?php if (in_array('stand_num', $visible_columns)) echo "<td>" . htmlspecialchars($panel['stand_id'] ?? '-') . "</td>"; ?>
                                    <?php if (in_array('full_address_identifier', $visible_columns)) echo "<td class='align-right'>" . htmlspecialchars($panel['full_address_identifier'] ?? '') . "</td>"; ?>
                                    <?php if (in_array('type', $visible_columns)) echo "<td>" . htmlspecialchars($panel['type_display']) . "</td>"; ?>
                                    <?php if (in_array('length_m', $visible_columns)) echo "<td>" . number_format($panel['length_m'] ?? 0, 2) . "</td>"; ?>
                                    <?php if (in_array('width_m', $visible_columns)) echo "<td>" . number_format($panel['width_m'] ?? 0, 2) . "</td>"; ?>
                                    <?php if (in_array('area', $visible_columns)) echo "<td>" . number_format($panel['display_area_sqm'] ?? 0, 2) . "</td>"; ?>
                                    <?php if (in_array('desc', $visible_columns)) echo "<td class='align-right'>" . htmlspecialchars($panel['description'] ?? '') . "</td>"; ?>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="page-total-row">
                            <th colspan="<?php echo max(1, count($visible_columns) - 2); ?>">جمع کل (صفحه):</th>
                            <th id="page-total-area">0.00</th>
                            <th id="page-total-count">0</th>
                        </tr>
                        <tr class="grand-total-row" style="display: none;">
                            <th colspan="<?php echo max(1, count($visible_columns) - 2); ?>">جمع کل (نهایی):</th>
                            <th><?php echo number_format($grandTotalArea, 2); ?> م.م.</th>
                            <th><?php echo $totalPanels; ?> پنل</th>
                        </tr>
                    </tfoot>
                </table>
                
                    <div class="notes-card-template" style="display: none;">
                        <div class="card notes-card">
                            <div class="card-header">
                                <h5>یادداشت‌ها</h5>
                            </div>
                            <div class="card-body">
                                <p><?= nl2br(htmlspecialchars($shipment['notes'])) ?></p>
                            </div>
                        </div>
                    </div>
                
                <div class="footer">
                    <?php echo renderFooter($user_print_settings, $truck ?? [], $shipment ?? []); ?>
                    <div class="page-number"></div>
                </div>
            </div>
        </div>

        <!-- Print Controls (No Print) -->
        <div class="no-print" style="position: fixed; bottom: 10px; left: 10px; background: rgba(255,255,255,0.8); padding: 10px; border-radius: 5px; box-shadow: 0 0 5px rgba(0,0,0,0.2); z-index: 100; display: flex; gap: 10px;">
            <button onclick="window.print();" style="padding: 8px 15px; background-color: #198754; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;"> <i class="bi bi-printer"></i> چاپ </button>
            <?php
            $settings_params = $_GET;
            $settings_params['settings'] = '1';
            $settings_url = strtok($_SERVER["REQUEST_URI"], '?') . '?' . http_build_query($settings_params); ?>
            <a href="<?php echo htmlspecialchars($settings_url); ?>" style="padding: 8px 15px; background-color: #0d6efd; color: white; text-decoration: none; border-radius: 4px; font-size: 12px;"> <i class="bi bi-gear"></i> تنظیمات </a>
            <a href="truck-assignment.php" style="padding: 8px 15px; background-color: #6c757d; color: white; text-decoration: none; border-radius: 4px; font-size: 12px;"> <i class="bi bi-arrow-left-circle"></i> بازگشت </a>
        </div>
    <?php endif; // End Print View 
    ?>

    </body>

</html>
<?php ob_end_flush(); ?>