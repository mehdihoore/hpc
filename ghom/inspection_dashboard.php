<?php
// ===================================================================
// START: FINAL, PROFESSIONAL PHP FOR STAGE-BASED DASHBOARD
// ===================================================================

require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once __DIR__ . '/includes/jdf.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    header('Location: /login.php?msg=login_required');
    exit();
}
if (!in_array($_SESSION['role'], ['admin', 'supervisor', 'user', 'superuser', 'cat', 'car', 'coa', 'crs'])) {
    http_response_code(403);
    require 'Access_Denied.php';
    exit;
}

$pageTitle = "داشبورد جامع بازرسی";
require_once __DIR__ . '/header_ghom.php';

// Helper function for coloring table rows based on status
function get_status_class($row)
{
    if ($row['overall_status'] === 'OK') return 'status-ok-cell';
    if ($row['overall_status'] === 'Not OK') return 'status-not-ok-cell';
    if ($row['contractor_status'] === 'Ready for Inspection' && $row['overall_status'] !== 'OK') return 'status-ready-cell';
    return 'status-pending-cell';
}

// --- Initialize variables to ensure they always exist ---
$all_inspection_data = [];
$data_by_type = [];
$status_counts = ['Ready for Inspection' => 0, 'Not OK' => 0, 'OK' => 0, 'Total' => 0];
$all_zones = [];
$all_stages = [];
$all_contractors = [];
$all_inspectors = [];
$db_error_message = null;

try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->exec("SET NAMES 'utf8mb4'");

    // The main SQL Query - Modified to include elements notes
    $sql = "
        SELECT 
            i.inspection_id, i.element_id, i.part_name, i.stage_id,
            s.stage AS stage_name,
            i.contractor_status, i.contractor_date,
            i.overall_status, i.inspection_date,
            e.element_type, e.plan_file, e.zone_name, e.contractor, e.block,e.axis_span,e.floor_level, i.notes,
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'کاربر حذف شده') AS inspector_name
        FROM inspections i
        JOIN elements e ON i.element_id = e.element_id
        JOIN inspection_stages s ON i.stage_id = s.stage_id
        LEFT JOIN hpc_common.users u ON i.user_id = u.id
        ORDER BY i.created_at DESC
    ";

    $stmt = $pdo->query($sql);
    if ($stmt === false) {
        throw new Exception("SQL query failed: " . print_r($pdo->errorInfo(), true));
    }
    $all_inspection_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process the data only if the query was successful
    foreach ($all_inspection_data as $row) {
        $data_by_type[$row['element_type']][] = $row;
        if ($row['overall_status'] === 'OK') $status_counts['OK']++;
        elseif ($row['overall_status'] === 'Not OK') $status_counts['Not OK']++;
        elseif ($row['contractor_status'] === 'Ready for Inspection' && $row['overall_status'] !== 'OK') $status_counts['Ready for Inspection']++;
    }
    $status_counts['Total'] = count($all_inspection_data);

    // Fetch data for dropdowns
    $all_zones = $pdo->query("SELECT DISTINCT plan_file FROM elements WHERE plan_file IS NOT NULL ORDER BY plan_file")->fetchAll(PDO::FETCH_COLUMN);
    $all_stages = $pdo->query("SELECT DISTINCT stage FROM inspection_stages ORDER BY stage")->fetchAll(PDO::FETCH_COLUMN);
    $all_contractors = $pdo->query("SELECT DISTINCT contractor FROM elements WHERE contractor IS NOT NULL AND contractor != '' ORDER BY contractor")->fetchAll(PDO::FETCH_COLUMN);
    $all_inspectors = $pdo->query("SELECT DISTINCT CONCAT(u.first_name, ' ', u.last_name) as inspector FROM hpc_common.users u JOIN inspections i ON u.id = i.user_id ORDER BY inspector")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $db_error_message = "خطای پایگاه داده. لطفا با مدیر سیستم تماس بگیرید. جزئیات فنی: " . $e->getMessage();
    error_log($db_error_message);
}


