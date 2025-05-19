<?php
    // upload_panel.php
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    ob_start();
    header('Content-Type: text/html; charset=utf-8');

    // --- Your existing setup code ---
    require_once __DIR__ . '/../../sercon/bootstrap.php'; // Adjust path if needed
    // require_once __DIR__ .'/includes/jdf.php'; // If needed for display, otherwise not for this logic
    secureSession();
    $expected_project_key = 'fereshteh';
    $current_project_config_key = $_SESSION['current_project_config_key'] ?? null;

    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit();
    }
    if ($current_project_config_key !== $expected_project_key) {
        logError("Access violation: {$expected_project_key} map accessed with project {$current_project_config_key}. User: {$_SESSION['user_id']}");
        header('Location: /select_project.php?msg=context_mismatch');
        exit();
    }
    $allowed_roles = ['admin', 'supervisor', 'superuser']; // Add other roles as needed
    if (!in_array($_SESSION['role'], $allowed_roles)) {
        logError("Unauthorized role '{$_SESSION['role']}' attempt on {$expected_project_key} upload_panel. User: {$_SESSION['user_id']}");
        header('Location: dashboard.php?msg=unauthorized');
        exit();
    }
    $user_id = $_SESSION['user_id'];
    $pdo = null;
    try {
        $pdo = getProjectDBConnection();
    } catch (Exception $e) {
        logError("DB Connection failed in {$expected_project_key} upload_panel: " . $e->getMessage());
        die("خطا در اتصال به پایگاه داده پروژه.");
    }

// If we get to this point, we know the user is logged in and is an admin.

// Set a default table.  This helps prevent errors if no table is selected.
$table = $_SESSION['last_selected_table'] ?? 'hpc_panels';

// Handle table selection (persist across requests via session).
if (isset($_POST['table_select'])) {
    $table = $_POST['table_select'];
	// Basic input validation.
    if (!in_array($table, ['hpc_panels', 'sarotah', 'formwork_types'])) {
        $upload_message = "<div class='message error'>Invalid table selected.</div>";
        $table = 'hpc_panels'; // Reset to default value.
    }
    $_SESSION['last_selected_table'] = $table;  // Store for later
}

// --- Validation and Processing Functions ---

function validateCSVStructure($header, $table) {
    $expected_headers = [
        'hpc_panels' => ['Address', 'Type', 'Area', 'Width', 'Length'],
        'sarotah' => ['Left Panel', 'Dimension 1', 'Dimension 2', 'Dimension 3', 'Dimension 4', 'Dimension 5', 'Right Panel'],
        'formwork_types' => ['Type', 'Width', 'Max Length']
    ];

    // Remove BOM and convert both to lowercase for a case-insensitive comparison.
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // Remove BOM
    $normalized_headers = array_map('mb_strtolower', $header);
    $normalized_expected = array_map('mb_strtolower', $expected_headers[$table]);

    return $normalized_headers === $normalized_expected;
}


// MODIFIED processCSV (no database interaction here)
function processCSV($file) {
    $results = [
        'errors' => 0,
        'error_messages' => [],
        'preview_data' => [],
        'total_rows' => 0
    ];

    if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
      $header = fgetcsv($handle); //read the header
        if ($header === false) {
            $results['errors']++;
            $results['error_messages'][] = "Error reading CSV header.";
            fclose($handle); //close the file immediately
            return $results; // Return to prevent futher processing
        }

        $results['preview_data'][] = $header; // Add headers to preview data

        $row_count = 0;
        while (($data = fgetcsv($handle)) !== FALSE) {
            // Store all rows for potential edit/preview.
            $results['preview_data'][] = $data;
            $row_count++;
        }
        $results['total_rows'] = $row_count;
        fclose($handle); // Always close the file
    }  else {
        $results['errors']++;
        $results['error_messages'][] = "Error opening file.";
    }
    return $results;
}

