<?php
// hpc_panels_print.php

// --- Basic Setup, Auth, DB, Helpers ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once __DIR__ . '/includes/jdf.php';
require_once __DIR__ . '/includes/functions.php'; // Needs secureSession, get_user_permissions, escapeHtml, log_activity, translate_status, get_status_color, formatJalaliDateOrEmpty, secure_file_upload
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
$current_user_id = $_SESSION['user_id'];
$report_key = 'hpc_panels';
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/hpc_panels_print.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}


// --- Mappings (Copy from manager page) ---
$status_map_persian = [
    'pending' => 'در انتظار',
    'polystyrene' => 'قالب فوم', // Corrected
    'Mesh' => 'مش بندی',          // Corrected
    'Concreting' => 'قالب‌بندی/بتن ریزی', // Corrected
    'Assembly' => 'فیس کوت',       // Corrected
    'completed' => 'تکمیل شده',
    // 'Formwork' was in your DB enum but not in your list, add if needed:
    // 'Formwork' => 'قالب بندی',
];
$packing_status_map_persian = [
    'pending' => 'در انتظار',
    'assigned' => 'تخصیص یافته',
    'shipped' => 'ارسال شده'
];
$polystyrene_map_persian = [
    0 => 'انجام نشده',
    1 => 'انجام شده'
];
$latest_activity_map_persian = [
    'assembly_done' => 'فیس کوت انجام شد',
    'concrete_done' => 'بتن ریزی انجام شد',
    'mesh_done' => 'مش بندی انجام شد',
    'pending' => 'شروع نشده / در انتظار',
];

// Add the list of columns needing Persian date formatting
$persian_date_columns = ['assigned_date', 'mesh_end_time', 'concrete_end_time', 'assembly_end_time', 'shipping_date']; // Added shipping_date

$available_columns = [
    'row_num' => 'شماره ردیف',           // NEW
    'address' => 'آدرس',
    'type' => 'نوع',
    'area' => 'مساحت (m²)',
    'width' => 'عرض (mm)',
    'length' => 'طول (mm)',
    'formwork_type' => 'نوع قالب',
    'Proritization' => 'اولویت',
    // 'status' => 'وضعیت', // REMOVED
    'latest_activity' => 'آخرین فعالیت', // NEW
    'assigned_date' => 'تاریخ تولید', // Changed label slightly to match manager?
    'polystyrene' => 'پلی استایرن',
    'mesh_end_time' => 'پایان مش',
    'concrete_end_time' => 'پایان بتن',
    'assembly_end_time' => 'پایان فیس کوت',
    'inventory_status' => 'موجودی/ارسال شده', // NEW
    'packing_status' => 'وضعیت بسته بندی',
    'shipping_date' => 'تاریخ ارسال', // NEW
];

// --- Load User Print Settings (Same as concrete_tests_print.php) ---
$default_print_settings = [
    'print_header' => "گزارش  وضعیت پنل‌ها\nشرکت آلومنیوم شیشه تهران",
    'print_footer' => "تاریخ چاپ: " . jdate('Y/m/d'),
    'print_signature_area' => "<td>مسئول آزمایشگاه</td><td>کنترل کیفیت</td><td>مدیر فنی</td>",
    'show_logos' => true,
    'custom_header_html' => '',
    'custom_footer_html' => '',
    'rows_per_page' => 12,

    // --- Defaults for style settings ---
    'header_font_size' => '14pt',
    'header_font_color' => '#000000',
    'footer_font_size' => '10pt',       // Default needed
    'footer_font_color' => '#333333',    // Default needed
    'thead_font_size' => '10pt',       // Default needed
    'thead_font_color' => '#000000',    // Default needed
    'thead_bg_color' => '#eeeeee',     // Default needed
    'tbody_font_size' => '10pt',       // Default needed
    'tbody_font_color' => '#000000',    // Default needed
    'row_min_height' => 'auto',        // Default needed

    // --- HPC Column Width Defaults ---
    'hpc_col_width_row_num' => '5%',
    'hpc_col_width_address' => '15%',
    'hpc_col_width_type' => '10%',
    'hpc_col_width_area' => '8%',
    'hpc_col_width_width' => '8%',
    'hpc_col_width_length' => '8%',
    'hpc_col_width_formwork_type' => '10%',
    'hpc_col_width_Proritization' => '8%',
    'hpc_col_width_assigned_date' => '10%',
    'hpc_col_width_polystyrene' => '8%',
    'hpc_col_width_mesh_end_time' => '10%',
    'hpc_col_width_concrete_end_time' => '10%',
    'hpc_col_width_assembly_end_time' => '10%',
    'hpc_col_width_latest_activity' => '12%',
    'hpc_col_width_inventory_status' => '10%',
    'hpc_col_width_packing_status' => '10%',
    'hpc_col_width_shipping_date' => '10%',
];


