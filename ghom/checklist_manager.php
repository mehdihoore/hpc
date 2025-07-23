<?php
//ghom/checklist_manager.php
require_once __DIR__ . '/../../sercon/bootstrap.php';
secureSession();

if (!isLoggedIn()) {
    header('Location: /login.php?msg=login_required');
    exit();
}
if (!in_array($_SESSION['role'], ['admin', 'superuser'])) {
    http_response_code(403);
    require 'Access_Denied.php';
    exit;
}

$pageTitle = "مدیریت قالب‌های چک‌لیست";
require_once __DIR__ . '/header_ghom.php';
// Note: We don't include header_ghom.php to keep this page self-contained and simple.
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت قالب‌های چک‌لیست</title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        @font-face {
            font-family: "Samim";
            src: url("assets/fonts/Samim-FD.woff2") format("woff2");
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: "Samim", sans-serif;
            background-color: #f4f7f6;
            direction: rtl;
            margin: 0;
            padding: 10px;
            font-size: 14px;
        }

        #manager-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        h1,
        h2,
        h3 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        h1 {
            font-size: 1.8em;
            text-align: center;
        }

        h2 {
            font-size: 1.4em;
        }

        h3 {
            font-size: 1.2em;
        }

        .layout {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .template-list-container {
            flex: 1;
            min-width: 300px;
            background-color: #ecf0f1;
            padding: 20px;
            border-radius: 8px;
            height: fit-content;
        }

        .template-form-container {
            flex: 2;
            min-width: 350px;
            padding: 20px;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 5px;
            }

            #manager-container {
                padding: 15px;
            }

            .layout {
                flex-direction: column;
                gap: 20px;
            }

            .template-list-container,
            .template-form-container {
                min-width: 100%;
                padding: 15px;
            }

            h1 {
                font-size: 1.5em;
            }

            h2 {
                font-size: 1.2em;
            }
        }

        /* Template List */
        #template-list {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }

        #template-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            margin-bottom: 10px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            flex-wrap: wrap;
            gap: 10px;
        }

        #template-list li span:first-child {
            flex: 1;
            min-width: 200px;
            font-weight: 500;
        }

        .template-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .template-actions button {
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s ease;
            min-width: 60px;
        }

        .copy-btn {
            background: #f39c12;
        }

        .copy-btn:hover {
            background: #e67e22;
        }

        .edit-btn {
            background: #3498db;
        }

        .edit-btn:hover {
            background: #2980b9;
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #2c3e50;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            font-family: "Samim", sans-serif;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3498db;
        }

        /* Stage Groups */
        .stage-group {
            border: 2px solid #3498db;
            border-radius: 12px;
            margin-bottom: 25px;
            background: #fff;
            overflow: hidden;
        }

        .stage-header {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            cursor: move;
            gap: 10px;
        }

        .stage-title {
            font-weight: bold;
            margin: 0;
            font-size: 1.1em;
        }

        .stage-items-container {
            padding: 20px;
        }

        /* Item Rows */
        .item-row {
            display: grid;
            grid-template-columns: 30px 1fr 2fr 120px 60px;
            gap: 15px;
            margin-bottom: 15px;
            padding: 15px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            align-items: center;
        }

        @media (max-width: 768px) {
            .item-row {
                grid-template-columns: 1fr;
                gap: 10px;
                text-align: center;
            }

            .item-row .item-drag-handle {
                display: none;
            }
        }

        .item-drag-handle {
            cursor: move;
            color: #999;
            font-size: 1.5em;
            text-align: center;
            user-select: none;
        }

        .item-stage-input,
        .item-text-input {
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 15px;
            font-family: "Samim", sans-serif;
            width: 100%;
        }

        .item-stage-input:focus,
        .item-text-input:focus {
            outline: none;
            border-color: #3498db;
        }

        .item-passing-status {
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: "Samim", sans-serif;
            background: white;
        }

        .remove-item-btn {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.3s ease;
        }

        .remove-item-btn:hover {
            background: #c0392b;
        }

        /* Headers for Item Rows */
        .item-header {
            display: grid;
            grid-template-columns: 30px 1fr 2fr 120px 60px;
            gap: 15px;
            font-weight: bold;
            margin-bottom: 10px;
            padding: 0 15px;
            color: #2c3e50;
        }

        @media (max-width: 768px) {
            .item-header {
                display: none;
            }
        }

        /* Buttons */
        button {
            font-family: "Samim", sans-serif;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            margin: 5px;
        }

        #add-item-btn {
            background: #95a5a6;
            color: white;
        }

        #add-item-btn:hover {
            background: #7f8c8d;
        }

        #clear-form-btn {
            background: #34495e;
            color: white;
        }

        #clear-form-btn:hover {
            background: #2c3e50;
        }

        .save-btn {
            background: #2ecc71;
            color: white;
            font-weight: bold;
            font-size: 16px;
            padding: 15px 30px;
        }

        .save-btn:hover {
            background: #27ae60;
        }

        /* Helper Text */
        .helper-text {
            font-size: 13px;
            color: #555;
            margin-bottom: 20px;
            line-height: 1.6;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #3498db;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #ecf0f1;
        }

        @media (max-width: 480px) {
            .form-actions {
                flex-direction: column;
            }

            .form-actions button {
                width: 100%;
                margin: 5px 0;
            }
        }

        /* Loading and States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Smooth Animations */
        .stage-group {
            transition: transform 0.2s ease;
        }

        .stage-group:hover {
            transform: translateY(-2px);
        }

        .item-row {
            transition: transform 0.2s ease;
        }

        .item-row:hover {
            transform: translateY(-1px);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
            font-style: italic;
        }

        /* Responsive Grid Headers */
        @media (max-width: 768px) {
            .item-row {
                display: block;
                padding: 15px;
            }

            .item-row>* {
                margin-bottom: 10px;
                width: 100%;
            }

            .item-row .item-drag-handle {
                display: block;
                text-align: center;
                margin-bottom: 10px;
            }

            .item-stage-input,
            .item-text-input,
            .item-passing-status {
                font-size: 16px;
                padding: 15px;
            }
        }

        .item-header,
        .item-row {
            grid-template-columns: 30px 1fr 2fr 120px 100px 100px 60px;
        }

        .weight-input-group,
        .critical-input-group {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .weight-input-group span {
            margin-right: 5px;
        }

        .item-critical-input {
            width: 20px;
            height: 20px;
        }

        .stage-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            /* This will push the total to the right */
        }

        .stage-item-total {
            font-size: 13px;
            font-weight: bold;
        }

        .total-item-weight-display.valid {
            color: #28a745;
        }

        .total-item-weight-display.invalid {
            color: #dc3545;
        }

        #save-validation-message.error {
            color: #c0392b;
            /* Red */
        }
    </style>