// --- Database Insertion Function (Separate from CSV processing) ---
function insertCSVData($data, $table, $pdo) { // Pass $pdo, not $conn
    $results = [
        'success' => 0,
        'errors' => 0,
        'error_messages' => [],
    ];

    $header = $data[0]; // First row is the header
     if (!validateCSVStructure($header, $table)) {
            $results['errors']++;
            $results['error_messages'][] = "Invalid CSV file structure.";
            return $results; // Return early if validation fails
    }

    // Prepare statement
    $stmt = null; // Initialize $stmt
    if ($table === 'hpc_panels') {
        $stmt = $pdo->prepare("INSERT INTO hpc_panels (address, type, area, width, length, status, user_id) VALUES (?, ?, ?, ?, ?, 'pending', ?)");
        if (!$stmt) { // Check if prepare failed!
                $results['errors']++;
                $results['error_messages'][] = "Database prepare error: " . $pdo->errorInfo()[2]; // Use PDO error info
                 return $results;
        }
    } else if ($table === 'sarotah') {
    $stmt = $pdo->prepare("INSERT INTO sarotah (left_panel, dimension_1, dimension_2, dimension_3, dimension_4, dimension_5, right_panel) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) { // Check if prepare failed!
                $results['errors']++;
                $results['error_messages'][] = "Database prepare error: " . $pdo->errorInfo()[2]; // Use PDO error info
                 return $results;
        }
    } else if ($table === 'formwork_types') {
    $stmt = $pdo->prepare("INSERT INTO formwork_types (type, width, max_length) VALUES (?, ?, ?)");
    if (!$stmt) { // Check if prepare failed!
                $results['errors']++;
                $results['error_messages'][] = "Database prepare error: " . $pdo->errorInfo()[2]; // Use PDO error info
                 return $results;
        }
    }


    // Iterate through rows (skipping the header)
    for ($i = 1; $i < count($data); $i++) { // Start from 1 to skip header
        $row = $data[$i];
        $row_count = $i + 1; // Actual row number in the file

        try {
            if ($table === 'hpc_panels') {
               if (count($row) < 5) {throw new Exception("Invalid number of columns.");}
                $address = trim($row[0]);
                $type = trim($row[1]);
                $area = floatval($row[2]);
                $width = floatval($row[3]);
                $length = floatval($row[4]);
                $user_id = $_SESSION['user_id']; // Get user ID from session
                if (empty($address) || empty($type) || $area <= 0 || $width <= 0 || $length <= 0) {throw new Exception("Invalid data.");}
                 $stmt->execute([$address, $type, $area, $width, $length, $user_id]);

            } else if ($table === 'sarotah') {
                 if (count($row) < 7) { throw new Exception("Invalid number of columns."); }
                $left_panel = trim($row[0]);
                $dimension_1 = !empty($row[1]) ? (int) $row[1] : null; // Cast to int, allow null
                $dimension_2 = !empty($row[2]) ? (int) $row[2] : null;
                $dimension_3 = !empty($row[3]) ? (int) $row[3] : null;
                $dimension_4 = !empty($row[4]) ? (int) $row[4] : null;
                $dimension_5 = !empty($row[5]) ? (int) $row[5] : null;
                $right_panel = trim($row[6]);
                if (empty($left_panel) || empty($right_panel)) {throw new Exception("Left and right panels cannot be empty.");}
                if($dimension_1 === null && $dimension_2 ===null && $dimension_3 === null && $dimension_4 === null && $dimension_5 === null){
                    throw new Exception("At least one dimension must be provided.");
                }
                 $stmt->execute([$left_panel, $dimension_1, $dimension_2, $dimension_3,$dimension_4, $dimension_5, $right_panel]);


            } else if ($table === 'formwork_types') {
                if (count($row) < 3) {throw new Exception("Invalid number of columns.");}
                $type = trim($row[0]);
                $width = floatval($row[1]);
                $max_length = floatval($row[2]);
                if (empty($type) || $width <= 0 || $max_length <= 0) { throw new Exception("Invalid data.");}
                $stmt->execute([$type, $width, $max_length]);

            }

            if ($stmt->rowCount() > 0) {
                $results['success']++;
            } else {
                $results['errors']++;
                $results['error_messages'][] = "Error inserting row $row_count: " . print_r($stmt->errorInfo(), true);
            }

        } catch (Exception $e) {
            $results['errors']++;
            $results['error_messages'][] = "Error in row $row_count: " . $e->getMessage();
        }
    }

    return $results;
}

// --- PHP Function to Format File Size ---
function formatFileSize($bytes) {
    if ($bytes === 0) return '0 بایت';
    $k = 1024;
    $sizes = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
    $i = floor(log($bytes) / log($k));
    return sprintf("%.2f %s", $bytes / pow($k, $i), $sizes[$i]);
}


// --- Main Request Handling ---
$upload_message = '';
$preview_data = null;
$show_preview = false; // Flag to control preview display


