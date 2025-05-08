<?php
// public_panel_view.php
// Public-facing panel detail page - NO LOGIN REQUIRED
ini_set('memory_limit', '1G');
// Disable error display in production for public pages
// ini_set('display_errors', 0);
// error_reporting(0);
// --- FOR DEVELOPMENT ONLY ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// --- END DEVELOPMENT ONLY ---

require_once __DIR__ . '/../../sercon/config_fereshteh.php';

// --- NO Session or Role Check ---
// This page is public.

// Function to convert Gregorian to Shamsi date (ensure it's defined - maybe move to config_fereshteh.php?)
if (!function_exists('gregorianToShamsi')) {
    function gregorianToShamsi($date)
    {
        if (empty($date) || $date == '0000-00-00') return '';
        try {
            $dt = new DateTime($date);
            $year = $dt->format('Y');
            $month = $dt->format('m');
            $day = $dt->format('d');
            $gDays = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            $jDays = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
            $gy = $year - 1600;
            $gm = $month - 1;
            $gd = $day - 1;
            $gDayNo = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
            for ($i = 0; $i < $gm; ++$i) $gDayNo += $gDays[$i];
            if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) $gDayNo++;
            $gDayNo += $gd;
            $jDayNo = $gDayNo - 79;
            $jNp = floor($jDayNo / 12053);
            $jDayNo %= 12053;
            $jy = 979 + 33 * $jNp + 4 * floor($jDayNo / 1461);
            $jDayNo %= 1461;
            if ($jDayNo >= 366) {
                $jy += floor(($jDayNo - 1) / 365);
                $jDayNo = ($jDayNo - 1) % 365;
            }
            for ($i = 0; $i < 11 && $jDayNo >= $jDays[$i]; ++$i) $jDayNo -= $jDays[$i];
            $jm = $i + 1;
            $jd = $jDayNo + 1;
            return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        } catch (Exception $e) {
            // Optional: Log error if needed
            // logError("Date conversion error: " . $e->getMessage());
            return 'تاریخ نامعتبر';
        }
    }
}


// --- Get Panel ID from URL ---
$panelId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;
if (!$panelId) {
    // Keep error messages simple for public view
    die("شناسه پنل نامعتبر است.");
}

// Initialize variables
$panel = null;
$concreteTests = []; // Initialize as empty array

