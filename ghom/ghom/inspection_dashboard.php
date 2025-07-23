<?php
// public_html/ghom/inspection_dashboard.php (UPGRADED VERSION)
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
$pageTitle = "داشبورد جامع بازرسی";
require_once __DIR__ . '/header_ghom.php';

// Helper function for status-based coloring
function get_status_class($row)
{
    if ($row['overall_status'] === 'OK') return 'status-ok-cell';
    if ($row['overall_status'] === 'Not OK') return 'status-not-ok-cell';
    if ($row['contractor_status'] === 'Ready for Inspection') return 'status-ready-cell';
    return 'status-pending-cell';
}

try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->exec("SET NAMES 'utf8mb4'");

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
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title><?php echo escapeHtml($pageTitle); ?></title>
    <link rel="stylesheet" href="/ghom/assets/css/jalalidatepicker.min.css" />
    <style>
        /* All original styles are preserved... */
        @font-face {
            font-family: "Samim";
            src: url("/ghom/assets/fonts/Samim-FD.woff2") format("woff2");
        }

        body {
            font-family: "Samim", sans-serif;
            background-color: #f4f7f6;
            direction: rtl;
            margin: 0;
            padding: 20px;
        }

        .dashboard-container {
            max-width: 95%;
            margin: auto;
        }

        h1,
        h2 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .tab-buttons {
            display: flex;
            border-bottom: 2px solid #ccc;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab-button {
            padding: 10px 20px;
            cursor: pointer;
            background: #eee;
            border: 1px solid #ccc;
            border-bottom: none;
            margin-left: 5px;
            border-radius: 5px 5px 0 0;
        }

        .tab-button.active {
            background: #fff;
            border-bottom: 2px solid #fff;
            font-weight: bold;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .table-container {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .filters,
        .column-filters {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }

        .column-filters {
            background: #e9ecef;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            min-width: 150px;
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
            font-family: inherit;
            width: 100%;
            box-sizing: border-box;
        }

        .btn,
        .clear-filters {
            background: #007bff;
            color: white;
            border: none;
            padding: 9px 15px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn:hover {
            background: #0056b3;
        }

        .clear-filters {
            background: #dc3545;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid #ddd;
            font-size: 0.9em;
        }

        th {
            background-color: #f8f9fa;
            font-weight: bold;
            cursor: pointer;
            user-select: none;
            position: relative;
        }

        .view-form-link {
            cursor: pointer;
            color: #007bff;
            text-decoration: underline;
        }

        .badge {
            background-color: #007bff;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8em;
            margin-right: 5px;
        }

        .results-info {
            margin: 15px 0;
            font-weight: bold;
            color: #666;
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

        .ready-status-text {
            background-color: #fff3cd;
            color: #856404;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: bold;
            display: inline-block;
        }

        /* 2. NEW CSS FOR SORTING & DATE STATUS */
        th .sort-icon {
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .status-overdue {
            color: #dc3545;
            font-weight: bold;
        }

        .status-upcoming {
            color: #17a2b8;
            font-weight: bold;
        }

        /* Slide-out panel styles */
        #form-view-panel {
            position: fixed;
            top: 0;
            left: -820px;
            width: 800px;
            height: 100%;
            background: #f9f9f9;
            box-shadow: -3px 0 15px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            transition: left 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
        }

        #form-view-panel.open {
            left: 0;
        }

        #form-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 999;
            display: none;
        }

        #form-overlay.open {
            display: block;
        }

        .form-header {
            padding: 10px 15px;
            background: #0056b3;
            color: white;
        }

        .form-content {
            padding: 15px;
            overflow-y: auto;
            flex-grow: 1;
        }

        .btn-container {
            text-align: left;
            padding: 15px;
            background: #f1f1f1;
            border-top: 1px solid #ccc;
        }

        .form-stage {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .form-stage:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 5px;
        }

        .stage-title {
            font-size: 1.2em;
            color: #0056b3;
            margin-bottom: 20px;
            padding-right: 12px;
            border-right: 4px solid #007bff;
        }

        .checklist-item-row {
            margin-bottom: 18px;
            padding-right: 5px;
        }

        .checklist-item-row label.item-text {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .checklist-item-row .status-selector {
            display: flex;
            gap: 20px;
            margin-bottom: 8px;
        }

        .checklist-item-row .checklist-input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
    </style>
</head>

<body data-user-role="<?php echo escapeHtml($_SESSION['role']); ?>">
    <div class="dashboard-container">
        <h1><?php echo escapeHtml($pageTitle); ?></h1>

        <div class="table-container" style="margin-bottom: 20px; background-color: #e9f5ff;">
            <h2>مشاهده وضعیت کلی در نقشه</h2>
            <div class="filters">
                <div class="form-group"><label>1. انتخاب فایل نقشه:</label><select id="report-zone-select"><?php foreach ($all_zones as $zone): ?><option value="<?php echo escapeHtml($zone); ?>"><?php echo escapeHtml($zone); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>2. مشاهده همه المان‌ها با وضعیت:</label>
                    <button type="button" class="btn report-btn" data-status="Ready for Inspection">آماده بازرسی (<?php echo number_format($readyfi); ?>)</button>
                    <button type="button" class="btn report-btn" data-status="Not OK" style="background-color: #dc3545;">رد شده (<?php echo number_format($readyno); ?>)</button>
                    <button type="button" class="btn report-btn" data-status="OK" style="background-color: #28a745;">تایید شده (<?php echo number_format($readyok); ?>)</button>
                    <button type="button" class="btn report-btn" data-status="Pending" style="background-color:rgb(85, 40, 167);">قطعی نشده (<?php echo number_format($readypen); ?>)</button>
                    <button type="button" class="btn report-btn" data-status="all" style="background-color: #17a2b8;">همه وضعیت‌ها (<?php echo $global_status_counts['Total']; ?>)</button>
                </div>
            </div>
        </div>

        <div id="dashboard-view">
            <div class="tab-buttons">
                <?php foreach ($data_by_type as $type => $inspections): if (empty($inspections)) continue; ?>
                    <button class="tab-button" data-tab="tab-<?php echo escapeHtml($type); ?>"><?php echo escapeHtml($type); ?><span class="badge"><?php echo count($inspections); ?></span></button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($data_by_type as $type => $inspections): if (empty($inspections)) continue; ?>
                <div id="tab-<?php echo escapeHtml($type); ?>" class="tab-content table-container">
                    <h2>لیست بازرسی‌های <?php echo escapeHtml($type); ?></h2>

                    <div class="filters">
                        <div class="form-group"><label>جستجوی کلی:</label><input type="text" class="search-input" placeholder="جستجو در تمام ستون‌ها..."></div>
                        <div class="form-group"><label>تاریخ از:</label><input type="text" class="date-start" data-jdp></div>
                        <div class="form-group"><label>تاریخ تا:</label><input type="text" class="date-end" data-jdp></div>
                        <div class="form-group"><label> </label><button type="button" class="clear-filters">پاک کردن فیلترها</button></div>
                    </div>
                    <div class="column-filters">
                        <div class="form-group"><label>فیلتر کد المان:</label><input type="text" class="filter-input" data-column="0"></div>
                        <div class="form-group"><label>فیلتر بخش:</label><input type="text" class="filter-input" data-column="1"></div>
                        <div class="form-group"><label>فیلتر وضعیت:</label>
                            <select class="filter-select" data-column="3">
                                <option value="">همه</option>
                                <option value="Ready for Inspection">Ready for Inspection</option>
                                <option value="OK">OK</option>
                                <option value="Not OK">Not OK</option>
                                <option value="Pending">Pending</option>
                            </select>
                        </div>
                    </div>

                    <div class="results-info">نمایش <span class="visible-count">0</span> از <span class="total-count"><?php echo count($inspections); ?></span> رکورد</div>

                    <table class="data-table sortable">
                        <thead>
                            <tr>
                                <th data-sort="element_id">کد المان</th>
                                <th data-sort="part_name">بخش بازرسی</th>
                                <th data-sort="plan_file">فایل نقشه</th>
                                <th data-sort="status">وضعیت نهایی</th>
                                <th data-sort="contractor_date">تاریخ اعلام پیمانکار</th>
                                <th data-sort="inspection_date">تاریخ بازرسی</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inspections as $row): ?>
                                <?php
                                $final_status = 'Pending';
                                if (!empty($row['overall_status'])) $final_status = $row['overall_status'];
                                elseif ($row['contractor_status'] === 'Ready for Inspection') $final_status = 'Ready for Inspection';
                                ?>
                                <tr class="data-row <?php echo get_status_class($row); ?>"
                                    data-element-id="<?php echo escapeHtml($row['element_id']); ?>"
                                    data-part-name="<?php echo escapeHtml($row['part_name']); ?>"
                                    data-element-type="<?php echo escapeHtml($row['element_type']); ?>"
                                    data-status="<?php echo escapeHtml($final_status); ?>"
                                    data-contractor-date-timestamp="<?php echo $row['contractor_date'] ? strtotime($row['contractor_date']) : '0'; ?>"
                                    data-inspection-date-timestamp="<?php echo $row['inspection_date'] ? strtotime($row['inspection_date']) : '0'; ?>"
                                    data-inspection-data='<?php echo json_encode($row, JSON_UNESCAPED_UNICODE); ?>'>

                                    <td><?php echo escapeHtml($row['element_id']); ?></td>
                                    <td><strong><?php echo escapeHtml($row['part_name']); ?></strong></td>
                                    <td><?php echo escapeHtml($row['plan_file']); ?></td>
                                    <td><span class="<?php echo $row['contractor_status'] === 'Ready for Inspection' ? 'ready-status-text' : ''; ?>"><?php echo escapeHtml($final_status); ?></span></td>
                                    <td><?php echo $row['contractor_date'] ? jdate('Y/m/d', strtotime($row['contractor_date'])) : '---'; ?></td>
                                    <td>
                                        <?php
                                        if (!empty($row['inspection_date']) && $row['inspection_date'] !== '0000-00-00 00:00:00') {
                                            echo jdate('Y/m/d', strtotime($row['inspection_date']));
                                        } elseif (!empty($row['contractor_date']) && $row['contractor_date'] !== '0000-00-00 00:00:00') {
                                            $today = new DateTime();
                                            $contractorDate = new DateTime($row['contractor_date']);
                                            $interval = $today->diff($contractorDate);
                                            $days_diff = $interval->days;

                                            if ($today > $contractorDate) {
                                                echo '<span class="status-overdue">بازرسی نشده (' . $days_diff . ' روز تاخیر)</span>';
                                            } else {
                                                echo '<span class="status-upcoming">' . ($days_diff + 1) . ' روز تا بازرسی</span>';
                                            }
                                        } else {
                                            echo '---';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <a href="/ghom/view_element_history.php?element_id=<?php echo urlencode($row['element_id']); ?>&part_name=<?php echo urlencode($row['part_name'] ?? 'N/A'); ?>" target="_blank">مشاهده سابقه</a>

                                        <?php if (!empty($row['plan_file'])): ?>
                                            | <a href="/ghom/viewer.php?plan=<?php echo urlencode(basename($row['plan_file'] ?? '')); ?>" target="_blank">مشاهده نقشه</a>

                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="form-overlay"></div>
    <div id="form-view-panel"></div>



    <script src="/ghom/assets/js/jalalidatepicker.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- GLOBAL VARIABLES ---
            const USER_ROLE = document.body.dataset.userRole;
            const formViewPanel = document.getElementById('form-view-panel');
            const formOverlay = document.getElementById('form-overlay');

            // --- INITIALIZE JALALI DATE PICKER ---
            if (typeof jalaliDatepicker !== 'undefined') {
                jalaliDatepicker.startWatch({
                    persianDigits: true,
                    autoHide: true,
                    selector: '[data-jdp]'
                });
            }

            // --- HELPER FUNCTIONS ---
            function jdate(format, timestamp) {
                if (!timestamp) return '';
                return new Intl.DateTimeFormat('fa-IR-u-nu-latn', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit'
                }).format(new Date(timestamp * 1000));
            }

            // --- FORM DISPLAY & HANDLING ---
            function showFormPanel() {
                formViewPanel.classList.add('open');
                formOverlay.classList.add('open');
            }

            function hideFormPanel() {
                formViewPanel.classList.remove('open');
                formOverlay.classList.remove('open');
            }

            function setFormPermissions(formEl, role) {
                const consultantSection = formEl.querySelector('.consultant-section');
                const contractorSection = formEl.querySelector('.contractor-section');
                const saveBtn = formViewPanel.querySelector('.btn.save');

                if (consultantSection) consultantSection.disabled = true;
                if (contractorSection) contractorSection.disabled = true;
                if (saveBtn) saveBtn.style.display = 'none';

                if (role === 'admin') {
                    if (consultantSection) consultantSection.disabled = false;
                    if (saveBtn) saveBtn.style.display = 'inline-block';
                } else if (role === 'supervisor') {
                    if (contractorSection) contractorSection.disabled = false;
                    if (saveBtn) saveBtn.style.display = 'inline-block';
                }
            }

            async function openChecklistForm(elementId, elementType, partName, inspectionData) {
                formViewPanel.innerHTML = '<div style="padding: 20px; text-align: center;">در حال بارگذاری فرم...</div>';
                showFormPanel();

                try {
                    const templateType = (elementType === 'GFRC') ? elementType : 'Curtainwall';
                    const response = await fetch(`/ghom/api/get_element_data.php?element_id=${encodeURIComponent(elementId)}&part_name=${encodeURIComponent(partName)}&element_type=${templateType}`);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);

                    const apiData = await response.json();
                    if (apiData.error) throw new Error(apiData.error);

                    let formChecklistHTML = '<p>قالب چک لیست برای این نوع المان یافت نشد.</p>';

                    if (apiData.items && apiData.items.length > 0) {
                        if (elementType === 'GFRC') {
                            // Vertical GFRC Form with Stage separators
                            const stages = [{
                                    title: 'مرحله ۱: رویت',
                                    count: 6
                                }, {
                                    title: 'مرحله ۲: عایق',
                                    count: 4
                                },
                                {
                                    title: 'مرحله ۳: مختصات',
                                    count: 2
                                }, {
                                    title: 'مرحله ۴: زیرسازی',
                                    count: 3
                                },
                                {
                                    title: 'مرحله ۵: اتصالات',
                                    count: 5
                                }, {
                                    title: 'مرحله ۶: تحویل',
                                    count: 2
                                }
                            ];
                            let itemIndex = 0;
                            formChecklistHTML = stages.map(stage => {
                                const stageItems = apiData.items.slice(itemIndex, itemIndex + stage.count);
                                itemIndex += stage.count;
                                const itemsHTML = stageItems.map(item => `
                            <div class="checklist-item-row">
                                <label class="item-text">${item.item_text}</label>
                                <div class="status-selector">
                                    <label><input type="radio" name="status_${item.item_id}" value="OK" ${item.item_status === 'OK' ? 'checked' : ''}> OK</label>
                                    <label><input type="radio" name="status_${item.item_id}" value="Not OK" ${item.item_status === 'Not OK' ? 'checked' : ''}> NOK</label>
                                    <label><input type="radio" name="status_${item.item_id}" value="N/A" ${item.item_status === 'N/A' || !item.item_status ? 'checked' : ''}> N/A</label>
                                </div>
                                <input type="text" class="checklist-input" data-item-id="${item.item_id}" value="${item.item_value || ''}" placeholder="مقدار...">
                            </div>`).join('');
                                return `<div class="form-stage"><h4 class="stage-title">${stage.title}</h4>${itemsHTML}</div>`;
                            }).join('');
                        } else {
                            // NEW: Simple Vertical Form for all other types (replaces complex table)
                            formChecklistHTML = apiData.items.map(item => `
                        <div class="checklist-item-row">
                            <label class="item-text">${item.item_text}</label>
                            <div class="status-selector">
                                <label><input type="radio" name="status_${item.item_id}" value="OK" ${item.item_status === 'OK' ? 'checked' : ''}> OK</label>
                                <label><input type="radio" name="status_${item.item_id}" value="Not OK" ${item.item_status === 'Not OK' ? 'checked' : ''}> NOK</label>
                                <label><input type="radio" name="status_${item.item_id}" value="N/A" ${item.item_status === 'N/A' || !item.item_status ? 'checked' : ''}> N/A</label>
                            </div>
                            <input type="text" class="checklist-input" data-item-id="${item.item_id}" value="${item.item_value || ''}" placeholder="مقدار...">
                        </div>`).join('');
                        }
                    }

                    formViewPanel.innerHTML = `
                <div class="form-header"><h3>فرم بازرسی: ${elementId} (${partName})</h3></div>
                <div class="form-content">
                    <form id="checklist-form" novalidate>
                        <input type="hidden" name="element_id" value="${elementId}">
                        <input type="hidden" name="part_name" value="${partName}">
                        <fieldset class="consultant-section">
                            <legend>بخش مشاور</legend>
                           <div class="form-group">
  <label>تاریخ بازرسی:</label>
  <input type="text" name="inspection_date" data-jdp 
    value="${
      inspectionData.inspection_date && inspectionData.inspection_date !== '0000-00-00 00:00:00'
        ? jdate('Y/m/d', new Date(inspectionData.inspection_date).getTime() / 1000)
        : '---'
    }">
</div>
                            <div class="form-group"><label>وضعیت کلی:</label><select name="overall_status"><option value="" ${!inspectionData.overall_status ? 'selected' : ''}>--</option><option value="OK" ${inspectionData.overall_status === 'OK' ? 'selected' : ''}>OK</option><option value="Not OK" ${inspectionData.overall_status === 'Not OK' ? 'selected' : ''}>Not OK</option></select></div>
                            ${formChecklistHTML}
                            <div class="form-group"><label>یادداشت مشاور:</label><textarea name="notes">${inspectionData.consultant_notes || ''}</textarea></div>
                            <div class="form-group"><label>پیوست‌ها:</label><input type="file" name="attachments[]" multiple></div>
                        </fieldset>
                        <fieldset class="contractor-section">
                            <legend>بخش پیمانکار</legend>
                             <div class="form-group"><label>وضعیت:</label><select name="contractor_status"><option value="Pending" ${inspectionData.contractor_status === 'Pending' || !inspectionData.contractor_status ? 'selected' : ''}>در انتظار</option><option value="Ready for Inspection" ${inspectionData.contractor_status === 'Ready for Inspection' ? 'selected' : ''}>آماده بازرسی</option></select></div>
                             <div class="form-group"><label>تاریخ اعلام:</label><input type="text" name="contractor_date" data-jdp value="${inspectionData.contractor_date ? jdate('Y/m/d', new Date(inspectionData.contractor_date).getTime() / 1000) : ''}"></div>
                             <div class="form-group"><label>یادداشت پیمانکار:</label><textarea name="contractor_notes">${inspectionData.contractor_notes || ''}</textarea></div>
                             <div class="form-group"><label>پیوست‌ها:</label><input type="file" name="contractor_attachments[]" multiple></div>
                        </fieldset>
                    </form>
                </div>
                <div class="btn-container"><button type="submit" form="checklist-form" class="btn save">ذخیره</button><button type="button" class="btn cancel">بستن</button></div>
            `;

                    const formEl = document.getElementById('checklist-form');
                    setFormPermissions(formEl, USER_ROLE);
                    if (typeof jalaliDatepicker !== 'undefined') {
                        jalaliDatepicker.startWatch({
                            selector: '#form-view-panel [data-jdp]'
                        });
                    }
                    formViewPanel.querySelector('.btn.cancel').addEventListener('click', hideFormPanel);
                    formEl.addEventListener('submit', handleFormSubmit);

                } catch (error) {
                    formViewPanel.innerHTML = `<div class="form-content"><p style="color:red;">خطا در بارگزاری فرم: ${error.message}</p></div><div class="btn-container"><button type="button" class="btn cancel">بستن</button></div>`;
                    formViewPanel.querySelector('.btn.cancel').addEventListener('click', hideFormPanel);
                }
            }

            async function handleFormSubmit(event) {
                event.preventDefault();
                const form = event.target;
                const saveBtn = formViewPanel.querySelector('.btn.save');
                saveBtn.textContent = 'در حال ذخیره...';
                saveBtn.disabled = true;

                try {
                    // This creates a bundle of the main form data
                    const formData = new FormData(form);

                    // --- THIS IS THE CRITICAL FIX ---
                    // We must manually gather the checklist data and add it to the bundle.
                    const itemsPayload = [];
                    form.querySelectorAll('.checklist-input').forEach(input => {
                        const itemId = input.dataset.itemId;
                        if (itemId) {
                            const statusRadio = form.querySelector(`input[name="status_${itemId}"]:checked`);
                            itemsPayload.push({
                                itemId: itemId,
                                status: statusRadio ? statusRadio.value : 'N/A',
                                value: input.value
                            });
                        }
                    });
                    // Add the checklist items to the form data as a JSON string
                    formData.append('items', JSON.stringify(itemsPayload));
                    // --- END OF FIX ---

                    const response = await fetch('/ghom/api/save_inspection_from_dashboard.php', {
                        method: 'POST',
                        body: formData
                    });

                    const resultText = await response.text();
                    const result = JSON.parse(resultText);

                    if (result.status === 'success') {
                        alert(result.message);
                        hideFormPanel();
                        location.reload(); // Refresh the dashboard to show new data
                    } else {
                        throw new Error(result.message || 'An unknown error occurred.');
                    }
                } catch (error) {
                    alert('خطا در ذخیره‌سازی: ' + error.message);
                } finally {
                    saveBtn.textContent = 'ذخیره';
                    saveBtn.disabled = false;
                }
            }

            // --- TABLE SORTING LOGIC ---
            function sortTableByColumn(table, column, asc = true) {
                const dirModifier = asc ? 1 : -1;
                const tBody = table.tBodies[0];
                const rows = Array.from(tBody.querySelectorAll("tr"));
                const sortedRows = rows.sort((a, b) => {
                    const header = table.querySelector(`th:nth-child(${column + 1})`);
                    const sortKey = header.dataset.sort;
                    let aColText, bColText;
                    if (sortKey === 'contractor_date' || sortKey === 'inspection_date') {
                        aColText = a.dataset[sortKey + 'Timestamp'] || 0;
                        bColText = b.dataset[sortKey + 'Timestamp'] || 0;
                    } else {
                        aColText = a.querySelector(`td:nth-child(${column + 1})`).textContent.trim();
                        bColText = b.querySelector(`td:nth-child(${column + 1})`).textContent.trim();
                    }
                    return isNaN(Number(aColText)) || isNaN(Number(bColText)) ?
                        aColText.localeCompare(bColText, 'fa') * dirModifier :
                        (Number(aColText) - Number(bColText)) * dirModifier;
                });
                tBody.append(...sortedRows);
            }

            // --- FILTERING LOGIC ---
            function updateTableCounts(tabContentElement) {
                const resultsInfo = tabContentElement.querySelector('.results-info');
                if (!resultsInfo) return;
                const visibleCountSpan = resultsInfo.querySelector('.visible-count');
                const visibleRowsCount = Array.from(tabContentElement.querySelectorAll('.data-table tbody .data-row')).filter(row => row.style.display !== 'none').length;
                visibleCountSpan.textContent = visibleRowsCount;
            }

            function applyAllFilters(tabContentElement) {
                const searchInput = tabContentElement.querySelector('.search-input').value.toLowerCase();
                const dateStartInput = tabContentElement.querySelector('.date-start');
                const dateEndInput = tabContentElement.querySelector('.date-end');
                const columnFilters = Array.from(tabContentElement.querySelectorAll('.filter-input, .filter-select'));
                const dateStart = (dateStartInput.value && dateStartInput.datepicker && dateStartInput.datepicker.gDate) ? new Date(dateStartInput.datepicker.gDate).getTime() / 1000 : 0;
                const dateEnd = (dateEndInput.value && dateEndInput.datepicker && dateEndInput.datepicker.gDate) ? new Date(dateEndInput.datepicker.gDate).setHours(23, 59, 59, 999) / 1000 : Infinity;
                const filters = columnFilters.map(input => ({
                    column: input.dataset.column,
                    value: input.value.toLowerCase()
                })).filter(f => f.value !== '');

                tabContentElement.querySelectorAll('.data-table tbody .data-row').forEach(row => {
                    let isVisible = true;
                    if (searchInput && !row.textContent.toLowerCase().includes(searchInput)) {
                        isVisible = false;
                    }
                    if (isVisible && (dateStart > 0 || dateEnd !== Infinity)) {
                        const contractorTimestamp = parseInt(row.dataset.contractorDateTimestamp, 10) || 0;
                        const inspectionTimestamp = parseInt(row.dataset.inspectionDateTimestamp, 10) || 0;
                        if (!((contractorTimestamp >= dateStart && contractorTimestamp <= dateEnd) || (inspectionTimestamp >= dateStart && inspectionTimestamp <= dateEnd))) {
                            isVisible = false;
                        }
                    }
                    if (isVisible) {
                        for (const filter of filters) {
                            let cellValue;
                            if (filter.column === '3') { // Status column
                                cellValue = row.dataset.status.toLowerCase();
                            } else {
                                const colIndex = parseInt(filter.column, 10);
                                cellValue = row.cells[colIndex]?.textContent.toLowerCase() || '';
                            }
                            if (!cellValue.includes(filter.value)) {
                                isVisible = false;
                                break;
                            }
                        }
                    }
                    row.style.display = isVisible ? '' : 'none';
                });
                updateTableCounts(tabContentElement);
            }

            // --- ALL EVENT LISTENERS ---
            document.addEventListener('click', function(e) {
                if (e.target.closest('.view-form-link')) {
                    e.preventDefault();
                    const row = e.target.closest('.data-row');
                    if (row) {
                        const {
                            elementId,
                            elementType,
                            partName,
                            inspectionData
                        } = row.dataset;
                        openChecklistForm(elementId, elementType, partName, JSON.parse(inspectionData));
                    }
                }
            });

            formOverlay.addEventListener('click', hideFormPanel);

            document.querySelectorAll('.tab-button').forEach(button => {
                button.addEventListener('click', () => {
                    document.querySelectorAll('.tab-button, .tab-content').forEach(el => el.classList.remove('active'));
                    button.classList.add('active');
                    document.getElementById(button.dataset.tab).classList.add('active');
                });
            });
            if (document.querySelectorAll('.tab-button').length > 0) document.querySelectorAll('.tab-button')[0].click();

            document.querySelectorAll('.sortable th[data-sort]').forEach(headerCell => {
                headerCell.innerHTML += '<span class="sort-icon">&nbsp;</span>';
                headerCell.addEventListener('click', () => {
                    const tableElement = headerCell.closest('table');
                    const headerIndex = Array.from(headerCell.parentElement.children).indexOf(headerCell);
                    const currentIsAsc = headerCell.classList.contains('sorted-asc');
                    tableElement.querySelectorAll('th').forEach(th => {
                        th.classList.remove('sorted-asc', 'sorted-desc');
                        if (th.querySelector('.sort-icon')) th.querySelector('.sort-icon').textContent = '\u00A0';
                    });
                    if (currentIsAsc) {
                        headerCell.classList.add('sorted-desc');
                        headerCell.querySelector('.sort-icon').textContent = '↓';
                        sortTableByColumn(tableElement, headerIndex, false);
                    } else {
                        headerCell.classList.add('sorted-asc');
                        headerCell.querySelector('.sort-icon').textContent = '↑';
                        sortTableByColumn(tableElement, headerIndex, true);
                    }
                });
            });

            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.querySelectorAll('.search-input, .filter-input').forEach(input => {
                    input.addEventListener('input', () => applyAllFilters(tab));
                });
                tab.querySelectorAll('.filter-select, .date-start, .date-end').forEach(input => {
                    input.addEventListener('change', () => applyAllFilters(tab));
                });
                tab.querySelector('.clear-filters').addEventListener('click', () => {
                    tab.querySelectorAll('input, select').forEach(input => input.value = '');
                    tab.querySelectorAll('[data-jdp]').forEach(dateInput => {
                        if (dateInput.datepicker) dateInput.datepicker.clear();
                    });
                    applyAllFilters(tab);
                });
                updateTableCounts(tab);
            });

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