<?php
// /public_html/ghom/workflow_manager.php (FINAL CORRECTED LOGIC)
require_once __DIR__ . '/../../sercon/bootstrap.php';
secureSession();

if (!isLoggedIn()) {
    header('Location: /login.php?msg=login_required');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'superuser', 'user', 'cat', 'car', 'coa', 'crs'])) {
    http_response_code(403);
    require 'Access_Denied.php';
    exit;
}
$pageTitle = "مدیریت مراحل بازرسی";
require_once __DIR__ . '/header_ghom.php';

$stages_by_template = [];
$stages_by_template = [];
try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->exec("SET NAMES 'utf8mb4'");

    // ===================================================================
    // START: THE FINAL, SIMPLIFIED QUERY FOR CLEAN DATA
    // ===================================================================

    // Step 1: Get all templates.
    $templates_stmt = $pdo->query("SELECT template_id, template_name, unit_of_measure, cost_per_unit FROM checklist_templates");
    $all_templates = $templates_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare a statement to get all stages for a template.
    // Because the data is now clean, a simple SELECT is all we need.
    $stages_stmt = $pdo->prepare(
        "SELECT stage_id, stage, weight 
         FROM inspection_stages 
         WHERE template_id = :template_id 
         ORDER BY display_order ASC"
    );

    // Step 2: Loop through each template and build the final data structure
    foreach ($all_templates as $template) {
        // Get the stages for this specific template
        $stages_stmt->execute([':template_id' => $template['template_id']]);
        $stages = $stages_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Assemble the final structure for the UI
        $stages_by_template[$template['template_name']] = [
            'template_id'     => $template['template_id'],
            'template_name'   => $template['template_name'],
            'unit_of_measure' => $template['unit_of_measure'],
            'cost_per_unit'   => $template['cost_per_unit'],
            'stages'          => $stages
        ];
    }
    // ===================================================================
    // END: FINAL QUERY
    // ===================================================================

} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title><?php echo escapeHtml($pageTitle); ?></title>
    <style>
        @font-face {
            font-family: "Samim";
            src: url("/ghom/assets/fonts/Samim-FD.woff2") format("woff2"),
                url("/ghom/assets/fonts/Samim-FD.woff") format("woff"),
                url("/ghom/assets/fonts/Samim-FD.ttf") format("truetype");
        }

        /* Styles to make the page look clean and professional */
        body {
            font-family: "Samim", sans-serif;
            background-color: #f4f7f6;
        }

        .container {
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        h1,
        h2 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }

        .workflow-group {
            margin-bottom: 30px;
        }

        .stage-list {
            list-style: none;
            padding: 0;
        }

        .stage-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            margin: 5px 0;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            cursor: move;
        }

        .drag-handle {
            font-size: 1.5em;
            margin-left: 15px;
            color: #999;
        }

        .stage-name {
            flex-grow: 1;
            font-size: 1.1em;
        }

        .stage-item .stage-weight-input {
            /* This more specific selector ensures these rules are applied */
            width: 80px !important;
            /* Use !important to override any generic input styles */
            flex-grow: 0;
            /* Prevent it from growing in the flex container */
            flex-shrink: 0;
            /* Prevent it from shrinking */
            text-align: right;
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1em;
            margin: 0 5px;
            /* Add some horizontal margin */
        }


        .percent-sign {
            margin-right: 5px;
            font-size: 1.1em;
            color: #555;
        }

        .stage-total {
            text-align: left;
            padding: 10px;
            margin-top: 10px;
            border-top: 2px solid #3498db;
            font-weight: bold;
        }

        .total-weight-display.valid {
            color: #28a745;
        }

        .total-weight-display.invalid {
            color: #dc3545;
        }

        .save-btn {
            display: block;
            margin-top: 20px;
            padding: 12px 30px;
            font-size: 1.1em;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .save-btn:hover:not(:disabled) {
            background-color: #218838;
        }

        .save-btn:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }

        .template-settings-form {
            display: flex;
            gap: 20px;
            padding: 15px;
            background: #e9f5ff;
            border: 1px solid #bde0fe;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .template-settings-form .form-group {
            flex: 1;
        }

        .template-settings-form label {
            font-weight: bold;
            font-size: 13px;
            display: block;
            margin-bottom: 5px;
        }

        .template-settings-form input,
        .template-settings-form select {
            width: 100%;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1><?php echo escapeHtml($pageTitle); ?></h1>
        <p>مراحل و هزینه‌ها را برای هر قالب مشخص کنید. مجموع وزن مراحل باید ۱۰۰٪ باشد.</p>

        <?php if (empty($stages_by_template)): ?>
            <p>هیچ قالبی در سیستم تعریف نشده است.</p>
        <?php else: ?>
            <?php foreach ($stages_by_template as $template_name => $template): ?>
                <div class="workflow-group">
                    <h2>قالب: <?php echo escapeHtml($template_name); ?></h2>

                    <!-- Cost Settings Form -->
                    <div class="template-settings-form" data-template-id="<?php echo $template['template_id']; ?>">
                        <div class="form-group">
                            <label>واحد اندازه‌گیری:</label>
                            <select class="unit-of-measure">
                                <option value="m2" <?php if (($template['unit_of_measure'] ?? 'm2') == 'm2') echo 'selected'; ?>>متر مربع (m²)</option>
                                <option value="meter" <?php if (($template['unit_of_measure'] ?? '') == 'meter') echo 'selected'; ?>>متر طول (m)</option>
                                <option value="item" <?php if (($template['unit_of_measure'] ?? '') == 'item') echo 'selected'; ?>>عددی (item)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>هزینه هر واحد (<?php echo defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : '$'; ?>):</label>
                            <input type="number" class="cost-per-unit" value="<?php echo number_format($template['cost_per_unit'] ?? 0.00, 2, '.', ''); ?>">
                        </div>
                    </div>

                    <!-- Stage List -->
                    <div class="stage-list" data-template-id="<?php echo $template['template_id']; ?>">
                        <?php if (empty($template['stages'])): ?>
                            <p>هیچ مرحله‌ای برای این قالب تعریف نشده است.</p>
                        <?php else: ?>
                            <?php foreach ($template['stages'] as $stage): ?>
                                <div class="stage-item" data-stage-id="<?php echo $stage['stage_id']; ?>">
                                    <span class="drag-handle">☰</span>
                                    <span class="stage-name"><?php echo escapeHtml($stage['stage']); ?></span>
                                    <input type="number" class="stage-weight-input" value="<?php echo number_format($stage['weight'] ?? 0.00, 2); ?>" min="0" max="100" step="0.01">
                                    <span class="percent-sign">%</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Total Weight Display -->
                    <div class="stage-total">
                        <span>مجموع وزن: </span>
                        <span class="total-weight-display">100.00%</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <button id="saveOrderBtn" class="save-btn">ذخیره تمام تغییرات</button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const saveBtn = document.getElementById('saveOrderBtn');

            // --- SETUP FOR EACH WORKFLOW GROUP ---
            document.querySelectorAll('.workflow-group').forEach(group => {
                const list = group.querySelector('.stage-list');
                const totalDisplay = group.querySelector('.total-weight-display');

                // 1. Initialize Drag-and-Drop functionality
                if (list) {
                    new Sortable(list, {
                        animation: 150,
                        handle: '.drag-handle',
                    });
                }

                // 2. Function to calculate and update total weight for a group
                const updateGroupTotal = () => {
                    if (!totalDisplay) return; // Exit if there's no total display for this group
                    let total = 0;
                    group.querySelectorAll('.stage-weight-input').forEach(input => {
                        total += parseFloat(input.value) || 0;
                    });

                    totalDisplay.textContent = total.toFixed(2) + '%';

                    // Check if the total is effectively 100
                    if (Math.abs(total - 100.00) < 0.01) {
                        totalDisplay.className = 'total-weight-display valid';
                        saveBtn.disabled = false; // Enable save button if all totals are valid
                    } else {
                        totalDisplay.className = 'total-weight-display invalid';
                        saveBtn.disabled = true; // Disable save button if any total is incorrect
                    }
                };

                // 3. Add event listener to each weight input to update total on change
                group.querySelectorAll('.stage-weight-input').forEach(input => {
                    input.addEventListener('input', updateGroupTotal);
                });

                // 4. Initial calculation on page load
                updateGroupTotal();
            });

            // --- SAVE BUTTON EVENT LISTENER ---
            saveBtn.addEventListener('click', function() {
                // Double check all totals before saving
                let allTotalsAreValid = true;
                document.querySelectorAll('.total-weight-display').forEach(display => {
                    if (display.classList.contains('invalid')) {
                        allTotalsAreValid = false;
                    }
                });

                if (!allTotalsAreValid) {
                    alert('خطا: مجموع وزن برای یک یا چند قالب ۱۰۰٪ نیست. لطفا قبل از ذخیره مقادیر را اصلاح کنید.');
                    return;
                }

                saveBtn.disabled = true;
                saveBtn.textContent = 'در حال ذخیره...';

                const payload = {
                    templates: [],
                    stages: []
                };

                // Gather all data from the page
                document.querySelectorAll('.workflow-group').forEach(group => {
                    const settingsForm = group.querySelector('.template-settings-form');
                    const stageList = group.querySelector('.stage-list');

                    if (settingsForm && stageList) {
                        const templateId = settingsForm.dataset.templateId;

                        // 1. Gather template-level data (cost and unit)
                        payload.templates.push({
                            template_id: templateId,
                            unit_of_measure: settingsForm.querySelector('.unit-of-measure').value,
                            cost_per_unit: parseFloat(settingsForm.querySelector('.cost-per-unit').value) || 0
                        });

                        // 2. Gather stage-level data (order and weight)
                        stageList.querySelectorAll('.stage-item').forEach((item, index) => {
                            payload.stages.push({
                                template_id: templateId,
                                stage_id: item.dataset.stageId,
                                display_order: index,
                                weight: parseFloat(item.querySelector('.stage-weight-input').value) || 0
                            });
                        });
                    }
                });

                fetch('/ghom/api/save_workflow_order.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            alert('تمام تغییرات با موفقیت ذخیره شد.');
                            // Optionally reload the page to confirm data persistence
                            location.reload();
                        } else {
                            throw new Error(data.message || 'An unknown error occurred.');
                        }
                    })
                    .catch(err => {
                        alert('خطا در ذخیره‌سازی: ' + err.message);
                        saveBtn.disabled = false; // Re-enable button on error
                        saveBtn.textContent = 'ذخیره تمام تغییرات';
                    })
                    .finally(() => {
                        // This block will run regardless of success or failure,
                        // but the catch block already handles re-enabling the button.
                    });
            });
        });
    </script>
    <?php require_once 'footer.php'; ?>
</body>

</html>