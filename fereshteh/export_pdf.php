<?php
// export_pdf.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start(); // Start output buffering

require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once __DIR__ . '/includes/jdf.php'; // For gregorianToShamsi
require_once __DIR__ . '/includes/libraries/TCPDF-main/tcpdf.php';
secureSession();
$expected_project_key = 'fereshteh'; // HARDCODED FOR THIS FILE
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
$report_key = 'export_pdf'; // HARDCODED FOR THIS FILE
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/export_pdf.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}
function getUserPreferences($user_id, $page, $preference_type = 'columns', $default_value = null)
{
    global $pdo;
    try {
        if (!isset($pdo) || !$pdo instanceof PDO) {
            throw new RuntimeException("Database connection is not initialized.");
        }
        $stmt = $pdo->prepare("SELECT preferences FROM user_preferences WHERE user_id = ? AND page = ? AND preference_type = ?");
        $stmt->execute([$user_id, $page, $preference_type]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && !empty($result['preferences'])) {
            return $result['preferences'];
        }
        return $default_value;
    } catch (PDOException $e) {
        error_log("Error getting user preferences: " . $e->getMessage());
        return $default_value;
    }
}

// --- Mappings & Available Columns (MUST match) ---
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

// --- Define Available Columns for Display/Selection ---
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
$pdf_default_columns = [ // Renamed for clarity
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

$latest_activity_map_persian = [
    'assembly_done' => 'فیس کوت انجام شد',
    'concrete_done' => 'بتن ریزی انجام شد',
    'mesh_done' => 'مش بندی انجام شد',
    'pending' => 'شروع نشده / در انتظار',
];

// Add the list of columns needing Persian date formatting
$persian_date_columns = ['assigned_date', 'mesh_end_time', 'concrete_end_time', 'assembly_end_time', 'shipping_date']; // Added shipping_date
// --- Determine Selected Columns (Priority: DB -> URL -> Print Default) ---
$selected_columns = []; // Initialize
$current_user_id = $_SESSION['user_id'] ?? null; // Ensure current_user_id is set

// 1. Try to load from User Preferences for the *manager* page
// Make sure $current_user_id is valid before calling
if ($current_user_id) {
    $user_columns_pref_string = getUserPreferences($current_user_id, 'hpc_panels_manager', 'columns', null);

    if (!empty($user_columns_pref_string)) {
        $selected_columns = explode(',', $user_columns_pref_string);
        if (count($selected_columns) === 1 && $selected_columns[0] === '') {
            $selected_columns = [];
        } else {
            error_log("[export_pdf] Using columns from DB preferences for user {$current_user_id}.");
        }
    }
} else {
    error_log("[export_pdf] Cannot load user preferences: user_id not found in session.");
}


// 2. Fallback to URL parameter if DB prefs weren't found or were empty
if (empty($selected_columns)) {
    $selected_columns_str_url = trim($_GET['cols'] ?? '');
    if (!empty($selected_columns_str_url)) {
        $selected_columns = explode(',', $selected_columns_str_url);
        error_log("[export_pdf] Using columns from URL parameter ('cols').");
    }
}

// 3. Fallback to PDF Defaults if DB and URL provided no columns
if (empty($selected_columns)) {
    $selected_columns = $pdf_default_columns; // Use PDF defaults
    error_log("[export_pdf] Using PDF default columns.");
}

// --- Final Validation ---
// (Keep the validation logic exactly as in the print script)
$validated_columns = [];
foreach ($selected_columns as $col_key) {
    if (isset($available_columns[trim($col_key)])) {
        $validated_columns[] = trim($col_key);
    } else {
        error_log("[export_pdf] Warning: Column '{$col_key}' from preferences/URL is not in available_columns, skipping.");
    }
}
$selected_columns = $validated_columns;

// Final fallback check
if (empty($selected_columns)) {
    error_log("[export_pdf] Columns empty after validation, falling back to *validated* PDF defaults.");
    $selected_columns = array_intersect($pdf_default_columns, array_keys($available_columns));
    if (empty($selected_columns)) {
        error_log("[export_pdf] CRITICAL: PDF default columns are also invalid or empty after validation.");
        $selected_columns = ['address']; // Absolute fallback
    }
}
// $selected_columns is now ready
// --- Process Filters (from $_GET) ---
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
$exact_match_filters = ['type', 'Proritization', 'packing_status', 'polystyrene']; // Add type and Proritization
foreach ($exact_match_filters as $filter_key) {
    // Check if filter is set and not an empty string
    if (isset($_GET["filter_$filter_key"]) && $_GET["filter_$filter_key"] !== '') {
        $value = $_GET["filter_$filter_key"];
        // Basic validation for polystyrene
        if ($filter_key == 'polystyrene' && ($value === '0' || $value === '1')) {
            $sql_conditions[] = "`$filter_key` = :$filter_key";
            $search_params[":$filter_key"] = $value;
        } elseif ($filter_key != 'polystyrene') { // For type, Proritization, status, packing_status
            $sql_conditions[] = "`$filter_key` = :$filter_key";
            $search_params[":$filter_key"] = $value;
        }
    }
}

if (isset($_GET["filter_inventory_status"]) && $_GET["filter_inventory_status"] !== '') {
    $inv_status_filter = $_GET["filter_inventory_status"];
    if ($inv_status_filter === 'موجود') {
        // Condition for 'موجود': Concrete is done AND Packing is NOT shipped
        $sql_conditions[] = "(concrete_end_time IS NOT NULL AND concrete_end_time != '0000-00-00 00:00:00' AND packing_status != 'shipped')";
        // Note: This assumes 'shipped' is the only status indicating not available. Adjust if null/pending/assigned also mean not shipped.
        // A potentially safer condition if other packing statuses exist and mean "not shipped":
        // $sql_conditions[] = "(concrete_end_time IS NOT NULL AND concrete_end_time != '0000-00-00 00:00:00' AND (packing_status IS NULL OR packing_status != 'shipped'))";
    } elseif ($inv_status_filter === 'ارسال شده') {
        // Condition for 'ارسال شده': Packing status is 'shipped'
        $sql_conditions[] = "(packing_status = 'shipped')";
        // No parameters needed here as values are hardcoded in SQL string
    }
    // Add hidden input value to keep filter selection
    // No need to add to $search_params unless using placeholders, which isn't necessary here.
}

// Handle NEW "Latest Activity" Filter
if (isset($_GET["filter_latest_activity"]) && $_GET["filter_latest_activity"] !== '') {
    $activity_filter = $_GET["filter_latest_activity"];
    // Define NULL/Empty date check condition string for readability
    $null_date_check = "IS NULL OR %s = '0000-00-00 00:00:00'";
    $not_null_date_check = "IS NOT NULL AND %s != '0000-00-00 00:00:00'";

    if ($activity_filter === 'assembly_done') { // 'فیس کوت انجام شد'
        $sql_conditions[] = sprintf("assembly_end_time $not_null_date_check", "assembly_end_time");
    } elseif ($activity_filter === 'concrete_done') { // 'بتن ریزی انجام شد'
        $sql_conditions[] = sprintf("concrete_end_time $not_null_date_check", "concrete_end_time") . " AND (" . sprintf("assembly_end_time $null_date_check", "assembly_end_time") . ")";
    } elseif ($activity_filter === 'mesh_done') { // 'مش بندی انجام شد'
        $sql_conditions[] = sprintf("mesh_end_time $not_null_date_check", "mesh_end_time") . " AND (" . sprintf("concrete_end_time $null_date_check", "concrete_end_time") . ")";
    } elseif ($activity_filter === 'pending') { // 'شروع نشده / در انتظار'
        $sql_conditions[] = "(" . sprintf("mesh_end_time $null_date_check", "mesh_end_time") . ")"; // Assumes mesh is the first step
    }
    // No parameters needed here as values are hardcoded in SQL string
}
// Date Range Filters
$date_filters = ['assigned_date', 'mesh_end_time', 'concrete_end_time', 'assembly_end_time', 'shipping_date'];
foreach ($date_filters as $filter_key) {
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

// --- Fetch Main Data ---
$records = [];
$total_filtered_count = 0; // Initialize total count
try {
    // Select all columns initially, filtering/display is handled later
    // Fetch only necessary columns for performance if $records gets very large
    $sql_main = "SELECT id, address, type, area, width, length, formwork_type, Proritization, assigned_date, polystyrene, mesh_end_time, concrete_end_time, assembly_end_time, packing_status, shipping_date FROM hpc_panels" . $where_clause . " ORDER BY Proritization DESC, assigned_date DESC, id DESC"; // Example order
    $stmt_main = $pdo->prepare($sql_main);
    $stmt_main->execute($search_params);
    $records = $stmt_main->fetchAll(PDO::FETCH_ASSOC);
    $total_filtered_count = count($records); // Get total count from fetched records
} catch (PDOException $e) {
    error_log("Error fetching HPC panels: " . $e->getMessage());
    $_SESSION['error_message'] = "خطا در بارگذاری داده‌های پنل‌ها.";
    // $records will remain empty, $total_filtered_count will be 0
}


// --- Calculate Counts in PHP from Filtered Data ---
$packing_status_counts_php = array_fill_keys(array_keys($packing_status_map_persian), 0);
$inventory_status_counts_php = ['موجود' => 0, 'ارسال شده' => 0]; // Overall Inventory
$latest_activity_counts_php = array_fill_keys(array_keys($latest_activity_map_persian), 0); // Counts for *latest* step completed
$overall_mesh_done_count = 0;      // Total ever completed Mesh
$overall_concrete_done_count = 0;   // Total ever completed Concrete
$overall_assembly_done_count = 0;  // Total ever completed Assembly

if (!empty($records)) { // Only calculate if there are records
    foreach ($records as $record) {
        // --- Check Date Validity ---
        $mesh_done_valid = !empty($record['mesh_end_time']) && $record['mesh_end_time'] != '0000-00-00 00:00:00';
        $concrete_done_valid = !empty($record['concrete_end_time']) && $record['concrete_end_time'] != '0000-00-00 00:00:00';
        $assembly_done_valid = !empty($record['assembly_end_time']) && $record['assembly_end_time'] != '0000-00-00 00:00:00';
        $packing_status = $record['packing_status'] ?? 'pending'; // Default to 'pending' if null

        // --- 1. Latest Activity Count ---
        // (Counts where this is the *last* completed major step)
        if ($assembly_done_valid) {
            $latest_activity_counts_php['assembly_done']++;
        } elseif ($concrete_done_valid) {
            $latest_activity_counts_php['concrete_done']++;
        } elseif ($mesh_done_valid) {
            $latest_activity_counts_php['mesh_done']++;
        } else {
            $latest_activity_counts_php['pending']++; // Not started Mesh yet
        }

        // --- 2. Overall Completion Counts ---
        // (Counts if the step was *ever* completed, regardless of later steps)
        if ($mesh_done_valid) {
            $overall_mesh_done_count++;
        }
        if ($concrete_done_valid) {
            $overall_concrete_done_count++;
        }
        if ($assembly_done_valid) {
            $overall_assembly_done_count++;
        }

        // --- 3. Packing Status Count (Overall) ---
        if (isset($packing_status_counts_php[$packing_status])) {
            $packing_status_counts_php[$packing_status]++;
        }

        // --- 4. Inventory Status Count (Overall) ---
        if ($packing_status === 'shipped') {
            $inventory_status_counts_php['ارسال شده']++;
        } elseif ($concrete_done_valid && $packing_status !== 'shipped') {
            // Note: Inventory 'موجود' requires concrete to be done.
            $inventory_status_counts_php['موجود']++;
        }
    }

    // Optional: Remove status keys with zero counts if desired for display
    // $packing_status_counts_php = array_filter($packing_status_counts_php);
    // $inventory_status_counts_php = array_filter($inventory_status_counts_php);
    // $latest_activity_counts_php = array_filter($latest_activity_counts_php);
    // Keep overall counts even if zero, perhaps more informative here.
}



// --- Create PDF ---
class MYPDF extends TCPDF
{
    // Optional: Page header/footer
    public function Header()
    {
        $this->SetFont('dejavusans', 'B', 12);
        $this->Cell(0, 10, 'گزارش پنل‌های HPC', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(5);
        $this->SetFont('dejavusans', '', 8);
        $current_gregorian_date = date('Y-m-d');
        $shamsi_date = function_exists('gregorianToShamsi') ? gregorianToShamsi($current_gregorian_date) : date('Y/m/d');
        $report_date_str = 'تاریخ گزارش: ' . $shamsi_date . ' ' . date('H:i:s');
        $this->Cell(0, 10, $report_date_str, 0, false, 'C', 0, '', 0, false, 'M', 'M');
    }
    public function Footer()
    {
        $this->SetY(-15);
        // Crucial: Set font *every time* before drawing text in header/footer
        $this->SetFont('dejavusans', 'I', 8);

        // Get current page number and convert it manually
        $currentPageNum = $this->getAliasNumPage();
        $currentPagePersian = function_exists('toPersianDigits') ? toPersianDigits($currentPageNum) : $currentPageNum;

        // Get the alias string for total pages
        $totalPagesAlias = $this->getAliasNbPages();

        // Construct the string - using "az" is often safer for RTL rendering than "/"
        $footerText = ' ' . $currentPagePersian . ' / ' . $totalPagesAlias;

        // Output the cell. Ensure alignment is center.
        // Parameters: width, height, text, border, line feed, align, fill, link, stretch, ignore min height, calign, valign
        $this->Cell(0, 10, $footerText, 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

// create new PDF document
// Use 'L' for Landscape if table is wide
$pdf = new MYPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// set document information
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('برنامه کارخانه بتن');
$pdf->SetTitle('گزارش پنل های HPC');
$pdf->SetSubject('اطلاعات پنل های فیلتر شده');

// set default header/footer data
$pdf->SetHeaderData('', 0, '', '', array(0, 0, 0), array(255, 255, 255));
$pdf->setFooterData(array(0, 0, 0), array(64, 64, 64));

// set header and footer fonts
$pdf->setHeaderFont(array('dejavusans', '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(array('dejavusans', '', PDF_FONT_SIZE_DATA));

// set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP + 5, PDF_MARGIN_RIGHT); // Increase top margin for header space
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Set font for Persian characters
$pdf->SetFont('dejavusans', '', 8); // Size 8 is small, adjust as needed

// Set RTL direction
$pdf->setRTL(true);

// Add a page
$pdf->AddPage();

// --- Build HTML Table ---
$html = '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">';
// Headers
$html .= '<thead style="background-color:#f2f2f2; font-weight:bold;"><tr>';
foreach ($selected_columns as $col_key) {
    $header_text = $available_columns[$col_key] ?? $col_key;
    // Use htmlspecialchars on header text
    $html .= '<th style="text-align:center;">' . htmlspecialchars($header_text) . '</th>';
}
$html .= '</tr></thead>';

// Data Rows
$html .= '<tbody>';
if (empty($records)) {
    $html .= '<tr><td colspan="' . count($selected_columns) . '" style="text-align:center;">رکوردی یافت نشد.</td></tr>';
} else {
    $row_number = 1; // Initialize row number
    foreach ($records as $record) {
        $html .= '<tr>';
        foreach ($selected_columns as $col_key) {
            $value = $record[$col_key] ?? null;
            $formatted_value = '-'; // Default for null or special calculated columns initially

            // --- Handle Special Calculated Columns First ---
            if ($col_key === 'row_num') {
                $formatted_value = $row_number;
            } elseif ($col_key === 'inventory_status') {
                $concrete_end = !empty($record['concrete_end_time']) && $record['concrete_end_time'] != '0000-00-00 00:00:00';
                $packing_status = $record['packing_status'] ?? null;
                if ($packing_status === 'shipped') {
                    $formatted_value = 'ارسال شده';
                } elseif ($concrete_end && $packing_status !== 'shipped') {
                    $formatted_value = 'موجود';
                } // else remains '-'
            } elseif ($col_key === 'latest_activity') {
                $assembly_done = !empty($record['assembly_end_time']) && $record['assembly_end_time'] != '0000-00-00 00:00:00';
                $concrete_done = !empty($record['concrete_end_time']) && $record['concrete_end_time'] != '0000-00-00 00:00:00';
                $mesh_done = !empty($record['mesh_end_time']) && $record['mesh_end_time'] != '0000-00-00 00:00:00';

                if ($assembly_done) {
                    $formatted_value = $latest_activity_map_persian['assembly_done'];
                } elseif ($concrete_done) {
                    $formatted_value = $latest_activity_map_persian['concrete_done'];
                } elseif ($mesh_done) {
                    $formatted_value = $latest_activity_map_persian['mesh_done'];
                } else {
                    $formatted_value = $latest_activity_map_persian['pending'];
                }
            }
            // --- Handle Standard DB Columns ---
            elseif ($value !== null) {
                $formatted_value = $value; // Start with raw value
                // Use the $persian_date_columns array
                if (in_array($col_key, $persian_date_columns)) {
                    $date_part = explode(' ', $value)[0];
                    // Use function_exists for safety
                    $formatted_value = function_exists('gregorianToShamsi') ? gregorianToShamsi($date_part) : $date_part;
                    if (empty($formatted_value)) $formatted_value = '-';
                } elseif ($col_key === 'polystyrene') {
                    $formatted_value = $polystyrene_map_persian[$value] ?? $value;
                }
                // Removed old 'status' check
                elseif ($col_key === 'packing_status') {
                    $formatted_value = $packing_status_map_persian[$value] ?? $value;
                } elseif (is_numeric($value) && $col_key === 'area') {
                    $formatted_value = number_format((float)$value, 3);
                } elseif (is_numeric($value) && ($col_key === 'width' || $col_key === 'length')) {
                    $formatted_value = number_format((float)$value, 0);
                }
                // Default case handled by starting with $value
            }

            // Always escape the final value for HTML safety before adding to cell
            $html .= '<td style="text-align:center;">' . htmlspecialchars((string)$formatted_value) . '</td>';
        }
        $html .= '</tr>';
        $row_number++; // Increment row number
    }
}
$html .= '</tbody></table>';

// --- Write HTML to PDF ---
$pdf->writeHTML($html, true, false, true, false, '');


// --- Output PDF ---
$filename = "hpc_panels_" . date('Ymd_His') . ".pdf";

// Clear buffer before sending headers
ob_end_clean();

// Close and output PDF document
// 'D' force download, 'I' display in browser
$pdf->Output($filename, 'D');

exit;