// Ensure this is set for all rows
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title><?php echo escapeHtml($pageTitle); ?></title>
    <link rel="icon" type="image/x-icon" href="/ghom/assets/images/favicon.ico" />
    <link rel="stylesheet" href="/ghom/assets/css/jalalidatepicker.min.css" />
    <link rel="stylesheet" href="/ghom/assets/css/formopen.css" />
    <script src="/ghom/assets/js/interact.min.js"></script>
    <style>
        @font-face {
            font-family: "Samim";
            src: url("/ghom/assets/fonts/Samim-FD.woff2") format("woff2");
        }

        /* All Professional CSS styles */
        body {
            font-family: "Samim", sans-serif;
            background-color: #f4f7f6;
            direction: rtl;
            margin: 0;
            padding: 20px;
            font-size: 14px;
        }

        .dashboard-container {
            max-width: 98%;
            margin: auto;
        }

        h1,
        h2 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .table-container {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .summary-box {
            background-color: #e9f5ff;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: bold;
            font-size: 0.9em;
        }

        .form-group input,
        .form-group select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            min-width: 200px;
        }

        .button-group {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .btn {
            border: none;
            padding: 9px 15px;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-viewer {
            background-color: #17a2b8;
            color: white;
        }

        .btn-viewer:hover {
            background-color: #138496;
        }

        .tab-buttons {
            display: flex;
            border-bottom: 2px solid #ccc;
            margin-bottom: -2px;
        }

        .tab-button {
            padding: 10px 20px;
            cursor: pointer;
            background: #eee;
            border: 1px solid #ccc;
            border-bottom: none;
            border-radius: 5px 5px 0 0;
        }

        .tab-button.active {
            background: #fff;
            border: 1px solid #ccc;
            border-bottom: 2px solid #fff;
            font-weight: bold;
            position: relative;
            z-index: 1;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px 8px;
            text-align: right;
            border-bottom: 1px solid #ddd;
            white-space: nowrap;
        }

        th {
            background-color: #f2f2f2;
            font-weight: bold;
            cursor: pointer;
        }

        .filter-row input {
            width: 100%;
            padding: 6px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 3px;
        }

        .results-info {
            margin: 15px 0;
            font-weight: bold;
        }

        .badge {
            background-color: #007bff;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8em;
            margin-right: 5px;
        }

        .status-ok-cell {
            background-color: #d4edda !important;
        }

        .status-ready-cell {
            background-color: #fff3cd !important;
        }

        .status-not-ok-cell {
            background-color: #f8d7da !important;
        }

        .action-btn {
            background-color: #007bff;
            color: white;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 4px;
        }

        .element-name-cell {
            cursor: pointer;
            color: #007bff;
        }

        .element-name-cell:hover {
            text-decoration: underline;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            border-radius: 8px;
            direction: rtl;
        }

        .close {
            color: #aaa;
            float: left;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }

        .sort-asc::after {
            content: " ↑";
        }

        .sort-desc::after {
            content: " ↓";
        }
    </style>
</head>

<body data-user-role="<?php echo escapeHtml($_SESSION['role']); ?>">

    <div class="dashboard-container">
        <h1><?php echo escapeHtml($pageTitle); ?></h1>

        <!-- ====================================================== -->
        <!-- 1. TOP SUMMARY BOX (As shown in your screenshot) -->
        <!-- ====================================================== -->
        <div class="table-container summary-box">
            <h2>خلاصه وضعیت و مشاهده در نقشه</h2>
            <div class="filters">
                <div class="form-group">
                    <label>۱. انتخاب فایل نقشه:</label>
                    <select id="report-zone-select">
                        <option value="">-- انتخاب کنید --</option>
                        <?php foreach ($all_zones as $zone): ?><option value="<?php echo escapeHtml($zone); ?>"><?php echo escapeHtml($zone); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>۲. مشاهده همه المان‌ها با وضعیت:</label>
                    <div class="button-group">
                        <button class="btn report-btn" data-status="Ready for Inspection" style="background-color: #b2dc35ff;">آماده بازرسی (<?php echo number_format($status_counts['Ready for Inspection']); ?>)</button>
                        <button class=" btn report-btn" data-status="Not OK" style="background-color: #dc3545;">رد شده (<?php echo number_format($status_counts['Not OK']); ?>)</button>
                        <button class="btn report-btn" data-status="OK" style="background-color: #28a745;">تایید شده (<?php echo number_format($status_counts['OK']); ?>)</button>
                        <button class="btn btn-viewer" id="open-viewer-btn">همه وضعیت‌ها</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ====================================================== -->
        <!-- 2. MAIN DASHBOARD VIEW WITH TABS AND TABLES -->
        <!-- ====================================================== -->
        <div id="dashboard-view">
            <div class="tab-buttons">
                <?php foreach ($data_by_type as $type => $inspections): if (empty($inspections)) continue; ?>
                    <button class="tab-button" data-tab="tab-<?php echo escapeHtml($type); ?>" data-element-type="<?php echo escapeHtml($type); ?>"><?php echo escapeHtml($type); ?><span class="badge"><?php echo count($inspections); ?></span></button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($data_by_type as $type => $inspections): if (empty($inspections)) continue; ?>
                <div id="tab-<?php echo escapeHtml($type); ?>" class="tab-content table-container" data-element-type="<?php echo escapeHtml($type); ?>">
                    <!-- ====================================================== -->
                    <!-- START: NEW, COMPLETE FILTER SECTION -->
                    <!-- ====================================================== -->
                    <div class="filters">
                        <div class="form-group">
                            <label>فیلتر مرحله:</label>
                            <select class="filter-select" data-column="3">
                                <option value="">همه</option>
                                <?php foreach ($all_stages as $stage): ?><option value="<?php echo escapeHtml($stage); ?>"><?php echo escapeHtml($stage); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>فیلتر پیمانکار:</label>
                            <select class="filter-select" data-column="5">
                                <option value="">همه</option>
                                <?php foreach ($all_contractors as $contractor): ?><option value="<?php echo escapeHtml($contractor); ?>"><?php echo escapeHtml($contractor); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>فیلتر بازرس:</label>
                            <select class="filter-select" data-column="6">
                                <option value="">همه</option>
                                <?php foreach ($all_inspectors as $inspector): ?><option value="<?php echo escapeHtml($inspector); ?>"><?php echo escapeHtml($inspector); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>از تاریخ پیمانکار:</label>
                            <input type="text" class="date-filter" data-column="7" data-type="start" data-jdp placeholder="انتخاب تاریخ">
                        </div>
                        <div class="form-group">
                            <label>تا تاریخ پیمانکار:</label>
                            <input type="text" class="date-filter" data-column="7" data-type="end" data-jdp placeholder="انتخاب تاریخ">
                        </div>
                        <div class="form-group"><label> </label><button class="btn clear-filters">پاک کردن فیلترها</button></div>
                    </div>
                    <!-- ====================================================== -->
                    <!-- END: NEW, COMPLETE FILTER SECTION -->
                    <!-- ====================================================== -->

                    <div class="results-info">نمایش <span class="visible-count"><?php echo count($inspections); ?></span> از <span class="total-count"><?php echo count($inspections); ?></span> رکورد</div>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th data-sort="element_name">نام المان</th>
                                    <th data-sort="axis_span">آدرس</th>
                                    <th data-sort="floor_level">تراز </th>
                                    <th data-sort="stage_name">مرحله</th>
                                    <th data-sort="status">وضعیت</th>
                                    <th data-sort="contractor">پیمانکار</th>
                                    <th data-sort="inspector_name">بازرس/ثبت کننده</th>
                                    <th data-sort="contractor_date">تاریخ پیمانکار</th>
                                    <th data-sort="inspection_date">تاریخ بازرسی</th>
                                    <th data-sort="zonename">زون</th>
                                    <th>عملیات</th>
                                </tr>
                                <tr class="filter-row">
                                    <td><input type="text" data-column="0" placeholder="فیلتر..."></td>
                                    <td><input type="text" data-column="1" placeholder="فیلتر..."></td>
                                    <td><input type="text" data-column="2" placeholder="فیلتر..."></td>
                                    <td><input type="text" data-column="3" placeholder="فیلتر..."></td>
                                    <td><input type="text" data-column="4" placeholder="فیلتر..."></td>
                                    <td><input type="text" data-column="5" placeholder="فیلتر..."></td>
                                    <td><input type="text" data-column="6" placeholder="فیلتر..."></td>
                                    <td><input type="text" data-column="7" placeholder="فیلتر..."></td>
                                    <td><input type="text" data-column="8" placeholder="فیلتر..."></td>
                                    <td><input type="text" data-column="9" placeholder="فیلتر..."></td>
                                    <td></td>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inspections as $row): ?>
                                    <?php if (!empty($row['inspection_date']) || !empty($row['contractor_date'])): ?>
                                        <?php
                                        $final_status_text = 'در حال اجرا';
                                        if ($row['overall_status'] === 'OK') $final_status_text = 'تایید شده';
                                        elseif ($row['overall_status'] === 'Not OK') $final_status_text = 'رد شده';
                                        elseif ($row['contractor_status'] === 'Ready for Inspection') $final_status_text = 'آماده بازرسی';

                                        // Create element name display
                                        $element_name = $row['element_id'];
                                        if (!empty($row['part_name'])) {
                                            $element_name .= ' - ' . $row['part_name'];
                                        }

                                        ?>
                                        <tr class="data-row <?php echo get_status_class($row); ?>">
                                            <td class="<?php echo !empty($row['notes']) ? 'element-name-cell' : ''; ?>"
                                                <?php if (!empty($row['notes'])): ?>
                                                data-notes="<?php echo escapeHtml($row['notes']); ?>"
                                                onclick="showNotesModal('<?php echo escapeHtml($element_name); ?>', '<?php echo escapeHtml($row['notes']); ?>')"
                                                <?php endif; ?>>
                                                <strong><?php echo escapeHtml($element_name); ?></strong>
                                                <?php if (!empty($row['notes'])): ?>
                                                    <span style="color: #17a2b8; font-size: 0.8em;"> (یادداشت موجود)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo escapeHtml($row['axis_span']); ?></td>


                                            <td><?php echo escapeHtml($row['floor_level']); ?></td>
                                            <td><?php echo escapeHtml($row['stage_name']); ?></td>
                                            <td><?php echo escapeHtml(data: $final_status_text); ?></td>
                                            <td><?php echo escapeHtml($row['contractor']); ?></td>
                                            <td><?php echo escapeHtml($row['inspector_name']); ?></td>
                                            <td data-timestamp="<?php echo $row['contractor_date'] ? strtotime($row['contractor_date']) : 0; ?>"
                                                data-persian-date="<?php echo $row['contractor_date'] ? jdate('Y/m/d', strtotime($row['contractor_date'])) : ''; ?>">
                                                <?php echo $row['contractor_date'] ? jdate('Y/m/d', strtotime($row['contractor_date'])) : '---'; ?>
                                            </td>
                                            <td data-timestamp="<?php echo $row['inspection_date'] ? strtotime($row['inspection_date']) : 0; ?>"
                                                data-persian-date="<?php echo $row['inspection_date'] ? jdate('Y/m/d', strtotime($row['inspection_date'])) : ''; ?>">
                                                <?php
                                                if (!empty($row['inspection_date']) && substr($row['inspection_date'], 0, 4) !== '0000') {
                                                    echo jdate('Y/m/d', strtotime($row['inspection_date']));
                                                } elseif ($row['contractor_status'] === 'Ready for Inspection' && $row['overall_status'] !== 'OK') {
                                                    $today = new DateTime();
                                                    $contractorDate = new DateTime($row['contractor_date']);
                                                    $interval = $today->diff($contractorDate);
                                                    echo '<span class="status-overdue">' . $interval->days . ' روز در انتظار</span>';
                                                } else {
                                                    echo '---';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                // Convert "Zone01.svg" to "زون 1"
                                                if (!empty($row['plan_file'])) {
                                                    if (preg_match('/Zone(\d+)/i', $row['plan_file'], $matches)) {
                                                        echo 'زون ' . intval($matches[1]);
                                                    } else {
                                                        echo escapeHtml($row['plan_file']);
                                                    }
                                                } else {
                                                    echo '---';
                                                }
                                                ?>
                                            </td>
                                            </td>
                                            <td>
                                                <a href="/ghom/view_element_history.php?element_id=<?php echo urlencode($row['element_id']); ?>&part_name=<?php echo urlencode($row['part_name'] ?? ''); ?>" class="btn action-btn" target="_blank">
                                                    مشاهده
                                                </a>
                                            </td>

                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Modal for displaying notes -->
    <div id="notesModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3 id="modalTitle">یادداشت المان</h3>
            <p id="modalNotes"></p>
        </div>
    </div>

    <?php require_once 'footer.php'; ?>

    <script src="/ghom/assets/js/jalalidatepicker.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            /**
             * NEW: Helper function to convert Persian numerals to Latin numerals.
             * This is essential for date comparisons to work.
             * @param {string} str The string with Persian numerals (e.g., "۱۴۰۳/۰۵/۱۰").
             * @returns {string} The string with Latin numerals (e.g., "1403/05/10").
             */
            function persianToLatinDigits(str) {
                if (!str) return '';
                const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
                const latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
                let result = String(str);
                for (let i = 0; i < 10; i++) {
                    result = result.replace(new RegExp(persian[i], 'g'), latin[i]);
                }
                return result;
            }

            // Initialize all date pickers on the page
            if (typeof jalaliDatepicker !== 'undefined') {
                jalaliDatepicker.startWatch({
                    persianDigits: true,
                    autoHide: true,
                    selector: '[data-jdp]'
                });
            }

            // Modal functionality
            const modal = document.getElementById('notesModal');
            const span = document.getElementsByClassName('close')[0];
            span.onclick = function() {
                modal.style.display = 'none';
            }
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            }

            // --- TAB SWITCHING LOGIC ---
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.addEventListener('click', () => {
                    document.querySelectorAll('.tab-button, .tab-content').forEach(el => el.classList.remove('active'));
                    button.classList.add('active');
                    document.getElementById(button.dataset.tab).classList.add('active');
                });
            });
            if (tabButtons.length > 0) tabButtons[0].click();

            // --- SETUP FILTERS AND SORTING FOR EVERY TABLE ON THE PAGE ---
            document.querySelectorAll('.data-table').forEach(table => {
                const tableContainer = table.closest('.table-container');
                const tbody = table.querySelector('tbody');

                const dropdownFilters = tableContainer.querySelectorAll('.filters .filter-select');
                const dateFilters = tableContainer.querySelectorAll('.filters .date-filter');
                const columnTextFilters = tableContainer.querySelectorAll('.filter-row input');
                const clearButton = tableContainer.querySelector('.clear-filters');
                const headers = tableContainer.querySelectorAll('thead th[data-sort]');

                const applyFilters = () => {
                    const dropdownValues = Array.from(dropdownFilters).map(s => ({
                        col: parseInt(s.dataset.column, 10),
                        val: s.value.toLowerCase()
                    }));
                    const columnValues = Array.from(columnTextFilters).map(i => ({
                        col: parseInt(i.dataset.column, 10),
                        val: i.value.toLowerCase().trim()
                    }));

                    const dateStartEl = tableContainer.querySelector('.date-filter[data-type="start"]');
                    const dateEndEl = tableContainer.querySelector('.date-filter[data-type="end"]');

                    // Get date values and CONVERT them to Latin digits
                    const latinDateStart = persianToLatinDigits(dateStartEl ? dateStartEl.value.trim() : '');
                    const latinDateEnd = persianToLatinDigits(dateEndEl ? dateEndEl.value.trim() : '');

                    // Now parse the Latin-digit strings
                    const startDateNum = latinDateStart ? parseInt(latinDateStart.replace(/\//g, ''), 10) : null;
                    const endDateNum = latinDateEnd ? parseInt(latinDateEnd.replace(/\//g, ''), 10) : null;
                    const dateColIndex = dateStartEl ? parseInt(dateStartEl.dataset.column, 10) : -1;

                    let visibleCount = 0;
                    tbody.querySelectorAll('tr.data-row').forEach(row => {
                        let isVisible = true;

                        // 1. Dropdown filters
                        for (const f of dropdownValues) {
                            if (f.val && row.cells[f.col].textContent.toLowerCase().trim() !== f.val) {
                                isVisible = false;
                                break;
                            }
                        }
                        if (!isVisible) {
                            row.style.display = 'none';
                            return;
                        }

                        // 2. Column text filters
                        for (const f of columnValues) {
                            if (f.val && !row.cells[f.col].textContent.toLowerCase().includes(f.val)) {
                                isVisible = false;
                                break;
                            }
                        }
                        if (!isVisible) {
                            row.style.display = 'none';
                            return;
                        }

                        // 3. Date range filter
                        if (dateColIndex !== -1 && (startDateNum || endDateNum)) {
                            // Get the cell's date and CONVERT it to Latin digits
                            const latinRowDate = persianToLatinDigits(row.cells[dateColIndex].dataset.persianDate || '');

                            if (latinRowDate) {
                                const rowDateNum = parseInt(latinRowDate.replace(/\//g, ''));
                                if (isNaN(rowDateNum)) {
                                    isVisible = false;
                                } else {
                                    if (startDateNum && rowDateNum < startDateNum) isVisible = false;
                                    if (endDateNum && rowDateNum > endDateNum) isVisible = false;
                                }
                            } else {
                                isVisible = false;
                            }
                        }

                        row.style.display = isVisible ? '' : 'none';
                        if (isVisible) visibleCount++;
                    });

                    tableContainer.querySelector('.visible-count').textContent = visibleCount;
                };

                // Attach Event Listeners
                dropdownFilters.forEach(input => input.addEventListener('change', applyFilters));
                dateFilters.forEach(input => input.addEventListener('change', applyFilters));
                columnTextFilters.forEach(input => input.addEventListener('input', applyFilters));
                clearButton.addEventListener('click', () => {
                    tableContainer.querySelectorAll('.filters select, .filters input, .filter-row input').forEach(input => {
                        input.value = '';
                    });
                    applyFilters();
                });

                // Attach event listener for sorting
                headers.forEach(header => {
                    header.addEventListener('click', () => {
                        const column = Array.from(header.parentNode.children).indexOf(header);
                        const isAsc = header.classList.contains('sort-asc');
                        const dir = isAsc ? -1 : 1;
                        const sortKey = header.dataset.sort;
                        const isDateSort = sortKey === 'contractor_date' || sortKey === 'inspection_date';

                        const rows = Array.from(tbody.querySelectorAll('tr.data-row'));

                        rows.sort((a, b) => {
                            let valA, valB;
                            const cellA = a.cells[column];
                            const cellB = b.cells[column];

                            if (isDateSort) {
                                valA = parseInt(cellA.dataset.timestamp || '0', 10);
                                valB = parseInt(cellB.dataset.timestamp || '0', 10);
                            } else {
                                valA = cellA.textContent.trim().toLowerCase();
                                valB = cellB.textContent.trim().toLowerCase();
                            }

                            if (valA < valB) return -1 * dir;
                            if (valA > valB) return 1 * dir;
                            return 0;
                        });

                        tbody.append(...rows);

                        headers.forEach(th => th.classList.remove('sort-asc', 'sort-desc'));
                        header.classList.toggle('sort-asc', !isAsc);
                        header.classList.toggle('sort-desc', isAsc);
                    });
                });
            });

            // --- PLAN VIEWER BUTTON LOGIC ---
            document.querySelectorAll('.report-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const planFile = document.getElementById('report-zone-select').value;
                    const status = this.dataset.status;
                    if (!planFile) {
                        alert('لطفا ابتدا فایل نقشه را انتخاب کنید');
                        return;
                    }
                    const url = `/ghom/viewer.php?plan=${encodeURIComponent(planFile)}&status=${encodeURIComponent(status)}`;
                    window.open(url, '_blank');
                });
            });

            document.getElementById('open-viewer-btn').addEventListener('click', function() {
                const planFile = document.getElementById('report-zone-select').value;
                if (!planFile) {
                    alert('لطفا ابتدا فایل نقشه را انتخاب کنید');
                    return;
                }
                const url = `/ghom/viewer.php?plan=${encodeURIComponent(planFile)}`;
                window.open(url, '_blank');
            });
        });

        // --- GLOBAL FUNCTIONS ---
        function showNotesModal(elementName, notes) {
            document.getElementById('modalTitle').textContent = 'یادداشت المان: ' + elementName;
            document.getElementById('modalNotes').textContent = notes;
            document.getElementById('notesModal').style.display = 'block';
        }
    </script>

</body>

</html>