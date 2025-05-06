<?php
// activity_log.php

require_once __DIR__ . '/../../sercon/config.php';
secureSession();
require_once 'includes/jdf.php'; // Or your date utility file
require_once 'includes/functions.php'; // For connectDB

// --- Admin-Only Access Check ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: unauthorized.php');
    exit();
}

if (!function_exists('gregorianToShamsi')) {
    function gregorianToShamsi($g_date)
    {
        if (empty($g_date) || $g_date == '0000-00-00' || $g_date == null || !preg_match('/^\d{4}-\d{2}-\d{2}/', $g_date)) {
            return ''; // Invalid input format or empty
        }
        $date_part = explode(' ', $g_date)[0]; // Take only date part
        list($g_y, $g_m, $g_d) = explode('-', $date_part);
        // Use the existing jdf jalali_to_gregorian internal logic (or your preferred library)
        // This is a placeholder for the actual conversion logic from jdf.php
        // Ensure your included jdf.php has the `jdate` or equivalent functions used below
        if (function_exists('jdate')) {
            // Assuming jdate can format from a timestamp
            $timestamp = strtotime($g_date);
            if ($timestamp === false) return ''; // Invalid date for strtotime
            return jdate('Y/m/d', $timestamp, '', 'Asia/Tehran', 'en'); // 'en' for latin digits output
        } else {
            // Fallback or error if jdate isn't available - your previous manual logic could go here
            error_log("jdate function not found in jdf.php for gregorianToShamsi");
            return ''; // Or implement your manual conversion
        }
    }
}

// Function to convert Shamsi (YYYY/MM/DD) to Gregorian (YYYY-MM-DD)
if (!function_exists('shamsiToGregorian')) {
    function shamsiToGregorian($sh_date)
    {
        if (empty($sh_date) || !preg_match('/^\d{4}\/\d{2}\/\d{2}$/', toLatinDigitsPhp($sh_date))) {
            return ''; // Invalid input format or empty
        }
        $sh_date_latin = toLatinDigitsPhp($sh_date);
        list($sh_y, $sh_m, $sh_d) = explode('/', $sh_date_latin);
        // Use the existing jdf internal logic (or your preferred library)
        if (function_exists('jalali_to_gregorian')) {
            $g_arr = jalali_to_gregorian((int)$sh_y, (int)$sh_m, (int)$sh_d);
            if ($g_arr) {
                return sprintf('%04d-%02d-%02d', $g_arr[0], $g_arr[1], $g_arr[2]);
            } else {
                error_log("jalali_to_gregorian failed for: " . $sh_date);
                return '';
            }
        } else {
            error_log("jalali_to_gregorian function not found in jdf.php");
            return '';
        }
    }
}

// Function to convert Persian/Arabic numerals to Latin (0-9)
if (!function_exists('toLatinDigitsPhp')) {
    function toLatinDigitsPhp($num)
    {
        if ($num === null || !is_scalar($num)) return '';
        return str_replace(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], range(0, 9), strval($num));
    }
}

// Function to convert Latin numerals (0-9) to Farsi
if (!function_exists('toFarsiDigits')) {
    function toFarsiDigits($num)
    {
        if ($num === null) return '';
        $farsi_array   = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $english_array = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($english_array, $farsi_array, (string)$num);
    }
}
// Set timezone for all date operations
date_default_timezone_set('Asia/Tehran');

// --- Database Connection ---
try {
    $pdo = connectDB();
} catch (PDOException $e) {
    logError("Database connection error in activity_log.php: " . $e->getMessage());
    die("یک ارور دیتابیسی رخ داد. لطفا بعداً دوباره تلاش کنید.");
}

// --- Get All Users ---
try {
    $userStmt = $pdo->query("SELECT id, username, first_name, last_name FROM users ORDER BY username");
    $users = $userStmt->fetchAll();
} catch (PDOException $e) {
    logError("Error fetching users: " . $e->getMessage());
    $users = [];
}


// --- Get Filters & Pagination ---
$selectedUserId     = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT) ?: null;
$selectedActionType = filter_input(INPUT_GET, 'action_type', FILTER_SANITIZE_STRING) ?: null;
$selectedPanelId    = filter_input(INPUT_GET, 'panel_id', FILTER_VALIDATE_INT) ?: null;
$selectedStepName   = filter_input(INPUT_GET, 'step_name', FILTER_SANITIZE_STRING) ?: null;
$selectedStatus     = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING) ?: null;
// Add Date Filters (using Shamsi input, converted to Gregorian for query)
$filterDateFromSh = filter_input(INPUT_GET, 'date_from', FILTER_SANITIZE_STRING) ?: null;
$filterDateToSh   = filter_input(INPUT_GET, 'date_to', FILTER_SANITIZE_STRING) ?: null;

