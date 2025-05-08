<?php
// truck-assignment.php
require_once __DIR__ . '/../../sercon/config_fereshteh.php';
require_once 'includes/jdf.php';
require_once 'includes/functions.php';

secureSession();

// --- ROLE DEFINITIONS ---
$full_access_roles = ['admin', 'superuser', 'planner'];
// Roles with upload permission but not full edit/save rights for scheduling/trucks
$upload_capable_roles = ['receiver', 'supervisor']; // <<< Defined clearly
$purely_read_only_roles = ['user']; // <<< Only those who can ONLY read

// Combine for initial access check (all roles that can VIEW the page)
$all_view_roles = array_merge($full_access_roles, $upload_capable_roles, $purely_read_only_roles); // <<< Uses new lists

// --- INITIAL ACCESS CHECK ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $all_view_roles)) {
    header('Location: /login');
    exit('Access Denied.');
}

// --- DETERMINE PERMISSIONS ---
$current_role = $_SESSION['role'];
// User cannot perform general edits/saves if they are NOT in full access roles
// This will be TRUE for supervisor, receiver, user
$isReadOnly = !in_array($current_role, $full_access_roles);

// User can upload if they have full access OR are upload capable
// This will be TRUE for admin, superuser, planner, receiver, supervisor
$canUpload = in_array($current_role, $full_access_roles) || in_array($current_role, $upload_capable_roles);
// --- END ROLE HANDLING ---

$pageTitle = 'تخصیص پنل به کامیون';
require_once 'header.php';

