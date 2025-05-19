<?php
// stand_return_tracking.php
ini_set('display_errors', 1); // Development only
error_reporting(E_ALL);
ob_start(); // Start output buffering

require_once __DIR__ . '/../../sercon/bootstrap.php'; // Adjust path if needed
require_once __DIR__ . '/includes/jdf.php';      // For jdate()
require_once __DIR__ . '/includes/functions.php'; // For secureSession, escapeHtml etc.

secureSession();
$expected_project_key = 'arad'; // HARDCODED FOR THIS FILE
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
$report_key = 'stand_return_tracking'; // HARDCODED FOR THIS FILE
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/stand_return_tracking.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// Authentication Check (Adjust roles as needed)
$allowed_roles = ['admin', 'superuser', 'supervisor', 'planner']; // Example roles
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Location: /login'); // Redirect to your login page
    exit('Access Denied.');
}
$current_user_id = $_SESSION['user_id'];
$pageTitle = 'پروژه فرشته - ثبت و پیگیری خرک‌های برگشتی ';

// DB Connection
try {

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Enable exceptions for errors
} catch (PDOException $e) {
    error_log("DB Connection failed in stand_return_tracking.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده.");
}

// --- Helper Functions (Copy from your previous code) ---
if (!function_exists('toLatinDigitsPhp')) {
    /**
     * Converts Persian/Arabic numerals in a string to Latin numerals.
     * @param string|int|float|null $num The input string or number.
     * @return string The string with numerals converted to Latin digits.
     */
    function toLatinDigitsPhp($num)
    {
        if ($num === null || !is_scalar($num)) return '';
        return str_replace(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], range(0, 9), strval($num));
    }
}
// ***** END: Add the provided helper function *****

/**
 * Helper function to convert Persian (Jalali) date to Gregorian date
 * Assumes input $jalaliDate uses Latin numerals (e.g., '1403/01/20').
 * @param string $jalaliDate Persian date in Y/m/d format (with Latin digits).
 * @return string|null Gregorian date in Y-m-d format, or null on failure.
 */
function jalali_to_gregorian_date($jalaliDate)
{
    if (empty($jalaliDate)) return null;

    // Split Persian date components
    $dateParts = explode('/', $jalaliDate);

    if (count($dateParts) != 3) {
        error_log("Invalid date format passed to jalali_to_gregorian_date (expected Y/m/d with Latin digits): " . $jalaliDate);
        return null; // Invalid format
    }

    // Ensure components are numeric after potential conversion
    if (!is_numeric($dateParts[0]) || !is_numeric($dateParts[1]) || !is_numeric($dateParts[2])) {
        error_log("Non-numeric date parts after numeral conversion: " . $jalaliDate);
        return null;
    }

    $jYear = intval($dateParts[0]);
    $jMonth = intval($dateParts[1]);
    $jDay = intval($dateParts[2]);

    // Basic validation for Jalali date components
    if ($jYear < 1300 || $jYear > 1500 || $jMonth < 1 || $jMonth > 12 || $jDay < 1 || $jDay > 31) {
        error_log("Invalid Jalali date components: Year=$jYear, Month=$jMonth, Day=$jDay (Original input: $jalaliDate)");
        return null;
    }


    // Use jdf.php's function to convert to Gregorian
    // Ensure jdf.php's function can handle integer inputs
    try {
        $gregorian = jalali_to_gregorian($jYear, $jMonth, $jDay);
        // Check if the result is a valid array
        if (!is_array($gregorian) || count($gregorian) !== 3) {
            error_log("jalali_to_gregorian function returned invalid result for $jYear-$jMonth-$jDay");
            return null;
        }
        // Basic validation of Gregorian result
        if (!checkdate($gregorian[1], $gregorian[2], $gregorian[0])) {
            error_log("Invalid Gregorian date produced by conversion: {$gregorian[0]}-{$gregorian[1]}-{$gregorian[2]}");
            return null;
        }
    } catch (Exception $e) {
        error_log("Error during jdf conversion for $jYear-$jMonth-$jDay: " . $e->getMessage());
        return null; // Conversion failed
    }


    // Format as Y-m-d for MySQL
    return sprintf('%04d-%02d-%02d', $gregorian[0], $gregorian[1], $gregorian[2]);
}