$user_print_settings = $default_print_settings; // Start with defaults
// Try to load user settings from DB
try {
    $stmt = $pdo->prepare("SELECT * FROM user_print_settings WHERE user_id = ? AND report_key = ?");
    $stmt->execute([$current_user_id, $report_key]);
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
// --- Handle SAVE settings form submission (Same as concrete_tests_print.php) ---
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
        'print_header' => trim($_POST['print_header'] ?? $default_print_settings['print_header']), // Use default if not set
        'print_footer' => trim($_POST['print_footer'] ?? $default_print_settings['print_footer']),
        'print_signature_area' => trim($_POST['print_signature_area'] ?? $default_print_settings['print_signature_area']),
        'show_logos' => isset($_POST['show_logos']) ? 1 : 0,
        'custom_header_html' => trim($_POST['custom_header_html'] ?? $default_print_settings['custom_header_html']),
        'custom_footer_html' => trim($_POST['custom_footer_html'] ?? $default_print_settings['custom_footer_html']),
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
        'hpc_col_width_address' => sanitize_css_value($_POST['hpc_col_width_address'] ?? '', $default_print_settings['hpc_col_width_address']),
        'hpc_col_width_type' => sanitize_css_value($_POST['hpc_col_width_type'] ?? '', $default_print_settings['hpc_col_width_type']),
        'hpc_col_width_area' => sanitize_css_value($_POST['hpc_col_width_area'] ?? '', $default_print_settings['hpc_col_width_area']),
        'hpc_col_width_width' => sanitize_css_value($_POST['hpc_col_width_width'] ?? '', $default_print_settings['hpc_col_width_width']),
        'hpc_col_width_length' => sanitize_css_value($_POST['hpc_col_width_length'] ?? '', $default_print_settings['hpc_col_width_length']),
        'hpc_col_width_formwork_type' => sanitize_css_value($_POST['hpc_col_width_formwork_type'] ?? '', $default_print_settings['hpc_col_width_formwork_type']),
        'hpc_col_width_Proritization' => sanitize_css_value($_POST['hpc_col_width_Proritization'] ?? '', $default_print_settings['hpc_col_width_Proritization']),
        'hpc_col_width_assigned_date' => sanitize_css_value($_POST['hpc_col_width_assigned_date'] ?? '', $default_print_settings['hpc_col_width_assigned_date']),
        'hpc_col_width_polystyrene' => sanitize_css_value($_POST['hpc_col_width_polystyrene'] ?? '', $default_print_settings['hpc_col_width_polystyrene']),
        'hpc_col_width_mesh_end_time' => sanitize_css_value($_POST['hpc_col_width_mesh_end_time'] ?? '', $default_print_settings['hpc_col_width_mesh_end_time']),
        'hpc_col_width_concrete_end_time' => sanitize_css_value($_POST['hpc_col_width_concrete_end_time'] ?? '', $default_print_settings['hpc_col_width_concrete_end_time']),
        'hpc_col_width_assembly_end_time' => sanitize_css_value($_POST['hpc_col_width_assembly_end_time'] ?? '', $default_print_settings['hpc_col_width_assembly_end_time']),
        'hpc_col_width_packing_status' => sanitize_css_value($_POST['hpc_col_width_packing_status'] ?? '', $default_print_settings['hpc_col_width_packing_status']),
        'hpc_col_width_row_num' => sanitize_css_value($_POST['hpc_col_width_row_num'] ?? '', $default_print_settings['hpc_col_width_row_num']),
        'hpc_col_width_latest_activity' => sanitize_css_value($_POST['hpc_col_width_latest_activity'] ?? '', $default_print_settings['hpc_col_width_latest_activity']),
        'hpc_col_width_inventory_status' => sanitize_css_value($_POST['hpc_col_width_inventory_status'] ?? '', $default_print_settings['hpc_col_width_inventory_status']),
        'hpc_col_width_shipping_date' => sanitize_css_value($_POST['hpc_col_width_shipping_date'] ?? '', $default_print_settings['hpc_col_width_shipping_date']),
    ];
    error_log("[{$report_key}] Prepared data for saving: " . print_r($new_settings_data, true)); // Log Prepared Data

    // Update SQL query to include all new columns
    $sql = "INSERT INTO user_print_settings (
                user_id, report_key, print_header, print_footer, print_signature_area, show_logos,
                custom_header_html, custom_footer_html, rows_per_page, header_font_size,
                header_font_color, footer_font_size, footer_font_color, thead_font_size,
                thead_font_color, thead_bg_color, tbody_font_size, tbody_font_color, row_min_height,
                hpc_col_width_address, hpc_col_width_type, hpc_col_width_area, hpc_col_width_width,
                hpc_col_width_length, hpc_col_width_formwork_type, hpc_col_width_Proritization,
                hpc_col_width_assigned_date, hpc_col_width_polystyrene,
                hpc_col_width_mesh_end_time, hpc_col_width_concrete_end_time,
                hpc_col_width_assembly_end_time, hpc_col_width_packing_status,
                hpc_col_width_row_num, hpc_col_width_latest_activity, hpc_col_width_inventory_status, hpc_col_width_shipping_date
            ) VALUES (
                :user_id, :report_key, :print_header, :print_footer, :print_signature_area, :show_logos,
                :custom_header_html, :custom_footer_html, :rows_per_page, :header_font_size,
                :header_font_color, :footer_font_size, :footer_font_color, :thead_font_size,
                :thead_font_color, :thead_bg_color, :tbody_font_size, :tbody_font_color, :row_min_height,
                :hpc_col_width_address, :hpc_col_width_type, :hpc_col_width_area, :hpc_col_width_width,
                :hpc_col_width_length, :hpc_col_width_formwork_type, :hpc_col_width_Proritization,
                :hpc_col_width_assigned_date, :hpc_col_width_polystyrene,
                :hpc_col_width_mesh_end_time, :hpc_col_width_concrete_end_time,
                :hpc_col_width_assembly_end_time, :hpc_col_width_packing_status,
                :hpc_col_width_row_num, :hpc_col_width_latest_activity, :hpc_col_width_inventory_status, :hpc_col_width_shipping_date
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
                hpc_col_width_address = VALUES(hpc_col_width_address), hpc_col_width_type = VALUES(hpc_col_width_type),
                hpc_col_width_area = VALUES(hpc_col_width_area), hpc_col_width_width = VALUES(hpc_col_width_width),
                hpc_col_width_length = VALUES(hpc_col_width_length), hpc_col_width_formwork_type = VALUES(hpc_col_width_formwork_type),
                hpc_col_width_Proritization = VALUES(hpc_col_width_Proritization), 
                hpc_col_width_assigned_date = VALUES(hpc_col_width_assigned_date), hpc_col_width_polystyrene = VALUES(hpc_col_width_polystyrene),
                hpc_col_width_mesh_end_time = VALUES(hpc_col_width_mesh_end_time), hpc_col_width_concrete_end_time = VALUES(hpc_col_width_concrete_end_time),
                hpc_col_width_assembly_end_time = VALUES(hpc_col_width_assembly_end_time), hpc_col_width_packing_status = VALUES(hpc_col_width_packing_status),
                hpc_col_width_row_num = VALUES(hpc_col_width_row_num),
                hpc_col_width_latest_activity = VALUES(hpc_col_width_latest_activity),
                hpc_col_width_inventory_status = VALUES(hpc_col_width_inventory_status),
                hpc_col_width_shipping_date = VALUES(hpc_col_width_shipping_date)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($new_settings_data);
        error_log("[{$report_key}] Save executed successfully for user {$current_user_id}."); // Log Success
        // Update settings variable after save
        $user_print_settings = array_merge($default_print_settings, array_filter($new_settings_data, function ($v) {
            return $v !== null;
        }));
        $user_print_settings['show_logos'] = (bool)$user_print_settings['show_logos'];
        $user_print_settings['rows_per_page'] = (int)$user_print_settings['rows_per_page'];
        $settings_saved_success = true;
        $show_settings_form = false;
    } catch (PDOException $e) {
        error_log("[{$report_key}] CRITICAL Error saving print settings for user {$current_user_id}: " . $e->getMessage() . " SQL: " . $sql . " Data: " . print_r($new_settings_data, true)); // Log error WITH details
        $settings_save_error = "خطا در ذخیره تنظیمات. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.";
        $show_settings_form = true;
    }

    if ($settings_saved_success) {
        error_log("[{$report_key}] Redirecting after save..."); // Log Redirect
        // Redirect to clean URL
        $redirect_params = $_GET;
        unset($redirect_params['settings']);
        $redirect_url = strtok($_SERVER["REQUEST_URI"], '?') . '?' . http_build_query($redirect_params);
        header('Location: ' . $redirect_url);
        exit();
    }
}
function getUserPreferences($user_id, $page, $preference_type = 'columns', $default_value = null)
{
    global $pdo; // Ensure $pdo is accessible
    try {
        // Add check if $pdo is valid
        if (!isset($pdo) || !$pdo instanceof PDO) {
            // Log error or handle appropriately, maybe return default
            error_log("getUserPreferences called but PDO connection is not available.");
            return $default_value;
        }
        $stmt = $pdo->prepare("SELECT preferences FROM user_preferences WHERE user_id = ? AND page = ? AND preference_type = ?");
        $stmt->execute([$user_id, $page, $preference_type]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && !empty($result['preferences'])) {
            return $result['preferences']; // Return the string 'col1,col2,...'
        }
        return $default_value;
    } catch (PDOException $e) {
        error_log("Error getting user preferences (User: $user_id, Page: $page, Type: $preference_type): " . $e->getMessage());
        return $default_value; // Return default on error
    }
}

