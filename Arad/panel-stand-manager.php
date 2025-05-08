<?php
require_once __DIR__ . '/../../sercon/config_fereshteh.php'; // Adjust path if needed
secureSession();
// --- ROLE DEFINITIONS (Adjust as needed for this specific page) ---
// Define who has FULL access to assign/unassign/save on THIS page
$write_access_roles = ['admin', 'superuser', 'planner']; // Example: Planners can assign
// Define who can ONLY view this page (no saving, no assigning)
$readonly_roles = ['user', 'supervisor', 'receiver']; // Example: Supervisors/Receivers can only view stand assignments

// Combine for initial access check (all roles that can VIEW the page)
$all_view_roles = array_merge($write_access_roles, $readonly_roles);

// --- AUTHORIZATION CHECK ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $all_view_roles)) {
    // Redirect or show error if user shouldn't even view the page
    // If you want anyone logged in to view, just check isset($_SESSION['user_id'])
    header('Location: /login'); // Or appropriate error page
    exit('Access Denied.');
}

// --- DETERMINE if current user has only READ access ---
$isPageReadOnly = in_array($_SESSION['role'], $readonly_roles);
// --- END ROLE HANDLING ---
$pageTitle = 'مدیریت تخصیص پنل به خرک'; // Set page title
require_once 'header.php'; // Assuming header exists

// Get truck_id from URL parameter
$truck_id = isset($_GET['truck_id']) ? (int)$_GET['truck_id'] : 0;

if ($truck_id <= 0) {
    echo '<div class="alert alert-danger">شناسه کامیون نامعتبر است.</div>';
    require_once 'footer.php'; // Assuming footer exists
    exit();
}

