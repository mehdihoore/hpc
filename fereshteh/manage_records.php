<?php
// manage_records.php
require_once __DIR__ . '/../../sercon/bootstrap.php'; // Go up one level to find sercon/

secureSession(); // Initializes session and security checks

// Determine which project this instance of the file belongs to.
// This is simple hardcoding based on folder. More complex routing could derive this.
$current_file_path = __DIR__;
$expected_project_key = null;
if (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Arad') !== false) {
    $expected_project_key = 'arad';
} elseif (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Fereshteh') !== false) {
    $expected_project_key = 'fereshteh';
} else {
    // If the file is somehow not in a recognized project folder, handle error
    logError("admin_panel_search.php accessed from unexpected path: " . $current_file_path);
    die("خطای پیکربندی: پروژه قابل تشخیص نیست.");
}


// --- Authorization ---
// 1. Check if logged in
if (!isLoggedIn()) {
    header('Location: /login.php'); // Redirect to common login
    exit();
}
// 2. Check if user has selected ANY project
if (!isset($_SESSION['current_project_config_key'])) {
    logError("Access attempt to {$expected_project_key}/admin_panel_search.php without project selection. User ID: " . $_SESSION['user_id']);
    header('Location: /select_project.php'); // Redirect to project selection
    exit();
}
// 3. Check if the selected project MATCHES the folder this file is in
if ($_SESSION['current_project_config_key'] !== $expected_project_key) {
    logError("Project context mismatch. Session has '{$_SESSION['current_project_config_key']}', expected '{$expected_project_key}'. User ID: " . $_SESSION['user_id']);
    // Maybe redirect to select_project or show an error specific to context mismatch
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}
// 4. Check user role access for this specific page

// --- End Authorization ---
require_once 'header.php'; // Includes the header.  This is now the *only* include needed for the header.
$pageTitle = "مدیریت رکوردها - " . escapeHtml($_SESSION['current_project_name'] ?? 'پروژه');;  // Set the page title *before* including the header.

// Check for admin login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: unauthorized.php'); // Use a relative path
     http_response_code(403);
    exit();
}

secureSession();

// Initialize variables
$message = '';
$records = [];
$current_table = isset($_GET['table']) ? $_GET['table'] : 'formwork_types';  // Default table
$valid_tables = ['formwork_types', 'hpc_panels', 'sarotah']; // Define valid tables


// Ensure the selected table is valid.  Important for security!
if (!in_array($current_table, $valid_tables)) {
    $current_table = 'formwork_types';  // Default to a safe table
}

// --- Database Connection ---
try {
    $pdo = getProjectDBConnection(); // Use PDO connection from config_fereshteh.php
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to exception
     $pdo->exec("SET NAMES utf8mb4"); // FOR PERSIAN

} catch (PDOException $e) {
    logError("Database connection error in manage_records.php: " . $e->getMessage());
    die("A database error occurred. Please try again later."); // User-friendly message
}

// --- Handle Delete Action (using PDO and prepared statements) ---
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];  // Cast to integer for security

    try {
        $stmt = $pdo->prepare("DELETE FROM `$current_table` WHERE id = :id"); // Use backticks, prepared statement
        $stmt->bindParam(':id', $id, PDO::PARAM_INT); // Bind the parameter

        if ($stmt->execute()) {
            $message = "رکورد با موفقیت پاک شد.";
        } else {
             // This should generally not happen with PDO in exception mode.
            $message = "Error deleting record."; // Generic error
        }
    } catch (PDOException $e) {
        logError("Database error (delete): " . $e->getMessage());
        $message = "Error deleting record: " . $e->getMessage();  // Show specific error to admin/log
    }
}

