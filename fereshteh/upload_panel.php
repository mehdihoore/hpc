<?php
// upload_panel.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../sercon/bootstrap.php';
secureSession();
$expected_project_key = 'fereshteh';
$current_project_config_key = $_SESSION['current_project_config_key'] ?? null;

if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}
if ($current_project_config_key !== $expected_project_key) {
    logError("Access violation: {$expected_project_key} map accessed. User: {$_SESSION['user_id']}");
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}
$allowed_roles = ['admin', 'supervisor', 'superuser'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    logError("Unauthorized role '{$_SESSION['role']}'. User: {$_SESSION['user_id']}");
    header('Location: dashboard.php?msg=unauthorized');
    exit();
}
$user_id = $_SESSION['user_id'];
$pdo = null;
try {
    $pdo = getProjectDBConnection();
} catch (Exception $e) {
    logError("DB Connection failed: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// --- Column Definitions ---
// Display Name => [db_column_name, validation_type, is_core (default_selected), is_nullable_in_db, example_value]
$TABLE_COLUMN_DEFINITIONS = [
    'hpc_panels' => [
        'Address'             => ['address',           'string_required', true,  false, 'S-T8-U9'],
        'Type'                => ['type',              'string_required', true,  true,  'Flat -Type A'],
        'Area'                => ['area',              'float_positive',  true,  true,  '1.319'],
        'Width'               => ['width',             'float_positive',  true,  true,  '390.0'],
        'Length'              => ['length',            'float_positive',  true,  true,  '1783.0'],
        'Status'              => ['status',            'string',          false, true,  'pending'],
        'Assigned Date'       => ['assigned_date',     'date_iso_or_empty', false, true,  '2025-07-15'],
        'Planned Finish Date' => ['planned_finish_date', 'date_iso_or_empty', false, true,  '2025-07-20'],
        'Formwork Type'       => ['formwork_type',     'string',          true,  true,  'F-H-1'], // Made core based on image
        'SVG Path'            => ['svg_path',          'string',          false, true,  '/svg/S-T8-U9.svg'],
        'Prioritization'      => ['Proritization',     'string',          true,  true,  'Normal'], // Made core based on image
        'Plan Checked'        => ['plan_checked',      'int_bool',        false, false, '0'],
        'Polystyrene'         => ['polystyrene',       'int_bool_or_empty', false, true,  '0'],
    ],
    'available_formworks' => [
        'Formwork Type'     => ['formwork_type',   'string_required', true,  false, 'F-H-2'],
        'Total Count'       => ['total_count',     'int_positive_zero', true, false, '15'],
        'Available Count'   => ['available_count', 'int_positive_zero', true, false, '12'],
    ]
];

function get_uploadable_columns_for_table($table_name, $definitions)
{
    return $definitions[$table_name] ?? [];
}

$table = $_SESSION['last_selected_table'] ?? 'hpc_panels';
$selected_columns_json = $_SESSION['last_selected_columns_json'] ?? '';

if (isset($_POST['table_select_triggered'])) { // Specifically for table select JS submit
    $table = $_POST['table_select'];
    if (!in_array($table, array_keys($TABLE_COLUMN_DEFINITIONS))) {
        $table = 'hpc_panels'; // Reset to default
    }
    $_SESSION['last_selected_table'] = $table;
    $_SESSION['last_selected_columns_json'] = ''; // Reset columns on table change
    $selected_columns_json = '';
    // No message needed for this type of POST
} elseif (isset($_POST['table_select']) && !isset($_POST['confirm_upload']) && !isset($_FILES["csvFile"])) {
    // This handles manual table selection if JS is off or for other form submissions.
    $table = $_POST['table_select'];
    if (!in_array($table, array_keys($TABLE_COLUMN_DEFINITIONS))) {
        $upload_message = "<div class='message error'>Invalid table selected.</div>";
        $table = 'hpc_panels';
    }
    $_SESSION['last_selected_table'] = $table;
    if ($_SESSION['last_selected_columns_json'] === '' || !isset($_POST['selected_columns'])) {
        $_SESSION['last_selected_columns_json'] = ''; // Reset if table changed without column POST
        $selected_columns_json = '';
    }
}

if (isset($_POST['selected_columns'])) {
    $selected_columns_json = $_POST['selected_columns'];
    $_SESSION['last_selected_columns_json'] = $selected_columns_json;
}

$user_selected_display_names = json_decode($selected_columns_json, true) ?: [];
if (empty($user_selected_display_names) && $table) { // If empty, populate with core fields for the current table
    $core_fields = [];
    foreach ($TABLE_COLUMN_DEFINITIONS[$table] as $displayName => $def) {
        if ($def[2]) { // is_core_field
            $core_fields[] = $displayName;
        }
    }
    $user_selected_display_names = $core_fields;
    // Update session and current var if we defaulted
    $_SESSION['last_selected_columns_json'] = json_encode($core_fields);
    $selected_columns_json = json_encode($core_fields);
}


// --- Validation, Processing, Insertion Functions (largely same as before) ---
function validateCSVStructure($csv_header, $expected_display_headers)
{ /* ... Same ... */
    if (count($csv_header) !== count($expected_display_headers)) {
        return false;
    }
    $normalized_csv_header = array_map('mb_strtolower', $csv_header);
    if (!empty($normalized_csv_header)) {
        $normalized_csv_header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $normalized_csv_header[0]);
    }
    $normalized_expected = array_map('mb_strtolower', $expected_display_headers);
    return $normalized_csv_header === $normalized_expected;
}
function processCSV($file)
{ /* ... Same ... */
    $results = [
        'errors' => 0,
        'error_messages' => [],
        'preview_data' => [],
        'total_rows' => 0
    ];
    if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
        $header = fgetcsv($handle);
        if ($header === false) {
            $results['errors']++;
            $results['error_messages'][] = "Error reading CSV header.";
            fclose($handle);
            return $results;
        }
        $results['preview_data'][] = $header;
        $row_count = 0;
        while (($data = fgetcsv($handle)) !== FALSE) {
            $results['preview_data'][] = $data;
            $row_count++;
        }
        $results['total_rows'] = $row_count;
        fclose($handle);
    } else {
        $results['errors']++;
        $results['error_messages'][] = "Error opening file.";
    }
    return $results;
}
function insertCSVData($csv_preview_data, $table, $pdo, $upload_mode, $user_selected_display_names, $column_definitions_for_table, $session_user_id)
{ /* ... Same as previous, ensure it uses the augmented definitions correctly if needed for validation messages, but core logic for insert relies on selected columns ... */
    $results = ['success' => 0, 'errors' => 0, 'error_messages' => [], 'messages' => []];

    if (empty($user_selected_display_names)) {
        $results['errors']++;
        $results['error_messages'][] = "No columns selected.";
        return $results;
    }
    $csv_header = $csv_preview_data[0];
    if (!validateCSVStructure($csv_header, $user_selected_display_names)) {
        $results['errors']++;
        $results['error_messages'][] = "CSV header mismatch. Expected (order matters): " . implode(", ", $user_selected_display_names) . ". Got: " . implode(", ", $csv_header);
        return $results;
    }

    if ($upload_mode === 'replace') {
        try {
            if (in_array($table, array_keys($GLOBALS['TABLE_COLUMN_DEFINITIONS']))) {
                $pdo->exec("TRUNCATE TABLE `$table`");
                $results['messages'][] = "Table `$table` truncated.";
            } else {
                throw new Exception("Truncate not allowed for `$table`.");
            }
        } catch (Exception $e) {
            $results['errors']++;
            $results['error_messages'][] = "Truncate error: " . $e->getMessage();
            return $results;
        }
    }

    $db_columns_to_insert = [];
    foreach ($user_selected_display_names as $displayName) {
        if (isset($column_definitions_for_table[$displayName])) {
            $db_columns_to_insert[] = $column_definitions_for_table[$displayName][0]; // DB name
        } else {
            $results['errors']++;
            $results['error_messages'][] = "Config error: Unknown column '$displayName'.";
            return $results;
        }
    }

    $current_user_id_field_name = null;
    if ($table === 'hpc_panels' && !in_array('user_id', $db_columns_to_insert)) {
        $db_columns_to_insert[] = 'user_id';
        $current_user_id_field_name = 'user_id';
    }

    if (empty($db_columns_to_insert)) {
        $results['errors']++;
        $results['error_messages'][] = "No DB columns to insert.";
        return $results;
    }

    $sql_cols = implode(", ", array_map(fn($col) => "`$col`", $db_columns_to_insert));
    $sql_placeholders = implode(", ", array_fill(0, count($db_columns_to_insert), "?"));
    $stmt_sql = "INSERT INTO `$table` ($sql_cols) VALUES ($sql_placeholders)";

    try {
        $stmt = $pdo->prepare($stmt_sql);
    } catch (PDOException $e) {
        $results['errors']++;
        $results['error_messages'][] = "DB prepare error: " . $e->getMessage();
        return $results;
    }

    for ($i = 1; $i < count($csv_preview_data); $i++) {
        $row_data_from_csv = $csv_preview_data[$i];
        $row_num_in_file = $i + 1;
        $params_to_execute = [];
        $current_row_has_error = false;

        foreach ($user_selected_display_names as $idx => $displayName) {
            $col_def = $column_definitions_for_table[$displayName];
            $val_type = $col_def[1];
            $is_nullable = $col_def[3];
            $raw_value = trim($row_data_from_csv[$idx]);
            $processed_value = null;

            if ($raw_value === '' && $is_nullable) {
                $processed_value = null;
            } else {
                switch ($val_type) {
                    case 'string_required':
                        if ($raw_value === '') {
                            $current_row_has_error = true;
                            $results['error_messages'][] = "Row $row_num_in_file, '$displayName': required.";
                        }
                        $processed_value = $raw_value;
                        break;
                    case 'string':
                        $processed_value = $raw_value;
                        break;
                    case 'float_positive':
                        if (!is_numeric($raw_value) || floatval($raw_value) <= 0) {
                            $current_row_has_error = true;
                            $results['error_messages'][] = "Row $row_num_in_file, '$displayName': positive number.";
                        }
                        $processed_value = $current_row_has_error ? null : floatval($raw_value);
                        break;
                    case 'int_positive_zero':
                        if (filter_var($raw_value, FILTER_VALIDATE_INT, ["options" => ["min_range" => 0]]) === false) {
                            $current_row_has_error = true;
                            $results['error_messages'][] = "Row $row_num_in_file, '$displayName': non-negative integer.";
                        }
                        $processed_value = $current_row_has_error ? null : intval($raw_value);
                        break;
                    case 'int_bool':
                        if ($raw_value !== '0' && $raw_value !== '1') {
                            $current_row_has_error = true;
                            $results['error_messages'][] = "Row $row_num_in_file, '$displayName': 0 or 1.";
                        }
                        $processed_value = $current_row_has_error ? null : intval($raw_value);
                        break;
                    case 'int_bool_or_empty':
                        if ($raw_value === '') {
                            $processed_value = null;
                        } elseif ($raw_value !== '0' && $raw_value !== '1') {
                            $current_row_has_error = true;
                            $results['error_messages'][] = "Row $row_num_in_file, '$displayName': 0, 1, or empty.";
                        } else {
                            $processed_value = intval($raw_value);
                        }
                        break;
                    case 'date_iso_or_empty':
                        if ($raw_value === '') {
                            $processed_value = null;
                        } else {
                            $d = DateTime::createFromFormat('Y-m-d', $raw_value);
                            if (!$d || $d->format('Y-m-d') !== $raw_value) {
                                $current_row_has_error = true;
                                $results['error_messages'][] = "Row $row_num_in_file, '$displayName': YYYY-MM-DD or empty.";
                            }
                            $processed_value = $current_row_has_error ? null : $raw_value;
                        }
                        break;
                    default:
                        $processed_value = $raw_value;
                }
            }
            $params_to_execute[] = $processed_value;
        }

        if ($current_user_id_field_name === 'user_id' && $table === 'hpc_panels') {
            $params_to_execute[] = $session_user_id;
        }
        if ($current_row_has_error) {
            $results['errors']++;
            continue;
        }

        try {
            if ($stmt->execute($params_to_execute)) {
                if ($stmt->rowCount() > 0) {
                    $results['success']++;
                } else { /* $results['errors']++; $results['error_messages'][] = "Row $row_num_in_file: 0 rows inserted."; */
                } // Can be noisy if update has no change
            } else {
                $results['errors']++;
                $errorInfo = $stmt->errorInfo();
                if ($errorInfo[1] == 1062) {
                    $results['error_messages'][] = "Row $row_num_in_file: Duplicate entry.";
                } else {
                    $results['error_messages'][] = "Row $row_num_in_file: DB Error - " . $errorInfo[2];
                }
            }
        } catch (PDOException $e) {
            $results['errors']++;
            $results['error_messages'][] = "Row $row_num_in_file: Exception - " . $e->getMessage();
        }
    }
    return $results;
}
function formatFileSize($bytes)
{ /* ... Same ... */
    if ($bytes === 0) return '0 بایت';
    $k = 1024;
    $sizes = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
    $i = floor(log($bytes) / log($k));
    return sprintf("%.2f %s", $bytes / pow($k, $i), $sizes[$i]);
}

