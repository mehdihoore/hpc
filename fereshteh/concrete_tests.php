<?php
// concrete_tests.php (New Name)
//ALTER TABLE concrete_tests ADD concrete_slump DECIMAL(5,1) NULL DEFAULT NULL AFTER production_date;
//ALTER TABLE concrete_tests ADD panel_type VARCHAR(255) NULL DEFAULT NULL AFTER concrete_slump;
//UPDATE concrete_tests SET panel_type = 'General' WHERE panel_type IS NULL OR panel_type = '';
// --- Basic Setup, Dependencies, Session, Auth, DB Connection ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once __DIR__ . '/includes/jdf.php';
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
$allowed_roles = ['admin', 'supervisor', 'superuser'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    logError("Unauthorized role '{$_SESSION['role']}' attempt on {$expected_project_key}/concrete_tests_manager.php. User: {$_SESSION['user_id']}");
    header('Location: dashboard.php?msg=unauthorized'); // Redirect within project
    exit();
}
// --- End Authorization ---
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/concrete_tests.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}


// --- NEW: Fetch Panel Types for the form dropdown ---
$panel_types = [];
try {
    // Assuming 'hpc_panels' is the correct table name and 'type' is the column with panel names
    $stmt_panels = $pdo->query("SELECT DISTINCT `type` FROM `hpc_panels` WHERE `type` IS NOT NULL AND `type` != '' ORDER BY `type` ASC");
    $panel_types = $stmt_panels->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    logError("Failed to fetch panel types: " . $e->getMessage());
    // The page can still load, but the dropdown will be empty. An error message might be useful.
    $_SESSION['error_message'] = "خطا در بارگذاری انواع پنل.";
}
if (!in_array('General', $panel_types)) {
    array_unshift($panel_types, 'General'); // Adds 'General' to the beginning of the array
}

// --- PHP Helper Functions ---

function calculate_age($prod_g, $break_g)
{
    if (empty($prod_g) | empty($break_g)) return null;
    try {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prod_g) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $break_g)) return null;
        $p = new DateTime($prod_g);
        $b = new DateTime($break_g);
        if ($b < $p) return null;
        return $p->diff($b)->days;
    } catch (Exception $e) {
        error_log("Err calc age: " . $e->getMessage());
        return null;
    }
}
function calculate_strength($force_kg, $dim_l, $dim_w)
{
    if ($dim_l <= 0 || $dim_w <= 0 || $force_kg <= 0) return null;
    return ($force_kg * 9.80665) / ($dim_l * $dim_w);
}
// --- Define Upload Path ---
define('UPLOAD_DIRT', 'uploads/concrete_tests/'); // Relative to current 

if (!is_dir(__DIR__ . '/' . UPLOAD_DIRT)) {
    if (!mkdir(__DIR__ . '/' . UPLOAD_DIRT, 0775, true)) {
        logError("Failed to create concrete test upload directory: " . __DIR__ . '/' . UPLOAD_DIRT);
        $error = "خطا: امکان ایجاد پوشه آپلود وجود ندارد.";
    }
}
function find_test_file($record_id, $sample_code)
{
    if (empty($record_id) || empty($sample_code)) {
        return null;
    }
    $safe_code = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sample_code);
    $base_filename = $record_id . '_' . $safe_code;
    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png', 'gif']; // Keep consistent

    foreach ($allowed_ext as $ext) {
        $potential_path_relative = $base_filename . '.' . $ext;
        $potential_path_full = UPLOAD_DIRT . $potential_path_relative;
        if (file_exists($potential_path_full)) {
            return [
                'relative_path' => $potential_path_relative, // Path without UPLOAD_DIRT
                'basename' => basename($potential_path_relative) // Just the filename
            ];
        }
    }
    return null; // No file found
}
// --- Fetch or Define Print Settings ---
// In real app: fetch from DB or config file based on user/site settings
$print_settings = [
    'header' => $_SESSION['print_settings']['header'] ?? "گزارش تست‌های بتن\nشرکت نمونه",
    'footer' => $_SESSION['print_settings']['footer'] ?? "تاریخ چاپ: " . jdate('Y/m/d'),
    'signature_area' => $_SESSION['print_settings']['signature_area'] ?? "<td>مسئول آزمایشگاه</td><td>کنترل کیفیت</td><td>مدیر فنی</td>", // Example table cells (td)
];
// --- END Print Settings ---

