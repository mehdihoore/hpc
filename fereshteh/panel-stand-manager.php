<?php
ini_set('display_errors', 1); // Enable error display for debugging
error_reporting(E_ALL);
ob_start(); // Start output buffering to catch stray output/errors
require_once __DIR__ . '/../../sercon/bootstrap.php';
secureSession();
$expected_project_key = 'fereshteh'; // HARDCODED FOR THIS FILE
$current_project_config_key = $_SESSION['current_project_config_key'] ?? null;

if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}
if ($current_project_config_key !== $expected_project_key) {
    logError("Concrete test manager accessed with incorrect project context. Session: {$current_project_config_key}, Expected: {$expected_project_key}, User: {$_SESSION['user_id']}");
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}


if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

$current_user_id = $_SESSION['user_id']; // Get current user ID
$report_key = 'new_panels'; // HARDCODED FOR THIS FILE
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/new_panels.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// --- ROLE DEFINITIONS (Adjust as needed for this specific page) ---
$write_access_roles = ['admin', 'superuser', 'planner'];
$readonly_roles = ['user', 'supervisor', 'receiver'];
$all_view_roles = array_merge($write_access_roles, $readonly_roles);

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $all_view_roles)) {
    header('Location: /login');
    exit('Access Denied.');
}
$isPageReadOnly = in_array($_SESSION['role'], $readonly_roles);
if ($_SESSION['current_project_config_key'] === 'fereshteh') {
    $pageTitle = 'صفحه مدیریت خرک‌ها - پروژه فرشته'; // Force read-only for this project
} elseif ($_SESSION['current_project_config_key'] === 'arad') {
    $pageTitle = 'صفحه مدیریت خرک‌ها - پروژه آراد'; // Force read-only for this project
} else {
    $pageTitle = 'صفحه مدیریت خرک‌ها - پروژه نامشخص'; // Force read-only for this project
}
require_once 'header.php';

$truck_id = isset($_GET['truck_id']) ? (int)$_GET['truck_id'] : 0;
if ($truck_id <= 0) {
    echo '<div class="alert alert-danger">شناسه کامیون نامعتبر است.</div>';
    require_once 'footer.php';
    exit();
}