try {
    $pdo = connectDB();
    // ... (rest of your database fetching logic remains the same) ...
    try {
        // 1. Get Total Stands
        $stmt_total_stands = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'total_stands' LIMIT 1");
        $totalStandsResult = $stmt_total_stands->fetch(PDO::FETCH_ASSOC);
        $totalStands = 'N/A'; // Default
        if ($totalStandsResult && is_numeric($totalStandsResult['setting_value'])) {
            $totalStands = (int)$totalStandsResult['setting_value'];
        } else {
            logError("Total stands setting ('total_stands') not found or invalid in app_settings.");
        }

        // 2. Calculate Stands Currently Out
        $stmt_stands_out = $pdo->query("
            SELECT SUM( IFNULL(stands_sent, 0) - IFNULL(stands_returned, 0) ) as total_out
            FROM shipments
            WHERE (stands_sent > IFNULL(stands_returned, 0))
               OR (stands_sent > 0 AND stands_returned IS NULL)
               AND status NOT IN ('cancelled', 'delivered_stands_returned') -- Adjust based on your status for returned stands
        ");

        $standsOutResult = $stmt_stands_out->fetch(PDO::FETCH_ASSOC);
        $standsOut = 'N/A'; // Default
        if ($standsOutResult) {
            $standsOut = ($standsOutResult['total_out'] === null) ? 0 : (int)$standsOutResult['total_out'];
            $standsOut = max(0, $standsOut);
        } else {
            logError("Could not fetch stands out count.");
        }

        // 3. Calculate Remaining (only if totals are valid numbers)
        $remainingStands = 'N/A'; // Default
        if (is_numeric($totalStands) && is_numeric($standsOut)) {
            $remainingStands = $totalStands - $standsOut;
        }
    } catch (PDOException $e) {
        logError("Database error fetching stand counts: " . $e->getMessage());
        // Keep counts as 'N/A' on error
        $totalStands = $standsOut = $remainingStands = 'N/A';
    }
    // --- End Fetch Stand Counts ---

    // Get all trucks (no filtering by status, show all) - Keep this query simple
    $stmt_trucks = $pdo->query("SELECT * FROM trucks ORDER BY id DESC"); // Order by ID or truck_number as preferred
    $allTrucks = $stmt_trucks->fetchAll(PDO::FETCH_ASSOC);

    // Get shipment status for each truck (more efficient than LEFT JOIN on large tables)
    $truckShipmentStatus = [];
    if (!empty($allTrucks)) {
        $truckIds = array_column($allTrucks, 'id');
        $placeholders = implode(',', array_fill(0, count($truckIds), '?'));
        $stmt_shipment_status = $pdo->prepare("
            SELECT truck_id, status
            FROM shipments
            WHERE truck_id IN ($placeholders)
            ORDER BY id DESC -- Assuming latest shipment status is most relevant if multiple exist per truck
        ");
        $stmt_shipment_status->execute($truckIds);
        $shipmentStatuses = $stmt_shipment_status->fetchAll(PDO::FETCH_KEY_PAIR); // truck_id => status
    }

    // Combine truck data with shipment status
    $trucks = [];
    foreach ($allTrucks as $truck) {
        $truck['shipment_status'] = $shipmentStatuses[$truck['id']] ?? null;
        $trucks[] = $truck;
    }


    // Get all panels that have completed concrete phase but haven't been assigned to trucks
    $stmt_available = $pdo->query("
        SELECT id, address, type, area, width, length, status
        FROM hpc_panels
        WHERE status = 'completed'
        AND concrete_end_time IS NOT NULL
        AND (packing_status = 'pending' OR packing_status IS NULL)
        AND truck_id IS NULL -- Ensure only unassigned panels are fetched here
        ORDER BY id
    ");
    $availablePanels = $stmt_available->fetchAll(PDO::FETCH_ASSOC);

    // Fetch panels assigned to trucks
    $stmt_assigned = $pdo->query("
        SELECT p.id, p.address, p.type, p.width, p.length, p.truck_id, p.packing_status, t.truck_number
        FROM hpc_panels p
        JOIN trucks t ON p.truck_id = t.id
        WHERE p.truck_id IS NOT NULL
        ORDER BY t.truck_number, p.id
    ");
    $assignedPanels = $stmt_assigned->fetchAll(PDO::FETCH_ASSOC);

    // Group panels by truck_id
    $panelsByTruck = [];
    foreach ($assignedPanels as $panel) {
        $truckId = $panel['truck_id'];
        if (!isset($panelsByTruck[$truckId])) {
            $panelsByTruck[$truckId] = [];
        }
        $panelsByTruck[$truckId][] = $panel;
    }

    // *** Get the count of completed panels *** (Only count truly available ones)
    $completedCount = count($availablePanels); // Use the count from the already fetched available panels


} catch (PDOException $e) {
    logError("Database error in truck-assignment.php: " . $e->getMessage());
    echo "<div class='alert alert-danger'>خطا در اتصال به پایگاه داده</div>";
    // Set defaults to avoid errors later if $pdo fails
    $trucks = [];
    $availablePanels = [];
    $panelsByTruck = [];
    $completedCount = 0;
    $totalStands = $standsOut = $remainingStands = 'N/A';
}
?>

<div class="container-fluid mt-4">
    <h2>تخصیص پنل‌ها به کامیون‌ها</h2>
    <?php
    // Include and display the stand summary
    require_once __DIR__ . '/includes/stand_tracking_summary.php';

    $buttonConfig = null; // Default: no button
    // Only show the "Add Return" button if the user is NOT read-only
    if (!$isReadOnly) {
        $buttonConfig = [
            'text' => 'ثبت برگشت',
            'type' => 'a',
            'icon' => 'bi-arrow-return-left', // Changed icon
            'class' => 'btn-info', // Changed style
            'url' => 'stand_return_tracking.php',
            // 'attributes' => 'onclick="myFunc()"' // Remove or keep if needed
        ];
    }
    // Display the summary, potentially without the button
    displayStandSummary($pdo, false, $buttonConfig);
    ?>

    <?php // --- Conditionally show Add Truck Form --- 
    ?>
    <?php if (!$isReadOnly): ?>
        <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
                <h5>افزودن کامیون جدید</h5>
            </div>
            <div class="card-body">
                <form id="add-truck-form" class="form-inline">
                    <div class="form-group mr-2">
                        <input type="text" class="form-control" name="truck_number" placeholder="شماره کامیون" required>
                    </div>
                    <div class="form-group mr-2">
                        <input type="text" class="form-control" name="driver_name" placeholder="نام راننده">
                    </div>
                    <div class="form-group mr-2">
                        <input type="text" class="form-control" name="driver_phone" placeholder="تلفن راننده">
                    </div>
                    <div class="form-group mr-2">
                        <input type="number" class="form-control" name="capacity" placeholder="ظرفیت" required>
                    </div>
                    <div class="form-group mr-2">
                        <input type="text" class="form-control" name="destination" id="destination" placeholder="مقصد" list="destination-list">
                        <datalist id="destination-list">
                            <!-- Options will be added dynamically via JavaScript -->
                        </datalist>
                    </div>
                    <button type="submit" class="stand-badgeb btn-success">افزودن</button> <?php // Changed class 
                                                                                            ?>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <?php // --- End Conditional Add Truck Form --- 
    ?>


    <button id="toggle-trucks-btn" class="stand-badgeb btn-info mb-2">نمایش/عدم نمایش کامیون‌های تکمیل شده</button>

    <!-- Filter Controls (Visible to all roles) -->
    <div class="card mb-3 panel-assign-filter">
        <div class="card-header panel-assign-card-header">
            <i class="fa fa-filter"></i> فیلترها
        </div>
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">

                <div class="col-md-3">
                    <label for="filter-panel-address" class="form-label form-label-sm">آدرس پنل:</label>
                    <input type="text" class="form-control form-control-sm truck-panel-filter-input" id="filter-panel-address" placeholder="جستجوی آدرس..." data-filter-type="panel" data-filter-field="address">
                </div>
                <div class="col-md-2">
                    <label for="filter-panel-type" class="form-label form-label-sm">نوع پنل:</label>
                    <input type="text" class="form-control form-control-sm truck-panel-filter-input" id="filter-panel-type" placeholder="جستجوی نوع..." data-filter-type="panel" data-filter-field="type">
                </div>
                <div class="col-md-2">
                    <label for="filter-panel-width-min" class="form-label form-label-sm">حداقل عرض (متر):</label>
                    <input type="number" step="0.01" class="form-control form-control-sm truck-panel-filter-input" id="filter-panel-width-min" placeholder="مثلا 1.2" data-filter-type="panel" data-filter-field="width-min">
                </div>
                <div class="col-md-2">
                    <label for="filter-panel-width-max" class="form-label form-label-sm">حداکثر عرض (متر):</label>
                    <input type="number" step="0.01" class="form-control form-control-sm truck-panel-filter-input" id="filter-panel-width-max" placeholder="مثلا 1.5" data-filter-type="panel" data-filter-field="width-max">
                </div>
                <div class="col-md-2">
                    <label for="filter-panel-length-min" class="form-label form-label-sm">حداقل طول (متر):</label>
                    <input type="number" step="0.01" class="form-control form-control-sm truck-panel-filter-input" id="filter-panel-length-min" placeholder="مثلا 2.4" data-filter-type="panel" data-filter-field="length-min">
                </div>
                <div class="col-md-2">
                    <label for="filter-panel-length-max" class="form-label form-label-sm">حداکثر طول (متر):</label>
                    <input type="number" step="0.01" class="form-control form-control-sm truck-panel-filter-input" id="filter-panel-length-max" placeholder="مثلا 3.0" data-filter-type="panel" data-filter-field="length-max">
                </div>


                <div class="col-md-2">
                    <label for="filter-truck-number" class="form-label form-label-sm">شماره کامیون:</label>
                    <input type="text" class="form-control form-control-sm truck-panel-filter-input" id="filter-truck-number" placeholder="جستجوی شماره..." data-filter-type="truck" data-filter-field="truck-number">
                </div>
                <div class="col-md-2">
                    <label for="filter-truck-destination" class="form-label form-label-sm">مقصد کامیون:</label>
                    <input type="text" class="form-control form-control-sm truck-panel-filter-input" id="filter-truck-destination" placeholder="جستجوی مقصد..." data-filter-type="truck" data-filter-field="destination">
                </div>


                <div class="col-md-1 mt-3 mt-md-0">
                    <button class="btn btn-secondary btn-sm w-100" id="clear-truck-panel-filters">
                        <i class="fa fa-times"></i> پاک کردن
                    </button>
                </div>
                <div class="col-12 mt-2 text-muted" id="visible-items-count">
                    پنل‌های قابل مشاهده: <span id="visible-panels-count"></span> | کامیون‌های قابل مشاهده: <span id="visible-trucks-count"></span>
                </div>
            </div>
        </div>
    </div>


    <div class="row">
        <!-- Available Panels and Search (Left Side) -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5>پنل‌های آماده بارگیری</h5>
                    <input type="text" id="panel-search" class="form-control mt-2" placeholder="جستجوی پنل...">
                    <span id="completed-panel-count" class="badge badge-light bg-light text-dark"> <?php // Updated badge classes 
                                                                                                    ?>
                        تعداد: <?= $completedCount ?>
                    </span>
                </div>
                <div class="card-body">
                    <div id="available-panels" class="panel-container">
                        <?php if (empty($availablePanels)): ?>
                            <p>هیچ پنلی آماده بارگیری نیست.</p>
                        <?php else: ?>
                            <?php foreach ($availablePanels as $panel): ?>
                                <div class="panel-item" data-id="<?= $panel['id'] ?>"
                                    data-address="<?= htmlspecialchars(strtolower($panel['address'] ?? '')) ?>"
                                    data-type="<?= htmlspecialchars(strtolower($panel['type'] ?? '')) ?>"
                                    data-width="<?= floatval(($panel['width'] ?? 0) / 1000) ?>"
                                    data-length="<?= floatval(($panel['length'] ?? 0) / 1000) ?>"
                                    <?php // Conditionally add draggable attribute 
                                    ?>
                                    <?= !$isReadOnly ? 'draggable="true"' : '' ?>>
                                    <strong>شماره پنل: <?= $panel['id'] ?></strong><br>
                                    آدرس: <?= htmlspecialchars($panel['address']) ?><br>
                                    نوع: <?= htmlspecialchars($panel['type'] ?? 'نامشخص') ?><br>
                                    ابعاد:
                                    <?= number_format(($panel['width'] ?? 0) / 1000, 2) ?> × <?= number_format(($panel['length'] ?? 0) / 1000, 2) ?>
                                    متر
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Trucks (Right Side) -->
        <div class="col-md-8">
            <div class="row" id="trucks-container">
                <?php $truck_count = 0; ?>
                <?php foreach ($trucks as $truck): ?>
                    <?php $truck_count++; ?>
                    <div class="col-md-6 mb-4 truck-item" data-truck-id="<?= $truck['id'] ?>"
                        data-shipment-status="<?= $truck['shipment_status'] ?? 'none' ?>"
                        data-truck-number="<?= htmlspecialchars(strtolower($truck['truck_number'] ?? '')) ?>"
                        data-destination="<?= htmlspecialchars(strtolower($truck['destination'] ?? '')) ?>"
                        data-driver-name="<?= htmlspecialchars(strtolower($truck['driver_name'] ?? '')) ?>"
                        data-driver-phone="<?= htmlspecialchars(strtolower($truck['driver_phone'] ?? '')) ?>">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <h5>کامیون شماره <?= htmlspecialchars($truck['truck_number']) ?></h5>
                                <small>ظرفیت: <?= $truck['capacity'] ?> پنل</small>

                                <?php // --- Conditionally show Edit Button/Form --- 
                                ?>
                                <?php if (!$isReadOnly): ?>
                                    <!-- Edit Truck Form (Initially Hidden) -->
                                    <form id="edit-truck-form-<?= $truck['id'] ?>" class="edit-truck-form" style="display: none;" data-truck-id="<?= $truck['id'] ?>">
                                        <div class="form-group">
                                            <label>شماره کامیون:</label>
                                            <input type="text" class="form-control" name="truck_number" value="<?= htmlspecialchars($truck['truck_number']) ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>نام راننده:</label>
                                            <input type="text" class="form-control" name="driver_name" value="<?= htmlspecialchars($truck['driver_name'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>تلفن راننده:</label>
                                            <input type="text" class="form-control" name="driver_phone" value="<?= htmlspecialchars($truck['driver_phone'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>ظرفیت:</label>
                                            <input type="number" class="form-control" name="capacity" value="<?= $truck['capacity'] ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>مقصد:</label>
                                            <input type="text" class="form-control" name="destination" value="<?= htmlspecialchars($truck['destination'] ?? '') ?>">
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-success">ذخیره</button>
                                        <button type="button" class="btn btn-sm btn-secondary cancel-edit-truck" data-truck-id="<?= $truck['id'] ?>">لغو</button>
                                    </form>
                                    <button class="btn btn-sm btn-warning edit-truck-btn float-left" data-truck-id="<?= $truck['id'] ?>">ویرایش</button> <?php // Added float-left 
                                                                                                                                                            ?>
                                <?php endif; ?>
                                <?php // --- End Conditional Edit --- 
                                ?>
                            </div>
                            <div class="card-body">
                                <!-- Truck details (Always Visible) -->
                                <div class="truck-details" id="truck-details-<?= $truck['id'] ?>" data-truck-id="<?= $truck['id'] ?>">
                                    <p><strong>نام راننده:</strong> <span class="driver-name"><?= htmlspecialchars($truck['driver_name'] ?? 'نامشخص') ?></span></p>
                                    <p><strong>تلفن راننده:</strong> <span class="driver-phone"><?= htmlspecialchars($truck['driver_phone'] ?? 'نامشخص') ?></span></p>
                                    <p><strong>مقصد:</strong> <span class="destination"><?= htmlspecialchars($truck['destination'] ?? 'نامشخص') ?></span></p>
                                </div>
                                <!-- Visual truck representation -->
                                <div class="truck-visual-container">
                                    <div class="truck-cabin"></div>
                                    <div class="truck-container" data-truck-id="<?= $truck['id'] ?>">
                                        <?php if (isset($panelsByTruck[$truck['id']])): ?>
                                            <?php foreach ($panelsByTruck[$truck['id']] as $panel): ?>
                                                <div class="panel-item assigned <?= strtolower($panel['packing_status'] ?? 'unknown') ?>" data-id="<?= $panel['id'] ?>"
                                                    data-address="<?= htmlspecialchars(strtolower($panel['address'] ?? '')) ?>"
                                                    data-type="<?= htmlspecialchars(strtolower($panel['type'] ?? '')) ?>"
                                                    data-width="<?= floatval(($panel['width'] ?? 0) / 1000) ?>"
                                                    data-length="<?= floatval(($panel['length'] ?? 0) / 1000) ?>"
                                                    <?php // Conditionally add draggable attribute for assigned panels 
                                                    ?>
                                                    <?= !$isReadOnly ? 'draggable="true"' : '' ?>>
                                                    <div class="panel-content">
                                                        <strong>شماره پنل: <?= $panel['id'] ?></strong><br>
                                                        آدرس: <?= htmlspecialchars($panel['address']) ?><br>
                                                        نوع: <?= htmlspecialchars($panel['type'] ?? 'نامشخص') ?><br>
                                                        ابعاد: <?= number_format(($panel['width'] ?? 0) / 1000, 2) ?> × <?= number_format(($panel['length'] ?? 0) / 1000, 2) ?> متر<br>
                                                        وضعیت: <?= $panel['packing_status'] ?? 'N/A' ?>
                                                    </div>
                                                    <?php // --- Conditionally show Unassign Button --- 
                                                    ?>
                                                    <?php if (!$isReadOnly): ?>
                                                        <button class="btn btn-sm btn-danger unassign-btn" data-panel-id="<?= $panel['id'] ?>">
                                                            <i class="fa fa-times"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php // --- End Conditional Unassign --- 
                                                    ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="truck-wheel truck-wheel-front"></div>
                                    <div class="truck-wheel truck-wheel-back"></div>
                                </div>

                                <div class="mt-3">
                                    <span class="panel-count">
                                        تعداد پنل‌های بارگیری شده:
                                        <?= isset($panelsByTruck[$truck['id']]) ? count($panelsByTruck[$truck['id']]) : 0 ?> / <?= $truck['capacity'] ?>
                                    </span>
                                </div>

                                <div class="mt-2">
                                    <?php // --- Conditionally show Schedule Button --- 
                                    ?>
                                    <?php // Read-only users CAN see schedule info, but not save (handled in JS) 
                                    ?>
                                    <button class="btn btn-primary schedule-btn" data-truck-id="<?= $truck['id'] ?>">
                                        <i class="fa fa-calendar-alt"></i> برنامه‌ریزی/مشاهده
                                    </button>

                                 
                                    
                                        <a href="panel-stand-manager.php?truck_id=<?= $truck['id'] ?>"
                                            class="btn btn-sm btn-light manage-stands-btn"
                                            target="_blank"
                                            title="مدیریت خرک‌های این کامیون">
                                            <i class="fas fa-pallet"></i> مدیریت خرک
                                        </a>
                                   

                                    <a href="packing-list.php?truck_id=<?= $truck['id'] ?>" class="btn btn-info" target="_blank">
                                        <i class="fa fa-print"></i> چاپ لیست بارگیری
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($truck_count === 0): ?>
                    <div class="col-12">
                        <p>هیچ کامیونی یافت نشد.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal for scheduling (Visible to all, Save button handled by JS) -->
<div class="modal fade" id="scheduleModal" tabindex="-1" role="dialog" aria-labelledby="scheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="scheduleModalLabel">برنامه‌ریزی / مشاهده ارسال کامیون</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="schedule-form">
                    <input type="hidden" id="truck-id" name="truck_id">
                    <input type="hidden" id="shipment-id" name="shipment_id">
                    <div class="form-group">
                        <label for="packing-list-number">Packing List Number:</label>
                        <input type="text" class="form-control" id="packing-list-number" name="packing_list_number" readonly>
                    </div>
                    <div class="form-group">
                        <label for="persian-date">تاریخ ارسال</label>
                        <input type="text" class="form-control" id="persian-date" placeholder="انتخاب تاریخ" required>
                        <input type="hidden" id="shipping-date" name="shipping_date" required>
                    </div>

                    <div class="form-group">
                        <label for="shipping-time">زمان ارسال</label>
                        <input type="time" class="form-control" id="shipping-time" name="shipping_time" required>
                    </div>

                    <div class="form-group" id="destination-group">
                        <label for="destination">مقصد</label>
                        <input type="text" class="form-control" id="destination-modal" name="destination"> <?php // Changed ID to avoid conflict 
                                                                                                            ?>
                    </div>

                    <div class="form-group">
                        <label for="status">وضعیت</label>
                        <select class="form-control" id="status" name="status" required>
                            <option value="scheduled">برنامه‌ریزی شده</option>
                            <option value="in_transit">در حال حمل</option>
                            <option value="delivered">تحویل شده</option>
                            <option value="cancelled">لغو شده</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="stands-sent">تعداد خرک ارسالی:</label>
                        <input type="number" class="form-control" id="stands-sent" name="stands_sent" min="0" value="0" required>
                    </div>
                    <div class="form-group">
                        <label for="stands-returned">تعداد خرک برگشتی (فقط نمایش):</label>
                        <input type="number" class="form-control" id="stands-returned" name="stands_returned" min="0" readonly style="background-color: #e9ecef;"> <?php // Make read-only visually 
                                                                                                                                                                    ?>
                    </div>
                    <div class="form-group">
                        <label for="stands-return-date-display">تاریخ برگشت خرک (فقط نمایش):</label>
                        <input type="text" class="form-control" id="stands-return-date-display" name="stands_return_date_display" placeholder="-" readonly style="background-color: #e9ecef;"> <?php // Make read-only visually 
                                                                                                                                                                                                ?>
                        <input type="hidden" id="stands-return-date-hidden" name="stands_return_date">
                    </div>
                    <div class="form-group">
                        <label for="notes">یادداشت‌ها</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>

                    <?php // --- Conditionally show Upload Section --- 
                    ?>
                    <?php if ($canUpload): // <<< MODIFIED Check 
                    ?>
                        <div class="form-group">
                            <label for="packing-list-upload">آپلود لیست بسته بندی:</label>
                            <div class="input-group">
                                <input type="file" name="packing_list" id="packing-list-upload" class="form-control" accept=".pdf,image/*">
                                <button type="button" class="btn btn-secondary" id="upload-packing-list-btn">آپلود</button>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php // --- End Conditional Upload --- 
                    ?>

                    <!-- File List (Always Visible) -->
                    <div class="form-group">
                        <label>لیست فایل‌های آپلود شده:</label>
                        <ul id="packing-list-files" style="list-style: none; padding-left: 0;">
                            <li><span class="text-muted">...</span></li>
                        </ul>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">انصراف</button>
                <?php // --- Conditionally show Save Button --- 
                ?>
                <?php // Button is rendered, but JS will disable it for read-only 
                ?>
                <button type="button" class="btn btn-primary" id="save-schedule">ذخیره</button>
            </div>
        </div>
    </div>
</div>


<style>
    .panel-container {
        min-height: 200px;
        border: 2px dashed #ccc;
        padding: 10px;
        margin-bottom: 10px;
        overflow-y: auto;
        max-height: 500px;
    }

    .panel-item {
        padding: 10px;
        margin-bottom: 10px;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        cursor: move;
        position: relative;
    }

    .panel-item.assigned {
        background-color: #d4edda;
        border-color: #c3e6cb;
        padding-right: 35px;
        /* Space for unassign button */
    }

    .panel-item.dragging {
        opacity: 0.5;
    }

    .unassign-btn {
        position: absolute;
        top: 5px;
        right: 5px;
        padding: 2px 6px;
        z-index: 10;
    }

    /* Truck visual styling */
    .truck-visual-container {
        position: relative;
        height: 200px;
        width: 100%;
        margin-bottom: 15px;
    }

    .truck-cabin {
        position: absolute;
        top: 20px;
        right: 10px;
        width: 60px;
        height: 60px;
        background-color: #555;
        border-radius: 5px;
        z-index: 2;
    }

    .truck-container {
        position: absolute;
        top: 0;
        left: 0;
        right: 80px;
        /* Space for cabin */
        height: 150px;
        background-color: #f0f0f0;
        border: 2px solid #555;
        border-radius: 5px;
        padding: 10px;
        overflow-y: auto;
        display: flex;
        flex-wrap: wrap;
        align-content: flex-start;
        z-index: 1;
    }

    .truck-wheel {
        position: absolute;
        bottom: 10px;
        width: 20px;
        height: 20px;
        background-color: #333;
        border-radius: 50%;
        z-index: 2;
    }

    .truck-wheel-front {
        right: 30px;
    }

    .truck-wheel-back {
        right: 120px;
    }

    /* Make sure panels fit nicely in the truck */
    .truck-container .panel-item {
        margin: 5px;
        max-width: calc(50% - 10px);
        box-sizing: border-box;
    }

    .stand-badgeb {
        font-size: 0.85em;
        padding: 12px;
        color: black;
        display: inline-block;
        min-width: 80px;
        text-align: center;
        border-radius: 8px;
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

    /* For animation */
    @keyframes shake {
        0% {
            transform: translate(0, 0);
        }

        25% {
            transform: translate(-5px, 0);
        }

        50% {
            transform: translate(5px, 0);
        }

        75% {
            transform: translate(-3px, 0);
        }

        100% {
            transform: translate(0, 0);
        }
    }

    .truck-visual-container.loading {
        animation: shake 0.5s ease-in-out;
    }

    .panel-container {
        min-height: 200px;
        border: 2px dashed #ccc;
        padding: 10px;
        margin-bottom: 10px;
        overflow-y: auto;
        max-height: 500px;
    }

    .panel-item {
        padding: 10px;
        margin-bottom: 10px;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        cursor: move;
        position: relative;
    }

    .panel-item.assigned {
        background-color: #d4edda;
        border-color: #c3e6cb;
        padding-right: 35px;
        /* Space for unassign button */
    }

    .panel-item.dragging {
        opacity: 0.5;
    }

    .unassign-btn {
        position: absolute;
        top: 5px;
        right: 5px;
        padding: 2px 6px;
        z-index: 10;
    }

    /* Truck visual styling */
    .truck-visual-container {
        position: relative;
        height: 200px;
        width: 100%;
        margin-bottom: 15px;
    }

    .truck-cabin {
        position: absolute;
        top: 20px;
        right: 10px;
        width: 60px;
        height: 60px;
        background-color: #555;
        border-radius: 5px;
        z-index: 2;
    }

    .truck-container {
        position: absolute;
        top: 0;
        left: 0;
        right: 80px;
        /* Space for cabin */
        height: 150px;
        background-color: #f0f0f0;
        border: 2px solid #555;
        border-radius: 5px;
        padding: 10px;
        overflow-y: auto;
        display: flex;
        flex-wrap: wrap;
        align-content: flex-start;
        z-index: 1;
    }

    .truck-wheel {
        position: absolute;
        bottom: 10px;
        width: 20px;
        height: 20px;
        background-color: #333;
        border-radius: 50%;
        z-index: 2;
    }

    .truck-wheel-front {
        right: 30px;
    }

    .truck-wheel-back {
        right: 120px;
    }

    /* Make sure panels fit nicely in the truck */
    .truck-container .panel-item {
        margin: 5px;
        max-width: calc(50% - 10px);
        box-sizing: border-box;
    }

    /* For animation */
    @keyframes shake {
        0% {
            transform: translate(0, 0);
        }

        25% {
            transform: translate(-5px, 0);
        }

        50% {
            transform: translate(5px, 0);
        }

        75% {
            transform: translate(-3px, 0);
        }

        100% {
            transform: translate(0, 0);
        }
    }

    .truck-visual-container.loading {
        animation: shake 0.5s ease-in-out;
    }

    .row {
        display: flex;
        flex-wrap: wrap;
    }

    .col-md-4,
    .col-md-8 {
        position: relative;
        width: 100%;
    }

    @media (min-width: 768px) {
        .col-md-4 {
            flex: 0 0 33.333333%;
            max-width: 33.333333%;
        }

        .col-md-8 {
            flex: 0 0 66.666667%;
            max-width: 66.666667%;
        }
    }

    .datepicker-plot-area {
        font-family: IRANSans, Tahoma, Arial;
        direction: rtl;
    }

    .modal {
        background: rgba(255, 255, 255, 0.8);
    }

    .modal-backdrop {
        display: none;
    }

    /* Existing styles from your original code */

    /* Add these new styles */
    #panel-search {
        width: 100%;
        /* Make search box full width */
        margin-bottom: 10px;
    }

    .truck-item.hidden {
        display: none;
        /* Hide trucks with this class */
    }

    /* Better display for form*/
    #add-truck-form .form-group {
        flex: 1;
        /* Equal width for form fields */
        min-width: 150px;
        /* Prevent fields from becoming too small */
    }

    #add-truck-form button {
        flex-shrink: 0;
        /* Prevent button from shrinking */
    }

    #add-truck-form {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        /* Spacing between form elements */
        align-items: center;
        /* Vertical alignment */
    }

    @media (max-width: 767px) {
        #add-truck-form .form-group {
            flex-basis: calc(50% - 5px);
            /* Two columns on smaller screens */
        }

        #add-truck-form button {
            flex-basis: 100%;
        }
    }

    .panel-item,
    .truck-details,
    .card-header,
    .modal-title,
    .form-control,
    label {
        direction: rtl;
        text-align: right;
        /* Often needed with RTL */
    }

    .form-inline {
        direction: ltr;
        text-align: left;
    }

    .truck-container .panel-item {
        direction: ltr;
        text-align: left;
    }

    .truck-container .panel-item .panel-content {
        direction: rtl;
        text-align: right;
    }

    .unassign-btn {
        left: 5px;
        right: auto;
    }

    .stand-tracking-info {
        padding: 0.8rem 1rem;
        border: 1px solid #c6c8ca;
        /* Secondary alert border */
        background-color: #e2e3e5;
        /* Secondary alert background */
        color: #383d41;
        /* Secondary alert text */
    }

    .stand-tracking-info h5 {
        margin-bottom: 0.5rem;
        font-weight: 500;
    }

    .stand-tracking-info hr {
        margin-top: 0.5rem;
        margin-bottom: 0.75rem;
        border-top: 1px solid rgba(0, 0, 0, .1);
    }

    .stand-tracking-info p span.stand-info-item {
        margin-left: 20px;
        /* Space between items */
    }

    .stand-tracking-info p span.stand-info-item:last-child {
        margin-left: 0;
    }

    .stand-tracking-info strong {
        font-weight: bold;
    }

    /* Add a style to visually indicate non-draggable items for read-only users */
    .panel-item:not([draggable="true"]) {
        cursor: default !important;
    }

    /* Style disabled buttons */
    button:disabled,
    input:disabled,
    select:disabled,
    textarea:disabled {
        cursor: not-allowed !important;
        opacity: 0.65;
    }

    .edit-truck-btn.float-left {
        float: left;
        /* Ensure edit button aligns left */
        margin-left: 5px;
        /* Optional spacing */
    }

    .card-header h5 {
        display: inline-block;
        /* Keep title and button on same line */
        margin-right: 10px;
    }

    .form-group {
        margin-bottom: 1rem;
        /* Ensure spacing between form groups */
    }