</head>

<body>
    <div id="manager-container">
        <h1>مدیریت قالب‌های چک‌لیست</h1>

        <div class="layout">
            <div class="template-list-container">
                <h2>قالب‌های موجود</h2>
                <ul id="template-list">
                    <!-- Demo templates -->
                    <li>
                        <span>چک لیست شیشه (GLASS)</span>
                        <div class="template-actions">
                            <button class="copy-btn" data-id="1">کپی</button>
                            <button class="edit-btn" data-id="1">ویرایش</button>
                        </div>
                    </li>
                    <li>
                        <span>چک لیست کرتین وال و وینووال (Curtainwall)</span>
                        <div class="template-actions">
                            <button class="copy-btn" data-id="2">کپی</button>
                            <button class="edit-btn" data-id="2">ویرایش</button>
                        </div>
                    </li>
                    <li>
                        <span>چک لیست کنترل کیفی GFRC - نسخه 1 (GFRC)</span>
                        <div class="template-actions">
                            <button class="copy-btn" data-id="3">کپی</button>
                            <button class="edit-btn" data-id="3">ویرایش</button>
                        </div>
                    </li>
                </ul>
            </div>

            <div class="template-form-container">
                <h2>ایجاد / ویرایش / کپی</h2>

                <datalist id="stage-datalist">
                    <option value="مرحله اول"></option>
                    <option value="مرحله دوم"></option>
                    <option value="مرحله سوم"></option>
                </datalist>

                <form id="template-form">
                    <input type="hidden" id="template_id" name="template_id">

                    <div class="form-group">
                        <label for="template_name">نام قالب</label>
                        <input type="text" id="template_name" name="template_name" required
                            placeholder="نام قالب را وارد کنید...">
                    </div>

                    <div class="form-group">
                        <label for="element_type">نوع المان (Element Type)</label>
                        <input type="text" id="element_type" name="element_type" required
                            placeholder="مثال: GFRC, Glass, Curtainwall">
                    </div>

                    <hr style="margin: 30px 0; border: 1px solid #ecf0f1;">

                    <h3>آیتم‌های چک‌لیست</h3>

                    <div class="helper-text">
                        <strong>راهنما:</strong>
                        <br>• <strong>شرط قبولی:</strong> برای آیتم‌های بررسی نقص (مانند "آیا ترکی وجود دارد؟")، شرط قبولی را <strong>ناموفق (✗)</strong> قرار دهید.
                        <br>• <strong>وزن آیتم:</strong> مجموع وزن تمام آیتم‌ها در یک مرحله باید <strong>دقیقا ۱۰۰٪</strong> شود.
                        <br>• <strong>آیتم کلیدی:</strong> اگر این گزینه فعال باشد و وضعیت آیتم "رد شده" (NOK) ثبت شود، کل مرحله به صورت خودکار "رد شده" در نظر گرفته خواهد شد.
                    </div>

                    <div class="item-header">
                        <span></span> <!-- Drag Handle -->
                        <span>مرحله (Stage)</span>
                        <span>متن سوال</span>
                        <span>شرط قبولی</span>
                        <span>وزن آیتم (%)</span>
                        <span>آیتم کلیدی؟</span>
                        <span></span> <!-- Delete Button -->
                    </div>

                    <div id="items-container">
                        <!-- Demo stage group -->
                        <div class="stage-group" data-stage-name="مرحله اول">
                            <div class="stage-header">
                                <span class="item-drag-handle">☰</span>
                                <h4 class="stage-title">مرحله اول</h4>
                            </div>
                            <div class="stage-items-container">
                                <div class="item-row">
                                    <span class="item-drag-handle">☰</span>
                                    <input type="text" class="item-stage-input" value="مرحله اول"
                                        placeholder="مرحله..." list="stage-datalist">
                                    <input type="text" class="item-text-input" value="آیا مواد نصب شده‌اند؟"
                                        placeholder="متن سوال..." required>
                                    <select class="item-passing-status">
                                        <option value="OK" selected>موفق (✓)</option>
                                        <option value="Not OK">ناموفق (✗)</option>
                                    </select>
                                    <button type="button" class="remove-item-btn">حذف</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="button" id="add-item-btn">افزودن آیتم</button>

                    <div class="form-actions">
                        <div id="save-validation-message" style="width: 100%; text-align: center; font-weight: bold; margin-bottom: 10px;"></div>

                        <button type="submit" class="save-btn">ذخیره قالب</button>
                        <button type="button" id="clear-form-btn">فرم جدید</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- VARIABLE DECLARATIONS ---
            const templateList = document.getElementById('template-list');
            const form = document.getElementById('template-form');
            const itemsContainer = document.getElementById('items-container');
            const templateIdField = document.getElementById('template_id');
            const templateNameField = document.getElementById('template_name');
            const elementTypeField = document.getElementById('element_type');
            const stageDatalist = document.getElementById('stage-datalist');
            const saveBtn = document.querySelector('.save-btn');
            const validationMessage = document.getElementById('save-validation-message');

            // --- HELPER FUNCTIONS ---
            function escapeHtml(unsafe) {
                if (typeof unsafe !== "string") return "";
                return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
            }

            // --- CORE LOGIC ---
            function loadTemplates() {
                fetch('api/get_templates.php').then(res => res.json()).then(templates => {
                    templateList.innerHTML = '';
                    templates.forEach(t => {
                        const li = document.createElement('li');
                        li.innerHTML = `
                    <span>${escapeHtml(t.template_name)} (<b>${escapeHtml(t.element_type)}</b>)</span> 
                    <span class="template-actions">
                        <button type="button" data-id="${t.template_id}" class="copy-btn">کپی</button>
                        <button type="button" data-id="${t.template_id}" class="edit-btn">ویرایش</button>
                    </span>`;
                        templateList.appendChild(li);
                    });
                });
            }

            function validateAllStageWeights() {
                let allStagesAreValid = true;
                let invalidStages = [];

                document.querySelectorAll('.stage-group').forEach(stageGroup => {
                    const totalDisplay = stageGroup.querySelector('.total-item-weight-display');
                    if (totalDisplay && totalDisplay.classList.contains('invalid')) {
                        allStagesAreValid = false;
                        invalidStages.push(stageGroup.querySelector('.stage-title').textContent);
                    }
                });

                saveBtn.disabled = !allStagesAreValid;

                if (allStagesAreValid) {
                    validationMessage.textContent = '';
                    validationMessage.classList.remove('error');
                    saveBtn.title = 'ذخیره قالب';
                } else {
                    validationMessage.textContent = `ذخیره غیرفعال است! لطفا مجموع وزن آیتم ها را در مراحل زیر به ۱۰۰٪ برسانید: ${invalidStages.join(', ')}`;
                    validationMessage.classList.add('error');
                    saveBtn.title = validationMessage.textContent;
                }
            }

            function loadTemplateForEditing(id, isCopy = false) {
                fetch(`api/get_template_details.php?id=${id}`).then(res => res.json()).then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    clearForm();
                    if (isCopy) {
                        templateIdField.value = '';
                        templateNameField.value = data.template.template_name + ' - کپی';
                        elementTypeField.value = data.template.element_type;
                    } else {
                        templateIdField.value = data.template.template_id;
                        templateNameField.value = data.template.template_name;
                        elementTypeField.value = data.template.element_type;
                    }
                    if (data.existing_stages && Array.isArray(data.existing_stages)) {
                        stageDatalist.innerHTML = data.existing_stages.map(s => `<option value="${escapeHtml(s)}"></option>`).join('');
                    }
                    const itemsByStage = data.items.reduce((acc, item) => {
                        const stage = item.stage || "مرحله تعریف نشده";
                        if (!acc[stage]) acc[stage] = [];
                        acc[stage].push(item);
                        return acc;
                    }, {});
                    for (const stageName in itemsByStage) {
                        addStageGroup(stageName, itemsByStage[stageName]);
                    }
                    initializeSortables();
                    validateAllStageWeights();
                });
            }

            function addStageGroup(stageName, items = []) {
                const stageGroupDiv = document.createElement('div');
                stageGroupDiv.className = 'stage-group';
                stageGroupDiv.dataset.stageName = stageName;
                stageGroupDiv.innerHTML = `
        <div class="stage-header">
            <div style="display: flex; align-items: center; gap: 10px; flex-grow: 1;">
                <span class="item-drag-handle">☰</span>
                <h4 class="stage-title" contenteditable="true">${escapeHtml(stageName)}</h4>
            </div>
            <div class="stage-actions" style="display: flex; align-items: center;">
                 <div class="stage-item-total">
                    <span>مجموع وزن آیتم‌ها: </span>
                    <span class="total-item-weight-display">0.00%</span>
                </div>
                <button type="button" class="remove-stage-btn" title="حذف این مرحله" style="background: #c0392b; color: white; padding: 5px 10px; font-size: 12px; border-radius: 4px; margin: 0 10px;">حذف مرحله</button>
            </div>
        </div>
        <div class="stage-items-container"></div>`;

                const stageItemsContainer = stageGroupDiv.querySelector('.stage-items-container');
                const stageTitle = stageGroupDiv.querySelector('.stage-title');

                stageTitle.addEventListener('input', () => {
                    const newStageName = stageTitle.textContent;
                    stageGroupDiv.dataset.stageName = newStageName; // Update dataset
                    stageGroupDiv.querySelectorAll('.item-stage-input').forEach(input => input.value = newStageName);
                });

                if (items.length > 0) {
                    items.forEach(item => addItemRow(stageItemsContainer, item.item_text, item.stage, item.passing_status, item.item_weight, item.is_critical));
                } else {
                    addItemRow(stageItemsContainer, '', stageName);
                }

                itemsContainer.appendChild(stageGroupDiv);

                // Validate the stage weight after adding
                validateStageWeight(stageGroupDiv);
            }

            function addItemRow(container, text = '', stage = '', passing_status = 'OK', item_weight = 0, is_critical = false) {
                const itemDiv = document.createElement('div');
                itemDiv.className = 'item-row';
                const isOkSelected = passing_status === 'OK' ? 'selected' : '';
                const isNotOkSelected = passing_status === 'Not OK' ? 'selected' : '';
                const isCriticalChecked = (is_critical == '1' || is_critical === true) ? 'checked' : '';
                itemDiv.innerHTML = `
            <span class="item-drag-handle">☰</span>
            <input type="text" class="item-stage-input" value="${escapeHtml(stage)}" placeholder="مرحله..." list="stage-datalist">
            <input type="text" class="item-text-input" value="${escapeHtml(text)}" placeholder="متن سوال..." required>
            <select class="item-passing-status"><option value="OK" ${isOkSelected}>موفق (✓)</option><option value="Not OK" ${isNotOkSelected}>ناموفق (✗)</option></select>
            <div class="weight-input-group"><input type="number" class="item-weight-input" value="${parseFloat(item_weight).toFixed(2)}" min="0" max="100" step="1"><span>%</span></div>
            <div class="critical-input-group"><input type="checkbox" class="item-critical-input" title="آیتم کلیدی؟" ${isCriticalChecked}></div>
            <button type="button" class="remove-item-btn">حذف</button>`;
                container.appendChild(itemDiv);
            }

            function clearForm() {
                form.reset();
                templateIdField.value = '';
                itemsContainer.innerHTML = '';
                stageDatalist.innerHTML = '';
            }

            function initializeSortables() {
                new Sortable(itemsContainer, {
                    animation: 150,
                    handle: '.stage-header',
                    group: 'stages',
                    forceFallback: true,
                    fallbackOnBody: true,
                    swapThreshold: 0.65
                });
                document.querySelectorAll('.stage-items-container').forEach(container => {
                    new Sortable(container, {
                        animation: 150,
                        handle: '.item-drag-handle',
                        group: 'items'
                    });
                });
            }

            function validateStageWeight(stageGroup) {
                const totalDisplay = stageGroup.querySelector('.total-item-weight-display');
                let total = 0;
                stageGroup.querySelectorAll('.item-weight-input').forEach(input => total += parseFloat(input.value) || 0);
                totalDisplay.textContent = total.toFixed(2) + '%';
                const isStageValid = Math.abs(total - 100.00) < 0.01;
                totalDisplay.classList.toggle('valid', isStageValid);
                totalDisplay.classList.toggle('invalid', !isStageValid);
                validateAllStageWeights();
            }

            /**
             * NEW: This version provides clear feedback on the save button itself.
             */
            function validateAllStageWeights() {
                let allStagesAreValid = true;
                let invalidStages = [];

                document.querySelectorAll('.stage-group').forEach(stageGroup => {
                    const totalDisplay = stageGroup.querySelector('.total-item-weight-display');
                    if (totalDisplay && totalDisplay.classList.contains('invalid')) {
                        allStagesAreValid = false;
                        const stageTitle = stageGroup.querySelector('.stage-title');
                        if (stageTitle) {
                            invalidStages.push(stageTitle.textContent);
                        }
                    }
                });

                saveBtn.disabled = !allStagesAreValid;

                if (allStagesAreValid) {
                    validationMessage.textContent = '';
                    validationMessage.classList.remove('error');
                    saveBtn.title = 'ذخیره قالب';
                } else {
                    validationMessage.textContent = `ذخیره غیرفعال است! لطفا مجموع وزن آیتم ها را در مراحل زیر به ۱۰۰٪ برسانید: ${invalidStages.join(', ')}`;
                    validationMessage.classList.add('error');
                    saveBtn.title = validationMessage.textContent;
                }
            }

            // --- CONSOLIDATED EVENT LISTENERS ---
            itemsContainer.addEventListener('click', e => {
                if (e.target.classList.contains('remove-item-btn')) {
                    const stageGroup = e.target.closest('.stage-group');
                    e.target.closest('.item-row').remove();
                    if (stageGroup) {
                        validateStageWeight(stageGroup);
                    }
                }
                if (e.target.classList.contains('remove-stage-btn')) {
                    const stageGroup = e.target.closest('.stage-group');
                    const stageName = stageGroup.querySelector('.stage-title').textContent;
                    if (confirm(`آیا از حذف کامل مرحله "${stageName}" و تمام آیتم‌های آن اطمینان دارید؟`)) {
                        stageGroup.remove();
                        // Re-validate after stage removal
                        validateAllStageWeights();
                    }
                }
            });
            itemsContainer.addEventListener('input', e => {
                if (e.target.classList.contains('item-weight-input')) {
                    const stageGroup = e.target.closest('.stage-group');
                    if (stageGroup) {
                        validateStageWeight(stageGroup);
                    }
                }
            });
            templateList.addEventListener('click', e => {
                if (e.target.classList.contains('edit-btn')) loadTemplateForEditing(e.target.dataset.id, false);
                if (e.target.classList.contains('copy-btn')) loadTemplateForEditing(e.target.dataset.id, true);
            });

            document.getElementById('add-item-btn').addEventListener('click', () => {
                let firstStageContainer = itemsContainer.querySelector('.stage-items-container');
                if (!firstStageContainer) {
                    addStageGroup("مرحله جدید", []);
                    firstStageContainer = itemsContainer.querySelector('.stage-items-container');
                    initializeSortables();
                }
                const stageName = firstStageContainer.closest('.stage-group').dataset.stageName;
                addItemRow(firstStageContainer, '', stageName);
            });

            document.getElementById('clear-form-btn').addEventListener('click', clearForm);

            form.addEventListener('submit', e => {
                e.preventDefault();

                // Double-check validation before submitting
                validateAllStageWeights();
                if (saveBtn.disabled) {
                    alert(validationMessage.textContent);
                    return;
                }

                saveBtn.disabled = true;
                saveBtn.textContent = 'در حال ذخیره...';

                const formData = new FormData(form);

                // Collect items data
                const items = [];
                document.querySelectorAll('.item-row').forEach(row => {
                    const stage = row.querySelector('.item-stage-input').value.trim();
                    const text = row.querySelector('.item-text-input').value.trim();
                    const passingStatus = row.querySelector('.item-passing-status').value;
                    const weight = parseFloat(row.querySelector('.item-weight-input').value) || 0;
                    const isCritical = row.querySelector('.item-critical-input').checked ? 1 : 0;

                    if (text) { // Only add items with text
                        items.push({
                            stage: stage,
                            item_text: text,
                            passing_status: passingStatus,
                            item_weight: weight,
                            is_critical: isCritical
                        });
                    }
                });

                formData.append('items', JSON.stringify(items));

                fetch('api/save_template.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) return response.text().then(text => {
                            throw new Error('Server error: ' + text)
                        });
                        return response.json();
                    })
                    .then(data => {
                        alert(data.message);
                        if (data.status === 'success') {
                            clearForm();
                            loadTemplates();
                        }
                    })
                    .catch(error => {
                        console.error('Save failed:', error);
                        alert('خطا در ذخیره‌سازی. لطفا کنسول مرورگر (F12) را برای جزئیات خطا بررسی کنید.');
                    })
                    .finally(() => {
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'ذخیره قالب';
                        validateAllStageWeights();
                    });
            });

            // --- INITIAL LOAD ---
            loadTemplates();
        });
    </script>
    <?php require_once 'footer.php'; ?>

</body>

</html>