// --- CSRF Token Generation (on GET request) ---
// This is now handled in config_fereshteh.php, so we don't repeat it here

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // CSRF Check.  Now done inside each specific POST handler for clarity.

    if (isset($_POST['table_select'])) {
        $table = $_POST['table_select'];
        if (!in_array($table, ['hpc_panels', 'sarotah', 'formwork_types'])) {
            $upload_message = "<div class='message error'>Invalid table selected.</div>";
            $table = 'hpc_panels'; //reset to default value
        }
        $_SESSION['last_selected_table'] = $table; //save it again after validation
    }

    // Handle File Preview (First Step)
    if (isset($_FILES["csvFile"]) && $_FILES["csvFile"]["error"] == UPLOAD_ERR_OK && !isset($_POST['confirm_upload']) && !isset($_POST['csv_data'])) {
        $file_extension = strtolower(pathinfo($_FILES["csvFile"]["name"], PATHINFO_EXTENSION));
        if ($file_extension == 'csv') {
            $results = processCSV($_FILES["csvFile"]);  // Process for preview ONLY
            $preview_data = $results['preview_data'];
            if(!empty($results['error_messages'])){
                 $upload_message = "<div class='message error'>".implode("<br>",$results['error_messages'])."</div>";
            }
            else{
                 $show_preview = true; // Show the preview table
                 $_SESSION['temp_csv_data'] = $preview_data; // Store in session, including ALL rows
                 $_SESSION['temp_file_name'] = $_FILES["csvFile"]['name']; // Store filename
                 $_SESSION['temp_file_size'] = $_FILES["csvFile"]['size']; //Store file size
                  $_SESSION['temp_total_rows'] = $results['total_rows']; //store total rows

            }
        } else {
            $upload_message = "<div class='message error'>Error: Please upload a CSV file only.</div>";
        }
    }

    // Handle Database Insertion (Second Step - after preview confirmation)
    elseif (isset($_POST['confirm_upload']) && isset($_POST['csv_data'])) {
        // CSRF Check *inside* the handler
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            logError("CSRF token mismatch in upload_panel.php (insert)");
            $upload_message = "<div class='message error'>Invalid request. CSRF token mismatch.</div>";
        }
        else{
            try {
               
                $received_data = json_decode($_POST['csv_data'], true); // Decode the JSON data from the hidden input

                // Check if json_decode was successful
                if ($received_data === null) {
                    $upload_message = "<div class='message error'>Error in the received data.</div>";
                } else {
                $insert_results = insertCSVData($received_data, $table, $pdo); // Pass $pdo
                $upload_message = sprintf(
                    "<div class='message success'>Total Rows: %d<br>Successfully Inserted: %d<br>Errors: %d</div>",
                    count($received_data) -1, // Count rows in received data, minus header.
                    $insert_results['success'],
                    $insert_results['errors']
                );
                if (!empty($insert_results['error_messages'])) {
                        $upload_message .= "<div class='message error'>Error details:<br>" . implode("<br>", $insert_results['error_messages']) . "</div>";
                }
            }
            } catch (PDOException $e) {
                 logError("Database connection error in upload_panel.php (insert): " . $e->getMessage());
                $upload_message = "<div class='message error'>A database error occurred. Please try again.</div>";
            }
            // Clear session data after successful/failed insertion.  VERY IMPORTANT!
            unset($_SESSION['temp_csv_data']);
            unset($_SESSION['temp_file_name']);
            unset($_SESSION['temp_file_size']);
            unset($_SESSION['temp_total_rows']);
            }
    }
}
?>
<?php
// --- HTML Starts Here ---
// Include the header *BEFORE* any other HTML output.
$pageTitle = "آپلود CSV داده‌های پنل‌ها - فقط مدیر"; // Set the page title
require_once 'header.php';
?>

    <div class="container">
       

        <h2>آپلود فایل CSV داده‌های پنل‌ها</h2>

        <form action="" method="post" enctype="multipart/form-data" id="uploadForm">
             <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-group">
                <label for="table_select">انتخاب جدول:</label>
                <select name="table_select" id="table_select" required>
                    <option value="hpc_panels" <?php echo ($table === 'hpc_panels') ? 'selected' : ''; ?>>HPC Panels</option>
                    <option value="sarotah" <?php echo ($table === 'sarotah') ? 'selected' : ''; ?>>Sarotah</option>
                    <option value="formwork_types" <?php echo ($table === 'formwork_types') ? 'selected' : ''; ?>>Formwork Types</option>
                </select>
            </div>

            <div class="form-group">
                <label for="csvFile">انتخاب فایل CSV:</label>
                <input type="file" id="csvFile" name="csvFile" accept=".csv">
                 <div class="file-drop-zone" id="fileDropZone">
                    <p>فایل CSV را اینجا بکشید و رها کنید یا کلیک کنید.</p>
                </div>
                <div class="validation-message" id="fileValidation"></div>
            </div>
                <?php if (!$show_preview): ?>
                    <button type="submit" class="btn">آپلود و پردازش</button>
                <?php endif; ?>
        </form>

        <?php echo $upload_message; ?>

        <?php if ($show_preview && !empty($preview_data)): ?>
           <form action="" method="post" id="confirmForm">
                <input type="hidden" name="table_select" value="<?php echo htmlspecialchars($table); ?>">
                <input type="hidden" name="confirm_upload" value="1">
                <!-- Hidden input to hold the CSV data -->
                <input type="hidden" name="csv_data" id="csvData" value="">
                 <!-- CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div class="preview-container">
                <h3>پیش‌نمایش فایل</h3>
                <div class="file-info">
                    <div>نام فایل:  <?php echo htmlspecialchars($_SESSION['temp_file_name']); ?></div>
                    <div>اندازه: <?php echo htmlspecialchars(formatFileSize($_SESSION['temp_file_size'])); ?></div>
                    <div>تعداد ردیف‌ها:  <?php echo htmlspecialchars($_SESSION['temp_total_rows']); ?></div>
                </div>
                <table class="preview-table" id="previewTable">
                    <thead>
                        <tr>
                            <?php foreach ($preview_data[0] as $index => $header): ?>
                                <th data-column-index="<?php echo $index; ?>"><?php echo htmlspecialchars($header); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                       <?php for ($i = 1; $i < count($preview_data); $i++): ?>
                            <tr data-row-index="<?php echo $i; ?>">
                                <?php foreach ($preview_data[$i] as $index => $cell): ?>
                                    <td contenteditable="true" data-column-index="<?php echo $index; ?>"><?php echo htmlspecialchars($cell); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn confirm-upload">تایید و آپلود نهایی</button>
                <button type="button" class="btn" id="cancelPreview">انصراف</button>
            </div>
           </form>
        <?php endif; ?>

        <div class="preview">
            <h3>الزامات فرمت CSV:</h3>
             <div class="button-group">
                <button class="btn download-template" data-table="hpc_panels">دانلود تمپلیت HPC Panels</button>
                <button class="btn download-template" data-table="sarotah">دانلود تمپلیت Sarotah</button>
                <button class="btn download-template" data-table="formwork_types">دانلود تمپلیت Formwork Types</button>
            </div>
           <div id="hpc_panels_format" <?php echo ($table !== 'hpc_panels') ? 'style="display: none;"' : ''; ?>>
            <h4>فرمت پنل‌های HPC:</h4>
            <table class = "example-table">
                <thead>
                    <tr>
                        <th>آدرس</th>
                        <th>نوع</th>
                        <th>مساحت</th>
                        <th>عرض (mm)</th>
                        <th>طول (mm)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>S-T8-U9</td>
                        <td>Flat -Type A</td>
                        <td>1.319</td>
                        <td>390.0</td>
                        <td>1783.0</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="sarotah_format" <?php echo ($table !== 'sarotah') ? 'style="display: none;"' : ''; ?>>
            <h4>فرمت ثروت:</h4>
           <table class = "example-table">
                <thead>
                    <tr>
                        <th>پنل چپ</th>
                        <th>بعد ۱</th>
                        <th>بعد ۲</th>
                        <th>بعد ۳</th>
                        <th>بعد ۴</th>
                        <th>بعد ۵</th>
                        <th>پنل راست</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>S-C19-D20-(C19)</td>
                        <td>100</td>
                        <td>450</td>
                        <td>500</td>
                        <td>50</td>
                        <td></td>
                        <td>S-C19-D20-(D20)</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="formwork_types_format" <?php echo ($table !== 'formwork_types') ? 'style="display: none;"' : '';?>>
            <h4>فرمت Formwork Types:</h4>
            <table class = "example-table">
                <thead>
                <tr>
                    <th>نوع</th>
                    <th>عرض (mm)</th>
                    <th>حداکثر طول (m)</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>F-H-1</td>
                    <td>280</td>
                    <td>1.824</td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
    </div>

    <script>
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 بایت';
            const k = 1024;
            const sizes = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[$i];
        }

        function validateFile(file) {
            const validationMessage = document.getElementById('fileValidation');
            const maxSize = 5 * 1024 * 1024; // 5MB

            if (file.size > maxSize) {
                validationMessage.textContent = 'حجم فایل باید کمتر از ۵ مگابایت باشد.';
                return false;
            }
            if (file.type !== 'text/csv' && !file.name.endsWith('.csv')) {
                validationMessage.textContent = 'لطفا یک فایل CSV معتبر آپلود کنید.';
                return false;
            }
            validationMessage.textContent = ''; // Clear any previous error
            return true;
        }

        document.getElementById('csvFile').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) {return;}

            if (!validateFile(file)) {
                this.value = ''; // Clear file input
                return;
            }
        document.getElementById('uploadForm').submit(); // Submit the form for preview

        });

         document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const fileInput = document.getElementById('csvFile');
            if (!fileInput.files || fileInput.files.length === 0) {
                document.getElementById('fileValidation').textContent = "لطفاً یک فایل برای آپلود انتخاب کنید.";
                e.preventDefault(); // Prevent form submission if no file
            }

        });

        // Download template functionality
        document.querySelectorAll('.download-template').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const table = this.dataset.table;
                let template = '';
                let fileName = '';

                if (table === 'hpc_panels') {
                    template = 'Address,Type,Area,Width,Length\nS-T8-U9,Flat -Type A,1.319,390.0,1783.0';
                    fileName = 'hpc_panels_template.csv';
                } else if (table === 'sarotah') {
                    template = 'Left Panel,Dimension 1,Dimension 2,Dimension 3,Dimension 4,Dimension 5,Right Panel\nS-C19-D20-(C19),100,450,500,50,,S-C19-D20-(D20)';
                    fileName = 'sarotah_template.csv';
                } else {
                    template = 'Type,Width,Max Length\nF-H-1,280,1.824';
                    fileName = 'formwork_types_template.csv';
                }
                const blob = new Blob([template], { type: 'text/csv;charset=utf-8;' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = fileName;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            });
        });

        // Toggle CSV format display
        document.getElementById('table_select').addEventListener('change', function() {
            const hpcFormat = document.getElementById('hpc_panels_format');
            const sarotahFormat = document.getElementById('sarotah_format');
            const formworkFormat = document.getElementById('formwork_types_format');

            hpcFormat.style.display = 'none';
            sarotahFormat.style.display = 'none';
            formworkFormat.style.display = 'none';

            if (this.value === 'hpc_panels') {
                hpcFormat.style.display = 'block';
            } else if (this.value === 'sarotah') {
                sarotahFormat.style.display = 'block';
            } else if (this.value === 'formwork_types') {
                formworkFormat.style.display = 'block';
            }
        });

        // Drag and Drop
        const dropZone = document.getElementById('fileDropZone');
        const fileInput = document.getElementById('csvFile');

        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                const event = new Event('change', { bubbles: true });
                fileInput.dispatchEvent(event);
            }
        });
         dropZone.addEventListener('click', function() {
            fileInput.click(); // Trigger the file input
        });

          // Keyboard support for file input and drop zone
        fileInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });

        dropZone.addEventListener('keydown', function(e){
            if(e.key === 'Enter' || e.key === ' '){
                e.preventDefault();
                fileInput.click();
            }
        });

        // --- Preview Table Editing ---
         const previewTable = document.getElementById('previewTable');
        if (previewTable) { // Check if previewTable exists
          previewTable.addEventListener('input', function(e) {
            if (e.target.tagName === 'TD') {
                e.target.classList.add('edited'); // Add a class to indicate editing
            }
          });
        }

         // Cancel Preview and go back to file selection
         const cancelPreviewButton = document.getElementById('cancelPreview');
        if (cancelPreviewButton) { // Null check
            cancelPreviewButton.addEventListener('click', function() {
                // Clear session storage related to preview
                sessionStorage.removeItem('temp_csv_data');
                sessionStorage.removeItem('temp_file_name');
                sessionStorage.removeItem('temp_file_size');
                sessionStorage.removeItem('temp_total_rows');

                 //Remove stored table

                 // Reload the page
                window.location.reload();

                //Alternative (Without reloading, more complex):
                // 1.  Hide the preview container.
                // 2.  Clear the file input.
                // 3.  Clear any messages.
                // 4.  Re-show the initial upload form.  You might need to add/remove
                //     CSS classes or use JavaScript to manipulate the DOM to do this
                //     cleanly, depending on your exact HTML structure.  The reload is
                //     *much* simpler.
            });
        }
        const confirmForm = document.getElementById('confirmForm');
        if(confirmForm){
            confirmForm.addEventListener('submit', function(e) {
                // Collect the edited data from the table
                const rows = Array.from(previewTable.rows);
                const data = rows.map(row => {
                    const cells = Array.from(row.cells);
                    //extract data from cells, handle th and td differently.
                    return cells.map(cell => cell.tagName === 'TH' ? cell.textContent.trim() : cell.textContent.trim());
                });

                // Put data into the hidden input (csvData)
                document.getElementById('csvData').value = JSON.stringify(data);

                 //Form will be submitted normally.
            });
        }
    </script>
    <?php require_once 'footer.php'; ?>
</body>
</html>