// --- Database Interaction ---
try {
    $pdo = connectDB(); // Assuming connectDB is defined

    // Get Shipment Info (Necessary for saving)
    $stmt_shipment = $pdo->prepare("SELECT id, truck_id, stands_sent, stands_returned, status, packing_list_number FROM shipments WHERE truck_id = ? LIMIT 1");
    $stmt_shipment->execute([$truck_id]);
    $shipment = $stmt_shipment->fetch(PDO::FETCH_ASSOC);

    if (!$shipment) {
        echo '<div class="alert alert-danger">هیچ محموله‌ای برای این کامیون یافت نشد.</div>';
        require_once 'footer.php';
        exit();
    }
    $shipment_id = $shipment['id'];
    $stands_count = $shipment['stands_sent'] ?? 0;
    $packing_list_number = $shipment['packing_list_number'] ?? 'نامشخص';

    // Get All Panels for this truck
    $stmt_panels = $pdo->prepare("
        SELECT id, address, truck_id, width, length, Proritization, type, status
        FROM hpc_panels
        WHERE truck_id = ?
        ORDER BY Proritization ASC, address ASC
    ");
    $stmt_panels->execute([$truck_id]);
    $panels = $stmt_panels->fetchAll(PDO::FETCH_ASSOC);

    // Get Current Assignments
    $stmt_assignments = $pdo->prepare("
        SELECT psa.panel_id, psa.stand_id
        FROM panel_stand_assignments psa
        WHERE psa.truck_id = ? AND psa.shipment_id = ?
    ");
    $stmt_assignments->execute([$truck_id, $shipment_id]);
    $assignments_raw = $stmt_assignments->fetchAll(PDO::FETCH_KEY_PAIR); // Get as [panel_id => stand_id]

} catch (PDOException $e) {
    // Proper error logging is crucial here
    error_log("Database error in panel assignment table: " . $e->getMessage());
    echo '<div class="alert alert-danger">خطای پایگاه داده. لطفاً با مدیر سیستم تماس بگیرید.</div>';
    require_once 'footer.php';
    exit();
}

// Prepare data for JavaScript (initial assignments)
$initial_assignments_json = json_encode($assignments_raw);

?>
<link href="/assets/css/bootstrap.rtl.min.css" rel="stylesheet">
<!-- Applied UI changes to HTML structure and classes -->
<div class="panel-assignment-container container mt-4" dir="rtl">
    <h2 class="panel-assign-title">مدیریت تخصیص پنل به خرک (جدول)</h2>
    <div class="panel-assign-info alert alert-info d-flex align-items-center justify-content-between">
        <span>

            <strong>شماره پکینگ لیست:</strong> <?= htmlspecialchars($packing_list_number) ?> |
            <strong>تعداد کل خرک‌ها:</strong> <?= htmlspecialchars($stands_count) ?>
        </span>
        <?php // --- Conditionally show Save Button --- 
        ?>
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
                    <select class="form-select form-select-sm panel-filter-input" id="filter-stand" data-column="4">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="unassigned">تخصیص نیافته</option>
                        <?php for ($i = 1; $i <= $stands_count; $i++): ?>
                            <option value="<?= $i ?>">خرک <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
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
    <?php if (!$isPageReadOnly): ?>
        <div class="card panel-bulk-card mb-3">
            <div class="card-body py-2 bg-light">
                <div class="row g-2 align-items-center">
                    <div class="col-auto">
                        <label for="bulk-assign-stand" class="col-form-label col-form-label-sm">تخصیص <strong id="bulk-selected-count">0</strong> پنل انتخاب شده به:</label>
                    </div>
                    <div class="col-auto">
                        <select class="form-select form-select-sm panel-bulk-select" id="bulk-assign-stand">
                            <option value="">انتخاب خرک...</option>
                            <?php for ($i = 1; $i <= $stands_count; $i++): ?>
                                <option value="<?= $i ?>">خرک <?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-success btn-sm px-3" id="bulk-assign-button" disabled>
                            <i class="fa fa-check"></i> تخصیص
                        </button>
                    </div>
                </div>
                <!-- Placeholder for Stand Counts -->
                <div id="stand-counts-display" class="mt-3 d-flex flex-wrap gap-2 justify-content-center">
                    <!-- Counts will be populated by JS -->
                </div>
            </div>
        </div>
    <?php endif; ?>


    <!-- Panels Table -->
    <div class="panel-table-container mb-4">
        <table class="table table-bordered table-striped table-hover table-sm panel-assign-table" id="panels-table">
            <thead class="thead-light panel-table-header">
                <tr>
                    <?php // --- Conditionally show Checkbox column header --- 
                    ?>
                    <?php if (!$isPageReadOnly): ?>
                        <th style="width: 5%;"><input type="checkbox" id="select-all-visible" title="انتخاب همه قابل مشاهده"></th>
                    <?php else: ?>
                        <th style="width: 5%;"><!-- Empty Header for Read Only --></th>
                    <?php endif; ?>
                    <th class="sortable" data-column="address" style="width: 25%;">آدرس <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-column="zone" style="width: 10%;">زون <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-column="dimensions" style="width: 17%;">ابعاد (WxL) <i class="fa fa-sort"></i></th>
                    <th class="sortable" data-column="assignment" style="width: 17%;">وضعیت / تخصیص به خرک <i class="fa fa-sort"></i></th>
                    <?php // --- Conditionally show Action column header --- 
                    ?>
                    <?php if (!$isPageReadOnly): ?>
                        <th style="width: 15%;">عملیات</th>
                    <?php else: ?>
                        <!-- Optional: Adjust column width percentages if hiding actions -->
                        <th style="width: 15%;"><!-- Empty Header --></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($panels)): ?>
                    <tr>
                        <td colspan="6" class="text-center">هیچ پنلی برای این کامیون یافت نشد.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($panels as $panel):
                        $panel_id = $panel['id'];
                        $assigned_stand = $assignments_raw[$panel_id] ?? null; // Check if assigned
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
                            <?php // --- Conditionally show Checkbox --- 
                            ?>
                            <?php if (!$isPageReadOnly): ?>
                                <td class="text-center align-middle">
                                    <input type="checkbox" class="panel-select-checkbox" value="<?= htmlspecialchars($panel_id) ?>" <?= $is_assigned ? 'disabled' : '' ?>>
                                </td>
                            <?php else: ?>
                                <td class="text-center align-middle"><!-- Empty Cell --></td>
                            <?php endif; ?>
                            <td class="align-middle"><?= htmlspecialchars($panel['address']) ?></td>
                            <td class="align-middle text-center"><?= htmlspecialchars($panel['Proritization']) ?></td>
                            <td class="align-middle text-center"><?= htmlspecialchars(intval($panel['width'])) ?> × <?= htmlspecialchars(intval($panel['length'])) ?></td>
                            <td class="assignment-cell text-center align-middle">
                                <?php if ($is_assigned): ?>
                                    <!-- Display assigned state -->
                                    <span class="badge stand-badge" data-stand-id="<?= htmlspecialchars($assigned_stand) ?>">
                                        خرک <?= htmlspecialchars($assigned_stand) ?>
                                    </span>
                                <?php else: ?>
                                    <!-- Display unassigned state -->
                                    <span class="badge bg-secondary">تخصیص نیافته</span>
                                <?php endif; ?>
                            </td>
                            <?php // --- Conditionally show Action Controls --- 
                            ?>
                            <?php if (!$isPageReadOnly): ?>
                                <td class="action-cell text-center align-middle">
                                    <?php if ($is_assigned): ?>
                                        <button class="btn btn-danger btn-sm panel-unassign-btn" title="لغو تخصیص">
                                            <i class="fa fa-times"></i>
                                        </button>
                                    <?php else: ?>
                                        <div class="input-group input-group-sm panel-assign-input-group">
                                            <input type="number" class="form-control panel-assign-input" placeholder="#" min="1" max="<?= $stands_count ?>" style="width: 40px;">
                                            <button class="panel-assign-btn" type="button" title="تخصیص">
                                                <i class="fa fa-check"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php else: ?>
                                <td class="action-cell text-center align-middle"><!-- Empty Action Cell --></td>
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
    const isPageReadOnly = <?= json_encode($isPageReadOnly); ?>;
    // Pass other necessary variables as before
    const truckId = <?= json_encode($truck_id) ?>;
    const shipmentId = <?= json_encode($shipment_id) ?>;
    const totalStands = <?= json_encode($stands_count) ?>;
    const initialAssignments = JSON.parse(JSON.stringify(<?= $initial_assignments_json ?> || {}));
</script>
<!-- Applied new CSS styles -->
<style>
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

    .stand-badgeb:hover {
        background-color: #e2e6ea;
        color: #212529;
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

    /* --- START: Updated Unassign Button Styles --- */
    /* Replace the previous .panel-unassign-btn rule */
    .panel-assign-table td .panel-unassign-btn {
        /* ... (Your previous working styles for the 'X' button) ... */
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        padding: 0 !important;
        margin: 0;
        width: 24px;
        /* Or previous size */
        height: 24px;
        /* Or previous size */
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
        /* Force flex grow and allow shrinking, base size auto */
        flex: 1 1 auto !important;
        /* REMOVE any explicit 'width' property */
        /* Set a minimum width so it doesn't collapse entirely */
        min-width: 60px;
        /* Increased min-width */

        /* Keep other essential styles */
        text-align: center;
        padding: 0.25rem 0.5rem;
        /* Consistent padding */
        font-size: 0.8rem;
        /* Slightly larger font */
        height: auto;
        /* Let height be determined by content/padding/border */
        line-height: 1.5;
        /* Standard line height */

        /* Ensure Bootstrap overrides don't break layout */
        position: relative;
        /* Keep this from .form-control */
        z-index: 2;
        /* Match Bootstrap's z-index for input-group */
    }

    .panel-assign-table .panel-assign-input-group .panel-assign-btn {
        /* Prevent button from growing/shrinking */
        flex: 0 0 auto !important;
        /* Ensure button aligns vertically if needed */
        display: inline-flex;
        align-items: center;
        justify-content: center;

        /* Adjust padding and font for size */
        padding: 0.25rem 0.6rem;
        /* Adjust padding */
        font-size: 0.8rem;
        /* Match input font size */
        line-height: 1;
        /* Keep icon centered */
        height: auto;
        /* Match input height setting */

        /* Override potential Bootstrap input-group button styling */
        position: relative;
        z-index: 2;
    }

    /* Ensure icon inside is visible */
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

    /* --- END: Updated Unassign Button Styles --- */

    .panel-assign-input-group {
        flex-wrap: nowrap;
        width: 100%;
        /* Increase max-width slightly more */
        max-width: 150px;
        margin: 0 auto;
        display: flex !important;
        /* Ensure flex display override */
        align-items: stretch;
        /* Make items fill height */
    }

    .panel-assign-input {
        /* Allow input to grow and shrink, taking available space */
        flex: 1 1 auto !important;
        /* Suggest a base width, flex might override */
        width: 60px !important;
        /* Let's try 60px as a base */
        /* Prevent input from becoming too small */
        min-width: 50px;

        /* Keep other essential styles */
        text-align: center;
        padding: 0.25rem;
        /* Adjust padding if needed */
        font-size: 0.75rem;
        /* Make font slightly smaller */
        height: calc(1.5em + 0.5rem + 2px);
        /* Match button height */
        /* Removed fixed width: 40px; */
        /* Removed flex-grow: 0 */
    }


    .panel-assign-btn {
        /* Prevent button from growing/shrinking */
        flex: 0 0 auto !important;

        /* Keep other essential styles */
        padding: 0.25rem 0.5rem;
        /* Adjust padding */
        font-size: 0.75rem;
        /* Match input font size */
        line-height: 1;
        /* Ensure icon is vertically centered */
    }

    /* More compact filter form */
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
        /* Align label better */
    }


    /* Stand colors with better contrast - Kept from New UI code */
    .stand-color-1 {
        background-color: #0066cc;
    }

    /* Darker blue */
    .stand-color-2 {
        background-color: #1a8754;
    }

    /* Darker green */
    .stand-color-3 {
        background-color: #dc3545;
    }

    /* Red */
    .stand-color-4 {
        background-color: #fd7e14;
    }

    /* Orange */
    .stand-color-5 {
        background-color: #0dcaf0;
    }

    /* Cyan */
    .stand-color-6 {
        background-color: #6f42c1;
    }

    /* Purple */
    .stand-color-7 {
        background-color: #d63384;
    }

    /* Pink */
    .stand-color-8 {
        background-color: #20c997;
    }

    /* Teal */
    .stand-color-9 {
        background-color: #343a40;
    }

    /* Dark */
    .stand-color-10 {
        background-color: #6c757d;
    }

    /* Styling for the stand count display */
    #stand-counts-display {
        font-size: 0.8rem;
        padding-top: 0.5rem;
        border-top: 1px solid #eee;
        /* Optional separator */

    }

    .stand-count-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.3em 0.6em;
        border-radius: 4px;
        font-weight: 500;
        min-width: 80px;
        /* Ensure consistent width */
        text-align: center;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);

    }

    .stand-count-badge .count {
        margin-right: 5px;
        /* Space between count and text */
        font-weight: bold;
        background-color: rgba(255, 255, 255, 0.2);
        /* Slightly highlight count */
        padding: 0.1em 0.4em;
        border-radius: 3px;
        min-width: 1.5em;
        /* Ensure count has some space */
        display: inline-block;
        text-align: center;

    }

    /* Gray */

    /* Styling for the stand count display */
    #stand-counts-display {
        font-size: 0.8rem;
        padding-top: 0.5rem;
        border-top: 1px solid #eee;
        /* Optional separator */

    }

    .stand-count-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.3em 0.6em;
        border-radius: 4px;
        font-weight: 500;
        min-width: 80px;
        /* Ensure consistent width */
        text-align: center;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);

    }

    .stand-count-badge .count {
        margin-right: 5px;
        /* Space between count and text */
        font-weight: bold;
        background-color: rgba(255, 255, 255, 0.2);
        /* Slightly highlight count */
        padding: 0.1em 0.4em;
        border-radius: 3px;
        min-width: 1.5em;
        /* Ensure count has some space */
        display: inline-block;
        text-align: center;

    }

    /* Sorting icons */
    th.sortable {
        cursor: pointer;
        position: relative;
        /* Needed for absolute positioning of icon */
    }

    th.sortable i {
        /* margin-right: 5px; Use absolute positioning instead */
        opacity: 0.4;
        position: absolute;
        left: 10px;
        /* Position icon to the left in RTL */
        top: 50%;
        transform: translateY(-50%);
    }

    th.sortable.sort-asc i,
    th.sortable.sort-desc i {
        opacity: 1;
    }

    th.sortable.sort-asc i::before {
        content: "\f0de";
        /* fa-sort-up */
    }

    th.sortable.sort-desc i::before {
        content: "\f0dd";
        /* fa-sort-down */
    }

    /* Hide elements smoothly */
    tr.hidden-panel-row {
        display: none;
    }

    /* Panel count info */
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

    /* Hide elements completely if needed */
    body.page-is-read-only .panel-bulk-card,
    body.page-is-read-only #save-assignments,
    body.page-is-read-only .action-cell>*,
    /* Hide direct children of action cell */
    body.page-is-read-only .panel-select-checkbox,
    /* Hide checkboxes */
    body.page-is-read-only #select-all-visible

    /* Hide select all checkbox */
        {
        display: none !important;
        /* Use hiding via PHP first */
    }


    /* Responsive adjustments */
    @media (max-width: 992px) {
        .panel-assign-table {
            font-size: 0.8rem;
        }

        .panel-assign-input {
            font-size: 0.7rem;
            /* Adjust width slightly for smaller screens if needed */
            /* width: 55px !important; */
        }

        /* NOTE: The panel-unassign-btn style was incorrect here before. */
        /* Let's keep the Assign button padding reasonable */
        .panel-assign-btn {
            padding: 0.2rem 0.4rem;
            /* Slightly smaller padding */
            font-size: 0.7rem;
        }

        /* The unassign button size is controlled by its width/height/font-size */
        /* .panel-unassign-btn rule above controls its size */

        .stand-badge {
            font-size: 0.75em;
        }
    }

    @media (max-width: 768px) {
        .panel-assign-table .panel-assign-input-group .panel-assign-input {
            font-size: 0.75rem;
            min-width: 50px;
            /* Adjust min-width */
        }

        .panel-assign-table .panel-assign-input-group .panel-assign-btn {
            padding: 0.2rem 0.4rem;
            /* Slightly smaller padding */
            font-size: 0.75rem;
        }

        .panel-assign-input-group {
            max-width: 130px;
            /* Make group slightly smaller on mobile */
        }
    }