try {
    $pdo = connectDB();

    // Get essential panel details for public view
    // Removed checker name columns as they are not needed for public view
    $stmt = $pdo->prepare("
            SELECT
                hp.id, hp.address, hp.type, hp.Proritization, hp.status,
                hp.assigned_date, hp.planned_finish_date,hp.packing_status,
                hp.area, hp.length, hp.width,
                hp.formwork_type, -- Keep formwork type if relevant
                ft.type as formwork_type_name, -- Include related formwork info if needed
                ft.width as formwork_width,
                ft.max_length as formwork_max_length
                -- Removed sarotah joins unless those dimensions are crucial for public view
            FROM hpc_panels hp
            LEFT JOIN formwork_types ft ON hp.formwork_type = ft.type
            -- LEFT JOIN sarotah s1 ON hp.address = SUBSTRING_INDEX(s1.left_panel, '-(', 1) -- Removed
            -- LEFT JOIN sarotah s2 ON hp.address = SUBSTRING_INDEX(s2.right_panel, '-(', 1) -- Removed
            WHERE hp.id = ?
        ");
    $stmt->execute([$panelId]);
    $panel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$panel) {
        die("پنل مورد نظر یافت نشد.");
    }

    // Fetch concrete test data IF assigned_date exists and is relevant for public
    // Fetch concrete test data for the panel's assigned date
    $processedConcreteTests = [
        'strength_1' => null,
        'rejected_1' => null,
        'code_1' => null,
        'strength_7' => null,
        'rejected_7' => null,
        'code_7' => null,
        'strength_28' => null,
        'rejected_28' => null,
        'code_28' => null,
    ]; // Initialize structure

    if (!empty($panel['assigned_date'])) {
        // Fetch ALL concrete test data for the panel's assigned date
        $concreteTestsStmt = $pdo->prepare("
            SELECT
                sample_code,
                sample_age_at_break, -- Age is crucial
                is_rejected,         -- Rejection status for this specific row
                strength_1_day,      -- Manually entered 1-day strength (if applicable)
                strength_7_day,      -- Manually entered 7-day strength (if applicable)
                strength_28_day,     -- Manually entered 28-day strength (if applicable)
                compressive_strength_mpa -- Calculated strength for this specific row/break
            FROM concrete_tests
            WHERE production_date = ?
            ORDER BY sample_age_at_break ASC -- Order by age
        ");
        $concreteTestsStmt->execute([$panel['assigned_date']]);
        $concreteTestRows = $concreteTestsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Process the fetched rows into the consolidated structure
        foreach ($concreteTestRows as $row) {
            $age = $row['sample_age_at_break'];
            $is_rejected = $row['is_rejected']; // Rejection status for this specific test
            $sample_code = $row['sample_code']; // Code for this specific sample

            if ($age == 1) {
                // Prioritize manually entered strength_1_day, fallback to calculated compressive_strength_mpa if needed
                $processedConcreteTests['strength_1'] = $row['strength_1_day'] ?? $row['compressive_strength_mpa'] ?? null;
                $processedConcreteTests['rejected_1'] = $is_rejected;
                $processedConcreteTests['code_1'] = $sample_code;
            } elseif ($age == 7) {
                $processedConcreteTests['strength_7'] = $row['strength_7_day'] ?? null; // Use specific 7-day field
                $processedConcreteTests['rejected_7'] = $is_rejected;
                $processedConcreteTests['code_7'] = $sample_code;
            } elseif ($age == 28) {
                $processedConcreteTests['strength_28'] = $row['strength_28_day'] ?? null; // Use specific 28-day field
                $processedConcreteTests['rejected_28'] = $is_rejected;
                $processedConcreteTests['code_28'] = $sample_code;
            }
            // Ignore other ages if any exist
        }
    }
    // --- End Fetch and Process Concrete Test Data ---

    // Construct SVG filename and check existence
    $filename = basename($panel['address']) . ".svg";
    $server_path = $_SERVER['DOCUMENT_ROOT'] . "/svg_files/" . $filename; // Ensure correct web root path
    if (file_exists($server_path)) {
        // Use a relative or absolute URL path accessible by the browser
        $panel['svg_url'] = "/svg_files/" . rawurlencode($filename); // Use rawurlencode for safety
    } else {
        // Optional: Log error if needed for internal tracking
        // logError("Public View: SVG not found for panel ID {$panelId}: " . $server_path);
        $panel['svg_url'] = ""; // Set to empty if not found
    }

    // Removed West/East parsing unless needed

} catch (PDOException $e) {
    // Optional: Log detailed error internally
    // logError("Database error in public_panel_view.php for ID {$panelId}: " . $e->getMessage());
    die("خطایی در بارگذاری اطلاعات رخ داد. لطفا بعدا تلاش کنید."); // Generic public error
} catch (Exception $e) {
    // Optional: Log detailed error internally
    // logError("General error in public_panel_view.php for ID {$panelId}: " . $e->getMessage());
    die("خطایی رخ داد: " . htmlspecialchars($e->getMessage())); // More specific if needed, but be cautious
} finally {
    $pdo = null; // Close connection
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/assets/images/favicon-96x96.png">
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico"> <!-- For older IE -->
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png"> <!-- For Apple devices -->
    <!-- Use a generic title or the panel address -->
    <title>مشخصات پنل <?php echo htmlspecialchars($panel['address'] ?? 'ناشناخته'); ?></title>
    <link href="/assets/css/tailwind.min.css" rel="stylesheet"> <!-- Adjust path if needed -->
    <style>
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
            /* Adjust path */
        }

        body {
            font-family: 'Vazir', sans-serif;
            background-color: #f3f4f6;
            /* Lighter gray for public page */
        }

        .no-date {
            color: #9ca3af;
        }

        /* Gray for no date */
        .panel-svg {
            background-color: rgba(255, 255, 255, 0.98);
            position: relative;
        }

        .panel-svg-container {
            overflow: scroll;
            max-height: 70vh;
            /* Adjust height as needed */
        }

        .zoom-controls {
            position: absolute;
            top: 10px;
            left: 10px;
            display: flex;
            gap: 5px;
            z-index: 10;
        }

        .zoom-btn {
            background: #fff;
            border: 1px solid #ccc;
            padding: 5px;
            cursor: pointer;
            border-radius: 4px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .zoom-btn:hover {
            background: #f0f0f0;
        }

        .zoom-level {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #fff;
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.75rem;
            z-index: 10;
            display: none;
            /* Initially hidden */
        }

        .status-passed {
            color: #16a34a;
            font-weight: 600;
        }

        .status-rejected {
            color: #dc2626;
            font-weight: 600;
        }

        /* Simple header */
        .page-header {
            background-color: #4b5563;
            /* Gray */
            color: white;
            padding: 1rem;
            text-align: center;
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 1.5rem;
            /* 2xl */
            font-weight: 600;
            /* semibold */
        }
    </style>
</head>

<body class="bg-gray-100">
    <div class="page-header">
        <h1>مشخصات پنل <?php echo htmlspecialchars($panel['address'] ?? 'ناشناخته'); ?></h1>
    </div>

    <div class="container mx-auto px-4 pb-8">

        <?php if ($panel): ?>
            <div class="bg-white rounded-lg shadow-md p-6">
                <!-- Basic Information -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <h2 class="text-xl font-semibold mb-4 text-gray-700 border-b pb-2">اطلاعات اصلی</h2>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                            <div>
                                <p class="font-semibold text-gray-600">آدرس:</p>
                                <p class="text-gray-800"><?php echo htmlspecialchars($panel['address']); ?></p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">نوع:</p>
                                <p class="text-gray-800"><?php echo htmlspecialchars($panel['type'] ?? 'نامشخص'); ?></p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">اولویت/زون:</p>
                                <p class="text-gray-800"><?php echo htmlspecialchars($panel['Proritization']); ?></p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">وضعیت:</p>
                                <p class="capitalize text-gray-800">
                                    <?php
                                    switch ($panel['status']) {
                                        case 'pending':
                                            echo 'در انتظار';
                                            break;
                                        case 'polystyrene':
                                            echo 'قالب فوم';
                                            break;
                                        case 'Mesh':
                                            echo 'مش بندی';
                                            break;
                                        case 'Concreting':
                                            echo 'قالب‌بندی/بتن ریزی';
                                            break;
                                        case 'Assembly':
                                            echo 'فیس کوت';
                                            break;
                                        case 'completed':
                                            echo 'تکمیل شده';
                                            break;
                                        case 'Cancelled':
                                            echo 'لغو شده';
                                            break;
                                        default:
                                            echo htmlspecialchars($panel['status']);
                                    }
                                    ?>
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">تاریخ شروع:</p>
                                <p class="<?php echo empty($panel['assigned_date']) ? 'no-date' : 'text-gray-800'; ?>">
                                    <?php echo empty($panel['assigned_date']) ? 'مشخص نشده' : gregorianToShamsi($panel['assigned_date']); ?>
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">وضعیت ارسال:</p>
                                <p class="<?php echo empty($panel['packing_status']) ? 'text-gray-400 italic' : ''; ?>">
                                    <?php
                                    if (empty($panel['packing_status'])) {
                                        echo 'مشخص نشده';
                                    } else {
                                        switch ($panel['packing_status']) {
                                            case 'pending':
                                                echo 'در انتظار';
                                                break;
                                            case 'assigned':
                                                echo 'تخصیص داده شده';
                                                break;
                                            case 'shipped':
                                                echo 'ارسال شده';
                                                break;
                                            default:
                                                echo htmlspecialchars($panel['packing_status']);
                                        }
                                    }
                                    ?>
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">مساحت کل:</p>
                                <p class="text-gray-800"><?php echo htmlspecialchars($panel['area']); ?> متر مربع</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">حجم:</p>
                                <p class="text-gray-800"><?php echo number_format($panel['area'] * 0.03, 3); ?> متر مکعب</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">طول:</p>
                                <p class="text-gray-800"><?php echo number_format($panel['length']); ?> میلیمتر</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">عرض:</p>
                                <p class="text-gray-800"><?php echo number_format($panel['width']); ?> میلیمتر</p>
                            </div>
                        </div>
                    </div>

                    <?php // Right Column 
                    ?>

                    <?php // --- Display Concrete Test Results --- 
                    ?>
                    <div> <?php // Start the right column div 
                            ?>
                        <h2 class="text-xl font-semibold mb-4 text-gray-800 border-b pb-2">نتایج تست بتن <?php echo !empty($panel['assigned_date']) ? '(تاریخ تولید: ' . gregorianToShamsi($panel['assigned_date']) . ')' : ''; ?></h2>

                        <?php if (!empty($panel['assigned_date'])): // Only show if assigned_date exists 
                        ?>
                            <?php
                            // Check if any test data was actually processed
                            $hasTestData = $processedConcreteTests['strength_1'] !== null ||
                                $processedConcreteTests['strength_7'] !== null ||
                                $processedConcreteTests['strength_28'] !== null;

                            // Helper function for status display
                            if (!function_exists('getConcreteTestStatusInfo')) { // Prevent redeclaration
                                function getConcreteTestStatusInfo($isRejected)
                                {
                                    if ($isRejected === 1 || $isRejected === '1') { // Explicitly check for rejected value
                                        return ['class' => 'text-red-600 font-semibold', 'text' => '✗ مردود'];
                                    } elseif ($isRejected === 0 || $isRejected === '0') { // Explicitly check for passed
                                        return ['class' => 'text-green-600 font-semibold', 'text' => '✓ قبول'];
                                    } else { // Handle null or other unexpected values
                                        return ['class' => 'text-gray-500 italic', 'text' => 'نامشخص'];
                                    }
                                }
                            }

                            // Define the tests to display using the processed data keys
                            $testsToShow = [
                                ['day' => 1, 'label' => '۱ روزه', 'strength_key' => 'strength_1', 'reject_key' => 'rejected_1', 'code_key' => 'code_1'],
                                ['day' => 7, 'label' => '۷ روزه', 'strength_key' => 'strength_7', 'reject_key' => 'rejected_7', 'code_key' => 'code_7'],
                                ['day' => 28, 'label' => '۲۸ روزه', 'strength_key' => 'strength_28', 'reject_key' => 'rejected_28', 'code_key' => 'code_28'],
                            ];

                            $notRecordedText = '<span class="text-gray-400 italic text-xs">ثبت نشده</span>';
                            ?>

                            <?php if ($hasTestData || !empty($concreteTestRows)): // Show table if *any* data fetched or processed 
                            ?>
                                <div class="shadow-md rounded-lg overflow-hidden border border-gray-200">
                                    <table class="table-auto w-full text-sm">
                                        <thead class="bg-gray-100 text-gray-600 uppercase text-xs leading-normal">
                                            <tr>
                                                <th class="py-2 px-3 text-center border-b border-gray-300">سن تست</th>
                                                <th class="py-2 px-3 text-center border-b border-gray-300">مقاومت (MPa)</th>
                                                <th class="py-2 px-3 text-center border-b border-gray-300">کد نمونه</th>
                                                <th class="py-2 px-3 text-center border-b border-gray-300">وضعیت</th>
                                            </tr>
                                        </thead>
                                        <tbody class="text-gray-700">
                                            <?php foreach ($testsToShow as $testInfo): ?>
                                                <?php
                                                // Get data from the processed array
                                                $strengthValue = $processedConcreteTests[$testInfo['strength_key']] ?? null;
                                                $isRejected = $processedConcreteTests[$testInfo['reject_key']]; // Should be 0, 1, or null
                                                $sampleCode = $processedConcreteTests[$testInfo['code_key']] ?? null;

                                                // Initialize display variables
                                                $strengthDisplay = $notRecordedText;
                                                $statusDisplay = $notRecordedText; // Default status to "Not Recorded"
                                                $codeDisplay = $notRecordedText;
                                                $strengthDisplayClass = 'text-gray-500'; // Default class

                                                if ($strengthValue !== null) {
                                                    // --- If strength value EXISTS, THEN determine status and apply class ---
                                                    $strengthDisplay = htmlspecialchars(number_format((float)$strengthValue, 1));

                                                    $statusInfo = getConcreteTestStatusInfo($isRejected); // Get status based on the reject flag
                                                    $statusDisplay = '<span class="' . $statusInfo['class'] . '">' . $statusInfo['text'] . '</span>';
                                                    $strengthDisplayClass = $statusInfo['class']; // Apply status color class to strength

                                                    // Code should be displayed if available for this test age
                                                    $codeDisplay = $sampleCode ? htmlspecialchars($sampleCode) : $notRecordedText;
                                                } else {
                                                    // --- If strength value does NOT exist ---
                                                    // Status and Strength remain "Not Recorded" ($notRecordedText).
                                                    // Check if a sample code was recorded for this age even without strength.
                                                    $codeDisplay = $sampleCode ? htmlspecialchars($sampleCode) : $notRecordedText;
                                                }
                                                ?>
                                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                                    <td class="py-2 px-3 text-center border-r border-gray-200">
                                                        <?php echo $testInfo['label']; ?>
                                                    </td>
                                                    <td class="py-2 px-3 text-center border-r border-gray-200 <?php echo $strengthDisplayClass; // Apply class here 
                                                                                                                ?>">
                                                        <?php echo $strengthDisplay; ?>
                                                    </td>
                                                    <td class="py-2 px-3 text-center border-r border-gray-200">
                                                        <?php echo $codeDisplay; ?>
                                                    </td>
                                                    <td class="py-2 px-3 text-center">
                                                        <?php echo $statusDisplay; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: // assigned_date exists but no records found at all 
                            ?>
                                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-3 text-sm" role="alert">
                                    <p>هیچ نتیجه تستی برای تاریخ تولید این پنل (<?php echo gregorianToShamsi($panel['assigned_date']); ?>) در سیستم ثبت نشده است.</p>
                                </div>
                            <?php endif; // End $hasTestData check 
                            ?>

                        <?php else: // Panel assigned_date is empty 
                        ?>
                            <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-3 text-sm" role="alert">
                                <p>تاریخ شروع تولید برای این پنل ثبت نشده است. نتایج تست بتن پس از ثبت تاریخ تولید و انجام تست‌ها نمایش داده می‌شود.</p>
                            </div>
                        <?php endif; // End !empty($panel['assigned_date']) check 
                        ?>
                    </div> <?php // End the right column div 
                            ?>
                    <?php // --- End Display Concrete Test Results --- 
                    ?>
                    <!-- Formwork Details (Optional) -->
                    <?php if (!empty($panel['formwork_type_name'])): ?>
                        <div>
                            <h2 class="text-xl font-semibold mb-4 text-gray-700 border-b pb-2">مشخصات قالب</h2>
                            <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                <div>
                                    <p class="font-semibold text-gray-600">نوع قالب:</p>
                                    <p class="text-gray-800"><?php echo htmlspecialchars($panel['formwork_type_name']); ?></p>
                                </div>
                                <?php if (!empty($panel['formwork_width'])): ?>
                                    <div>
                                        <p class="font-semibold text-gray-600">عرض قالب:</p>
                                        <p class="text-gray-800"><?php echo htmlspecialchars($panel['formwork_width']); ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($panel['formwork_max_length'])): ?>
                                    <div>
                                        <p class="font-semibold text-gray-600">طول حداکثر:</p>
                                        <p class="text-gray-800"><?php echo htmlspecialchars($panel['formwork_max_length']); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div><!-- End Basic Information Grid -->

            <!-- REMOVED Plan Check Section -->

            <!-- SVG Display -->
            <div class="mt-6 border-t pt-6">
                <h2 class="text-xl font-semibold mb-4 text-gray-700">نقشه پنل</h2>

                <?php if (!empty($panel['svg_url'])): ?>
                    <div class="panel-svg mb-4 relative" id="svgContainer">
                        <div class="panel-svg-container border rounded bg-white shadow-inner" id="svgWrapper">
                            <!-- Added cache buster using filemtime if possible, else time() -->
                            <?php
                            $svgTimestamp = file_exists($server_path) ? filemtime($server_path) : time();
                            ?>
                            <img src="<?php echo htmlspecialchars($panel['svg_url']) . '?v=' . $svgTimestamp; ?>"
                                alt="نقشه پنل <?php echo htmlspecialchars($panel['address']); ?>"
                                id="svgImage"
                                class="block w-full h-auto" <?php // Basic styling for img 
                                                            ?>
                                onerror="this.style.display='none'; document.getElementById('svgError').style.display='block';" />
                            <p id="svgError" style="display:none;" class="p-4 text-red-600 bg-red-100 border border-red-300 rounded">خطا در بارگذاری نقشه SVG.</p>
                        </div>
                        <!-- Zoom Controls -->
                        <div class="zoom-controls">
                            <button class="zoom-btn" id="zoomIn" title="بزرگنمایی"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4" />
                                </svg> </button>
                            <button class="zoom-btn" id="zoomOut" title="کوچک‌نمایی"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M4 8a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7A.5.5 0 0 1 4 8" />
                                </svg> </button>
                            <button class="zoom-btn" id="resetZoom" title="اندازه اصلی"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z" />
                                    <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466" />
                                </svg> </button>
                        </div>
                        <div class="zoom-level" id="zoomLevel">100%</div>
                    </div>
                <?php else: ?>
                    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded" role="alert">
                        <p class="font-bold">توجه</p>
                        <p>نقشه‌ای برای این پنل بارگذاری نشده است.</p>
                    </div>
                <?php endif; // End SVG URL check 
                ?>

                <!-- REMOVED Print Button -->
                <!-- REMOVED Change SVG Form -->

            </div> <!-- End SVG Display Section -->

    </div> <?php // Close the main panel content div 
            ?>

<?php else: // Panel not found 
?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-center">
        پنلی با این شناسه یافت نشد.
    </div>
<?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- SVG Zoom/Pan Script (Keep this part) ---
        const svgContainer = document.getElementById('svgContainer');
        if (svgContainer) {
            const wrapper = document.getElementById('svgWrapper');
            const img = document.getElementById('svgImage');
            const zoomInBtn = document.getElementById('zoomIn');
            const zoomOutBtn = document.getElementById('zoomOut');
            const resetZoomBtn = document.getElementById('resetZoom');
            const zoomLevelDisplay = document.getElementById('zoomLevel');

            // Ensure all elements exist before adding listeners
            if (!wrapper || !img || !zoomInBtn || !zoomOutBtn || !resetZoomBtn || !zoomLevelDisplay) {
                console.warn("SVG zoom controls or image not fully loaded/found.");
                return;
            }
            if (img.naturalWidth === 0 && !img.complete) {
                // Image might not be loaded yet, wait for load event
                img.onload = setupZoomPan;
                // Also handle error just in case
                img.onerror = () => console.error("SVG failed to load, cannot initialize zoom.");
            } else if (img.naturalWidth > 0) {
                // Image already loaded (e.g. from cache) or SVG has intrinsic size
                setupZoomPan();
            } else {
                // If it's an SVG without intrinsic size and not loaded, setup might still work
                setupZoomPan();
            }


            function setupZoomPan() {
                let scale = 1;
                let panning = false;
                let pointX = 0;
                let pointY = 0;
                let start = {
                    x: 0,
                    y: 0
                };
                let touchPanning = false;
                let initialDistance = 0;
                let initialScale = 1;

                function updateZoomLevel() {
                    zoomLevelDisplay.style.display = 'block';
                    zoomLevelDisplay.textContent = `${Math.round(scale * 100)}%`;
                    setTimeout(() => {
                        zoomLevelDisplay.style.display = 'none';
                    }, 1500);
                }

                function setTransform() {
                    // Use transformOrigin 0 0 for predictable scaling/panning
                    img.style.transformOrigin = '0 0';
                    // Clamp panning to prevent excessive dragging off-screen (optional adjustment)
                    // const maxX = Math.max(0, img.offsetWidth * scale - wrapper.clientWidth);
                    // const maxY = Math.max(0, img.offsetHeight * scale - wrapper.clientHeight);
                    // pointX = Math.min(0, Math.max(-maxX, pointX));
                    // pointY = Math.min(0, Math.max(-maxY, pointY));
                    img.style.transform = `translate(${pointX}px, ${pointY}px) scale(${scale})`;
                }

                function resetTransform() {
                    scale = 1;
                    pointX = 0;
                    pointY = 0;
                    setTransform();
                    updateZoomLevel();
                }

                wrapper.addEventListener('wheel', (e) => {
                    e.preventDefault();
                    const rect = wrapper.getBoundingClientRect();
                    // Cursor position relative to the wrapper
                    const clientX = e.clientX - rect.left;
                    const clientY = e.clientY - rect.top;
                    // Calculate the point in the image coordinate system under the cursor
                    const xs = (clientX - pointX) / scale;
                    const ys = (clientY - pointY) / scale;

                    const delta = e.deltaY > 0 ? 0.85 : 1.15; // Zoom factor
                    const oldScale = scale;
                    scale = Math.min(Math.max(0.1, scale * delta), 15); // Min/Max zoom

                    // Adjust pointX/Y to keep the point under the cursor stationary
                    pointX = clientX - xs * scale;
                    pointY = clientY - ys * scale;

                    setTransform();
                    updateZoomLevel();
                }, {
                    passive: false
                }); // Need passive: false to preventDefault

                wrapper.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    panning = true;
                    start = {
                        x: e.clientX - pointX,
                        y: e.clientY - pointY
                    };
                    wrapper.style.cursor = 'grabbing';
                });

                wrapper.addEventListener('mousemove', (e) => {
                    if (!panning) return;
                    e.preventDefault();
                    pointX = e.clientX - start.x;
                    pointY = e.clientY - start.y;
                    setTransform();
                });

                wrapper.addEventListener('mouseup', () => {
                    if (panning) {
                        panning = false;
                        wrapper.style.cursor = 'grab';
                    }
                });

                wrapper.addEventListener('mouseleave', () => {
                    if (panning) {
                        panning = false;
                        wrapper.style.cursor = 'grab';
                    }
                });

                // --- Touch Events for Pan and Pinch Zoom ---
                wrapper.addEventListener('touchstart', (e) => {
                    if (e.touches.length === 1) {
                        // Pan start
                        e.preventDefault(); // Prevent scroll only when panning starts
                        touchPanning = true;
                        start = {
                            x: e.touches[0].clientX - pointX,
                            y: e.touches[0].clientY - pointY
                        };
                        wrapper.style.cursor = 'grabbing'; // Indicate interaction
                    } else if (e.touches.length === 2) {
                        // Pinch zoom start
                        e.preventDefault(); // Prevent default pinch zoom behavior
                        touchPanning = false; // Stop panning if starting pinch
                        const t1 = e.touches[0];
                        const t2 = e.touches[1];
                        initialDistance = Math.sqrt(Math.pow(t1.clientX - t2.clientX, 2) + Math.pow(t1.clientY - t2.clientY, 2));
                        initialScale = scale;
                        // Calculate pinch center relative to wrapper for zoom origin
                        const rect = wrapper.getBoundingClientRect();
                        const pinchCenterX = (t1.clientX + t2.clientX) / 2 - rect.left;
                        const pinchCenterY = (t1.clientY + t2.clientY) / 2 - rect.top;
                        start = {
                            x: pinchCenterX,
                            y: pinchCenterY
                        }; // Store pinch center
                    }
                }, {
                    passive: false
                });

                wrapper.addEventListener('touchmove', (e) => {
                    if (touchPanning && e.touches.length === 1) {
                        // Pan move
                        e.preventDefault(); // Prevent scroll while panning
                        pointX = e.touches[0].clientX - start.x;
                        pointY = e.touches[0].clientY - start.y;
                        setTransform();
                    } else if (e.touches.length === 2) {
                        // Pinch zoom move
                        e.preventDefault(); // Prevent default pinch behavior
                        const t1 = e.touches[0];
                        const t2 = e.touches[1];
                        const currentDistance = Math.sqrt(Math.pow(t1.clientX - t2.clientX, 2) + Math.pow(t1.clientY - t2.clientY, 2));
                        if (initialDistance > 0) { // Avoid division by zero
                            const scaleMultiplier = currentDistance / initialDistance;
                            const newScale = Math.min(Math.max(0.1, initialScale * scaleMultiplier), 15);

                            // Calculate zoom point in image coordinates
                            const xs = (start.x - pointX) / scale;
                            const ys = (start.y - pointY) / scale;

                            // Adjust pointX/Y based on pinch center
                            pointX = start.x - xs * newScale;
                            pointY = start.y - ys * newScale;

                            scale = newScale;
                            setTransform();
                            updateZoomLevel(); // Show level during pinch
                        }
                    }
                }, {
                    passive: false
                });

                wrapper.addEventListener('touchend', (e) => {
                    // Reset panning flag if only one or zero touches remain
                    if (e.touches.length < 2) {
                        touchPanning = false;
                        wrapper.style.cursor = 'grab'; // Reset cursor
                    }
                    // Reset pinch state if less than 2 touches
                    if (e.touches.length < 2) {
                        initialDistance = 0;
                    }
                });


                // --- Button Controls ---
                zoomInBtn.addEventListener('click', () => {
                    const rect = wrapper.getBoundingClientRect();
                    const centerXs = (rect.width / 2 - pointX) / scale; // Center X in image coords
                    const centerYs = (rect.height / 2 - pointY) / scale; // Center Y in image coords
                    const oldScale = scale;
                    scale = Math.min(scale * 1.25, 15); // Limit max zoom
                    // Adjust pointX/Y to zoom towards center
                    pointX += centerXs * oldScale - centerXs * scale;
                    pointY += centerYs * oldScale - centerYs * scale;
                    setTransform();
                    updateZoomLevel();
                });

                zoomOutBtn.addEventListener('click', () => {
                    const rect = wrapper.getBoundingClientRect();
                    const centerXs = (rect.width / 2 - pointX) / scale;
                    const centerYs = (rect.height / 2 - pointY) / scale;
                    const oldScale = scale;
                    scale = Math.max(scale * 0.8, 0.1); // Limit min zoom
                    pointX += centerXs * oldScale - centerXs * scale;
                    pointY += centerYs * oldScale - centerYs * scale;
                    setTransform();
                    updateZoomLevel();
                });

                resetZoomBtn.addEventListener('click', resetTransform);

                // --- Initial Setup ---
                wrapper.style.cursor = 'grab'; // Set initial cursor
                // Optionally call setTransform here if initial scale/position might be non-default
                // setTransform();
            }


        } // End if(svgContainer)

        // --- REMOVED Plan Check Script ---
        // --- REMOVED Print Script ---

    }); // End DOMContentLoaded
</script>
</body>

</html>