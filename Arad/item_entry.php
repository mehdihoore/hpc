<?php
// item_entry.php (FINAL & COMPLETE)

require_once __DIR__ . '/../../sercon/bootstrap.php';

// --- Authorization & Project Setup ---
secureSession();
$current_file_path = __DIR__;
$expected_project_key = 'arad'; // Simplified
if (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Arad') === false) {
    die("خطای پیکربندی: پروژه قابل تشخیص نیست.");
}
if (!isLoggedIn() || ($_SESSION['current_project_config_key'] ?? '') !== $expected_project_key) {
    header('Location: /select_project.php');
    exit();
}
$allowed_roles = ['admin', 'supervisor', 'superuser', 'planner', 'user'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header('Location: dashboard.php?msg=unauthorized');
    exit();
}
$can_edit = in_array($_SESSION['role'], ['admin', 'supervisor', 'superuser', 'planner']);

// ==================================================================
//  POST Handling for Adding NEW Items (Runs Before HTML Output)
// ==================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_items') {
    $floor_to_add = trim($_POST['floor'] ?? '');
    $submittedItems = $_POST['items'] ?? [];
    $pdo = getProjectDBConnection();

    if (empty($floor_to_add) || !is_numeric($floor_to_add)) {
        $_SESSION['form_message'] = ['type' => 'error', 'text' => 'شماره طبقه برای افزودن آیتم نامعتبر است.'];
    } elseif (empty($submittedItems)) {
        $_SESSION['form_message'] = ['type' => 'error', 'text' => 'هیچ آیتمی برای ثبت ارسال نشده است.'];
    } else {
        $pdo->beginTransaction();
        try {
            $stmtItem = $pdo->prepare("INSERT INTO hpc_items (item_name, item_code, floor, quantity, status_sent, status_made) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtComp = $pdo->prepare("INSERT INTO item_components (hpc_item_id, component_type, component_name, attribute_1, attribute_2, attribute_3, component_quantity, unit, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtFile = $pdo->prepare("INSERT INTO item_files (hpc_item_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)");

            $status_sent = isset($_POST['overall_status_sent']) ? 1 : 0;
            $status_made = isset($_POST['overall_status_made']) ? 1 : 0;

            foreach ($submittedItems as $itemData) {
                if (empty($itemData['item_name']) || empty($itemData['item_code']) || (int)($itemData['quantity'] ?? 0) <= 0) continue;
                $stmtItem->execute([$itemData['item_name'], $itemData['item_code'], $floor_to_add, $itemData['quantity'], $status_sent, $status_made]);
                $hpcItemId = $pdo->lastInsertId();

                $detailedComponents = json_decode($itemData['detailed_components_json'] ?? '[]', true);
                foreach ($detailedComponents as $c) {
                    $attr1 = $c['attributes']['نام'] ?? $c['attributes']['نوع'] ?? $c['attributes']['شکل'] ?? $c['attributes']['قطر'] ?? $c['attributes']['سایز'] ?? null;
                    $attr2 = $c['attributes']['طول'] ?? $c['attributes']['ابعاد'] ?? $c['attributes']['وزن در متر'] ?? $c['attributes']['تعداد'] ?? null;
                    $attr3 = $c['attributes']['ضخامت'] ?? $c['attributes']['وزن'] ?? null;
                    $stmtComp->execute([$hpcItemId, $c['type'], $c['name'], $attr1, $attr2, $attr3, $c['quantity'], $c['unit'], $c['notes']]);
                }

                $uploadedFiles = json_decode($itemData['uploaded_files_json'] ?? '[]', true);
                if (!empty($uploadedFiles)) {
                    $permanentUploadDir = __DIR__ . '/uploads/item_documents/';
                    if (!is_dir($permanentUploadDir)) {
                        mkdir($permanentUploadDir, 0777, true);
                    }
                    foreach ($uploadedFiles as $file) {
                        $tempFilePath = $file['path'];
                        if (strpos(realpath($tempFilePath), realpath(__DIR__ . '/uploads/temp/')) === 0 && file_exists($tempFilePath)) {
                            $permanentFileName = uniqid('file-', true) . '-' . preg_replace("/[^a-zA-Z0-9.\-_]/", "", basename($file['name']));
                            $permanentFilePath = $permanentUploadDir . $permanentFileName;
                            if (rename($tempFilePath, $permanentFilePath)) {
                                $stmtFile->execute([$hpcItemId, $file['name'], $permanentFileName, $file['type']]);
                            }
                        }
                    }
                }
            }
            $pdo->commit();
            header("Location: item_entry.php?floor=$floor_to_add&msg=add_success");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            logError("Item Add Error: " . $e->getMessage());
            $_SESSION['form_message'] = ['type' => 'error', 'text' => 'خطا در ثبت اطلاعات: ' . $e->getMessage()];
        }
    }
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}

// --- Start Page Output ---
$pageTitle = "مدیریت قطعات طبقات - " . escapeHtml($_SESSION['current_project_name'] ?? 'پروژه');
require_once __DIR__ . '/header.php';

// --- Message Display Logic ---
$message = '';
$messageType = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'add_success') {
        $message = 'آیتم‌های جدید با موفقیت افزوده شدند.';
        $messageType = 'success';
    }
    if ($_GET['msg'] === 'edit_success') {
        $message = 'جزئیات آیتم با موفقیت بروزرسانی شد.';
        $messageType = 'success';
    }
}
if (isset($_SESSION['form_message'])) {
    $message = $_SESSION['form_message']['text'];
    $messageType = $_SESSION['form_message']['type'];
    unset($_SESSION['form_message']);
}