// --- Main Request Handling (POST) ---
$upload_message = '';
$preview_data = null;
$show_preview = false;
$current_table_column_definitions = get_uploadable_columns_for_table($table, $TABLE_COLUMN_DEFINITIONS);

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['table_select_triggered'])) {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        logError("CSRF token mismatch");
        $upload_message = "<div class='message error'>Invalid request. CSRF token mismatch.</div>";
        if (isset($_FILES["csvFile"]) || isset($_POST['confirm_upload'])) {
            unset($_SESSION['csrf_token']);
        }
    } else {
        if (isset($_FILES["csvFile"]) && $_FILES["csvFile"]["error"] == UPLOAD_ERR_OK && !isset($_POST['confirm_upload'])) { // Preview
            if (empty($user_selected_display_names)) {
                $upload_message = "<div class='message error'>Please select columns for import.</div>";
            } else {
                $file_ext = strtolower(pathinfo($_FILES["csvFile"]["name"], PATHINFO_EXTENSION));
                if ($file_ext == 'csv') {
                    $csv_results = processCSV($_FILES["csvFile"]);
                    if (!empty($csv_results['error_messages'])) {
                        $upload_message = "<div class='message error'>" . implode("<br>", $csv_results['error_messages']) . "</div>";
                    } else if (empty($csv_results['preview_data']) || count($csv_results['preview_data']) < 2) {
                        $upload_message = "<div class='message error'>CSV is empty or has only header.</div>";
                    } else {
                        if (!validateCSVStructure($csv_results['preview_data'][0], $user_selected_display_names)) {
                            $upload_message = "<div class='message error'>CSV header mismatch. Expected: " . implode(", ", $user_selected_display_names) . ".<br>File header: " . implode(", ", $csv_results['preview_data'][0]) . ".</div>";
                        } else {
                            $preview_data = $csv_results['preview_data'];
                            $show_preview = true;
                            $_SESSION['temp_csv_data'] = $preview_data;
                            $_SESSION['temp_file_name'] = $_FILES["csvFile"]['name'];
                            $_SESSION['temp_file_size'] = $_FILES["csvFile"]['size'];
                            $_SESSION['temp_total_rows'] = $csv_results['total_rows'];
                        }
                    }
                } else {
                    $upload_message = "<div class='message error'>CSV file only.</div>";
                }
            }
        } elseif (isset($_POST['confirm_upload']) && isset($_POST['csv_data'])) { // Insert
            if (empty($user_selected_display_names)) {
                $upload_message = "<div class='message error'>Column selection lost.</div>";
            } else {
                $received_data = json_decode($_POST['csv_data'], true);
                $upload_mode = $_POST['upload_mode'] ?? 'add';
                if ($received_data === null || empty($received_data) || count($received_data) < 2) {
                    $upload_message = "<div class='message error'>No data to import.</div>";
                } else {
                    $insert_results = insertCSVData($received_data, $table, $pdo, $upload_mode, $user_selected_display_names, $current_table_column_definitions, $user_id);
                    $final_msg = "";
                    if (!empty($insert_results['messages'])) {
                        $final_msg .= "<div class='message info'>" . implode("<br>", $insert_results['messages']) . "</div>";
                    }
                    $final_msg .= sprintf("<div class='message success'>Rows in File: %d<br>Inserted: %d<br>Errors/Skipped: %d</div>", count($received_data) - 1, $insert_results['success'], $insert_results['errors']);
                    if (!empty($insert_results['error_messages'])) {
                        $final_msg .= "<div class='message error'>Details:<br>" . implode("<br>", array_slice($insert_results['error_messages'], 0, 20)) . (count($insert_results['error_messages']) > 20 ? "<br>...more errors." : "") . "</div>";
                    }
                    $upload_message = $final_msg;
                }
                unset($_SESSION['temp_csv_data'], $_SESSION['temp_file_name'], $_SESSION['temp_file_size'], $_SESSION['temp_total_rows']);
            }
        }
    }
}