// --- Define Available Columns (Same as manager page) ---
$available_columns = [
    'row_num' => 'شماره ردیف',
    'address' => 'آدرس',
    'type' => 'نوع',
    'area' => 'مساحت (m²)',
    'width' => 'عرض (mm)',
    'length' => 'طول (mm)',
    'formwork_type' => 'نوع قالب',
    'Proritization' => 'اولویت',
    'latest_activity' => 'آخرین فعالیت',
    'assigned_date' => 'تاریخ تولید',
    'polystyrene' => 'پلی استایرن',
    'mesh_end_time' => 'پایان مش',
    'concrete_end_time' => 'پایان بتن',
    'assembly_end_time' => 'پایان فیس کوت',
    'inventory_status' => 'موجودی/ارسال شده',
    'packing_status' => 'وضعیت بسته بندی',
    'shipping_date' => 'تاریخ ارسال',
];

// --- Handle Column Selection (from $_GET['cols']) ---
$default_columns = [
    'row_num',
    'address',
    'type',
    'area',
    'latest_activity',
    'assigned_date',
    'concrete_end_time',
    'inventory_status',
    'packing_status',
    'shipping_date'
]; // Example print defaults
$print_default_columns = [
    'row_num',
    'address',
    'type',
    'area',
    'latest_activity',
    'assigned_date',
    'concrete_end_time',
    'inventory_status',
    'packing_status',
    'shipping_date'
];
// --- Determine Selected Columns (Priority: DB -> URL -> Print Default) ---
$selected_columns = []; // Initialize