// --- Data Fetching & Configurations ---
$selected_floor = $_GET['floor'] ?? 'all';
$items_on_floor = [];
$pdo = getProjectDBConnection();
try {
    $whereClause = ($selected_floor !== 'all' && is_numeric($selected_floor)) ? "floor = :floor" : "1=1";
    $params = ($selected_floor !== 'all' && is_numeric($selected_floor)) ? [':floor' => $selected_floor] : [];
    $stmt = $pdo->prepare("SELECT * FROM hpc_items WHERE $whereClause ORDER BY floor ASC, item_code ASC");
    $stmt->execute($params);
    $items_on_floor = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    logError("Error fetching items for floor view: " . $e->getMessage());
    $items_on_floor = [];
}
$itemComponentConfigurations = [
    'دستک' => ['profiles' => ['display' => 'پروفیل', 'attributes' => ['نام' => 'text', 'طول' => 'number', 'وزن در متر' => 'number']], 'bolts' => ['display' => 'پیچ', 'attributes' => ['نوع' => 'text', 'قطر' => 'number']], 'plates' => ['display' => 'پلیت', 'attributes' => ['شکل' => 'text', 'ابعاد' => 'text']]],
    'رینگ سر بالا' => ['profiles' => ['display' => 'پروفیل', 'attributes' => ['نام' => 'text', 'طول' => 'number']], 'bolts' => ['display' => 'پیچ', 'attributes' => ['نوع' => 'text', 'تعداد' => 'number']]],
    'رینگ سر پایین' => ['profiles' => ['display' => 'پروفیل', 'attributes' => ['نام' => 'text', 'طول' => 'number']], 'bolts' => ['display' => 'پیچ', 'attributes' => ['نوع' => 'text', 'تعداد' => 'number']]],
    'مهاری' => ['rods' => ['display' => 'میلگرد', 'attributes' => ['قطر' => 'number', 'طول' => 'number']], 'connectors' => ['display' => 'اتصال دهنده', 'attributes' => ['نوع' => 'text']]],
    'قفس' => ['bars' => ['display' => 'میلگرد', 'attributes' => ['نوع' => 'text', 'طول' => 'number']], 'mesh' => ['display' => 'توری مش', 'attributes' => ['ابعاد' => 'text']]],
    'نبشی پشت پنل' => ['angle_irons' => ['display' => 'نبشی', 'attributes' => ['سایز' => 'text', 'طول' => 'number']], 'fasteners' => ['display' => 'بست', 'attributes' => ['نوع' => 'text']]],
];
$itemOptionsForSelect = array_keys($itemComponentConfigurations);
?>

