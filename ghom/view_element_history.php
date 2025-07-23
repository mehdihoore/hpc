<?php
// ===================================================================
// START: FINAL, PROFESSIONAL PHP FOR ELEMENT HISTORY CONSOLE
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

// 1. GET PARAMETERS
$element_id = $_GET['element_id'] ?? null;
$part_name = $_GET['part_name'] ?? null;

if (!$element_id) {
    die("A required Element ID was not provided.");
}

// 2. INITIALIZE ARRAYS
$history = [];
$element_info = null;
$template_items = [];

try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->exec("SET NAMES 'utf8mb4'");

    // 3. GET ELEMENT INFO
    $stmt_el = $pdo->prepare("SELECT * FROM elements WHERE element_id = ?");
    $stmt_el->execute([$element_id]);
    $element_info = $stmt_el->fetch(PDO::FETCH_ASSOC);

    if ($element_info) {
        // 4. FETCH ALL HISTORICAL INSPECTIONS FOR THIS PART, INCLUDING THE USER'S NAME
        $stmt_inspections = $pdo->prepare(
            "SELECT 
                i.*, 
                CONCAT_WS(' ', u.first_name, u.last_name) as user_name
             FROM inspections i
             LEFT JOIN hpc_common.users u ON i.user_id = u.id
             WHERE i.element_id = ? AND i.part_name <=> ?
             ORDER BY i.created_at DESC, i.inspection_id DESC"
        );
        $stmt_inspections->execute([$element_id, $part_name]);
        $inspections = $stmt_inspections->fetchAll(PDO::FETCH_ASSOC);

        // 5. FETCH THE CORRECT CHECKLIST TEMPLATE
        $templateType = ($element_info['element_type'] === 'GFRC') ? 'GFRC' : 'Curtainwall';
        $stmt_template = $pdo->prepare(
            "SELECT item_id, item_text, stage FROM checklist_items 
             WHERE template_id = (SELECT template_id FROM checklist_templates WHERE element_type = ? LIMIT 1)
             ORDER BY item_order ASC"
        );
        $stmt_template->execute([$templateType]);
        $template_items = $stmt_template->fetchAll(PDO::FETCH_ASSOC);

        // 6. ORGANIZE DATA: For each inspection, fetch its specific answers
        $stmt_results = $pdo->prepare("SELECT item_id, item_status, item_value FROM inspection_data WHERE inspection_id = ?");
        foreach ($inspections as $inspection) {
            $stmt_results->execute([$inspection['inspection_id']]);
            $results_raw = $stmt_results->fetchAll(PDO::FETCH_ASSOC);

            // Create a simple map of item_id => results for easy lookup in JavaScript
            $results_map = [];
            foreach ($results_raw as $res) {
                $results_map[$res['item_id']] = $res;
            }
            // Add the organized data to our final history array
            $history[] = ['inspection' => $inspection, 'results' => $results_map];
        }
    }
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// ===================================================================
// END: FINAL PHP BLOCK
// ===================================================================
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title>سابقه بازرسی: <?php echo escapeHtml($element_id); ?></title>
    <link rel="stylesheet" href="/ghom/assets/css/jalalidatepicker.min.css" />
    <style>
        /* --- NEW, PROFESSIONAL CONSOLE STYLES --- */
        @font-face {
            font-family: "Samim";
            src: url("/ghom/assets/fonts/Samim-FD.woff2") format("woff2");
        }

        body {
            font-family: "Samim", sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            padding: 20px;
        }

        .console-container {
            display: flex;
            flex-direction: row;
            gap: 20px;
            max-width: 1800px;
            margin: auto;
            align-items: flex-start;
        }

        .history-column {
            flex: 1;
            min-width: 400px;
        }

        .form-column {
            flex: 1.5;
            position: sticky;
            top: 20px;
        }

        .panel {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
        }

        h1,
        h2 {
            color: #1d2129;
        }

        h1 {
            margin-top: 0;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }

        h2 {
            font-size: 1.4em;
            color: #0056b3;
            margin-bottom: 20px;
        }

        .element-info {
            background: #e9f5ff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            border: 1px solid #bce0fd;
        }

        .element-info span {
            font-size: 0.95em;
        }

        details {
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 10px;
            overflow: hidden;
        }

        summary {
            cursor: pointer;
            padding: 12px 15px;
            background-color: #f8f9fa;
            list-style: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        summary:hover {
            background-color: #e9ecef;
        }

        .history-content {
            padding: 15px;
            border-top: 1px solid #ddd;
        }

        /* Button Styles */
        .btn {
            padding: 8px 15px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            color: white;
            font-family: inherit;
            font-size: 0.9em;
            transition: background-color 0.2s;
        }

        .btn-load {
            background-color: #007bff;
        }

        .btn-load:hover {
            background-color: #0069d9;
        }

        .btn-save {
            background-color: #28a745;
        }

        .btn-new {
            background-color: #6c757d;
        }

        .btn-back {
            background-color: #5a6268;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
        }

        #form-container {
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            padding-right: 15px;
        }

        /* Form Element Styles (As seen in your screenshot) */
        .form-stage {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .stage-title {
            font-size: 1.2em;
            color: #0d6efd;
            margin-bottom: 18px;
            padding-right: 12px;
            border-right: 4px solid #0d6efd;
            font-weight: bold;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
            font-size: 0.95em;
            color: #495057;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
            background-color: #fff;
            transition: border-color .15s ease-in-out, box-shadow .15s ease-in-out;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #80bdff;
            outline: 0;
            box-shadow: 0 0 0 .2rem rgba(0, 123, 255, .25);
        }

        .checklist-item-row {
            margin-bottom: 20px;
        }

        .checklist-item-row .item-text {
            font-weight: bold;
            margin-bottom: 10px;
            display: block;
        }

        .status-selector {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
            gap: 20px;
            margin-bottom: 8px;
        }

        .status-selector label {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        fieldset {
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        legend {
            font-weight: bold;
            color: #333;
            padding: 0 10px;
        }
    </style>
</head>

<body data-user-role="<?php echo escapeHtml($_SESSION['role']); ?>">
    <div class="console-container">
        <div class="history-column panel">
            <!-- NEW: Back Button -->
            <a href="/ghom/inspection_dashboard.php" class="btn btn-back">بازگشت به داشبورد</a>
            <h1>تاریخچه بازرسی</h1>
            <?php if ($element_info): ?>
                <div class="element-info">
                    <span><strong>کد:</strong> <?php echo escapeHtml($element_info['element_id']); ?> (<?php echo escapeHtml($part_name); ?>)</span>
                    <span><strong>نوع:</strong> <?php echo escapeHtml($element_info['element_type']); ?></span>
                    <span><strong>طبقه:</strong> <?php echo escapeHtml($element_info['floor_level']); ?></span>
                </div>
                <div id="history-list">
                    <?php if (!empty($history)): foreach ($history as $item): $inspection = $item['inspection']; ?>
                            <details>
                                <summary>
                                    <span>ثبت در: <strong><?php echo jdate('Y/m/d H:i', strtotime($inspection['created_at'])); ?></strong></span>
                                    <button class="btn btn-load" data-inspection-id="<?php echo $inspection['inspection_id']; ?>">بارگذاری</button>
                                </summary>
                                <div class="history-content">
                                    <p><strong>ثبت توسط:</strong> <?php echo escapeHtml($inspection['user_name']); ?></p>
                                    <p><strong>وضعیت مشاور:</strong> <?php echo escapeHtml($inspection['overall_status'] ?: '---'); ?></p>
                                    <p><strong>وضعیت پیمانکار:</strong> <?php echo escapeHtml($inspection['contractor_status'] ?: '---'); ?></p>
                                </div>
                            </details>
                        <?php endforeach;
                    else: ?>
                        <p>هیچ سابقه بازرسی یافت نشد.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p>المان مورد نظر یافت نشد.</p>
            <?php endif; ?>
        </div>
        <div class="form-column panel">
            <div id="form-container">
                <!-- JavaScript will build the form here -->
            </div>
        </div>
    </div>

    <script src="/ghom/assets/js/jalalidatepicker.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- GLOBAL STATE & CONFIG ---
            const USER_ROLE = document.body.dataset.userRole;
            const formContainer = document.getElementById('form-container');
            const historyData = <?php echo json_encode($history, JSON_UNESCAPED_UNICODE); ?>;
            const elementInfo = <?php echo json_encode($element_info, JSON_UNESCAPED_UNICODE); ?>;
            const templateItems = <?php echo json_encode($template_items, JSON_UNESCAPED_UNICODE); ?>;
            const partName = '<?php echo escapeHtml($part_name ?? '', 'js'); ?>';

            let editingInspectionId = null; // Track which record is being edited

            /**
             * A safe way to format dates coming from PHP.
             */
            function formatDate(dateString) {
                if (!dateString || dateString.startsWith('0000')) return '';
                // Use jdate since it's already included and handles timestamps well
                return jdate('Y/m/d', new Date(dateString).getTime() / 1000);
            }

            /**
             * The main function to build and display the inspection form.
             * Can be called with a historical record to load, or with null to create a new one.
             */
            function buildInspectionForm(inspectionRecord = null) {
                const isEditMode = !!inspectionRecord;
                editingInspectionId = isEditMode ? inspectionRecord.inspection.inspection_id : null;

                try {
                    if (!templateItems || templateItems.length === 0) {
                        throw new Error(`قالب چک لیست برای نوع "${elementInfo.element_type}" یافت نشد.`);
                    }

                    // Group template items by their stage for display
                    const itemsByStage = templateItems.reduce((acc, item) => {
                        const stageName = item.stage || 'موارد عمومی';
                        if (!acc[stageName]) acc[stageName] = [];
                        acc[stageName].push(item);
                        return acc;
                    }, {});

                    // Build the checklist HTML by looping through the stages
                    const formChecklistHTML = Object.keys(itemsByStage).map(stageName => {
                        const stageItemsHTML = itemsByStage[stageName].map(item => {
                            const result = isEditMode ? inspectionRecord.results[item.item_id] : null;
                            const status = result ? result.item_status : 'N/A';
                            const value = result ? result.item_value : '';
                            return `
                    <div class="checklist-item-row">
                        <label class="item-text">${escapeHtml(item.item_text)}</label>
                        <div style="display: flex; gap: 15px; align-items: center;">
                            <input type="text" class="checklist-input" data-item-id="${item.item_id}" value="${escapeHtml(value)}">
                            <div class="status-selector">
                                <label><input type="radio" name="status_${item.item_id}" value="OK" ${status === 'OK' ? 'checked' : ''}> OK</label>
                                <label><input type="radio" name="status_${item.item_id}" value="Not OK" ${status === 'Not OK' ? 'checked' : ''}> NOK</label>
                                <label><input type="radio" name="status_${item.item_id}" value="N/A" ${status === 'N/A' || !status ? 'checked' : ''}> N/A</label>
                            </div>
                        </div>
                    </div>`;
                        }).join('');
                        return `<fieldset class="form-stage"><legend class="stage-title">${escapeHtml(stageName)}</legend>${stageItemsHTML}</fieldset>`;
                    }).join('');

                    const inspData = isEditMode ? inspectionRecord.inspection : {};

                    // Assemble the final form HTML
                    formContainer.innerHTML = `
                <h2>${isEditMode ? 'ویرایش سابقه (ID: ' + editingInspectionId + ')' : 'ثبت بازرسی جدید'}</h2>
                <form id="inspection-form">
                    <input type="hidden" name="inspection_id" value="${editingInspectionId || ''}">
                    <input type="hidden" name="element_id" value="${elementInfo.element_id}">
                    <input type="hidden" name="part_name" value="${partName}">
                    
                    <fieldset class="consultant-section">
                        <legend>بخش مشاور</legend>
                        <div class="form-group"><label>تاریخ بازرسی:</label><input type="text" name="inspection_date" data-jdp value="${formatDate(inspData.inspection_date)}"></div>
                        <div class="form-group"><label>وضعیت کلی:</label><select name="overall_status"><option value="">--</option><option value="OK" ${inspData.overall_status === 'OK' ? 'selected' : ''}>OK</option><option value="Not OK" ${inspData.overall_status === 'Not OK' ? 'selected' : ''}>Not OK</option></select></div>
                    </fieldset>

                    <fieldset class="contractor-section">
                        <legend>بخش پیمانکار</legend>
                        <div class="form-group"><label>وضعیت:</label><select name="contractor_status"><option value="Pending" ${inspData.contractor_status === 'Pending' || !inspData.contractor_status ? 'selected' : ''}>در حال اجرا</option><option value="Ready for Inspection" ${inspData.contractor_status === 'Ready for Inspection' ? 'selected' : ''}>آماده بازرسی</option></select></div>
                        <div class="form-group"><label>تاریخ اعلام:</label><input type="text" name="contractor_date" data-jdp value="${formatDate(inspData.contractor_date)}"></div>
                    </fieldset>
                    
                    <hr>
                    ${formChecklistHTML}
                    
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px;">
                         <button type="button" id="new-inspection-btn" class="btn btn-new">ایجاد فرم جدید</button>
                         <button type="submit" class="btn btn-save">${isEditMode ? 'ذخیره تغییرات در این سابقه' : 'ثبت به عنوان بازرسی جدید'}</button>
                    </div>
                </form>`;

                    // Re-initialize datepickers and event listeners on the new form
                    if (typeof jalaliDatepicker !== 'undefined') {
                        jalaliDatepicker.startWatch({
                            selector: '#form-container [data-jdp]'
                        });
                    }
                    document.getElementById('inspection-form').addEventListener('submit', handleFormSubmit);
                    document.getElementById('new-inspection-btn').addEventListener('click', () => buildInspectionForm(null));

                    // Apply role-based permissions
                    setFormPermissions(document.getElementById('inspection-form'), USER_ROLE);

                } catch (e) {
                    formContainer.innerHTML = `<h2 style="color:red;">خطا: ${e.message}</h2>`;
                }
            }

            /**
             * Sets permissions on the form based on user role.
             */
            function setFormPermissions(formEl, role) {
                const consultantSection = formEl.querySelector('.consultant-section');
                const contractorSection = formEl.querySelector('.contractor-section');

                if (consultantSection) consultantSection.disabled = !['admin', 'superuser'].includes(role);
                if (contractorSection) contractorSection.disabled = !['supervisor', 'cat', 'car', 'coa', 'crs', 'superuser'].includes(role);
            }

            /**
             * Handles the form submission for both new records and updates.
             */
            async function handleFormSubmit(event) {
                event.preventDefault();
                const form = event.target;
                const saveBtn = form.querySelector('.btn-save');
                const originalBtnText = saveBtn.textContent;
                saveBtn.textContent = 'در حال ذخیره...';
                saveBtn.disabled = true;

                try {
                    const formData = new FormData(form);
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
                    formData.append('items', JSON.stringify(itemsPayload));

                    // Use the correct API endpoint
                    const response = await fetch('/ghom/api/save_inspection_from_dashboard.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (response.ok && result.status === 'success') {
                        alert(result.message);
                        location.reload(); // Reload the page to see the new history
                    } else {
                        throw new Error(result.message || 'An unknown server error occurred.');
                    }
                } catch (error) {
                    alert('خطا در ذخیره‌سازی: ' + error.message);
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.textContent = originalBtnText;
                }
            }

            // --- INITIAL PAGE LOAD & EVENT LISTENERS ---
            document.getElementById('history-list').addEventListener('click', function(e) {
                if (e.target.classList.contains('btn-load')) {
                    const inspectionIdToLoad = e.target.dataset.inspectionId;
                    const recordToLoad = historyData.find(h => h.inspection.inspection_id == inspectionIdToLoad);
                    if (recordToLoad) {
                        buildInspectionForm(recordToLoad);
                        formContainer.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                }
            });

            // Initial load of a blank form
            buildInspectionForm(null);

            // Helper function to escape HTML for security
            function escapeHtml(unsafe) {
                if (typeof unsafe !== "string") return "";
                return unsafe
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }
        });
    </script>
</body>

</html>