if (!isset($_SESSION['csrf_token']) || $_SERVER['REQUEST_METHOD'] === 'GET' || (isset($insert_results) && empty($insert_results['errors']))) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<?php
$pageTitle = "آپلود CSV داده‌ها - فقط مدیر";
require_once 'header.php';
?>
<div class="container">
    <h2>آپلود فایل CSV داده‌ها</h2>

    <form action="" method="post" enctype="multipart/form-data" id="uploadForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="form-group">
            <label for="table_select">انتخاب جدول:</label>
            <select name="table_select" id="table_select" required>
                <?php foreach (array_keys($TABLE_COLUMN_DEFINITIONS) as $tableNameKey): ?>
                    <option value="<?php echo htmlspecialchars($tableNameKey); ?>" <?php echo ($table === $tableNameKey) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tableNameKey))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="columnSelectionContainer" class="form-group">
            <label>انتخاب ستون‌ها برای آپلود (ترتیب ستون‌ها در CSV باید مطابق انتخاب زیر باشد):</label>
            <div id="columnCheckboxes">
                <?php // Populated by JS 
                ?>
            </div>
            <input type="hidden" name="selected_columns" id="selected_columns_json" value="<?php echo htmlspecialchars($selected_columns_json); ?>">
        </div>

        <div class="form-group">
            <label for="csvFile">انتخاب فایل CSV ( مطابق ستون های انتخاب شده):</label>
            <input type="file" id="csvFile" name="csvFile" accept=".csv">
            <div class="file-drop-zone" id="fileDropZone">
                <p>فایل CSV را اینجا بکشید و رها کنید یا کلیک کنید.</p>
            </div>
            <div class="validation-message" id="fileValidation"></div>
        </div>
        <?php if (!$show_preview): ?>
            <button type="submit" class="btn" id="submitPreviewBtn">آپلود و نمایش پیش‌نمایش</button>
        <?php endif; ?>
    </form>

    <?php echo $upload_message; ?>

    <?php if ($show_preview && !empty($preview_data)): ?>
        <form action="" method="post" id="confirmForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="table_select" value="<?php echo htmlspecialchars($table); ?>">
            <input type="hidden" name="selected_columns" value="<?php echo htmlspecialchars($selected_columns_json); ?>">
            <input type="hidden" name="confirm_upload" value="1"> <input type="hidden" name="csv_data" id="csvData" value="">
            <div class="form-group"><label>حالت آپلود:</label>
                <div><label><input type="radio" name="upload_mode" value="add" checked> اضافه کردن رکوردهای جدید</label></div>
                <div><label><input type="radio" name="upload_mode" value="replace"> جایگزینی تمام داده‌های جدول</label></div>
            </div>
            <div class="preview-container">
                <h3>پیش‌نمایش فایل</h3>
                <div class="file-info">
                    <div>نام فایل: <?php echo htmlspecialchars($_SESSION['temp_file_name']); ?></div>
                    <div>اندازه: <?php echo htmlspecialchars(formatFileSize($_SESSION['temp_file_size'])); ?></div>
                    <div>ردیف‌های داده: <?php echo htmlspecialchars($_SESSION['temp_total_rows']); ?></div>
                </div>
                <p><strong>ستون‌های وارداتی (به ترتیب):</strong> <?php echo implode(", ", array_map('htmlspecialchars', $user_selected_display_names)); ?></p>
                <table class="preview-table" id="previewTable">
                    <thead>
                        <tr>
                            <?php foreach ($preview_data[0] as $index => $header_col): ?><th><?php echo htmlspecialchars($header_col); ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 1; $i < count($preview_data); $i++): ?><tr>
                                <?php foreach ($preview_data[$i] as $index => $cell): ?><td contenteditable="true"><?php echo htmlspecialchars($cell); ?></td><?php endforeach; ?>
                            </tr><?php endfor; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn confirm-upload">تایید و آپلود نهایی</button>
                <button type="button" class="btn" id="cancelPreview">انصراف</button>
            </div>
        </form>
    <?php endif; ?>

    <div class="preview" id="csvRequirementsSection">
        <h3>الزامات فرمت CSV:</h3>
        <p>ستون‌های فایل CSV شما باید دقیقاً با ستون‌هایی که در بالا انتخاب کرده‌اید و به همان ترتیب مطابقت داشته باشند.</p>
        <div class="button-group">
            <button type="button" class="btn" id="downloadTemplateBtn">دانلود تمپلیت CSV (با داده نمونه)</button>
        </div>
        <div id="formatExamplesContainer">
            <?php foreach ($TABLE_COLUMN_DEFINITIONS as $tableNameKey => $columns): ?>
                <div id="<?php echo htmlspecialchars($tableNameKey); ?>_format_example" class="format-example" style="display: none;">
                    <h4 id="<?php echo htmlspecialchars($tableNameKey); ?>_example_heading">
                        مثال برای <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tableNameKey))); ?> (با ستون های انتخاب شده):
                    </h4>
                    <table class="example-table" id="<?php echo htmlspecialchars($tableNameKey); ?>_example_table">
                        <thead></thead>
                        <tbody></tbody>
                    </table>
                    <p><em>توجه: تمپلیت دانلود شده و مثال بالا بر اساس انتخاب فعلی ستون های شما خواهد بود.</em></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
    const tableColumnDefinitions = <?php echo json_encode($TABLE_COLUMN_DEFINITIONS); ?>;
    let currentSelectedDisplayNames = <?php echo json_encode($user_selected_display_names); ?>;
    const initialTable = '<?php echo $table; ?>';

    function populateColumnCheckboxes(tableName) {
        const container = document.getElementById('columnCheckboxes');
        container.innerHTML = ''; // Clear previous checkboxes

        let defaultSelectedForThisTable = [];
        if (tableColumnDefinitions[tableName]) {
            Object.entries(tableColumnDefinitions[tableName]).forEach(([displayName, def]) => {
                if (def[2]) defaultSelectedForThisTable.push(displayName); // is_core

                const checkboxId = 'col_' + tableName + '_' + displayName.replace(/[^a-zA-Z0-9]/g, "");
                const div = document.createElement('div');
                div.className = 'checkbox-item';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.id = checkboxId;
                checkbox.value = displayName;
                // Use currentSelectedDisplayNames if available for current table, else use core defaults
                const currentlySelected = JSON.parse(document.getElementById('selected_columns_json').value || '[]');
                if (document.getElementById('table_select').value === tableName && currentlySelected.length > 0) {
                    checkbox.checked = currentlySelected.includes(displayName);
                } else {
                    checkbox.checked = def[2]; // is_core_field
                }

                const label = document.createElement('label');
                label.htmlFor = checkboxId;
                label.textContent = displayName + (def[2] ? ' (پیشنهادی)' : '');
                div.appendChild(checkbox);
                div.appendChild(label);
                container.appendChild(div);
                checkbox.addEventListener('change', handleColumnSelectionChange);
            });
        }
        updateSelectedColumnsJson(); // Update hidden input based on initial checkbox state
        updateOnPageExampleTable(tableName, JSON.parse(document.getElementById('selected_columns_json').value || '[]'));
    }

    function handleColumnSelectionChange() {
        updateSelectedColumnsJson();
        const currentTable = document.getElementById('table_select').value;
        const selectedNames = JSON.parse(document.getElementById('selected_columns_json').value || '[]');
        updateOnPageExampleTable(currentTable, selectedNames);
    }

    function updateSelectedColumnsJson() {
        const selectedNames = [];
        document.querySelectorAll('#columnCheckboxes input[type="checkbox"]:checked').forEach(cb => {
            selectedNames.push(cb.value);
        });
        document.getElementById('selected_columns_json').value = JSON.stringify(selectedNames);
        currentSelectedDisplayNames = selectedNames; // Update global JS var
    }

    function updateOnPageExampleTable(tableName, selectedDisplayNames) {
        const exampleDiv = document.getElementById(tableName + '_format_example');
        if (!exampleDiv) return;

        const exampleTable = exampleDiv.querySelector('.example-table');
        const exampleHeading = exampleDiv.querySelector('h4');

        if (exampleHeading) {
            exampleHeading.textContent = `مثال برای ${tableName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())} (با ستون های انتخاب شده):`;
        }

        if (!exampleTable) return;
        const thead = exampleTable.querySelector('thead') || exampleTable.appendChild(document.createElement('thead'));
        const tbody = exampleTable.querySelector('tbody') || exampleTable.appendChild(document.createElement('tbody'));
        thead.innerHTML = '';
        tbody.innerHTML = '';

        if (selectedDisplayNames.length === 0) {
            tbody.innerHTML = '<tr><td colspan="100%">لطفا ستون‌ها را انتخاب کنید تا مثال نمایش داده شود.</td></tr>';
            return;
        }

        const trHead = document.createElement('tr');
        selectedDisplayNames.forEach(displayName => {
            const th = document.createElement('th');
            th.textContent = displayName;
            trHead.appendChild(th);
        });
        thead.appendChild(trHead);

        const trBody = document.createElement('tr');
        selectedDisplayNames.forEach(displayName => {
            const td = document.createElement('td');
            const exampleValue = tableColumnDefinitions[tableName]?.[displayName]?.[4] || ''; // def[4] is example_value
            td.textContent = exampleValue;
            trBody.appendChild(td);
        });
        tbody.appendChild(trBody);
    }

    document.getElementById('table_select').addEventListener('change', function() {
        const selectedTable = this.value;
        document.getElementById('selected_columns_json').value = '[]'; // Reset to force default core selection for new table
        populateColumnCheckboxes(selectedTable); // This will call updateSelectedColumnsJson and updateOnPageExampleTable

        document.querySelectorAll('.format-example').forEach(ex => ex.style.display = 'none');
        const currentExampleDiv = document.getElementById(selectedTable + '_format_example');
        if (currentExampleDiv) currentExampleDiv.style.display = 'block';

        // To update server session about table change if needed without full form submit for file
        // This uses a simple GET reload, alternative is AJAX or a hidden field + specific submit button.
        // window.location.href = window.location.pathname + '?table_select=' + selectedTable; // Simpler reload
        // Or, to use POST to align with current PHP logic expecting POST for table_select:
        const hiddenField = document.createElement('input');
        hiddenField.type = 'hidden';
        hiddenField.name = 'table_select_triggered'; // Special name
        hiddenField.value = '1';
        document.getElementById('uploadForm').appendChild(hiddenField);
        // No file attached, so this POST is just for table context update.
        // This might clear upload_message, consider if that's desired on simple table change.
        // document.getElementById('uploadForm').submit(); // Disabling for now as it can be disruptive
    });

    document.getElementById('downloadTemplateBtn').addEventListener('click', function() {
        const selectedTable = document.getElementById('table_select').value;
        const selectedNames = currentSelectedDisplayNames; // Use the global JS variable

        if (selectedNames.length === 0) {
            alert("لطفا ابتدا ستون‌هایی را برای تمپلیت انتخاب کنید.");
            return;
        }
        const header = selectedNames.join(',');
        let exampleRow = selectedNames.map(displayName => {
            const val = tableColumnDefinitions[selectedTable]?.[displayName]?.[4] || ''; // def[4] is example_value
            // Escape commas and quotes in example data for CSV
            return /[,"]/.test(val) ? `"${val.replace(/"/g, '""')}"` : val;
        }).join(',');

        const csvContent = "\uFEFF" + header + "\n" + exampleRow; // BOM for Excel
        const blob = new Blob([csvContent], {
            type: 'text/csv;charset=utf-8;'
        });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = url;
        a.download = selectedTable + '_template_با_نمونه.csv';
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    });

    // Initial setup
    populateColumnCheckboxes(initialTable);
    const initialExampleDiv = document.getElementById(initialTable + '_format_example');
    if (initialExampleDiv) initialExampleDiv.style.display = 'block';

    // --- File Input, Drag/Drop, Preview Form Logic (mostly same as before) ---
    function validateFile(file) {
        /* ... Same ... */
        const validationMessage = document.getElementById('fileValidation');
        const maxSize = 5 * 1024 * 1024;
        validationMessage.textContent = '';
        if (file.size > maxSize) {
            validationMessage.textContent = 'حجم فایل کمتر از ۵ مگابایت.';
            return false;
        }
        if (file.type !== 'text/csv' && !file.name.endsWith('.csv') && file.type !== 'application/vnd.ms-excel') {
            validationMessage.textContent = 'فایل CSV معتبر آپلود کنید.';
            return false;
        }
        return true;
    }
    document.getElementById('csvFile').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        if (!validateFile(file)) {
            this.value = '';
            return;
        }
    });
    document.getElementById('submitPreviewBtn')?.addEventListener('click', function(e) {
        updateSelectedColumnsJson(); // Ensure hidden field is up-to-date
        const selectedJson = document.getElementById('selected_columns_json').value;
        if (selectedJson === '[]' || selectedJson === '') {
            alert('حداقل یک ستون انتخاب کنید.');
            e.preventDefault();
            return;
        }
        const fileInput = document.getElementById('csvFile');
        if (!fileInput.files || fileInput.files.length === 0) {
            document.getElementById('fileValidation').textContent = "فایل انتخاب کنید.";
            e.preventDefault();
        }
    });
    const dropZone = document.getElementById('fileDropZone');
    if (dropZone) {
        /* ... Drag/drop logic same ... */
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('dragover');
        });
        dropZone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('dragover');
        });
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                if (validateFile(files[0])) {
                    document.getElementById('csvFile').files = files;
                    document.getElementById('csvFile').dispatchEvent(new Event('change'));
                } else {
                    document.getElementById('csvFile').value = '';
                }
            }
        });
        dropZone.addEventListener('click', () => document.getElementById('csvFile').click());
    }
    const confirmForm = document.getElementById('confirmForm');
    if (confirmForm) {
        /* ... Preview confirm logic same ... */
        confirmForm.addEventListener('submit', function(e) {
            const previewTableEl = document.getElementById('previewTable');
            if (!previewTableEl) {
                e.preventDefault();
                alert("Preview table missing.");
                return;
            }
            const rows = Array.from(previewTableEl.rows);
            const data = rows.map(row => Array.from(row.cells).map(cell => cell.textContent.trim()));
            document.getElementById('csvData').value = JSON.stringify(data);
        });
    }
    document.getElementById('cancelPreview')?.addEventListener('click', () => window.location.href = window.location.pathname);
</script>
<?php require_once 'footer.php'; ?>
</body>

</html>