try {


    // Get Shipment Info, now including global_stand_number_offset
    $stmt_shipment = $pdo->prepare("
        SELECT id, truck_id, stands_sent, stands_returned, status, packing_list_number, 
               COALESCE(global_stand_number_offset, 0) as global_stand_number_offset 
        FROM shipments 
        WHERE truck_id = ? LIMIT 1
    ");
    $stmt_shipment->execute([$truck_id]);
    $shipment = $stmt_shipment->fetch(PDO::FETCH_ASSOC);

    if (!$shipment) {
        echo '<div class="alert alert-danger">هیچ محموله‌ای برای این کامیون یافت نشد.</div>';
        require_once 'footer.php';
        exit();
    }
    $shipment_id = $shipment['id'];
    $stands_count = $shipment['stands_sent'] ?? 0; // Local count of stands for THIS shipment
    $packing_list_number = $shipment['packing_list_number'] ?? 'نامشخص';
    $global_stand_offset = (int)$shipment['global_stand_number_offset']; // The starting offset

    // Calculate the global stand ID range for this shipment
    $min_global_stand_for_shipment = $stands_count > 0 ? (1 + $global_stand_offset) : 0;
    $max_global_stand_for_shipment = $stands_count > 0 ? ($stands_count + $global_stand_offset) : 0;


    $stmt_panels = $pdo->prepare("
        SELECT id, address, truck_id, width, length, Proritization, type, status
        FROM hpc_panels
        WHERE truck_id = ?
        ORDER BY Proritization ASC, address ASC
    ");
    $stmt_panels->execute([$truck_id]);
    $panels = $stmt_panels->fetchAll(PDO::FETCH_ASSOC);

    // Get Current Assignments (stand_id is already global here)
    $stmt_assignments = $pdo->prepare("
        SELECT psa.panel_id, psa.stand_id
        FROM panel_stand_assignments psa
        WHERE psa.truck_id = ? AND psa.shipment_id = ?
    ");
    $stmt_assignments->execute([$truck_id, $shipment_id]);
    $assignments_raw = $stmt_assignments->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    error_log("Database error in panel assignment table: " . $e->getMessage());
    echo '<div class="alert alert-danger">خطای پایگاه داده. لطفاً با مدیر سیستم تماس بگیرید.</div>';
    require_once 'footer.php';
    exit();
}

$initial_assignments_json = json_encode($assignments_raw);

?>
<link href="/assets/css/bootstrap.rtl.min.css" rel="stylesheet">
<div class="panel-assignment-container container mt-4" dir="rtl">
    <h2 class="panel-assign-title">مدیریت تخصیص پنل به خرک (جدول)</h2>
    <div class="panel-assign-info alert alert-info d-flex align-items-center justify-content-between">
        <span>
            <strong>شماره پکینگ لیست:</strong> <?= htmlspecialchars($packing_list_number) ?> |
            <strong>تعداد خرک‌های این محموله:</strong> <?= htmlspecialchars($stands_count) ?>
            <?php if ($stands_count > 0): ?>
                (شماره‌های <?= $min_global_stand_for_shipment ?> الی <?= $max_global_stand_for_shipment ?>)
            <?php endif; ?>
        </span>
        <?php if (!$isPageReadOnly): ?>
            <button id="save-assignments" class="stand-badgeb">
                <i class="fa fa-save"></i> ذخیره تخصیص‌ها
            </button>
        <?php endif; ?>
    </div>
    <div id="save-status" class="mt-2"></div>

    <!-- Filter Controls -->
    <div class="panel-assign-filter card mb-3">
        <div class="card-header panel-assign-card-header">
            <i class="fa fa-filter"></i> فیلتر پنل‌ها
        </div>
        <div class="card-body py-2">
            <div class="row g-2">
                <!-- ... other filter inputs ... -->
                <div class="col-md-2">
                    <select class="form-select form-select-sm panel-filter-input" id="filter-stand" data-column="4">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="unassigned">تخصیص نیافته</option>
                        <?php if ($stands_count > 0): ?>
                            <?php for ($i = 1; $i <= $stands_count; $i++):
                                $current_global_stand_id = $i + $global_stand_offset;
                            ?>
                                <option value="<?= $current_global_stand_id ?>">خرک <?= $current_global_stand_id ?></option>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <!-- ... other filter inputs ... -->
                <div class="col-md-2">
                    <input type="text" class="form-control form-control-sm panel-filter-input" id="filter-address" placeholder="آدرس..." data-column="1">
                </div>
                <div class="col-md-2">
                    <input type="text" class="form-control form-control-sm panel-filter-input" id="filter-zone" placeholder="زون..." data-column="2">
                </div>
                <div class="col-md-1">
                    <input type="number" class="form-control form-control-sm panel-filter-input" id="filter-width" placeholder="عرض..." data-column="3">
                </div>
                <div class="col-md-1">
                    <input type="number" class="form-control form-control-sm panel-filter-input" id="filter-length" placeholder="طول..." data-column="3"> <!-- Note: Combined Width/Length column -->
                </div>

                <div class="col-md-2">
                    <button class="btn btn-secondary btn-sm w-100" id="clear-filters">
                        <i class="fa fa-times"></i> پاک کردن فیلترها
                    </button>
                </div>
                <div class="col-md-2 text-muted" id="visible-rows-count">
                    <!-- Count updated by JS -->

                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Assignment Controls -->
    <?php if (!$isPageReadOnly && $stands_count > 0): ?>
        <div class="card panel-bulk-card mb-3">
            <div class="card-body py-2 bg-light">
                <div class="row g-2 align-items-center">
                    <div class="col-auto">
                        <label for="bulk-assign-stand" class="col-form-label col-form-label-sm">تخصیص <strong id="bulk-selected-count">0</strong> پنل انتخاب شده به:</label>
                    </div>
                    <div class="col-auto">
                        <select class="form-select form-select-sm panel-bulk-select" id="bulk-assign-stand">
                            <option value="">انتخاب خرک...</option>
                            <?php for ($i = 1; $i <= $stands_count; $i++):
                                $current_global_stand_id = $i + $global_stand_offset;
                            ?>
                                <option value="<?= $current_global_stand_id ?>">خرک <?= $current_global_stand_id ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-success btn-sm px-3" id="bulk-assign-button" disabled>
                            <i class="fa fa-check"></i> تخصیص
                        </button>
                    </div>
                </div>
                <div id="stand-counts-display" class="mt-3 d-flex flex-wrap gap-2 justify-content-center">
                    <!-- Counts will be populated by JS -->
                </div>
            </div>
        </div>
    <?php endif; ?>


    <!-- Panels Table -->
    <div class="panel-table-container mb-4">
        <table class="table table-bordered table-striped table-hover table-sm panel-assign-table" id="panels-table">
            <!-- ... thead ... -->
            <thead class="thead-light panel-table-header">
                <tr>
                    <?php if (!$isPageReadOnly): ?>
                        <th style="width: 5%;"><input type="checkbox" id="select-all-visible" title="انتخاب همه قابل مشاهده"></th>
                    <?php else: ?>
                        <th style="width: 5%;"></th>
                    <?php endif; ?>
                    <th class="sortable" data-column="address" style="width: 25%;">آدرس <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-column="zone" style="width: 10%;">زون <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-column="dimensions" style="width: 17%;">ابعاد (WxL) <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-column="assignment" style="width: 17%;">وضعیت / تخصیص به خرک <i class="fa fa-sort"></i></th>
                    <?php if (!$isPageReadOnly): ?>
                        <th style="width: 15%;">عملیات</th>
                    <?php else: ?>
                        <th style="width: 15%;"></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($panels)): ?>
                    <tr>
                        <td colspan="<?= $isPageReadOnly ? 5 : 6 ?>" class="text-center">هیچ پنلی برای این کامیون یافت نشد.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($panels as $panel):
                        $panel_id = $panel['id'];
                        $assigned_stand = $assignments_raw[$panel_id] ?? null; // This is already global stand ID
                        $is_assigned = $assigned_stand !== null;
                    ?>
                        <tr
                            data-panel-id="<?= htmlspecialchars($panel_id) ?>"
                            data-address="<?= htmlspecialchars($panel['address']) ?>"
                            data-zone="<?= htmlspecialchars($panel['Proritization']) ?>"
                            data-width="<?= htmlspecialchars(intval($panel['width'])) ?>"
                            data-length="<?= htmlspecialchars(intval($panel['length'])) ?>"
                            class="<?= $is_assigned ? 'assigned-panel-row' : '' ?>"
                            data-current-stand="<?= $is_assigned ? htmlspecialchars($assigned_stand) : '' ?>">
                            <?php if (!$isPageReadOnly): ?>
                                <td class="text-center align-middle">
                                    <input type="checkbox" class="panel-select-checkbox" value="<?= htmlspecialchars($panel_id) ?>" <?= $is_assigned ? 'disabled' : '' ?>>
                                </td>
                            <?php else: ?>
                                <td class="text-center align-middle"></td>
                            <?php endif; ?>
                            <td class="align-middle"><?= htmlspecialchars($panel['address']) ?></td>
                            <td class="align-middle text-center"><?= htmlspecialchars($panel['Proritization']) ?></td>
                            <td class="align-middle text-center"><?= htmlspecialchars(intval($panel['width'])) ?> × <?= htmlspecialchars(intval($panel['length'])) ?></td>
                            <td class="assignment-cell text-center align-middle">
                                <?php if ($is_assigned): ?>
                                    <span class="badge stand-badge" data-stand-id="<?= htmlspecialchars($assigned_stand) ?>">
                                        خرک <?= htmlspecialchars($assigned_stand) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">تخصیص نیافته</span>
                                <?php endif; ?>
                            </td>
                            <?php if (!$isPageReadOnly): ?>
                                <td class="action-cell text-center align-middle">
                                    <?php if ($is_assigned): ?>
                                        <button class="btn btn-danger btn-sm panel-unassign-btn" title="لغو تخصیص">
                                            <i class="fa fa-times"></i>
                                        </button>
                                    <?php else: ?>
                                        <?php if ($stands_count > 0): // Only show input if stands exist for this shipment 
                                        ?>
                                            <div class="input-group input-group-sm panel-assign-input-group">
                                                <input type="number" class="form-control panel-assign-input"
                                                    placeholder="#"
                                                    min="<?= $min_global_stand_for_shipment ?>"
                                                    max="<?= $max_global_stand_for_shipment ?>">
                                                <button class="panel-assign-btn" type="button" title="تخصیص">
                                                    <i class="fa fa-check"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">بدون خرک</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php else: ?>
                                <td class="action-cell text-center align-middle"></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="alert alert-secondary mt-2 panel-count-info">
        تعداد کل پنل‌ها: <?= count($panels) ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Make these available globally for the script below
    const SCRIPT_VARS = {
        isPageReadOnly: <?= json_encode($isPageReadOnly); ?>,
        truckId: <?= json_encode($truck_id) ?>,
        shipmentId: <?= json_encode($shipment_id) ?>,
        localStandsCount: <?= json_encode($stands_count) ?>,
        globalStandOffset: <?= json_encode($global_stand_offset) ?>,
        minGlobalStandForShipment: <?= json_encode($min_global_stand_for_shipment) ?>,
        maxGlobalStandForShipment: <?= json_encode($max_global_stand_for_shipment) ?>,
        rawInitialAssignments: <?= $initial_assignments_json ?> // Keep it as JSON string initially
    };
</script>
<!-- Include your existing CSS via <style> block or linked stylesheet -->
<style>
    /* YOUR EXISTING CSS FROM THE PREVIOUS WORKING VERSION */
    /* Custom Styles for Panel Assignment - Using unique class names */
    @font-face {
        font-family: 'Vazir';
        src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
    }

    .panel-assignment-container {
        font-family: 'Vazir', sans-serif;
        max-width: 1200px;
        /* Limit width for better readability */
        margin-left: auto;
        /* Center container */
        margin-right: auto;
    }

    .panel-assign-title {
        font-size: 1.5rem;
        margin-bottom: 1rem;
        color: #333;
    }

    .panel-assign-info {
        font-size: 0.9rem;
    }

    /* Improve table appearance */
    .panel-assign-table {
        font-size: 0.875rem;
        border-collapse: separate;
        /* Needed for border-radius on cells if desired */
        border-spacing: 0;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .panel-table-container {
        overflow-x: auto;
        /* Allows horizontal scroll */
        max-height: 65vh;
        /* Limit table height and make it scrollable */
        overflow-y: auto;
        border: 1px solid #dee2e6;
        border-radius: 0.25rem;
    }

    .panel-table-header th {
        background-color: #e9ecef;
        /* Slightly darker header */
        color: #495057;
        position: sticky;
        top: 0;
        /* Stick to top */
        z-index: 10;
        /* Ensure header is above content */
        border-top: none;
        /* Remove default top border */
        border-bottom-width: 2px;
    }

    /* Add vertical borders back to header */
    .panel-table-header th:not(:last-child) {
        border-right: 1px solid #dee2e6;
    }

    /* Align header text */
    .panel-table-header th {
        vertical-align: middle;
        text-align: center;
        /* Center header text */
    }

    /* Specific alignment for Address */
    .panel-table-header th[data-column="address"] {
        text-align: right;
    }

    /* Ensure checkbox header is centered */
    .panel-table-header th:first-child {
        text-align: center;
    }


    /* Style for assigned rows */
    .assigned-panel-row {
        background-color: #e2f0d9 !important;
        /* Light green */
        transition: background-color 0.3s ease;
    }

    .assigned-panel-row:hover {
        background-color: #d4e9c8 !important;
        /* Slightly darker green on hover */
    }

    .panel-assign-table tbody tr:hover {
        background-color: #f1f1f1;
        /* Subtle hover for non-assigned rows */
    }

    .stand-badgeb {
        font-size: 0.85em;
        padding: 0.35em 0.6em;
        color: black;
        display: inline-block;
        min-width: 80px;
        text-align: center;
        border-radius: 4px;
        font-weight: 500;
        background-color: #f8f9fa;
        border: 1px solid #ced4da;
        cursor: pointer;
        transition: background-color 0.3s ease, color 0.3s ease;
    }

    .stand-badgeb.btn-warning {
        /* For unsaved changes indication */
        background-color: #ffc107;
        border-color: #ffc107;
        color: #000;
    }


    .stand-badgeb:hover {
        background-color: #e2e6ea;
        color: #212529;
    }

    .stand-badgeb.btn-warning:hover {
        background-color: #e0a800;
        border-color: #d39e00;
    }


    /* Style for stand badges */
    .stand-badge {
        font-size: 0.85em;
        padding: 0.35em 0.6em;
        color: white;
        display: inline-block;
        min-width: 80px;
        text-align: center;
        border-radius: 4px;
        font-weight: 500;
    }

    .panel-assign-table td .panel-unassign-btn {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        padding: 0 !important;
        margin: 0;
        width: 24px;
        height: 24px;
        font-size: 1rem;
        line-height: 1;
        background-color: transparent !important;
        border: none !important;
        color: #dc3545 !important;
        cursor: pointer;
        opacity: 0.7;
        vertical-align: middle;
        box-shadow: none !important;
        border-radius: 50%;
        text-decoration: none !important;
    }

    .panel-assign-table .panel-assign-input-group .panel-assign-input {
        flex: 1 1 auto !important;
        min-width: 60px;
        text-align: center;
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
        height: auto;
        line-height: 1.5;
        position: relative;
        z-index: 2;
    }

    .panel-assign-table .panel-assign-input-group .panel-assign-btn {
        flex: 0 0 auto !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.25rem 0.6rem;
        font-size: 0.8rem;
        line-height: 1;
        height: auto;
        position: relative;
        z-index: 2;
    }

    .panel-assign-table td .panel-unassign-btn i {
        margin: 0;
        padding: 0;
        line-height: 1;
        vertical-align: baseline;
    }

    .panel-assign-table td .panel-unassign-btn:hover,
    .panel-assign-table td .panel-unassign-btn:focus {
        color: #a71d2a !important;
        opacity: 1;
        background-color: #f8d7da !important;
        border: none !important;
        box-shadow: none !important;
        text-decoration: none !important;
    }

    .panel-assign-input-group {
        flex-wrap: nowrap;
        width: 100%;
        max-width: 150px;
        margin: 0 auto;
        display: flex !important;
        align-items: stretch;
    }

    /*
    Removed .panel-assign-input and .panel-assign-btn general rules
    as they are now more specifically targeted with
    .panel-assign-table .panel-assign-input-group .panel-assign-input
    and
    .panel-assign-table .panel-assign-input-group .panel-assign-btn
    */

    .panel-filter-input {
        font-size: 0.85rem;
    }

    .panel-assign-card-header {
        padding: 0.5rem 1rem;
        background-color: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
    }

    .panel-assign-filter .card-body,
    .panel-bulk-card .card-body {
        padding-top: 0.75rem;
        padding-bottom: 0.75rem;
    }

    .panel-bulk-card label {
        margin-bottom: 0;
    }

    .stand-color-1 {
        background-color: #0066cc;
    }

    .stand-color-2 {
        background-color: #1a8754;
    }

    .stand-color-3 {
        background-color: #dc3545;
    }

    .stand-color-4 {
        background-color: #fd7e14;
    }

    .stand-color-5 {
        background-color: #0dcaf0;
    }

    .stand-color-6 {
        background-color: #6f42c1;
    }

    .stand-color-7 {
        background-color: #d63384;
    }

    .stand-color-8 {
        background-color: #20c997;
    }

    .stand-color-9 {
        background-color: #343a40;
    }

    .stand-color-10 {
        background-color: #6c757d;
    }

    #stand-counts-display {
        font-size: 0.8rem;
        padding-top: 0.5rem;
        border-top: 1px solid #eee;
    }

    .stand-count-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.3em 0.6em;
        border-radius: 4px;
        font-weight: 500;
        min-width: 80px;
        text-align: center;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    .stand-count-badge .count {
        margin-left: 5px;
        /* Switched to margin-left for RTL text "خرک X: [COUNT]" */
        font-weight: bold;
        background-color: rgba(255, 255, 255, 0.2);
        padding: 0.1em 0.4em;
        border-radius: 3px;
        min-width: 1.5em;
        display: inline-block;
        text-align: center;
    }

    th.sortable {
        cursor: pointer;
        position: relative;
    }

    th.sortable i {
        opacity: 0.4;
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
    }

    th.sortable.sort-asc i,
    th.sortable.sort-desc i {
        opacity: 1;
    }

    th.sortable.sort-asc i::before {
        content: "\f0de";
    }

    th.sortable.sort-desc i::before {
        content: "\f0dd";
    }

    tr.hidden-panel-row {
        display: none;
    }

    .panel-count-info {
        padding: 0.5rem 1rem;
        margin-bottom: 1rem;
        font-size: 0.9rem;
    }

    body.page-is-read-only .panel-select-checkbox,
    body.page-is-read-only .panel-assign-input,
    body.page-is-read-only .panel-assign-btn,
    body.page-is-read-only .panel-unassign-btn,
    body.page-is-read-only #bulk-assign-stand,
    body.page-is-read-only #bulk-assign-button,
    body.page-is-read-only #save-assignments {
        cursor: not-allowed !important;
        opacity: 0.65;
    }

    body.page-is-read-only .panel-bulk-card,
    body.page-is-read-only #save-assignments,
    body.page-is-read-only .action-cell>*,
    body.page-is-read-only .panel-select-checkbox,
    body.page-is-read-only #select-all-visible {
        /* display: none !important; */
        /* Prefer PHP hiding, this is fallback */
    }

    @media (max-width: 992px) {
        .panel-assign-table {
            font-size: 0.8rem;
        }

        .panel-assign-table .panel-assign-input-group .panel-assign-input {
            font-size: 0.7rem;
        }

        .panel-assign-table .panel-assign-input-group .panel-assign-btn {
            padding: 0.2rem 0.4rem;
            font-size: 0.7rem;
        }

        .stand-badge {
            font-size: 0.75em;
        }
    }

    @media (max-width: 768px) {
        .panel-assign-table .panel-assign-input-group .panel-assign-input {
            font-size: 0.75rem;
            min-width: 50px;
        }

        .panel-assign-table .panel-assign-input-group .panel-assign-btn {
            padding: 0.2rem 0.4rem;
            font-size: 0.75rem;
        }

        .panel-assign-input-group {
            max-width: 130px;
        }

        .panel-assign-info span {
            display: block;
            margin-bottom: 5px;
        }

        .panel-assign-info button {
            margin-top: 5px;
        }

        .panel-filter-input {
            margin-bottom: 5px;
        }

        .panel-bulk-card .col-auto {
            margin-bottom: 5px;
        }
    }