// --- Handle Update Action (using PDO and prepared statements) ---
if (isset($_POST['action']) && $_POST['action'] === 'update') {
    $id = (int)$_POST['id'];  // Cast to integer
    $updates = [];
    $params = [];  // Use an associative array for named parameters

    switch ($current_table) {
        case 'formwork_types':
            $updates = ["type = :type", "width = :width", "max_length = :max_length"];
            $params = [
                ':type' => $_POST['type'],
                ':width' => (float)$_POST['width'],  // Cast to float
                ':max_length' => (float)$_POST['max_length'], // Cast to float
                ':id' => $id // Add the ID for the WHERE clause
            ];
            break;

        case 'hpc_panels':
            $updates = ["address = :address", "type = :type", "area = :area", "width = :width", "length = :length", "status = :status"];
            $params = [
                ':address' => $_POST['address'],
                ':type' => $_POST['type'],
                ':area' => (float)$_POST['area'],    // Cast to float
                ':width' => (float)$_POST['width'],   // Cast to float
                ':length' => (float)$_POST['length'],  // Cast to float
                ':status' => $_POST['status'],
                ':id' => $id
            ];
            break;

        case 'sarotah':
            $updates = [
                "left_panel = :left_panel",
                "dimension_1 = :dimension_1",
                "dimension_2 = :dimension_2",
                "dimension_3 = :dimension_3",
                "dimension_4 = :dimension_4",
                "dimension_5 = :dimension_5",
                "right_panel = :right_panel"
            ];
            $params = [
                ':left_panel' => $_POST['left_panel'],
                ':dimension_1' => (float)$_POST['dimension_1'], // Cast
                ':dimension_2' => (float)$_POST['dimension_2'], // Cast
                ':dimension_3' => (float)$_POST['dimension_3'], // Cast
                ':dimension_4' => (float)$_POST['dimension_4'], // Cast
                ':dimension_5' => (float)$_POST['dimension_5'], // Cast
                ':right_panel' => $_POST['right_panel'],
                ':id' => $id
            ];
            break;
    }

    $sql = "UPDATE `$current_table` SET " . implode(", ", $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql); // Prepare the statement

    try {
        if ($stmt->execute($params)) { // Execute with named parameters
            $message = "رکوردها با موفقیت ویرایش شد!";
        } else {
            $message = "Error updating record.";  // Generic error
        }
    } catch (PDOException $e) {
        logError("Database error (update): " . $e->getMessage());
        $message = "Error updating record: " . $e->getMessage();
    }
}

// --- Handle Insert Action (using PDO and prepared statements) ---
if (isset($_POST['action']) && $_POST['action'] === 'insert') {
    $fields = [];
    $placeholders = [];
    $params = []; // Use named parameters

    switch ($current_table) {
        case 'formwork_types':
            $fields = ["type", "width", "max_length"];
            $placeholders = [":type", ":width", ":max_length"];
            $params = [
                ':type' => $_POST['type'],
                ':width' => (float)$_POST['width'],      // Cast to float
                ':max_length' => (float)$_POST['max_length'] // Cast to float
            ];
            break;

        case 'hpc_panels':
            $fields = ["address", "type", "area", "width", "length", "status", "user_id"];
            $placeholders = [":address", ":type", ":area", ":width", ":length", ":status", ":user_id"];
            $params = [
                ':address' => $_POST['address'],
                ':type' => $_POST['type'],
                ':area' => (float)$_POST['area'],   // Cast to float
                ':width' => (float)$_POST['width'],  // Cast to float
                ':length' => (float)$_POST['length'], // Cast to float
                ':status' => $_POST['status'],
                ':user_id' => $_SESSION['user_id'] // Use session user ID
            ];
            break;

        case 'sarotah':
            $fields = ["left_panel", "dimension_1", "dimension_2", "dimension_3", "dimension_4", "dimension_5", "right_panel"];
            $placeholders = [":left_panel", ":dimension_1", ":dimension_2", ":dimension_3", ":dimension_4", ":dimension_5", ":right_panel"];
            $params = [
                ':left_panel' => $_POST['left_panel'],
                ':dimension_1' => (float)$_POST['dimension_1'], // Cast
                ':dimension_2' => (float)$_POST['dimension_2'], // Cast
                ':dimension_3' => (float)$_POST['dimension_3'], // Cast
                ':dimension_4' => (float)$_POST['dimension_4'], // Cast
                ':dimension_5' => (float)$_POST['dimension_5'], // Cast
                ':right_panel' => $_POST['right_panel']
            ];
            break;
    }

    $sql = "INSERT INTO `$current_table` (" . implode(", ", $fields) . ") VALUES (" . implode(", ", $placeholders) . ")";
    $stmt = $pdo->prepare($sql);

    try {
        if ($stmt->execute($params)) {  // Use named parameters
            $message = "رکورد با موفقیت اضافه شد!";
        } else {
            $message = "Error inserting record."; // Generic error
        }
    } catch (PDOException $e) {
        logError("Database error (insert): " . $e->getMessage());
        $message = "خطا در اضافه کردن رکورد: " . $e->getMessage();  // Show specific error
    }
}