$filterDateFromGr = !empty($filterDateFromSh) ? shamsiToGregorian($filterDateFromSh) : null;
$filterDateToGr   = !empty($filterDateToSh) ? shamsiToGregorian($filterDateToSh) : null;


$itemsPerPage = 25; // Increase items per page slightly?
$currentPage  = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
$offset       = ($currentPage - 1) * $itemsPerPage;

// --- Build WHERE Clause & Params ---
$whereClauses = [];
$params       = [];

if ($selectedUserId) {
    $whereClauses[] = "user_id = ?";
    $params[] = $selectedUserId;
}
if ($selectedActionType) {
    $whereClauses[] = "action_type LIKE ?";
    $params[] = "%" . $selectedActionType . "%";
} // Use LIKE for partial match?
if ($selectedPanelId) {
    $whereClauses[] = "panel_id = ?";
    $params[] = $selectedPanelId;
}
if ($selectedStepName) {
    $whereClauses[] = "step_name LIKE ?";
    $params[] = "%" . $selectedStepName . "%";
} // Use LIKE for partial match?
if ($selectedStatus) {
    $whereClauses[] = "status LIKE ?";
    $params[] = "%" . $selectedStatus . "%";
} // Use LIKE for partial match?
if ($filterDateFromGr) {
    $whereClauses[] = "action_time >= ?";
    $params[] = $filterDateFromGr . " 00:00:00";
} // Compare against timestamp
if ($filterDateToGr) {
    $whereClauses[] = "action_time <= ?";
    $params[] = $filterDateToGr . " 23:59:59";
}   // Compare against timestamp


// --- Count Query ---
// Use the ALIASED derived table for filtering count
$countQuery = "SELECT COUNT(*) FROM (
    SELECT user_id, activity_type AS action_type, panel_id, step AS step_name, action AS status, timestamp AS action_time FROM activity_log
    UNION ALL
    SELECT pd.user_id, pd.step_name AS action_type, pd.panel_id, pd.step_name, pd.status, pd.updated_at AS action_time FROM hpc_panel_details pd WHERE pd.status IS NOT NULL
    UNION ALL
    SELECT user_id, 'panel_status_change' AS action_type, id AS panel_id, address AS step_name, status, assigned_date AS action_time FROM hpc_panels WHERE status IS NOT NULL
) AS combined_activities"; // Alias the combined result

if (!empty($whereClauses)) {
    $countQuery .= " WHERE " . implode(" AND ", $whereClauses);
}
try {
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalItems = $countStmt->fetchColumn();
} catch (PDOException $e) {
    logError("Error fetching count: " . $e->getMessage());
    $totalItems = 0;
}

// --- Main Activity Query ---
// Select specific columns, apply WHERE to the derived table
$activityQuery = "SELECT
        source, user_id, action_type, panel_id, step_name, status, action_time,
        rejection_reason, username, panel_address
    FROM (
        SELECT 'activity_log' AS source, al.user_id, al.activity_type AS action_type, al.panel_id, al.step AS step_name, al.action AS status, al.timestamp AS action_time, NULL AS rejection_reason, u.username, p.address AS panel_address
        FROM activity_log al LEFT JOIN users u ON al.user_id = u.id LEFT JOIN hpc_panels p ON al.panel_id = p.id
        UNION ALL
        SELECT 'panel_details' AS source, pd.user_id, pd.step_name AS action_type, pd.panel_id, pd.step_name, pd.status, pd.updated_at AS action_time, pd.rejection_reason, u.username, p.address AS panel_address
        FROM hpc_panel_details pd INNER JOIN hpc_panels p ON pd.panel_id = p.id LEFT JOIN users u ON pd.user_id = u.id WHERE pd.status IS NOT NULL
        UNION ALL
        SELECT 'panels' AS source, p.user_id, 'panel_status_change' AS action_type, p.id AS panel_id, p.address AS step_name, p.status, p.assigned_date AS action_time, NULL AS rejection_reason, u.username, p.address AS panel_address
        FROM hpc_panels p LEFT JOIN users u ON p.user_id = u.id WHERE p.status IS NOT NULL
    ) AS combined_activities"; // Alias the combined result

if (!empty($whereClauses)) {
    $activityQuery .= " WHERE " . implode(" AND ", $whereClauses);
}
$activityQuery .= " ORDER BY action_time DESC LIMIT ?, ?";

