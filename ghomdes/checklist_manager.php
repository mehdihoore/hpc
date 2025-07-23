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
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

<head>
    <meta charset="UTF-8">
    <title><?php echo escapeHtml($pageTitle); ?></title>
    <style>
        @font-face {
            font-family: "Samim";
            src: url("assets/fonts/Samim-FD.woff2") format("woff2");
        }

        body {
            font-family: "Samim", sans-serif;
            background-color: #f4f7f6;
            direction: rtl;
        }

        #manager-container {
            max-width: 900px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        h1,
        h2 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .layout {
            display: flex;
            gap: 30px;
        }

        .layout>div {
            padding: 15px;
            border-radius: 5px;
        }

        .layout .template-list-container {
            flex: 1;
            background-color: #ecf0f1;
        }

        .layout .template-form-container {
            flex: 2;
        }

        #template-list {
            list-style-type: none;
            padding: 0;
        }

        #template-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #bdc3c7;
        }

        #template-list li:last-child {
            border-bottom: none;
        }

        #template-list .edit-btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .item-row {
            display: grid;
            grid-template-columns: 1fr 2fr auto;
            /* Stage | Text | Button */
            gap: 10px;
            margin-bottom: 8px;
            align-items: center;
        }

        .item-row input {
            flex-grow: 1;
        }

        .item-row .remove-item-btn {
            background: #e74c3c;
            color: white;
            border: none;
            cursor: pointer;
            padding: 0 12px;
        }

        #add-item-btn,
        #clear-form-btn {
            background-color: #95a5a6;
            color: white;
            border: none;
            padding: 10px;
            cursor: pointer;
            border-radius: 4px;
            margin-top: 10px;
        }

        #template-form button[type="submit"] {
            background-color: #2ecc71;
            color: white;
            font-weight: bold;
            font-size: 1.1em;
        }

        footer {
            background-color: #343a40;
            color: #f8f9fa;
            text-align: center;
            padding: 20px;
            width: 100%;
            margin-top: auto;
            font-size: 0.9em;
        }

        footer p {
            margin: 0;
        }

        .copy-btn {
            background: #f39c12 !important;
            /* Orange color for copy */
            margin-left: 5px;
        }

        .edit-btn {
            background: #3498db;
        }

        .template-actions button {
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
        }

        #template-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #bdc3c7;
        }

        .item-row {
            /* This changes your grid to a more flexible flex layout */
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            padding: 8px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }

        .item-drag-handle {
            cursor: move;
            color: #999;
            font-size: 1.2em;
            padding: 0 5px;
        }

        .stage-group {
            border: 1px solid #007bff;
            border-radius: 5px;
            margin-bottom: 20px;
            background: #fff;
        }

        .stage-header {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            background-color: #f0f8ff;
            /* A light blue for the header */
            cursor: move;
            /* Indicates the whole header is draggable */
            border-bottom: 1px solid #007bff;
        }

        .stage-title {
            font-weight: bold;
            color: #0056b3;
            margin: 0;
        }

        .stage-items-container {
            padding: 15px;
        }
    </style>
</head>