// --- Fetch Records (using PDO) ---
try {
    $stmt = $pdo->query("SELECT * FROM `$current_table` ORDER BY id DESC"); // Use backticks
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC); // Use PDO::FETCH_ASSOC
} catch (PDOException $e) {
    logError("Database error (fetch): " . $e->getMessage());
    $records = []; // Set to an empty array on error
}
?>
<style>
.modal {
    background-color: rgba(0, 0, 0, 0.5);
}

.modal-dialog {
    max-width: 500px;
    margin: 1.75rem auto;
}

.modal-content {
    position: relative;
    background-color: #fff;
    border: 1px solid rgba(0, 0, 0, 0.2);
    border-radius: 0.3rem;
    outline: 0;
}

.form-control {
    display: block;
    width: 100%;
    padding: 0.375rem 0.75rem;
    font-size: 1rem;
    line-height: 1.5;
    border: 1px solid #ced4da;
    border-radius: 0.25rem;
}
.modal {
    z-index: 1050;
}
.modal-backdrop {
    z-index: 1040;
}
.modal-dialog {
    z-index: 1060;
}
</style>
<div class="content-wrapper"> <!-- WRAP ALL PAGE CONTENT -->
    

    <?php if (!empty($message)): ?>
        <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4" role="alert">
            <p><?php echo $message; ?></p>
        </div>
    <?php endif; ?>

    <ul class="flex flex-wrap text-sm font-medium text-center text-gray-500 border-b border-gray-200 mb-4">
        <?php foreach ($valid_tables as $table): ?>
            <li class="mr-2">
                <a href="?table=<?php echo $table; ?>" class="inline-block p-4 rounded-t-lg <?php echo $current_table === $table ? 'bg-gray-100 text-blue-600 font-semibold' : 'hover:text-gray-600 hover:bg-gray-50'; ?>">
                    <?php echo ucwords(str_replace('_', ' ', $table)); ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="mb-3">
    <!-- Use Tailwind classes for smaller button -->
        <button type="button" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-3 rounded text-sm" data-bs-toggle="modal" data-bs-target="#addRecordModal">
            افزودن رکورد جدید
        </button>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full bg-white border border-gray-200">
            <thead class = "bg-gray-50">
                <tr>
                    <th class = "px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <?php
                    // Dynamically generate table headers from the first record
                    if (!empty($records)) {
                        foreach (array_keys(reset($records)) as $key) {
                            if ($key !== 'id') { // Exclude the 'id' column
                                 echo "<th class=\"px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider\">" . ucwords(str_replace('_', ' ', $key)) . "</th>";
                            }
                        }
                    }
                    ?>
                    <th class = "px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($records as $record): ?>
                    <tr>
                        <?php foreach ($record as $key => $value): ?>
                            <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($value ?? 'N/A'); ?></td>
                        <?php endforeach; ?>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <!-- Use Tailwind classes for smaller, styled buttons -->
                            <button type="button" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-1 px-2 rounded text-xs"
                                    onclick="editRecord(<?php echo htmlspecialchars(json_encode($record)); ?>)">
                                Edit
                            </button>
                            <!-- Added type="button" and fixed the onclick -->
                            <button type="button" class="bg-red-500 hover:bg-red-700 text-white font-bold py-1 px-2 rounded text-xs ml-2"
                                    onclick="deleteRecord(<?php echo $record['id']; ?>)">
                                Delete
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>  <!-- Close content-wrapper -->