<body class="bg-gray-100 font-sans text-right" dir="rtl">
    <div class="container mx-auto p-6">
        <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">مدیریت قطعات بر اساس طبقه</h2>

        <?php if ($message): ?>
            <div class="p-3 mb-4 rounded-md <?php echo $messageType === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>"><?php echo escapeHtml($message); ?></div>
        <?php endif; ?>

        <div class="bg-white p-4 rounded-lg shadow-md mb-8">
            <form id="floorSelectForm" action="item_entry.php" method="GET" class="flex items-center space-x-4 space-x-reverse">
                <label for="floor_select" class="text-lg font-medium text-gray-700">نمایش طبقه:</label>
                <select id="floor_select" name="floor" class="w-64 p-2 border rounded-md" onchange="this.form.submit()">
                    <option value="all" <?php echo ($selected_floor === 'all') ? 'selected' : ''; ?>>نمایش همه طبقات</option>
                    <?php for ($i = 1; $i <= 24; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo ((string)$selected_floor === (string)$i) ? 'selected' : ''; ?>>طبقه <?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>

        <div class="bg-white p-6 rounded-lg shadow-md mb-8">
            <h3 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-3">لیست قطعات <?php if ($selected_floor !== 'all') echo " (طبقه " . escapeHtml($selected_floor) . ")";
                                                                                            else echo "(همه طبقات)"; ?></h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <?php if ($selected_floor === 'all'): ?><th class="py-3 px-2 text-right text-xs font-medium text-gray-500 uppercase">طبقه</th><?php endif; ?>
                            <th class="py-3 px-2 text-right text-xs font-medium text-gray-500 uppercase">آیتم</th>
                            <th class="py-3 px-2 text-right text-xs font-medium text-gray-500 uppercase">کد</th>
                            <th class="py-3 px-2 text-right text-xs font-medium text-gray-500 uppercase">تعداد</th>
                            <th class="py-3 px-2 text-center text-xs font-medium text-gray-500 uppercase">وضعیت (ارسال/ساخت)</th>
                            <th class="py-3 px-2 text-center text-xs font-medium text-gray-500 uppercase">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="item_management_table_body">
                        <?php if (empty($items_on_floor)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-6 text-gray-500">هیچ آیتمی برای نمایش یافت نشد.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items_on_floor as $item): ?>
                                <tr data-item-id="<?php echo $item['id']; ?>" data-item-name="<?php echo escapeHtml($item['item_name']); ?>">
                                    <?php if ($selected_floor === 'all'): ?><td class="py-2 px-2 whitespace-nowrap text-sm"><?php echo escapeHtml($item['floor']); ?></td><?php endif; ?>
                                    <td class="py-2 px-2 whitespace-nowrap text-sm"><?php echo escapeHtml($item['item_name']); ?></td>
                                    <td <?php if ($can_edit) echo 'contenteditable="true"'; ?> class="py-2 px-2 whitespace-nowrap text-sm editable bg-yellow-50" data-field="item_code"><?php echo escapeHtml($item['item_code']); ?></td>
                                    <td <?php if ($can_edit) echo 'contenteditable="true"'; ?> class="py-2 px-2 whitespace-nowrap text-sm editable bg-yellow-50" data-field="quantity"><?php echo escapeHtml($item['quantity']); ?></td>
                                    <td class="py-2 px-2 whitespace-nowrap text-sm text-center">
                                        <label class="inline-flex items-center mr-2 editable-checkbox" data-field="status_sent" title="ارسال شده"><input type="checkbox" <?php if (!$can_edit) echo 'disabled'; ?> <?php echo $item['status_sent'] ? 'checked' : ''; ?> class="form-checkbox h-4 w-4 text-green-600 rounded"></label>
                                        <label class="inline-flex items-center editable-checkbox" data-field="status_made" title="ساخته شده"><input type="checkbox" <?php if (!$can_edit) echo 'disabled'; ?> <?php echo $item['status_made'] ? 'checked' : ''; ?> class="form-checkbox h-4 w-4 text-blue-600 rounded"></label>
                                    </td>
                                    <td class="py-2 px-2 whitespace-nowrap text-sm text-center">
                                        <button type="button" class="edit-details-btn bg-blue-500 hover:bg-blue-600 text-white text-xs py-1 px-2 rounded">جزئیات</button>
                                        <?php if ($can_edit): ?><button type="button" class="delete-item-btn bg-red-500 hover:bg-red-600 text-white text-xs py-1 px-2 rounded ml-1">حذف</button><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($can_edit && $selected_floor !== 'all'): ?>
            <div class="bg-white p-6 rounded-lg shadow-md mt-12">
                <h3 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-3">افزودن قطعات جدید به طبقه <?php echo escapeHtml($selected_floor); ?></h3>
                <form action="item_entry.php?floor=<?php echo escapeHtml($selected_floor); ?>" method="POST" id="mainEntryForm">
                    <input type="hidden" name="action" value="add_items">
                    <input type="hidden" name="floor" value="<?php echo escapeHtml($selected_floor); ?>">
                    <div class="mb-6">
                        <div class="flex items-center space-x-4 space-x-reverse"><label class="inline-flex items-center"><input type="checkbox" name="overall_status_sent" value="1" class="form-checkbox h-5 w-5 text-green-600 rounded"><span class="mr-2">ارسال شده</span></label><label class="inline-flex items-center"><input type="checkbox" name="overall_status_made" value="1" class="form-checkbox h-5 w-5 text-blue-600 rounded"><span class="mr-2">ساخته شده</span></label></div>
                    </div>
                    <div class="overflow-x-auto mb-6">
                        <table class="min-w-full" id="item_entry_table">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="py-2 px-3 border-b">آیتم*</th>
                                    <th class="py-2 px-3 border-b">کد*</th>
                                    <th class="py-2 px-3 border-b">تعداد*</th>
                                    <th class="py-2 px-3 border-b text-center">جزئیات/مدارک</th>
                                    <th class="py-2 px-3 border-b text-center">عملیات</th>
                                </tr>
                            </thead>
                            <tbody id="item_entry_tbody"></tbody>
                        </table>
                        <table style="display: none;">
                            <tr id="item-row-template">
                                <td class="py-2 px-3 border-b"><select name="items[__INDEX__][item_name]" class="w-full p-2 border rounded-md item-name-select" disabled>
                                        <option value="">انتخاب</option><?php foreach ($itemOptionsForSelect as $o): ?><option value="<?php echo escapeHtml($o); ?>"><?php echo escapeHtml($o); ?></option><?php endforeach; ?>
                                    </select></td>
                                <td class="py-2 px-3 border-b"><input type="text" name="items[__INDEX__][item_code]" class="w-full p-2 border rounded-md" placeholder="کد منحصر به فرد" disabled></td>
                                <td class="py-2 px-3 border-b"><input type="number" name="items[__INDEX__][quantity]" min="1" class="w-full p-2 border rounded-md" placeholder="تعداد" disabled></td>
                                <td class="py-2 px-3 border-b text-center"><input type="hidden" name="items[__INDEX__][detailed_components_json]" class="detailed-components-json" value="[]" disabled><input type="hidden" name="items[__INDEX__][uploaded_files_json]" class="uploaded-files-json" value="[]" disabled><button type="button" class="open-detail-modal-btn bg-blue-500 hover:bg-blue-600 text-white text-sm py-1 px-3 rounded">افزودن جزئیات</button>
                                    <div class="text-xs text-gray-600 mt-1 detail-summary"></div>
                                </td>
                                <td class="py-2 px-3 border-b text-center"><button type="button" class="duplicate-row-btn bg-green-500 text-white text-xs py-1 px-2 rounded">کپی</button><button type="button" class="remove-row-btn bg-red-500 text-white text-xs py-1 px-2 rounded ml-1">حذف</button></td>
                            </tr>
                        </table>
                    </div>
                    <div class="mt-4 flex justify-between items-center"><button type="button" id="add-item-row-btn" class="bg-indigo-500 hover:bg-indigo-600 text-white font-bold py-2 px-4 rounded-lg"> + افزودن آیتم</button><button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-lg text-lg">ذخیره آیتم‌های جدید</button></div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div id="detailModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center p-4 z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto relative rtl:text-right">
            <div class="p-6 border-b flex justify-between items-center">
                <h3 class="text-xl font-semibold" id="modalTitle">جزئیات آیتم</h3><button type="button" class="close-modal text-gray-500 hover:text-gray-700 text-2xl font-bold">×</button>
            </div>
            <div class="p-6">
                <input type="hidden" id="modal_mode"><input type="hidden" id="modal_item_id">
                <div id="modal-status-section" class="mb-4 pb-4 border-b">
                    <h4 class="text-lg font-semibold text-gray-700 mb-3">وضعیت آیتم:</h4>
                    <div class="flex items-center space-x-4 space-x-reverse"><label class="inline-flex items-center"><input type="checkbox" id="modal_status_sent" class="form-checkbox h-5 w-5 text-green-600 rounded"><span class="mr-2 text-gray-700">ارسال شده</span></label><label class="inline-flex items-center"><input type="checkbox" id="modal_status_made" class="form-checkbox h-5 w-5 text-blue-600 rounded"><span class="mr-2 text-gray-700">ساخته شده</span></label></div>
                </div>
                <h4 class="text-lg font-semibold text-gray-700 mb-3">اجزاء:</h4>
                <div id="dynamic-component-forms" class="space-y-4"></div>
                <button type="button" id="add-component-row-btn" class="bg-green-500 text-white text-sm py-2 px-4 rounded mt-2">افزودن جزء جدید</button>
                <div id="file-upload-section" class="mt-6 pt-4 border-t">
                    <h4 class="text-lg font-semibold text-gray-700 mb-3">مدارک:</h4><input type="file" id="modal_file_upload" multiple class="w-full p-2 border rounded-md file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 mb-3">
                    <div id="uploaded-files-preview" class="space-y-2"></div>
                </div>
            </div>
            <div class="p-6 border-t flex justify-end space-x-2 space-x-reverse"><button type="button" id="saveModalDetails" class="bg-blue-600 text-white font-bold py-2 px-4 rounded-lg">ذخیره جزئیات</button><button type="button" class="close-modal bg-gray-400 text-white font-bold py-2 px-4 rounded-lg">بستن</button></div>
        </div>
    </div>

    <script>
        const itemComponentConfigurationsJS = <?php echo json_encode($itemComponentConfigurations); ?>;
        const canEdit = <?php echo json_encode($can_edit); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            const managementTableBody = document.getElementById('item_management_table_body');
            const addItemTableBody = document.getElementById('item_entry_tbody');
            const detailModal = document.getElementById('detailModal');
            let activeModalRow = null;

            // --- Logic for "Add New Items" Form ---
            if (addItemTableBody) {
                const addBtn = document.getElementById('add-item-row-btn');
                const template = document.getElementById('item-row-template');
                let counter = 0;

                function addNewItemRow(cloneFromRow = null) {
                    const newRow = template.cloneNode(true);
                    newRow.id = '';
                    newRow.classList.add('item-row');
                    newRow.querySelectorAll('input, select').forEach(i => {
                        i.disabled = false;
                        i.name = i.name.replace('__INDEX__', counter);
                    });
                    if (cloneFromRow) {
                        newRow.querySelector('.item-name-select').value = cloneFromRow.querySelector('.item-name-select').value;
                        newRow.querySelector('input[name*="[quantity]"]').value = cloneFromRow.querySelector('input[name*="[quantity]"]').value;
                        const componentsJson = cloneFromRow.querySelector('.detailed-components-json').value;
                        const filesJson = cloneFromRow.querySelector('.uploaded-files-json').value;
                        newRow.querySelector('.detailed-components-json').value = componentsJson;
                        newRow.querySelector('.uploaded-files-json').value = filesJson;
                        updateSummary(newRow, JSON.parse(componentsJson), JSON.parse(filesJson));
                    }
                    addItemTableBody.appendChild(newRow);
                    counter++;
                }
                addBtn.addEventListener('click', () => addNewItemRow());
                addNewItemRow();
                addItemTableBody.addEventListener('click', function(e) {
                    const target = e.target;
                    if (target.classList.contains('remove-row-btn')) {
                        target.closest('tr').remove();
                    }
                    if (target.classList.contains('duplicate-row-btn')) {
                        addNewItemRow(target.closest('tr'));
                    }
                    if (target.classList.contains('open-detail-modal-btn')) {
                        activeModalRow = target.closest('tr');
                        const itemName = activeModalRow.querySelector('.item-name-select').value;
                        if (!itemName) {
                            alert('لطفا ابتدا نوع آیتم را انتخاب کنید.');
                            return;
                        }
                        const components = JSON.parse(activeModalRow.querySelector('.detailed-components-json').value || '[]');
                        const files = JSON.parse(activeModalRow.querySelector('.uploaded-files-json').value || '[]');
                        openDetailModal('add', null, itemName, components, files);
                    }
                });
            }

            // --- Logic for Management Table ---
            if (managementTableBody) {
                managementTableBody.addEventListener('click', function(e) {
                    const target = e.target;
                    const row = target.closest('tr');
                    if (!row) return;
                    if (target.classList.contains('edit-details-btn')) {
                        activeModalRow = row;
                        openDetailModal('edit', row.dataset.itemId, row.dataset.itemName);
                    }
                    if (canEdit && target.classList.contains('delete-item-btn')) {
                        if (confirm('آیا از حذف این آیتم اطمینان دارید؟')) {
                            fetch('delete_item.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: `itemId=${row.dataset.itemId}`
                            }).then(res => res.json()).then(data => {
                                if (data.success) {
                                    row.remove();
                                } else {
                                    alert('خطا: ' + data.message);
                                }
                            });
                        }
                    }
                });
                if (canEdit) {
                    managementTableBody.addEventListener('focusout', function(e) {
                        const target = e.target;
                        if (target.classList.contains('editable') && target.hasAttribute('contenteditable')) {
                            if (target.dataset.originalValue !== target.textContent.trim()) {
                                sendUpdate(target.closest('tr').dataset.itemId, target.dataset.field, target.textContent.trim(), target);
                            }
                        }
                    });
                    managementTableBody.addEventListener('focusin', e => {
                        if (e.target.classList.contains('editable')) e.target.dataset.originalValue = e.target.textContent;
                    });
                    managementTableBody.addEventListener('change', e => {
                        const target = e.target;
                        if (target.type === 'checkbox' && target.closest('.editable-checkbox')) {
                            const cell = target.closest('.editable-checkbox');
                            sendUpdate(cell.closest('tr').dataset.itemId, cell.dataset.field, target.checked ? 1 : 0, cell);
                        }
                    });
                }
            }

            // --- Unified Modal Logic ---
            function openDetailModal(mode, itemId, itemName, components = null, files = null) {
                document.getElementById('modal_mode').value = mode;
                document.getElementById('modal_item_id').value = itemId;
                document.getElementById('modalTitle').textContent = `جزئیات آیتم: ${itemName}`;
                let canUserEditModal = (mode === 'add' && canEdit) || (mode === 'edit' && canEdit);
                document.getElementById('add-component-row-btn').style.display = canUserEditModal ? 'inline-block' : 'none';
                document.getElementById('saveModalDetails').style.display = canUserEditModal ? 'inline-block' : 'none';
                document.getElementById('file-upload-section').style.display = canUserEditModal ? 'block' : 'none';
                document.getElementById('modal_status_sent').disabled = !canUserEditModal;
                document.getElementById('modal_status_made').disabled = !canUserEditModal;
                if (mode === 'edit') {
                    fetch(`api_get_item_details.php?id=${itemId}`).then(res => res.json()).then(data => {
                        if (data.success && data.item) {
                            document.getElementById('modal_status_sent').checked = !!parseInt(data.item.status_sent);
                            document.getElementById('modal_status_made').checked = !!parseInt(data.item.status_made);
                            renderDynamicComponents(itemName, data.components);
                            renderUploadedFiles(data.files);
                            detailModal.classList.remove('hidden');
                        } else {
                            alert('خطا در بارگذاری جزئیات: ' + (data.message || 'پاسخ نامعتبر از سرور.'));
                        }
                    }).catch(err => alert('خطای شبکه در دریافت جزئیات.'));
                } else {
                    document.getElementById('modal_status_sent').checked = false;
                    document.getElementById('modal_status_made').checked = false;
                    renderDynamicComponents(itemName, components || []);
                    renderUploadedFiles(files || []);
                    detailModal.classList.remove('hidden');
                }
            }

            document.getElementById('saveModalDetails').addEventListener('click', function() {
                const mode = document.getElementById('modal_mode').value;
                const components = gatherComponentsFromModal();
                const {
                    filesToKeep,
                    newFiles
                } = gatherFilesFromModal();
                if (mode === 'edit') {
                    const payload = {
                        itemId: document.getElementById('modal_item_id').value,
                        status_sent: document.getElementById('modal_status_sent').checked ? 1 : 0,
                        status_made: document.getElementById('modal_status_made').checked ? 1 : 0,
                        components,
                        files_to_keep: filesToKeep,
                        new_files: newFiles
                    };
                    fetch('api_update_item_details.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    }).then(res => res.json()).then(data => {
                        if (data.success) {
                            const floor = new URLSearchParams(window.location.search).get('floor') || 'all';
                            window.location.href = `${window.location.pathname}?floor=${floor}&msg=edit_success`;
                        } else {
                            alert('خطا در ذخیره جزئیات: ' + data.message);
                        }
                    });
                } else {
                    activeModalRow.querySelector('.detailed-components-json').value = JSON.stringify(components);
                    activeModalRow.querySelector('.uploaded-files-json').value = JSON.stringify(newFiles);
                    updateSummary(activeModalRow, components, newFiles);
                    detailModal.classList.add('hidden');
                }
            });
            document.querySelectorAll('.close-modal').forEach(b => b.addEventListener('click', () => detailModal.classList.add('hidden')));

            // --- File Upload & Preview ---
            document.getElementById('modal_file_upload').addEventListener('change', function() {
                if (this.files.length === 0) return;
                const formData = new FormData();
                Array.from(this.files).forEach(file => formData.append('files[]', file));
                fetch('upload_handler.php', {
                    method: 'POST',
                    body: formData
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        data.uploaded_files.forEach(file => addFileToPreview(file, false));
                        this.value = '';
                    } else {
                        alert('خطا در آپلود فایل: ' + data.message);
                    }
                });
            });

            function renderUploadedFiles(filesData) {
                document.getElementById('uploaded-files-preview').innerHTML = '';
                if (filesData) filesData.forEach(file => addFileToPreview(file, true));
            }

            function addFileToPreview(file, isPermanent) {
                const previewContainer = document.getElementById('uploaded-files-preview');
                const fileDiv = document.createElement('div');
                fileDiv.className = 'uploaded-file-item flex items-center justify-between p-2 border rounded-md bg-gray-50 text-sm';
                const fileName = file.file_name || file.name;
                const filePath = file.file_path || file.path;
                fileDiv.dataset.fileName = fileName;
                fileDiv.dataset.filePath = filePath;
                fileDiv.dataset.fileType = file.file_type || file.type;
                fileDiv.dataset.isPermanent = isPermanent;
                fileDiv.dataset.fileId = file.id || '';
                const nameHtml = isPermanent ? `<a href="/Arad/uploads/item_documents/${fileName}" target="_blank" class="text-blue-600 hover:underline">${escapeHtml(fileName)}</a>` : `<span>${escapeHtml(fileName)}</span>`;
                fileDiv.innerHTML = `${nameHtml} ${canEdit ? `<button type="button" class="text-red-500 hover:text-red-700 ml-2 remove-uploaded-file">×</button>` : ''}`;
                if (canEdit) fileDiv.querySelector('.remove-uploaded-file')?.addEventListener('click', function() {
                    this.parentElement.remove();
                });
                previewContainer.appendChild(fileDiv);
            }

            // --- Helper Functions ---
            function sendUpdate(itemId, field, value, element) {
                element.style.backgroundColor = '#fff3cd';
                fetch('update_item_field.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `itemId=${itemId}&field=${field}&value=${encodeURIComponent(value)}`
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        element.style.backgroundColor = '#d4edda';
                    } else {
                        throw new Error(data.message);
                    }
                }).catch(err => {
                    element.style.backgroundColor = '#f8d7da';
                    alert('خطا در بروزرسانی: ' + err.message);
                }).finally(() => setTimeout(() => element.style.backgroundColor = '', 2000));
            }

            function updateSummary(rowElement, components, files) {
                const summaryDiv = rowElement.querySelector('.detail-summary');
                if (!summaryDiv) return;
                let summaryText = [];
                if (components && components.length > 0) summaryText.push(`اجزا: ${components.length}`);
                if (files && files.length > 0) summaryText.push(`مدارک: ${files.length}`);
                summaryDiv.textContent = summaryText.join(' | ');
            }

            function renderDynamicComponents(itemName, componentsData) {
                const formsContainer = document.getElementById('dynamic-component-forms');
                formsContainer.innerHTML = '';
                if (componentsData) componentsData.forEach(comp => formsContainer.appendChild(createComponentRow(itemName, comp)));
            }
            document.getElementById('add-component-row-btn').addEventListener('click', function() {
                const mode = document.getElementById('modal_mode').value;
                const itemName = (mode === 'edit') ? activeModalRow.dataset.itemName : activeModalRow.querySelector('.item-name-select').value;
                if (itemName) document.getElementById('dynamic-component-forms').appendChild(createComponentRow(itemName));
            });

            function createComponentRow(itemName, existingData = {}) {
                const itemConfig = itemComponentConfigurationsJS[itemName];
                if (!itemConfig) return document.createElement('div');
                const row = document.createElement('div');
                row.className = 'component-detail-row border border-blue-200 rounded-md p-4 mb-3 relative';
                const selectOptions = Object.keys(itemConfig).map(typeKey => `<option value="${typeKey}" ${typeKey === existingData.type ? 'selected' : ''}>${itemConfig[typeKey].display}</option>`).join('');
                row.innerHTML = `${canEdit ? '<button type="button" class="remove-component-row absolute top-2 left-2 bg-red-500 text-white rounded-full h-6 w-6 flex items-center justify-center text-xs">×</button>' : ''}<div class="mb-2"><label class="block text-sm font-medium">نوع جزء:</label><select class="w-full p-2 border rounded-md component-type-select" ${!canEdit ? 'disabled' : ''}><option value="">انتخاب</option>${selectOptions}</select></div><div class="grid grid-cols-2 gap-4" data-attributes-container></div><div class="mt-2"><label class="block text-sm font-medium">تعداد/مقدار:</label><input type="number" class="w-full p-2 border rounded-md component-quantity" min="0" value="${existingData.quantity || ''}" ${!canEdit ? 'readonly' : ''}></div><div class="mt-2"><label class="block text-sm font-medium">واحد:</label><input type="text" class="w-full p-2 border rounded-md component-unit" value="${existingData.unit || ''}" ${!canEdit ? 'readonly' : ''}></div><div class="mt-2"><label class="block text-sm font-medium">توضیحات:</label><textarea class="w-full p-2 border rounded-md component-notes" rows="2" ${!canEdit ? 'readonly' : ''}>${escapeHtml(existingData.notes || '')}</textarea></div>`;
                const typeSelect = row.querySelector('.component-type-select');
                const attrsContainer = row.querySelector('[data-attributes-container]');
                const unitInput = row.querySelector('.component-unit');

                function updateAttrs() {
                    renderComponentAttributes(attrsContainer, typeSelect.value, itemConfig, unitInput, existingData.attributes);
                }
                if (canEdit) typeSelect.addEventListener('change', updateAttrs);
                if (canEdit) row.querySelector('.remove-component-row')?.addEventListener('click', () => row.remove());
                if (existingData.type) updateAttrs();
                return row;
            }

            function renderComponentAttributes(container, typeKey, itemConfig, unitInput, attributesData = {}) {
                container.innerHTML = '';
                if (!typeKey || !itemConfig[typeKey] || !itemConfig[typeKey].attributes) return;
                for (const attrName in itemConfig[typeKey].attributes) {
                    const inputType = itemConfig[typeKey].attributes[attrName];
                    const value = attributesData ? (attributesData[attrName] || '') : '';
                    container.innerHTML += `<div><label class="block text-sm font-medium">${attrName}:</label><input type="${inputType}" class="w-full p-2 border rounded-md component-attribute" data-attribute-name="${attrName}" value="${escapeHtml(value)}" ${!canEdit ? 'readonly' : ''}></div>`;
                }
                if (itemConfig[typeKey].default_unit && !unitInput.value) unitInput.value = itemConfig[typeKey].default_unit;
            }

            function gatherComponentsFromModal() {
                const components = [];
                document.getElementById('dynamic-component-forms').querySelectorAll('.component-detail-row').forEach(row => {
                    const type = row.querySelector('.component-type-select').value;
                    if (!type) return;
                    const attributes = {};
                    let componentNameValue = '';
                    row.querySelectorAll('.component-attribute').forEach(attrInput => {
                        const attrName = attrInput.dataset.attributeName;
                        attributes[attrName] = attrInput.value;
                        if (['نام', 'نوع', 'شکل', 'سایز'].includes(attrName)) componentNameValue = attrInput.value;
                    });
                    components.push({
                        type,
                        name: componentNameValue,
                        attributes,
                        quantity: row.querySelector('.component-quantity').value,
                        unit: row.querySelector('.component-unit').value,
                        notes: row.querySelector('.component-notes').value
                    });
                });
                return components;
            }

            function gatherFilesFromModal() {
                const filesToKeep = [];
                const newFiles = [];
                document.getElementById('uploaded-files-preview').querySelectorAll('.uploaded-file-item').forEach(div => {
                    const fileData = {
                        id: div.dataset.fileId,
                        name: div.dataset.fileName,
                        path: div.dataset.filePath,
                        type: div.dataset.fileType
                    };
                    if (div.dataset.isPermanent === 'true') {
                        filesToKeep.push(fileData);
                    } else {
                        newFiles.push(fileData);
                    }
                });
                return {
                    filesToKeep,
                    newFiles
                };
            }

            function escapeHtml(text) {
                if (typeof text !== 'string' && typeof text !== 'number') return text || '';
                return String(text).replace(/[&<>"']/g, m => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                })[m]);
            }
        });
    </script>
</body>

</html>