// --- POST Handling ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Action: SAVE PRINT SETTINGS ---
    if (isset($_POST['action']) && $_POST['action'] == 'save_print_settings') {
        $_SESSION['print_settings']['header'] = trim($_POST['print_header'] ?? '');
        $_SESSION['print_settings']['footer'] = trim($_POST['print_footer'] ?? '');
        $_SESSION['print_settings']['signature_area'] = trim($_POST['print_signature_area'] ?? ''); // Store signature HTML snippet
        $_SESSION['success_message'] = "تنظیمات چاپ ذخیره شد.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();

        // --- Action: SAVE BATCH ---
    } elseif (isset($_POST['action']) && ($_POST['action'] == 'save_batch' || $_POST['action'] == 'update_batch')) {
        // --- BATCH SAVE LOGIC (Revised for new understanding) ---
        $is_update_mode = ($_POST['action'] == 'update_batch');

        // --- Fetch form data ---
        $prod_jalali = toLatinDigitsPhp(trim($_POST['production_date'] ?? ''));
        // --- NEW: Get Panel Type and Slump ---
        $panel_type = trim($_POST['panel_type'] ?? '');
        $concrete_slump = filter_var($_POST['concrete_slump'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]);

        $record_ids = $_POST['record_id'] ?? [];      // Ensure this is receiving the array
        $sample_codes = $_POST['sample_code'] ?? []; // Ensure this is receiving the array
        $dims_l = $_POST['dimension_l'] ?? [];       // Ensure this is receiving the array
        $dims_w = $_POST['dimension_w'] ?? [];       // Ensure this is receiving the array
        $dims_h = $_POST['dimension_h'] ?? [];       // Ensure this is receiving the array
        $forces_kg = $_POST['max_force_kg'] ?? [];      // Ensure this is receiving the array
        error_log("[Save/Update Debug] POST data received: " . print_r($_POST, true));
        $errors = [];
        $prod_g = null;
        $prod_dt = null;
        // Validate Production Date
        if (empty($prod_jalali) || !preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $prod_jalali)) {
            $errors[] = "فرمت تاریخ تولید نامعتبر.";
        } else { /* ... Date conversion logic ... */
            $prod_p = explode('/', $prod_jalali);
            if (count($prod_p) === 3) {
                $jy_p = intval($prod_p[0]);
                $jm_p = intval($prod_p[1]);
                $jd_p = intval($prod_p[2]);
                if ($jy_p >= 1300 && $jy_p <= 1500) {
                    $prod_g_arr = jalali_to_gregorian($jy_p, $jm_p, $jd_p);
                    if ($prod_g_arr) {
                        $prod_g = sprintf('%04d-%02d-%02d', $prod_g_arr[0], $prod_g_arr[1], $prod_g_arr[2]);
                        $prod_dt = DateTime::createFromFormat('Y-m-d', $prod_g);
                        if (!$prod_dt || $prod_dt->format('Y-m-d') !== $prod_g) {
                            $errors[] = "تاریخ تولید میلادی نامعتبر.";
                            $prod_g = null;
                            $prod_dt = null;
                        }
                    } else {
                        $errors[] = "خطا در تبدیل تاریخ تولید.";
                    }
                } else {
                    $errors[] = "سال تولید جلالی نامعتبر.";
                }
            } else {
                $errors[] = "فرمت تاریخ تولید نامعتبر.";
            }
        }

        // --- NEW: Validate Panel Type and Slump ---
        if (empty($panel_type)) {
            $errors[] = "نوع پنل الزامی است.";
        }
        if (isset($_POST['concrete_slump']) && $_POST['concrete_slump'] !== '' && ($concrete_slump === null || $concrete_slump < 0)) {
            $errors[] = "مقدار اسلامپ نامعتبر است.";
        }


        // Correct sample ages based on new requirement
        $sample_ages = [1, 1, 7, 28]; // Instant/1D (Calc), 1D (Manual), 7D (Manual), 28D (Manual)
        $num_samples = count($sample_ages);
        // *** Initialize submitted_codes ***
        $submitted_codes = [];
        // Validate array counts and content
        if (count($sample_codes) !== $num_samples || count($dims_l) !== $num_samples || count($dims_w) !== $num_samples || count($dims_h) !== $num_samples || count($forces_kg) !== $num_samples) {
            $errors[] = "اطلاعات نمونه‌ها ناقص است.";
        } else {
            for ($i = 0; $i < $num_samples; $i++) {
                $sample_label = "نمونه " . ($i + 1);

                // --- Use isset() for safer access ---
                $current_code = isset($sample_codes[$i]) ? trim($sample_codes[$i]) : '';
                $current_id = isset($record_ids[$i]) ? trim($record_ids[$i]) : '';
                // --- End safer access ---

                $is_existing_record = !empty($current_id) && is_numeric($current_id);

                // Code validation
                if (empty($current_code)) {
                    // Only error if it's a new record or the first record which MUST have a code
                    if ($i == 0 || !$is_existing_record) {
                        $errors[] = "کد {$sample_label} الزامی است.";
                    }
                } else {
                    $submitted_codes[] = $current_code; // Add non-empty code for duplicate check
                }


                // Detail validation
                $is_required = ($i == 0); // Only first row details mandatory for NEW batch
                $l_val = isset($dims_l[$i]) ? filter_var($dims_l[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;
                $w_val = isset($dims_w[$i]) ? filter_var($dims_w[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;
                $h_val = isset($dims_h[$i]) ? filter_var($dims_h[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;
                $force_val = isset($forces_kg[$i]) ? filter_var($forces_kg[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;

                // Validate mandatory fields for first row, OR any fields IF they have been filled (even if optional)
                if ($is_required || ($l_val !== null || $w_val !== null || $h_val !== null || $force_val !== null)) {
                    if ($l_val === null || $l_val <= 0) {
                        if ($is_required || $l_val !== null) $errors[] = "طول {$sample_label} نامعتبر.";
                    }
                    if ($w_val === null || $w_val <= 0) {
                        if ($is_required || $w_val !== null) $errors[] = "عرض {$sample_label} نامعتبر.";
                    }
                    if ($h_val === null || $h_val <= 0) {
                        if ($is_required || $h_val !== null) $errors[] = "ارتفاع {$sample_label} نامعتبر.";
                    }
                    if ($force_val === null || $force_val <= 0) {
                        if ($is_required || $force_val !== null) $errors[] = "نیرو Fx {$sample_label} نامعتبر.";
                    }
                }
            } // End validation loop

            // Check for duplicates within the submission
            // Detail validation
            $is_required = ($i == 0); // Only first row details mandatory for NEW batch
            $l_val = isset($dims_l[$i]) ? filter_var($dims_l[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;
            $w_val = isset($dims_w[$i]) ? filter_var($dims_w[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;
            $h_val = isset($dims_h[$i]) ? filter_var($dims_h[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;
            $force_val = isset($forces_kg[$i]) ? filter_var($forces_kg[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;

            // Validate mandatory fields for first row, OR any fields IF they have been filled (even if optional)
            if ($is_required || ($l_val !== null || $w_val !== null || $h_val !== null || $force_val !== null)) {
                if ($l_val === null || $l_val <= 0) {
                    if ($is_required || $l_val !== null) $errors[] = "طول {$sample_label} نامعتبر.";
                }
                if ($w_val === null || $w_val <= 0) {
                    if ($is_required || $w_val !== null) $errors[] = "عرض {$sample_label} نامعتبر.";
                }
                if ($h_val === null || $h_val <= 0) {
                    if ($is_required || $h_val !== null) $errors[] = "ارتفاع {$sample_label} نامعتبر.";
                }
                if ($force_val === null || $force_val <= 0) {
                    if ($is_required || $force_val !== null) $errors[] = "نیرو Fx {$sample_label} نامعتبر.";
                }
            }
        } // End validation loop

        // --- Database Operations ---
        if (empty($errors) && $prod_dt) {
            $pdo->beginTransaction();
            try {
                // Prepare statements (might need separate INSERT and UPDATE)
                // MODIFICATION: Added panel_type and concrete_slump to INSERT
                $sql_insert = "INSERT INTO concrete_tests (production_date, panel_type, concrete_slump, sample_code, break_date, sample_age_at_break, dimension_l, dimension_w, dimension_h, max_force_kg, compressive_strength_mpa, strength_1_day, strength_7_day, strength_28_day, user_id, is_rejected, rejection_reason, results_file_path) VALUES (:prod_date, :panel_type, :slump, :code, :break_date, :age, :dim_l, :dim_w, :dim_h, :force, :strength_mpa, :str1, :str7, :str28, :user_id, 0, NULL, NULL)";
                $stmt_insert = $pdo->prepare($sql_insert);

                // MODIFICATION: Added panel_type and concrete_slump to UPDATE
                $sql_update = "UPDATE concrete_tests SET panel_type = :panel_type, concrete_slump = :slump, sample_code = :code, dimension_l = :dim_l, dimension_w = :dim_w, dimension_h = :dim_h, max_force_kg = :force, compressive_strength_mpa = :strength_mpa, strength_1_day = :str1, strength_7_day = :str7, strength_28_day = :str28, user_id = :user_id WHERE id = :id"; // Removed prod_date, break_date, age update
                $stmt_update = $pdo->prepare($sql_update);

                $all_success = true;
                for ($i = 0; $i < $num_samples; $i++) {
                    // --- More Robust ID Check ---
                    $record_id_value = isset($record_ids[$i]) ? trim($record_ids[$i]) : '';
                    $record_id = 0; // Default to 0 (insert)
                    if (!empty($record_id_value) && is_numeric($record_id_value) && intval($record_id_value) > 0) {
                        $record_id = intval($record_id_value); // Assign valid numeric ID > 0
                    }
                    $code = isset($sample_codes[$i]) ? trim($sample_codes[$i]) : '';

                    // Skip if code is empty AND it's not an existing record we are processing
                    if (empty($code) && $record_id <= 0) {
                        error_log("[Save/Update Debug] Skipping row $i: Empty code and no existing ID.");
                        continue;
                    }

                    $age = $sample_ages[$i];
                    $break_dt = clone $prod_dt;
                    $break_dt->add(new DateInterval("P{$age}D"));
                    $break_g = $break_dt->format('Y-m-d');
                    $l = isset($dims_l[$i]) ? filter_var($dims_l[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;
                    $w = isset($dims_w[$i]) ? filter_var($dims_w[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;
                    $h = isset($dims_h[$i]) ? filter_var($dims_h[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;
                    $force = isset($forces_kg[$i]) ? filter_var($forces_kg[$i], FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]) : null;

                    // Map strength to correct columns based on index
                    $strength_value = null;
                    if ($l > 0 && $w > 0 && $force !== null && $force > 0) {
                        $strength_value = calculate_strength($force, $l, $w);
                    }
                    $strength_mpa_val = ($i == 0 && $strength_value !== null) ? $strength_value : null;
                    $strength_1_val   = ($i == 1 && $strength_value !== null) ? $strength_value : null;
                    $strength_7_val   = ($i == 2 && $strength_value !== null) ? $strength_value : null;
                    $strength_28_val  = ($i == 3 && $strength_value !== null) ? $strength_value : null;
                    $compressive_strength_for_db = null;
                    if ($l > 0 && $w > 0 && $force !== null) {
                        $compressive_strength_for_db = calculate_strength(max(0, $force), $l, $w);
                    }


                    if ($is_update_mode && $record_id > 0) { // <<< Use the validated $record_id
                        // --- UPDATE Existing Record ---
                        error_log("[Save/Update Debug] Updating record ID: $record_id for index $i with code: $code");
                        // MODIFICATION: Bind new params
                        $params_update = [':panel_type' => $panel_type, ':slump' => $concrete_slump, ':code' => $code, ':dim_l' => $l, ':dim_w' => $w, ':dim_h' => $h, ':force' => $force, ':strength_mpa' => $compressive_strength_for_db, ':str1' => $strength_1_val, ':str7' => $strength_7_val, ':str28' => $strength_28_val, ':user_id' => $user_id, ':id' => $record_id];
                        if (!$stmt_update->execute($params_update)) {
                            $all_success = false;
                            $errors[] = "Error updating {$code}.";
                            break;
                        }
                    } elseif ((!$is_update_mode || ($is_update_mode && $record_id <= 0)) && !empty($code)) { // INSERT new record
                        // --- INSERT New Record (Only if code is provided and ID is missing/zero) ---
                        error_log("[Save/Update Debug] Inserting new record for index $i with code: $code");
                        // MODIFICATION: Bind new params
                        $params_insert = [':prod_date' => $prod_g, ':panel_type' => $panel_type, ':slump' => $concrete_slump, ':code' => $code, ':break_date' => $break_g, ':age' => $age, ':dim_l' => $l, ':dim_w' => $w, ':dim_h' => $h, ':force' => $force, ':strength_mpa' => $compressive_strength_for_db, ':str1' => $strength_1_val, ':str7' => $strength_7_val, ':str28' => $strength_28_val, ':user_id' => $user_id];
                        if (!$stmt_insert->execute($params_insert)) {
                            $all_success = false;
                            $errors[] = "Error inserting {$code}.";
                            if ($pdo->errorCode() == '23000') $errors[] = "Code {$code} exists.";
                            break;
                        }
                    } else {
                        error_log("[Save/Update Debug] Skipping row $i: Empty code for insert, or invalid ID for update. ID Value: '$record_id_value'");
                    }
                } // End for loop

                if ($all_success) {
                    $pdo->commit();
                    $_SESSION['success_message'] = "{$num_samples} نمونه ثبت شد.";
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit();
                } else {
                    $pdo->rollBack();
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Batch Insert Exception: " . $e->getMessage());
                $errors[] = "خطای پایگاه داده.";
            }
        }
        if (!empty($errors)) {
            $_SESSION['error_message'] = implode("<br>", $errors);
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        // --- END BATCH SAVE ---




        // --- Action: SAVE UPDATE (Single Record) ---
    } elseif (isset($_POST['action']) && $_POST['action'] == 'save_update') {
        // --- SINGLE UPDATE LOGIC ---
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error_message'] = "شناسه رکورد نامعتبر.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        // --- Fetch form data ---
        $prod_jalali = toLatinDigitsPhp(trim($_POST['production_date'] ?? '')); // Readonly
        $break_jalali = toLatinDigitsPhp(trim($_POST['break_date'] ?? ''));
        $sample_code = trim($_POST['sample_code'] ?? ''); // Readonly
        // ... (fetch other fields: dim_l, dim_w, dim_h, force_kg, is_rejected, reason, str1, str7, str28) ...
        // NEW: Fetch slump for single edit
        $concrete_slump_single = filter_var($_POST['concrete_slump'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]);

        $dim_l = filter_var($_POST['dimension_l'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]);
        $dim_w = filter_var($_POST['dimension_w'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]);
        $dim_h = filter_var($_POST['dimension_h'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]);
        $force_kg = filter_var($_POST['max_force_kg'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]);
        $is_rejected = isset($_POST['is_rejected']) ? 1 : 0;
        $reason = trim($_POST['rejection_reason'] ?? '');
        $reason = ($is_rejected == 1 && !empty($reason)) ? $reason : null;
        $strength_1 = filter_var($_POST['strength_1_day'] ?? '', FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]);
        $strength_7 = filter_var($_POST['strength_7_day'] ?? '', FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]);
        $strength_28 = filter_var($_POST['strength_28_day'] ?? '', FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]);
        // Convert production date to Gregorian FOR DIRECTORY STRUCTURE
        $prod_g_for_dir = null;
        if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $prod_jalali)) {
            $prod_p = explode('/', $prod_jalali);
            if (count($prod_p) === 3) {
                $prod_g_arr = jalali_to_gregorian(intval($prod_p[0]), intval($prod_p[1]), intval($prod_p[2]));
                if ($prod_g_arr) {
                    $prod_g_for_dir = sprintf('%04d-%02d-%02d', $prod_g_arr[0], $prod_g_arr[1], $prod_g_arr[2]);
                }
            }
        }

        // --- Validation ---
        $errors = [];
        if (empty($sample_code)) $errors[] = "کد نمونه الزامی.";
        // NEW: Validate slump for single edit
        if (isset($_POST['concrete_slump']) && $_POST['concrete_slump'] !== '' && ($concrete_slump_single === null || $concrete_slump_single < 0)) {
            $errors[] = "مقدار اسلامپ نامعتبر است.";
        }
        if ($prod_g_for_dir === null) $errors[] = "تاریخ تولید نامعتبر برای ساختار پوشه.";
        if (empty($prod_jalali) || !preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $prod_jalali)) $errors[] = "فرمت تاریخ تولید نامعتبر.";
        $prod_g = null;
        $break_g = null;
        $dates_valid = false;
        if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $prod_jalali)) {
            $prod_p = explode('/', $prod_jalali);
            if (count($prod_p) === 3) {
                $prod_g_arr = jalali_to_gregorian(intval($prod_p[0]), intval($prod_p[1]), intval($prod_p[2]));
                if ($prod_g_arr) {
                    $prod_g = sprintf('%04d-%02d-%02d', $prod_g_arr[0], $prod_g_arr[1], $prod_g_arr[2]);
                } else {
                    $errors[] = "فرمت تاریخ تولید نامعتبر.";
                }
            }
        }
        if (!empty($break_jalali)) {
            if (!preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $break_jalali)) {
                $errors[] = "فرمت تاریخ شکست نامعتبر.";
            } else {
                $break_p = explode('/', $break_jalali);
                if (count($break_p) === 3) {
                    $break_g_arr = jalali_to_gregorian(intval($break_p[0]), intval($break_p[1]), intval($break_p[2]));
                    if ($break_g_arr) {
                        $break_g = sprintf('%04d-%02d-%02d', $break_g_arr[0], $break_g_arr[1], $break_g_arr[2]);
                        $dates_valid = ($prod_g !== null);
                    } else {
                        $errors[] =  "خطا تبدیل تاریخ شکست.";
                    }
                } else {
                    $errors[] =  "فرمت تاریخ شکست نامعتبر.";
                }
            }
            if ($dim_l === null || $dim_l <= 0) $errors[] = "طول نامعتبر.";
            if ($dim_w === null || $dim_w <= 0) $errors[] = "عرض نامعتبر.";
            if ($dim_h === null || $dim_h <= 0) $errors[] = "err height";
            if ($force_kg === null || $force_kg <= 0) $errors[] = "نیرو نامعتبر.";
            if ($is_rejected == 1 && $reason === null) $errors[] = "دلیل مردودی الزامی.";
        } else {
            $break_g = null;
            $dates_valid = false;
        }

        // --- Calculate Age/Strength ---
        $age = null;
        $strength = null; // Recalculate strength on update
        if ($dates_valid && $prod_g && $break_g && empty($errors)) {
            $age = calculate_age($prod_g, $break_g);
            if ($age === null && $prod_g && $break_g) {
                if (new DateTime($break_g) < new DateTime($prod_g)) {
                    $errors[] = "تاریخ شکست قبل از تولید.";
                } else {
                    $errors[] = "خطا در محاسبه سن.";
                }
            }
            if ($force_kg > 0 && $dim_l > 0 && $dim_w > 0) {
                $strength = calculate_strength($force_kg, $dim_l, $dim_w); /* ... strength error check ... */
            } else {
                $strength = null;
            }
        } else {
            $age = null;
            $strength = null;
        }

        $upload_errors = []; // Specific errors for uploads
        // Construct the base path and potential filename parts
        $safe_code = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sample_code);
        $base_filename_pattern = $id . '_' . $safe_code; // e.g., 123_C1-3009

        if (isset($_FILES['test_results_files']) && $prod_g_for_dir && !empty($sample_code)) { // Check if file input exists and key info is available
            $safe_code = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sample_code);
            $target_dir = UPLOAD_DIRT . $prod_g_for_dir . '/' . $safe_code . '/'; // YYYY-MM-DD/SAFE_CODE/

            // Create directory if it doesn't exist
            if (!is_dir($target_dir)) {
                if (!mkdir($target_dir, 0775, true)) {
                    $upload_errors[] = "خطا در ایجاد پوشه آپلود: " . $target_dir;
                    error_log("Failed to create directory: " . $target_dir);
                }
            }

            if (empty($upload_errors)) { // Proceed only if directory is ready
                $total_files = count($_FILES['test_results_files']['name']);

                for ($i = 0; $i < $total_files; $i++) {
                    // Check if a file was actually uploaded in this iteration
                    if ($_FILES['test_results_files']['error'][$i] == UPLOAD_ERR_OK) {
                        $file_tmp_path = $_FILES['test_results_files']['tmp_name'][$i];
                        $file_name = basename($_FILES['test_results_files']['name'][$i]);
                        $file_size = $_FILES['test_results_files']['size'][$i];
                        $file_ext_parts = explode('.', $file_name);
                        $file_ext = strtolower(end($file_ext_parts));

                        $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'docx', 'xlsx']; // Adjust allowed
                        $max_file_size = 10 * 1024 * 1024; // 10 MB

                        if (!in_array($file_ext, $allowed_ext)) {
                            $upload_errors[] = "نوع فایل '{$file_name}' مجاز نیست.";
                            continue;
                        }
                        if ($file_size > $max_file_size) {
                            $upload_errors[] = "حجم فایل '{$file_name}' بیش از حد مجاز.";
                            continue;
                        }

                        // Sanitize filename (optional but recommended)
                        $safe_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $file_name);
                        // Prevent overwriting - add timestamp or check existence
                        $counter = 0;
                        $target_path = $target_dir . $safe_filename;
                        while (file_exists($target_path) && $counter < 100) { // Limit loop
                            $counter++;
                            $path_info = pathinfo($safe_filename);
                            $target_path = $target_dir . $path_info['filename'] . '_' . $counter . '.' . $path_info['extension'];
                        }


                        if (!move_uploaded_file($file_tmp_path, $target_path)) {
                            $upload_errors[] = "خطا در آپلود فایل '{$file_name}'.";
                            error_log("Failed move_uploaded_file to: " . $target_path);
                        } else {
                            error_log("Successfully uploaded: " . $target_path);
                        }
                    } elseif ($_FILES['test_results_files']['error'][$i] != UPLOAD_ERR_NO_FILE) {
                        // Handle other upload errors if needed
                        $upload_errors[] = "خطا در آپلود فایل شماره " . ($i + 1) . " کد خطا: " . $_FILES['test_results_files']['error'][$i];
                    }
                } // end for loop
            }
        }
        // Merge upload errors with main validation errors
        $errors = array_merge($errors, $upload_errors);
        // --- End File Upload ---


        // --- DB Update ---
        if (empty($errors)) {
            // MODIFICATION: Add slump to params
            $params = [':slump' => $concrete_slump_single, ':prod_date' => $prod_g, ':code' => $sample_code, ':break_date' => $break_g, ':age' => $age, ':dim_l' => $dim_l, ':dim_w' => $dim_w, ':dim_h' => $dim_h, ':force' => $force_kg, ':strength' => $strength, ':str1' => $strength_1, ':str7' => $strength_7, ':str28' => $strength_28, ':rejected' => $is_rejected, ':reason' => $reason, ':user_id' => $user_id,  ':id' => $id];
            try {
                // MODIFICATION: Add slump to UPDATE query
                $sql = "UPDATE concrete_tests SET concrete_slump = :slump, production_date=:prod_date, sample_code=:code, break_date=:break_date, sample_age_at_break=:age, dimension_l=:dim_l, dimension_w=:dim_w, dimension_h=:dim_h, max_force_kg=:force, compressive_strength_mpa=:strength, strength_1_day=:str1, strength_7_day=:str7, strength_28_day=:str28, is_rejected=:rejected, rejection_reason=:reason,  user_id=:user_id WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                // NEW: Update slump for all other tests in the same batch (same date and panel type)
                $stmt_get_batch_info = $pdo->prepare("SELECT production_date, panel_type FROM concrete_tests WHERE id = ?");
                $stmt_get_batch_info->execute([$id]);
                $batch_info = $stmt_get_batch_info->fetch(PDO::FETCH_ASSOC);

                if ($batch_info) {
                    $stmt_update_batch_slump = $pdo->prepare("UPDATE concrete_tests SET concrete_slump = ? WHERE production_date = ? AND panel_type = ?");
                    $stmt_update_batch_slump->execute([$concrete_slump_single, $batch_info['production_date'], $batch_info['panel_type']]);
                }


                $_SESSION['success_message'] = "بروزرسانی شد.";
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            } catch (PDOException $e) {
                error_log("Update fail ID $id: " . $e->getMessage());
                $_SESSION['error_message'] = ($e->getCode() == 23000) ? "خطا: کد نمونه تکراری؟" : "خطا ذخیره سازی.";
            }
        }
        if (!empty($errors)) {
            $_SESSION['error_message'] = implode("<br>", $errors);
            $_SESSION['form_data'] = $_POST;

            header('Location: ' . $_SERVER['PHP_SELF'] . '?edit=' . $id);
            exit();
        }
        // --- END SINGLE UPDATE ---

        // --- Action: DELETE (Single Record) ---
    } elseif (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $id = intval($_POST['id'] ?? 0); // Get ID from the hidden form input named 'id'

        // Reset $sql and $params for this action block if using the generic execution later
        $sql = "";
        $params = [];
        // Reset messages specific to this action block
        $message = "";
        // $error is handled by the outer try/catch and final check

        if ($id <= 0) {
            $error = "شناسه نامعتبر برای حذف.";
        } else {
            try {
                // 1. Get record details FIRST (needed for folder path & confirmation)
                $stmt_details = $pdo->prepare("SELECT production_date, sample_code FROM concrete_tests WHERE id = ?");
                $stmt_details->execute([$id]);
                $details = $stmt_details->fetch(PDO::FETCH_ASSOC);

                if (!$details) {
                    // Record did not exist BEFORE attempting delete
                    $error = "رکورد مورد نظر برای حذف یافت نشد (ID: {$id}).";
                    // No need to rollback here specifically, the outer catch/final check will handle it if $error is set.
                } else {
                    // 2. Attempt to DELETE the DB record (only ONCE)
                    $stmt_del = $pdo->prepare("DELETE FROM concrete_tests WHERE id = ?");
                    $deleted = $stmt_del->execute([$id]);

                    if ($deleted && $stmt_del->rowCount() > 0) {
                        // --- Deletion SUCCESSFUL ---
                        $message = "رکورد با موفقیت حذف شد."; // Set success message

                        // Log the activity
                        log_activity(
                            $user_id, // Admin performing the action

                            'delete_concrete_test',
                            "Deleted Record ID: {$id}, Sample Code: " . ($details['sample_code'] ?? 'N/A')
                            // Project ID might be added if relevant for this log type
                        );

                        // 3. Attempt to delete associated folder/files
                        if (!empty($details['production_date']) && !empty($details['sample_code'])) {
                            $safe_code_del = preg_replace('/[^a-zA-Z0-9_-]/', '_', $details['sample_code']);
                            $folder_path = __DIR__ . '/' . UPLOAD_DIRT . $details['production_date'] . '/' . $safe_code_del . '/';

                            if (is_dir($folder_path)) {
                                // Ensure delete_directory function is available
                                if (!function_exists('delete_directory')) {
                                    function delete_directory($dir)
                                    {
                                        if (!file_exists($dir)) return true;
                                        if (!is_dir($dir)) return unlink($dir);
                                        foreach (scandir($dir) as $item) {
                                            if ($item == '.' || $item == '..') continue;
                                            if (!delete_directory($dir . DIRECTORY_SEPARATOR . $item)) return false;
                                        }
                                        return rmdir($dir);
                                    }
                                }

                                if (!delete_directory($folder_path)) {
                                    logError("Could not delete directory: " . $folder_path);
                                    // Set a separate session warning, don't overwrite success message
                                    $_SESSION['warning_message'] = "رکورد پایگاه داده حذف شد، اما حذف پوشه فایل‌های مرتبط با خطا مواجه شد.";
                                } else {
                                    logError("Deleted associated directory: " . $folder_path);
                                }
                            }
                        } else {
                            logError("Missing production_date or sample_code, cannot attempt folder deletion for deleted record ID: " . $id);
                        }
                        // --- End File Deletion Attempt ---

                    } else {
                        // --- Deletion FAILED (or row didn't exist after check - less likely) ---
                        $error = "خطا در هنگام حذف رکورد از پایگاه داده (ID: {$id}).";
                        logError("Delete execute failed or rowCount=0 for concrete_test ID: {$id}");
                    }
                } // end if($details) check

            } catch (PDOException $e) {
                // Catch DB errors during the SELECT or DELETE specific to this block
                logError("Delete DB Exception ID {$id}: " . $e->getMessage());
                $error = "خطای پایگاه داده هنگام حذف رکورد.";
            }
        } // end if($id > 0)

        // The success/error message will be set in the session AFTER the main try/catch block based on $message/$error state
        // The redirect happens once at the end of all POST processing.
        // End elseif action == 'delete'
        // --- END DELETE ---
    } elseif (isset($_POST['action']) && $_POST['action'] == 'list_files' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        ob_clean(); // Clean potential output buffer
        header('Content-Type: text/html; charset=utf-8'); // Set correct header for HTML response

        $prod_date = $_POST['prod_date'] ?? null;
        $sample_code = $_POST['sample_code'] ?? null;

        if (!$prod_date || !$sample_code || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prod_date)) {
            echo '<p class="text-danger text-center">اطلاعات نامعتبر.</p>';
            exit;
        }

        $safe_code = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sample_code);
        $target_dir = UPLOAD_DIRT . $prod_date . '/' . $safe_code . '/';

        if (!is_dir($target_dir)) {
            echo '<p class="text-center text-muted">هنوز فایلی آپلود نشده است.</p>';
            exit;
        }

        $files = scandir($target_dir);
        $output_html = '<table class="table table-sm table-striped table-hover file-list-table"><thead><tr><th>نام فایل</th><th style="width: 80px;">عملیات</th></tr></thead><tbody>';
        $file_found = false;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $file_found = true;
            $file_path_relative = $prod_date . '/' . $safe_code . '/' . $file; // Relative for download link
            $download_url = UPLOAD_DIRT . $file_path_relative;

            $output_html .= '<tr>';
            $output_html .= '<td><a href="' . htmlspecialchars($download_url) . '" target="_blank">' . htmlspecialchars($file) . '</a></td>';
            $output_html .= '<td>';
            $output_html .= '<button type="button" class="btn btn-danger btn-sm" title="حذف فایل" ';
            $output_html .= 'onclick="deleteManagedFile(\'' . htmlspecialchars($prod_date) . '\', \'' . htmlspecialchars(addslashes($sample_code), ENT_QUOTES) . '\', \'' . htmlspecialchars(addslashes($file), ENT_QUOTES) . '\', this)">'; // Pass necessary info
            $output_html .= '<i class="bi bi-trash"></i></button>';
            // Add download button too if desired
            $output_html .= '<a href="' . htmlspecialchars($download_url) . '" class="btn btn-success btn-sm ms-1" title="دانلود فایل" download="' . htmlspecialchars($file) . '" target="_blank"><i class="bi bi-download"></i></a>';
            $output_html .= '</td>';
            $output_html .= '</tr>';
        }

        $output_html .= '</tbody></table>';

        if (!$file_found) {
            echo '<p class="text-center text-muted">هنوز فایلی آپلود نشده است.</p>';
        } else {
            echo $output_html;
        }
        exit; // Important: stop script execution after AJAX response

        // --- Action: DELETE FILE (AJAX) ---
    } elseif (isset($_POST['action']) && $_POST['action'] == 'delete_file' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        ob_clean();
        header('Content-Type: application/json'); // Respond with JSON

        $prod_date = $_POST['prod_date'] ?? null;
        $sample_code = $_POST['sample_code'] ?? null;
        $filename = $_POST['filename'] ?? null;

        $response = ['success' => false, 'message' => 'اطلاعات نامعتبر.'];

        // Basic validation and security checks
        if (!$prod_date || !$sample_code || !$filename || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prod_date) || strpos($filename, '/') !== false || strpos($filename, '..') !== false) {
            echo json_encode($response);
            exit;
        }

        $safe_code = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sample_code);
        $file_path = UPLOAD_DIRT . $prod_date . '/' . $safe_code . '/' . $filename;

        // Final check to prevent deleting outside intended directory
        if (strpos(realpath($file_path), realpath(UPLOAD_DIRT)) !== 0) {
            $response['message'] = 'مسیر فایل نامعتبر.';
            error_log("Attempt to delete file outside UPLOAD_DIRT: " . $file_path);
            echo json_encode($response);
            exit;
        }


        if (file_exists($file_path)) {
            if (unlink($file_path)) {
                $response['success'] = true;
                $response['message'] = 'فایل با موفقیت حذف شد.';
            } else {
                $response['message'] = 'خطا در حذف فایل از سرور.';
                error_log("Failed to unlink file: " . $file_path);
            }
        } else {
            $response['message'] = 'فایل مورد نظر یافت نشد.';
        }

        echo json_encode($response);
        exit; // Stop script execution
    } elseif (isset($_POST['action']) && $_POST['action'] == 'lookup_date' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        ob_clean(); // << Keep this first
        header('Content-Type: application/json'); // << Re-enabled
        $response = ['found' => false, 'records' => [], 'message' => 'Lookup started']; // Initial message

        try { // Wrap more code in try-catch
            $prod_jalali = toLatinDigitsPhp(trim($_POST['production_date'] ?? ''));
            // NEW: Get panel_type for lookup
            $panel_type = trim($_POST['panel_type'] ?? '');
            $prod_g = null;

            if (empty($prod_jalali) || !preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $prod_jalali) || empty($panel_type)) {
                $response['message'] = 'تاریخ و نوع پنل برای جستجو الزامی است.';
                echo json_encode($response);
                exit; // Exit early
            }

            // Convert to Gregorian
            $prod_p = explode('/', $prod_jalali);
            if (count($prod_p) === 3) {
                $prod_g_arr = jalali_to_gregorian(intval($prod_p[0]), intval($prod_p[1]), intval($prod_p[2]));
                if ($prod_g_arr) {
                    $prod_g = sprintf('%04d-%02d-%02d', $prod_g_arr[0], $prod_g_arr[1], $prod_g_arr[2]);
                }
            }

            if ($prod_g === null) {
                $response['message'] = 'خطا در تبدیل تاریخ.';
                echo json_encode($response);
                exit; // Exit early
            }

            // MODIFICATION: Fetch ALL records for that date AND panel_type
            $sql = "SELECT * FROM concrete_tests
                    WHERE production_date = ? AND panel_type = ?
                    ORDER BY sample_age_at_break ASC, id ASC
                    LIMIT 4";
            $stmt = $pdo->prepare($sql);
            // MODIFICATION: Bind both params
            $stmt->execute([$prod_g, $panel_type]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $found_count = count($records);
            error_log("[Lookup Debug] Found " . $found_count . " records for date " . $prod_g . " and panel " . $panel_type);

            $response['found'] = ($found_count > 0);

            // MODIFICATION: Add concrete_slump to the response
            $responseData = [
                'record_id' => [],
                'sample_code' => [],
                'dimension_l' => [],
                'dimension_w' => [],
                'dimension_h' => [],
                'max_force_kg' => [],
                'concrete_slump' => '' // Initialize slump
            ];

            // Get slump from the first record found
            if ($found_count > 0) {
                $responseData['concrete_slump'] = $records[0]['concrete_slump'];
            }

            $sample_ages_lookup = [1, 1, 7, 28];
            $num_expected = count($sample_ages_lookup); // Should be 4
            $filled_indices = array_fill(0, $num_expected, false); // Track which slots are filled

            // Populate responseData with found records, matching by age if possible
            foreach ($records as $rec) {
                $match_found_for_record = false;
                for ($k = 0; $k < $num_expected; $k++) {
                    // Match if age is correct AND this slot hasn't been filled yet
                    if ($rec['sample_age_at_break'] == $sample_ages_lookup[$k] && !$filled_indices[$k]) {
                        error_log("[Lookup Debug] Matched record ID " . $rec['id'] . " (Age " . $rec['sample_age_at_break'] . ") to form index $k");
                        $responseData['record_id'][$k] = $rec['id'];
                        $responseData['sample_code'][$k] = $rec['sample_code'];
                        $responseData['dimension_l'][$k] = $rec['dimension_l'];
                        $responseData['dimension_w'][$k] = $rec['dimension_w'];
                        $responseData['dimension_h'][$k] = $rec['dimension_h'];
                        $responseData['max_force_kg'][$k] = $rec['max_force_kg'];
                        $filled_indices[$k] = true; // Mark this slot as filled
                        $match_found_for_record = true;
                        break; // Stop trying to match this record once placed
                    }
                }
                if (!$match_found_for_record) {
                    error_log("[Lookup Debug] Could not reliably place record ID " . $rec['id'] . " (Age " . $rec['sample_age_at_break'] . ") into expected slots.");
                    // Decide how to handle orphans? For now, they are ignored in the responseData structure.
                }
            }

            // Fill any remaining empty slots
            for ($k = 0; $k < $num_expected; $k++) {
                if (!$filled_indices[$k]) {
                    $responseData['record_id'][$k] = ''; // Ensure empty ID for new entries
                    $responseData['sample_code'][$k] = ''; // Start empty
                    $responseData['dimension_l'][$k] = '';
                    $responseData['dimension_w'][$k] = '';
                    $responseData['dimension_h'][$k] = '';
                    $responseData['max_force_kg'][$k] = '';
                }
            }

            $response['records'] = $responseData;
            if ($response['found']) {
                $response['message'] = 'رکورد(ها) یافت شد. می‌توانید موارد موجود را ویرایش یا موارد ناقص را تکمیل کنید.';
            } else {
                $response['message'] = 'هیچ رکوردی یافت نشد. فرم برای ثبت بچ جدید.';
            }
        } catch (PDOException $e) {
            error_log("[Lookup Debug] PDO Exception: " . $e->getMessage()); // Log DB errors
            $response['message'] = 'خطای پایگاه داده هنگام جستجو.';
        } catch (Throwable $t) { // Catch any other general error/exception
            error_log("[Lookup Debug] General Throwable: " . $t->getMessage() . " in " . $t->getFile() . " on line " . $t->getLine());
            $response['message'] = 'خطای داخلی سرور.';
        }

        echo json_encode($response);
        exit; // CRUCIAL: stop script execution
        // --- Action: UPDATE BATCH ---
    }
}
// End POST handling


// --- GET Request Handling ---
$form_data = $_SESSION['form_data'] ?? null;
unset($_SESSION['form_data']);
$edit_record = null;
$edit_id = 0;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $current_edit_id = intval($_GET['edit']);
    if ($current_edit_id > 0 && !$form_data) { // Fetch only if not repopulating from failed POST
        try {
            $stmt = $pdo->prepare("SELECT * FROM concrete_tests WHERE id = ?");
            $stmt->execute([$current_edit_id]);
            $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($edit_record) {
                $edit_id = $current_edit_id; /* Set edit_id ONLY if record found */
            } else {
                $_SESSION['error_message'] = "رکورد ویرایش یافت نشد.";
            }
        } catch (PDOException $e) {
            error_log("Fetch Edit ID $current_edit_id err: " . $e->getMessage());
            $_SESSION['error_message'] = "خطا در بازیابی رکورد.";
            $edit_record = null;
        }
    } elseif ($form_data && isset($form_data['id']) && $form_data['id'] == $current_edit_id) {
        // If form_data exists AND the ID matches the GET param, we are repopulating the EDIT form
        $edit_id = $current_edit_id;
    }
}
$search_query = "";
$search_params = [];
$sql_conditions = [];
// MODIFICATION: Add panel_type to filters
$filter_panel_type = trim($_GET['filter_panel_type'] ?? '');
$filter_rejected = $_GET['filter_rejected'] ?? '';
$filter_sample_code = trim($_GET['filter_sample'] ?? '');
$filter_age = trim($_GET['filter_age'] ?? '');
$filter_date_from_j = trim($_GET['filter_date_from'] ?? '');
$filter_date_to_j = trim($_GET['filter_date_to'] ?? '');

// Date From Filter
if (!empty($filter_date_from_j)) {
    $jds = toLatinDigitsPhp($filter_date_from_j);
    $jp = explode('/', $jds);
    if (count($jp) === 3) {
        $ga = jalali_to_gregorian(intval($jp[0]), intval($jp[1]), intval($jp[2]));
        if ($ga) {
            $gs = sprintf('%04d-%02d-%02d', $ga[0], $ga[1], $ga[2]);
            if (DateTime::createFromFormat('Y-m-d', $gs)) {
                $sql_conditions[] = "production_date >= ?";
                $search_params[] = $gs;
            }
        }
    }
}
// Date To Filter
if (!empty($filter_date_to_j)) {
    $jds = toLatinDigitsPhp($filter_date_to_j);
    $jp = explode('/', $jds);
    if (count($jp) === 3) {
        $ga = jalali_to_gregorian(intval($jp[0]), intval($jp[1]), intval($jp[2]));
        if ($ga) {
            $gs = sprintf('%04d-%02d-%02d', $ga[0], $ga[1], $ga[2]);
            if (DateTime::createFromFormat('Y-m-d', $gs)) {
                $sql_conditions[] = "production_date <= ?";
                $search_params[] = $gs;
            }
        }
    }
}
// Sample Code Filter
if (!empty($filter_sample_code)) {
    $sql_conditions[] = "sample_code LIKE ?";
    $search_params[] = "%" . $filter_sample_code . "%";
}
// NEW: Panel Type Filter
if (!empty($filter_panel_type)) {
    $sql_conditions[] = "panel_type = ?";
    $search_params[] = $filter_panel_type;
}

// Age Filter
if ($filter_age !== '' && is_numeric($filter_age)) {
    $sql_conditions[] = "sample_age_at_break = ?";
    $search_params[] = intval($filter_age);
}
// Rejected Status Filter
if ($filter_rejected === '0' || $filter_rejected === '1') {
    $sql_conditions[] = "is_rejected = ?";
    $search_params[] = intval($filter_rejected);
}

// Combine conditions if any exist
if (!empty($sql_conditions)) {
    $search_query = " WHERE " . implode(" AND ", $sql_conditions);
}

// --- Fetch Records ---
try {
    // MODIFICATION: Changed ORDER BY to group by panel_type as well
    $sql = "SELECT * FROM concrete_tests" . $search_query . " ORDER BY production_date DESC, panel_type ASC, sample_age_at_break ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($search_params); // Execute with the built parameters
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error loading data.";
    $records = [];
}
// --- Prepare Edit Form Data ---
$edit_prod_shamsi = '';
$edit_break_shamsi = '';
$edit_code = '';
$edit_l = '';
$edit_w = '';
$edit_h = '';
$edit_force = '';
$edit_age = '';
$edit_strength = '';
$edit_rejected = 0;
$edit_reason = '';
$edit_strength_1 = '';
$edit_strength_7 = '';
$edit_strength_28 = '';
$edit_current_file_path = null;
// NEW: Variable for slump in edit form
$edit_slump = '';
$edit_panel_type = '';

if ($edit_id > 0) {
    if ($edit_record && !$form_data) { // Fresh load for edit
        $edit_prod_shamsi = gregorianToShamsi($edit_record['production_date']);
        $edit_break_shamsi = gregorianToShamsi($edit_record['break_date']);
        $edit_code = $edit_record['sample_code'];
        $edit_l = $edit_record['dimension_l'];
        $edit_w = $edit_record['dimension_w'];
        $edit_h = $edit_record['dimension_h'];
        $edit_force = $edit_record['max_force_kg'];
        $edit_age = $edit_record['sample_age_at_break'];
        $edit_strength = $edit_record['compressive_strength_mpa'];
        $edit_rejected = $edit_record['is_rejected'];
        $edit_reason = $edit_record['rejection_reason'];
        $edit_strength_1 = $edit_record['strength_1_day'];
        $edit_strength_7 = $edit_record['strength_7_day'];
        $edit_strength_28 = $edit_record['strength_28_day'];
        $edit_current_file_path = $edit_record['results_file_path'];
        // NEW: Populate slump and panel type for edit form
        $edit_slump = $edit_record['concrete_slump'];
        $edit_panel_type = $edit_record['panel_type'];
    } elseif ($form_data) { // Repopulate from failed POST
        $edit_prod_shamsi = $form_data['production_date'] ?? '';
        $edit_break_shamsi = $form_data['break_date'] ?? '';
        $edit_code = $form_data['sample_code'] ?? '';
        $edit_l = $form_data['dimension_l'] ?? '';
        $edit_w = $form_data['dimension_w'] ?? '';
        $edit_h = $form_data['dimension_h'] ?? '';
        $edit_force = $form_data['max_force_kg'] ?? '';
        $edit_rejected = isset($form_data['is_rejected']) ? 1 : 0;
        $edit_reason = $form_data['rejection_reason'] ?? '';
        $edit_strength_1 = $form_data['strength_1_day'] ?? '';
        $edit_strength_7 = $form_data['strength_7_day'] ?? '';
        $edit_strength_28 = $form_data['strength_28_day'] ?? '';
        $edit_current_file_path = $form_data['current_file_path'] ?? null;
        // NEW: Repopulate slump from failed POST
        $edit_slump = $form_data['concrete_slump'] ?? '';
    }
}

// --- Other variables ---
$current_shamsi = gregorianToShamsi(date('Y-m-d'));
$current_day = jdate('l');
$pageTitle = $edit_id > 0 ? "ویرایش تست بتن" : "ثبت و مدیریت تست‌های بتن";
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.min.css">
    <style>
        :root {
            --bs-primary: #0d6efd;
            --bs-secondary: #6c757d;
            --bs-success: #198754;
            --bs-danger: #dc3545;
            --bs-light-grey: #f8f9fa;
            --bs-grey-border: #dee2e6;
            --bs-text-muted: #6c757d;
            --input-border-color: #ced4da;
            --input-bg-readonly: #e9ecef;
        }

        body {
            font-family: Vazirmatn, Tahoma, sans-serif;
            background-color: var(--bs-light-grey);
            direction: rtl;
            font-size: 0.85rem;
        }

        body.modal-open {
            overflow: visible;
            /* Or similar */
        }

        .main-container {
            max-width: 1600px;
            margin: auto;
            padding: 1rem;
        }

        .form-label {
            margin-bottom: 0.25rem;
            font-size: 0.7rem;
            color: var(--bs-text-muted);
            font-weight: 500;
            display: block;
        }

        .form-control,
        .form-select {
            font-size: 0.75rem;
            border-radius: 0.2rem;
            border-color: var(--input-border-color);
            min-height: calc(1.5em + 0.5rem + 2px);
            padding: 0.25rem 0.5rem;
        }

        .form-control:read-only {
            background-color: var(--input-bg-readonly);
            cursor: not-allowed;
        }

        .readonly-display {
            background-color: var(--input-bg-readonly);
            border: 1px solid var(--input-border-color);
            padding: 0.25rem 0.5rem;
            min-height: calc(1.5em + 0.5rem + 2px);
            border-radius: 0.2rem;
            font-size: 0.75rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #495057;
        }

        .table th,
        .table td {
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
            padding: 0.4rem;
            font-size: 0.75rem;
            border-color: var(--bs-grey-border);
        }

        .table thead th {
            background-color: var(--bs-light-grey);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 1;
            border-bottom-width: 1px;
        }

        .alert {
            margin-top: 1rem;
            font-size: 0.85rem;
        }

        .loading {
            position: fixed;
            inset: 0;
            background-color: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1060;
            display: none;
        }

        .card {
            border: 1px solid var(--bs-grey-border);
            border-radius: 0.375rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, .075);
        }

        .card-header {
            background-color: #ffffff;
            border-bottom: 1px solid var(--bs-grey-border);
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.6rem 1rem;
        }

        .card-body {
            padding: 1rem;
        }

        .btn {
            font-size: 0.75rem;
            /* Smaller base font */
            padding: 0.2rem 0.4rem;
            /* Reduced padding */
            border-radius: 0.2rem;
            margin-top: 0px;
        }

        .btn-success {
            width: 250px;
        }

        .btn-sm {
            font-size: 0.65rem;
            /* Even smaller font */
            padding: 0.15rem 0.35rem;
            /* Tighter padding */
        }

        .table .btn-sm {
            padding: 0.1rem 0.35rem;
            font-size: 0.7rem;
            line-height: 1.2;
            margin-right: 0.15rem;
        }

        .table td .btn {
            /* Target buttons directly in td */
            font-size: 0.65rem;
            /* Smallest font */
            padding: 0.1rem 0.25rem;
            /* Very tight padding */
            line-height: 2;
            /* Adjust line height */
            margin-right: 0.1rem;
            /* Tiny space between buttons */
            vertical-align: middle;
            /* Align buttons vertically */
            width: auto;
            margin-top: 5px;
        }

        .table td .btn i.bi {
            font-size: 0.75em;
            /* Slightly smaller icon relative to button font */
            vertical-align: text-top;
            /* Adjust icon alignment */
        }

        /* Tweak modal button size if needed */

        .search-card .btn-sm {
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
        }

        /* Tweak batch submit button size if needed */
        #batchTestForm .btn-sm {
            font-size: 0.8rem;
            /* Keep batch submit slightly larger */
            width: 150px;
            padding: 1rem 1rem;
        }

        /* Tweak single edit submit/cancel button size if needed */
        #singleEditForm .btn-sm {
            font-size: 0.8rem;
            /* Keep edit submit slightly larger */
            padding: 0.25rem 0.5rem;
        }

        /* Tighter buttons in table */
        .table td .btn-primary {
            background-color: var(--bs-primary);
            border-color: var(--bs-primary);
            width: 50px;
        }

        .table td .btn-danger {
            background-color: var(--bs-danger);
            border-color: var(--bs-danger);
        }

        .badge {
            font-size: 0.7em;
            padding: 0.3em 0.45em;
        }

        .form-check {
            display: flex;
            align-items: center;
            padding-left: 0;
            margin-top: 0.5rem;
        }

        .form-check-input {
            margin-left: 0.5rem;
            margin-top: 0;
            float: none;
            border-color: var(--input-border-color);
        }

        .form-check-label {
            font-size: 0.75rem;
            margin-bottom: 0;
        }

        .pwt-btn-calendar {
            direction: ltr !important;
        }

        .datepicker-plot-area {
            font-family: Vazirmatn, Tahoma !important;
            direction: rtl !important;
        }

        .datepicker-plot-area .table-days td span {
            direction: rtl !important;
            text-align: center !important;
        }

        .datepicker-plot-area .btn-next,
        .datepicker-plot-area .btn-prev {
            transform: rotate(180deg);
        }

        .sample-input-row {
            border: 1px solid #eee;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border-radius: 0.25rem;
            background-color: #fdfdfd;
        }

        .sample-input-row legend {
            font-size: 0.8rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            padding-bottom: 0.2rem;
            border-bottom: 1px solid #eee;
            width: auto;
        }

        .group-start td {
            border-top: 2px solid #aaa !important;
        }



        /* --- Button Width Fix --- */
        .print-settings-button-wrapper {
            /* Prevent the div from stretching */
            flex-grow: 0;
            /* Don't grow in flex container */
            flex-shrink: 0;
            /* Don't shrink */
            /* Add more specific width control if needed: */
            /* max-width: fit-content; */
            /* Or a specific px value */
        }

        /* Ensure the button itself doesn't have weird width styles */
        .print-settings-button-wrapper .btn {
            width: auto;
            /* Override any forced width */
        }

        /* --- END Button Width Fix --- */

        @media print {
            @page {
                size: A4;
                margin: 2cm 1.5cm 2.5cm 1.5cm;
                /* Top, R/L, Bottom, R/L - Increase bottom margin for footer */

                /* Attempt basic page numbering using CSS counters - might not work reliably in all browsers for "X of Y" */
                @bottom-center {
                    content: "صفحه " counter(page) " از " counter(pages);
                    font-size: 8pt;
                    color: #555;
                }
            }

            body {
                font-size: 9pt;
                padding-top: 70px;
                /* Increased space for header table */
                padding-bottom: 150px;
                /* Increased space for footer + signature */
                width: 100%;
            }

            .main-container {
                max-width: 100%;
                width: 100%;
                margin: 0;
                padding: 0;
            }

            /* --- Elements to HIDE --- */
            .inputFormsContainer,
            .header-content,
            .nav-container,
            .text-gray-300,
            .manage-files-td,
            .fileManagerModal,
            .search-card,
            .manage-files-th,
            .edit-form,
            .single-edit-form,
            .alert,
            #messages,
            .top-date-display,
            .print-button-container,
            /* Includes print settings button */
            .action-buttons-th,
            .action-buttons-td,
            .file-column-th,
            .file-column-td,
            #printSettingsModal,
            .modal-backdrop

            /* Hide print settings modal */
                {
                display: none !important;
                visibility: hidden !important;
            }

            .action-buttons-th,
            .action-buttons-td,
            /* Hide action TH and TD */
            #printSettingsModal

            /* Hide print settings modal */
                {
                display: none !important;
                visibility: hidden !important;
            }

            /* --- Print Header/Footer Display --- */
            #print-header,
            #print-footer {
                display: block !important;
                visibility: visible !important;
            }

            #print-header {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                padding: 5px 10px;
                /* Reduced padding */
                height: 60px;
                /* Adjust height based on logo/content */
                border-bottom: 1px solid #ccc;
                background-color: #fff;
                z-index: 1000;
            }

            #print-footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                width: 100%;
                /* Ensure it spans width */
                padding: 5px 10px 10px 10px;
                /* Add more bottom padding */
                height: auto;
                /* Allow height to be determined by content */
                border-top: 1px solid #ccc;
                background-color: #fff;
                font-size: 8pt;
                z-index: 1000;
                white-space: pre-wrap;
                display: block !important;
                /* Ensure it's block */
                visibility: visible !important;
            }

            #print-footer .footer-text-area {
                display: block;
                /* Ensure block layout */
                width: 100%;
                text-align: center;
                margin-bottom: 8px;
                /* Space between text and table */
                font-size: 8pt;
                line-height: 1.3;
            }

            #print-footer .page-number-container {
                display: none;
                /* Still hide if using CSS counters */
            }

            /* Signature Table Styles */
            #print-signature-table {
                width: 100%;
                margin-top: 5px;
                /* Reset margin, handled by footer padding */
                border-collapse: collapse;
                table-layout: fixed;
                border: 1px solid #ccc;
                page-break-inside: avoid;
                /* Try to prevent breaking inside the table */
            }

            #print-signature-table thead th {
                /* Style headers */
                border: 1px solid #ccc;
                padding: 4px 5px;
                /* Padding for header cells */
                text-align: center;
                font-size: 7pt;
                font-weight: bold;
                /* Make headers bold */
                vertical-align: middle;
                background-color: #f8f8f8;
                /* Optional light background for headers */
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            #print-signature-table tbody td {
                /* Style signing cells */
                border: 1px solid #ccc;
                height: 50px;
                /* Define signing space height */
                padding: 0;
                /* Remove padding from empty cells */
            }

            /* --- End Print Footer Styles --- */
            /* --- Print Header Table Styles --- */
            #print-header-table {
                width: 100%;
                border-collapse: collapse;
                table-layout: fixed;
            }

            #print-header-table td {
                border: none;
                vertical-align: middle;
                padding: 0 5px;
            }

            #print-header-table .logo-left {
                width: 20%;
                text-align: right;
            }

            #print-header-table .header-center {
                width: 60%;
                text-align: center;
                font-size: 10pt;
                font-weight: bold;
                line-height: 1.2;
            }

            #print-header-table .logo-right {
                width: 20%;
                text-align: left;
            }

            #print-header-table img {
                max-width: 100%;
                height: auto;
                max-height: 45px;
            }

            /* Control logo size */
            /* --- End Print Header Table Styles --- */

            /* --- Print Footer Styles --- */
            #print-signature-table {
                width: 100%;
                margin-top: 10px;
                border-collapse: collapse;
                table-layout: fixed;
            }

            #print-signature-table td {
                border: 1px solid #ccc;
                padding: 15px 5px 5px 5px;
                text-align: center;
                font-size: 7pt;
                vertical-align: bottom;
                height: 40px;
            }

            .page-number-container {
                display: none;
                /* Hide the JS placeholder if CSS counters work */
            }

            /* --- End Print Footer Styles --- */


            /* --- Table Full Width & Styling --- */
            .table-responsive {
                overflow: visible !important;
                width: 100%;
            }

            .table {
                table-layout: fixed;
                /* Use fixed for better width control */
                width: 100% !important;
                margin-bottom: 0;
            }

            .table th,
            .table td {
                font-size: 10pt;
                padding: 0.15rem 0.2rem;
                border-color: #ccc !important;
                white-space: normal;
                word-wrap: break-word;
                padding: 0.4rem 0.2rem;
                /* << INCREASE PADDING for more row height */
                min-height: 80px;
                /* << Optional: Set minimum height */
                vertical-align: middle;
                /* Ensure vertical alignment */
            }

            /* Adjust column widths using percentages (ensure they add up close to 100%) */
            .table th:nth-child(1),
            .table td:nth-child(1) {
                width: 13%;
            }

            /* تاریخ تولید */
            .table th:nth-child(2),
            .table td:nth-child(2) {
                width: 15%;
            }

            /* کد نمونه */
            .table th:nth-child(3),
            .table td:nth-child(3) {
                width: 13%;
            }

            /* تاریخ شکست */
            .table th:nth-child(4),
            .table td:nth-child(4) {
                width: 7%;
            }

            /* سن */
            .table th:nth-child(5),
            .table td:nth-child(5) {
                width: 27%;
            }

            /* ابعاد */
            .table th:nth-child(6),
            .table td:nth-child(6) {
                width: 15%;
            }

            /* مقاومت */
            .table th:nth-child(7),
            .table td:nth-child(7) {
                width: 10%;
            }

            /* وضعیت */
            /* Other print styles (.card, .badge, etc.) */
            /* ... */
        }

        #print-header,
        #print-footer {
            display: none;
        }

        .card {
            border: none;
            box-shadow: none;
            margin-bottom: 0.5rem;
        }

        .card-header {
            background-color: #fff !important;
            border-bottom: 1px solid #ccc;
            padding: 0.2rem 0;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .card-body {
            padding: 0;
        }

        .table thead th {
            background-color: #eee !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .badge {
            border: 1px solid #ccc;
            color: #000;
            background-color: #fff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            font-size: 7pt;
            padding: 0.2em 0.3em;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        a::after {
            content: none !important;
        }

        .table-bordered th,
        .table-bordered td {
            border: 1px solid #dee2e6 !important;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.03) !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .group-start td {
            border-top: 2px solid #666 !important;
        }

        /* Example: ابعاد */


        #print-header,
        #print-footer {
            display: none;
        }

        /* Hide by default */
        /* Style for file link */
        .file-link a {
            text-decoration: none;
        }

        .file-link i {
            margin-right: 5px;
        }

        .file-link .no-file {
            color: #999;
            font-style: italic;
        }

        /* Signature table appearance */
        #print-signature-table {
            width: 100%;
            margin-top: 10px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        #print-signature-table td {
            border: 1px solid #ccc;
            padding: 20px 5px 5px 5px;
            /* More space for signing */
            text-align: center;
            font-size: 7pt;
            vertical-align: bottom;
        }

        #print-header,
        #print-footer {
            display: none;
        }
    </style>