<!-- Add Record Modal -->
<div class="modal fade" id="addRecordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
      <div class="modal-body">
        <form id="addForm" method="POST">
          <input type="hidden" name="action" value="insert">
            <?php
            // Dynamically generate form fields based on the selected table
            switch ($current_table) {
                case 'formwork_types':
                    ?>
                    <div class="mb-3">
                        <label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                        <input type="text" name="type" id="type" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    </div>
                   <div class="mb-3">
                        <label for="width" class="block text-sm font-medium text-gray-700">Width (mm)</label>
                        <input type="number" step="0.01" name="width" id="width" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    </div>
                    <div class="mb-3">
                        <label for="max_length" class="block text-sm font-medium text-gray-700">Max Length (m)</label>
                        <input type="number" step="0.001" name="max_length" id="max_length" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    </div>

                    <?php
                    break;

                case 'hpc_panels':
                    ?>
                     <div class="mb-3">
                        <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                        <input type="text" name="address" id="address" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    </div>
                    <div class="mb-3">
                        <label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                        <input type="text" name="type" id="type" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    </div>
                    <div class="mb-3">
                        <label for="area" class="block text-sm font-medium text-gray-700">Area</label>
                        <input type="number" step="0.001" name="area" id="area" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    </div>
                    <div class="mb-3">
                        <label for="width" class="block text-sm font-medium text-gray-700">Width</label>
                        <input type="number" step="0.1" name="width" id="width" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    </div>
                    <div class="mb-3">
                        <label for="length" class="block text-sm font-medium text-gray-700">Length</label>
                        <input type="number" step="0.1" name="length" id="length" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    </div>
                    <div class="mb-3">
                        <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                         <select name="status" id="status" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <?php
                    break;

                case 'sarotah':
                    ?>
                    <div class="mb-3">
                        <label for="left_panel" class="block text-sm font-medium text-gray-700">Left Panel</label>
                        <input type="text" name="left_panel" id="left_panel" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    </div>
                     <div class="mb-3">
                        <label for="dimension_1" class="block text-sm font-medium text-gray-700">Dimension 1</label>
                        <input type="number" step="0.1" name="dimension_1" id="dimension_1" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    </div>
                    <div class="mb-3">
                        <label for="dimension_2" class="block text-sm font-medium text-gray-700">Dimension 2</label>
                        <input type="number" step="0.1" name="dimension_2" id="dimension_2" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    </div>
                    <div class="mb-3">
                        <label for="dimension_3" class="block text-sm font-medium text-gray-700">Dimension 3</label>
                        <input type="number" step="0.1" name="dimension_3" id="dimension_3" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    </div>
                    <div class="mb-3">
                        <label for="dimension_4" class="block text-sm font-medium text-gray-700">Dimension 4</label>
                        <input type="number" step="0.1" name="dimension_4" id="dimension_4" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    </div>
                    <div class="mb-3">
                        <label for="dimension_5" class="block text-sm font-medium text-gray-700">Dimension 5</label>
                        <input type="number" step="0.1" name="dimension_5" id="dimension_5" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    </div>
                    <div class="mb-3">
                        <label for="right_panel" class="block text-sm font-medium text-gray-700">Right Panel</label>
                        <input type="text" name="right_panel" id="right_panel" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    </div>
                    <?php
                    break;
            }
            ?>
             <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Add Record</button>
            </div>
          </form>
      </div>
    </div>
  </div>
</div>