// --- End Helper Functions ---


$errors = [];
$success_message = '';

// ----- CSRF Protection -----
// Generate CSRF Token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Validate CSRF token on POST requests
function validateCsrfToken()
{
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF token validation failed");
        return false;
    }
    return true;
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if any form was submitted
    if (isset($_POST['submit_return'])) {
        // Add CSRF validation
        if (!validateCsrfToken()) {
            $errors[] = "درخواست نامعتبر. لطفا صفحه را مجددا بارگذاری نمایید.";
        } else {
            $returned_count = filter_input(INPUT_POST, 'returned_count', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $return_date_persian = filter_input(INPUT_POST, 'return_date_persian', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

            // Validation
            if ($returned_count === false || $returned_count <= 0) {
                $errors[] = 'تعداد خرک‌های برگشتی باید یک عدد صحیح مثبت باشد.';
            }
            if (empty($return_date_persian)) {
                $errors[] = 'تاریخ برگشت الزامی است.';
            }

            $return_date_gregorian = null;
            if (empty($errors) && !empty($return_date_persian)) {
                $latin_date = toLatinDigitsPhp($return_date_persian);
                $return_date_gregorian = jalali_to_gregorian_date($latin_date);
                if (!$return_date_gregorian) {
                    $errors[] = 'فرمت تاریخ برگشت نامعتبر است. لطفا از انتخابگر تاریخ استفاده کنید (YYYY/MM/DD).';
                    error_log("Failed to convert return date: " . $return_date_persian . " (Attempted with: " . $latin_date . ")");
                }
            }

            // If no validation errors, insert into DB
            if (empty($errors)) {
                try {
                    $sql = "INSERT INTO stand_returns (returned_count, return_date, description, recorded_by_user_id)
                            VALUES (:count, :date, :desc, :user_id)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindParam(':count', $returned_count, PDO::PARAM_INT);
                    $stmt->bindParam(':date', $return_date_gregorian, PDO::PARAM_STR);
                    $stmt->bindParam(':desc', $description, PDO::PARAM_STR);
                    $stmt->bindParam(':user_id', $current_user_id, PDO::PARAM_INT);

                    if ($stmt->execute()) {
                        // Use session flash message for success after redirect
                        $_SESSION['flash_success'] = "تعداد " . $returned_count . " خرک برگشتی با موفقیت ثبت شد.";
                        // Redirect to prevent double submission (PRG Pattern)
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    } else {
                        $errors[] = "خطا در ذخیره اطلاعات در پایگاه داده.";
                        $errorInfo = $stmt->errorInfo();
                        error_log("DB Insert Error (stand_returns): " . print_r($errorInfo, true));
                    }
                } catch (PDOException $e) {
                    $errors[] = "خطای پایگاه داده: " . $e->getMessage();
                    error_log("PDOException saving stand return: " . $e->getMessage());
                }
            }
        }
    }
    // --- End Form Submission Handling ---

    // --- Handle Edit Submission ---
    elseif (isset($_POST['edit_return'])) {
        // Add CSRF validation
        if (!validateCsrfToken()) {
            $errors[] = "درخواست نامعتبر. لطفا صفحه را مجددا بارگذاری نمایید.";
        } else {
            $record_id = filter_input(INPUT_POST, 'edit_id', FILTER_VALIDATE_INT);
            $returned_count = filter_input(INPUT_POST, 'edit_returned_count', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $return_date_persian = filter_input(INPUT_POST, 'edit_return_date_persian', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $description = trim(filter_input(INPUT_POST, 'edit_description', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

            // Validation
            if (!$record_id) {
                $errors[] = 'شناسه رکورد نامعتبر است.';
            }
            if ($returned_count === false || $returned_count <= 0) {
                $errors[] = 'تعداد خرک‌های برگشتی باید یک عدد صحیح مثبت باشد.';
            }
            if (empty($return_date_persian)) {
                $errors[] = 'تاریخ برگشت الزامی است.';
            }

            $return_date_gregorian = null;
            if (empty($errors) && !empty($return_date_persian)) {
                $latin_date = toLatinDigitsPhp($return_date_persian);
                $return_date_gregorian = jalali_to_gregorian_date($latin_date);
                if (!$return_date_gregorian) {
                    $errors[] = 'فرمت تاریخ برگشت نامعتبر است. لطفا از انتخابگر تاریخ استفاده کنید (YYYY/MM/DD).';
                }
            }

            // If no validation errors, update DB
            if (empty($errors)) {
                try {
                    $sql = "UPDATE stand_returns 
                            SET returned_count = :count, 
                                return_date = :date, 
                                description = :desc,
                                updated_by_user_id = :user_id,
                                updated_at = NOW()
                            WHERE id = :id";

                    $stmt = $pdo->prepare($sql);
                    $stmt->bindParam(':count', $returned_count, PDO::PARAM_INT);
                    $stmt->bindParam(':date', $return_date_gregorian, PDO::PARAM_STR);
                    $stmt->bindParam(':desc', $description, PDO::PARAM_STR);
                    $stmt->bindParam(':user_id', $current_user_id, PDO::PARAM_INT);
                    $stmt->bindParam(':id', $record_id, PDO::PARAM_INT);

                    if ($stmt->execute()) {
                        $_SESSION['flash_success'] = "رکورد خرک برگشتی با موفقیت ویرایش شد.";
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    } else {
                        $errors[] = "خطا در بروزرسانی اطلاعات در پایگاه داده.";
                        $errorInfo = $stmt->errorInfo();
                        error_log("DB Update Error (stand_returns): " . print_r($errorInfo, true));
                    }
                } catch (PDOException $e) {
                    $errors[] = "خطای پایگاه داده در ویرایش رکورد: " . $e->getMessage();
                    error_log("PDOException updating stand return: " . $e->getMessage());
                }
            }
        }
    }

    // --- Handle Delete Submission ---
    elseif (isset($_POST['delete_return'])) {
        // Add CSRF validation
        if (!validateCsrfToken()) {
            $errors[] = "درخواست نامعتبر. لطفا صفحه را مجددا بارگذاری نمایید.";
        } else {
            $record_id = filter_input(INPUT_POST, 'delete_id', FILTER_VALIDATE_INT);

            // Validation
            if (!$record_id) {
                $errors[] = 'شناسه رکورد نامعتبر است.';
            }

            // Delete record if validation passes
            if (empty($errors)) {
                try {
                    $sql = "DELETE FROM stand_returns WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindParam(':id', $record_id, PDO::PARAM_INT);

                    if ($stmt->execute()) {
                        $_SESSION['flash_success'] = "رکورد خرک برگشتی با موفقیت حذف شد.";
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    } else {
                        $errors[] = "خطا در حذف رکورد از پایگاه داده.";
                        $errorInfo = $stmt->errorInfo();
                        error_log("DB Delete Error (stand_returns): " . print_r($errorInfo, true));
                    }
                } catch (PDOException $e) {
                    $errors[] = "خطای پایگاه داده در حذف رکورد: " . $e->getMessage();
                    error_log("PDOException deleting stand return: " . $e->getMessage());
                }
            }
        }
    }
}

// Check for flash messages from redirect
if (isset($_SESSION['flash_success'])) {
    $success_message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']); // Clear the message after displaying
}


// --- Fetch Data for Display ---
$total_stands = 0;
$total_sent = 0;
$total_returned = 0;
$stand_returns_history = [];

try {
    // 1. Get Total Stands Setting
    $stmt_total = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'total_stands' LIMIT 1");
    $total_stands_result = $stmt_total->fetch(PDO::FETCH_ASSOC);
    if ($total_stands_result && is_numeric($total_stands_result['setting_value'])) {
        $total_stands = (int)$total_stands_result['setting_value'];
    } else {
        error_log("Setting 'total_stands' not found or invalid.");
        // Optionally set an error message for display
    }

    // Handle Update Total Stands Form Submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_total_stands'])) {
        if (!validateCsrfToken()) {
            $errors[] = "درخواست نامعتبر. لطفا صفحه را مجددا بارگذاری نمایید.";
        } else {
            $new_total_stands = filter_input(INPUT_POST, 'new_total_stands', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($new_total_stands === false || $new_total_stands <= 0) {
                $errors[] = 'کل خرک‌ها باید یک عدد صحیح مثبت باشد.';
            } else {
                try {
                    $stmt_update = $pdo->prepare("UPDATE app_settings SET setting_value = :value WHERE setting_key = 'total_stands'");
                    $stmt_update->bindParam(':value', $new_total_stands, PDO::PARAM_INT);
                    if ($stmt_update->execute()) {
                        $_SESSION['flash_success'] = "کل خرک‌ها با موفقیت به‌روزرسانی شد.";
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    } else {
                        $errors[] = "خطا در به‌روزرسانی کل خرک‌ها.";
                        error_log("Failed to update total_stands setting.");
                    }
                } catch (PDOException $e) {
                    $errors[] = "خطای پایگاه داده: " . $e->getMessage();
                    error_log("PDOException updating total_stands: " . $e->getMessage());
                }
            }
        }
    }

    // 2. Get Total Stands Sent (Sum from shipments)
    $stmt_sent = $pdo->query("SELECT SUM(IFNULL(stands_sent, 0)) as total_sent FROM shipments");
    $total_sent_result = $stmt_sent->fetch(PDO::FETCH_ASSOC);
    $total_sent = ($total_sent_result && $total_sent_result['total_sent'] !== null) ? (int)$total_sent_result['total_sent'] : 0;

    // 3. Get Total Stands Returned (Sum from the new table)
    $stmt_returned = $pdo->query("SELECT SUM(IFNULL(returned_count, 0)) as total_returned FROM stand_returns");
    $total_returned_result = $stmt_returned->fetch(PDO::FETCH_ASSOC);
    $total_returned = ($total_returned_result && $total_returned_result['total_returned'] !== null) ? (int)$total_returned_result['total_returned'] : 0;

    // 4. Fetch Return History
    $stmt_history = $pdo->query("
        SELECT sr.*, u.first_name, u.last_name
        FROM stand_returns sr
        LEFT JOIN hpc_common.users u ON sr.recorded_by_user_id = u.id
        ORDER BY sr.return_date DESC, sr.recorded_at DESC
        LIMIT 500 -- Add a limit for performance if the table grows large
    ");
    $stand_returns_history = $stmt_history->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = "خطا در بارگذاری اطلاعات وضعیت خرک‌ها: " . $e->getMessage();
    error_log("Error fetching stand status/history: " . $e->getMessage());
}

// --- Calculate Current Status ---
$currently_out = max(0, $total_sent - $total_returned); // Ensure non-negative
$currently_available = max(0, $total_stands - $currently_out); // Ensure non-negative


// --- Include Header ---
require_once 'header.php'; // Assuming header.php sets up HTML structure, includes CSS/JS assets

// --- Display Messages ---
if (!empty($errors)): ?>
    <div class="alert alert-danger" role="alert">
        <strong>خطا:</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?php echo escapeHtml($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($success_message)): ?>
    <div class="alert alert-success" role="alert">
        <?php echo escapeHtml($success_message); ?>
    </div>
<?php endif; ?>


<div class="container mt-4">
    <h2><i class="bi bi-truck-front"></i> ثبت و پیگیری خرک‌های برگشتی</h2>

    <!-- Stand Status Display -->
    <div class="card mb-4 stand-status-card">
        <div class="card-header bg-info text-white">
            <i class="bi bi-bar-chart-line-fill"></i> وضعیت فعلی خرک‌ها
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col">
                    <div class="stat-box">
                        <div class="stat-value"><?php echo escapeHtml($total_stands); ?></div>
                        <div class="stat-label">کل خرک‌ها</div>
                        <button type="button" class="btn btn-sm btn-warning mt-2" data-bs-toggle="modal" data-bs-target="#editTotalStandsModal">
                            ویرایش
                        </button>
                    </div>

                    <!-- Edit Total Stands Modal -->
                    <div class="modal fade" id="editTotalStandsModal" tabindex="-1" aria-labelledby="editTotalStandsModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-warning text-white">
                                    <h5 class="modal-title" id="editTotalStandsModalLabel">ویرایش کل خرک‌ها</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label for="new_total_stands" class="form-label">کل خرک‌ها <span class="text-danger">*</span></label>
                                            <input type="number" class="form-control" id="new_total_stands" name="new_total_stands" min="1" required value="<?php echo escapeHtml($total_stands); ?>">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                                        <button type="submit" name="update_total_stands" class="btn btn-warning">ذخیره تغییرات</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-box text-danger">
                        <div class="stat-value"><?php echo escapeHtml($currently_out); ?></div>
                        <div class="stat-label">خرک‌های بیرون (ارسال شده)</div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-box text-primary">
                        <div class="stat-value"><?php echo escapeHtml($total_returned); ?></div>
                        <div class="stat-label">مجموع برگشت داده شده</div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-box text-success">
                        <div class="stat-value"><?php echo escapeHtml($currently_available); ?></div>
                        <div class="stat-label">خرک‌های موجود در کارخانه</div>
                    </div>
                </div>
            </div>
            <hr>
            <p class="text-muted small text-center mb-0">
                کل ارسال شده: <?php echo escapeHtml($total_sent); ?> |
                مجموع برگشتی ثبت شده: <?php echo escapeHtml($total_returned); ?>
            </p>
        </div>
    </div>


    <!-- Return Entry Form -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <i class="bi bi-plus-circle-fill"></i> ثبت برگشت خرک جدید
        </div>
        <div class="card-body">
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" id="return-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="returned_count" class="form-label">تعداد برگشتی <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="returned_count" name="returned_count" min="1" required
                            value="<?php echo isset($_POST['returned_count']) ? escapeHtml($_POST['returned_count']) : ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="return_date_persian" class="form-label">تاریخ برگشت <span class="text-danger">*</span></label>
                        <input type="text" class="form-control date-input-persian" id="return_date_persian" name="return_date_persian" placeholder="YYYY/MM/DD" required autocomplete="off"
                            value="<?php echo isset($_POST['return_date_persian']) ? escapeHtml($_POST['return_date_persian']) : ''; ?>">
                        <!-- No hidden field needed if PHP converts on POST -->
                    </div>
                    <div class="col-md-4">
                        <label for="description" class="form-label">توضیحات (مبدا، وضعیت، ...)</label>
                        <input type="text" class="form-control" id="description" name="description" maxlength="255"
                            value="<?php echo isset($_POST['description']) ? escapeHtml($_POST['description']) : ''; ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="submit_return" class="btn btn-success w-100">
                            <i class="bi bi-check-lg"></i> ثبت برگشت
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Return History Table -->
    <div class="card">
        <div class="card-header bg-secondary text-white">
            <i class="bi bi-list-ul"></i> تاریخچه برگشت خرک‌ها
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered table-hover table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>تاریخ برگشت</th>
                            <th>تعداد</th>
                            <th>توضیحات</th>
                            <th>ثبت کننده</th>
                            <th>زمان ثبت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stand_returns_history)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">هنوز هیچ رکوردی برای برگشت خرک ثبت نشده است.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stand_returns_history as $record): ?>
                                <tr>
                                    <td class="text-nowrap"><?php echo !empty($record['return_date']) ? escapeHtml(jdate('Y/m/d', strtotime($record['return_date']))) : '-'; ?></td>
                                    <td><?php echo escapeHtml($record['returned_count']); ?></td>
                                    <td><?php echo !empty($record['description']) ? nl2br(escapeHtml($record['description'])) : '-'; ?></td>
                                    <td><?php echo escapeHtml(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? 'کاربر حذف شده')); ?></td>
                                    <td class="text-nowrap"><?php echo escapeHtml(jdate('Y/m/d H:i', strtotime($record['recorded_at']))); ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="button-edit edit-btn" title="ویرایش"
                                                data-id="<?php echo $record['id']; ?>"
                                                data-count="<?php echo $record['returned_count']; ?>"
                                                data-date="<?php echo !empty($record['return_date']) ? escapeHtml(jdate('Y/m/d', strtotime($record['return_date']))) : ''; ?>"
                                                data-desc="<?php echo escapeHtml($record['description'] ?? ''); ?>">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <button type="button" class="button-24 delete-btn" title="حذف"
                                                data-id="<?php echo $record['id']; ?>"
                                                data-count="<?php echo $record['returned_count']; ?>"
                                                data-date="<?php echo !empty($record['return_date']) ? escapeHtml(jdate('Y/m/d', strtotime($record['return_date']))) : ''; ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div> <!-- /container -->

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="editModalLabel">ویرایش رکورد خرک برگشتی</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" id="edit-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_returned_count" class="form-label">تعداد برگشتی <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="edit_returned_count" name="edit_returned_count" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_return_date_persian" class="form-label">تاریخ برگشت <span class="text-danger">*</span></label>
                        <input type="text" class="form-control date-input-persian" id="edit_return_date_persian" name="edit_return_date_persian" placeholder="YYYY/MM/DD" required autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">توضیحات</label>
                        <input type="text" class="form-control" id="edit_description" name="edit_description" maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                    <button type="submit" name="edit_return" class="btn btn-primary">ذخیره تغییرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel">حذف رکورد خرک برگشتی</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" id="delete-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="delete_id" id="delete_id">
                <div class="modal-body">
                    <p class="text-center mb-3">آیا از حذف این رکورد اطمینان دارید؟</p>
                    <div class="alert alert-warning">
                        <p id="delete-confirm-text"></p>
                        <p class="text-danger mb-0"><strong>توجه: </strong>این عملیات غیر قابل بازگشت است.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                    <button type="submit" name="delete_return" class="btn btn-danger">
                        <i class="bi bi-trash"></i> حذف
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Include necessary JS libraries -->
form-control datepicker
<link rel="stylesheet" href="/assets/css/persian-datepicker-dark.min.css">
<script src="/assets/js/jquery-3.6.0.min.js"></script> <!-- Assuming you have jQuery -->
<script src="/assets/js/persian-date.min.js"></script>
<script src="/assets/js/persian-datepicker.min.js"></script>
<!-- Bootstrap JS Bundle if needed -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
    /* Styles for Date Picker Input */
    .date-input-persian {
        /* width: 150px; Adjust as needed */
        padding: 0.375rem 0.75rem;
        border-radius: 0.25rem;
        border: 1px solid #ced4da;
        font-family: 'Vazir', sans-serif;
        text-align: center;
        direction: ltr;
        /* Important for datepicker */
    }

    /* Persian datepicker customization */
    .datepicker-plot-area {
        font-family: 'Vazir', sans-serif !important;
    }

    /* Status Card Styles */
    .stand-status-card .card-body {
        padding-top: 1.5rem;
        padding-bottom: 1rem;
    }

    .stat-box {
        padding: 10px;
        border: 1px solid #eee;
        border-radius: 5px;
        margin-bottom: 10px;
        /* Space on smaller screens */
        background-color: #f8f9fa;
    }

    .stat-value {
        font-size: 2rem;
        font-weight: bold;
        display: block;
        margin-bottom: 5px;
    }

    .stat-label {
        font-size: 0.9rem;
        color: #6c757d;
    }

    .text-danger .stat-label {
        color: #dc3545;
    }

    .text-success .stat-label {
        color: #198754;
    }

    .text-primary .stat-label {
        color: #0d6efd;
    }

    .table th,
    .table td {
        vertical-align: middle;
    }



    /* CSS */
    .button-24 {
        background: #FF4742;
        border: 1px solid #FF4742;
        border-radius: 6px;
        box-shadow: rgba(0, 0, 0, 0.1) 1px 2px 4px;
        box-sizing: border-box;
        color: #FFFFFF;
        cursor: pointer;
        display: inline-block;
        font-family: "Vazir", sans-serif;
        font-size: 9px;
        font-weight: 800;
        line-height: 16px;
        min-height: 40px;
        outline: 0;
        padding: 12px 14px;
        text-align: center;
        text-rendering: geometricprecision;
        text-transform: none;
        user-select: none;
        -webkit-user-select: none;
        touch-action: manipulation;
        vertical-align: middle;
    }

    .button-24:hover,
    .button-24:active {
        background-color: initial;
        background-position: 0 0;
        color: #FF4742;
    }

    .button-24:active {
        opacity: .5;
    }

    .button-edit {
        background: rgb(66, 255, 101);
        border: 1px solidrgb(66, 255, 176);
        border-radius: 6px;
        box-shadow: rgba(0, 0, 0, 0.1) 1px 2px 4px;
        box-sizing: border-box;
        color: #FFFFFF;
        cursor: pointer;
        display: inline-block;
        font-family: "Vazir", sans-serif;
        font-size: 9px;
        font-weight: 800;
        line-height: 16px;
        min-height: 40px;
        outline: 0;
        padding: 12px 14px;
        text-align: center;
        text-rendering: geometricprecision;
        text-transform: none;
        user-select: none;
        -webkit-user-select: none;
        touch-action: manipulation;
        vertical-align: middle;
    }

    .button-edit:hover,
    .button-edit:active {
        background-color: initial;
        background-position: 0 0;
        color: #FF4742;
    }

    .button-edit:active {
        opacity: .5;
    }

    .table-sm td,
    .table-sm th {
        padding: 0.4rem;
        /* Adjust padding for smaller tables */
    }

    .text-nowrap {
        white-space: nowrap;
    }

    /* Add space between action buttons */
    .btn-group .btn+.btn {
        margin-right: 4px;
    }
</style>

<script>
    $(document).ready(function() {
        // Initialize Persian datepicker for the add form
        $("#return_date_persian").persianDatepicker({
            format: 'YYYY/MM/DD',
            autoClose: true,
            initialValue: false, // Start empty
            calendar: {
                persian: {
                    locale: 'fa',
                    leapYearMode: 'astronomical'
                }
            }
        });

        // Initialize Persian datepicker for the edit form
        $("#edit_return_date_persian").persianDatepicker({
            format: 'YYYY/MM/DD',
            autoClose: true,
            initialValue: false,
            calendar: {
                persian: {
                    locale: 'fa',
                    leapYearMode: 'astronomical'
                }
            }
        });

        // Edit button click handler
        $('.edit-btn').on('click', function() {
            // Get data from button attributes
            const id = $(this).data('id');
            const count = $(this).data('count');
            const date = $(this).data('date');
            const desc = $(this).data('desc');

            // Populate the edit form
            $('#edit_id').val(id);
            $('#edit_returned_count').val(count);
            $('#edit_return_date_persian').val(date);
            $('#edit_description').val(desc);

            // Show the modal
            $('#editModal').modal('show');
        });

        // Delete button click handler
        $('.delete-btn').on('click', function() {
            // Get data from button attributes
            const id = $(this).data('id');
            const count = $(this).data('count');
            const date = $(this).data('date');

            // Populate the delete confirmation form
            $('#delete_id').val(id);
            $('#delete-confirm-text').html(`شما در حال حذف رکورد برگشت <strong>${count} عدد خرک</strong> در تاریخ <strong>${date}</strong> هستید.`);

            // Show the modal
            $('#deleteModal').modal('show');
        });

        // Form validation for add form
        $('#return-form').on('submit', function(e) {
            const count = $('#returned_count').val();
            const date = $('#return_date_persian').val();

            if (!count || count < 1 || !date) {
                e.preventDefault();
                alert('لطفا تمام فیلدهای الزامی را پر کنید.');
                return false;
            }
            return true;
        });

        // Form validation for edit form
        $('#edit-form').on('submit', function(e) {
            const count = $('#edit_returned_count').val();
            const date = $('#edit_return_date_persian').val();

            if (!count || count < 1 || !date) {
                e.preventDefault();
                alert('لطفا تمام فیلدهای الزامی را پر کنید.');
                return false;
            }
            return true;
        });

        // Optional: Clear success message after a few seconds
        setTimeout(function() {
            $('.alert-success').fadeOut('slow');
        }, 5000); // 5 seconds
    });
</script>

<?php
require_once 'footer.php'; // Include your standard footer
ob_end_flush(); // Send output buffer
?>