</style>
<script>
    $(document).ready(function() {
        const isPageReadOnly = SCRIPT_VARS.isPageReadOnly;
        const truckId = SCRIPT_VARS.truckId;
        const shipmentId = SCRIPT_VARS.shipmentId;
        const localStandsCount = SCRIPT_VARS.localStandsCount;
        const globalStandOffset = SCRIPT_VARS.globalStandOffset;
        const minGlobalStandForShipment = SCRIPT_VARS.minGlobalStandForShipment;
        const maxGlobalStandForShipment = SCRIPT_VARS.maxGlobalStandForShipment;

        // THIS is where initialAssignments should be mutable
        let initialAssignments = JSON.parse(JSON.stringify(SCRIPT_VARS.rawInitialAssignments || {}));
        // --- Read-Only Check ---
        if (isPageReadOnly) {
            $('body').addClass('page-is-read-only');
            // PHP should handle hiding/disabling, JS is fallback
            $('#save-assignments, .panel-bulk-card, .panel-select-checkbox, #select-all-visible').prop('disabled', true);
            $('.action-cell').empty();
        }

        // --- Configuration ---
        // truckId, shipmentId, localStandsCount, globalStandOffset,
        // minGlobalStandForShipment, maxGlobalStandForShipment, initialAssignments
        // are now available from the PHP script block above this.

        let panelAssignments = JSON.parse(JSON.stringify(initialAssignments));
        let hasUnsavedChanges = false;
        let changedStands = new Set();

        const standColors = [
            '#0066cc', '#1a8754', '#dc3545', '#fd7e14', '#0dcaf0',
            '#6f42c1', '#d63384', '#20c997', '#343a40', '#6c757d'
        ];

        // --- Helper Functions ---
        function getStandColorClass(globalStandId) {
            if (!globalStandId) return '';
            // Color cycling based on the global stand ID
            const colorIndex = (parseInt(globalStandId) - 1) % standColors.length;
            return `stand-color-${(colorIndex + 1)}`;
        }

        function getStandHexColor(globalStandId) {
            if (!globalStandId) return '#f8f9fa';
            const colorIndex = (parseInt(globalStandId) - 1) % standColors.length;
            return standColors[colorIndex];
        }

        function checkUnsavedChanges() {
            if (isPageReadOnly) {
                hasUnsavedChanges = false;
                $('#save-assignments').removeClass('btn-warning').addClass('stand-badgeb');
                return;
            }
            hasUnsavedChanges = false;
            changedStands.clear();

            const allPanelIds = new Set([
                ...Object.keys(initialAssignments),
                ...Object.keys(panelAssignments)
            ]);

            allPanelIds.forEach(panelIdStr => {
                const initialStand = initialAssignments[panelIdStr];
                const currentStand = panelAssignments[panelIdStr];

                if (initialStand !== currentStand) {
                    hasUnsavedChanges = true;
                    if (initialStand) changedStands.add(initialStand.toString());
                    if (currentStand) changedStands.add(currentStand.toString());
                }
            });
            updateBulkDropdownIndicators();
            $('#save-assignments').toggleClass('btn-warning', hasUnsavedChanges).toggleClass('stand-badgeb', !hasUnsavedChanges);
        }

        function updateBulkDropdownIndicators() {
            if (isPageReadOnly || localStandsCount === 0) return; // Added localStandsCount check
            const $bulkSelect = $('#bulk-assign-stand');
            $bulkSelect.find('option').each(function() {
                const $option = $(this);
                const standId = $option.val(); // This is global stand ID
                let originalText = $option.data('original-text');

                if (!standId) return;

                if (originalText === undefined) {
                    originalText = $option.text();
                    $option.data('original-text', originalText);
                }

                if (changedStands.has(standId)) {
                    if (!originalText.endsWith(' *')) {
                        $option.text(originalText + ' *');
                    }
                } else {
                    $option.text(originalText);
                }
            });
        }

        function updateRowUI(rowElement, panelId, assignedGlobalStandId) {
            const $row = $(rowElement);
            const $assignCell = $row.find('.assignment-cell');
            const $actionCell = $row.find('.action-cell');
            const $checkbox = $row.find('.panel-select-checkbox');

            $row.attr('data-current-stand', assignedGlobalStandId ? assignedGlobalStandId : '');

            if (assignedGlobalStandId) {
                const colorClass = getStandColorClass(assignedGlobalStandId);
                const hexColor = getStandHexColor(assignedGlobalStandId);
                const textColor = isLight(hexColor) ? '#333' : '#fff';
                const badgeHtml = `
            <span class="badge stand-badge ${colorClass}" data-stand-id="${assignedGlobalStandId}" style="background-color: ${hexColor}; color: ${textColor};">
                خرک ${assignedGlobalStandId}
            </span>`;
                $assignCell.html(badgeHtml);
                $row.addClass('assigned-panel-row');
            } else {
                const statusHtml = `<span class="badge bg-secondary">تخصیص نیافته</span>`;
                $assignCell.html(statusHtml);
                $row.removeClass('assigned-panel-row').css('background-color', '');
            }

            if (isPageReadOnly) {
                $actionCell.html('');
                $checkbox.prop('disabled', true).hide();
            } else {
                if (assignedGlobalStandId) {
                    const actionHtml = `
                     <button class="btn btn-danger btn-sm panel-unassign-btn" title="لغو تخصیص">
                         <i class="fa fa-times"></i>
                     </button>`;
                    $actionCell.html(actionHtml);
                    $checkbox.prop('checked', false).prop('disabled', true);
                } else {
                    if (localStandsCount > 0) { // Only show input if stands exist for this shipment
                        const assignInputHtml = `
                         <div class="input-group input-group-sm panel-assign-input-group">
                             <input type="number" class="form-control panel-assign-input" placeholder="#"
                                    min="${minGlobalStandForShipment}" max="${maxGlobalStandForShipment}">
                             <button class="panel-assign-btn" type="button" title="تخصیص">
                                 <i class="fa fa-check"></i>
                             </button>
                         </div>`;
                        $actionCell.html(assignInputHtml);
                    } else {
                        $actionCell.html('<span class="text-muted small">بدون خرک</span>');
                    }
                    $checkbox.prop('disabled', false);
                }
                updateBulkAssignState();
            }
            updateStandCountsDisplay();
        }

        function isLight(hexColor) {
            if (!hexColor || hexColor.length < 4) return true;
            const color = hexColor.charAt(0) === '#' ? hexColor.substring(1, 7) : hexColor;
            let r, g, b;
            if (color.length === 3) {
                r = parseInt(color.substring(0, 1).repeat(2), 16);
                g = parseInt(color.substring(1, 2).repeat(2), 16);
                b = parseInt(color.substring(2, 3).repeat(2), 16);
            } else if (color.length === 6) {
                r = parseInt(color.substring(0, 2), 16);
                g = parseInt(color.substring(2, 4), 16);
                b = parseInt(color.substring(4, 6), 16);
            } else {
                return true;
            }
            const uicolors = [r / 255, g / 255, b / 255];
            const c = uicolors.map((col) => (col <= 0.03928 ? col / 12.92 : Math.pow((col + 0.055) / 1.055, 2.4)));
            const L = (0.2126 * c[0]) + (0.7152 * c[1]) + (0.0722 * c[2]);
            return L > 0.179;
        }

        function updateStandCountsDisplay() {
            const $displayArea = $('#stand-counts-display');
            if (!$displayArea.length || localStandsCount === 0) { // Added localStandsCount check
                if ($displayArea.length) $displayArea.html(''); // Clear if exists but no stands
                return;
            }

            const standCounts = {};
            Object.values(panelAssignments).forEach(globalStandId => {
                standCounts[globalStandId] = (standCounts[globalStandId] || 0) + 1;
            });

            let displayHtml = '';
            // Iterate through the global stand numbers RELEVANT to THIS shipment
            for (let i = 0; i < localStandsCount; i++) {
                const currentGlobalStandId = minGlobalStandForShipment + i;
                const count = standCounts[currentGlobalStandId] || 0;
                const hexColor = getStandHexColor(currentGlobalStandId);
                const textColor = isLight(hexColor) ? '#333' : '#fff';
                displayHtml += `
               <span class="stand-count-badge" style="background-color: ${hexColor}; color: ${textColor};" title="خرک ${currentGlobalStandId}: ${count} پنل">
                خرک ${currentGlobalStandId}:
                   <span class="count">${count}</span>
               </span>`;
            }
            $displayArea.html(displayHtml || '<span class="text-muted">هنوز پنلی تخصیص داده نشده است.</span>');
        }

        function initializeTable() {
            $('#panels-table tbody tr').each(function() {
                const $row = $(this);
                const panelId = $row.data('panel-id');
                const assignedGlobalStand = panelAssignments[panelId.toString()];
                updateRowUI(this, panelId, assignedGlobalStand);
            });
            updateVisibleRowCount();
            updateStandCountsDisplay();
            checkUnsavedChanges();
            setupEventListeners();
        }

        function filterTable() {
            const filters = {};
            $('.panel-filter-input').each(function() {
                const $input = $(this);
                const column = $input.data('column');
                const value = $input.val().trim().toLowerCase();
                if (value) filters[column] = value;
            });

            $('#panels-table tbody tr').each(function() {
                const $row = $(this);
                let visible = true;
                const address = ($row.data('address') || '').toString().toLowerCase();
                const zone = ($row.data('zone') || '').toString().toLowerCase();
                const width = (parseInt($row.data('width') || 0)).toString();
                const length = (parseInt($row.data('length') || 0)).toString();
                const currentGlobalStand = $row.attr('data-current-stand'); // This is global

                if (filters['1'] && !address.includes(filters['1'])) visible = false;
                if (filters['2'] && !zone.includes(filters['2'])) visible = false;
                if (filters['3']) {
                    const widthFilter = $('#filter-width').val().trim();
                    const lengthFilter = $('#filter-length').val().trim();
                    if (widthFilter && width !== widthFilter) visible = false;
                    if (lengthFilter && length !== lengthFilter) visible = false;
                }
                if (filters['4']) { // Filter by stand (uses global stand ID from option value)
                    if (filters['4'] === 'unassigned') {
                        if (currentGlobalStand) visible = false;
                    } else {
                        if (!currentGlobalStand || currentGlobalStand !== filters['4']) {
                            visible = false;
                        }
                    }
                }
                $row.toggleClass('hidden-panel-row', !visible);
            });
            updateVisibleRowCount();
            updateSelectAllCheckbox();
        }

        function updateVisibleRowCount() {
            const visibleCount = $('#panels-table tbody tr:not(.hidden-panel-row)').length;
            const totalCount = $('#panels-table tbody tr').length;
            $('#visible-rows-count').text(`${visibleCount} / ${totalCount} نمایش`);
        }

        let currentSortColumn = null;
        let currentSortDirection = 'asc';

        function sortTable(column) {
            // ... (Sorting logic remains largely the same, ensure data attributes are correct) ...
            // The `data-current-stand` attribute will hold the global stand ID,
            // so sorting by 'assignment' will use global IDs.
            const $tbody = $('#panels-table tbody');
            const rows = $tbody.find('tr').toArray();

            if (currentSortColumn === column) {
                currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortDirection = 'asc';
                $('th.sortable').removeClass('sort-asc sort-desc');
            }
            currentSortColumn = column;

            const $header = $(`th.sortable[data-column="${column}"]`);
            $header.removeClass('sort-asc sort-desc').addClass(`sort-${currentSortDirection}`);

            rows.sort((a, b) => {
                let valA, valB;
                const $rowA = $(a);
                const $rowB = $(b);

                switch (column) {
                    case 'address':
                        valA = ($rowA.data('address') || '').toString();
                        valB = ($rowB.data('address') || '').toString();
                        break;
                    case 'zone':
                        valA = ($rowA.data('zone') || '').toString();
                        valB = ($rowB.data('zone') || '').toString();
                        break;
                    case 'dimensions':
                        valA = parseInt($rowA.data('width') || 0) * parseInt($rowA.data('length') || 0);
                        valB = parseInt($rowB.data('width') || 0) * parseInt($rowB.data('length') || 0);
                        break;
                    case 'assignment': // Uses global stand ID from data-current-stand
                        valA = parseInt($rowA.attr('data-current-stand') || '0');
                        valB = parseInt($rowB.attr('data-current-stand') || '0');
                        break;
                    default:
                        return 0;
                }
                let comparison = 0;
                if (typeof valA === 'string' && typeof valB === 'string') {
                    comparison = valA.localeCompare(valB, 'fa', {
                        numeric: true,
                        sensitivity: 'base'
                    });
                } else {
                    comparison = (valA < valB) ? -1 : (valA > valB) ? 1 : 0;
                }
                return currentSortDirection === 'asc' ? comparison : (comparison * -1);
            });
            $tbody.empty().append(rows);
        }

        function assignPanel(panelId, globalStandIdToAssign, rowElement) {
            if (isPageReadOnly || localStandsCount === 0) return false; // Added localStandsCount check
            const standIdInt = parseInt(globalStandIdToAssign);

            // Validate against the global range for THIS shipment
            if (isNaN(standIdInt) || standIdInt < minGlobalStandForShipment || standIdInt > maxGlobalStandForShipment) {
                alert(`شماره خرک نامعتبر است. لطفاً عددی بین ${minGlobalStandForShipment} و ${maxGlobalStandForShipment} وارد کنید.`);
                $(rowElement).find('.panel-assign-input').val('').focus();
                return false;
            }
            const panelIdStr = panelId.toString();
            const previousStand = panelAssignments[panelIdStr];
            if (previousStand === standIdInt) return true;

            panelAssignments[panelIdStr] = standIdInt;
            updateRowUI(rowElement, panelId, standIdInt);
            checkUnsavedChanges();
            return true;
        }

        function unassignPanel(panelId, rowElement) {
            if (isPageReadOnly) return;
            if (panelAssignments[panelId.toString()]) {
                delete panelAssignments[panelId.toString()];
                updateRowUI(rowElement, panelId, null);
                checkUnsavedChanges();
            }
        }

        function updateBulkAssignState() {
            if (isPageReadOnly || localStandsCount === 0) { // Added localStandsCount check
                $('#bulk-selected-count').text(0);
                if ($('#bulk-assign-button').length) $('#bulk-assign-button').prop('disabled', true);
                return;
            }
            const selectedCount = $('.panel-select-checkbox:checked:not(:disabled)').length;
            $('#bulk-selected-count').text(selectedCount);
            const standSelected = !!$('#bulk-assign-stand').val();
            $('#bulk-assign-button').prop('disabled', selectedCount === 0 || !standSelected);
            updateSelectAllCheckbox();
        }

        function updateSelectAllCheckbox() {
            if (isPageReadOnly) {
                $('#select-all-visible').prop({
                    checked: false,
                    indeterminate: false
                });
                return;
            }
            const $visibleCheckboxes = $('.panel-select-checkbox:not(:disabled):visible');
            const $visibleChecked = $visibleCheckboxes.filter(':checked');
            const allVisibleChecked = $visibleCheckboxes.length > 0 && $visibleCheckboxes.length === $visibleChecked.length;
            const someVisibleChecked = $visibleChecked.length > 0 && $visibleChecked.length < $visibleCheckboxes.length;
            $('#select-all-visible').prop({
                checked: allVisibleChecked,
                indeterminate: someVisibleChecked && !allVisibleChecked
            });
        }

        function saveAssignments() {
            if (isPageReadOnly) return;
            // ... (Save logic remains the same: it sends globalStandId in `assignments`)
            const $button = $('#save-assignments');
            const $status = $('#save-status');

            $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ذخیره...');
            $status.html('<div class="alert alert-info">در حال ارسال اطلاعات...</div>').show();

            const flatAssignments = [];
            const standPanelPositions = {}; // Keyed by global stand ID

            Object.keys(panelAssignments).forEach(panelIdStr => {
                const globalStandId = panelAssignments[panelIdStr]; // This is already global
                const panelIdInt = parseInt(panelIdStr);

                if (!standPanelPositions[globalStandId]) {
                    standPanelPositions[globalStandId] = 0;
                }
                standPanelPositions[globalStandId]++;
                const position = standPanelPositions[globalStandId];

                flatAssignments.push({
                    panel_id: panelIdInt,
                    stand_id: globalStandId, // Send global stand ID
                    position: position
                });
            });

            fetch('/api/save_stands_assignments.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        shipment_id: shipmentId,
                        truck_id: truckId,
                        assignments: flatAssignments
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(errData => {
                            throw new Error(errData.message || `Error: ${response.statusText}`);
                        }).catch(() => {
                            throw new Error(`Error: ${response.statusText}`);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        $status.html(`<div class="alert alert-success alert-dismissible fade show">تخصیص‌ها ذخیره شدند. (${data.count || 0}) <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`);
                        initialAssignments = JSON.parse(JSON.stringify(panelAssignments)); // Update baseline
                        hasUnsavedChanges = false;
                        changedStands.clear();
                        checkUnsavedChanges();
                        $button.removeClass('btn-warning').addClass('stand-badgeb');
                    } else {
                        $status.html(`<div class="alert alert-danger alert-dismissible fade show"><strong>خطا:</strong> ${data.message || 'Unknown error.'} <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`);
                    }
                })
                .catch(error => {
                    console.error('Save error:', error);
                    $status.html(`<div class="alert alert-danger alert-dismissible fade show"><strong>خطای ارتباط:</strong> ${error.message} <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`);
                })
                .finally(() => {
                    $button.prop('disabled', false).html('<i class="fa fa-save"></i> ذخیره تخصیص‌ها');
                    setTimeout(() => {
                        $status.fadeOut(500, () => $status.empty().show());
                    }, 7000);
                });
        }

        function setupEventListeners() {
            $('.panel-assignment-container').on('input change', '.panel-filter-input', filterTable);
            $('#clear-filters').on('click', function() {
                $('.panel-filter-input').val('');
                $('#filter-width, #filter-length').val('');
                filterTable();
                // initializeTable(); // Or just re-filter
            });
            $('th.sortable').on('click', function() {
                sortTable($(this).data('column'));
            });

            if (!isPageReadOnly) {
                const $tbody = $('#panels-table tbody');
                $tbody.on('click', '.panel-assign-btn', function() {
                    const $row = $(this).closest('tr');
                    const panelId = $row.data('panel-id');
                    const $input = $row.find('.panel-assign-input');
                    const globalStandId = $input.val();
                    if (assignPanel(panelId, globalStandId, $row[0])) {
                        $input.val('');
                    }
                });
                $tbody.on('keypress', '.panel-assign-input', function(e) {
                    if (e.which === 13) {
                        e.preventDefault();
                        const $row = $(this).closest('tr');
                        const panelId = $row.data('panel-id');
                        const globalStandId = $(this).val();
                        if (assignPanel(panelId, globalStandId, $row[0])) {
                            $(this).val('');
                        }
                    }
                });
                $tbody.on('click', '.panel-unassign-btn', function() {
                    const $row = $(this).closest('tr');
                    const panelId = $row.data('panel-id');
                    unassignPanel(panelId, $row[0]);
                });
                $tbody.on('change', '.panel-select-checkbox', updateBulkAssignState);
                $('#select-all-visible').on('change', function() {
                    $('.panel-select-checkbox:not(:disabled):visible').prop('checked', $(this).prop('checked'));
                    updateBulkAssignState();
                });
                $('#bulk-assign-stand').on('change', updateBulkAssignState); // Dropdown uses global stand ID
                $('#bulk-assign-button').on('click', function() {
                    const globalStandId = $('#bulk-assign-stand').val(); // This is global
                    if (!globalStandId) {
                        alert('لطفا یک خرک برای تخصیص انتخاب کنید.');
                        return;
                    }
                    const $selectedCheckboxes = $('.panel-select-checkbox:checked:not(:disabled):visible');
                    const count = $selectedCheckboxes.length;
                    if (count === 0) {
                        alert('هیچ پنلی انتخاب نشده است.');
                        return;
                    }
                    if (confirm(`آیا ${count} پنل انتخاب شده به خرک ${globalStandId} تخصیص داده شود؟`)) {
                        $selectedCheckboxes.each(function() {
                            const $row = $(this).closest('tr');
                            const panelId = $row.data('panel-id');
                            assignPanel(panelId, globalStandId, $row[0]);
                        });
                        $('#bulk-assign-stand').val('');
                        $('#select-all-visible').prop({
                            checked: false,
                            indeterminate: false
                        });
                        updateBulkAssignState();
                    }
                });
                $('#save-assignments').on('click', saveAssignments);
                $(window).on('beforeunload', function(e) {
                    if (hasUnsavedChanges) {
                        const msg = 'تغییرات ذخیره نشده وجود دارد. آیا مطمئن هستید؟';
                        e.returnValue = msg;
                        return msg;
                    }
                });
            }
        }
        // --- Run Initialization ---
        initializeTable();
    });
</script>

<?php require_once 'footer.php'; ?>