<!-- Edit Record Modal -->
<div class="modal fade" id="editRecordModal" tabindex="-1">
<div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editRecordModalLabel">Edit Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Edit form, similar to add form but with pre-filled values -->
                <form id="editForm" method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit_id">
                    <?php
                    switch ($current_table) {
                        case 'formwork_types':
                            ?>
                            <div class="form-group">
                                <label>Type</label>
                                <input type="text" name="type" id="edit_type" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Width (mm)</label>
                                <input type="number" step="0.01" name="width" id="edit_width" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Max Length (m)</label>
                                <input type="number" step="0.001" name="max_length" id="edit_max_length" class="form-control" required>
                            </div>
                            <?php
                            break;

                        case 'hpc_panels':
                            ?>
                            <div class="form-group">
                                <label>Address</label>
                                <input type="text" name="address" id="edit_address" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Type</label>
                                <input type="text" name="type" id="edit_type" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Area</label>
                                <input type="number" step="0.001" name="area" id="edit_area" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Width</label>
                                <input type="number" step="0.1" name="width" id="edit_width" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Length</label>
                                <input type="number" step="0.1" name="length" id="edit_length" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="edit_status" class="form-control" required>
                                    <option value="pending">Pending</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <?php
                            break;

                        case 'sarotah':
                            ?>
                            <div class="form-group">
                                <label>Left Panel</label>
                                <input type="text" name="left_panel" id="edit_left_panel" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Dimension 1</label>
                                <input type="number" step="0.1" name="dimension_1" id="edit_dimension_1" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Dimension 2</label>
                                <input type="number" step="0.1" name="dimension_2" id="edit_dimension_2" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Dimension 3</label>
                                <input type="number" step="0.1" name="dimension_3" id="edit_dimension_3" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Dimension 4</label>
                                <input type="number" step="0.1" name="dimension_4" id="edit_dimension_4" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Dimension 5</label>
                                <input type="number" step="0.1" name="dimension_5" id="edit_dimension_5" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Right Panel</label>
                                <input type="text" name="right_panel" id="edit_right_panel" class="form-control" required>
                            </div>
                            <?php
                            break;
                    }
                    ?>
                    <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
        </div>
    </div>
</div>

<!-- At the end of your body tag -->
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.19/dist/sweetalert2.all.min.js"></script>
<script>
function deleteRecord(id) {
    // Confirm deletion
   if (!confirm('مطمينید می‌خواهید رکورد را پاک کنید؟ این روند برگشت پذیر نیست!')) {
        return;
    }

    // Create a form to submit the delete request
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = ''; // Current page

    // Add hidden input fields for action and id
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'delete';
    form.appendChild(actionInput);

    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'id';
    idInput.value = id;
    form.appendChild(idInput);

    // Append the form to the body and submit it
    document.body.appendChild(form);
    form.submit();
}
// Debug version of modal handling
function editRecord(record) {
    console.log('Edit record called with:', record); // Debug log
    
    try {
        // Populate form fields
        Object.keys(record).forEach(key => {
            const input = document.getElementById('edit_' + key);
            if (input) {
                input.value = record[key];
                console.log(`Set ${key} to ${record[key]}`); // Debug log
            }
        });

        // Get modal element
        const modalElement = document.getElementById('editRecordModal');
        if (!modalElement) {
            console.error('Modal element not found!');
            return;
        }

        // Initialize modal with specific options
        const modalInstance = new bootstrap.Modal(modalElement, {
            keyboard: true,
            backdrop: 'static'
        });

        // Show modal
        modalInstance.show();
        
        // Add close handler
        const closeButtons = modalElement.querySelectorAll('[data-bs-dismiss="modal"]');
        closeButtons.forEach(button => {
            button.addEventListener('click', () => {
                modalInstance.hide();
            });
        });

    } catch (error) {
        console.error('Error in editRecord:', error);
    }
}

// Make sure modals are properly disposed when hidden
document.addEventListener('hidden.bs.modal', function (event) {
    const modal = bootstrap.Modal.getInstance(event.target);
    if (modal) {
        modal.dispose();
    }
});

// Initialize all close buttons
document.addEventListener('DOMContentLoaded', function() {
    const closeButtons = document.querySelectorAll('[data-bs-dismiss="modal"]');
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const modalElement = this.closest('.modal');
            const modalInstance = bootstrap.Modal.getInstance(modalElement);
            if (modalInstance) {
                modalInstance.hide();
            }
        });
    });
});
</script>
<?php require_once 'footer.php'; ?>