// 1. Try to load from User Preferences for the *manager* page
$user_columns_pref_string = getUserPreferences($current_user_id, 'hpc_panels_manager', 'columns', null);

if (!empty($user_columns_pref_string)) {
    $selected_columns = explode(',', $user_columns_pref_string);
    // Basic check: Ensure explode didn't just result in [""] from an empty string pref
    if (count($selected_columns) === 1 && $selected_columns[0] === '') {
        $selected_columns = []; // Treat as empty if pref was just ""
    } else {
        error_log("[hpc_panels_print] Using columns from DB preferences for user {$current_user_id}.");
    }
}

// 2. Fallback to URL parameter if DB prefs weren't found or were empty
if (empty($selected_columns)) {
    $selected_columns_str_url = trim($_GET['cols'] ?? '');
    if (!empty($selected_columns_str_url)) {
        $selected_columns = explode(',', $selected_columns_str_url);
        error_log("[hpc_panels_print] Using columns from URL parameter ('cols').");
    }
}

// 3. Fallback to Print Defaults if DB and URL provided no columns
if (empty($selected_columns)) {
    $selected_columns = $print_default_columns;
    error_log("[hpc_panels_print] Using print default columns.");
}

// --- Final Validation ---
// Ensure all selected columns actually exist in the current $available_columns map
$validated_columns = [];
foreach ($selected_columns as $col_key) {
    if (isset($available_columns[trim($col_key)])) { // Trim whitespace just in case
        $validated_columns[] = trim($col_key);
    } else {
        error_log("[hpc_panels_print] Warning: Column '{$col_key}' from preferences/URL is not in available_columns, skipping.");
    }
}
$selected_columns = $validated_columns;


// If validation resulted in an empty list (e.g., saved prefs/URL had only old/invalid cols), use print defaults as final resort
if (empty($selected_columns)) {
    error_log("[hpc_panels_print] Columns empty after validation, falling back to *validated* print defaults.");
    $selected_columns = array_intersect($print_default_columns, array_keys($available_columns));
    // Final check in case defaults themselves are out of sync (shouldn't happen ideally)
    if (empty($selected_columns)) {
        error_log("[hpc_panels_print] CRITICAL: Print default columns are also invalid or empty after validation.");
        // Maybe just pick one known good column?
        $selected_columns = ['address']; // Absolute fallback
    }
}



// --- Process Filters (Same logic as manager page) ---
$search_params = [];
$sql_conditions = [];

// Text Filters (Address only now)
if (!empty($_GET["filter_address"])) {
    $value = trim($_GET["filter_address"]);
    $sql_conditions[] = "`address` LIKE :address"; // Use backticks
    $search_params[":address"] = "%" . $value . "%";
}

// Number Range Filters
$range_filters = ['area', 'width', 'length'];
foreach ($range_filters as $filter_key) {
    if (!empty($_GET["filter_{$filter_key}_min"])) {
        $value = filter_var($_GET["filter_{$filter_key}_min"], FILTER_VALIDATE_FLOAT);
        if ($value !== false) {
            $sql_conditions[] = "`$filter_key` >= :{$filter_key}_min";
            $search_params[":{$filter_key}_min"] = $value;
        }
    }
    if (!empty($_GET["filter_{$filter_key}_max"])) {
        $value = filter_var($_GET["filter_{$filter_key}_max"], FILTER_VALIDATE_FLOAT);
        if ($value !== false) {
            $sql_conditions[] = "`$filter_key` <= :{$filter_key}_max";
            $search_params[":{$filter_key}_max"] = $value;
        }
    }
}

// Exact Match / Enum Filters
$exact_match_filters = ['type', 'Proritization', /*'status',*/ 'packing_status', 'polystyrene']; // Removed 'status'
foreach ($exact_match_filters as $filter_key) {
    // Check if filter is set and not an empty string
    if (isset($_GET["filter_$filter_key"]) && $_GET["filter_$filter_key"] !== '') {
        $value = $_GET["filter_$filter_key"];
        // Basic validation for polystyrene
        if ($filter_key == 'polystyrene' && ($value === '0' || $value === '1')) {
            $sql_conditions[] = "`$filter_key` = :$filter_key";
            $search_params[":$filter_key"] = $value;
        } elseif ($filter_key != 'polystyrene') { // For type, Proritization, packing_status
            $sql_conditions[] = "`$filter_key` = :$filter_key";
            $search_params[":$filter_key"] = $value;
        }
    }
}