</style>

<!-- Updated JS with new class names and color array -->
<script>
    $(document).ready(function() {
        // --- Read-Only Check ---
        if (isPageReadOnly) {
            console.log("Page is in Read-Only Mode.");
            // Add a class to body for easier CSS targeting (optional but recommended)
            $('body').addClass('page-is-read-only');

            // Explicitly disable any remaining interactive elements just in case PHP didn't hide them
            $('#save-assignments').prop('disabled', true).hide(); // Hide if not already hidden by PHP
            $('.panel-bulk-card').hide(); // Hide if not already hidden by PHP
            $('.panel-select-checkbox, #select-all-visible').prop('disabled', true);
            $('.action-cell').empty(); // Remove content from action cells

            // Optionally disable filter inputs too, though request was to keep them enabled
            // $('.panel-filter-input').prop('disabled', true);
        }
        // --- Configuration ---
        const truckId = <?= json_encode($truck_id) ?>;
        const shipmentId = <?= json_encode($shipment_id) ?>;
        const totalStands = <?= json_encode($stands_count) ?>;
        const initialAssignments = JSON.parse(JSON.stringify(<?= $initial_assignments_json ?> || {}));
        let panelAssignments = JSON.parse(JSON.stringify(initialAssignments)); // Start with a copy of initial
        let hasUnsavedChanges = false;
        let changedStands = new Set(); // Keep track of stands with changes

        // Updated standColors array from New UI code
        const standColors = [ // Array of color HEX codes (match CSS for logic)
            '#0066cc', '#1a8754', '#dc3545', '#fd7e14', '#0dcaf0',
            '#6f42c1', '#d63384', '#20c997', '#343a40', '#6c757d'
        ];

        // --- Helper Functions ---
        function getStandColorClass(standId) {
            if (!standId) return '';
            const colorIndex = (parseInt(standId) - 1) % standColors.length;
            // Return the CSS class name convention matching the NEW CSS
            return `stand-color-${(colorIndex + 1)}`; // e.g., stand-color-1
        }

        function getStandHexColor(standId) {
            if (!standId) return '#f8f9fa'; // Default light grey
            const colorIndex = (parseInt(standId) - 1) % standColors.length;
            return standColors[colorIndex];
        }

        function checkUnsavedChanges() {
            if (isPageReadOnly) { // <<< ADDED Check
                hasUnsavedChanges = false;
                $('#save-assignments').removeClass('btn-warning').addClass('stand-badgeb'); // Ensure save button looks normal (though hidden/disabled)
                return;
            }
            hasUnsavedChanges = false; // Assume no changes initially
            changedStands.clear(); // Reset the set of changed stands

            // Get all panel IDs currently in the table (or could use keys from initial/current assignments)
            const allPanelIds = new Set([
                ...Object.keys(initialAssignments),
                ...Object.keys(panelAssignments)
            ]);

            allPanelIds.forEach(panelIdStr => {
                const initialStand = initialAssignments[panelIdStr];
                const currentStand = panelAssignments[panelIdStr];

                if (initialStand !== currentStand) {
                    hasUnsavedChanges = true;
                    // Mark both the old stand (if any) and the new stand (if any) as changed
                    if (initialStand) changedStands.add(initialStand.toString());
                    if (currentStand) changedStands.add(currentStand.toString());
                }
            });

            // Update UI elements based on the flag
            updateBulkDropdownIndicators();
            // Optionally, visually indicate the save button needs attention
            $('#save-assignments').toggleClass('btn-warning', hasUnsavedChanges).toggleClass('stand-badgeb', !hasUnsavedChanges); // Example: change color
        }
        // --- NEW: Function to update indicators in the bulk dropdown ---
        function updateBulkDropdownIndicators() {
            if (isPageReadOnly) return;
            const $bulkSelect = $('#bulk-assign-stand');
            $bulkSelect.find('option').each(function() {
                const $option = $(this);
                const standId = $option.val();
                let originalText = $option.data('original-text'); // Store original text

                if (!standId) return; // Skip the placeholder option

                // Store original text if not already stored
                if (originalText === undefined) {
                    originalText = $option.text();
                    $option.data('original-text', originalText);
                }

                // Check if this stand has pending changes
                if (changedStands.has(standId)) {
                    // Add indicator only if not already present
                    if (!originalText.endsWith(' *')) {
                        $option.text(originalText + ' *');
                    }
                } else {
                    // Remove indicator by restoring original text
                    $option.text(originalText);
                }
            });
        }
        // Function to update the UI of a single row based on assignment
        // Updated HTML strings to match the new UI structure and classes
        function updateRowUI(rowElement, panelId, assignedStandId) {
            const $row = $(rowElement);
            const $assignCell = $row.find('.assignment-cell');
            const $actionCell = $row.find('.action-cell');
            const $checkbox = $row.find('.panel-select-checkbox');

            $row.attr('data-current-stand', assignedStandId ? assignedStandId : '');

            if (assignedStandId) {
                // --- Assigned State ---
                const colorClass = getStandColorClass(assignedStandId);
                const hexColor = getStandHexColor(assignedStandId);
                const textColor = isLight(hexColor) ? '#333' : '#fff';

                // Updated badge HTML with correct classes/style
                const badgeHtml = `
                <span class="badge stand-badge ${colorClass}" data-stand-id="${assignedStandId}" style="background-color: ${hexColor}; color: ${textColor};">
                    خرک ${assignedStandId}
                </span>`;

                // Updated Action Button HTML with correct classes
                const actionHtml = `
                 <button class="btn btn-danger btn-sm panel-unassign-btn" title="لغو تخصیص">
                    <i class="fa fa-times"></i>
                </button>`;

                $assignCell.html(badgeHtml);
                $actionCell.html(actionHtml);

                // Use the new class name for assigned rows
                $row.removeClass('assigned-panel-row').addClass('assigned-panel-row'); // Ensure only one class

                $checkbox.prop('checked', false).prop('disabled', true);

            } else {
                const statusHtml = `<span class="badge bg-secondary">تخصیص نیافته</span>`;
                $assignCell.html(statusHtml);
                $row.removeClass('assigned-panel-row');
                $row.css('background-color', ''); // Reset background
            }

            // ---- Action Cell and Checkbox (Read-Only Handling) ----
            if (isPageReadOnly) {
                $actionCell.html(''); // Ensure action cell is empty
                $checkbox.prop('disabled', true).hide(); // Disable and hide checkbox
            } else {
                // --- Logic for NON-Read-Only ---
                if (assignedStandId) {
                    // Show Unassign Button
                    const actionHtml = `
                         <button class="btn btn-danger btn-sm panel-unassign-btn" title="لغو تخصیص">
                             <i class="fa fa-times"></i>
                         </button>`;
                    $actionCell.html(actionHtml);
                    $checkbox.prop('checked', false).prop('disabled', true); // Cannot bulk-assign an already assigned panel
                } else {
                    // Show Assign Input Group
                    const assignInputHtml = `
                         <div class="input-group input-group-sm panel-assign-input-group">
                             <input type="number" class="form-control panel-assign-input" placeholder="#" min="1" max="${totalStands}">
                             <button class="panel-assign-btn" type="button" title="تخصیص">
                                 <i class="fa fa-check"></i>
                             </button>
                         </div>`;
                    $actionCell.html(assignInputHtml);
                    $checkbox.prop('disabled', false); // Enable checkbox for unassigned panels
                }
                updateBulkAssignState(); // Update bulk controls only if not read-only
            }

            // Update counts regardless of read-only status
            updateStandCountsDisplay();
        }

        // Basic contrast check (remains the same)
        function isLight(hexColor) {
            if (!hexColor || hexColor.length < 4) return true; // Default to light if invalid
            const color = hexColor.charAt(0) === '#' ? hexColor.substring(1, 7) : hexColor;
            // Handle short hex codes (#RGB)
            let r, g, b;
            if (color.length === 3) {
                r = parseInt(color.substring(0, 1) + color.substring(0, 1), 16);
                g = parseInt(color.substring(1, 2) + color.substring(1, 2), 16);
                b = parseInt(color.substring(2, 3) + color.substring(2, 3), 16);
            } else if (color.length === 6) {
                r = parseInt(color.substring(0, 2), 16);
                g = parseInt(color.substring(2, 4), 16);
                b = parseInt(color.substring(4, 6), 16);
            } else {
                return L > 0.179; // Threshold // Default to light if format is wrong
            }

            const uicolors = [r / 255, g / 255, b / 255];
            const c = uicolors.map((col) => {
                if (col <= 0.03928) {
                    return col / 12.92;
                }
                return Math.pow((col + 0.055) / 1.055, 2.4);
            });
            const L = (0.2126 * c[0]) + (0.7152 * c[1]) + (0.0722 * c[2]);
            return L > 0.179; // Threshold
        }
        // --- NEW: Function to Update Stand Counts Display ---

        function updateStandCountsDisplay() {

            const $displayArea = $('#stand-counts-display');
            if (!$displayArea.length) return; // Exit if placeholder doesn't exist


            const standCounts = {}; // { standId: count }

            // Calculate counts from current assignments
            Object.values(panelAssignments).forEach(standId => {
                standCounts[standId] = (standCounts[standId] || 0) + 1;
            });

            let displayHtml = '';
            for (let i = 1; i <= totalStands; i++) {

                const count = standCounts[i] || 0;
                const hexColor = getStandHexColor(i);
                const textColor = isLight(hexColor) ? '#333' : '#fff';
                // Build the badge HTML for this stand
                displayHtml += `
                   <span class="stand-count-badge" style="background-color: ${hexColor}; color: ${textColor};" title="خرک ${i}: ${count} پنل">
                    خرک ${i}:
                       <span class="count">${count}</span>
                      
                   </span>`;
            }
            $displayArea.html(displayHtml || '<span class="text-muted">هنوز پنلی تخصیص داده نشده است.</span>'); // Show message if empty

        }
        // --- Initialization ---
        function initializeTable() {
            $('#panels-table tbody tr').each(function() {
                const $row = $(this);
                const panelId = $row.data('panel-id');
                // panelAssignments uses string keys from PHP's json_encode of FETCH_KEY_PAIR
                const assignedStand = panelAssignments[panelId.toString()];
                if (assignedStand) {
                    updateRowUI(this, panelId, assignedStand);
                } else {
                    updateRowUI(this, panelId, null); // Ensure unassigned state is correct
                }
            });
            updateVisibleRowCount();
            updateStandCountsDisplay();
            checkUnsavedChanges();
            setupEventListeners();
        }

        // --- Filtering Logic ---
        // Updated selectors and class name for hidden rows
        function filterTable() {
            const filters = {};
            // Use the new filter input class
            $('.panel-filter-input').each(function() {
                const $input = $(this);
                const column = $input.data('column');
                const value = $input.val().trim().toLowerCase();
                if (value) {
                    filters[column] = value;
                }
            });

            $('#panels-table tbody tr').each(function() {
                const $row = $(this);
                let visible = true;

                const address = ($row.data('address') || '').toString().toLowerCase();
                const zone = ($row.data('zone') || '').toString().toLowerCase();
                const width = (parseInt($row.data('width') || 0)).toString();
                const length = (parseInt($row.data('length') || 0)).toString();
                const currentStand = $row.attr('data-current-stand');

                // Apply filters
                if (filters['1'] && !address.includes(filters['1'])) visible = false;
                if (filters['2'] && !zone.includes(filters['2'])) visible = false;

                if (filters['3']) {
                    const widthFilter = $('#filter-width').val().trim();
                    const lengthFilter = $('#filter-length').val().trim();
                    if (widthFilter && width !== widthFilter) visible = false;
                    if (lengthFilter && length !== lengthFilter) visible = false;
                }

                if (filters['4']) {
                    if (filters['4'] === 'unassigned') {
                        if (currentStand) visible = false;
                    } else {
                        if (!currentStand || currentStand !== filters['4']) {
                            visible = false;
                        }
                    }
                }

                // Use the new hidden row class name
                $row.toggleClass('hidden-panel-row', !visible);
            });

            updateVisibleRowCount();
            updateSelectAllCheckbox();
        }

        function updateVisibleRowCount() {
            // Use the new hidden row class name
            const visibleCount = $('#panels-table tbody tr:not(.hidden-panel-row)').length;
            const totalCount = $('#panels-table tbody tr').length;
            $('#visible-rows-count').text(`${visibleCount} / ${totalCount} نمایش`);
        }

        // --- Sorting Logic (remains the same conceptually) ---
        let currentSortColumn = null;
        let currentSortDirection = 'asc'; // 'asc' or 'desc'

        function sortTable(column) {
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
                    case 'assignment':
                        valA = parseInt($rowA.attr('data-current-stand') || '0');
                        valB = parseInt($rowB.attr('data-current-stand') || '0');
                        break;
                    default:
                        return 0;
                }

                let comparison = 0;
                if (typeof valA === 'string' && typeof valB === 'string') {
                    // Natural sort for strings containing numbers (like addresses)
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


        // --- Assignment/Unassignment Logic (remains the same conceptually) ---
        function assignPanel(panelId, standId, rowElement) {
            if (isPageReadOnly) return false;
            const standIdInt = parseInt(standId);
            if (isNaN(standIdInt) || standIdInt < 1 || standIdInt > totalStands) {
                alert('شماره خرک نامعتبر است. لطفاً عددی بین 1 و ' + totalStands + ' وارد کنید.');
                // Optionally clear the input field
                $(rowElement).find('.panel-assign-input').val('').focus();
                return false; // Indicate failure
            }
            // Ensure panelId is stored as string key, consistent with PHP fetch
            const panelIdStr = panelId.toString();
            const previousStand = panelAssignments[panelIdStr]; // Get previous value before changing

            // Only proceed if the assignment is actually changing
            if (previousStand === standIdInt) {
                console.log(`Panel ${panelIdStr} already assigned to stand ${standIdInt}. No change.`);
                return true; // No actual change, but technically not an error
            }

            panelAssignments[panelIdStr] = standIdInt;
            updateRowUI(rowElement, panelId, standIdInt);
            checkUnsavedChanges(); // <-- Check for changes AFTER modification
            return true;
        }

        function unassignPanel(panelId, rowElement) {
            if (isPageReadOnly) return;
            // Ensure panelId is used as string key
            if (panelAssignments[panelId.toString()]) {
                delete panelAssignments[panelId.toString()];
                updateRowUI(rowElement, panelId, null);
                // updateStandCountsDisplay(); // Called within updateRowUI now
                checkUnsavedChanges(); // <-- Check for changes AFTER modification
            }
        }

        // --- Bulk Actions ---
        function updateBulkAssignState() {
            if (isPageReadOnly) { // <<< ADDED Check
                $('#bulk-selected-count').text(0);
                $('#bulk-assign-button').prop('disabled', true);
                return;
            }
            const selectedCount = $('.panel-select-checkbox:checked:not(:disabled)').length;
            $('#bulk-selected-count').text(selectedCount);
            // Check both count and dropdown selection
            const standSelected = !!$('#bulk-assign-stand').val(); // Check if a stand is selected
            $('#bulk-assign-button').prop('disabled', selectedCount === 0 || !standSelected);
            updateSelectAllCheckbox();
        }

        function updateSelectAllCheckbox() {
            if (isPageReadOnly) { // <<< ADDED Check
                $('#select-all-visible').prop({
                    checked: false,
                    indeterminate: false
                });
                return;
            }
            // Select only visible and enabled checkboxes
            const $visibleCheckboxes = $('.panel-select-checkbox:not(:disabled):visible');
            const $visibleChecked = $visibleCheckboxes.filter(':checked');
            const allVisibleChecked = $visibleCheckboxes.length > 0 && $visibleCheckboxes.length === $visibleChecked.length;
            const someVisibleChecked = $visibleChecked.length > 0 && $visibleChecked.length < $visibleCheckboxes.length;

            $('#select-all-visible').prop({
                checked: allVisibleChecked,
                indeterminate: someVisibleChecked && !allVisibleChecked // Only indeterminate if not all are checked
            });
        }


        // --- Saving Logic (remains the same, uses variables defined above) ---
        function saveAssignments() {
            if (isPageReadOnly) return;
            const $button = $('#save-assignments');
            const $status = $('#save-status');

            $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ذخیره...');
            $status.html('<div class="alert alert-info">در حال ارسال اطلاعات به سرور...</div>').show();

            // Prepare flat assignment data with positions
            const flatAssignments = [];
            const standPanelPositions = {};

            // panelAssignments uses string keys { "panelIdString": standIdInt }
            Object.keys(panelAssignments).forEach(panelIdStr => {
                const standId = panelAssignments[panelIdStr];
                const panelIdInt = parseInt(panelIdStr); // Convert key back to integer for saving

                if (standPanelPositions[standId] === undefined) {
                    standPanelPositions[standId] = 0;
                }
                standPanelPositions[standId]++;
                const position = standPanelPositions[standId];

                flatAssignments.push({
                    panel_id: panelIdInt,
                    stand_id: standId, // Keep as integer
                    position: position
                });
            });

            // console.log("Data to save:", flatAssignments); // Debug

            fetch('/api/save_stands_assignments.php', { // MAKE SURE THIS PATH IS CORRECT
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
                        // Try to parse JSON error first
                        return response.json().then(errData => {
                            throw new Error(errData.message || `خطای سرور: ${response.statusText} (${response.status})`);
                        }).catch(() => {
                            // If JSON parsing fails, throw generic error
                            throw new Error(`خطای سرور: ${response.statusText} (${response.status})`);
                        });
                    }
                    return response.json(); // Parse successful JSON response
                })
                .then(data => {
                    if (data.success) {
                        $status.html(`<div class="alert alert-success alert-dismissible fade show" role="alert">
                            تخصیص‌ها با موفقیت ذخیره شدند. (${data.count || 0} مورد)
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>`);
                        // Optionally: Mark rows as 'saved' state if needed, though usually not necessary
                        Object.assign(initialAssignments, panelAssignments); // Update initial state
                        // Or for a truly clean slate, create a new deep copy:
                        // initialAssignments = JSON.parse(JSON.stringify(panelAssignments));

                        hasUnsavedChanges = false; // Reset flag
                        changedStands.clear(); // Clear changed stands
                        checkUnsavedChanges(); // Re-check (should now be false) and update UI
                        // Optionally remove warning class from save button explicitly
                        $button.removeClass('btn-warning').addClass('stand-badgeb');
                    } else {
                        $status.html(`<div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <strong>خطا در ذخیره‌سازی:</strong> ${data.message || 'خطای ناشناخته از سرور.'}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>`);
                    }
                })
                .catch(error => {
                    console.error('Error saving assignments:', error);
                    $status.html(`<div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>خطا در ارتباط با سرور:</strong> ${error.message}. لطفاً دوباره تلاش کنید.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>`);
                })
                .finally(() => {
                    // Re-enable button regardless of success/failure
                    $button.prop('disabled', false).html('<i class="fa fa-save"></i> ذخیره تخصیص‌ها');
                    // Status message will auto-dismiss or can be manually closed
                    // Remove the automatic fadeOut to allow users to read errors
                    setTimeout(() => {
                        $status.fadeOut(500, () => $status.empty().show());
                    }, 7000);
                });
        }


        // --- Event Listeners Setup (using delegation and updated selectors) ---
        function setupEventListeners() {
            // Filters - Use the new filter class
            $('.panel-assignment-container').on('input change', '.panel-filter-input', filterTable);
            $('#clear-filters').on('click', function() {
                $('.panel-filter-input').val('');
                // Clear width/length specifically if needed by filter logic
                $('#filter-width').val('');
                $('#filter-length').val('');
                filterTable(); // Re-apply filters (shows all)
                // Optional: Reset sorting
                $('th.sortable').removeClass('sort-asc sort-desc');
                currentSortColumn = null;
                initializeTable(); // Or re-sort to default if needed
            });

            // Sorting
            $('th.sortable').on('click', function() {
                sortTable($(this).data('column'));
            });
            if (!isPageReadOnly) {
                const $tbody = $('#panels-table tbody');

                // Single Assign Button - Use the new button class
                $tbody.on('click', '.panel-assign-btn', function() {
                    const $row = $(this).closest('tr');
                    const panelId = $row.data('panel-id');
                    const $input = $row.find('.panel-assign-input'); // Use new input class
                    const standId = $input.val();
                    if (assignPanel(panelId, standId, $row[0])) { // Only clear if assignment was valid
                        $input.val(''); // Clear input after successful assignment
                    }
                });

                // Single Assign Enter Key - Use the new input class
                $tbody.on('keypress', '.panel-assign-input', function(e) {
                    if (e.which === 13) { // Enter key pressed
                        e.preventDefault(); // Prevent potential form submission
                        const $row = $(this).closest('tr');
                        const panelId = $row.data('panel-id');
                        const standId = $(this).val();
                        if (assignPanel(panelId, standId, $row[0])) { // Only clear if assignment was valid
                            $(this).val(''); // Clear input
                        }
                    }
                });


                // Unassign Button - Use the new button class
                $tbody.on('click', '.panel-unassign-btn', function() {
                    const $row = $(this).closest('tr');
                    const panelId = $row.data('panel-id');
                    //const panelAddress = $row.data('address'); // Get address for confirmation
                    //if (confirm(`آیا از لغو تخصیص پنل ${panelAddress || panelId} مطمئن هستید؟`)) {
                    unassignPanel(panelId, $row[0]);
                    //}
                });

                // Bulk Selection Checkbox (individual rows)
                $tbody.on('change', '.panel-select-checkbox', updateBulkAssignState);

                // Select All Visible Checkbox
                $('#select-all-visible').on('change', function() {
                    const isChecked = $(this).prop('checked');
                    // Target only visible and not disabled checkboxes
                    $('.panel-select-checkbox:not(:disabled):visible').prop('checked', isChecked);
                    updateBulkAssignState();
                });

                // Bulk Assign Dropdown (to enable/disable button)
                // Use the new bulk select class if specific styling needed, otherwise ID is fine
                $('#bulk-assign-stand').on('change', updateBulkAssignState);


                // Bulk Assign Button
                $('#bulk-assign-button').on('click', function() {
                    const standId = $('#bulk-assign-stand').val();
                    if (!standId) {
                        alert('لطفا یک خرک برای تخصیص انتخاب کنید.');
                        return;
                    }

                    // Target only checked, visible, and not disabled checkboxes
                    const $selectedCheckboxes = $('.panel-select-checkbox:checked:not(:disabled):visible');
                    const count = $selectedCheckboxes.length;

                    if (count === 0) {
                        alert('هیچ پنل قابل تخصیصی انتخاب نشده است (یا انتخاب‌ها فیلتر شده‌اند).');
                        return;
                    }

                    if (confirm(`آیا مطمئن هستید ${count} پنل انتخاب شده را به خرک ${standId} تخصیص دهید؟`)) {
                        $selectedCheckboxes.each(function() {
                            const $checkbox = $(this);
                            const $row = $checkbox.closest('tr');
                            const panelId = $row.data('panel-id');
                            // Assign panel (updates UI, including disabling checkbox)
                            assignPanel(panelId, standId, $row[0]);
                            // updateRowUI will disable the checkbox, no need to manually uncheck/disable here
                        });
                        // Reset bulk controls after assignment
                        $('#bulk-assign-stand').val(''); // Clear dropdown selection
                        $('#select-all-visible').prop({
                            checked: false,
                            indeterminate: false
                        }); // Reset select-all
                        updateBulkAssignState(); // Update counts and button state
                        // updateStandCountsDisplay(); // Called within updateRowUI which is called by assignPanel
                    }
                });


                // Save Button
                $('#save-assignments').on('click', saveAssignments);
                $(window).on('beforeunload', function(e) {
                    if (hasUnsavedChanges) {
                        const confirmationMessage = 'تغییرات ذخیره نشده‌ای وجود دارد. آیا مطمئن هستید که می‌خواهید صفحه را ترک کنید؟';
                        // Standard way to trigger the browser's confirmation dialog
                        e.returnValue = confirmationMessage; // Gecko, Trident, Chrome >= 34
                        return confirmationMessage; // Gecko, WebKit, Chrome < 34
                    }
                    // If no unsaved changes, return undefined (or nothing) to allow navigation
                });
                console.log("Action event listeners attached (Write Mode).");

            } else {
                console.log("Action event listeners skipped (Read-Only Mode).");
            } // End if (!isPageReadOnly) for listeners

        }


        // --- Run Initialization ---
        initializeTable();

    });
</script>

<?php require_once 'footer.php'; // Assuming footer exists 
?>