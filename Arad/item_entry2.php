<?php
// item_entry.php (Located inside /Arad/) - REVISED

require_once __DIR__ . '/../../sercon/bootstrap.php'; // Go up one level to find sercon/

secureSession(); // Initializes session and security checks
// Determine which project this instance of the file belongs to.
$current_file_path = __DIR__;
$expected_project_key = null;
if (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Fereshteh') !== false) {
    $expected_project_key = 'fereshteh';
} elseif (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Arad') !== false) {
    $expected_project_key = 'arad';
} else {
    logError("item_entry.php accessed from unexpected path: " . $current_file_path);
    die("خطای پیکربندی: پروژه قابل تشخیص نیست.");
}

// --- Authorization ---
if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}
if (!isset($_SESSION['current_project_config_key']) || $_SESSION['current_project_config_key'] !== $expected_project_key) {
    logError("Project context mismatch or no project selected for item_entry.php. User ID: " . ($_SESSION['user_id'] ?? 'N/A'));
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}
$allowed_roles = ['admin', 'supervisor', 'planner', 'user', 'superuser'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    logError("Role '{$_SESSION['role']}' unauthorized access attempt to {$expected_project_key}/item_entry.php. User ID: " . $_SESSION['user_id']);
    header('Location: dashboard.php?msg=unauthorized');
    exit();
}

$pageTitle = "ورود اطلاعات قطعات - " . escapeHtml($_SESSION['current_project_name'] ?? 'پروژه');
require_once __DIR__ . '/header.php';

// Define predefined items and their component types with example attributes
$itemComponentConfigurations = [
    'دستک' => [
        'profiles' => ['display' => 'پروفیل', 'attributes' => ['نام' => 'text', 'طول' => 'number', 'وزن در متر' => 'number', 'واحد' => 'text'], 'default_unit' => 'متر'],
        'bolts' => ['display' => 'پیچ', 'attributes' => ['نوع' => 'text', 'قطر' => 'number', 'طول' => 'number', 'واحد' => 'text'], 'default_unit' => 'عدد'],
        'plates' => ['display' => 'پلیت', 'attributes' => ['شکل' => 'text', 'ابعاد' => 'text', 'ضخامت' => 'number', 'وزن' => 'number', 'واحد' => 'text'], 'default_unit' => 'کیلوگرم'],
    ],
    'رینگ سر بالا' => [
        'profiles' => ['display' => 'پروفیل', 'attributes' => ['نام' => 'text', 'طول' => 'number', 'واحد' => 'text'], 'default_unit' => 'متر'],
        'bolts' => ['display' => 'پیچ', 'attributes' => ['نوع' => 'text', 'تعداد' => 'number', 'واحد' => 'text'], 'default_unit' => 'عدد'],
    ],
    'رینگ سر پایین' => [
        'profiles' => ['display' => 'پروفیل', 'attributes' => ['نام' => 'text', 'طول' => 'number', 'واحد' => 'text'], 'default_unit' => 'متر'],
        'bolts' => ['display' => 'پیچ', 'attributes' => ['نوع' => 'text', 'تعداد' => 'number', 'واحد' => 'text'], 'default_unit' => 'عدد'],
    ],
    'مهاری' => [
        'rods' => ['display' => 'میلگرد', 'attributes' => ['قطر' => 'number', 'طول' => 'number', 'واحد' => 'text'], 'default_unit' => 'متر'],
        'connectors' => ['display' => 'اتصال دهنده', 'attributes' => ['نوع' => 'text', 'واحد' => 'text'], 'default_unit' => 'عدد'],
    ],
    'قفس' => [
        'bars' => ['display' => 'میلگرد', 'attributes' => ['نوع' => 'text', 'طول' => 'number', 'واحد' => 'text'], 'default_unit' => 'متر'],
        'mesh' => ['display' => 'توری مش', 'attributes' => ['ابعاد' => 'text', 'واحد' => 'text'], 'default_unit' => 'متر مربع'],
    ],
    'نبشی پشت پنل' => [
        'angle_irons' => ['display' => 'نبشی', 'attributes' => ['سایز' => 'text', 'طول' => 'number', 'واحد' => 'text'], 'default_unit' => 'متر'],
        'fasteners' => ['display' => 'بست', 'attributes' => ['نوع' => 'text', 'واحد' => 'text'], 'default_unit' => 'عدد'],
    ],
];
$itemOptionsForSelect = array_keys($itemComponentConfigurations);


