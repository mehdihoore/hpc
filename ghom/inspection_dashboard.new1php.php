<?php
// public_html/ghom/inspection_dashboard.php (FINAL, ALL-IN-ONE VERSION)
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once __DIR__ . '/includes/jdf.php';
secureSession();

if (!in_array($_SESSION['role'], ['admin', 'supervisor', 'contractor', 'consultant'])) {
    http_response_code(403);
    die("Access Denied.");
}
$pageTitle = "داشبورد جامع بازرسی";
require_once __DIR__ . '/header_ghom.php';

// Helper function for status-based coloring of table rows
function get_status_class($row)
{
    if (!empty($row['overall_status'])) {
        if ($row['overall_status'] === 'OK') return 'status-ok-cell';
        if ($row['overall_status'] === 'Not OK') return 'status-not-ok-cell';
    }
    if ($row['contractor_status'] === 'Ready for Inspection') {
        return 'status-ready-cell';
    }
    return 'status-pending-cell';
}

try {
    $pdo = getProjectDBConnection('ghom');
    $all_zones_stmt = $pdo->query("SELECT DISTINCT plan_file FROM elements WHERE plan_file IS NOT NULL AND plan_file != '' ORDER BY plan_file");
    $all_zones = $all_zones_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch all necessary data for both the table and the forms
    $all_data_stmt = $pdo->query("
    SELECT
        e.element_id, e.element_type, e.plan_file, e.zone_name, e.axis_span, e.floor_level, e.contractor, e.block,
        -- Get the most RECENT contractor status and date for each element
        MAX(i.contractor_status) as contractor_status,
        MAX(i.contractor_date) as contractor_date,
        MAX(i.contractor_notes) as contractor_notes,
        -- Get the final OVERALL status based on a hierarchy (Not OK > OK)
        CASE
            WHEN MAX(CASE WHEN i.overall_status = 'Not OK' THEN 1 ELSE 0 END) = 1 THEN 'Not OK'
            WHEN MAX(CASE WHEN i.overall_status = 'OK' THEN 1 ELSE 0 END) = 1 THEN 'OK'
            ELSE NULL
        END as overall_status,
        MAX(i.inspection_date) as inspection_date,
        MAX(i.notes) as consultant_notes,
        MAX(i.attachments) as attachments
    FROM elements e
    LEFT JOIN inspections i ON i.element_id LIKE CONCAT(e.element_id, '%')
    GROUP BY e.element_id, e.element_type, e.plan_file, e.zone_name, e.axis_span, e.floor_level, e.contractor, e.block
    ORDER BY e.element_type, e.plan_file, e.element_id
");

    $data_by_type = [];
    while ($row = $all_data_stmt->fetch(PDO::FETCH_ASSOC)) {
        $data_by_type[$row['element_type']][$row['element_id']] = $row;
    }
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}
$status_counts = [];
foreach ($data_by_type as $type => $elements) {
    $counts = [
        'Ready for Inspection' => 0,
        'OK' => 0,
        'Not OK' => 0,
        'Pending' => 0,
        'plan_file' => '' // Store a sample plan file for the links
    ];
    if (!empty($elements)) {
        // Get a sample plan file from the first element of this type
        $counts['plan_file'] = reset($elements)['plan_file'];
    }
    foreach ($elements as $element) {
        $status = 'Pending'; // Default
        if (!empty($element['overall_status'])) {
            $status = $element['overall_status'];
        } elseif ($element['contractor_status'] === 'Ready for Inspection') {
            $status = 'Ready for Inspection';
        }
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }
    $status_counts[$type] = $counts;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title><?php echo escapeHtml($pageTitle); ?></title>
    <link rel="stylesheet" href="/ghom/assets/css/jalalidatepicker.min.css" />
    <style>
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
            transition: margin-left 0.3s;
        }

        .dashboard-container.form-open {
            margin-left: 470px;
        }

        h1,
        h2,
        h3 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        h3 {
            border-bottom-width: 1px;
            font-size: 1.1em;
        }

        .hidden {
            display: none !important;
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
            margin-bottom: 15px;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: bold;
            font-size: 0.9em;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9em;
            font-family: "Samim", sans-serif;
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
            font-size: 0.9em;
            transition: background-color 0.2s;
        }

        .btn:hover,
        .clear-filters:hover {
            background: #0056b3;
        }

        .btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }

        .clear-filters {
            background: #dc3545;
        }

        .clear-filters:hover {
            background: #c82333;
        }

        .btn.cancel {
            background: #6c757d;
        }

        .btn.cancel:hover {
            background: #5a6268;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px;
            text-align: right;
            border-bottom: 1px solid #ddd;
            font-size: 0.9em;
        }

        th {
            background-color: #f8f9fa;
            font-weight: bold;
            cursor: pointer;
            user-select: none;
        }

        th.sorted-asc::after {
            content: " ↑";
        }

        th.sorted-desc::after {
            content: " ↓";
        }

        .view-form-link {
            cursor: pointer;
            color: #007bff;
            text-decoration: underline;
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

        .status-pending-cell {}

        .ready-status-text {
            background-color: #fff3cd;
            color: #856404;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: bold;
            display: inline-block;
        }

        .badge {
            background-color: #dc3545;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8em;
            margin-right: 5px;
            vertical-align: middle;
        }

        #form-view-panel {
            position: fixed;
            top: 0;
            left: -470px;
            width: 450px;
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

        .form-header h3 {
            color: white;
            border: none;
            margin: 0;
            font-size: 1.2em;
        }

        .form-content {
            padding: 15px;
            overflow-y: auto;
            flex-grow: 1;
        }

        .form-content fieldset {
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 15px;
        }

        .form-content fieldset:disabled {
            background-color: #e9ecef;
            opacity: 0.7;
        }

        .form-content legend {
            font-weight: bold;
            color: #0056b3;
        }

        .btn-container {
            text-align: left;
            padding: 15px;
            background: #f1f1f1;
            border-top: 1px solid #ccc;
        }

        .results-info {
            margin: 15px 0;
            font-weight: bold;
            color: #666;
        }

        .date-debug {
            font-size: 0.75em;
            color: #6c757d;
            margin-top: 4px;
            direction: ltr;
        }
    </style>
</head>

<body data-user-role="<?php echo escapeHtml($_SESSION['role']); ?>">
    <div class="dashboard-container">
        <h1><?php echo escapeHtml($pageTitle); ?></h1>
        <div class="table-container" style="margin-bottom: 20px; background-color: #e9f5ff;">
            <h2>مشاهده وضعیت کلی در نقشه</h2>
            <div class="filters">
                <div class="form-group">
                    <label for="report-zone-select">1. انتخاب فایل نقشه:</label>
                    <select id="report-zone-select">
                        <?php foreach ($all_zones as $zone): ?>
                            <option value="<?php echo escapeHtml($zone); ?>"><?php echo escapeHtml($zone); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>2. مشاهده همه المان‌ها با وضعیت:</label>
                    <button type="button" class="btn report-btn" data-status="Ready for Inspection">آماده بازرسی</button>
                    <button type="button" class="btn report-btn" data-status="Not OK" style="background-color: #dc3545;">رد شده (Not OK)</button>
                    <button type="button" class="btn report-btn" data-status="OK" style="background-color: #28a745;">تایید شده (OK)</button>
                </div>
            </div>
        </div>
        <div id="dashboard-view">
            <div class="tab-buttons">
                <?php foreach ($data_by_type as $type => $elements_data): ?>
                    <button class="tab-button" data-tab="tab-<?php echo escapeHtml($type); ?>">
                        <?php echo escapeHtml($type); ?>
                        <span class="badge">
                            <?php echo count(array_filter($elements_data, fn($row) => $row['contractor_status'] === 'Ready for Inspection' && $row['overall_status'] !== 'OK')); ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($data_by_type as $type => $elements_data): ?>
                <div id="tab-<?php echo escapeHtml($type); ?>" class="tab-content table-container">
                    <h2>لیست المان‌های <?php echo escapeHtml($type); ?></h2>
                    <div class="quick-filters">
                        <strong>مشاهده در نقشه:</strong>
                        <?php
                        $plan = $status_counts[$type]['plan_file'];
                        if (!empty($plan)) {
                            $base_url = "/ghom/viewer.php?plan=" . urlencode($plan) . "&type=" . urlencode($type);
                            echo '<a href="' . $base_url . '&status=Ready for Inspection" target="_blank" class="btn">آماده بازرسی <span class="badge">' . $status_counts[$type]['Ready for Inspection'] . '</span></a>';
                            echo '<a href="' . $base_url . '&status=OK" target="_blank" class="btn" style="background-color:#28a745;">OK <span class="badge">' . $status_counts[$type]['OK'] . '</span></a>';
                            echo '<a href="' . $base_url . '&status=Not OK" target="_blank" class="btn" style="background-color:#dc3545;">Not OK <span class="badge">' . $status_counts[$type]['Not OK'] . '</span></a>';
                        } else {
                            echo "<span>(نقشه‌ای برای این المان‌ها ثبت نشده)</span>";
                        }
                        ?>
                    </div>
                    <div class="filters">
                        <div class="form-group"><label>جستجوی کلی:</label><input type="text" class="search-input" placeholder="جستجو..."></div>
                        <div class="form-group"><label>تاریخ از:</label><input type="text" class="date-start" data-jdp>
                            <div class="date-debug date-start-debug"></div>
                        </div>
                        <div class="form-group"><label>تاریخ تا:</label><input type="text" class="date-end" data-jdp>
                            <div class="date-debug date-end-debug"></div>
                        </div>
                        <div class="form-group"><label>&nbsp;</label><button type="button" class="clear-filters">پاک کردن فیلترها</button></div>
                    </div>
                    <div class="column-filters">
                        <div class="form-group"><label>فیلتر کد المان:</label><input type="text" class="filter-element-id" placeholder="فیلتر..."></div>
                        <div class="form-group"><label>فیلتر فایل نقشه:</label><select class="filter-zone">
                                <option value="">همه</option><?php foreach ($all_zones as $zone): ?><option value="<?php echo escapeHtml($zone); ?>"><?php echo escapeHtml($zone); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="form-group"><label>فیلتر وضعیت:</label><select class="filter-status">
                                <option value="">همه</option>
                                <option value="Ready for Inspection">Ready for Inspection</option>
                                <option value="OK">OK</option>
                                <option value="Not OK">Not OK</option>
                                <option value="---">بدون وضعیت</option>
                            </select></div>
                    </div>
                    <div class="results-info">نمایش <span class="visible-count">0</span> از <span class="total-count">0</span> رکورد</div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th data-sort="element_id">کد المان</th>
                                <th data-sort="plan_file">فایل نقشه</th>
                                <th data-sort="status">وضعیت پیمانکار</th>
                                <th data-sort="date">تاریخ اعلام پیمانکار</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($elements_data as $element_id => $row): ?>
                                <tr class="data-row <?php echo get_status_class($row); ?>"
                                    data-element-id="<?php echo escapeHtml($row['element_id']); ?>"
                                    data-plan-file="<?php echo escapeHtml($row['plan_file']); ?>"
                                    data-element-type="<?php echo escapeHtml($row['element_type']); ?>"
                                    data-status="<?php echo escapeHtml($row['contractor_status'] ?: '---'); ?>"
                                    data-overall-status="<?php echo escapeHtml($row['overall_status'] ?: ''); ?>"
                                    data-date-timestamp="<?php echo $row['contractor_date'] ? strtotime($row['contractor_date']) : '0'; ?>"
                                    data-inspection-data='<?php echo json_encode($row, JSON_UNESCAPED_UNICODE); ?>'>
                                    <td><?php echo escapeHtml($row['element_id']); ?></td>
                                    <td><?php echo escapeHtml($row['plan_file']); ?></td>
                                    <td><span class="<?php echo $row['contractor_status'] === 'Ready for Inspection' ? 'ready-status-text' : ''; ?>"><?php echo escapeHtml($row['contractor_status'] ?: '---'); ?></span></td>
                                    <td><?php echo $row['contractor_date'] ? jdate('Y/m/d', strtotime($row['contractor_date'])) : '---'; ?></td>
                                    <td>
                                        <a class="view-form-link" href="#">مشاهده فرم</a>
                                        <?php if (!empty($row['plan_file'])): ?>
                                            | <!-- NEW AND CORRECT LINK -->
                                            <a href="/ghom/viewer.php?plan=<?php echo urlencode($row['plan_file']); ?>&highlight_id=<?php echo urlencode($row['element_id']); ?>" target="_blank">مشاهده نقشه</a>
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
    <div id="form-view-panel">
        <div class="form-header">
            <h3 id="form-title">فرم بازرسی</h3>
        </div>
        <div class="form-content" id="form-content-container"></div>
        <div class="btn-container" id="form-btn-container"></div>
    </div>

    <script src="/ghom/assets/js/jalalidatepicker.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const USER_ROLE = document.body.dataset.userRole;
            const dashboardContainer = document.querySelector('.dashboard-container');
            const formViewPanel = document.getElementById('form-view-panel');
            const formOverlay = document.getElementById('form-overlay');
            const formTitle = document.getElementById('form-title');
            const formContentContainer = document.getElementById('form-content-container');
            const formBtnContainer = document.getElementById('form-btn-container');

            jalaliDatepicker.startWatch({
                persianDigits: true,
                autoHide: true
            });

            function showFormPanel(elementId, elementType, inspectionData) {
                dashboardContainer.classList.add('form-open');
                formViewPanel.classList.add('open');
                formOverlay.classList.add('open');
                formTitle.textContent = `فرم بازرسی: ${elementId}`;
                formContentContainer.innerHTML = '<p>در حال بارگذاری فرم...</p>';
                formBtnContainer.innerHTML = '';
                openChecklistForm(elementId, elementType, inspectionData);
            }

            function hideFormPanel() {
                dashboardContainer.classList.remove('form-open');
                formViewPanel.classList.remove('open');
                formOverlay.classList.remove('open');
            }

            // FIX: Rewritten permission logic for clarity and correctness
            function setFormPermissions(formEl, buttonsEl, role) {
                const consultantSection = formEl.querySelector('.consultant-section');
                const contractorSection = formEl.querySelector('.contractor-section');
                const saveBtn = buttonsEl.querySelector('.btn.save');

                consultantSection.disabled = true;
                contractorSection.disabled = true;
                if (saveBtn) saveBtn.style.display = 'none';

                if (role === 'admin') {
                    consultantSection.disabled = false;
                    contractorSection.disabled = false;
                    if (saveBtn) saveBtn.style.display = 'inline-block';
                } else if (role === 'supervisor') { // Supervisor is the contractor
                    contractorSection.disabled = false;
                    if (saveBtn) saveBtn.style.display = 'inline-block';
                } else if (role === 'consultant') { // A dedicated consultant role
                    consultantSection.disabled = false;
                    if (saveBtn) saveBtn.style.display = 'inline-block';
                }
            }

            async function openChecklistForm(elementId, elementType, inspectionData) {
                try {
                    const response = await fetch(`/ghom/api/get_element_data.php?element_id=${elementId}&element_type=${elementType}`);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    const data = await response.json();
                    if (data.error) throw new Error(data.error);

                    let itemsHTML = '<tr><td colspan="3">چک لیستی برای این المان یافت نشد.</td></tr>';
                    if (data.items.length > 0) {
                        itemsHTML = data.items.map(item => `
                        <tr>
                            <td>${item.item_text}</td>
                            <td style="text-align:center; display:flex; justify-content:center; gap: 8px;">
                                <label><input type="radio" name="status_${item.item_id}" value="OK" ${item.item_status === 'OK' ? 'checked' : ''}> OK</label>
                                <label><input type="radio" name="status_${item.item_id}" value="Not OK" ${item.item_status === 'Not OK' ? 'checked' : ''}> Not OK</label>
                                <label><input type="radio" name="status_${item.item_id}" value="N/A" ${!item.item_status || item.item_status === 'N/A' ? 'checked' : ''}> N/A</label>
                            </td>
                            <td><input type="text" class="checklist-input" data-item-id="${item.item_id}" value="${item.item_value || ''}"></td>
                        </tr>`).join('');
                    }

                    formContentContainer.innerHTML = `
                    <form id="checklist-form">
                        <input type="hidden" name="element_id" value="${elementId}">
                        <input type="hidden" name="element_type" value="${elementType}">
                        <input type="hidden" name="plan_file" value="${inspectionData.plan_file || ''}">
                        <input type="hidden" name="contractor" value="${inspectionData.contractor || ''}">
                        <input type="hidden" name="block" value="${inspectionData.block || ''}">
                        
                        <fieldset class="consultant-section">
                            <legend>بخش مشاور</legend>
                            <div class="form-group"><label>تاریخ بازرسی:</label><input type="text" name="inspection_date" data-jdp value="${inspectionData.inspection_date ? jdate('Y/m/d', new Date(inspectionData.inspection_date).getTime()/1000) : ''}"></div>
                            <div class="form-group"><label>وضعیت کلی:</label><select name="overall_status"><option value="" ${!inspectionData.overall_status ? 'selected' : ''}>--</option><option value="OK" ${inspectionData.overall_status === 'OK' ? 'selected' : ''}>OK</option><option value="Not OK" ${inspectionData.overall_status === 'Not OK' ? 'selected' : ''}>Not OK</option></select></div>
                            <table><thead><tr><th>شرح</th><th>وضعیت</th><th>مقدار</th></tr></thead><tbody>${itemsHTML}</tbody></table>
                            <div class="form-group"><label>یادداشت مشاور:</label><textarea name="notes">${inspectionData.consultant_notes || ''}</textarea></div>
                        </fieldset>
                        <fieldset class="contractor-section">
                            <legend>بخش پیمانکار</legend>
                            <div class="form-group"><label>وضعیت:</label><select name="contractor_status"><option value="Pending" ${inspectionData.contractor_status === 'Pending' || !inspectionData.contractor_status ? 'selected' : ''}>در انتظار</option><option value="Ready for Inspection" ${inspectionData.contractor_status === 'Ready for Inspection' ? 'selected' : ''}>آماده بازرسی</option></select></div>
                            <div class="form-group"><label>تاریخ اعلام:</label><input type="text" name="contractor_date" data-jdp value="${inspectionData.contractor_date ? jdate('Y/m/d', new Date(inspectionData.contractor_date).getTime()/1000) : ''}"></div>
                            <div class="form-group"><label>یادداشت پیمانکار:</label><textarea name="contractor_notes">${inspectionData.contractor_notes || ''}</textarea></div>
                        </fieldset>
                    </form>`;

                    formBtnContainer.innerHTML = `<button type="submit" form="checklist-form" class="btn save">ذخیره</button><button type="button" class="btn cancel">بستن</button>`;

                    const formEl = document.getElementById('checklist-form');
                    setFormPermissions(formEl, formBtnContainer, USER_ROLE);
                    jalaliDatepicker.startWatch({
                        selector: '#checklist-form [data-jdp]'
                    });
                    formBtnContainer.querySelector('.btn.cancel').addEventListener('click', hideFormPanel);
                    formEl.addEventListener('submit', handleFormSubmit);

                } catch (error) {
                    formContentContainer.innerHTML = `<p style="color:red;">خطا در بارگزاری فرم: ${error.message}</p>`;
                    formBtnContainer.innerHTML = `<button type="button" class="btn cancel">بستن</button>`;
                    formBtnContainer.querySelector('.btn.cancel').addEventListener('click', hideFormPanel);
                }
            }

            async function handleFormSubmit(event) {
                event.preventDefault();
                const form = event.target;
                const saveBtn = formBtnContainer.querySelector('.btn.save');
                const formData = new FormData(form);
                const itemsPayload = [];
                form.querySelectorAll('tbody tr .checklist-input').forEach(input => {
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
                formData.append('items', JSON.stringify(itemsPayload));

                saveBtn.textContent = 'در حال ذخیره...';
                saveBtn.disabled = true;

                try {
                    // Using the new, separate API endpoint as requested
                    const response = await fetch('/ghom/api/save_inspection_from_dashboard.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    if (result.status === 'success') {
                        alert(result.message);
                        hideFormPanel();
                        location.reload();
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

            function persianToEnglishDigits(s) {
                if (!s) return '';
                const p = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
                    e = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
                let n = String(s);
                for (let i = 0; i < 10; i++) n = n.replace(new RegExp(p[i], 'g'), e[i]);
                return n
            }

            function jalaliToGregorian(y, m, d) {
                y = parseInt(y);
                m = parseInt(m);
                d = parseInt(d);
                let a, gy, gm, gd, s;
                y += 1595;
                s = -355668 + 365 * y + ~~(y / 33) * 8 + ~~(((y % 33) + 3) / 4) + d + (m < 7 ? (m - 1) * 31 : (m - 7) * 30 + 186);
                gy = 400 * ~~(s / 146097);
                s %= 146097;
                if (s > 36524) {
                    gy += 100 * ~~(--s / 36524);
                    s %= 36524;
                    if (s >= 365) s++
                }
                gy += 4 * ~~(s / 1461);
                s %= 1461;
                if (s > 365) {
                    gy += ~~((s - 1) / 365);
                    s = (s - 1) % 365
                }
                gd = s + 1;
                a = [0, 31, gy % 4 == 0 && gy % 100 != 0 || gy % 400 == 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
                for (gm = 0; gm < 13 && gd > a[gm]; gm++) gd -= a[gm];
                return {
                    gy,
                    gm,
                    gd
                }
            }

            function jdate(format, timestamp) {
                if (!timestamp) return '';
                let date = new Date(timestamp * 1000);
                let gy = date.getFullYear(),
                    gm = date.getMonth() + 1,
                    gd = date.getDate();
                let g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
                let jy = gy <= 1600 ? 0 : 979;
                gy -= gy <= 1600 ? 621 : 1600;
                let gy2 = gm > 2 ? gy + 1 : gy;
                let days = (365 * gy) + (parseInt((gy2 + 3) / 4)) - (parseInt((gy2 + 99) / 100)) + (parseInt((gy2 + 399) / 400)) - 80 + gd + g_d_m[gm - 1];
                jy += 33 * parseInt(days / 12053);
                days %= 12053;
                jy += 4 * parseInt(days / 1461);
                days %= 1461;
                jy += parseInt((days - 1) / 365);
                if (days > 365) days = (days - 1) % 365;
                let jm = days < 187 ? 1 + parseInt(days / 31) : 7 + parseInt((days - 186) / 30);
                let jd = 1 + ((days < 187) ? days % 31 : (days - 186) % 30);
                return format.replace(/Y/g, jy).replace(/m/g, jm < 10 ? '0' + jm : jm).replace(/d/g, jd < 10 ? '0' + jd : jd);
            }

            function jalaliToTimestamp(d) {
                if (!d || String(d).trim() === '---' || String(d).trim() === '') return 0;
                try {
                    const ed = persianToEnglishDigits(d.trim()),
                        p = ed.split('/');
                    if (p.length !== 3) return 0;
                    const y = parseInt(p[0], 10),
                        m = parseInt(p[1], 10),
                        _d = parseInt(p[2], 10);
                    if (isNaN(y) || isNaN(m) || isNaN(_d)) return 0;
                    const g = jalaliToGregorian(y, m, _d);
                    return g ? new Date(Date.UTC(g.gy, g.gm - 1, g.gd)).getTime() : 0
                } catch (e) {
                    return 0
                }
            }

            function setupFiltersAndSorting(tab) {
                tab.querySelectorAll('th[data-sort]').forEach(header => {
                    header.addEventListener('click', () => {
                        const tbody = tab.querySelector('tbody'),
                            rows = Array.from(tbody.querySelectorAll('.data-row'));
                        const sortField = header.dataset.sort,
                            isAsc = !header.classList.contains('sorted-asc');
                        tab.querySelectorAll('th[data-sort]').forEach(h => h.classList.remove('sorted-asc', 'sorted-desc'));
                        header.classList.add(isAsc ? 'sorted-asc' : 'sorted-desc');
                        rows.sort((a, b) => {
                            let aVal, bVal;
                            if (sortField === 'date') {
                                aVal = parseInt(a.dataset.dateTimestamp, 10) || 0;
                                bVal = parseInt(b.dataset.dateTimestamp, 10) || 0;
                            } else {
                                aVal = (a.dataset[sortField] || '').toLowerCase();
                                bVal = (b.dataset[sortField] || '').toLowerCase();
                            }
                            if (aVal < bVal) return isAsc ? -1 : 1;
                            if (aVal > bVal) return isAsc ? 1 : -1;
                            return 0;
                        });
                        rows.forEach(row => tbody.appendChild(row));
                    });
                });
                const allFilters = Array.from(tab.querySelectorAll('.search-input,.date-start,.date-end,.filter-element-id,.filter-zone,.filter-status'));
                allFilters.forEach(input => {
                    input.addEventListener('input', () => applyFilters(tab));
                    input.addEventListener('change', () => applyFilters(tab));
                });
                tab.querySelector('.clear-filters').addEventListener('click', () => {
                    allFilters.forEach(input => {
                        input.tagName === 'SELECT' ? input.selectedIndex = 0 : input.value = '';
                    });
                    applyFilters(tab);
                });
                applyFilters(tab);
            }

            function applyFilters(tab) {
                const [searchTerm, startDateVal, endDateVal, elementIdFilter, zoneFilter, statusFilter] = Array.from(tab.querySelectorAll('.search-input,.date-start,.date-end,.filter-element-id,.filter-zone,.filter-status')).map(el => el.value.toLowerCase());
                const startTimestamp = jalaliToTimestamp(startDateVal),
                    endTimestamp = jalaliToTimestamp(endDateVal) ? jalaliToTimestamp(endDateVal) + (24 * 60 * 60 * 1000 - 1) : Number.MAX_SAFE_INTEGER;
                let visibleCount = 0;
                tab.querySelectorAll('.data-row').forEach(row => {
                    const rowTimestamp = parseInt(row.dataset.dateTimestamp, 10) * 1000;
                    let statusMatch = true;
                    if (statusFilter) {
                        const overallStatus = row.dataset.overallStatus.toLowerCase();
                        const contractorStatus = row.dataset.status.toLowerCase();
                        if (statusFilter === 'ok') {
                            statusMatch = overallStatus === 'ok';
                        } else if (statusFilter === 'not ok') {
                            statusMatch = overallStatus === 'not ok';
                        } else if (statusFilter === 'ready for inspection') {
                            statusMatch = contractorStatus === 'ready for inspection';
                        } else if (statusFilter === '---') {
                            statusMatch = contractorStatus === '---';
                        }
                    }
                    const show = (!searchTerm || Array.from(row.cells).some(c => c.textContent.toLowerCase().includes(searchTerm))) && (!elementIdFilter || row.dataset.elementId.toLowerCase().includes(elementIdFilter)) && (!zoneFilter || row.dataset.planFile.toLowerCase() === zoneFilter) && statusMatch && (!(startDateVal || endDateVal) || (rowTimestamp > 0 && rowTimestamp >= startTimestamp && rowTimestamp <= endTimestamp));
                    row.classList.toggle('hidden', !show);
                    if (show) visibleCount++;
                });
                updateResultsCount(tab, visibleCount);
            }

            function updateResultsCount(tab, visibleCount) {
                const total = tab.querySelectorAll('.data-row').length;
                if (visibleCount === null) visibleCount = tab.querySelectorAll('.data-row:not(.hidden)').length;
                tab.querySelector('.visible-count').textContent = visibleCount;
                tab.querySelector('.total-count').textContent = total;
            }

            document.querySelectorAll('.tab-content').forEach(setupFiltersAndSorting);
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.addEventListener('click', () => {
                    document.querySelectorAll('.tab-button, .tab-content').forEach(el => el.classList.remove('active'));
                    button.classList.add('active');
                    document.getElementById(button.dataset.tab).classList.add('active');
                });
            });
            if (tabButtons.length > 0) tabButtons[0].click();

            // FIX: Event listener to handle date picker clicks without closing the form
            document.body.addEventListener('click', function(e) {
                const formLink = e.target.closest('.view-form-link');
                if (formLink) {
                    e.preventDefault();
                    const row = e.target.closest('.data-row');
                    if (row) {
                        const {
                            elementId,
                            elementType,
                            inspectionData
                        } = row.dataset;
                        showFormPanel(elementId, elementType, JSON.parse(inspectionData));
                    }
                } else if (formViewPanel.classList.contains('open') && !formViewPanel.contains(e.target) && !e.target.closest('.jdp-container')) {
                    hideFormPanel();
                }
            });
            const reportZoneSelect = document.getElementById('report-zone-select');
            document.querySelectorAll('.report-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const planFile = reportZoneSelect.value;
                    const statusToHighlight = this.dataset.status;

                    if (!planFile) {
                        alert('لطفا ابتدا یک فایل نقشه را انتخاب کنید.');
                        return;
                    }

                    // Construct the new URL with the highlight_status parameter
                    const url = `/ghom/viewer.php?plan=${encodeURIComponent(planFile)}&highlight_status=${encodeURIComponent(statusToHighlight)}`;

                    // Open the generated map in a new tab
                    window.open(url, '_blank');
                });
            });
        });
    </script>
</body>

</html>