try {
    $stmt = $pdo->prepare($activityQuery);
    // Bind filter parameters
    $i = 1;
    foreach ($params as $param) {
        $paramType = is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($i, $param, $paramType);
        $i++;
    }
    // Bind offset and limit
    $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
    $stmt->bindValue($i, $itemsPerPage, PDO::PARAM_INT);

    $stmt->execute();
    $activities = $stmt->fetchAll();
} catch (PDOException $e) {
    logError("Error fetching combined activity log: " . $e->getMessage());
    $activities = [];
}


$totalPages = ceil($totalItems / $itemsPerPage);

$pageTitle = "لاگ فعالیت‌های کاربران";
require_once 'header.php'; // Include Bootstrap via header
?>

<!-- Include Persian Datepicker JS/CSS if not already in header.php -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css">
<style>
    /* Add some padding and spacing */
    .filter-card .card-body {
        padding: 1rem;
    }

    .filter-form-row {
        margin-bottom: 0.75rem;
    }

    .table-responsive {
        margin-top: 1.5rem;
    }

    .table th,
    .table td {
        white-space: normal;
        word-wrap: break-word;
        font-size: 0.8rem;
    }

    /* Allow wrapping */
    .pagination .page-link {
        font-size: 0.85rem;
    }

    .pagination .page-item select.page-jump {
        display: inline-block;
        width: auto;
        padding: 0.375rem 0.5rem;
        font-size: 0.85rem;
        height: calc(1.5em + 0.75rem + 2px);
        margin: 0 0.25rem;
        vertical-align: middle;
    }

    /* Ensure datepicker button aligns well */
    .input-group .pwt-btn-calendar {
        height: calc(1.5em + 0.75rem + 2px);
    }
</style>