// Handle form submission
$message = '';
$messageType = ''; // 'success' or 'error'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $floor = trim($_POST['floor'] ?? '');
    $locationCode = trim($_POST['location_code'] ?? ''); // Optional
    $overallStatusSent = isset($_POST['overall_status_sent']) ? 1 : 0;
    $overallStatusMade = isset($_POST['overall_status_made']) ? 1 : 0;
    $submittedItems = $_POST['items'] ?? [];

    if (empty($floor) || !is_numeric($floor) || $floor < 0) {
        $message = 'لطفاً شماره طبقه را به صورت عددی و صحیح وارد نمایید.';
        $messageType = 'error';
    } elseif (empty($submittedItems)) {
        $message = 'هیچ آیتمی برای ثبت ارسال نشده است. لطفاً حداقل یک آیتم اضافه کنید.';
        $messageType = 'error';
    } else {
        $pdo = getProjectDBConnection();
        $pdo->beginTransaction();
        try {
            foreach ($submittedItems as $index => $itemData) {
                $itemName = trim($itemData['item_name'] ?? '');
                $itemCode = trim($itemData['item_code'] ?? '');
                $quantity = (int)($itemData['quantity'] ?? 0);

                // Skip items with no name, code or quantity
                if (empty($itemName) || empty($itemCode) || $quantity <= 0) {
                    continue;
                }

                // Insert into hpc_items table
                $stmt = $pdo->prepare("
                    INSERT INTO hpc_items (item_name, item_code, floor, location_code, quantity, status_sent, status_made)
                    VALUES (:item_name, :item_code, :floor, :location_code, :quantity, :status_sent, :status_made)
                ");
                $stmt->execute([
                    ':item_name' => $itemName,
                    ':item_code' => $itemCode,
                    ':floor' => $floor,
                    ':location_code' => $locationCode ?: null, // Store null if empty
                    ':quantity' => $quantity,
                    ':status_sent' => $overallStatusSent,
                    ':status_made' => $overallStatusMade,
                ]);
                $hpcItemId = $pdo->lastInsertId();

                // Process and insert detailed components
                $detailedComponents = json_decode($itemData['detailed_components_json'] ?? '[]', true);
                if (!empty($detailedComponents)) {
                    $stmtComponent = $pdo->prepare("
                        INSERT INTO item_components (hpc_item_id, component_type, component_name, attribute_1, attribute_2, attribute_3, component_quantity, unit, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($detailedComponents as $component) {
                        $stmtComponent->execute([
                            $hpcItemId,
                            $component['type'] ?? null,
                            $component['name'] ?? null,
                            $component['attr1'] ?? null,
                            $component['attr2'] ?? null,
                            $component['attr3'] ?? null,
                            $component['quantity'] ?? 0,
                            $component['unit'] ?? null,
                            $component['notes'] ?? null
                        ]);
                    }
                }

                // Process and insert file uploads
                $uploadedFiles = json_decode($itemData['uploaded_files_json'] ?? '[]', true);
                if (!empty($uploadedFiles)) {
                    $stmtFile = $pdo->prepare("INSERT INTO item_files (hpc_item_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)");
                    $permanentUploadDir = __DIR__ . '/uploads/item_documents/';
                    if (!is_dir($permanentUploadDir)) {
                        mkdir($permanentUploadDir, 0777, true);
                    }
                    foreach ($uploadedFiles as $file) {
                        $tempFilePath = $file['path'];
                        $permanentFilePath = $permanentUploadDir . basename($tempFilePath);
                        if (file_exists($tempFilePath) && rename($tempFilePath, $permanentFilePath)) {
                            $stmtFile->execute([$hpcItemId, $file['name'], $permanentFilePath, $file['type']]);
                        } else {
                            logError("Failed to move uploaded file for item ID {$hpcItemId}: {$tempFilePath}");
                        }
                    }
                }
            } // End foreach

            $pdo->commit();
            $message = 'اطلاعات با موفقیت ثبت شد.';
            $messageType = 'success';
            $_POST = []; // Clear form on success
        } catch (PDOException | Exception $e) {
            $pdo->rollBack();
            logError("Error during item submission: " . $e->getMessage());
            $message = 'خطا در ثبت اطلاعات: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}
?>

<body class="bg-gray-100 font-sans text-right" dir="rtl">
    <div class="container mx-auto p-6 bg-white rounded-lg shadow-md mt-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">فرم ورود اطلاعات قطعات</h2>

        <?php if ($message): ?>
            <div class="p-3 mb-4 rounded-md <?php echo $messageType === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <?php echo escapeHtml($message); ?>
            </div>
        <?php endif; ?>

        <form action="item_entry.php" method="POST" id="mainEntryForm">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6 pb-6 border-b border-gray-200">
                <div>
                    <label for="floor" class="block text-sm font-medium text-gray-700 mb-1">شماره طبقه <span class="text-red-500">*</span></label>
                    <input type="number" id="floor" name="floor" value="<?php echo escapeHtml($_POST['floor'] ?? ''); ?>"
                        class="w-full p-2 border rounded-md focus:ring-blue-500 focus:border-blue-500" placeholder="مثال: 5" required>
                </div>
                <div>
                    <label for="location_code" class="block text-sm font-medium text-gray-700 mb-1">کد موقعیت / بچ (اختیاری)</label>
                    <input type="text" id="location_code" name="location_code" value="<?php echo escapeHtml($_POST['location_code'] ?? ''); ?>"
                        class="w-full p-2 border rounded-md focus:ring-blue-500 focus:border-blue-500" placeholder="مثال: Zone-A">
                </div>
                <div class="flex items-center space-x-4 space-x-reverse justify-end pt-5">
                    <label class="inline-flex items-center">
                        <input type="checkbox" name="overall_status_sent" value="1"
                            <?php echo (isset($_POST['overall_status_sent'])) ? 'checked' : ''; ?>
                            class="form-checkbox h-5 w-5 text-blue-600 rounded">
                        <span class="mr-2 text-gray-700">ارسال شده</span>
                    </label>
                    <label class="inline-flex items-center">
                        <input type="checkbox" name="overall_status_made" value="1"
                            <?php echo (isset($_POST['overall_status_made'])) ? 'checked' : ''; ?>
                            class="form-checkbox h-5 w-5 text-blue-600 rounded">
                        <span class="mr-2 text-gray-700">ساخته شده</span>
                    </label>
                </div>
            </div>

            <div class="overflow-x-auto mb-6">
                <table class="min-w-full bg-white border border-gray-300 rounded-lg" id="item_entry_table">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="py-2 px-3 border-b text-right text-gray-600">آیتم <span class="text-red-500">*</span></th>
                            <th class="py-2 px-3 border-b text-right text-gray-600">کد <span class="text-red-500">*</span></th>
                            <th class="py-2 px-3 border-b text-right text-gray-600">تعداد <span class="text-red-500">*</span></th>
                            <th class="py-2 px-3 border-b text-center text-gray-600">جزئیات/مدارک</th>
                            <th class="py-2 px-3 border-b text-center text-gray-600">عملیات</th>
                        </tr>
                    </thead>
                    <tbody id="item_entry_tbody">
                        <!-- Rows will be added here by JavaScript -->
                        <?php
                        // Re-populate rows on form error
                        if (!empty($_POST['items'])) {
                            foreach ($_POST['items'] as $index => $itemData) {
                                // This block will render the rows again if there was a submission error
                                // Note: A more robust solution would use JS to rebuild the state from a JSON blob
                                // But for server-side validation feedback, this is a reasonable approach.
                        ?>
                                <tr class="item-row">
                                    <td class="py-2 px-3 border-b">
                                        <select name="items[<?php echo $index; ?>][item_name]" class="w-full p-2 border rounded-md item-name-select">
                                            <option value="">انتخاب آیتم</option>
                                            <?php foreach ($itemOptionsForSelect as $option): ?>
                                                <option value="<?php echo escapeHtml($option); ?>" <?php echo ($itemData['item_name'] === $option) ? 'selected' : ''; ?>>
                                                    <?php echo escapeHtml($option); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="py-2 px-3 border-b"><input type="text" name="items[<?php echo $index; ?>][item_code]" value="<?php echo escapeHtml($itemData['item_code'] ?? ''); ?>" class="w-full p-2 border rounded-md" placeholder="کد منحصر به فرد"></td>
                                    <td class="py-2 px-3 border-b"><input type="number" name="items[<?php echo $index; ?>][quantity]" value="<?php echo escapeHtml($itemData['quantity'] ?? ''); ?>" min="1" class="w-full p-2 border rounded-md" placeholder="تعداد"></td>
                                    <td class="py-2 px-3 border-b text-center">
                                        <input type="hidden" name="items[<?php echo $index; ?>][detailed_components_json]" class="detailed-components-json" value="<?php echo escapeHtml($itemData['detailed_components_json'] ?? '[]'); ?>">
                                        <input type="hidden" name="items[<?php echo $index; ?>][uploaded_files_json]" class="uploaded-files-json" value="<?php echo escapeHtml($itemData['uploaded_files_json'] ?? '[]'); ?>">
                                        <button type="button" class="open-detail-modal-btn bg-blue-500 hover:bg-blue-600 text-white text-sm py-1 px-3 rounded">افزودن جزئیات</button>
                                        <div class="text-xs text-gray-600 mt-1 detail-summary"></div>
                                    </td>
                                    <td class="py-2 px-3 border-b text-center">
                                        <button type="button" class="duplicate-row-btn bg-green-500 hover:bg-green-600 text-white text-xs py-1 px-2 rounded">کپی</button>
                                        <button type="button" class="remove-row-btn bg-red-500 hover:bg-red-600 text-white text-xs py-1 px-2 rounded ml-1">حذف</button>
                                    </td>
                                </tr>
                        <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
                <!-- Hidden template row for cloning -->
                <table style="display: none;">
                    <tr id="item-row-template">
                        <td class="py-2 px-3 border-b">
                            <select name="items[__INDEX__][item_name]" class="w-full p-2 border rounded-md item-name-select" disabled>
                                <option value="">انتخاب آیتم</option>
                                <?php foreach ($itemOptionsForSelect as $option): ?>
                                    <option value="<?php echo escapeHtml($option); ?>"><?php echo escapeHtml($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="py-2 px-3 border-b"><input type="text" name="items[__INDEX__][item_code]" class="w-full p-2 border rounded-md" placeholder="کد منحصر به فرد" disabled></td>
                        <td class="py-2 px-3 border-b"><input type="number" name="items[__INDEX__][quantity]" min="1" class="w-full p-2 border rounded-md" placeholder="تعداد" disabled></td>
                        <td class="py-2 px-3 border-b text-center">
                            <input type="hidden" name="items[__INDEX__][detailed_components_json]" class="detailed-components-json" value="[]" disabled>
                            <input type="hidden" name="items[__INDEX__][uploaded_files_json]" class="uploaded-files-json" value="[]" disabled>
                            <button type="button" class="open-detail-modal-btn bg-blue-500 hover:bg-blue-600 text-white text-sm py-1 px-3 rounded">افزودن جزئیات</button>
                            <div class="text-xs text-gray-600 mt-1 detail-summary"></div>
                        </td>
                        <td class="py-2 px-3 border-b text-center">
                            <button type="button" class="duplicate-row-btn bg-green-500 hover:bg-green-600 text-white text-xs py-1 px-2 rounded">کپی</button>
                            <button type="button" class="remove-row-btn bg-red-500 hover:bg-red-600 text-white text-xs py-1 px-2 rounded ml-1">حذف</button>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="mt-4 flex justify-start">
                <button type="button" id="add-item-row-btn" class="bg-indigo-500 hover:bg-indigo-600 text-white font-bold py-2 px-4 rounded-lg shadow-md">
                    + افزودن آیتم جدید
                </button>
            </div>

            <div class="mt-8 text-center">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-8 rounded-lg shadow-md text-lg">
                    ثبت کلیه اطلاعات
                </button>
            </div>
        </form>
    </div>

    <!-- MODAL (No changes needed here) -->
    <div id="detailModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center p-4 z-50">
        <!-- ... Modal content is the same as your original file ... -->
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto relative rtl:text-right">
            <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-xl font-semibold text-gray-800" id="modalTitle">جزئیات آیتم</h3>
                <button type="button" class="close-modal text-gray-500 hover:text-gray-700 text-2xl font-bold">×</button>
            </div>
            <div class="p-6">
                <input type="hidden" id="modal_item_name_key">
                <input type="hidden" id="modal_current_row_index">

                <div id="dynamic-component-forms" class="space-y-4 mb-6">
                </div>
                <button type="button" id="add-component-row-btn" class="bg-green-500 hover:bg-green-600 text-white text-sm py-2 px-4 rounded mb-4">افزودن جزء جدید</button>

                <h4 class="text-lg font-semibold text-gray-700 mb-3 pt-4 border-t border-gray-100">آپلود مدارک:</h4>
                <input type="file" id="modal_file_upload" multiple class="w-full p-2 border rounded-md file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 mb-3">
                <div id="uploaded-files-preview" class="space-y-2">
                </div>
                <p class="text-xs text-gray-500 mt-1">حداکثر حجم هر فایل: 50MB. فرمت‌های مجاز: DWG, PDF, JPG/JPEG, PNG</p>

            </div>
            <div class="p-6 border-t border-gray-200 flex justify-end space-x-2 space-x-reverse">
                <button type="button" id="saveModalDetails" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">ذخیره جزئیات</button>
                <button type="button" class="close-modal bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-4 rounded-lg">بستن</button>
            </div>
        </div>
    </div>


    <link rel="stylesheet" href="/assets/css/bootstrap.rtl.min.css">
    <script src="/assets/js/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/moment.min.js"></script>
    <script src="/assets/js/moment-jalaali.js"></script>
    <script src="/assets/js/persian-datepicker.min.js"></script>
    <script src="/assets/js/mobile-detect.min.js"></script>

    <script src="/assets/js/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/jquery-3.6.0.min.js"></script>
    <script>
        const itemComponentConfigurationsJS = <?php echo json_encode($itemComponentConfigurations); ?>;

        // This will be the <tr> element of the row whose modal is currently open
        let activeModalRow = null;

        document.addEventListener('DOMContentLoaded', function() {
            // Main table and buttons
            const itemTableBody = document.getElementById('item_entry_tbody');
            const addItemBtn = document.getElementById('add-item-row-btn');
            const templateRow = document.getElementById('item-row-template');

            // Modal elements
            const detailModal = document.getElementById('detailModal');
            const modalTitle = document.getElementById('modalTitle');
            const dynamicComponentForms = document.getElementById('dynamic-component-forms');
            const addComponentRowBtn = document.getElementById('add-component-row-btn');
            const modalFileUpload = document.getElementById('modal_file_upload');
            const uploadedFilesPreview = document.getElementById('uploaded-files-preview');
            const saveModalDetailsBtn = document.getElementById('saveModalDetails');

            let rowIndexCounter = itemTableBody.getElementsByClassName('item-row').length;

            // --- Core Row Management ---

            function addNewRow(cloneFromRow = null) {
                const newRow = templateRow.cloneNode(true);
                newRow.id = '';
                newRow.classList.add('item-row');
                newRow.style.display = 'table-row';

                newRow.querySelectorAll('input, select').forEach(input => {
                    input.disabled = false;
                    input.name = input.name.replace('__INDEX__', rowIndexCounter);
                });

                if (cloneFromRow) {
                    const sourceSelect = cloneFromRow.querySelector('.item-name-select');
                    newRow.querySelector('.item-name-select').value = sourceSelect.value;
                    newRow.querySelector('input[name*="[quantity]"]').value = cloneFromRow.querySelector('input[name*="[quantity]"]').value;

                    const sourceComponentsJson = cloneFromRow.querySelector('.detailed-components-json').value;
                    const sourceFilesJson = cloneFromRow.querySelector('.uploaded-files-json').value;
                    newRow.querySelector('.detailed-components-json').value = sourceComponentsJson;
                    newRow.querySelector('.uploaded-files-json').value = sourceFilesJson;

                    updateSummary(newRow, JSON.parse(sourceComponentsJson), JSON.parse(sourceFilesJson));
                }

                itemTableBody.appendChild(newRow);
                rowIndexCounter++;
            }

            // --- Event Delegation for Dynamic Rows ---

            itemTableBody.addEventListener('click', function(e) {
                const target = e.target;

                if (target.classList.contains('remove-row-btn')) {
                    target.closest('tr').remove();
                } else if (target.classList.contains('duplicate-row-btn')) {
                    const rowToClone = target.closest('tr');
                    addNewRow(rowToClone);
                } else if (target.classList.contains('open-detail-modal-btn')) {
                    activeModalRow = target.closest('tr');
                    const itemName = activeModalRow.querySelector('.item-name-select').value;

                    if (!itemName) {
                        alert('لطفا ابتدا نوع آیتم را انتخاب کنید.');
                        return;
                    }

                    modalTitle.textContent = `جزئیات آیتم: ${itemName}`;

                    const componentsJson = activeModalRow.querySelector('.detailed-components-json').value;
                    const filesJson = activeModalRow.querySelector('.uploaded-files-json').value;

                    openDetailModal(itemName, JSON.parse(componentsJson), JSON.parse(filesJson));
                }
            });

            // Add first row on page load if table is empty, or re-init summaries for existing rows
            if (itemTableBody.children.length === 0) {
                addNewRow();
            } else {
                document.querySelectorAll('#item_entry_tbody .item-row').forEach(row => {
                    const components = JSON.parse(row.querySelector('.detailed-components-json').value);
                    const files = JSON.parse(row.querySelector('.uploaded-files-json').value);
                    updateSummary(row, components, files);
                });
            }

            addItemBtn.addEventListener('click', () => addNewRow());

            // --- Modal Logic ---

            function openDetailModal(itemName, componentsData, filesData) {
                // Render existing data into the modal
                renderDynamicComponents(itemName, componentsData);
                renderUploadedFiles(filesData);
                // Show the modal
                detailModal.classList.remove('hidden');
            }

            saveModalDetailsBtn.addEventListener('click', function() {
                if (!activeModalRow) return;

                const detailedComponents = [];
                dynamicComponentForms.querySelectorAll('.component-detail-row').forEach(row => {
                    const type = row.querySelector('.component-type-select').value;
                    const quantity = parseInt(row.querySelector('.component-quantity').value) || 0;
                    if (!type || quantity <= 0) return;

                    const unit = row.querySelector('.component-unit').value;
                    const notes = row.querySelector('.component-notes').value;
                    const attributes = {};
                    let componentNameValue = '';
                    row.querySelectorAll('.component-attribute').forEach(attrInput => {
                        const attrName = attrInput.dataset.attributeName;
                        const attrValue = attrInput.value;
                        attributes[attrName] = attrValue;
                        if (['نام', 'نوع', 'شکل', 'سایز'].includes(attrName)) {
                            componentNameValue = attrValue;
                        }
                    });

                    detailedComponents.push({
                        type: type,
                        name: componentNameValue,
                        attr1: attributes['نام'] || attributes['نوع'] || attributes['شکل'] || attributes['قطر'] || attributes['سایز'] || null,
                        attr2: attributes['طول'] || attributes['ابعاد'] || attributes['وزن در متر'] || attributes['تعداد'] || null,
                        attr3: attributes['ضخامت'] || attributes['وزن'] || null,
                        quantity: quantity,
                        unit: unit,
                        notes: notes,
                        attributes: attributes // Keep original attributes for re-editing
                    });
                });

                const uploadedFiles = [];
                uploadedFilesPreview.querySelectorAll('.uploaded-file-item').forEach(fileDiv => {
                    uploadedFiles.push({
                        name: fileDiv.dataset.fileName,
                        path: fileDiv.dataset.filePath,
                        type: fileDiv.dataset.fileType
                    });
                });

                activeModalRow.querySelector('.detailed-components-json').value = JSON.stringify(detailedComponents);
                activeModalRow.querySelector('.uploaded-files-json').value = JSON.stringify(uploadedFiles);

                updateSummary(activeModalRow, detailedComponents, uploadedFiles);
                detailModal.classList.add('hidden');
            });

            // Close Modal button(s)
            document.querySelectorAll('.close-modal').forEach(button => {
                button.addEventListener('click', function() {
                    detailModal.classList.add('hidden');
                    activeModalRow = null;
                });
            });

            // --- Modal Component Rendering ---

            function renderDynamicComponents(itemName, componentsData) {
                dynamicComponentForms.innerHTML = '';
                const itemConfig = itemComponentConfigurationsJS[itemName];
                if (!itemConfig || !componentsData) return;

                componentsData.forEach(comp => {
                    const componentRow = createComponentRow(itemConfig, comp);
                    dynamicComponentForms.appendChild(componentRow);
                });
            }

            addComponentRowBtn.addEventListener('click', function() {
                const itemName = activeModalRow.querySelector('.item-name-select').value;
                const itemConfig = itemComponentConfigurationsJS[itemName];
                const componentRow = createComponentRow(itemConfig);
                dynamicComponentForms.appendChild(componentRow);
            });

            function createComponentRow(itemConfig, existingData = {}) {
                const componentRow = document.createElement('div');
                componentRow.className = 'component-detail-row border border-blue-200 rounded-md p-4 mb-3 relative';

                const selectOptions = Object.keys(itemConfig).map(typeKey =>
                    `<option value="${typeKey}" ${typeKey === existingData.type ? 'selected' : ''}>${itemConfig[typeKey].display}</option>`
                ).join('');

                componentRow.innerHTML = `
                    <button type="button" class="remove-component-row absolute top-2 left-2 bg-red-500 text-white rounded-full h-6 w-6 flex items-center justify-center text-xs">×</button>
                    <div class="mb-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">نوع جزء:</label>
                        <select class="w-full p-2 border rounded-md mb-2 bg-gray-100 component-type-select">
                            <option value="">انتخاب نوع جزء</option>
                            ${selectOptions}
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4" data-attributes-container></div>
                    <div class="mt-2"><label class="block text-sm font-medium text-gray-700 mb-1">تعداد/مقدار:</label><input type="number" class="w-full p-2 border rounded-md component-quantity" min="0" value="${existingData.quantity || ''}" placeholder="تعداد/مقدار"></div>
                    <div class="mt-2"><label class="block text-sm font-medium text-gray-700 mb-1">واحد:</label><input type="text" class="w-full p-2 border rounded-md component-unit" value="${existingData.unit || ''}" placeholder="مثال: متر، عدد"></div>
                    <div class="mt-2"><label class="block text-sm font-medium text-gray-700 mb-1">توضیحات:</label><textarea class="w-full p-2 border rounded-md component-notes" rows="2" placeholder="توضیحات بیشتر">${existingData.notes || ''}</textarea></div>
                `;

                const typeSelect = componentRow.querySelector('.component-type-select');
                const attributesContainer = componentRow.querySelector('[data-attributes-container]');
                const unitInput = componentRow.querySelector('.component-unit');

                function updateAttributes() {
                    const selectedTypeKey = typeSelect.value;
                    renderComponentAttributes(attributesContainer, selectedTypeKey, itemConfig, unitInput, existingData.attributes);
                }

                typeSelect.addEventListener('change', updateAttributes);
                componentRow.querySelector('.remove-component-row').addEventListener('click', () => componentRow.remove());

                if (existingData.type) {
                    updateAttributes();
                }

                return componentRow;
            }

            function renderComponentAttributes(container, typeKey, itemConfig, unitInput, attributesData = {}) {
                container.innerHTML = '';
                if (!typeKey || !itemConfig[typeKey] || !itemConfig[typeKey].attributes) return;

                for (const attrName in itemConfig[typeKey].attributes) {
                    const inputType = itemConfig[typeKey].attributes[attrName];
                    const value = attributesData ? (attributesData[attrName] || '') : '';
                    container.innerHTML += `
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">${attrName}:</label>
                            <input type="${inputType}" class="w-full p-2 border rounded-md component-attribute" data-attribute-name="${attrName}" value="${escapeHtml(value)}" placeholder="${attrName}">
                        </div>
                    `;
                }

                if (itemConfig[typeKey].default_unit && !unitInput.value) {
                    unitInput.value = itemConfig[typeKey].default_unit;
                }
            }

            // --- Modal File Upload Logic ---

            modalFileUpload.addEventListener('change', function() {
                if (this.files.length === 0) return;
                const formData = new FormData();
                for (let i = 0; i < this.files.length; i++) {
                    formData.append('files[]', this.files[i]);
                }

                fetch('upload_handler.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok.');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            data.uploaded_files.forEach(file => addFileToPreview(file));
                            modalFileUpload.value = ''; // Clear the input
                        } else {
                            alert('خطا در آپلود فایل: ' + (data.message || 'خطای ناشناخته'));
                        }
                    })
                    .catch(error => {
                        console.error('Upload Error:', error);
                        alert('خطا در ارتباط با سرور برای آپلود فایل: ' + error.message);
                    });
            });

            function renderUploadedFiles(filesData) {
                uploadedFilesPreview.innerHTML = '';
                if (!filesData) return;
                filesData.forEach(file => addFileToPreview(file));
            }

            function addFileToPreview(file) {
                const fileDiv = document.createElement('div');
                fileDiv.className = 'uploaded-file-item flex items-center justify-between p-2 border rounded-md bg-gray-50 text-sm';
                fileDiv.dataset.fileName = file.name;
                fileDiv.dataset.filePath = file.path;
                fileDiv.dataset.fileType = file.type;
                fileDiv.innerHTML = `
                    <span>${escapeHtml(file.name)}</span>
                    <button type="button" class="text-red-500 hover:text-red-700 ml-2 remove-uploaded-file">حذف</button>
                `;
                fileDiv.querySelector('.remove-uploaded-file').addEventListener('click', function() {
                    // Here you could add an AJAX call to delete the temp file from the server
                    this.parentElement.remove();
                });
                uploadedFilesPreview.appendChild(fileDiv);
            }

            // --- Helper Functions ---

            function updateSummary(rowElement, components, files) {
                const summaryDiv = rowElement.querySelector('.detail-summary');
                if (!summaryDiv) return;
                let summaryText = [];
                if (components && components.length > 0) summaryText.push(`اجزا: ${components.length}`);
                if (files && files.length > 0) summaryText.push(`مدارک: ${files.length}`);
                summaryDiv.textContent = summaryText.join(' | ');
            }

            function escapeHtml(text) {
                if (typeof text !== 'string') return text || '';
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                };
                return text.replace(/[&<>"']/g, m => map[m]);
            }

        }); // This was the missing closing bracket and parenthesis
    </script>
    <?php require_once __DIR__ . '/footer.php'; ?>
</body>

</html>