<body>
    <div id="manager-container">
        <h1><?php echo escapeHtml($pageTitle); ?></h1>
        <div class="layout" style="display:flex; gap:30px;">
            <div class="template-list-container" style="flex:1;">
                <h2>قالب‌های موجود</h2>
                <ul id="template-list" style="list-style:none; padding:0;"></ul>
            </div>
            <div class="template-form-container" style="flex:2;">
                <h2>ایجاد / ویرایش / کپی</h2>
                <datalist id="stage-datalist"></datalist>
                <form id="template-form">
                    <input type="hidden" id="template_id" name="template_id">
                    <div class="form-group">
                        <label for="template_name">نام قالب</label>
                        <input type="text" id="template_name" name="template_name" required>
                    </div>
                    <div class="form-group">
                        <label for="element_type">نوع المان (Element Type)</label>
                        <input type="text" id="element_type" name="element_type" required placeholder="e.g., GFRC, Glass">
                    </div>
                    <hr>
                    <h3>آیتم‌های چک‌لیست</h3>
                    <div id="items-container">
                        <div style="display: grid; grid-template-columns: 1fr 2fr auto; gap: 10px; font-weight: bold; margin-bottom: 5px; padding-right: 40px;">
                            <span>مرحله (Stage)</span>
                            <span>متن سوال</span>
                        </div>
                    </div>
                    <button type="button" id="add-item-btn" style="background: #95a5a6;">افزودن آیتم</button>
                    <hr>
                    <button type="submit" class="save-btn" style="background:#2ecc71;">ذخیره قالب</button>
                    <button type="button" id="clear-form-btn" style="background:#34495e;">فرم جدید</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const templateList = document.getElementById('template-list');
            const form = document.getElementById('template-form');
            const itemsContainer = document.getElementById('items-container');
            const templateIdField = document.getElementById('template_id');
            const templateNameField = document.getElementById('template_name');
            const elementTypeField = document.getElementById('element_type');
            const stageDatalist = document.getElementById('stage-datalist');

            // --- MAIN FUNCTIONS ---

            function loadTemplates() {
                fetch('api/get_templates.php').then(res => res.json()).then(templates => {
                    templateList.innerHTML = '';
                    templates.forEach(t => {
                        const li = document.createElement('li');
                        li.innerHTML = `
                    <span>${t.template_name} (<b>${t.element_type}</b>)</span> 
                    <span class="template-actions">
                        <button data-id="${t.template_id}" class="copy-btn">کپی</button>
                        <button data-id="${t.template_id}" class="edit-btn">ویرایش</button>
                    </span>`;
                        templateList.appendChild(li);
                    });
                });
            }

            function loadTemplateForEditing(id, isCopy = false) {
                fetch(`api/get_template_details.php?id=${id}`).then(res => res.json()).then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }

                    clearForm();

                    if (isCopy) {
                        templateIdField.value = ''; // Makes it a new template on save
                        templateNameField.value = data.template.template_name + ' - کپی';
                        elementTypeField.value = data.template.element_type;
                        alert('قالب کپی شد. لطفاً نام قالب را قبل از ذخیره تغییر دهید.');
                    } else {
                        templateIdField.value = data.template.template_id;
                        templateNameField.value = data.template.template_name;
                        elementTypeField.value = data.template.element_type;
                    }

                    // Populate the datalist for stage autocomplete
                    if (data.existing_stages && Array.isArray(data.existing_stages)) {
                        stageDatalist.innerHTML = data.existing_stages.map(s => `<option value="${s}"></option>`).join('');
                    }

                    // --- NEW: Group items by stage before rendering ---
                    const itemsByStage = data.items.reduce((acc, item) => {
                        const stage = item.stage || "مرحله تعریف نشده"; // Group items without a stage
                        if (!acc[stage]) acc[stage] = [];
                        acc[stage].push(item);
                        return acc;
                    }, {});

                    // Render each stage group
                    for (const stageName in itemsByStage) {
                        const items = itemsByStage[stageName];
                        addStageGroup(stageName, items);
                    }

                    initializeSortables();
                });
            }

            function addStageGroup(stageName, items = []) {
                const stageGroupDiv = document.createElement('div');
                stageGroupDiv.className = 'stage-group';
                stageGroupDiv.dataset.stageName = stageName;

                stageGroupDiv.innerHTML = `
        <div class="stage-header">
            <span class="item-drag-handle">☰</span>
            <h4 class="stage-title">${stageName}</h4>
        </div>
        <div class="stage-items-container"></div>
    `;

                const stageItemsContainer = stageGroupDiv.querySelector('.stage-items-container');
                if (items.length > 0) {
                    // --- THIS IS THE FIX ---
                    // Changed item.stage_name to the correct item.stage
                    items.forEach(item => addItemRow(stageItemsContainer, item.item_text, item.stage));
                } else {
                    addItemRow(stageItemsContainer, '', stageName);
                }

                itemsContainer.appendChild(stageGroupDiv);
            }

            function addItemRow(container, text = '', stage = '') {
                const itemDiv = document.createElement('div');
                itemDiv.className = 'item-row';
                itemDiv.innerHTML = `
            <span class="item-drag-handle">☰</span>
            <input type="text" class="item-stage-input" value="${stage}" placeholder="مرحله را انتخاب یا تایپ کنید" list="stage-datalist">
            <input type="text" class="item-text-input" value="${text}" placeholder="متن سوال..." required>
            <button type="button" class="remove-item-btn">حذف</button>`;
                container.appendChild(itemDiv);
            }

            function clearForm() {
                form.reset();
                templateIdField.value = '';
                itemsContainer.innerHTML = ''; // Clear all stage groups
                stageDatalist.innerHTML = '';
            }

            function initializeSortables() {
                const itemsContainer = document.getElementById('items-container');

                // Makes the STAGE GROUPS sortable
                new Sortable(itemsContainer, {
                    animation: 150,
                    handle: '.stage-header', // Use the header to drag the whole group
                    group: 'stages',

                    // --- THIS IS THE FIX ---
                    // This creates a clone of the element being dragged, which prevents
                    // conflicts with the browser's native scrolling behavior.
                    forceFallback: true,
                    fallbackOnBody: true,
                    swapThreshold: 0.65
                });

                // Makes the ITEMS within each stage sortable
                document.querySelectorAll('.stage-items-container').forEach(container => {
                    new Sortable(container, {
                        animation: 150,
                        handle: '.item-drag-handle', // Use the handle to drag individual items
                        group: 'items'
                    });
                });
            }

            // --- EVENT LISTENERS ---

            templateList.addEventListener('click', e => {
                if (e.target.classList.contains('edit-btn')) {
                    loadTemplateForEditing(e.target.dataset.id, false);
                }
                if (e.target.classList.contains('copy-btn')) {
                    loadTemplateForEditing(e.target.dataset.id, true);
                }
            });

            itemsContainer.addEventListener('click', e => {
                if (e.target.classList.contains('remove-item-btn')) {
                    e.target.closest('.item-row').remove();
                }
            });

            document.getElementById('add-item-btn').addEventListener('click', () => {
                // Adds a new item to the FIRST stage group, or creates a new group if none exist
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

                // --- NEW: Logic to save the reordered stages and items ---
                const formData = new FormData();
                formData.append('template_id', templateIdField.value);
                formData.append('template_name', templateNameField.value);
                formData.append('element_type', elementTypeField.value);

                // Iterate through the DOM to get the correct order
                document.querySelectorAll('.stage-group').forEach(stageGroup => {
                    const stageName = stageGroup.querySelector('.stage-title').textContent;
                    stageGroup.querySelectorAll('.item-row').forEach(itemRow => {
                        // For each item, append its stage and text to the form data arrays
                        formData.append('item_stages[]', itemRow.querySelector('.item-stage-input').value || stageName);
                        formData.append('item_texts[]', itemRow.querySelector('.item-text-input').value);
                    });
                });

                fetch('api/save_template.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        alert(data.message);
                        if (data.status === 'success') {
                            clearForm();
                            loadTemplates();
                        }
                    });
            });

            // Initial load
            loadTemplates();
        });
    </script>
    <?php require_once 'footer.php'; ?>

</body>

</html>