<?php
// /public_html/ghom/workflow_manager.php (FINAL CORRECTED LOGIC)
require_once __DIR__ . '/../../sercon/bootstrap.php';
secureSession();

if (!in_array($_SESSION['role'], ['admin', 'superuser'])) {
    http_response_code(403);
    die("Access Denied.");
}

$pageTitle = "مدیریت مراحل بازرسی";
require_once __DIR__ . '/header_ghom.php';

$stages_by_template = [];
try {
    $pdo = getProjectDBConnection('ghom');
    $pdo->exec("SET NAMES 'utf8mb4'");

    // 1. Get all unique stages defined in checklist_items
    $defined_stages_stmt = $pdo->query(
        "SELECT DISTINCT t.template_id, t.template_name, ci.stage
         FROM checklist_items ci
         JOIN checklist_templates t ON ci.template_id = t.template_id
         WHERE ci.stage IS NOT NULL AND ci.stage != ''"
    );
    $all_defined_stages = $defined_stages_stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

    // 2. Get the currently saved order from inspection_stages
    $ordered_stages_stmt = $pdo->query("SELECT * FROM inspection_stages");
    $saved_order = [];
    foreach ($ordered_stages_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $saved_order[$row['template_id']][$row['stage']] = $row['display_order'];
    }

    // 3. Merge the two lists to create the final, correctly sorted list
    foreach ($all_defined_stages as $template_id => $stages) {
        $template_name = $stages[0]['template_name'];
        $final_stage_list = [];
        foreach ($stages as $stage_info) {
            $stage_name = $stage_info['stage'];
            $final_stage_list[] = [
                'template_id' => $template_id,
                'template_name' => $template_name,
                'stage' => $stage_name,
                // Use saved order, or a large number to push new stages to the end
                'display_order' => $saved_order[$template_id][$stage_name] ?? 9999
            ];
        }
        // Sort by the display order
        usort($final_stage_list, fn($a, $b) => $a['display_order'] <=> $b['display_order']);
        $stages_by_template[$template_name] = $final_stage_list;
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
    <style>
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
            padding: 15px;
            margin: 5px 0;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            cursor: move;
            display: flex;
            align-items: center;
            font-size: 1.1em;
        }

        .drag-handle {
            font-size: 1.5em;
            margin-left: 15px;
            color: #999;
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
    </style>
</head>

<body>
    <div class="container">
        <h1><?php echo escapeHtml($pageTitle); ?></h1>
        <p>مراحل را بکشید و رها کنید تا ترتیب فرآیند بازرسی برای هر قالب مشخص شود.</p>

        <?php if (empty($stages_by_template)): ?>
            <p>هیچ مرحله‌ای در چک‌لیست‌ها تعریف نشده است.</p>
        <?php else: ?>
            <?php foreach ($stages_by_template as $template_name => $stages): ?>
                <div class="workflow-group">
                    <h2>قالب: <?php echo escapeHtml($template_name); ?></h2>
                    <div class="stage-list" data-template-id="<?php echo $stages[0]['template_id']; ?>">
                        <?php foreach ($stages as $stage): ?>
                            <div class="stage-item" data-stage-name="<?php echo escapeHtml($stage['stage']); ?>">
                                <span class="drag-handle">☰</span>
                                <?php echo escapeHtml($stage['stage']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <button id="saveOrderBtn" class="save-btn">ذخیره ترتیب</button>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // This JavaScript is now correct and doesn't need to be changed.
            // It reads the data from the HTML and sends it to the API.
            const saveBtn = document.getElementById('saveOrderBtn');
            const stageLists = document.querySelectorAll('.stage-list');

            stageLists.forEach(list => {
                new Sortable(list, {
                    animation: 150,
                    handle: '.drag-handle',
                });
            });

            saveBtn.addEventListener('click', function() {
                saveBtn.disabled = true;
                saveBtn.textContent = 'در حال ذخیره...';

                const payload = [];
                stageLists.forEach(list => {
                    const templateId = list.dataset.templateId;
                    list.querySelectorAll('.stage-item').forEach((item, index) => {
                        payload.push({
                            template_id: templateId,
                            stage_name: item.dataset.stageName, // JS sends stage_name key
                            display_order: index
                        });
                    });
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
                            alert('ترتیب با موفقیت ذخیره شد.');
                        } else {
                            throw new Error(data.message);
                        }
                    })
                    .catch(err => {
                        alert('خطا در ذخیره‌سازی: ' + err.message);
                    })
                    .finally(() => {
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'ذخیره ترتیب';
                    });
            });
        });
    </script>
</body>

</html>