// Handle NEW "Inventory Status" Filter
if (isset($_GET["filter_inventory_status"]) && $_GET["filter_inventory_status"] !== '') {
    $inv_status_filter = $_GET["filter_inventory_status"];
    if ($inv_status_filter === 'موجود') {
        $sql_conditions[] = "(concrete_end_time IS NOT NULL AND concrete_end_time != '0000-00-00 00:00:00' AND (packing_status IS NULL OR packing_status != 'shipped'))";
    } elseif ($inv_status_filter === 'ارسال شده') {
        $sql_conditions[] = "(packing_status = 'shipped')";
    }
}

// Handle NEW "Latest Activity" Filter
if (isset($_GET["filter_latest_activity"]) && $_GET["filter_latest_activity"] !== '') {
    $activity_filter = $_GET["filter_latest_activity"];
    $null_date_check = "IS NULL OR %s = '0000-00-00 00:00:00'";
    $not_null_date_check = "IS NOT NULL AND %s != '0000-00-00 00:00:00'";

    if ($activity_filter === 'assembly_done') {
        $sql_conditions[] = sprintf("assembly_end_time $not_null_date_check", "assembly_end_time");
    } elseif ($activity_filter === 'concrete_done') {
        $sql_conditions[] = sprintf("concrete_end_time $not_null_date_check", "concrete_end_time") . " AND (" . sprintf("assembly_end_time $null_date_check", "assembly_end_time") . ")";
    } elseif ($activity_filter === 'mesh_done') {
        $sql_conditions[] = sprintf("mesh_end_time $not_null_date_check", "mesh_end_time") . " AND (" . sprintf("concrete_end_time $null_date_check", "concrete_end_time") . ")";
    } elseif ($activity_filter === 'pending') {
        $sql_conditions[] = "(" . sprintf("mesh_end_time $null_date_check", "mesh_end_time") . ")";
    }
}

// Date Range Filters
$date_filters = ['assigned_date', 'mesh_end_time', 'concrete_end_time', 'assembly_end_time', 'shipping_date']; // Added shipping_date
foreach ($date_filters as $filter_key) {
    // Keep the inner logic for parsing Jalali dates the same as in manager
    $date_from_j = $_GET["filter_{$filter_key}_from"] ?? '';
    $date_to_j = $_GET["filter_{$filter_key}_to"] ?? '';

    if (!empty($date_from_j)) {
        $jds = toLatinDigitsPhp(trim($date_from_j));
        $jp = explode('/', $jds);
        if (count($jp) === 3) {
            $ga = jalali_to_gregorian(intval($jp[0]), intval($jp[1]), intval($jp[2]));
            if ($ga) {
                $gs = sprintf('%04d-%02d-%02d', $ga[0], $ga[1], $ga[2]);
                // For datetime columns, compare against the date part
                $column_name = ($filter_key == 'assigned_date') ? $filter_key : "DATE(`$filter_key`)";
                if (DateTime::createFromFormat('Y-m-d', $gs)) {
                    $sql_conditions[] = "$column_name >= :{$filter_key}_from";
                    $search_params[":{$filter_key}_from"] = $gs;
                }
            }
        }
    }
    if (!empty($date_to_j)) {
        $jds = toLatinDigitsPhp(trim($date_to_j));
        $jp = explode('/', $jds);
        if (count($jp) === 3) {
            $ga = jalali_to_gregorian(intval($jp[0]), intval($jp[1]), intval($jp[2]));
            if ($ga) {
                $gs = sprintf('%04d-%02d-%02d', $ga[0], $ga[1], $ga[2]);
                $column_name = ($filter_key == 'assigned_date') ? $filter_key : "DATE(`$filter_key`)";
                if (DateTime::createFromFormat('Y-m-d', $gs)) {
                    $sql_conditions[] = "$column_name <= :{$filter_key}_to";
                    $search_params[":{$filter_key}_to"] = $gs;
                }
            }
        }
    }
}


// --- Build Final Query ---
$where_clause = "";
if (!empty($sql_conditions)) {
    $where_clause = " WHERE " . implode(" AND ", $sql_conditions);
}
// --- Fetch Data (Apply filters) ---
$records = [];
try {
    // Select all columns initially, filtering/display is handled later
    $sql_main = "SELECT * FROM hpc_panels" . $where_clause . " ORDER BY Proritization DESC, assigned_date DESC, id DESC"; // Example order
    $stmt_main = $pdo->prepare($sql_main);
    $stmt_main->execute($search_params);
    $records = $stmt_main->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching HPC panels: " . $e->getMessage());
    $_SESSION['error_message'] = "خطا در بارگذاری داده‌های پنل‌ها.";
}

// --- Helper Functions for Rendering (Use settings) ---
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
            $header .= '<td class="logo-cell logo-right"><img src="/assets/images/hotelfereshteh1.png" alt="Logo"></td>';
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
// --- RENDER TABLE HEADERS BASED ON *SELECTED* COLUMNS ---
function renderHPCTableHeaders($selected_cols_keys, $all_available_cols)
{
    $html = '<thead><tr>';
    foreach ($selected_cols_keys as $key) {
        $label = $all_available_cols[$key] ?? ucfirst(str_replace('_', ' ', $key)); // Fallback label
        $html .= '<th>' . htmlspecialchars($label) . '</th>';
    }
    $html .= '</tr></thead>';
    return $html;
}