</head>

<body>
    <div id="print-header">
        <table id="print-header-table">
            <tr>
                <td class="logo-left">
                    <img src="/assets/images/alumglass-farsi-logo-H40.png" alt="Logo Left" style="max-height: 40px; display: block;">
                </td>
                <td class="header-center">
                    <?php echo nl2br(htmlspecialchars($print_settings['header'])); ?>
                </td>
                <td class="logo-right">
                    <img src="/assets/images/hotelfereshteh1.png" alt="Logo Right" style="max-height: 40px; display: block;">
                </td>
            </tr>
        </table>
    </div>
    <div id="print-footer">
        <div class="footer-text-area">
            <?php echo nl2br(htmlspecialchars($print_settings['footer'])); ?>
            <div class="page-number-container">صفحه: <span class="page-number"></span> / <span class="total-pages"></span></div>
        </div>

        <?php if (!empty($print_settings['signature_area'])) : ?>
            <table id="print-signature-table">
                <thead>
                    <tr><?php echo $print_settings['signature_area']; // Output the TD cells directly as headers 
                        ?></tr>
                </thead>
                <tbody>
                    <tr>
                        <?php
                        // Create empty TD cells below the headers for signing space
                        $signature_headers = $print_settings['signature_area'];
                        preg_match_all('/<td[^>]*>.*?<\/td>/i', $signature_headers, $matches);
                        if (!empty($matches[0])) {
                            echo str_repeat('<td></td>', count($matches[0]));
                        }
                        ?>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php require_once 'header.php'; ?>
    <div class="loading">
        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">...</span></div>
    </div>
    <div class="main-container">
        <div class="d-flex justify-content-between align-items-center mb-3 top-date-display">

            <div class="small text-muted">امروز: <?php echo htmlspecialchars($current_day . '، ' . $current_shamsi); ?></div>
        </div>
        <div id="messages">
            <?php if (isset($_SESSION['success_message'])) : ?>
                <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                    <?php echo $_SESSION['success_message'];
                    unset($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])) : ?>
                <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                    <?php echo $_SESSION['error_message'];
                    unset($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning_message'])) : // Display warning messages too 
            ?>
                <div class="alert alert-warning alert-dismissible fade show py-2" role="alert">
                    <?php echo $_SESSION['warning_message'];
                    unset($_SESSION['warning_message']); ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        </div>

        <div class="card search-card">
            <div class="card-header"><i class="bi bi-search me-2"></i>جستجو</div>
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md"><label>تولید از:</label><input type="text" class="form-control form-control-sm datepicker" name="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from_j); ?>"></div>
                    <div class="col-md"><label>تولید تا:</label><input type="text" class="form-control form-control-sm datepicker" name="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to_j); ?>"></div>

                    <div class="col-md">
                        <label>نوع پنل:</label>
                        <select class="form-select form-select-sm" name="filter_panel_type">
                            <option value="">همه</option>
                            <?php foreach ($panel_types as $pt) : ?>
                                <option value="<?php echo htmlspecialchars($pt); ?>" <?php echo ($filter_panel_type === $pt) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md">
                        <label>سن:</label>
                        <select class="form-select form-select-sm" name="filter_age">
                            <option value="" <?php echo ($filter_age === '') ? 'selected' : ''; ?>>همه</option>
                            <option value="1" <?php echo ($filter_age === '1') ? 'selected' : ''; ?>>1 روزه</option>
                            <option value="7" <?php echo ($filter_age === '7') ? 'selected' : ''; ?>>7 روزه</option>
                            <option value="28" <?php echo ($filter_age === '28') ? 'selected' : ''; ?>>28 روزه</option>
                        </select>
                    </div>
                    <div class="col-md"><label>وضعیت:</label><select class="form-select form-select-sm" name="filter_rejected">
                            <option value="" <?php echo ($filter_rejected === '') ? 'selected' : ''; ?>>همه</option>
                            <option value="0" <?php echo ($filter_rejected === '0') ? 'selected' : ''; ?>>قبول</option>
                            <option value="1" <?php echo ($filter_rejected === '1') ? 'selected' : ''; ?>>مردود</option>
                        </select></div>
                    <div class="col-md-auto"><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button></div>
                    <div class="col-md-auto"><a href="<?php echo htmlspecialchars(strtok($_SERVER["REQUEST_URI"], '?')); ?>" class="btn btn-secondary btn-sm"><i class="bi bi-x-lg"></i></a></div>
                </form>
            </div>
        </div>
        <div class="d-grid gap-2 d-md-flex justify-content-md-start mb-3">
            <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#inputFormsContainer" aria-expanded="false" aria-controls="inputFormsContainer" id="toggleFormBtn">
                <i class="bi bi-plus-lg"></i> نمایش فرم ثبت / ویرایش
            </button>
        </div>
        <div class="collapse" id="inputFormsContainer">
            <?php if ($edit_id > 0) : // --- SHOW SINGLE EDIT FORM --- 
            ?>
                <div class="card single-edit-form">
                    <div class="card-header"><i class="bi bi-pencil-square me-2"></i>ویرایش تست (<?php echo htmlspecialchars($edit_code ?? ''); ?>)</div>
                    <div class="card-body">
                        <form id="singleEditForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?edit=' . $edit_id; ?>" method="post" class="needs-validation" novalidate enctype="multipart/form-data">
                            <input type="hidden" name="action" value="save_update">
                            <input type="hidden" name="id" value="<?php echo $edit_id; ?>">
                            <input type="hidden" name="current_file_path" value="<?php echo htmlspecialchars($edit_current_file_path ?? ''); ?>">

                            <div class="row g-3 mb-3">
                                <div class="col-md-6 col-lg-2"><label>تاریخ تولید:</label><input class="form-control form-control-sm" value="<?php echo htmlspecialchars($edit_prod_shamsi ?? ''); ?>" readonly><input type="hidden" name="production_date" value="<?php echo htmlspecialchars($edit_prod_shamsi ?? ''); ?>"></div>
                                <div class="col-md-6 col-lg-2"><label>نوع پنل:</label><input class="form-control form-control-sm" value="<?php echo htmlspecialchars($edit_panel_type ?? ''); ?>" readonly></div>
                                <div class="col-md-6 col-lg-2"><label>اسلامپ:</label><input type="number" step="0.1" min="0" class="form-control form-control-sm" name="concrete_slump" value="<?php echo htmlspecialchars($edit_slump ?? ''); ?>"></div>
                                <div class="col-md-6 col-lg-2"><label>کد نمونه:</label><input class="form-control form-control-sm" value="<?php echo htmlspecialchars($edit_code ?? ''); ?>" readonly><input type="hidden" name="sample_code" value="<?php echo htmlspecialchars($edit_code ?? ''); ?>"></div>
                                <div class="col-md-6 col-lg-2"><label>تاریخ شکست:</label><input type="text" class="form-control form-control-sm datepicker" name="break_date" value="<?php echo htmlspecialchars($edit_break_shamsi ?? ''); ?>"></div>
                                <div class="col-md-6 col-lg-2"><label>سن شکست:</label>
                                    <div id="edit_sample_age_display" class="readonly-display"><?php echo htmlspecialchars($edit_age ?? ''); ?></div>
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4 col-lg-2"><label>طول(mm):</label><input type="number" step="0.01" min="0.01" class="form-control form-control-sm dimension-input" name="dimension_l" value="<?php echo htmlspecialchars($edit_l ?? ''); ?>"></div>
                                <div class="col-md-4 col-lg-2"><label>عرض(mm):</label><input type="number" step="0.01" min="0.01" class="form-control form-control-sm dimension-input" name="dimension_w" value="<?php echo htmlspecialchars($edit_w ?? ''); ?>"></div>
                                <div class="col-md-4 col-lg-2"><label>ارتفاع(mm):</label><input type="number" step="0.01" min="0.01" class="form-control form-control-sm dimension-input" name="dimension_h" value="<?php echo htmlspecialchars($edit_h ?? ''); ?>"></div>
                                <div class="col-md-6 col-lg-3"><label>Fx max(kg):</label><input type="number" step="0.01" min="0.01" class="form-control form-control-sm" id="edit_max_force_kg" name="max_force_kg" value="<?php echo htmlspecialchars($edit_force ?? ''); ?>"></div>
                                <div class="col-md-6 col-lg-3"><label>مقاومت محاسبه (MPa):</label>
                                    <div id="edit_compressive_strength_display" class="readonly-display"><?php echo $edit_strength ? number_format((float)$edit_strength, 2) : ''; ?></div>
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4 col-lg-3"><label>مقاومت ثبت ۱ روزه(MPa):</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="strength_1_day" value="<?php echo htmlspecialchars($edit_strength_1 ?? ''); ?>"></div>
                                <div class="col-md-4 col-lg-3"><label>مقاومت ثبت ۷ روزه(MPa):</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="strength_7_day" value="<?php echo htmlspecialchars($edit_strength_7 ?? ''); ?>"></div>
                                <div class="col-md-4 col-lg-3"><label>مقاومت ثبت ۲۸ روزه(MPa):</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="strength_28_day" value="<?php echo htmlspecialchars($edit_strength_28 ?? ''); ?>"></div>
                            </div>
                            <div class="row g-3 mb-3 align-items-end">
                                <div class="col-md-6 col-lg-4"><label>دلیل مردودی:</label><input type="text" class="form-control form-control-sm" id="edit_rejection_reason" name="rejection_reason" value="<?php echo htmlspecialchars($edit_reason ?? ''); ?>" <?php echo ($edit_rejected != 1) ? 'disabled' : ''; ?>>
                                    <div class="invalid-feedback small">...</div>
                                </div>
                                <div class="col-md-auto">
                                    <div class="form-check"><input class="form-check-input" type="checkbox" id="edit_is_rejected" name="is_rejected" value="1" <?php echo ($edit_rejected == 1) ? 'checked' : ''; ?>><label class="form-check-label">مردود؟</label></div>
                                </div>
                            </div>
                            <div class="row g-3 mb-1 align-items-center">
                                <div class="col-md-6 col-lg-4">
                                    <label for="test_results_files_input" class="form-label">فایل(های) نتایج (PDF/Image/...):</label>
                                    <input type="file" class="form-control form-control-sm" id="test_results_files_input" name="test_results_files[]" accept=".pdf,.jpg,.jpeg,.png,.gif,.txt,.docx,.xlsx" multiple>
                                </div>
                                <div class="col-md-6 col-lg-4 pt-3">
                                    <span class="text-muted small">فایل‌های موجود در جدول اصلی قابل مشاهده/حذف هستند.</span>
                                </div>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-12">
                                    <div class="progress" id="uploadProgressBarContainer" style="display: none; height: 5px;">
                                        <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-4">
                                <div class="col-12 d-flex justify-content-start"> <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i> ذخیره تغییرات</button> <a href="<?php echo htmlspecialchars(strtok($_SERVER["REQUEST_URI"], '?')); ?>" class="btn btn-secondary btn-sm ms-2"><i class="bi bi-x-lg me-1"></i> انصراف</a> </div>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else : // --- SHOW BATCH INPUT FORM --- 
            ?>
                <div class="card edit-form">
                    <div class="card-header"><i class="bi bi-clipboard-plus-fill me-2"></i><span id="batchFormTitle">ثبت بچ جدید نمونه‌ها</span></div>
                    <div class="card-body">
                        <form id="batchTestForm" method="post" class="needs-validation" novalidate>
                            <input type="hidden" name="action" id="batchFormAction" value="save_batch">
                            <div class="row g-3 mb-4 align-items-end">
                                <div class="col-md-6 col-lg-3">
                                    <label for="production_date" class="form-label">تاریخ تولید بچ:*</label>
                                    <input type="text" class="form-control form-control-sm datepicker" id="production_date" name="production_date" required autocomplete="off">
                                    <div class="invalid-feedback">تاریخ تولید الزامی است.</div>
                                </div>
                                <div class="col-md-6 col-lg-3">
                                    <label for="panel_type" class="form-label">نوع پنل:*</label>
                                    <select class="form-select form-select-sm" id="panel_type" name="panel_type" required>
                                        <option value="" selected disabled>-- انتخاب کنید --</option>
                                        <?php foreach ($panel_types as $pt) : ?>
                                            <option value="<?php echo htmlspecialchars($pt); ?>"><?php echo htmlspecialchars($pt); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">نوع پنل الزامی است.</div>
                                </div>
                                <div class="col-md-6 col-lg-2">
                                    <label for="concrete_slump" class="form-label">اسلامپ (cm):</label>
                                    <input type="number" step="0.1" min="0" class="form-control form-control-sm" id="concrete_slump" name="concrete_slump">
                                </div>
                                <div class="col-md-6 col-lg-4" id="batchDateStatus">
                                    <span class="text-muted small">تاریخ و نوع پنل را برای ثبت جدید یا ویرایش انتخاب کنید.</span>
                                </div>
                            </div>
                            <?php
                            $sample_labels = ["نمونه آنی/۱ روزه", "نمونه ۱ روزه (دستی)", "نمونه ۷ روزه", "نمونه ۲۸ روزه"];
                            $num_samples_form = count($sample_labels);
                            for ($i = 0; $i < $num_samples_form; $i++) :
                                $is_required = ($i < 2);
                                $required_attr = $is_required ? 'required' : '';
                            ?>
                                <fieldset class="sample-input-row">
                                    <legend><?php echo $sample_labels[$i]; ?></legend>
                                    <input type="hidden" name="record_id[]" id="record_id_<?php echo $i; ?>" value="">
                                    <div class="row g-3">
                                        <div class="col-md-6 col-lg-3"><label>کد نمونه:*</label><input type="text" class="form-control form-control-sm" name="sample_code[]" id="sample_code_<?php echo $i; ?>" required></div>
                                        <div class="col-md-6 col-lg-3"><label>Fx max(kg):<?php echo $is_required ? '*' : ''; ?></label><input type="number" step="0.01" min="0.01" class="form-control form-control-sm" name="max_force_kg[]" id="max_force_kg_<?php echo $i; ?>" <?php echo $required_attr; ?>></div>
                                        <div class="col-md-4 col-lg-2"><label>طول(mm):<?php echo $is_required ? '*' : ''; ?></label><input type="number" step="0.01" min="0.01" class="form-control form-control-sm dimension-input" name="dimension_l[]" id="dimension_l_<?php echo $i; ?>" <?php echo $required_attr; ?>></div>
                                        <div class="col-md-4 col-lg-2"><label>عرض(mm):<?php echo $is_required ? '*' : ''; ?></label><input type="number" step="0.01" min="0.01" class="form-control form-control-sm dimension-input" name="dimension_w[]" id="dimension_w_<?php echo $i; ?>" <?php echo $required_attr; ?>></div>
                                        <div class="col-md-4 col-lg-2"><label>ارتفاع(mm):<?php echo $is_required ? '*' : ''; ?></label><input type="number" step="0.01" min="0.01" class="form-control form-control-sm dimension-input" name="dimension_h[]" id="dimension_h_<?php echo $i; ?>" <?php echo $required_attr; ?>></div>
                                    </div>
                                </fieldset>
                            <?php endfor; ?>
                            <div class="row mt-4">
                                <div class="col-12 d-flex justify-content-start">
                                    <button type="submit" class="btn btn-success btn-sm" id="batchSubmitButton">
                                        <i class="bi bi-check-lg me-1"></i> <span id="batchSubmitButtonText">ثبت <?php echo $num_samples_form; ?> نمونه</span>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm ms-2" onclick="clearBatchForm(true)">پاک کردن فرم</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; // End conditional form display 
            ?>
        </div>

        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0 h6"><i class="bi bi-table me-2"></i>لیست تست‌ها</h5>
                <div class="d-flex align-items-center print-button-container">
                    <span class="badge bg-light text-dark border me-3">تعداد: <?php echo count($records); ?></span>
                    <?php
                    $query_string = $_SERVER['QUERY_STRING'] ?? '';
                    parse_str($query_string, $query_params);
                    unset($query_params['edit']);
                    $filtered_query_string = http_build_query($query_params);
                    $print_page_url = 'concrete_tests_print.php?' . $filtered_query_string;
                    $excel_export_url = 'export_concrete_csv.php?' . $filtered_query_string;
                    $pdf_export_url   = 'export_concrete_pdf.php?' . $filtered_query_string;
                    ?>
                    <div class="btn-group btn-group-sm ms-2">
                        <a href="<?php echo htmlspecialchars($excel_export_url); ?>" target="_blank" class="btn btn-outline-success" title="خروجی اکسل (CSV)"><i class="bi bi-file-earmark-excel me-1"></i> اکسل</a>
                        <a href="<?php echo htmlspecialchars($pdf_export_url); ?>" target="_blank" class="btn btn-outline-danger" title="خروجی PDF"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</a>
                        <a href="<?php echo htmlspecialchars($print_page_url); ?>" target="_blank" class="btn btn-outline-secondary" title="باز کردن صفحه آماده چاپ"><i class="bi bi-printer me-1"></i> چاپ</a>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-sm mb-0">
                        <thead class="sticky-top">
                            <tr>
                                <th class="action-buttons-th" style="width: 50px;">عملیات</th>
                                <th style="width: 85px;">تاریخ تولید</th>
                                <th style="min-width: 90px;">نوع پنل</th>
                                <th style="width: 60px;">اسلامپ</th>
                                <th style="min-width: 80px;">کد نمونه</th>
                                <th style="width: 85px;">تاریخ شکست</th>
                                <th style="width: 50px;">سن</th>
                                <th style="min-width: 100px;">ابعاد</th>
                                <th style="width: 70px;">مقاومت</th>
                                <th class="manage-files-th" style="width: 90px;">مدیریت فایل‌ها</th>
                                <th style="width: 60px;">وضعیت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)) : ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted py-3">رکوردی یافت نشد.</td>
                                </tr>
                                <?php else :
                                // MODIFICATION: Grouping logic now includes panel_type
                                $grouped_records = [];
                                foreach ($records as $record) {
                                    $group_key = $record['production_date'] . '___' . $record['panel_type']; // Use a unique separator
                                    $grouped_records[$group_key][] = $record;
                                }

                                foreach ($grouped_records as $group_key => $group_records) :
                                    $row_count = count($group_records);
                                    $first_row_in_group = true;
                                    list($prod_date, $panel_type_group) = explode('___', $group_key);

                                    foreach ($group_records as $record) :
                                        $row_class = $record['is_rejected'] ? 'table-danger' : '';
                                        if ($first_row_in_group) {
                                            $row_class .= ' group-start';
                                        }
                                        $associated_file = find_test_file($record['id'], $record['sample_code']);
                                ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <td class="action-buttons-td align-middle">
                                                <div class="d-flex justify-content-center align-items-center">
                                                    <a href="?edit=<?php echo $record['id']; ?>" class="btn btn-primary btn-sm" title="ویرایش"><i class="bi bi-pencil"></i></a>
                                                    <button type="button" class="btn btn-danger btn-sm" title="حذف" onclick="confirmDelete(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars(addslashes($record['sample_code']), ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
                                                    <?php // --- Download Button ---
                                                    if ($associated_file):
                                                        $file_url = UPLOAD_DIRT . $associated_file['relative_path'];
                                                        $file_basename = $associated_file['basename'];
                                                    ?>
                                                        <a href="<?php echo htmlspecialchars($file_url); ?>" class="btn btn-success btn-sm" title="دانلود: <?php echo htmlspecialchars($file_basename); ?>" download="<?php echo htmlspecialchars($file_basename); ?>" target="_blank">
                                                            <i class="bi bi-download"></i>
                                                        </a>
                                                    <?php endif; // --- End Download Button --- 
                                                    ?>
                                                </div>
                                            </td>
                                            <?php if ($first_row_in_group) : // Show production date, panel type, and slump spanning the group
                                            ?>
                                                <td class="align-middle" rowspan="<?php echo $row_count; ?>">
                                                    <?php $prod_shamsi = gregorianToShamsi($prod_date);
                                                    echo !empty($prod_shamsi) ? $prod_shamsi : '-'; ?>
                                                </td>
                                                <td class="align-middle" rowspan="<?php echo $row_count; ?>">
                                                    <?php echo htmlspecialchars($panel_type_group); ?>
                                                </td>
                                                <td class="align-middle" rowspan="<?php echo $row_count; ?>">
                                                    <?php echo htmlspecialchars($record['concrete_slump'] ?? '-'); ?>
                                                </td>
                                            <?php endif; ?>
                                            <td><?php echo htmlspecialchars($record['sample_code']); ?></td>
                                            <td><?php $break_shamsi = gregorianToShamsi($record['break_date']);
                                                echo !empty($break_shamsi) ? $break_shamsi : '-'; ?></td>
                                            <td><?php echo htmlspecialchars($record['sample_age_at_break'] ?? '-'); ?></td>
                                            <td><?php echo ($record['dimension_l'] && $record['dimension_w'] && $record['dimension_h']) ? htmlspecialchars(number_format((float)$record['dimension_l'], 1)) . 'x' . htmlspecialchars(number_format((float)$record['dimension_w'], 1)) . 'x' . htmlspecialchars(number_format((float)$record['dimension_h'], 1)) : '-'; ?></td>
                                            <td>
                                                <?php // Display the relevant strength based on age
                                                $display_strength = '-';
                                                if ($record['sample_age_at_break'] == 1) {
                                                    if ($record['strength_1_day'] !== null) {
                                                        $display_strength = "<div style='border-bottom: 1px solid black; font-weight: bold;'>مقاومت 1 روزه</div><div>" . number_format((float)$record['strength_1_day'], 2) . "</div>";
                                                    } elseif ($record['compressive_strength_mpa'] !== null) {
                                                        $display_strength = "<div style='border-bottom: 1px solid black; font-weight: bold;'>مقاومت نمونه 1 روزه</div><div>" . number_format((float)$record['compressive_strength_mpa'], 2) . "</div>";
                                                    }
                                                } elseif ($record['sample_age_at_break'] == 7 && $record['strength_7_day'] !== null) {
                                                    $display_strength = "<div style='border-bottom: 1px solid black; font-weight: bold;'>مقاومت 7 روزه</div><div>" . number_format((float)$record['strength_7_day'], 2) . "</div>";
                                                } elseif ($record['sample_age_at_break'] == 28 && $record['strength_28_day'] !== null) {
                                                    $display_strength = "<div style='border-bottom: 1px solid black; font-weight: bold;'>مقاومت 28 روزه</div><div>" . number_format((float)$record['strength_28_day'], 2) . "</div>";
                                                } elseif ($record['compressive_strength_mpa'] !== null) {
                                                    $display_strength = "<div style='border-bottom: 1px solid black; font-weight: bold;'>" . number_format((float)$record['compressive_strength_mpa'], 2) . "</div>";
                                                }
                                                echo $display_strength;
                                                ?>
                                            </td>
                                            <td class="manage-files-td align-middle">
                                                <button type="button" class="btn btn-info btn-sm" onclick="openFileManagerModal('<?php echo $record['production_date']; ?>', '<?php echo htmlspecialchars(addslashes($record['sample_code']), ENT_QUOTES); ?>')" title="مشاهده و مدیریت فایل‌ها"><i class="bi bi-folder2-open"></i></button>
                                            </td>
                                            <td> <?php if ($record['is_rejected']) : ?> <span class="badge bg-danger" <?php if (!empty($record['rejection_reason'])) echo 'data-bs-toggle="tooltip" title="' . htmlspecialchars($record['rejection_reason']) . '"'; ?>> مردود <?php if (!empty($record['rejection_reason'])) : ?> <i class="bi bi-info-circle ms-1"></i> <?php endif; ?> </span> <?php else : ?> <span class="badge bg-success">قبول</span> <?php endif; ?> </td>
                                        </tr>
                            <?php
                                        $first_row_in_group = false;
                                    endforeach; // End loop through records in the group
                                endforeach; // End loop through grouped dates
                            endif; // End if empty records
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <form id="deleteRecordForm" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" style="display: none;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteRecordIdInput" value="">
        </form>
        <div class="modal fade" id="fileManagerModal" tabindex="-1" aria-labelledby="fileManagerModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="fileManagerModalLabel">مدیریت فایل‌ها برای نمونه: <span id="fileManagerSampleCode"></span> (<span id="fileManagerProdDate"></span>)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="fileManagerBody">
                        <p class="text-center">در حال بارگذاری لیست فایل‌ها...</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">بستن</button>
                    </div>
                </div>
            </div>
        </div>



        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.min.js"></script>

        <script>
            // --- 0. Helpers & Initializations ---
            function toLatinDigits(num) {
                if (num == null || typeof num == 'undefined') return '';
                const p = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
                    a = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
                    l = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
                let res = String(num);
                for (let i = 0; i < 10; i++) {
                    res = res.replace(new RegExp(p[i], 'g'), l[i]);
                    res = res.replace(new RegExp(a[i], 'g'), l[i]);
                }
                return res;
            }
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function(el) {
                return new bootstrap.Tooltip(el);
            });
            // Bootstrap Modal Instances (Create once)
            var printModalElement = document.getElementById('printSettingsModal');
            var printModal = printModalElement ? new bootstrap.Modal(printModalElement, {
                keyboard: false,
                backdrop: 'static'
            }) : null;
            var fileManagerModalElement = document.getElementById('fileManagerModal');
            var fileManagerBsModal = fileManagerModalElement ? new bootstrap.Modal(fileManagerModalElement) : null;

            // Form Element References (Get once)
            const singleEditFormElement = document.getElementById('singleEditForm');
            const batchFormElement = document.getElementById('batchTestForm');
            const loadingElement = document.querySelector('.loading');
            const batchProdDateInput = document.getElementById('production_date');
            // NEW: Selectors for new fields
            const batchPanelTypeSelect = document.getElementById('panel_type');
            const batchSlumpInput = document.getElementById('concrete_slump');

            const batchFormActionInput = document.getElementById('batchFormAction');
            const batchStatusDiv = document.getElementById('batchDateStatus');
            const batchFormTitle = document.getElementById('batchFormTitle');
            const batchSubmitButton = document.getElementById('batchSubmitButton');
            const batchSubmitButtonText = document.getElementById('batchSubmitButtonText');
            const batchRecordIdInputs = batchFormElement ? batchFormElement.querySelectorAll('input[name="record_id[]"]') : [];
            const batchSampleCodeInputs = batchFormElement ? batchFormElement.querySelectorAll('input[name="sample_code[]"]') : [];
            const batchDimLInputs = batchFormElement ? batchFormElement.querySelectorAll('input[name="dimension_l[]"]') : [];
            const batchDimWInputs = batchFormElement ? batchFormElement.querySelectorAll('input[name="dimension_w[]"]') : [];
            const batchDimHInputs = batchFormElement ? batchFormElement.querySelectorAll('input[name="dimension_h[]"]') : [];
            const batchForceInputs = batchFormElement ? batchFormElement.querySelectorAll('input[name="max_force_kg[]"]') : [];
            const collapseElement = document.getElementById('inputFormsContainer');
            const toggleFormBtn = document.getElementById('toggleFormBtn');


            // --- 1. General Functions ---
            function confirmDelete(id, code) {
                const escapedCode = code.replace(/'/g, "\\'").replace(/"/g, '\\"');
                const message = `آیا از حذف تست '${escapedCode}' با شناسه ${id} اطمینان دارید؟`;
                if (confirm(message)) {
                    const deleteForm = document.getElementById('deleteRecordForm');
                    const idInput = document.getElementById('deleteRecordIdInput');
                    if (deleteForm && idInput) {
                        idInput.value = id;
                        deleteForm.submit();
                    } else {
                        console.error("Hidden delete form elements not found!");
                        alert("خطا در ارسال درخواست حذف. لطفا صفحه را رفرش کنید.");
                    }
                }
            }

            function gregorianToShamsiJs(gDate) {
                if (!gDate || !/^\d{4}-\d{2}-\d{2}$/.test(gDate)) return gDate;
                try {
                    if (typeof persianDate === 'undefined') return gDate;
                    const parts = gDate.split('-');
                    const pd = new persianDate([parseInt(parts[0]), parseInt(parts[1]), parseInt(parts[2])]);
                    return pd.format('YYYY/MM/DD');
                } catch (e) {
                    return gDate;
                }
            }

            // --- 2. Batch Form Logic ---
            function clearBatchForm(clearMainFields = false) {
                if (!batchFormElement) return;

                if (clearMainFields) {
                    if (batchProdDateInput) {
                        batchProdDateInput.value = '';
                        if (typeof $ !== 'undefined' && $(batchProdDateInput).data('datepicker')) {
                            $(batchProdDateInput).datepicker('setDate', null);
                        }
                    }
                    // NEW: Reset panel type and slump
                    if (batchPanelTypeSelect) batchPanelTypeSelect.value = '';
                    if (batchSlumpInput) batchSlumpInput.value = '';
                }

                // Reset all sample rows
                for (let i = 0; i < 4; i++) {
                    const is_required = (i < 2);
                    if (batchRecordIdInputs[i]) batchRecordIdInputs[i].value = '';
                    if (batchSampleCodeInputs[i]) {
                        batchSampleCodeInputs[i].value = '';
                        batchSampleCodeInputs[i].readOnly = false;
                        batchSampleCodeInputs[i].required = true;
                        batchSampleCodeInputs[i].disabled = false;
                    }
                    if (batchDimLInputs[i]) {
                        batchDimLInputs[i].value = '';
                        batchDimLInputs[i].required = is_required;
                        batchDimLInputs[i].disabled = false;
                    }
                    if (batchDimWInputs[i]) {
                        batchDimWInputs[i].value = '';
                        batchDimWInputs[i].required = is_required;
                        batchDimWInputs[i].disabled = false;
                    }
                    if (batchDimHInputs[i]) {
                        batchDimHInputs[i].value = '';
                        batchDimHInputs[i].required = is_required;
                        batchDimHInputs[i].disabled = false;
                    }
                    if (batchForceInputs[i]) {
                        batchForceInputs[i].value = '';
                        batchForceInputs[i].required = is_required;
                        batchForceInputs[i].disabled = false;
                    }
                }

                // Reset form state indicators
                if (batchFormActionInput) batchFormActionInput.value = 'save_batch';
                if (batchFormTitle) batchFormTitle.textContent = 'ثبت بچ جدید نمونه‌ها';
                if (batchSubmitButtonText) batchSubmitButtonText.textContent = 'ثبت ۴ نمونه';
                if (batchStatusDiv) batchStatusDiv.innerHTML = '<span class="text-muted small">تاریخ و نوع پنل را برای ثبت جدید یا ویرایش انتخاب کنید.</span>';
                if (batchSubmitButton) batchSubmitButton.disabled = false;
                if (batchProdDateInput) batchProdDateInput.disabled = false;
                if (batchPanelTypeSelect) batchPanelTypeSelect.disabled = false;
                if (batchSlumpInput) batchSlumpInput.disabled = false;
                batchFormElement.classList.remove('was-validated');
            }

            // MODIFICATION: This is the function from your request, now updated
            function populateBatchForm(data) {
                if (!batchFormElement || !data || !data.records) {
                    console.error("Populate error: Invalid data received", data);
                    clearBatchForm(false);
                    if (batchStatusDiv) batchStatusDiv.innerHTML = '<span class="text-warning small">خطا در پردازش اطلاعات دریافتی.</span>';
                    return;
                }
                const records = data.records;
                console.log("Populating form with records:", records);

                // NEW: Populate the slump value for the batch
                if (batchSlumpInput) {
                    batchSlumpInput.value = records.concrete_slump || '';
                }

                for (let i = 0; i < 4; i++) {
                    const recordId = records.record_id?.[i] || '';
                    const isExisting = !!recordId;

                    if (batchRecordIdInputs[i]) {
                        batchRecordIdInputs[i].value = recordId;
                        batchRecordIdInputs[i].disabled = false;
                        console.log(`Populated record_id_${i} with value: ${recordId}`);
                    } else {
                        console.warn(`Hidden input record_id_${i} not found!`);
                    }

                    if (batchSampleCodeInputs[i]) {
                        batchSampleCodeInputs[i].value = records.sample_code?.[i] || '';
                        batchSampleCodeInputs[i].readOnly = isExisting;
                        batchSampleCodeInputs[i].required = true;
                        batchSampleCodeInputs[i].disabled = false;
                    }

                    const l_val = records.dimension_l?.[i] || '';
                    const w_val = records.dimension_w?.[i] || '';
                    const h_val = records.dimension_h?.[i] || '';
                    const force_val = records.max_force_kg?.[i] || '';

                    const is_row_required = (i < 2) || l_val || w_val || h_val || force_val;

                    if (batchDimLInputs[i]) {
                        batchDimLInputs[i].value = l_val;
                        batchDimLInputs[i].disabled = false;
                        batchDimLInputs[i].required = is_row_required;
                    }
                    if (batchDimWInputs[i]) {
                        batchDimWInputs[i].value = w_val;
                        batchDimWInputs[i].disabled = false;
                        batchDimWInputs[i].required = is_row_required;
                    }
                    if (batchDimHInputs[i]) {
                        batchDimHInputs[i].value = h_val;
                        batchDimHInputs[i].disabled = false;
                        batchDimHInputs[i].required = is_row_required;
                    }
                    if (batchForceInputs[i]) {
                        batchForceInputs[i].value = force_val;
                        batchForceInputs[i].disabled = false;
                        batchForceInputs[i].required = is_row_required;
                    }
                }

                // Set form state to Update
                if (batchFormActionInput) batchFormActionInput.value = 'update_batch';
                if (batchFormTitle) batchFormTitle.textContent = 'ویرایش/تکمیل بچ: ' + (batchProdDateInput ? batchProdDateInput.value : '') + ' - ' + (batchPanelTypeSelect ? batchPanelTypeSelect.value : '');
                if (batchSubmitButtonText) batchSubmitButtonText.textContent = 'ذخیره تغییرات بچ';
                if (batchStatusDiv) batchStatusDiv.innerHTML = '<span class="text-success small"><i class="bi bi-check-circle-fill"></i> ' + (data.message || 'رکورد(ها) یافت شد.') + '</span>';
                if (batchSubmitButton) batchSubmitButton.disabled = false;
                if (batchProdDateInput) batchProdDateInput.disabled = false;
                if (batchPanelTypeSelect) batchPanelTypeSelect.disabled = false;
                if (batchSlumpInput) batchSlumpInput.disabled = false;
            }

            // MODIFICATION: Lookup now depends on both date and panel type
            const performLookup = function() {
                if (!batchProdDateInput || !batchPanelTypeSelect || !batchStatusDiv || !batchFormElement) return;

                const selectedDate = batchProdDateInput.value;
                const selectedPanelType = batchPanelTypeSelect.value;

                // Only proceed if both fields have a value
                if (selectedDate && /^\d{4}\/\d{2}\/\d{2}$/.test(toLatinDigits(selectedDate)) && selectedPanelType) {

                    // Disable form and show loading
                    clearBatchForm(false);
                    batchStatusDiv.innerHTML = '<span class="text-info small"><i class="spinner-border spinner-border-sm"></i> در حال بررسی...</span>';
                    batchSubmitButton.disabled = true;
                    batchFormElement.querySelectorAll('fieldset input, fieldset select').forEach(el => el.disabled = true);
                    batchProdDateInput.disabled = true;
                    batchPanelTypeSelect.disabled = true;
                    batchSlumpInput.disabled = true;

                    fetch('<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: new URLSearchParams({
                                'action': 'lookup_date',
                                'production_date': selectedDate,
                                'panel_type': selectedPanelType
                            })
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response error: ' + response.statusText);
                            }
                            return response.json();
                        })
                        .then(data => {
                            console.log("Lookup data:", data);
                            if (data.found) {
                                populateBatchForm(data); // Populates and enables fields
                            } else {
                                clearBatchForm(false); // Clears and enables for new entry
                                batchStatusDiv.innerHTML = `<span class="text-muted small">${data.message || 'آماده ثبت بچ جدید.'}</span>`;
                            }
                        })
                        .catch(error => {
                            console.error('Lookup error:', error);
                            clearBatchForm(false); // Ensure form is usable after error
                            batchStatusDiv.innerHTML = '<span class="text-danger small">خطا در ارتباط با سرور.</span>';
                        })
                        .finally(() => {
                            // Re-enable form controls managed by populate/clear functions
                            batchSubmitButton.disabled = false;
                            batchFormElement.querySelectorAll('fieldset input, fieldset select').forEach(el => el.disabled = false);
                            batchProdDateInput.disabled = false;
                            batchPanelTypeSelect.disabled = false;
                            batchSlumpInput.disabled = false;
                        });
                } else {
                    // If one of the fields is cleared, reset to new entry mode but don't clear the user's selections
                    clearBatchForm(false);
                    batchStatusDiv.innerHTML = '<span class="text-muted small">تاریخ جدید را انتخاب کنید یا تاریخ موجود را برای ویرایش وارد کنید.</span>';
                    if (batchSubmitButton) batchSubmitButton.disabled = false;
                    if (batchProdDateInput) batchProdDateInput.disabled = false;
                }
            };


            // --- 3. Single Edit Form Logic ---
            // REPLACE THIS ENTIRE FUNCTION
            function updateEditSampleAgeDisplay() {
                const prodIn = document.querySelector('#singleEditForm input[name="production_date"]'); // Hidden input
                const breakIn = document.querySelector('#singleEditForm input[name="break_date"]');
                const ageDisplay = document.getElementById('edit_sample_age_display');
                if (!prodIn || !breakIn || !ageDisplay) return;

                const pRaw = toLatinDigits(prodIn.value);
                const bRaw = toLatinDigits(breakIn.value);

                let ageText = '';
                if (pRaw && bRaw && /^\d{4}\/\d{2}\/\d{2}$/.test(pRaw) && /^\d{4}\/\d{2}\/\d{2}$/.test(bRaw)) {
                    try {
                        if (typeof persianDate !== 'undefined') {
                            const pParts = pRaw.split('/');
                            const bParts = bRaw.split('/');
                            const pDate = new persianDate([parseInt(pParts[0]), parseInt(pParts[1]), parseInt(pParts[2])]);
                            const bDate = new persianDate([parseInt(bParts[0]), parseInt(bParts[1]), parseInt(bParts[2])]);

                            // CORRECTED: Removed the incorrect .isValid() check
                            if (pDate.gDate && bDate.gDate && !isNaN(pDate.gDate.getTime()) && !isNaN(bDate.gDate.getTime())) {
                                const pMilli = pDate.gDate.getTime();
                                const bMilli = bDate.gDate.getTime();
                                const diffMilli = bMilli - pMilli;
                                if (diffMilli >= 0) {
                                    ageText = String(Math.floor(diffMilli / (1000 * 60 * 60 * 24)));
                                } else {
                                    ageText = 'خطا';
                                }
                            }
                        }
                    } catch (e) {
                        console.error('Err age calc JS:', e);
                        ageText = '?';
                    }
                }
                ageDisplay.textContent = ageText;
            }

            function updateEditCompressiveStrengthDisplay() {
                const forceIn = document.getElementById('edit_max_force_kg');
                const lIn = document.querySelector('#singleEditForm input[name="dimension_l"]');
                const wIn = document.querySelector('#singleEditForm input[name="dimension_w"]');
                const strengthDisplay = document.getElementById('edit_compressive_strength_display');
                if (!forceIn || !lIn || !wIn || !strengthDisplay) return;

                let strengthText = '';
                const f = parseFloat(forceIn.value);
                const l = parseFloat(lIn.value);
                const w = parseFloat(wIn.value);

                if (!isNaN(f) && !isNaN(l) && !isNaN(w) && l > 0 && w > 0 && f > 0) {
                    const s = (f * 9.80665) / (l * w);
                    strengthText = s.toFixed(2);
                }
                strengthDisplay.textContent = strengthText;
            }

            // --- 4. File Manager Logic ---
            function openFileManagerModal(prodDate, sampleCode) {
                if (!fileManagerBsModal) return;
                document.getElementById('fileManagerSampleCode').textContent = sampleCode;
                document.getElementById('fileManagerProdDate').textContent = gregorianToShamsiJs(prodDate);
                const modalBody = document.getElementById('fileManagerBody');
                modalBody.innerHTML = '<p class="text-center">در حال بارگذاری لیست فایل‌ها...</p>';
                fileManagerBsModal.show();

                fetch('<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: new URLSearchParams({
                            'action': 'list_files',
                            'prod_date': prodDate,
                            'sample_code': sampleCode
                        })
                    })
                    .then(response => response.text())
                    .then(html => {
                        modalBody.innerHTML = html;
                    })
                    .catch(error => {
                        console.error('Error fetching file list:', error);
                        modalBody.innerHTML = '<p class="text-danger text-center">خطا در بارگذاری لیست فایل‌ها.</p>';
                    });
            }

            function deleteManagedFile(prodDate, sampleCode, filename, buttonElement) {
                if (!confirm(`آیا از حذف فایل '${filename}' اطمینان دارید؟`)) return;

                buttonElement.disabled = true;
                buttonElement.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

                fetch('<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: new URLSearchParams({
                            'action': 'delete_file',
                            'prod_date': prodDate,
                            'sample_code': sampleCode,
                            'filename': filename
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const fileRow = buttonElement.closest('tr');
                            if (fileRow) fileRow.remove();
                            const fileTableBody = document.querySelector('#fileManagerBody table tbody');
                            if (fileTableBody && fileTableBody.rows.length === 0) {
                                document.getElementById('fileManagerBody').innerHTML = '<p class="text-center text-muted">هیچ فایلی یافت نشد.</p>';
                            }
                        } else {
                            alert('خطا در حذف فایل: ' + (data.message || 'خطای ناشناخته'));
                            buttonElement.disabled = false;
                            buttonElement.innerHTML = '<i class="bi bi-trash"></i>';
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting file:', error);
                        alert('خطا در ارتباط با سرور.');
                        buttonElement.disabled = false;
                        buttonElement.innerHTML = '<i class="bi bi-trash"></i>';
                    });
            }

            // --- 5. Event Listeners & Initialization ---
            (function() {
                'use strict';
                var forms = document.querySelectorAll('.needs-validation');

                // Common form submission handling
                Array.prototype.slice.call(forms).forEach(function(form) {
                    form.addEventListener('submit', function(event) {
                        // Rejection validation
                        const rejChk = form.querySelector('#edit_is_rejected, #is_rejected');
                        const rsnInp = form.querySelector('#edit_rejection_reason, #rejection_reason');
                        if (rejChk && rsnInp) {
                            if (rejChk.checked && rsnInp.value.trim() === '') {
                                rsnInp.required = true;
                                rsnInp.setCustomValidity('دلیل مردودی الزامی است.');
                            } else {
                                rsnInp.required = false;
                                rsnInp.setCustomValidity('');
                            }
                        }

                        // Form validity check
                        if (!form.checkValidity()) {
                            event.preventDefault();
                            event.stopPropagation();
                        } else {
                            const loadingEl = document.querySelector('.loading');
                            if (loadingEl) loadingEl.style.display = 'flex';
                        }
                        form.classList.add('was-validated');
                    }, false);
                });

                // Rejection checkbox logic
                const rejectionForms = [{
                        checkboxId: 'edit_is_rejected',
                        reasonInputId: 'edit_rejection_reason'
                    },
                    {
                        checkboxId: 'is_rejected',
                        reasonInputId: 'rejection_reason'
                    }
                ];

                rejectionForms.forEach(({
                    checkboxId,
                    reasonInputId
                }) => {
                    const rejChk = document.getElementById(checkboxId);
                    const rsnInp = document.getElementById(reasonInputId);
                    if (rejChk && rsnInp) {
                        rejChk.addEventListener('change', function() {
                            rsnInp.disabled = !this.checked;
                            rsnInp.required = this.checked;
                            if (!this.checked) {
                                rsnInp.value = '';
                                rsnInp.classList.remove('is-invalid');
                                rsnInp.setCustomValidity('');
                            }
                        });
                        rsnInp.disabled = !rejChk.checked;
                        rsnInp.required = rejChk.checked;
                    }
                });
            })();

            document.addEventListener('DOMContentLoaded', function() {
                try {
                    // Initialize Datepickers
                    if (typeof $ !== 'undefined' && typeof $.fn.persianDatepicker === 'function') {
                        $(".datepicker").persianDatepicker({
                            format: 'YYYY/MM/DD',
                            autoClose: true,
                            initialValue: false,
                            observer: true,
                            onSelect: function(unix) {
                                const inputElement = this.model.inputElement;
                                setTimeout(() => {
                                    // Trigger change for all datepickers to handle lookups or calculations
                                    $(inputElement).trigger('change');
                                }, 50);
                            }
                        });
                    }

                    // Batch Form Listeners
                    if (batchProdDateInput) {
                        batchProdDateInput.addEventListener('change', performLookup);
                    }
                    if (batchPanelTypeSelect) {
                        batchPanelTypeSelect.addEventListener('change', performLookup);
                    }

                    // Single Edit Form Calculation Listeners
                    if (singleEditFormElement) {
                        ['change', 'input'].forEach(evt => {
                            singleEditFormElement.querySelector('input[name="break_date"]').addEventListener(evt, updateEditSampleAgeDisplay);
                            singleEditFormElement.querySelector('input[name="max_force_kg"]').addEventListener(evt, updateEditCompressiveStrengthDisplay);
                            singleEditFormElement.querySelector('input[name="dimension_l"]').addEventListener(evt, updateEditCompressiveStrengthDisplay);
                            singleEditFormElement.querySelector('input[name="dimension_w"]').addEventListener(evt, updateEditCompressiveStrengthDisplay);
                        });
                        updateEditSampleAgeDisplay();
                        updateEditCompressiveStrengthDisplay();
                    }

                    // Collapse Button Text Toggle Listener
                    if (collapseElement && toggleFormBtn) {
                        collapseElement.addEventListener('show.bs.collapse', () => toggleFormBtn.innerHTML = '<i class="bi bi-dash-lg"></i> پنهان کردن فرم');
                        collapseElement.addEventListener('hide.bs.collapse', () => toggleFormBtn.innerHTML = '<i class="bi bi-plus-lg"></i> نمایش فرم ثبت / ویرایش');
                    }

                    // General form submission spinner
                    document.querySelectorAll('.needs-validation').forEach(form => {
                        form.addEventListener('submit', function(event) {
                            if (form.checkValidity()) {
                                if (loadingElement) loadingElement.style.display = 'flex';
                            }
                        });
                    });

                } catch (error) {
                    console.error("DOM Init Error:", error);
                }
            });
        </script>
        <?php
        include('footer.php');
        ?>
</body>

</html>
<?php ob_end_flush(); ?>