</style>

<?php // --- Pass Read-Only Status to JavaScript --- 
?>
<script>
    const isUserReadOnly = <?= json_encode($isReadOnly); ?>; // True for 'user' and 'receiver'
    const isUserCanUpload = <?= json_encode($canUpload); ?>; // <<< ADDED: True for admin, superuser, receiver
    const truckDataArray = <?= json_encode($trucks ?? []); ?>;
    const currentUserRole = <?= json_encode($_SESSION['role'] ?? null); ?>; // <<< ADDED: Pass current role
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        // --- Read-Only Adjustments ---
        if (isUserReadOnly) {
            // 1. Disable drag for all panel items
            document.querySelectorAll('.panel-item').forEach(panel => {
                panel.removeAttribute('draggable');
                panel.style.cursor = 'default';
            });

            // 2. Disable form elements in the schedule modal that modify data
            const modalFormElementsToDisable = document.querySelectorAll(
                '#scheduleModal #persian-date, #scheduleModal #shipping-date, #scheduleModal #shipping-time, ' +
                '#scheduleModal #destination-modal, #scheduleModal #status, #scheduleModal #stands-sent, ' +
                '#scheduleModal #notes'
                // <<< NOTICE: Excluded #packing-list-upload, #upload-packing-list-btn from this selector
            );
            modalFormElementsToDisable.forEach(el => {
                el.disabled = true;
                el.style.backgroundColor = '#e9ecef';
            });


            // 3. Disable the Save button in the schedule modal
            const saveScheduleBtn = document.getElementById('save-schedule');
            if (saveScheduleBtn) {
                saveScheduleBtn.disabled = true;
                saveScheduleBtn.title = 'شما دسترسی لازم برای ذخیره تغییرات را ندارید';
            }

            // 4. Disable the Save button within any Edit Truck forms
            document.querySelectorAll('.edit-truck-form button[type="submit"]').forEach(btn => {
                btn.disabled = true;
                btn.title = 'شما دسترسی لازم برای ذخیره تغییرات را ندارید';
            });


            // 5. (Optional) Add visual cues for read-only mode if desired
            if (!isUserCanUpload) { // This will be true for 'user', false for 'receiver'
                const uploadInput = document.getElementById('packing-list-upload');
                const uploadBtn = document.getElementById('upload-packing-list-btn');
                if (uploadInput) {
                    uploadInput.disabled = true;
                    uploadInput.style.backgroundColor = '#e9ecef';
                }
                if (uploadBtn) {
                    uploadBtn.disabled = true;
                    uploadBtn.style.backgroundColor = '#e9ecef'; // Optional visual cue
                }
                console.log("Upload disabled for read-only user without upload permission.");
            } else {
                console.log("Upload controls remain enabled for this user role.");
            }


            console.log(`Read-only mode adjustments applied for role: ${currentUserRole}. Upload allowed: ${isUserCanUpload}`);
        }
        // --- Autofill for Destination ---
        const destinationInput = document.getElementById('destination'); // In Add Truck form
        const destinationList = document.getElementById('destination-list');

        function fetchDestinations() {
            fetch('api/get-destinations.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && destinationList) { // Check if destinationList exists
                        destinationList.innerHTML = ''; // Clear existing options
                        data.destinations.forEach(destination => {
                            const option = document.createElement('option');
                            option.value = destination;
                            destinationList.appendChild(option);
                        });
                    } else if (!data.success) {
                        console.error('Error fetching destinations:', data.message);
                    }
                })
                .catch(error => console.error('Error fetching destinations:', error));
        }
        if (!isUserReadOnly) { // Only fetch destinations if user can add trucks
            fetchDestinations();
        }

        // --- Filtering Logic ---
        const filterInputs = document.querySelectorAll('.truck-panel-filter-input');
        const availablePanelsContainer = document.getElementById('available-panels');
        const trucksContainer = document.getElementById('trucks-container');
        const clearFiltersButton = document.getElementById('clear-truck-panel-filters');
        const visiblePanelsCountSpan = document.getElementById('visible-panels-count');
        const visibleTrucksCountSpan = document.getElementById('visible-trucks-count');

        // (Keep your applyTruckPanelFilters function as is)
        function applyTruckPanelFilters() {
            // ... your existing filter logic ...
            const filters = {
                panel: {},
                truck: {}
            };
            let hasActivePanelFilter = false;
            let hasActiveTruckFilter = false;

            // 1. Collect Filter Values
            filterInputs.forEach(input => {
                const filterType = input.dataset.filterType; // 'panel' or 'truck'
                const filterField = input.dataset.filterField;
                let value = input.value.trim();
                if (value) {
                    if (filterField.includes('width') || filterField.includes('length')) {
                        const floatValue = parseFloat(value);
                        if (!isNaN(floatValue)) {
                            filters[filterType][filterField] = floatValue;
                            if (filterType === 'panel') hasActivePanelFilter = true;
                        } else {
                            value = ''; // Ignore invalid number input
                        }
                    } else {
                        filters[filterType][filterField] = value.toLowerCase();
                        if (filterType === 'panel') hasActivePanelFilter = true;
                        if (filterType === 'truck') hasActiveTruckFilter = true;
                    }
                }
            });

            let visiblePanelsCount = 0;
            let visibleTrucksCount = 0;
            const checkPanelDimensions = (panelData, panelFilters) => {
                // Convert data attributes (which are strings) to floats for comparison
                const panelWidth = parseFloat(panelData.width || '0');
                const panelLength = parseFloat(panelData.length || '0');

                // Use parseFloat for filter values as well, checking for NaN
                const widthMin = parseFloat(panelFilters['width-min']);
                const widthMax = parseFloat(panelFilters['width-max']);
                const lengthMin = parseFloat(panelFilters['length-min']);
                const lengthMax = parseFloat(panelFilters['length-max']);

                if (!isNaN(widthMin) && panelWidth < widthMin) return false;
                if (!isNaN(widthMax) && panelWidth > widthMax) return false;
                if (!isNaN(lengthMin) && panelLength < lengthMin) return false;
                if (!isNaN(lengthMax) && panelLength > lengthMax) return false;
                return true;
            };


            // 2. Filter Available Panels
            if (availablePanelsContainer) {
                const availablePanelItems = availablePanelsContainer.querySelectorAll('.panel-item');
                availablePanelItems.forEach(panel => {
                    let isVisible = true;
                    const panelData = panel.dataset;

                    // Check Text Filters
                    for (const field in filters.panel) {
                        if (field.includes('width') || field.includes('length')) continue;
                        const filterValue = filters.panel[field]; // Already lowercase
                        // Ensure panelData[field] exists and is converted to lowercase
                        const panelValue = (panelData[field] ? panelData[field] : '').toLowerCase();

                        if (!panelValue.includes(filterValue)) {
                            isVisible = false;
                            break;
                        }
                    }

                    // Check Dimension Filters if still visible
                    if (isVisible && hasActivePanelFilter && !checkPanelDimensions(panelData, filters.panel)) {
                        isVisible = false;
                    }

                    panel.style.display = isVisible ? '' : 'none';
                    if (isVisible) visiblePanelsCount++;
                });
            }


            // 3. Filter Trucks
            if (trucksContainer) {
                const truckItems = trucksContainer.querySelectorAll('.truck-item');
                truckItems.forEach(truck => {
                    let truckInitiallyVisible = true;
                    const truckData = truck.dataset;
                    let containsMatchingPanel = false;

                    // --- Check Truck-specific filters ---
                    if (hasActiveTruckFilter) {
                        for (const field in filters.truck) {
                            const filterValue = filters.truck[field];
                            // Ensure truckData[field] exists before calling toLowerCase
                            const truckValue = (truckData[field] ? truckData[field] : '').toLowerCase();
                            if (!truckValue.includes(filterValue)) {
                                truckInitiallyVisible = false;
                                break;
                            }
                        }
                    }

                    // --- Filter Internal Panels if Panel Filters are Active ---
                    const internalPanels = truck.querySelectorAll('.truck-container .panel-item');
                    let internalVisiblePanelCount = 0; // Count visible panels inside this truck

                    internalPanels.forEach(intPanel => {
                        let panelMatches = true;
                        const intPanelData = intPanel.dataset;

                        // Check Internal Panel Text Filters
                        for (const field in filters.panel) {
                            if (field.includes('width') || field.includes('length')) continue;
                            const filterValue = filters.panel[field];
                            const panelValue = (intPanelData[field] ? intPanelData[field] : '').toLowerCase();
                            if (!panelValue.includes(filterValue)) {
                                panelMatches = false;
                                break;
                            }
                        }

                        // Check Internal Panel Dimension Filters if still matches
                        if (panelMatches && hasActivePanelFilter && !checkPanelDimensions(intPanelData, filters.panel)) {
                            panelMatches = false;
                        }

                        intPanel.style.display = panelMatches ? '' : 'none';
                        if (panelMatches) {
                            containsMatchingPanel = true; // Truck contains at least one matching panel
                            internalVisiblePanelCount++;
                        }
                    });


                    // --- Determine Final Truck Visibility ---
                    // A truck is visible if:
                    // 1. It matches the truck filters (truckInitiallyVisible)
                    // 2. AND ( EITHER panel filters are inactive OR it contains at least one panel matching the panel filters )
                    let finalTruckVisible = truckInitiallyVisible && (!hasActivePanelFilter || containsMatchingPanel);


                    // --- Consider Toggle Button State ---
                    const isToggledHidden = truck.dataset.toggledHidden === 'true';
                    const status = truck.dataset.shipmentStatus;
                    // *** Use the SAME definition of completed statuses ***
                    const completedStatuses = ['delivered', 'cancelled', 'delivered_stands_returned']; // Align this list
                    const isCompleted = completedStatuses.includes(status);


                    // Update filteredOut status
                    truck.dataset.filteredOut = finalTruckVisible ? 'false' : 'true';

                    // Final visibility check: hide if filtered out OR if it's completed AND toggled to be hidden
                    if (!finalTruckVisible || (isCompleted && isToggledHidden)) {
                        truck.style.display = 'none';
                        // console.log(`Hiding Truck ${truck.dataset.truckId}: finalTruckVisible=${finalTruckVisible}, isCompleted=${isCompleted}, isToggledHidden=${isToggledHidden}`);
                    } else {
                        truck.style.display = ''; // Show if filter allows AND not toggled hidden
                        // console.log(`Showing Truck ${truck.dataset.truckId}: finalTruckVisible=${finalTruckVisible}, isCompleted=${isCompleted}, isToggledHidden=${isToggledHidden}`);
                    }

                    if (truck.style.display !== 'none') {
                        visibleTrucksCount++;
                    }

                    // Update internal panel count display for THIS truck based on internal filter results
                    const countDisplay = truck.querySelector('.panel-count');
                    if (countDisplay) {
                        const capacityText = countDisplay.textContent.split('/')[1]?.trim() || truckData.capacity || 'N/A'; // Get capacity robustly
                        countDisplay.textContent = `تعداد پنل‌های بارگیری شده (قابل مشاهده): ${internalVisiblePanelCount} / ${capacityText}`;
                    }
                });
            }


            // 4. Update Global Counts
            if (visiblePanelsCountSpan) visiblePanelsCountSpan.textContent = visiblePanelsCount; // Counts only *available* panels matching filter
            if (visibleTrucksCountSpan) visibleTrucksCountSpan.textContent = visibleTrucksCount;

        }


        // --- Event Listeners for Filters ---
        filterInputs.forEach(input => {
            input.addEventListener('input', applyTruckPanelFilters);
        });

        if (clearFiltersButton) {
            clearFiltersButton.addEventListener('click', () => {
                filterInputs.forEach(input => {
                    input.value = '';
                });
                applyTruckPanelFilters(); // Re-apply filters (which will show all)
                // Also, reset internal counts on trucks after clearing filters
                document.querySelectorAll('.truck-item').forEach(truck => {
                    updatePanelCount(truck.querySelector('.truck-container'));
                });
            });
        }


        // --- Initial Filter Application & Count Update ---
        function updateInitialCounts() {
            let initialVisiblePanels = 0;
            if (availablePanelsContainer) {
                availablePanelsContainer.querySelectorAll('.panel-item').forEach(p => {
                    if (p.style.display !== 'none') initialVisiblePanels++;
                });
            }
            if (visiblePanelsCountSpan) visiblePanelsCountSpan.textContent = initialVisiblePanels;

            let initialVisibleTrucks = 0;
            if (trucksContainer) {
                trucksContainer.querySelectorAll('.truck-item').forEach(t => {
                    // Initialize toggle state if needed
                    t.dataset.toggledHidden = 'false';
                    t.dataset.filteredOut = 'false';
                    if (t.style.display !== 'none') {
                        initialVisibleTrucks++;
                    }
                    // Initialize original panel count
                    updatePanelCount(t.querySelector('.truck-container')); // Update count on load
                });
            }
            if (visibleTrucksCountSpan) visibleTrucksCountSpan.textContent = initialVisibleTrucks;
        }
        updateInitialCounts();
        //applyTruckPanelFilters(); // Apply if needed on load


        // --- Drag and Drop (Only initialize if NOT read-only) ---
        let draggedItem = null;

        function initializeDragForPanel(panel) {
            panel.addEventListener('dragstart', function(e) {
                // Check read-only status *again* just in case
                if (isUserReadOnly) {
                    e.preventDefault();
                    return;
                }
                draggedItem = this;
                setTimeout(() => this.classList.add('dragging'), 0);
            });

            panel.addEventListener('dragend', function() {
                // No need to check read-only here, just cleanup
                this.classList.remove('dragging');
                // Do NOT reset draggedItem here if drop was successful elsewhere
            });
            // Draggable attribute is set via PHP now
            // panel.setAttribute('draggable', true);
        }

        if (!isUserReadOnly) {
            // Initialize drag for initially available panels
            document.querySelectorAll('#available-panels .panel-item').forEach(panel => {
                if (panel.hasAttribute('draggable')) { // Check if PHP allowed it
                    initializeDragForPanel(panel);
                }
            });
            // Initialize drag for initially assigned panels
            document.querySelectorAll('.truck-container .panel-item').forEach(panel => {
                if (panel.hasAttribute('draggable')) {
                    initializeDragForPanel(panel);
                }
            });


            // Add event listeners to truck containers (Drop Zones)
            const truckContainers = document.querySelectorAll('.truck-container');
            truckContainers.forEach(container => {
                container.addEventListener('dragover', function(e) {
                    e.preventDefault(); // Necessary to allow drop
                    // Optional: Provide visual feedback
                    if (draggedItem) { // Only show feedback if dragging a valid item
                        this.style.backgroundColor = '#e9e9e9'; // Light grey highlight
                    }
                });

                container.addEventListener('dragleave', function() {
                    this.style.backgroundColor = ''; // Reset background
                });

                container.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.style.backgroundColor = ''; // Reset background

                    if (!draggedItem) {
                        console.warn("Drop failed: No valid panel was being dragged.");
                        return;
                    }

                    const panelId = draggedItem.dataset.id;
                    const truckId = this.dataset.truckId;
                    const sourceContainer = draggedItem.parentNode;

                    // Capacity Check
                    const panelCountSpan = this.closest('.card-body').querySelector('.panel-count');
                    const capacityMatch = panelCountSpan ? panelCountSpan.textContent.match(/(\d+)\s*\/\s*(\d+)/) : null; // Get total capacity
                    const capacity = capacityMatch ? parseInt(capacityMatch[2], 10) : Infinity; // Use truck capacity from display/data
                    const currentCount = this.querySelectorAll('.panel-item').length;

                    if (currentCount >= capacity) {
                        alert('ظرفیت این کامیون تکمیل است!');
                        draggedItem = null; // Reset dragged item as the drop failed
                        return;
                    }

                    // Only proceed if we have both IDs and source isn't the same truck
                    if (panelId && truckId && sourceContainer !== this) {
                        // --- AJAX call to assign ---
                        fetch('api/assign-panel-to-truck.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({
                                    panel_id: panelId,
                                    truck_id: truckId
                                })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    // Successfully assigned on server

                                    // 1. Remove original panel from its source container
                                    const originalPanelElement = document.querySelector(`.panel-item[data-id="${panelId}"]`);
                                    if (originalPanelElement) {
                                        originalPanelElement.remove();
                                    } else {
                                        console.warn("Could not find original panel element to remove after drop.");
                                    }


                                    // 2. Create or move the panel to the target truck container
                                    // We use the draggedItem directly if moving between trucks,
                                    // or clone if coming from available list
                                    let panelToAdd = draggedItem;

                                    // If coming from available, we need to style it and add button
                                    if (sourceContainer.id === 'available-panels') {
                                        panelToAdd.classList.add('assigned'); // Mark as assigned

                                        // Add unassign button (only if not read-only - already checked by PHP/JS)
                                        const unassignBtn = document.createElement('button');
                                        unassignBtn.className = 'btn btn-sm btn-danger unassign-btn';
                                        unassignBtn.dataset.panelId = panelId;
                                        unassignBtn.innerHTML = '<i class="fa fa-times"></i>';
                                        unassignBtn.addEventListener('click', handleUnassign); // Attach listener

                                        // Wrap content if needed (check if already wrapped)
                                        if (!panelToAdd.querySelector('.panel-content')) {
                                            const contentDiv = document.createElement('div');
                                            contentDiv.className = 'panel-content';
                                            while (panelToAdd.firstChild) {
                                                contentDiv.appendChild(panelToAdd.firstChild);
                                            }
                                            panelToAdd.appendChild(contentDiv);
                                        }
                                        // Ensure button is outside the content div for positioning
                                        panelToAdd.appendChild(unassignBtn);

                                    } else {
                                        // If moving between trucks, the button and structure should already exist
                                        // We might need to re-attach the listener if elements were cloned/recreated
                                        const existingBtn = panelToAdd.querySelector('.unassign-btn');
                                        if (existingBtn) {
                                            existingBtn.removeEventListener('click', handleUnassign); // Remove old listener
                                            existingBtn.addEventListener('click', handleUnassign); // Add new one
                                        }
                                    }


                                    // 3. Append the final panel to the target truck container
                                    this.appendChild(panelToAdd);


                                    // 4. Update panel counts
                                    updatePanelCount(this); // Update target truck count
                                    if (sourceContainer.classList.contains('truck-container')) {
                                        updatePanelCount(sourceContainer); // Update source truck count if applicable
                                    }
                                    updateAvailablePanelCount(); // Update the count of available panels


                                    // 5. Add animation
                                    const truckVisual = this.closest('.truck-visual-container');
                                    if (truckVisual) {
                                        truckVisual.classList.add('loading');
                                        setTimeout(() => truckVisual.classList.remove('loading'), 500);
                                    }
                                    applyTruckPanelFilters(); // Re-apply filters after move

                                } else {
                                    alert('خطا در تخصیص پنل: ' + (data.message || 'خطای نامشخص'));
                                }
                            })
                            .catch(error => {
                                console.error('Assign Panel Error:', error);
                                alert('خطا در ارتباط با سرور هنگام تخصیص پنل.');
                            })
                            .finally(() => {
                                draggedItem = null; // Reset dragged item after fetch attempt
                            });
                    } else if (sourceContainer === this) {
                        // Dropped onto the same truck, do nothing significant
                        console.log("Panel dropped back onto the same truck.");
                        draggedItem = null; // Reset dragged item
                    } else {
                        // Invalid drop condition (e.g., missing IDs)
                        console.warn("Drop conditions not met (missing panelId or truckId).");
                        draggedItem = null; // Reset dragged item
                    }
                });
            });

            // Add event listener to available panels container (Drop Zone for Unassign)
            const availableContainer = document.getElementById('available-panels');
            if (availableContainer) {
                availableContainer.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    if (draggedItem && draggedItem.classList.contains('assigned')) {
                        this.style.backgroundColor = '#ffe0e0'; // Light red highlight for unassign drop
                    }
                });

                availableContainer.addEventListener('dragleave', function() {
                    this.style.backgroundColor = ''; // Reset background
                });

                availableContainer.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.style.backgroundColor = ''; // Reset background

                    if (draggedItem && draggedItem.classList.contains('assigned')) {
                        // Unassign the panel if dropped here
                        const panelId = draggedItem.dataset.id;
                        const sourceTruckContainer = draggedItem.parentNode; // The truck container it came from

                        if (panelId && sourceTruckContainer && sourceTruckContainer.classList.contains('truck-container')) {
                            unassignPanel(panelId, this, sourceTruckContainer); // 'this' is the #available-panels container
                        } else {
                            console.warn("Cannot unassign: Invalid source or panel ID for drop.");
                            draggedItem = null; // Reset if invalid
                        }
                    } else {
                        console.log("Non-assigned panel dropped back into available list.");
                        draggedItem = null; // Reset if not assigned
                    }
                });
            }


            // --- Unassign Button Handling ---
            let unassignTimeout;

            function handleUnassign(e) {
                // Check read-only status *again*
                if (isUserReadOnly) return;

                const button = e.currentTarget; // Use currentTarget
                const panelId = button.dataset.panelId;
                const panelItem = button.closest('.panel-item');
                const sourceContainer = panelItem ? panelItem.parentNode : null;
                const availablePanelsContainer = document.getElementById('available-panels');

                if (!panelId || !panelItem || !sourceContainer || !availablePanelsContainer) {
                    console.error("Could not unassign: Missing required elements/data.");
                    return;
                }


                // Debounce the unassignPanel call
                clearTimeout(unassignTimeout);
                unassignTimeout = setTimeout(() => {
                    unassignPanel(panelId, availablePanelsContainer, sourceContainer);
                }, 200); // Short delay

                e.stopPropagation(); // Prevent potential parent handlers
            }

            // Add event listeners to initially loaded unassign buttons
            document.querySelectorAll('.unassign-btn').forEach(btn => {
                btn.addEventListener('click', handleUnassign);
            });

            // Function to unassign panel (called by button click or drop)
            function unassignPanel(panelId, targetContainer, sourceContainer) {
                if (isUserReadOnly) return; // Final check

                // --- AJAX call to unassign ---
                fetch('api/unassign-panel-from-truck.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            panel_id: panelId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Successfully unassigned on server
                            const panelItem = sourceContainer.querySelector(`.panel-item[data-id="${panelId}"]`);

                            if (panelItem) {
                                // 1. Remove the 'assigned' class and the unassign button
                                panelItem.classList.remove('assigned');
                                const unassignBtn = panelItem.querySelector('.unassign-btn');
                                if (unassignBtn) unassignBtn.remove();

                                // 2. Remove the content wrapper if it exists
                                const contentWrapper = panelItem.querySelector('.panel-content');
                                if (contentWrapper) {
                                    // Move children out of the wrapper before removing it
                                    while (contentWrapper.firstChild) {
                                        panelItem.appendChild(contentWrapper.firstChild);
                                    }
                                    contentWrapper.remove();
                                }


                                // 3. Make it draggable again (if it wasn't already)
                                panelItem.setAttribute('draggable', 'true');
                                // Re-initialize drag listeners for this specific panel
                                initializeDragForPanel(panelItem);


                                // 4. Move the panel element to the target container (available panels)
                                targetContainer.appendChild(panelItem);

                                // 5. Update counts
                                updatePanelCount(sourceContainer); // Update source truck count
                                updateAvailablePanelCount(); // Update available count

                                applyTruckPanelFilters(); // Re-apply filters after move

                            } else {
                                console.warn("Could not find panel item to unassign visually.");
                            }
                        } else {
                            alert('خطا در حذف تخصیص پنل: ' + (data.message || 'خطای نامشخص'));
                        }
                    })
                    .catch(error => {
                        console.error('Unassign Panel Error:', error);
                        alert('خطا در ارتباط با سرور هنگام حذف تخصیص.');
                    })
                    .finally(() => {
                        draggedItem = null; // Ensure draggedItem is cleared after unassign attempt
                    });
            }

        } // --- End of if (!isUserReadOnly) block for drag/drop/unassign ---


        // Function to update panel count display on a truck
        function updatePanelCount(truckContainer) {
            if (!truckContainer || !truckContainer.dataset || !truckContainer.dataset.truckId) {
                // console.warn("updatePanelCount called with invalid container");
                return; // Exit if the container is not valid
            }
            const truckId = truckContainer.dataset.truckId;
            const cardBody = truckContainer.closest('.card-body');
            if (!cardBody) return; // Exit if card body not found

            const countDisplay = cardBody.querySelector('.panel-count');
            if (countDisplay) {
                // Find the capacity reliably (e.g., from truck's data attribute or the original text)
                const truckItem = truckContainer.closest('.truck-item');
                const capacity = truckItem ? parseInt(truckItem.dataset.capacity || truckItem.querySelector('.card-header small')?.textContent.match(/ظرفیت:\s*(\d+)/)?.[1] || '0', 10) : 0; // Get capacity
                const currentCount = truckContainer.querySelectorAll('.panel-item').length; // Count panels physically present

                countDisplay.textContent = `تعداد پنل‌های بارگیری شده: ${currentCount} / ${capacity}`;

                // Update visual styling based on capacity (optional)
                const truckVisual = cardBody.querySelector('.truck-visual-container'); // Find the visual container
                if (truckVisual) {
                    if (currentCount >= capacity) {
                        truckVisual.style.borderColor = '#dc3545'; // Red border if full/over
                    } else if (currentCount > 0) {
                        truckVisual.style.borderColor = '#ffc107'; // Yellow if contains panels but not full
                    } else {
                        truckVisual.style.borderColor = '#555'; // Default border if empty
                    }
                }
            }
        }
        // Function to update the count of available panels in the header
        function updateAvailablePanelCount() {
            const availableContainer = document.getElementById('available-panels');
            const countBadge = document.getElementById('completed-panel-count');
            if (availableContainer && countBadge) {
                const count = availableContainer.querySelectorAll('.panel-item').length;
                countBadge.textContent = `تعداد: ${count}`;
            }
        }


        // --- Schedule Modal Handling ---
        document.querySelectorAll('.schedule-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const truckId = this.dataset.truckId;
                console.log(`Schedule button clicked for truck ID: ${truckId}`);

                const scheduleModal = document.getElementById('scheduleModal');
                const scheduleForm = document.getElementById('schedule-form');
                const truckIdInput = document.getElementById('truck-id');
                const shipmentIdInput = document.getElementById('shipment-id');
                const packingListNumberInput = document.getElementById('packing-list-number');
                const persianDateInput = document.getElementById('persian-date');
                const shippingDateInput = document.getElementById('shipping-date');
                const shippingTimeInput = document.getElementById('shipping-time');
                const destinationInputModal = document.getElementById('destination-modal');
                const destinationGroupModal = document.getElementById('destination-group');
                const statusSelect = document.getElementById('status');
                const standsSentInput = document.getElementById('stands-sent');
                const standsReturnedInput = document.getElementById('stands-returned');
                const standsReturnDateDisplayInput = document.getElementById('stands-return-date-display');
                const standsReturnDateHiddenInput = document.getElementById('stands-return-date-hidden');
                const notesTextarea = document.getElementById('notes');
                const packingListFilesUl = document.getElementById('packing-list-files');
                const uploadPackingListBtn = document.getElementById('upload-packing-list-btn');
                const packingListUploadInput = document.getElementById('packing-list-upload');
                const saveScheduleButton = document.getElementById('save-schedule'); // Get reference to save button

                // 1. Set hidden truck ID
                if (truckIdInput) {
                    truckIdInput.value = truckId;
                } else {
                    console.error("Hidden truck-id input not found in modal.");
                    return; // Stop if essential element is missing
                }

                // 2. Reset the form and related elements visually BEFORE fetching
                console.log("Resetting schedule form...");
                if (scheduleForm) {
                    scheduleForm.reset(); // Reset native form elements
                }
                if (shipmentIdInput) shipmentIdInput.value = ''; // Clear hidden shipment ID
                if (persianDateInput) $(persianDateInput).val(''); // Clear persian datepicker input if used
                if (shippingDateInput) shippingDateInput.value = ''; // Clear hidden gregorian date
                if (packingListFilesUl) packingListFilesUl.innerHTML = '<li><span class="text-muted">در حال بارگذاری...</span></li>'; // Reset file list

                // 3. Set Packing List Number input to read-only and placeholder
                if (packingListNumberInput) {
                    packingListNumberInput.readOnly = true;
                    packingListNumberInput.style.backgroundColor = '#e9ecef'; // Visual cue for readonly
                    packingListNumberInput.value = '(در حال بارگذاری...)'; // Initial placeholder
                } else {
                    console.error("Packing List Number input not found.");
                }

                // 4. Disable upload controls initially
                if (uploadPackingListBtn) uploadPackingListBtn.disabled = true;
                if (packingListUploadInput) packingListUploadInput.disabled = true;

                // 5. Disable Save button initially (will be enabled only if !isUserReadOnly)
                if (saveScheduleButton) saveScheduleButton.disabled = true;


                // 6. Construct the API URL
                const apiUrl = `api/get-truck-shipment.php?truck_id=${encodeURIComponent(truckId)}`;
                console.log(`Fetching shipment data from: ${apiUrl}`);

                // 7. Fetch the data
                fetch(apiUrl)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`Network response was not ok: ${response.status} ${response.statusText}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log("Received shipment data:", data);

                        if (!data || typeof data.success === 'undefined') {
                            throw new Error("Invalid response structure from API.");
                        }

                        if (data.success && data.shipment) {
                            // --- Scenario A: Populate modal with EXISTING shipment data ---
                            console.log("Populating modal with existing shipment data.");
                            const shipment = data.shipment;

                            if (shipmentIdInput) shipmentIdInput.value = shipment.id || '';

                            // Display EXISTING Packing List Number
                            if (packingListNumberInput) {
                                packingListNumberInput.value = shipment.packing_list_number || '(خطا: شماره یافت نشد)';
                            }

                            // Enable upload if PL# exists and user has rights
                            const canUploadNow = isUserCanUpload && !!shipment.packing_list_number; // <<< MODIFIED Check
                            if (uploadPackingListBtn) uploadPackingListBtn.disabled = !canUploadNow;
                            if (packingListUploadInput) packingListUploadInput.disabled = !canUploadNow;
                            console.log(`Upload enabled: ${canUploadNow} (isUserCanUpload: ${isUserCanUpload}, PL#: ${shipment.packing_list_number})`);


                            // Populate Dates
                            if (shippingDateInput) shippingDateInput.value = shipment.shipping_date || '';
                            if (persianDateInput) {
                                if (typeof $ === 'function' && typeof $().persianDatepicker === 'function' && shipment.shipping_date_persian) {
                                    // Use the persian date string provided by the API for display
                                    $(persianDateInput).val(shipment.shipping_date_persian);
                                    // Optional: If datepicker needs explicit setting after external val change:
                                    // try { $(persianDateInput).persianDatepicker('setDate', new Date(shipment.shipping_date).getTime()); } catch(e) {}
                                } else {
                                    // Fallback to Gregorian date if no persian datepicker or persian string unavailable
                                    persianDateInput.value = shipment.shipping_date || '';
                                }
                            }

                            if (shippingTimeInput) shippingTimeInput.value = shipment.shipping_time || '';

                            // Populate Other Fields
                            if (destinationInputModal) destinationInputModal.value = shipment.destination || '';
                            if (statusSelect) statusSelect.value = shipment.status || 'scheduled';
                            if (standsSentInput) standsSentInput.value = shipment.stands_sent || 0;
                            if (standsReturnedInput) standsReturnedInput.value = shipment.stands_returned || 0; // Already readonly

                            // Populate Stand Return Date (Display)
                            const returnDateDisplayVal = shipment.stands_return_date_persian || (shipment.stands_return_date ? shipment.stands_return_date.split(' ')[0] : '-');
                            if (standsReturnDateDisplayInput) standsReturnDateDisplayInput.value = returnDateDisplayVal; // Already readonly
                            if (standsReturnDateHiddenInput) standsReturnDateHiddenInput.value = shipment.stands_return_date || ''; // Store hidden value

                            if (notesTextarea) notesTextarea.value = shipment.notes || '';

                            // Always show destination group when editing
                            if (destinationGroupModal) destinationGroupModal.style.display = 'block';

                            // Fetch and display files
                            if (shipment.packing_list_number && typeof fetchPackingListFiles === 'function') {
                                fetchPackingListFiles(shipment.packing_list_number);
                            } else if (!shipment.packing_list_number && packingListFilesUl) {
                                packingListFilesUl.innerHTML = '<li><span class="text-muted">شماره لیست بسته‌بندی موجود نیست.</span></li>';
                            }


                        } else {
                            // --- Scenario B: Populate modal for a NEW shipment ---
                            console.log("Populating modal for a NEW shipment.");
                            if (shipmentIdInput) shipmentIdInput.value = ''; // Ensure no ID

                            // Display Placeholder for NEW Packing List Number
                            if (packingListNumberInput) {
                                packingListNumberInput.value = '(ذخیره نشده - شماره خودکار)';
                            }

                            // Keep upload disabled
                            if (uploadPackingListBtn) uploadPackingListBtn.disabled = true;
                            if (packingListUploadInput) packingListUploadInput.disabled = true;

                            // Reset fields to defaults
                            if (shippingDateInput) shippingDateInput.value = '';
                            if (persianDateInput) $(persianDateInput).val(''); // Clear datepicker
                            if (shippingTimeInput) shippingTimeInput.value = ''; // Or maybe a default like '08:00'?
                            if (statusSelect) statusSelect.value = 'scheduled';
                            if (standsSentInput) standsSentInput.value = 0;
                            if (standsReturnedInput) standsReturnedInput.value = 0;
                            if (standsReturnDateDisplayInput) standsReturnDateDisplayInput.value = '-';
                            if (standsReturnDateHiddenInput) standsReturnDateHiddenInput.value = '';
                            if (notesTextarea) notesTextarea.value = '';

                            // Try to pre-fill destination from truck data
                            const truck = truckDataArray.find(t => t.id == truckId); // Ensure truckDataArray is available
                            const truckDestination = truck ? truck.destination : '';
                            if (destinationInputModal) destinationInputModal.value = truckDestination || '';

                            // Always show destination group for new shipments
                            if (destinationGroupModal) destinationGroupModal.style.display = 'block';

                            // Set file list message
                            if (packingListFilesUl) packingListFilesUl.innerHTML = '<li><span class="text-muted">ابتدا ارسال را ذخیره کنید تا فایل‌ها نمایش داده شوند.</span></li>';
                        }

                        // 8. Final step: Enable/Disable based on Read-Only status
                        // This runs *after* populating, ensuring API-filled fields are also disabled if needed.
                        console.log(`Applying read-only status (isUserReadOnly: ${isUserReadOnly})`);
                        if (scheduleForm) {
                            const elementsToDisable = scheduleForm.querySelectorAll(
                                'input:not([type="hidden"]):not(#stands-returned):not(#stands-return-date-display), select, textarea, #upload-packing-list-btn'
                            ); // Select inputs (excluding specific readonly ones), selects, textareas, and the upload button
                            const elementsToEnable = []; // We will enable save button separately if needed

                            if (isUserReadOnly) {
                                elementsToDisable.forEach(el => {
                                    el.disabled = true;
                                    el.style.backgroundColor = '#e9ecef'; // Optional visual cue
                                });
                                if (saveScheduleButton) saveScheduleButton.disabled = true; // Ensure save is disabled
                                console.log("Read-only mode: All editable fields and save button disabled.");
                            } else {
                                // Enable fields that are editable by default (upload button handled above based on PL#)
                                elementsToDisable.forEach(el => {
                                    if (el.id !== 'upload-packing-list-btn' && el.id !== 'packing-list-upload') { // Don't re-enable upload here
                                        el.disabled = false;
                                        el.style.backgroundColor = ''; // Reset background
                                    }
                                });
                                // Enable the save button if user is not read-only
                                if (saveScheduleButton) saveScheduleButton.disabled = false;
                                console.log("Write mode: Editable fields and save button enabled (upload depends on PL#).");
                            }
                            // Ensure the explicitly read-only fields remain visually read-only
                            if (standsReturnedInput) standsReturnedInput.style.backgroundColor = '#e9ecef';
                            if (standsReturnDateDisplayInput) standsReturnDateDisplayInput.style.backgroundColor = '#e9ecef';
                            if (packingListNumberInput) packingListNumberInput.style.backgroundColor = '#e9ecef';
                        }

                        // 9. Show the modal
                        if (typeof $ === 'function' && scheduleModal) {
                            console.log("Showing schedule modal.");
                            $(scheduleModal).modal('show');
                        } else {
                            console.error("jQuery or scheduleModal element not found. Cannot show modal.");
                        }

                    })
                    .catch(error => {
                        // --- Handle Fetch Errors ---
                        console.error('Error fetching or processing shipment data:', error);
                        alert(`خطا در دریافت اطلاعات ارسال: ${error.message}`);

                        // Reset form to a safe error state
                        if (scheduleForm) scheduleForm.reset();
                        if (shipmentIdInput) shipmentIdInput.value = '';
                        if (persianDateInput) $(persianDateInput).val('');
                        if (shippingDateInput) shippingDateInput.value = '';
                        if (packingListFilesUl) packingListFilesUl.innerHTML = '<li><span class="text-danger">خطا در بارگذاری</span></li>';
                        if (packingListNumberInput) {
                            packingListNumberInput.readOnly = true;
                            packingListNumberInput.style.backgroundColor = '#e9ecef';
                            packingListNumberInput.value = '(خطا در بارگذاری)';
                        }
                        if (uploadPackingListBtn) uploadPackingListBtn.disabled = true;
                        if (packingListUploadInput) packingListUploadInput.disabled = true;
                        if (saveScheduleButton) saveScheduleButton.disabled = true; // Disable save on error
                    });
            });
        });

        // --- Save Schedule Button (Only attach listener if NOT read-only) ---
        const saveScheduleButton = document.getElementById('save-schedule');
        if (saveScheduleButton && !isUserReadOnly) { // Check button exists and user has write access
            saveScheduleButton.addEventListener('click', function() {
                const form = document.getElementById('schedule-form');
                const formData = new FormData(form);
                const jsonData = Object.fromEntries(formData.entries());
                const wasNewShipment = !document.getElementById('shipment-id').value; // Check if it was new *before* save

                // ... (validation checks) ...
                if (!jsonData.shipping_date || !jsonData.shipping_time || !jsonData.status) {
                    alert('لطفاً تاریخ، زمان و وضعیت ارسال را مشخص کنید.');
                    return;
                }
                jsonData.stands_return_date = document.getElementById('stands-return-date-hidden').value || null;
                // Remove the placeholder PL# before sending if it was new
                if (wasNewShipment) {
                    delete jsonData.packing_list_number; // Let server generate it
                }


                fetch('api/save-truck-shipment.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(jsonData)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('برنامه‌ریزی با موفقیت ذخیره شد.');

                            // *** Update UI with data returned from Server (including generated PL#) ***
                            if (data.shipment) {
                                document.getElementById('shipment-id').value = data.shipment.id || ''; // Update shipment ID
                                document.getElementById('packing-list-number').value = data.shipment.packing_list_number || '(خطا)'; // Update PL# from server response

                                // Enable upload button now that we have a PL# (if not read-only)
                                const uploadPackingListBtn = document.getElementById('upload-packing-list-btn');
                                const packingListUploadInput = document.getElementById('packing-list-upload');
                                if (isUserCanUpload && data.shipment.packing_list_number) { // <<< MODIFIED Check
                                    if (uploadPackingListBtn) uploadPackingListBtn.disabled = false;
                                    if (packingListUploadInput) packingListUploadInput.disabled = false;
                                } else {
                                    if (uploadPackingListBtn) uploadPackingListBtn.disabled = true;
                                    if (packingListUploadInput) packingListUploadInput.disabled = true;
                                }
                                // Fetch files for the new/updated PL number
                                if (data.shipment.packing_list_number) {
                                    fetchPackingListFiles(data.shipment.packing_list_number);
                                }

                            } else if (wasNewShipment) {
                                // Handle case where save succeeded but server didn't return shipment details (less ideal)
                                document.getElementById('packing-list-number').value = '(ذخیره شد - شماره؟)';
                                // Upload might be disabled here
                            }


                            $('#scheduleModal').modal('hide'); // Hide the modal
                            // Update truck status display
                            const truckItem = document.querySelector(`.truck-item[data-truck-id="${jsonData.truck_id}"]`);
                            if (truckItem && data.shipment) {
                                truckItem.dataset.shipmentStatus = data.shipment.status || jsonData.status;
                            }
                            applyTruckPanelFilters();


                        } else {
                            alert('خطا در ذخیره برنامه‌ریزی: ' + (data.message || 'خطای نامشخص'));
                        }
                    })
                    .catch(error => {
                        console.error('Save Shipment Error:', error);
                        alert('خطا در ارتباط با سرور هنگام ذخیره برنامه‌ریزی.');
                    });
            });
        }


        // --- Edit Truck Form Handling (Only attach listeners if NOT read-only) ---
        if (!isUserReadOnly) {
            document.querySelectorAll('.edit-truck-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (isUserReadOnly) return; // Double check
                    const truckId = this.dataset.truckId;
                    const editForm = document.getElementById('edit-truck-form-' + truckId);
                    const truckDetails = document.getElementById('truck-details-' + truckId);

                    if (editForm && truckDetails) {
                        // Toggle visibility
                        editForm.style.display = 'block';
                        truckDetails.style.display = 'none';
                        this.style.display = 'none'; // Hide the "Edit" button itself
                    }
                });
            });

            document.querySelectorAll('.cancel-edit-truck').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (isUserReadOnly) return; // Double check
                    const truckId = this.dataset.truckId;
                    const editForm = document.getElementById('edit-truck-form-' + truckId);
                    const truckDetails = document.getElementById('truck-details-' + truckId);
                    const editButton = document.querySelector(`.edit-truck-btn[data-truck-id="${truckId}"]`);
                    if (editForm && truckDetails && editButton) {
                        // Toggle visibility back
                        editForm.style.display = 'none';
                        truckDetails.style.display = 'block';
                        editButton.style.display = 'inline-block'; // Or 'block' depending on original style
                    }
                });
            });

            document.querySelectorAll('.edit-truck-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (isUserReadOnly) return; // Final check before submit

                    const truckId = this.dataset.truckId;
                    const formData = new FormData(this);
                    // Create JSON object including truck_id
                    const jsonData = {
                        truck_id: truckId
                    };
                    formData.forEach((value, key) => {
                        jsonData[key] = value;
                    });


                    fetch('api/edit-truck.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(jsonData)
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('اطلاعات کامیون با موفقیت به‌روزرسانی شد.');

                                // Update the displayed details *without* a full page reload
                                const truckDetails = document.getElementById('truck-details-' + truckId);
                                const truckItem = document.querySelector(`.truck-item[data-truck-id="${truckId}"]`); // Get parent item for data attributes

                                if (truckDetails) {
                                    truckDetails.querySelector('.driver-name').textContent = jsonData.driver_name || 'نامشخص';
                                    truckDetails.querySelector('.driver-phone').textContent = jsonData.driver_phone || 'نامشخص';
                                    truckDetails.querySelector('.destination').textContent = jsonData.destination || 'نامشخص';
                                }
                                // Update data attributes used by filters
                                if (truckItem) {
                                    truckItem.dataset.truckNumber = (jsonData.truck_number || '').toLowerCase();
                                    truckItem.dataset.destination = (jsonData.destination || '').toLowerCase();
                                    truckItem.dataset.driverName = (jsonData.driver_name || '').toLowerCase();
                                    truckItem.dataset.driverPhone = (jsonData.driver_phone || '').toLowerCase();
                                    // Update capacity display in header and count span
                                    const headerSmall = truckItem.querySelector('.card-header small');
                                    const panelCountSpan = truckItem.querySelector('.panel-count');
                                    if (headerSmall) headerSmall.textContent = `ظرفیت: ${jsonData.capacity} پنل`;
                                    truckItem.dataset.capacity = jsonData.capacity; // Update data attribute
                                    updatePanelCount(truckItem.querySelector('.truck-container')); // Recalculate count display

                                }

                                // Hide form, show details, show edit button
                                const editButton = document.querySelector(`.edit-truck-btn[data-truck-id="${truckId}"]`);
                                this.style.display = 'none'; // Hide the form
                                if (truckDetails) truckDetails.style.display = 'block';
                                if (editButton) editButton.style.display = 'inline-block';


                            } else {
                                alert('خطا در به‌روزرسانی اطلاعات کامیون: ' + (data.message || 'خطای نامشخص'));
                            }
                        })
                        .catch(error => {
                            console.error('Edit Truck Error:', error);
                            alert('خطا در ارتباط با سرور هنگام ویرایش کامیون.');
                        });
                });
            });
        }


        // --- Panel Search (Available Panels) ---
        const panelSearchInput = document.getElementById('panel-search');
        if (panelSearchInput) {
            panelSearchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                const panels = document.querySelectorAll('#available-panels .panel-item');
                let visibleCount = 0;

                panels.forEach(panel => {
                    // Check if the panel is already hidden by the main filter
                    if (panel.style.display === 'none' && searchTerm !== '') {
                        // If main filter hid it, search won't reveal it unless search is cleared
                        return;
                    }

                    const panelIdText = panel.querySelector('strong')?.textContent.toLowerCase() || '';
                    const panelAddressText = panel.textContent.toLowerCase(); // Search whole text content
                    const matchesSearch = panelIdText.includes(searchTerm) || panelAddressText.includes(searchTerm);


                    if (matchesSearch) {
                        panel.style.display = ''; // Show if matches search (and wasn't hidden by main filter)
                        visibleCount++;
                    } else {
                        panel.style.display = 'none'; // Hide if doesn't match search
                    }
                });
                // Optionally update a specific search result count?
            });
        }


        // --- Toggle Completed Trucks ---
        const toggleBtn = document.getElementById('toggle-trucks-btn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                const trucks = trucksContainer ? trucksContainer.querySelectorAll('.truck-item') : [];
                const completedStatuses = ['delivered', 'cancelled', 'delivered_stands_returned']; // Define statuses eligible for hiding

                trucks.forEach(truck => {
                    const status = truck.dataset.shipmentStatus;
                    const isCompleted = completedStatuses.includes(status);

                    if (isCompleted) {
                        // Toggle the data attribute only for completed trucks
                        const currentlyHiddenByToggle = truck.dataset.toggledHidden === 'true';
                        truck.dataset.toggledHidden = currentlyHiddenByToggle ? 'false' : 'true';
                        console.log(`Truck ${truck.dataset.truckId} (${status}) toggledHidden set to: ${truck.dataset.toggledHidden}`);
                    } else {
                        // Ensure non-completed trucks are never marked as hidden by the toggle itself
                        truck.dataset.toggledHidden = 'false';
                    }
                });
                console.log("Toggle button clicked, applying filters...");
                applyTruckPanelFilters(); // Re-apply filters to respect new toggle state and existing filters
            });
        }
        // --- Sort Trucks (Newest First by ID) ---
        function sortTrucks() {
            const truckContainer = document.getElementById('trucks-container');
            if (!truckContainer) return; // Exit if container doesn't exist

            const trucks = Array.from(truckContainer.querySelectorAll('.truck-item'));

            trucks.sort((a, b) => {
                // Ensure IDs are treated as numbers for correct sorting
                return parseInt(b.dataset.truckId || '0', 10) - parseInt(a.dataset.truckId || '0', 10);
            });

            // Clear and re-append sorted trucks
            // truckContainer.innerHTML = ''; // Avoid innerHTML for performance if possible
            trucks.forEach(truck => truckContainer.appendChild(truck)); // Re-append in sorted order
        }
        sortTrucks(); // Initial sort on page load


        // --- Add New Truck (Only if NOT read-only) ---
        const addTruckForm = document.getElementById('add-truck-form');
        if (addTruckForm && !isUserReadOnly) { // Check form exists and user has rights
            addTruckForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (isUserReadOnly) return; // Final check

                const formData = new FormData(this);
                // Convert FormData to JSON
                const jsonData = {};
                formData.forEach((value, key) => {
                    jsonData[key] = value;
                });


                fetch('api/add-truck.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(jsonData)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.truck) {
                            alert('کامیون با موفقیت اضافه شد.');
                            addTruckForm.reset(); // Clear the form
                            addNewTruck(data.truck); // Add the new truck visually to the top
                            // sortTrucks(); // No need to sort again if prepending
                            fetchDestinations(); // Refresh destination list potentially
                            applyTruckPanelFilters(); // Apply filters to ensure new truck visibility
                            updateInitialCounts(); // Recalculate counts

                        } else {
                            alert('خطا در افزودن کامیون: ' + (data.message || 'خطای نامشخص'));
                        }
                    })
                    .catch(error => {
                        console.error('Add Truck Error:', error);
                        alert('خطا در ارتباط با سرور هنگام افزودن کامیون.');
                    });
            });

            // Autofill listener (only needed if adding trucks)
            const truckNumberInput = addTruckForm.querySelector("input[name='truck_number']");
            if (truckNumberInput) {
                truckNumberInput.addEventListener('input', function() {
                    const enteredTruckNumber = this.value.trim();
                    const driverNameInput = addTruckForm.querySelector("input[name='driver_name']");
                    const driverPhoneInput = addTruckForm.querySelector("input[name='driver_phone']");
                    // const destinationInput = addTruckForm.querySelector("input[name='destination']"); // Removed destination autofill

                    if (enteredTruckNumber === "") {
                        // Clear related fields if truck number is empty
                        if (driverNameInput) driverNameInput.value = "";
                        if (driverPhoneInput) driverPhoneInput.value = "";
                        // if(destinationInput) destinationInput.value = ""; // Removed
                        return;
                    }

                    // Fetch existing truck data to check for match
                    fetch('api/get-truck-details.php?truck_number=' + encodeURIComponent(enteredTruckNumber)) // Use a specific API endpoint if possible
                        .then(response => response.json())
                        .then(data => {
                            // Assuming API returns { success: true, truck: { ... } } or { success: false }
                            if (data.success && data.truck) {
                                const matchingTruck = data.truck;
                                // Autofill the other fields
                                if (driverNameInput) driverNameInput.value = matchingTruck.driver_name || '';
                                if (driverPhoneInput) driverPhoneInput.value = matchingTruck.driver_phone || '';
                                // if(destinationInput) destinationInput.value = matchingTruck.destination || ''; // Removed
                            } else {
                                // No match found or API error, maybe clear fields or do nothing
                                // if(driverNameInput) driverNameInput.value = "";
                                // if(driverPhoneInput) driverPhoneInput.value = "";
                            }
                        })
                        .catch(error => {
                            console.error("Error fetching truck details for autofill:", error);
                        });
                });
            }
        }


        // Function to add a new truck element to the UI (prepends)
        function addNewTruck(truck) {
            const truckContainerElement = document.getElementById('trucks-container');
            if (!truckContainerElement) return; // Exit if container not found

            const newTruckDiv = document.createElement('div');
            newTruckDiv.className = 'col-md-6 mb-4 truck-item'; // Match existing structure
            newTruckDiv.dataset.truckId = truck.id;
            newTruckDiv.dataset.shipmentStatus = 'none'; // New trucks have no shipment yet
            // Add other relevant data attributes for filtering
            newTruckDiv.dataset.truckNumber = (truck.truck_number || '').toLowerCase();
            newTruckDiv.dataset.destination = (truck.destination || '').toLowerCase();
            newTruckDiv.dataset.driverName = (truck.driver_name || '').toLowerCase();
            newTruckDiv.dataset.driverPhone = (truck.driver_phone || '').toLowerCase();
            newTruckDiv.dataset.capacity = truck.capacity;


            // Construct inner HTML (ensure it matches the structure in the PHP loop)
            newTruckDiv.innerHTML = `
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5>کامیون شماره ${truck.truck_number}</h5>
                    <small>ظرفیت: ${truck.capacity} پنل</small>
                    ${!isUserReadOnly ? `
                    <form id="edit-truck-form-${truck.id}" class="edit-truck-form" style="display: none;" data-truck-id="${truck.id}">
                        <div class="form-group"><label>شماره:</label><input type="text" class="form-control" name="truck_number" value="${truck.truck_number || ''}" required></div>
                        <div class="form-group"><label>راننده:</label><input type="text" class="form-control" name="driver_name" value="${truck.driver_name || ''}"></div>
                        <div class="form-group"><label>تلفن:</label><input type="text" class="form-control" name="driver_phone" value="${truck.driver_phone || ''}"></div>
                        <div class="form-group"><label>ظرفیت:</label><input type="number" class="form-control" name="capacity" value="${truck.capacity}" required></div>
                        <div class="form-group"><label>مقصد:</label><input type="text" class="form-control" name="destination" value="${truck.destination || ''}"></div>
                        <button type="submit" class="btn btn-sm btn-success">ذخیره</button>
                        <button type="button" class="btn btn-sm btn-secondary cancel-edit-truck" data-truck-id="${truck.id}">لغو</button>
                    </form>
                    <button class="btn btn-sm btn-warning edit-truck-btn float-left" data-truck-id="${truck.id}">ویرایش</button>
                    ` : ''}
                </div>
                <div class="card-body">
                    <div class="truck-details" id="truck-details-${truck.id}" data-truck-id="${truck.id}">
                        <p><strong>نام راننده:</strong> <span class="driver-name">${truck.driver_name || 'نامشخص'}</span></p>
                        <p><strong>تلفن راننده:</strong> <span class="driver-phone">${truck.driver_phone || 'نامشخص'}</span></p>
                        <p><strong>مقصد:</strong> <span class="destination">${truck.destination || 'نامشخص'}</span></p>
                    </div>
                    <div class="truck-visual-container">
                        <div class="truck-cabin"></div>
                        <div class="truck-container" data-truck-id="${truck.id}"></div>
                        <div class="truck-wheel truck-wheel-front"></div>
                        <div class="truck-wheel truck-wheel-back"></div>
                    </div>
                    <div class="mt-3">
                        <span class="panel-count">تعداد پنل‌های بارگیری شده: 0 / ${truck.capacity}</span>
                    </div>
                    <div class="mt-2">
                        <button class="btn btn-primary schedule-btn" data-truck-id="${truck.id}">
                           <i class="fa fa-calendar-alt"></i> برنامه‌ریزی/مشاهده
                        </button>
                        ${!isUserReadOnly ? `
                        <a href="panel-stand-manager.php?truck_id=${truck.id}" class="btn btn-sm btn-light manage-stands-btn" target="_blank" title="مدیریت خرک"><i class="fas fa-pallet"></i> مدیریت خرک</a>
                        ` : ''}
                        <a href="packing-list.php?truck_id=${truck.id}" class="btn btn-info" target="_blank">
                            <i class="fa fa-print"></i> چاپ لیست بارگیری
                        </a>
                    </div>
                </div>
            </div>
        `;

            // --- Add Event Listeners to the New Truck ---
            const newTruckElement = newTruckDiv; // The div itself

            // Schedule Button Listener
            const scheduleBtnNew = newTruckElement.querySelector('.schedule-btn');
            if (scheduleBtnNew) {
                scheduleBtnNew.addEventListener('click', function() {
                    const truckId = this.dataset.truckId;
                    document.getElementById('truck-id').value = truckId;
                    // Logic to fetch/reset modal data (same as the main listener)
                    // Reset form first
                    document.getElementById('schedule-form').reset();
                    document.getElementById('shipment-id').value = '';
                    $('#persian-date').val('');
                    document.getElementById('shipping-date').value = '';
                    document.getElementById('packing-list-number').value = `PL-${truckId}-${Date.now()}`; // Default for new truck
                    document.getElementById('packing-list-files').innerHTML = '<li><span class="text-muted">هنوز فایلی آپلود نشده است.</span></li>';

                    // Populate destination from truck data
                    const destinationInputModal = document.getElementById('destination-modal');
                    const destinationGroupModal = document.getElementById('destination-group');
                    destinationInputModal.value = truck.destination || '';
                    destinationGroupModal.style.display = 'block';

                    // Set defaults
                    document.getElementById('status').value = 'scheduled';
                    document.getElementById('stands-sent').value = 0;
                    document.getElementById('stands-returned').value = 0;
                    document.getElementById('stands-return-date-display').value = '-';
                    document.getElementById('stands-return-date-hidden').value = '';
                    document.getElementById('notes').value = '';


                    // Re-apply read-only disabling if needed
                    if (isUserReadOnly) {
                        const modalFormElementsRO = document.getElementById('schedule-form').querySelectorAll('input, select, textarea, button');
                        modalFormElementsRO.forEach(el => {
                            if (!el.classList.contains('close') && el.getAttribute('data-dismiss') !== 'modal' && el.id !== 'save-schedule') {
                                el.disabled = true;
                                el.style.backgroundColor = '#e9ecef';
                            } else if (el.id === 'save-schedule') {
                                el.disabled = true;
                            }
                        });
                    }

                    $('#scheduleModal').modal('show');
                });
            }


            // Edit/Cancel/Submit Listeners (only if NOT read-only)
            if (!isUserReadOnly) {
                const editBtnNew = newTruckElement.querySelector('.edit-truck-btn');
                const cancelBtnNew = newTruckElement.querySelector('.cancel-edit-truck');
                const editFormNew = newTruckElement.querySelector('.edit-truck-form');

                if (editBtnNew) {
                    editBtnNew.addEventListener('click', function() {
                        const truckId = this.dataset.truckId;
                        const form = document.getElementById('edit-truck-form-' + truckId);
                        const details = document.getElementById('truck-details-' + truckId);
                        if (form && details) {
                            form.style.display = 'block';
                            details.style.display = 'none';
                            this.style.display = 'none';
                        }
                    });
                }

                if (cancelBtnNew) {
                    cancelBtnNew.addEventListener('click', function() {
                        const truckId = this.dataset.truckId;
                        const form = document.getElementById('edit-truck-form-' + truckId);
                        const details = document.getElementById('truck-details-' + truckId);
                        const editButton = document.querySelector(`.edit-truck-btn[data-truck-id="${truckId}"]`);
                        if (form && details && editButton) {
                            form.style.display = 'none';
                            details.style.display = 'block';
                            editButton.style.display = 'inline-block';
                        }
                    });
                }

                if (editFormNew) {
                    editFormNew.addEventListener('submit', function(e) { // Use the same logic as the main edit handler
                        e.preventDefault();
                        if (isUserReadOnly) return;

                        const truckId = this.dataset.truckId;
                        const formData = new FormData(this);
                        const jsonData = {
                            truck_id: truckId
                        };
                        formData.forEach((value, key) => {
                            jsonData[key] = value;
                        });

                        // --- Fetch Call ---
                        fetch('api/edit-truck.php', {
                                /* ... same as main edit fetch ... */
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify(jsonData)
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    alert('اطلاعات کامیون با موفقیت به‌روزرسانی شد.');
                                    // Update UI (same as main edit success block)
                                    const truckDetails = document.getElementById('truck-details-' + truckId);
                                    const truckItemElement = document.querySelector(`.truck-item[data-truck-id="${truckId}"]`);

                                    if (truckDetails) {
                                        truckDetails.querySelector('.driver-name').textContent = jsonData.driver_name || 'نامشخص';
                                        truckDetails.querySelector('.driver-phone').textContent = jsonData.driver_phone || 'نامشخص';
                                        truckDetails.querySelector('.destination').textContent = jsonData.destination || 'نامشخص';
                                    }
                                    if (truckItemElement) {
                                        truckItemElement.dataset.truckNumber = (jsonData.truck_number || '').toLowerCase();
                                        truckItemElement.dataset.destination = (jsonData.destination || '').toLowerCase();
                                        truckItemElement.dataset.driverName = (jsonData.driver_name || '').toLowerCase();
                                        truckItemElement.dataset.driverPhone = (jsonData.driver_phone || '').toLowerCase();
                                        const headerSmall = truckItemElement.querySelector('.card-header small');
                                        if (headerSmall) headerSmall.textContent = `ظرفیت: ${jsonData.capacity} پنل`;
                                        truckItemElement.dataset.capacity = jsonData.capacity;
                                        updatePanelCount(truckItemElement.querySelector('.truck-container'));
                                    }
                                    const editButton = document.querySelector(`.edit-truck-btn[data-truck-id="${truckId}"]`);
                                    this.style.display = 'none';
                                    if (truckDetails) truckDetails.style.display = 'block';
                                    if (editButton) editButton.style.display = 'inline-block';


                                } else {
                                    alert('خطا در به‌روزرسانی: ' + (data.message || 'خطای نامشخص'));
                                }
                            })
                            .catch(error => {
                                console.error('Edit Truck Error:', error);
                                alert('خطا در ارتباط با سرور.');
                            });


                    });
                }

                // Drag/Drop Listener for the new truck's container
                const truckVisualNew = newTruckElement.querySelector('.truck-container');
                if (truckVisualNew) {
                    truckVisualNew.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        if (draggedItem) this.style.backgroundColor = '#e9e9e9';
                    });
                    truckVisualNew.addEventListener('dragleave', function() {
                        this.style.backgroundColor = '';
                    });
                    truckVisualNew.addEventListener('drop', function(e) { // Use same drop logic as main handler
                        e.preventDefault();
                        this.style.backgroundColor = '';
                        if (!draggedItem) return;

                        const panelId = draggedItem.dataset.id;
                        const truckId = this.dataset.truckId;
                        const sourceContainer = draggedItem.parentNode;

                        const panelCountSpan = this.closest('.card-body').querySelector('.panel-count');
                        const capacityMatch = panelCountSpan ? panelCountSpan.textContent.match(/(\d+)\s*\/\s*(\d+)/) : null;
                        const capacity = capacityMatch ? parseInt(capacityMatch[2], 10) : Infinity;
                        const currentCount = this.querySelectorAll('.panel-item').length;


                        if (currentCount >= capacity) {
                            alert('ظرفیت این کامیون تکمیل است!');
                            draggedItem = null;
                            return;
                        }


                        if (panelId && truckId && sourceContainer !== this) {
                            // --- AJAX call ---
                            fetch('api/assign-panel-to-truck.php', {
                                    /* ... same fetch call ... */
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                    },
                                    body: JSON.stringify({
                                        panel_id: panelId,
                                        truck_id: truckId
                                    })
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        // Update UI (same logic as main drop handler)
                                        const originalPanelElement = document.querySelector(`.panel-item[data-id="${panelId}"]`);
                                        if (originalPanelElement) originalPanelElement.remove();

                                        let panelToAdd = draggedItem;
                                        if (sourceContainer.id === 'available-panels') {
                                            panelToAdd.classList.add('assigned');
                                            const unassignBtn = document.createElement('button');
                                            unassignBtn.className = 'btn btn-sm btn-danger unassign-btn';
                                            unassignBtn.dataset.panelId = panelId;
                                            unassignBtn.innerHTML = '<i class="fa fa-times"></i>';
                                            unassignBtn.addEventListener('click', handleUnassign);

                                            if (!panelToAdd.querySelector('.panel-content')) {
                                                const contentDiv = document.createElement('div');
                                                contentDiv.className = 'panel-content';
                                                while (panelToAdd.firstChild) contentDiv.appendChild(panelToAdd.firstChild);
                                                panelToAdd.appendChild(contentDiv);
                                            }
                                            panelToAdd.appendChild(unassignBtn);
                                        } else {
                                            const existingBtn = panelToAdd.querySelector('.unassign-btn');
                                            if (existingBtn) {
                                                existingBtn.removeEventListener('click', handleUnassign);
                                                existingBtn.addEventListener('click', handleUnassign);
                                            }
                                        }

                                        this.appendChild(panelToAdd);
                                        updatePanelCount(this);
                                        if (sourceContainer.classList.contains('truck-container')) updatePanelCount(sourceContainer);
                                        updateAvailablePanelCount();

                                        const truckVis = this.closest('.truck-visual-container');
                                        if (truckVis) {
                                            truckVis.classList.add('loading');
                                            setTimeout(() => truckVis.classList.remove('loading'), 500);
                                        }
                                        applyTruckPanelFilters();


                                    } else {
                                        alert('خطا در تخصیص: ' + (data.message || 'خطای نامشخص'));
                                    }
                                })
                                .catch(error => {
                                    console.error('Assign Panel Error:', error);
                                    alert('خطا در ارتباط با سرور.');
                                })
                                .finally(() => {
                                    draggedItem = null;
                                });
                        } else {
                            draggedItem = null;
                        }

                    });
                }
            } // End if (!isUserReadOnly) for new truck listeners


            // Prepend the new truck element to the container
            truckContainerElement.insertBefore(newTruckElement, truckContainerElement.firstChild);
        }

        // --- Packing List Upload (Only if NOT read-only) ---
        const uploadBtn = document.getElementById('upload-packing-list-btn');

        // Check if the button exists AND the user has permission to upload
        if (uploadBtn && isUserCanUpload) {

            // ATTACH the event listener
            uploadBtn.addEventListener('click', function() {
                // This code runs ONLY when the button is clicked

                console.log("DEBUG: Upload button CLICKED!"); // Log that the click was registered

                // Find necessary elements *inside* the handler (safer)
                const packingListNumberInput = document.getElementById('packing-list-number');
                const fileInput = document.getElementById('packing-list-upload');
                const fileList = document.getElementById('packing-list-files'); // Although not used directly here, good to check

                // Verify elements exist
                if (!packingListNumberInput || !fileInput) {
                    console.error("DEBUG: Could not find PL# input or file input inside upload handler!");
                    alert("خطای داخلی - عناصر مورد نیاز آپلود یافت نشد.");
                    return; // Stop if elements are missing
                }

                const packingListNumber = packingListNumberInput.value.trim();

                // ---- Validation Checks ----
                if (!packingListNumber || packingListNumber.startsWith('(')) {
                    console.log("DEBUG: Upload prevented - Invalid PL Number:", packingListNumber);
                    alert('لطفاً ابتدا ارسال را ذخیره کنید تا شماره لیست بسته‌بندی مشخص شود.');
                    return; // Stop if PL# is invalid
                }
                console.log("DEBUG: PL Number valid:", packingListNumber);

                if (!fileInput.files || fileInput.files.length === 0) {
                    console.log("DEBUG: Upload prevented - No file selected.");
                    alert('لطفاً یک فایل برای آپلود انتخاب کنید.');
                    return; // Stop if no file chosen
                }
                console.log("DEBUG: File selected:", fileInput.files[0].name);

                // ---- Prepare data for sending ----
                const file = fileInput.files[0];
                const formData = new FormData();
                formData.append('packing_list', file); // Key must match $_FILES key in PHP
                formData.append('packing_list_number', packingListNumber); // Key must match $_POST key in PHP

                console.log("DEBUG: FormData prepared. Initiating fetch to api/upload-packing-list.php...");

                // ---- Visual feedback: Disable button during upload ----
                uploadBtn.disabled = true;
                uploadBtn.textContent = 'در حال آپلود...';

                // ---- Perform the AJAX upload request ----
                fetch('api/upload-packing-list.php', {
                        method: 'POST',
                        body: formData // Let the browser set the Content-Type for FormData
                    })
                    .then(response => {
                        // Check if response status is ok (e.g., 200)
                        if (!response.ok) {
                            // Try to get error message from response body if possible
                            return response.json().then(errData => {
                                throw new Error(errData.message || `خطای سرور: ${response.status}`);
                            }).catch(() => {
                                // If response wasn't JSON or failed to parse
                                throw new Error(`خطای سرور: ${response.status}`);
                            });
                        }
                        return response.json(); // Parse successful JSON response
                    })
                    .then(data => {
                        // Handle the JSON data from the server
                        console.log("DEBUG: Upload fetch response received:", data);
                        if (data.success && data.filename) {
                            alert('فایل با موفقیت آپلود شد.');
                            fetchPackingListFiles(packingListNumber); // Refresh the file list in the modal
                            fileInput.value = ''; // Clear the file input field
                        } else {
                            // Show error message from the server response
                            alert('خطا در آپلود فایل: ' + (data.message || 'خطای نامشخص از سرور.'));
                        }
                    })
                    .catch(error => {
                        // Handle network errors or errors thrown from .then()
                        console.error('Upload Fetch Error:', error);
                        alert('خطا در ارتباط با سرور یا پردازش آپلود: ' + error.message);
                    })
                    .finally(() => {
                        // This runs whether the fetch succeeded or failed
                        // Re-enable the button and reset text
                        uploadBtn.disabled = false;
                        uploadBtn.textContent = 'آپلود';
                        console.log("DEBUG: Upload process finished (finally block).");
                    });

            }); // End of the 'click' event listener function

            // Log that the listener was successfully attached ON PAGE LOAD
            console.log("DEBUG: Event listener successfully ATTACHED to #upload-packing-list-btn on page load.");

        } else {
            // Log why the listener was NOT attached ON PAGE LOAD
            console.warn("DEBUG: Event listener was NOT attached to #upload-packing-list-btn on page load.", {
                buttonElementExists: !!uploadBtn, // was the button found in the HTML?
                userCanUploadFlag: isUserCanUpload // did the user have permission?
            });
        } // End of if (uploadBtn && isUserCanUpload)
        // --- Fetch Packing List Files Function ---
        function fetchPackingListFiles(packingListNumber) {
            const fileList = document.getElementById('packing-list-files');
            if (!fileList) return; // Exit if element doesn't exist

            fileList.innerHTML = '<li><span class="text-muted">در حال بارگذاری لیست فایل‌ها...</span></li>'; // Loading message

            if (!packingListNumber) {
                fileList.innerHTML = '<li><span class="text-muted">شماره لیست بسته‌بندی مشخص نیست.</span></li>';
                return;
            }

            const API_GET_FILES_URL = 'api/get-packing-list-files.php';
            const fetchUrl = `${API_GET_FILES_URL}?packing_list_number=${encodeURIComponent(packingListNumber)}`;

            fetch(fetchUrl)
                .then(response => {
                    if (!response.ok) throw new Error(`خطای شبکه: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    fileList.innerHTML = ''; // Clear loading/previous
                    if (data.success && data.files && Array.isArray(data.files) && data.files.length > 0) {
                        data.files.forEach(filename => {
                            const listItem = document.createElement('li');
                            const downloadLink = document.createElement('a');
                            const encodedPL = encodeURIComponent(packingListNumber);
                            const encodedFN = encodeURIComponent(filename);
                            downloadLink.href = `download.php?packing_list_number=${encodedPL}&filename=${encodedFN}`;
                            downloadLink.textContent = filename;
                            downloadLink.target = "_blank";
                            listItem.appendChild(downloadLink);
                            fileList.appendChild(listItem);
                        });
                    } else {
                        fileList.innerHTML = '<li><span class="text-muted">هیچ فایلی برای این شماره یافت نشد.</span></li>';
                    }
                })
                .catch(error => {
                    console.error('Fetch Files Error:', error);
                    fileList.innerHTML = `<li><span class="text-danger">خطا در دریافت لیست فایل‌ها. ${error.message}</span></li>`;
                });
        }


        // --- Initialize Persian Date Pickers ---
        // (Ensure jQuery and persian-datepicker are loaded before this runs)
        $(document).ready(function() { // Use jQuery ready for datepicker initialization
            if (typeof $().persianDatepicker === 'function') {
                // Schedule Modal Date Picker
                $('#persian-date').persianDatepicker({
                    format: 'YYYY/MM/DD',
                    autoClose: true,
                    initialValue: false, // Start empty
                    persianDigit: false, // Use Latin digits
                    calendar: {
                        persian: {
                            locale: 'fa',
                            leapYearMode: 'astronomical'
                        }
                    },
                    onSelect: function(unix) {
                        const date = new Date(unix);
                        const year = date.getFullYear();
                        const month = String(date.getMonth() + 1).padStart(2, '0');
                        const day = String(date.getDate()).padStart(2, '0');
                        $('#shipping-date').val(`${year}-${month}-${day}`); // Update hidden Gregorian input
                    }
                });

                // Stand Return Date Picker (if it were editable, which it isn't currently)
                // $('#stands-return-date-display').persianDatepicker({ ... });

            } else {
                console.warn("Persian datepicker library not found. Using native date inputs.");
                // Fallback for Schedule Modal Date Picker
                const persianDateInput = document.getElementById('persian-date');
                if (persianDateInput) {
                    persianDateInput.type = 'date'; // Change type
                    persianDateInput.addEventListener('change', function() {
                        document.getElementById('shipping-date').value = this.value; // Sync hidden input
                    });
                }
                // Fallback for Stand Return Date (already readonly, so no interaction needed)
                // const returnDateInput = document.getElementById('stands-return-date-display');
                // if (returnDateInput) returnDateInput.type = 'date';
            }

            // Modal shown event to fetch files
            $('#scheduleModal').on('shown.bs.modal', function() {
                const plNumberInput = document.getElementById('packing-list-number');
                const plNumber = plNumberInput ? plNumberInput.value.trim() : null; // Get PL# safely
                if (plNumber && !plNumber.startsWith('(')) { // Only fetch if PL# seems valid
                    fetchPackingListFiles(plNumber);
                } else {
                    const fileList = document.getElementById('packing-list-files');
                    if (fileList) fileList.innerHTML = '<li><span class="text-muted">شماره لیست بسته‌بندی مشخص نیست یا هنوز ایجاد نشده است.</span></li>';
                }

                // --- Apply final permission state to upload controls ---
                const uploadInput = document.getElementById('packing-list-upload');
                const uploadBtnModal = document.getElementById('upload-packing-list-btn');
                const hasValidPL = plNumber && !plNumber.startsWith('('); // Check PL# validity again

                if (isUserCanUpload && hasValidPL) {
                    if (uploadInput) {
                        uploadInput.disabled = false;
                        uploadInput.style.backgroundColor = ''; // Ensure default style
                    }
                    if (uploadBtnModal) {
                        uploadBtnModal.disabled = false;
                        uploadBtnModal.style.backgroundColor = ''; // Ensure default style
                    }
                    console.log(`shown.bs.modal: Upload controls ENABLED (Role: ${currentUserRole}, PL Valid: ${hasValidPL})`);
                } else {
                    // Disable if user cannot upload OR if PL# is invalid/missing
                    if (uploadInput) {
                        uploadInput.disabled = true;
                        uploadInput.style.backgroundColor = '#e9ecef'; // Read-only style
                    }
                    if (uploadBtnModal) {
                        uploadBtnModal.disabled = true;
                        uploadBtnModal.style.backgroundColor = ''; // Default button style might handle disabled look
                    }
                    console.log(`shown.bs.modal: Upload controls DISABLED (Role: ${currentUserRole}, CanUpload: ${isUserCanUpload}, PL Valid: ${hasValidPL})`);
                }

                // --- REMOVED the complex query and re-disabling loop ---
                /*
                // OLD CODE - REMOVE THIS BLOCK:
                // Re-apply general read-only disabling for other fields if needed (redundant but safe)
                if (isUserReadOnly) {
                    const form = document.getElementById('schedule-form');
                    // Select elements *excluding* upload ones if user *can* upload
                    const query = (isUserCanUpload)
                       ? 'input:not([type="hidden"]):not(#packing-list-upload):not(#stands-returned):not(#stands-return-date-display), select, textarea'
                       : 'input:not([type="hidden"]):not(#stands-returned):not(#stands-return-date-display), select, textarea, #upload-packing-list-btn, #packing-list-upload';

                    const elements = form.querySelectorAll(query);
                    elements.forEach(el => {
                       if (!el.classList.contains('close') && el.getAttribute('data-dismiss') !== 'modal') {
                           el.disabled = true;
                           el.style.backgroundColor = '#e9ecef';
                       }
                    });
                     // Ensure save button is always disabled if read-only
                     const saveBtn = form.querySelector('#save-schedule');
                     if(saveBtn) saveBtn.disabled = true;
                }
                */
                // --- END REMOVED BLOCK ---


                // Ensure Save button state is correct based *only* on isUserReadOnly
                const saveBtn = document.getElementById('save-schedule');
                if (saveBtn) {
                    saveBtn.disabled = isUserReadOnly; // Directly use the main read-only flag
                }


            }); // End of shown.bs.modal


            // Modal hide event (optional: focus trigger button)
            $('#scheduleModal').on('hide.bs.modal', function(e) {
                const truckId = document.getElementById('truck-id').value;
                const scheduleButton = document.querySelector(`.schedule-btn[data-truck-id="${truckId}"]`);
                if (scheduleButton) {
                    // scheduleButton.focus(); // May cause unexpected scrolling
                }
            });

        }); // End jQuery document ready


    }); // End DOMContentLoaded
</script>

<?php require_once 'footer.php'; ?>