$pageTitle = "چاپ گزارش پنل‌های HPC";
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <?php if (!$show_settings_form): ?>
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
                --footer-font-size: <?php echo htmlspecialchars($user_print_settings['footer_font_size'] ?? '10pt'); ?>;
                --footer-font-color: <?php echo htmlspecialchars($user_print_settings['footer_font_color'] ?? '#333333'); ?>;
                --thead-font-size: <?php echo htmlspecialchars($user_print_settings['thead_font_size'] ?? '10pt'); ?>;
                --thead-font-color: <?php echo htmlspecialchars($user_print_settings['thead_font_color'] ?? '#000000'); ?>;
                --thead-bg-color: <?php echo htmlspecialchars($user_print_settings['thead_bg_color'] ?? '#eeeeee'); ?>;
                --tbody-font-size: <?php echo htmlspecialchars($user_print_settings['tbody_font_size'] ?? '10pt'); ?>;
                --tbody-font-color: <?php echo htmlspecialchars($user_print_settings['tbody_font_color'] ?? '#000000'); ?>;
                --row-min-height: <?php echo htmlspecialchars($user_print_settings['row_min_height'] ?? 'auto'); ?>;
                /* Column Widths */
                --hpc-col-width-address: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_address'] ?? '15%'); ?>;
                --hpc-col-width-type: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_type'] ?? '10%'); ?>;
                --hpc-col-width-area: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_area'] ?? '8%'); ?>;
                --hpc-col-width-width: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_width'] ?? '8%'); ?>;
                --hpc-col-width-length: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_length'] ?? '8%'); ?>;
                --hpc-col-width-formwork-type: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_formwork_type'] ?? '10%'); ?>;
                --hpc-col-width-Proritization: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_Proritization'] ?? '8%'); ?>;
                /* Corrected key */


                --hpc-col-width-assigned-date: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_assigned_date'] ?? '10%'); ?>;
                --hpc-col-width-polystyrene: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_polystyrene'] ?? '8%'); ?>;
                --hpc-col-width-mesh-end-time: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_mesh_end_time'] ?? '10%'); ?>;
                --hpc-col-width-concrete-end-time: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_concrete_end_time'] ?? '10%'); ?>;
                --hpc-col-width-assembly-end-time: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_assembly_end_time'] ?? '10%'); ?>;
                --hpc-col-width-packing-status: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_packing_status'] ?? '10%'); ?>;
                --hpc-col-width-row-num: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_row_num'] ?? '5%'); ?>;
                --hpc-col-width-latest-activity: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_latest_activity'] ?? '12%'); ?>;
                --hpc-col-width-inventory-status: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_inventory_status'] ?? '10%'); ?>;
                --hpc_col-width-shipping-date: <?php echo htmlspecialchars($user_print_settings['hpc_col_width_shipping_date'] ?? '10%'); ?>;
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
                width: var(--hpc-col-width-row-num);
            }

            /* Example: Row Num */
            .data-table th:nth-child(2),
            .data-table td:nth-child(2) {
                width: var(--hpc-col-width-address);
            }

            .data-table th:nth-child(3),
            .data-table td:nth-child(3) {
                width: var(--hpc-col-width-type);
            }

            .data-table th:nth-child(4),
            .data-table td:nth-child(4) {
                width: var(--hpc-col-width-area);
            }

            .data-table th:nth-child(5),
            .data-table td:nth-child(5) {
                width: var(--hpc-col-width-width);
            }

            .data-table th:nth-child(6),
            .data-table td:nth-child(6) {
                width: var(--hpc-col-width-length);
            }

            .data-table th:nth-child(7),
            .data-table td:nth-child(7) {
                width: var(--hpc-col-width-formwork-type);
            }

            .data-table th:nth-child(8),
            .data-table td:nth-child(8) {
                width: var(--hpc-col-width-Proritization);
            }

            .data-table th:nth-child(9),
            .data-table td:nth-child(9) {
                width: var(--hpc-col-width-latest-activity);
            }

            /* Example: Latest Activity */
            .data-table th:nth-child(10),
            .data-table td:nth-child(10) {
                width: var(--hpc-col-width-assigned-date);
            }

            .data-table th:nth-child(11),
            .data-table td:nth-child(11) {
                width: var(--hpc-col-width-polystyrene);
            }

            .data-table th:nth-child(12),
            .data-table td:nth-child(12) {
                width: var(--hpc-col-width-mesh-end-time);
            }

            .data-table th:nth-child(13),
            .data-table td:nth-child(13) {
                width: var(--hpc-col-width-concrete-end-time);
            }

            .data-table th:nth-child(14),
            .data-table td:nth-child(14) {
                width: var(--hpc-col-width-assembly-end-time);
            }

            .data-table th:nth-child(15),
            .data-table td:nth-child(15) {
                width: var(--hpc-col-width-inventory-status);
            }

            /* Example: Inventory Status */
            .data-table th:nth-child(16),
            .data-table td:nth-child(16) {
                width: var(--hpc-col-width-packing-status);
            }

            .data-table th:nth-child(17),
            .data-table td:nth-child(17) {
                width: var(--hpc_col-width-shipping-date);
            }

            /* Example: Shipping Date */


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
                    titleDiv.textContent = 'لیست پنل‌های HPC';
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
    <?php else: ?>
        <style>
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
            <h1>تنظیمات چاپ گزارش پنل‌ها</h1>
            <?php if ($settings_save_error): ?>
                <div class="error-message"><?php echo htmlspecialchars($settings_save_error); ?></div>
            <?php endif; ?>
            <!-- --- Settings Form (Same structure as concrete_tests_print.php) --- -->
            <form method="post" action="hpc_panels_print.php?<?php echo http_build_query($_GET); ?>">
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
                    <legend>عرض ستون‌های جدول پنل HPC</legend>
                    <small>مقادیر را با % یا px وارد کنید (مثال: 15% یا 120px). مجموع درصدها لزوماً نباید 100 باشد.</small>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="hpc_col_width_row_num">شماره ردیف:</label>
                            <input type="text" name="hpc_col_width_row_num" id="hpc_col_width_row_num" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_row_num'] ?? $default_print_settings['hpc_col_width_row_num']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_address">آدرس:</label>
                            <input type="text" name="hpc_col_width_address" id="hpc_col_width_address" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_address']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_type">نوع:</label>
                            <input type="text" name="hpc_col_width_type" id="hpc_col_width_type" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_type']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_area">مساحت (m²):</label>
                            <input type="text" name="hpc_col_width_area" id="hpc_col_width_area" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_area']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_width">عرض (mm):</label>
                            <input type="text" name="hpc_col_width_width" id="hpc_col_width_width" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_width']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="hpc_col_width_length">طول (mm):</label>
                            <input type="text" name="hpc_col_width_length" id="hpc_col_width_length" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_length']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_formwork_type">نوع قالب:</label>
                            <input type="text" name="hpc_col_width_formwork_type" id="hpc_col_width_formwork_type" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_formwork_type']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_Proritization">اولویت:</label>
                            <input type="text" name="hpc_col_width_Proritization" id="hpc_col_width_Proritization" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_Proritization']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_status">وضعیت:</label>
                            <input type="text" name="hpc_col_width_status" id="hpc_col_width_status" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_status']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="hpc_col_width_assigned_date">تاریخ تخصیص:</label>
                            <input type="text" name="hpc_col_width_assigned_date" id="hpc_col_width_assigned_date" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_assigned_date']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_polystyrene">پلی استایرن:</label>
                            <input type="text" name="hpc_col_width_polystyrene" id="hpc_col_width_polystyrene" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_polystyrene']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_mesh_end_time">پایان مش:</label>
                            <input type="text" name="hpc_col_width_mesh_end_time" id="hpc_col_width_mesh_end_time" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_mesh_end_time']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_concrete_end_time">پایان بتن:</label>
                            <input type="text" name="hpc_col_width_concrete_end_time" id="hpc_col_width_concrete_end_time" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_concrete_end_time']); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="hpc_col_width_assembly_end_time">پایان فیس کوت:</label>
                            <input type="text" name="hpc_col_width_assembly_end_time" id="hpc_col_width_assembly_end_time" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_assembly_end_time']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_packing_status">وضعیت بسته‌بندی:</label>
                            <input type="text" name="hpc_col_width_packing_status" id="hpc_col_width_packing_status" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_packing_status']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="hpc_col_width_latest_activity">آخرین فعالیت:</label>
                            <input type="text" name="hpc_col_width_latest_activity" id="hpc_col_width_latest_activity" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_latest_activity'] ?? $default_print_settings['hpc_col_width_latest_activity']); ?>">
                        </div>

                        <!-- Add input for Inventory Status -->
                        <div class="form-group">
                            <label for="hpc_col_width_inventory_status">موجودی/ارسال:</label>
                            <input type="text" name="hpc_col_width_inventory_status" id="hpc_col_width_inventory_status" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_inventory_status'] ?? $default_print_settings['hpc_col_width_inventory_status']); ?>">
                        </div>

                        <!-- Add input for Shipping Date -->
                        <div class="form-group">
                            <label for="hpc_col_width_shipping_date">تاریخ ارسال:</label>
                            <input type="text" name="hpc_col_width_shipping_date" id="hpc_col_width_shipping_date" value="<?php echo htmlspecialchars($user_print_settings['hpc_col_width_shipping_date'] ?? $default_print_settings['hpc_col_width_shipping_date']); ?>">
                        </div>

                        <!-- Add placeholders for alignment if needed -->
                        <div class="form-group" style="visibility: hidden;"></div>
                        <div class="form-group" style="visibility: hidden;"></div>
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
                <div class="buttons no-print">
                    <button type="submit">ذخیره تنظیمات و نمایش چاپ</button>
                    <?php
                    // Correct "Cancel" link: Back to print view, keep filters, remove settings=1
                    $cancel_params = $_GET;
                    unset($cancel_params['settings']);
                    $cancel_url = strtok($_SERVER["REQUEST_URI"], '?') . '?' . http_build_query($cancel_params);
                    ?>
                    <a href="<?php echo htmlspecialchars($cancel_url); ?>"><button type="button" class="btn-secondary">انصراف</button></a>
                    <?php
                    // Return to list button
                    $return_page = 'hpc_panels_manager.php';
                    $return_query_string = http_build_query($cancel_params); // Use params without 'settings'
                    $return_url = $return_page . (!empty($return_query_string) ? '?' . $return_query_string : '');
                    ?>
                    <a href="<?php echo htmlspecialchars($return_url); ?>"><button type="button" class="btn-secondary">بازگشت به لیست</button></a>
                </div>
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
                <div style="text-align: center; font-weight: bold; margin-bottom: 15px; font-size: var(--header-font-size);">
                    گزارش پنل‌های HPC
                </div>
                <table id="data-table" class="data-table">
                    <!-- Render headers based on selected columns -->
                    <?php echo renderHPCTableHeaders($selected_columns, $available_columns); ?>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="<?php echo count($selected_columns); ?>" class="text-center text-muted py-3">رکوردی یافت نشد.</td>
                            </tr>
                            <?php else:
                            $row_number = 1; // Initialize row number for print
                            foreach ($records as $record):
                            ?>
                                <tr>
                                    <?php foreach ($selected_columns as $col_key): ?>
                                        <td>
                                            <?php
                                            // --- Handle Special Calculated Columns First ---
                                            if ($col_key === 'row_num') {
                                                echo $row_number;
                                            } elseif ($col_key === 'inventory_status') {
                                                // Same logic as manager
                                                $concrete_end = !empty($record['concrete_end_time']) && $record['concrete_end_time'] != '0000-00-00 00:00:00';
                                                $packing_status = $record['packing_status'] ?? null;
                                                if ($packing_status === 'shipped') {
                                                    echo 'ارسال شده';
                                                } elseif ($concrete_end && $packing_status !== 'shipped') {
                                                    echo 'موجود';
                                                } else {
                                                    echo '-';
                                                }
                                            } elseif ($col_key === 'latest_activity') {
                                                // Same logic as manager
                                                $assembly_done = !empty($record['assembly_end_time']) && $record['assembly_end_time'] != '0000-00-00 00:00:00';
                                                $concrete_done = !empty($record['concrete_end_time']) && $record['concrete_end_time'] != '0000-00-00 00:00:00';
                                                $mesh_done = !empty($record['mesh_end_time']) && $record['mesh_end_time'] != '0000-00-00 00:00:00';

                                                if ($assembly_done) {
                                                    echo $latest_activity_map_persian['assembly_done'];
                                                } elseif ($concrete_done) {
                                                    echo $latest_activity_map_persian['concrete_done'];
                                                } elseif ($mesh_done) {
                                                    echo $latest_activity_map_persian['mesh_done'];
                                                } else {
                                                    echo $latest_activity_map_persian['pending'];
                                                }
                                            }
                                            // --- Handle Standard DB Columns ---
                                            else {
                                                $value = $record[$col_key] ?? null;
                                                if ($value === null) {
                                                    echo '-';
                                                }
                                                // UPDATED: Use the $persian_date_columns array
                                                elseif (in_array($col_key, $persian_date_columns)) {
                                                    $date_part = ($value) ? explode(' ', $value)[0] : null;
                                                    echo gregorianToShamsi($date_part) ?: '-';
                                                } elseif ($col_key === 'polystyrene') {
                                                    echo $polystyrene_map_persian[$value] ?? htmlspecialchars($value);
                                                }
                                                // REMOVED: elseif ($col_key === 'status') { ... }
                                                elseif ($col_key === 'packing_status') {
                                                    echo $packing_status_map_persian[$value] ?? htmlspecialchars($value);
                                                } elseif (is_numeric($value) && $col_key === 'area') {
                                                    // Consistent formatting
                                                    echo number_format((float)$value, 3);
                                                } elseif (is_numeric($value) && ($col_key === 'width' || $col_key === 'length')) {
                                                    // Consistent formatting
                                                    echo number_format((float)$value, 0);
                                                } else {
                                                    echo htmlspecialchars($value);
                                                }
                                            } // End else for standard columns
                                            ?>
                                        </td>
                                    <?php endforeach; // End inner loop for columns 
                                    ?>
                                </tr>
                        <?php
                                $row_number++; // Increment row number
                            endforeach; // End outer loop for records
                        endif; ?>
                    </tbody>
                </table>
                <div class="footer">
                    <?php echo renderFooter($user_print_settings); ?>
                    <div class="page-number"></div>
                </div>
            </div> <!-- End initial print-page -->
        </div> <!-- End content-area -->

        </form>
        </div>
        <!-- Print Controls -->
        <div class="no-print" style="position: fixed; bottom: 10px; left: 10px; background: rgba(255,255,255,0.8); padding: 10px; border-radius: 5px; box-shadow: 0 0 5px rgba(0,0,0,0.2); z-index: 100; display: flex; gap: 10px;">
            <button onclick="window.print();" tyle="padding: 8px 15px; background-color: #198754; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;"> <i class="bi bi-printer"></i> چاپ </button>
            <?php $settings_params = $_GET;
            $settings_params['settings'] = '1';
            $settings_url = strtok($_SERVER["REQUEST_URI"], '?') . '?' . http_build_query($settings_params); ?>
            <a href="<?php echo htmlspecialchars($settings_url); ?>" style="padding: 8px 15px; background-color: #0d6efd; color: white; text-decoration: none; border-radius: 4px; font-size: 12px;"> <i class="bi bi-gear"></i> تنظیمات </a>
            <?php $return_page = 'hpc_panels_manager.php';
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