<div class="container-fluid mt-4">
    <h4 class="mb-3"><?php echo $pageTitle; ?></h4>

    <!-- Filters Card -->
    <div class="card filter-card mb-4 shadow-sm">
        <div class="card-header">
            <i class="bi bi-funnel-fill me-1"></i> فیلترها
        </div>
        <div class="card-body">
            <form action="" method="get">
                <div class="row filter-form-row g-3">
                    <div class="col-md-6 col-lg-4">
                        <label for="user_id" class="form-label">کاربر:</label>
                        <select name="user_id" id="user_id" class="form-select form-select-sm">
                            <option value="">-- همه --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo (int)$user['id']; ?>" <?php selected($selectedUserId, $user['id']); ?>>
                                    <?php echo htmlspecialchars($user['username'] . " ({$user['first_name']} {$user['last_name']})"); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label for="action_type" class="form-label">نوع عملیات:</label>
                        <input type="text" name="action_type" id="action_type" value="<?php echo htmlspecialchars($selectedActionType ?? ''); ?>" class="form-control form-control-sm" placeholder="مثلاً: panel_status_change">
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label for="panel_id" class="form-label">شناسه پنل:</label>
                        <input type="number" name="panel_id" id="panel_id" value="<?php echo htmlspecialchars($selectedPanelId ?? ''); ?>" class="form-control form-control-sm" placeholder="مثلاً: 123">
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label for="step_name" class="form-label">مرحله/آدرس:</label>
                        <input type="text" name="step_name" id="step_name" value="<?php echo htmlspecialchars($selectedStepName ?? ''); ?>" class="form-control form-control-sm" placeholder="مثلاً: Mesh یا S-C1-D2">
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label for="status" class="form-label">وضعیت:</label>
                        <input type="text" name="status" id="status" value="<?php echo htmlspecialchars($selectedStatus ?? ''); ?>" class="form-control form-control-sm" placeholder="مثلاً: completed">
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label for="date_from" class="form-label">از تاریخ:</label>
                        <input type="text" name="date_from" id="date_from" value="<?php echo htmlspecialchars($filterDateFromSh ?? ''); ?>" class="form-control form-control-sm persian-datepicker" autocomplete="off">
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <label for="date_to" class="form-label">تا تاریخ:</label>
                        <input type="text" name="date_to" id="date_to" value="<?php echo htmlspecialchars($filterDateToSh ?? ''); ?>" class="form-control form-control-sm persian-datepicker" autocomplete="off">
                    </div>
                </div>
                <div class="mt-3 d-flex justify-content-end">
                    <a href="<?php echo strtok($_SERVER["REQUEST_URI"], '?'); ?>" class="btn btn-secondary btn-sm me-2">پاک کردن فیلتر</a>
                    <button type="submit" class="btn btn-primary btn-sm">اعمال فیلتر</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Activity Log Table -->
    <div class="card shadow-sm">
        <div class="card-header">
            <i class="bi bi-list-ul me-1"></i> نتایج (<?php echo toFarsiDigits($totalItems); ?> مورد)
        </div>
        <div class="card-body p-0">
            <?php if (!empty($activities)): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-bordered mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>منبع</th>
                                <th>کاربر</th>
                                <th>نوع عملیات</th>
                                <th>شناسه/آدرس پنل</th>
                                <!-- <th>مرحله</th> -->
                                <th>وضعیت</th>
                                <th>دلیل رد</th>
                                <th>زمان</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activities as $activity): ?>
                                <tr>
                                    <td>
                                        <?php
                                        $sourceText = [
                                            'activity_log'   => 'لاگ اصلی',
                                            'panel_details'  => 'جزئیات',
                                            'panels'         => 'وضعیت پنل'
                                        ][$activity['source']] ?? $activity['source'];
                                        echo htmlspecialchars($sourceText);
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($activity['username'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($activity['action_type'] ?? '-'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($activity['panel_id'] ?? '-'); ?>
                                        <?php if ($activity['source'] !== 'panel_details' && !empty($activity['panel_address'])): ?>
                                            <br><small class="text-muted">(<?php echo htmlspecialchars($activity['panel_address']); ?>)</small>
                                        <?php elseif ($activity['source'] === 'panel_details' && !empty($activity['step_name'])): ?>
                                            <br><small class="text-muted">(<?php echo htmlspecialchars($activity['step_name']); ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <!-- <td><?php // echo htmlspecialchars($activity['step_name'] ?? '-'); 
                                                ?></td> -->
                                    <td><?php echo htmlspecialchars($activity['status'] ?? '-'); ?></td>
                                    <td class="text-danger"><?php echo htmlspecialchars($activity['rejection_reason'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars(gregorianToShamsi($activity['action_time']) . ' ' . date('H:i', strtotime($activity['action_time']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-warning m-3">هیچ فعالیتی برای فیلترهای انتخاب شده یافت نشد.</div>
            <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-light border-top-0">
                <!-- Pagination (Bootstrap 5) -->
                <nav aria-label="Page navigation">
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <!-- Previous -->
                        <li class="page-item <?php echo ($currentPage <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $currentPage - 1])); ?>">قبلی</a>
                        </li>

                        <!-- First Page -->
                        <li class="page-item <?php echo ($currentPage == 1) ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">۱</a>
                        </li>

                        <?php if ($currentPage > 3): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>

                        <!-- Current Page Number Selector -->
                        <li class="page-item">
                            <select class="form-select form-select-sm page-jump" onchange="window.location.href=this.value">
                                <?php
                                $start = max(1, $currentPage - 2);
                                $end = min($totalPages, $currentPage + 2);
                                if ($start === 1 && $end < $totalPages) $end = min($totalPages, $start + 4);
                                if ($end === $totalPages && $start > 1) $start = max(1, $end - 4);

                                for ($i = $start; $i <= $end; $i++):
                                    $pageQuery = http_build_query(array_merge($_GET, ['page' => $i]));
                                ?>
                                    <option value="?<?php echo $pageQuery; ?>" <?php echo ($i == $currentPage) ? 'selected' : ''; ?>>
                                        <?php echo toFarsiDigits($i); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </li>


                        <?php if ($currentPage < $totalPages - 2): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>

                        <!-- Last Page -->
                        <?php if ($totalPages > 1): ?>
                            <li class="page-item <?php echo ($currentPage == $totalPages) ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>"><?php echo toFarsiDigits($totalPages); ?></a>
                            </li>
                        <?php endif; ?>

                        <!-- Next -->
                        <li class="page-item <?php echo ($currentPage >= $totalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $currentPage + 1])); ?>">بعدی</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div> <!-- End Records Card -->

</div> <!-- End Container -->

<!-- Include Persian Datepicker JS -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script> <!-- If not already included -->
<script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js"></script>
<script>
    $(document).ready(function() {
        $(".persian-datepicker").persianDatepicker({
            format: 'YYYY/MM/DD',
            autoClose: true,
            initialValue: false, // Don't set initial value unless needed
            calendar: {
                persian: {
                    locale: 'fa',
                    leapYearMode: 'astronomical'
                }
            },
            toolbox: {
                calendarSwitch: {
                    enabled: false
                } // Disable calendar switch if not needed
            }
        });
    });
</script>

<?php require_once 'footer.php'; ?>

<?php
// Helper function for select options
function selected($val1, $val2)
{
    if ($val1 == $val2) echo ' selected';
}
?>