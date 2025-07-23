<?php
// public_panel_view.php
// (for Arad Project - Final Version)

ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');

// --- SETUP ---
// Include the global bootstrap file which contains helper functions and the DB manager.
require_once __DIR__ . '/../../sercon/bootstrap.php';
// Include jdf.php if it contains other necessary functions.
require_once 'includes/jdf.php';

// --- DATABASE CONNECTION ---
$pdo = null;
try {
    // Explicitly connect to the 'arad' project database using the function from bootstrap.php.
    // This is crucial for a public page that has no user session to determine the project.
    $pdo = getProjectDBConnection('arad');
} catch (Exception $e) {
    // Use the logger from bootstrap.php for internal error tracking.
    logError("Public View DB Connection Failed [Arad]: " . $e->getMessage());
    // Display a generic, user-friendly error message.
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// --- Get Panel ID from URL ---
// Sanitize the ID from the GET parameters to ensure it's an integer.
$panelId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;
if (!$panelId) {
    die("شناسه پنل نامعتبر است.");
}

// Initialize variables
$panel = null;

try {
    // --- FETCH PANEL DATA ---
    // The SQL query now selects 'floor'.
    $stmt = $pdo->prepare("
            SELECT
                id, address, floor, Proritization, status,
                assigned_date, planned_finish_date, packing_status,
                area, length, width, full_address_identifier
            FROM hpc_panels
            WHERE id = ?
        ");
    $stmt->execute([$panelId]);
    $panel = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no panel is found with the given ID, stop execution.
    if (!$panel) {
        die("پنل مورد نظر یافت نشد.");
    }

    // --- FETCH AND PROCESS CONCRETE TEST DATA ---
    // This logic remains as it is independent of the formwork changes.
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
    ];

    if (!empty($panel['assigned_date'])) {
        $concreteTestsStmt = $pdo->prepare("
            SELECT sample_code, sample_age_at_break, is_rejected, strength_1_day,
                   strength_7_day, strength_28_day, compressive_strength_mpa
            FROM concrete_tests
            WHERE production_date = ? ORDER BY sample_age_at_break ASC
        ");
        $concreteTestsStmt->execute([$panel['assigned_date']]);
        $concreteTestRows = $concreteTestsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($concreteTestRows as $row) {
            $age = $row['sample_age_at_break'];
            $is_rejected = $row['is_rejected'];
            $sample_code = $row['sample_code'];

            if ($age == 1) {
                $processedConcreteTests['strength_1'] = $row['strength_1_day'] ?? $row['compressive_strength_mpa'] ?? null;
                $processedConcreteTests['rejected_1'] = $is_rejected;
                $processedConcreteTests['code_1'] = $sample_code;
            } elseif ($age == 7) {
                $processedConcreteTests['strength_7'] = $row['strength_7_day'] ?? null;
                $processedConcreteTests['rejected_7'] = $is_rejected;
                $processedConcreteTests['code_7'] = $sample_code;
            } elseif ($age == 28) {
                $processedConcreteTests['strength_28'] = $row['strength_28_day'] ?? null;
                $processedConcreteTests['rejected_28'] = $is_rejected;
                $processedConcreteTests['code_28'] = $sample_code;
            }
        }
    }

    // --- SVG FILE HANDLING ---
    // Construct SVG filename and check for its existence.
    $filename = basename($panel['address'] ?? ''); // FIX: Provide default for basename

    $server_path = "/public_html/Arad/svg_files/" . $filename;


    // Use rawurlencode for safety in the URL.
    $panel['svg_url'] = "/Arad/svg_files/" . urlencode(basename($panel['address'])) . ".svg";
} catch (PDOException $e) {
    logError("Database error in public_panel_view.php for ID {$panelId}: " . $e->getMessage());
    die("خطایی در بارگذاری اطلاعات رخ داد. لطفا بعدا تلاش کنید.");
} catch (Exception $e) {
    logError("General error in public_panel_view.php for ID {$panelId}: " . $e->getMessage());
    die("خطایی رخ داد: " . htmlspecialchars($e->getMessage()));
} finally {
    // Ensure the database connection is closed.
    $pdo = null;
}

// Helper function for displaying concrete test status.
if (!function_exists('getConcreteTestStatusInfo')) {
    function getConcreteTestStatusInfo($isRejected)
    {
        if ($isRejected === 1 || $isRejected === '1') {
            return ['class' => 'text-red-600 font-semibold', 'text' => '✗ مردود'];
        } elseif ($isRejected === 0 || $isRejected === '0') {
            return ['class' => 'text-green-600 font-semibold', 'text' => '✓ قبول'];
        } else {
            return ['class' => 'text-gray-500 italic', 'text' => 'نامشخص'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico">
    <title>مشخصات پنل <?php echo htmlspecialchars($panel['full_address_identifier'] ?? 'ناشناخته'); ?></title>
    <link href="/assets/css/tailwind.min.css" rel="stylesheet">
    <style>
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
        }

        body {
            font-family: 'Vazir', sans-serif;
            background-color: #f3f4f6;
        }

        .page-header {
            background-color: #4b5563;
            color: white;
            padding: 1rem;
            text-align: center;
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .panel-svg-container {
            overflow: scroll;
            max-height: 70vh;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            background-color: white;
            box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.05);
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

        .no-date {
            color: #9ca3af;
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
                <!-- Main Details Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6 mb-6">

                    <!-- Left Column: Basic Information -->
                    <div>
                        <h2 class="text-xl font-semibold mb-4 text-gray-700 border-b pb-2">اطلاعات اصلی</h2>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                            <div>
                                <p class="font-semibold text-gray-600">آدرس:</p>
                                <p class="text-gray-800"><?php echo htmlspecialchars($panel['full_address_identifier'] ?? ''); ?></p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">طبقه:</p>
                                <p class="text-gray-800"><?php echo htmlspecialchars($panel['floor'] ?? 'نامشخص'); ?></p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">اولویت/زون:</p>
                                <!-- UPDATED: Using the translation function for Proritization/zone -->
                                <p class="text-gray-800"><?php echo translate_panel_data_to_persian('zone', $panel['Proritization'] ?? ''); ?></p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">وضعیت:</p>
                                <p class="capitalize text-gray-800">
                                    <?php
                                    // Translate status to Persian
                                    $status_translations = [
                                        'pending' => 'در انتظار',
                                        'polystyrene' => 'قالب فوم',
                                        'Mesh' => 'مش بندی',
                                        'Concreting' => 'قالب‌بندی/بتن ریزی',
                                        'Assembly' => 'فیس کوت',
                                        'completed' => 'تکمیل شده',
                                        'Cancelled' => 'لغو شده'
                                    ];
                                    echo $status_translations[$panel['status']] ?? htmlspecialchars($panel['status'] ?? '');
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
                                    $packing_translations = ['pending' => 'در انتظار', 'assigned' => 'تخصیص داده شده', 'shipped' => 'ارسال شده'];
                                    echo $packing_translations[$panel['packing_status']] ?? (empty($panel['packing_status']) ? 'مشخص نشده' : htmlspecialchars($panel['packing_status']));
                                    ?>
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">مساحت کل:</p>
                                <p class="text-gray-800"><?php echo htmlspecialchars($panel['area'] ?? '0'); ?> متر مربع</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">حجم:</p>
                                <p class="text-gray-800"><?php echo number_format(($panel['area'] ?? 0) * 0.03, 3); ?> متر مکعب</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">طول:</p>
                                <p class="text-gray-800"><?php echo number_format($panel['length'] ?? 0); ?> میلیمتر</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">عرض:</p>
                                <p class="text-gray-800"><?php echo number_format($panel['width'] ?? 0); ?> میلیمتر</p>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Concrete Test Results -->
                    <div>
                        <h2 class="text-xl font-semibold mb-4 text-gray-800 border-b pb-2">نتایج تست بتن <?php echo !empty($panel['assigned_date']) ? '(تاریخ تولید: ' . gregorianToShamsi($panel['assigned_date']) . ')' : ''; ?></h2>
                        <?php if (!empty($panel['assigned_date'])): ?>
                            <?php
                            $hasTestData = $processedConcreteTests['strength_1'] !== null || $processedConcreteTests['strength_7'] !== null || $processedConcreteTests['strength_28'] !== null;
                            $testsToShow = [
                                ['day' => 1, 'label' => '۱ روزه', 'strength_key' => 'strength_1', 'reject_key' => 'rejected_1', 'code_key' => 'code_1'],
                                ['day' => 7, 'label' => '۷ روزه', 'strength_key' => 'strength_7', 'reject_key' => 'rejected_7', 'code_key' => 'code_7'],
                                ['day' => 28, 'label' => '۲۸ روزه', 'strength_key' => 'strength_28', 'reject_key' => 'rejected_28', 'code_key' => 'code_28'],
                            ];
                            $notRecordedText = '<span class="text-gray-400 italic text-xs">ثبت نشده</span>';
                            ?>
                            <?php if ($hasTestData || !empty($concreteTestRows)): ?>
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
                                                $strengthValue = $processedConcreteTests[$testInfo['strength_key']] ?? null;
                                                $isRejected = $processedConcreteTests[$testInfo['reject_key']];
                                                $sampleCode = $processedConcreteTests[$testInfo['code_key']] ?? null;
                                                $statusInfo = getConcreteTestStatusInfo($isRejected);
                                                ?>
                                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                                    <td class="py-2 px-3 text-center border-r border-gray-200"><?php echo $testInfo['label']; ?></td>
                                                    <td class="py-2 px-3 text-center border-r border-gray-200 <?php echo $strengthValue !== null ? $statusInfo['class'] : 'text-gray-500'; ?>">
                                                        <?php echo $strengthValue !== null ? htmlspecialchars(number_format((float)$strengthValue, 1)) : $notRecordedText; ?>
                                                    </td>
                                                    <td class="py-2 px-3 text-center border-r border-gray-200">
                                                        <?php echo $sampleCode ? htmlspecialchars($sampleCode) : $notRecordedText; ?>
                                                    </td>
                                                    <td class="py-2 px-3 text-center">
                                                        <?php echo $strengthValue !== null ? '<span class="' . $statusInfo['class'] . '">' . $statusInfo['text'] . '</span>' : $notRecordedText; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-3 text-sm" role="alert">
                                    <p>هیچ نتیجه تستی برای تاریخ تولید این پنل (<?php echo gregorianToShamsi($panel['assigned_date']); ?>) در سیستم ثبت نشده است.</p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-3 text-sm" role="alert">
                                <p>تاریخ شروع تولید برای این پنل ثبت نشده است. نتایج تست بتن پس از ثبت تاریخ تولید و انجام تست‌ها نمایش داده می‌شود.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div><!-- End Main Details Grid -->

                <!-- SVG Display Section -->
                <div class="mt-6 border-t pt-6">
                    <h2 class="text-xl font-semibold mb-4 text-gray-700">نقشه پنل</h2>
                    <?php if (!empty($panel['svg_url'])): ?>
                        <div class="panel-svg relative" id="svgContainer">
                            <div class="panel-svg-container" id="svgWrapper">
                                <img src="<?php echo htmlspecialchars($panel['svg_url']); ?>"
                                    alt="نقشه پنل <?php echo htmlspecialchars($panel['address'] ?? ''); ?>"
                                    id="svgImage"
                                    class="block w-full h-auto"
                                    onerror="this.style.display='none'; document.getElementById('svgError').style.display='block';" />
                                <p id="svgError" style="display:none;" class="p-4 text-red-600 bg-red-100 border border-red-300 rounded">خطا در بارگذاری نقشه SVG.</p>
                            </div>
                            <!-- Zoom Controls -->
                            <div class="zoom-controls">
                                <button class="zoom-btn" id="zoomIn" title="بزرگنمایی"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4" />
                                    </svg></button>
                                <button class="zoom-btn" id="zoomOut" title="کوچک‌نمایی"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M4 8a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7A.5.5 0 0 1 4 8" />
                                    </svg></button>
                                <button class="zoom-btn" id="resetZoom" title="اندازه اصلی"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                        <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z" />
                                        <path d="M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966c.12.1.12.284 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466" />
                                    </svg></button>
                            </div>
                            <div class="zoom-level" id="zoomLevel">100%</div>
                        </div>
                    <?php else: ?>
                        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded" role="alert">
                            <p>نقشه‌ای برای این پنل بارگذاری نشده است.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-center">
                پنلی با این شناسه یافت نشد.
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- SVG Zoom/Pan Script ---
            const svgContainer = document.getElementById('svgContainer');
            if (svgContainer) {
                const wrapper = document.getElementById('svgWrapper');
                const img = document.getElementById('svgImage');
                const zoomInBtn = document.getElementById('zoomIn');
                const zoomOutBtn = document.getElementById('zoomOut');
                const resetZoomBtn = document.getElementById('resetZoom');
                const zoomLevelDisplay = document.getElementById('zoomLevel');

                if (!wrapper || !img || !zoomInBtn || !zoomOutBtn || !resetZoomBtn || !zoomLevelDisplay) {
                    console.warn("SVG zoom controls or image not fully loaded/found.");
                    return;
                }

                if (img.naturalWidth === 0 && !img.complete) {
                    img.onload = setupZoomPan;
                    img.onerror = () => console.error("SVG failed to load, cannot initialize zoom.");
                } else {
                    setupZoomPan();
                }

                function setupZoomPan() {
                    let scale = 1,
                        panning = false,
                        touchPanning = false,
                        initialDistance = 0,
                        initialScale = 1;
                    let pointX = 0,
                        pointY = 0;
                    let start = {
                        x: 0,
                        y: 0
                    };

                    function updateZoomLevel() {
                        zoomLevelDisplay.style.display = 'block';
                        zoomLevelDisplay.textContent = `${Math.round(scale * 100)}%`;
                        setTimeout(() => {
                            zoomLevelDisplay.style.display = 'none';
                        }, 1500);
                    }

                    function setTransform() {
                        img.style.transformOrigin = '0 0';
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
                        const clientX = e.clientX - rect.left;
                        const clientY = e.clientY - rect.top;
                        const xs = (clientX - pointX) / scale;
                        const ys = (clientY - pointY) / scale;
                        const delta = e.deltaY > 0 ? 0.85 : 1.15;
                        scale = Math.min(Math.max(0.1, scale * delta), 15);
                        pointX = clientX - xs * scale;
                        pointY = clientY - ys * scale;
                        setTransform();
                        updateZoomLevel();
                    }, {
                        passive: false
                    });

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

                    const endPan = () => {
                        if (panning) {
                            panning = false;
                            wrapper.style.cursor = 'grab';
                        }
                    };
                    wrapper.addEventListener('mouseup', endPan);
                    wrapper.addEventListener('mouseleave', endPan);

                    // Touch Events
                    wrapper.addEventListener('touchstart', (e) => {
                        if (e.touches.length === 1) {
                            e.preventDefault();
                            touchPanning = true;
                            start = {
                                x: e.touches[0].clientX - pointX,
                                y: e.touches[0].clientY - pointY
                            };
                        } else if (e.touches.length === 2) {
                            e.preventDefault();
                            touchPanning = false;
                            const t1 = e.touches[0];
                            const t2 = e.touches[1];
                            initialDistance = Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY);
                            initialScale = scale;
                            const rect = wrapper.getBoundingClientRect();
                            start = {
                                x: (t1.clientX + t2.clientX) / 2 - rect.left,
                                y: (t1.clientY + t2.clientY) / 2 - rect.top
                            };
                        }
                    }, {
                        passive: false
                    });

                    wrapper.addEventListener('touchmove', (e) => {
                        if (touchPanning && e.touches.length === 1) {
                            e.preventDefault();
                            pointX = e.touches[0].clientX - start.x;
                            pointY = e.touches[0].clientY - start.y;
                            setTransform();
                        } else if (e.touches.length === 2) {
                            e.preventDefault();
                            const t1 = e.touches[0];
                            const t2 = e.touches[1];
                            const currentDistance = Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY);
                            if (initialDistance > 0) {
                                const scaleMultiplier = currentDistance / initialDistance;
                                const newScale = Math.min(Math.max(0.1, initialScale * scaleMultiplier), 15);
                                const xs = (start.x - pointX) / scale;
                                const ys = (start.y - pointY) / scale;
                                pointX = start.x - xs * newScale;
                                pointY = start.y - ys * newScale;
                                scale = newScale;
                                setTransform();
                                updateZoomLevel();
                            }
                        }
                    }, {
                        passive: false
                    });

                    wrapper.addEventListener('touchend', (e) => {
                        if (e.touches.length < 2) touchPanning = false;
                        if (e.touches.length < 2) initialDistance = 0;
                    });

                    // Button Controls
                    const zoomWithButtons = (factor) => {
                        const rect = wrapper.getBoundingClientRect();
                        const centerXs = (rect.width / 2 - pointX) / scale;
                        const centerYs = (rect.height / 2 - pointY) / scale;
                        const oldScale = scale;
                        scale = Math.min(Math.max(0.1, scale * factor), 15);
                        pointX += centerXs * oldScale - centerXs * scale;
                        pointY += centerYs * oldScale - centerYs * scale;
                        setTransform();
                        updateZoomLevel();
                    };

                    zoomInBtn.addEventListener('click', () => zoomWithButtons(1.25));
                    zoomOutBtn.addEventListener('click', () => zoomWithButtons(0.8));
                    resetZoomBtn.addEventListener('click', resetTransform);

                    wrapper.style.cursor = 'grab';
                }
            }
        });
    </script>
</body>

</html>