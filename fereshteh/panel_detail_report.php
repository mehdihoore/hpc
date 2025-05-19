<?php
// panel_detail_report.php
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
    logError("Unauthorized role '{$_SESSION['role']}' attempt on {$expected_project_key} map. User: {$_SESSION['user_id']}");
    header('Location: dashboard.php?msg=unauthorized');
    exit();
}
$user_id = $_SESSION['user_id'];
$pdo = null;
try {
    $pdo = getProjectDBConnection();
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key} map: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}
// Validate and get panel ID from URL
$panelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($panelId <= 0) {
    die("Invalid Panel ID");
}

try {
    // Assuming connectDB() returns a PDO instance, defined in config_fereshteh.php


    // Optimized query selecting only necessary fields
    $stmt = $pdo->prepare("
        SELECT
            hp.*,
            ft.type as formwork_type_name, -- Renamed to avoid conflict if 'type' exists in hp
            ft.width as formwork_width,
            ft.max_length as formwork_max_length,
            ft.is_disabled as formwork_is_disabled,
            ft.in_repair as formwork_in_repair,
            ft.is_available as formwork_is_available,
            s1.dimension_1 as left_dim1, s1.dimension_2 as left_dim2, s1.dimension_3 as left_dim3, s1.dimension_4 as left_dim4, s1.dimension_5 as left_dim5,
            s1.left_panel as left_panel_full, s1.right_panel as right_panel_full,
            s2.dimension_1 as right_dim1, s2.dimension_2 as right_dim2, s2.dimension_3 as right_dim3, s2.dimension_4 as right_dim4, s2.dimension_5 as right_dim5,
            checker.username as plan_checker_username -- Fetch checker username directly
        FROM hpc_panels hp
        LEFT JOIN formwork_types ft ON hp.formwork_type = ft.type -- Join on formwork_type if it's the FK reference
        LEFT JOIN sarotah s1 ON hp.address = SUBSTRING_INDEX(s1.left_panel, '-(', 1)
        LEFT JOIN sarotah s2 ON hp.address = SUBSTRING_INDEX(s2.right_panel, '-(', 1)
        LEFT JOIN hpc_common.users checker ON hp.plan_checker_user_id = checker.id -- Join to get checker username
        WHERE hp.id = ?
    ");
    $stmt->execute([$panelId]);
    $panel = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$panel) {
        die("Panel not found");
    }
    $checkerUsername = $panel['plan_checker_username'] ?? null;
    // Construct and verify SVG file path
    $filename = basename($panel['address']) . ".svg";
    $server_path = $_SERVER['DOCUMENT_ROOT'] . "/Fereshteh/svg_files/" . $filename;
    $panel['svg_url'] = file_exists($server_path) ? "/Fereshteh/svg_files/" . $filename : "";

    // Parse West/East addresses
    $addressParts = explode('-', $panel['address']);
    $westAddress = $eastAddress = '';
    if (count($addressParts) >= 3) {
        $direction = $addressParts[0];
        $westAddress = $direction . '-' . $addressParts[1];
        $eastAddress = $direction . '-' . $addressParts[2];
    }
} catch (PDOException $e) {
    // Log error securely (assuming logError is defined in config_fereshteh.php)
    logError("Database error: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>جزئیات پنل <?php echo htmlspecialchars($panel['address'] ?? 'Unknown'); ?></title>
    <link href="assets/css/tailwind.min.css" rel="stylesheet">
    <style>
        @font-face {
            font-family: 'Vazir';
            src: url('assets/fonts/Vazir-Regular.woff2') format('woff2');
        }

        body {
            font-family: 'Vazir', sans-serif;
        }

        .panel-svg {
            width: 100%;
            height: 500px;
            background-color: rgba(12, 12, 12, 0.26);
            border: 1px solid #e2e8f0;
            border-radius: 0.375rem;
            position: relative;
            overflow: hidden;
        }

        .panel-svg-container {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: auto;
        }

        .panel-svg img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            transform-origin: center;
            transition: transform 0.1s ease-out;
            cursor: zoom-in;
        }

        .panel-svg.zoomed img {
            cursor: move;
        }

        .zoom-controls {
            position: absolute;
            bottom: 1rem;
            right: 1rem;
            display: flex;
            gap: 0.5rem;
            z-index: 10;
        }

        .zoom-btn {
            background-color: white;
            border: 1px solid #e2e8f0;
            border-radius: 0.375rem;
            padding: 0.5rem;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            width: 2.5rem;
            height: 2.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .zoom-btn:hover {
            background-color: #f8f9fa;
        }

        .zoom-level {
            background-color: rgba(255, 255, 255, 0.9);
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 0.875rem;
            display: none;
        }
    </style>
</head>

<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold">جزئیات پنل <?php echo htmlspecialchars($panel['address'] ?? 'Unknown'); ?></h1>
        </div>

        <?php if ($panel): ?>
            <div class="bg-white rounded-lg shadow-md p-6">
                <!-- Basic Information -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div>
                        <h2 class="text-xl font-bold mb-4">اطلاعات اصلی</h2>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="font-semibold text-gray-600">آدرس:</p>
                                <p><?php echo htmlspecialchars($panel['address']); ?></p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">نوع:</p>
                                <p><?php echo htmlspecialchars($panel['type'] ?? 'نامشخص'); ?></p>
                            </div>
                            <div class="flex items-center gap-4">
                                <p>وضعیت نقشه:
                                    <span id="planStatusText" class="font-semibold <?php echo $panel['plan_checked'] ? 'status-ok' : 'status-pending'; ?>">
                                        <?php echo $panel['plan_checked'] ? 'تایید شده' : 'در انتظار بررسی'; ?>
                                    </span>
                                    <?php if ($checkerUsername): ?>
                                        <span class="checker-info">(توسط: <?php echo htmlspecialchars($checkerUsername); ?>)</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">وضعیت:</p>
                                <p class="capitalize"><?php
                                                        echo $panel['status'] === 'pending' ? 'در انتظار' : ($panel['status'] === 'completed' ? 'تکمیل شده' :
                                                            htmlspecialchars($panel['status']));
                                                        ?></p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">تاریخ شروع:</p>
                                <p class="<?php echo empty($panel['assigned_date']) ? 'no-date' : ''; ?>">
                                    <?php echo empty($panel['assigned_date']) ? 'مشخص نشده' : gregorianToShamsi($panel['assigned_date']); ?>
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">تاریخ پایان (برنامه‌ریزی شده):</p>
                                <p class="<?php echo empty($panel['planned_finish_date']) ? 'no-date' : ''; ?>">
                                    <?php echo empty($panel['planned_finish_date']) ? 'مشخص نشده' : gregorianToShamsi($panel['planned_finish_date']); ?>
                                </p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">مساحت:</p>
                                <p><?php echo htmlspecialchars($panel['area']); ?> متر مربع</p>
                            </div>

                            <div>
                                <p class="font-semibold text-gray-600">حجم:</p>
                                <p><?php echo number_format($panel['area'] * 0.03, 3); ?> متر مکعب</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">عرض:</p>
                                <p><?php echo htmlspecialchars($panel['width']); ?> میلیمتر</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">طول:</p>
                                <p><?php echo htmlspecialchars($panel['length']); ?> میلیمتر</p>
                            </div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-600"> <?php echo htmlspecialchars($westAddress); ?></p>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-600"><?php echo htmlspecialchars($eastAddress); ?></p>
                        </div>
                    </div>

                    <?php if ($panel['formwork_type']): ?>
                        <div>
                            <h2 class="text-xl font-bold mb-4">مشخصات قالب</h2>
                            <div>
                                <p class="font-semibold text-gray-600">نوع قالب:</p>
                                <p><?php echo htmlspecialchars($panel['formwork_type']); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- SVG Display -->
                <?php if (!empty($panel['svg_url'])): ?>
                    <div class="panel-svg mb-8" id="svgContainer">
                        <div class="panel-svg-container" id="svgWrapper">
                            <img src="<?php echo htmlspecialchars($panel['svg_url']); ?>"
                                alt="SVG تصویر برای پنل <?php echo htmlspecialchars($panel['address']); ?>"
                                id="svgImage" />
                        </div>
                        <div class="zoom-controls">
                            <button class="zoom-btn" id="zoomIn" title="بزرگ‌نمایی">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19"></line>
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                            </button>
                            <button class="zoom-btn" id="zoomOut" title="کوچک‌نمایی">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                            </button>
                            <button class="zoom-btn" id="resetZoom" title="بازنشانی زوم">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
                                    <path d="M3 3v5h5"></path>
                                </svg>
                            </button>
                            <div class="open-svg">
                                <a href="<?php echo htmlspecialchars($panel['svg_url']); ?>" target="_blank" class="open-svg-link">باز کردن تصویر</a>
                            </div>
                        </div>
                        <div class="zoom-level" id="zoomLevel">100%</div>

                    </div>
                <?php else: ?>
                    <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded">
                        تصویر SVG برای این پنل پیدا نشد.
                    </div>
                <?php endif; ?>


            </div>
        <?php else: ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                پنلی پیدا نشد!
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('svgContainer');
            const wrapper = document.getElementById('svgWrapper');
            const img = document.getElementById('svgImage');
            const zoomInBtn = document.getElementById('zoomIn');
            const zoomOutBtn = document.getElementById('zoomOut');
            const resetZoomBtn = document.getElementById('resetZoom');
            const zoomLevelDisplay = document.getElementById('zoomLevel');

            if (!img) return; // Exit if SVG image isn't found

            let scale = 1;
            let panning = false;
            let pointX = 0;
            let pointY = 0;
            let start = {
                x: 0,
                y: 0
            };
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
                img.style.transform = `translate(${pointX}px, ${pointY}px) scale(${scale})`;
                // Add class to indicate zoom state
                if (scale > 1) {
                    container.classList.add('zoomed');
                } else {
                    container.classList.remove('zoomed');
                }
            }

            function resetTransform() {
                scale = 1;
                pointX = 0;
                pointY = 0;
                setTransform();
                updateZoomLevel();
            }

            // Prevent default behavior for mouse wheel on the SVG container
            wrapper.addEventListener('wheel', function(e) {
                e.preventDefault();

                const rect = wrapper.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;

                // Calculate position relative to current scale
                const xs = (mouseX - pointX) / scale;
                const ys = (mouseY - pointY) / scale;

                // Determine zoom direction
                const delta = -e.deltaY;
                const newScale = delta > 0 ? scale * 1.1 : scale / 1.1;

                // Apply zoom limits
                scale = Math.min(Math.max(0.5, newScale), 5);

                // Adjust position to zoom toward mouse position
                pointX = mouseX - xs * scale;
                pointY = mouseY - ys * scale;

                setTransform();
                updateZoomLevel();
            }, {
                passive: false
            });

            // Mouse panning
            wrapper.addEventListener('mousedown', function(e) {
                if (scale > 1) {
                    e.preventDefault();
                    panning = true;
                    start = {
                        x: e.clientX - pointX,
                        y: e.clientY - pointY
                    };
                    wrapper.style.cursor = 'grabbing';
                }
            });

            wrapper.addEventListener('mousemove', function(e) {
                if (!panning) return;
                e.preventDefault();
                pointX = e.clientX - start.x;
                pointY = e.clientY - start.y;
                setTransform();
            });

            wrapper.addEventListener('mouseup', function() {
                panning = false;
                wrapper.style.cursor = scale > 1 ? 'grab' : 'zoom-in';
            });

            wrapper.addEventListener('mouseleave', function() {
                panning = false;
                wrapper.style.cursor = 'default';
            });

            // Touch events
            wrapper.addEventListener('touchstart', function(e) {
                if (e.touches.length === 2) {
                    e.preventDefault();
                    initialDistance = Math.hypot(
                        e.touches[0].clientX - e.touches[1].clientX,
                        e.touches[0].clientY - e.touches[1].clientY
                    );
                    initialScale = scale;
                } else if (e.touches.length === 1 && scale > 1) {
                    e.preventDefault();
                    const touch = e.touches[0];
                    start = {
                        x: touch.clientX - pointX,
                        y: touch.clientY - pointY
                    };
                }
            }, {
                passive: false
            });

            wrapper.addEventListener('touchmove', function(e) {
                if (e.touches.length === 2) {
                    e.preventDefault();
                    const currentDistance = Math.hypot(
                        e.touches[0].clientX - e.touches[1].clientX,
                        e.touches[0].clientY - e.touches[1].clientY
                    );
                    const midX = (e.touches[0].clientX + e.touches[1].clientX) / 2;
                    const midY = (e.touches[0].clientY + e.touches[1].clientY) / 2;

                    const rect = wrapper.getBoundingClientRect();
                    const relativeX = midX - rect.left;
                    const relativeY = midY - rect.top;

                    const xs = (relativeX - pointX) / scale;
                    const ys = (relativeY - pointY) / scale;

                    scale = Math.min(Math.max(0.5, initialScale * currentDistance / initialDistance), 5);

                    pointX = relativeX - xs * scale;
                    pointY = relativeY - ys * scale;

                    setTransform();
                    updateZoomLevel();
                } else if (e.touches.length === 1 && scale > 1) {
                    e.preventDefault();
                    const touch = e.touches[0];
                    pointX = touch.clientX - start.x;
                    pointY = touch.clientY - start.y;
                    setTransform();
                }
            }, {
                passive: false
            });

            // Button controls
            zoomInBtn.addEventListener('click', function() {
                scale = Math.min(scale * 1.2, 5);
                setTransform();
                updateZoomLevel();
            });

            zoomOutBtn.addEventListener('click', function() {
                scale = Math.max(scale / 1.2, 0.5);
                setTransform();
                updateZoomLevel();
            });

            resetZoomBtn.addEventListener('click', resetTransform);

            // Double-click to zoom
            wrapper.addEventListener('dblclick', function(e) {
                e.preventDefault();

                const rect = wrapper.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;

                // Calculate position relative to current scale
                const xs = (mouseX - pointX) / scale;
                const ys = (mouseY - pointY) / scale;

                if (scale === 1) {
                    scale = 2;
                    pointX = mouseX - xs * scale;
                    pointY = mouseY - ys * scale;
                } else {
                    resetTransform();
                }

                setTransform();
                updateZoomLevel();
            });
        });
    </script>
</body>

</html>