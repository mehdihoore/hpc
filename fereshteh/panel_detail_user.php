<?php
ini_set('memory_limit', '1G');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../sercon/config_fereshteh.php';

// Check user login
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'user'])) { // Allow admin and worker roles
    header('Location: unauthorized.php');
    exit();
}
// Get username from Session
$username = $_SESSION['username'];
$role = $_SESSION['role']; // Get the user role

secureSession();

function getWebPath($windowsPath) {
    if (empty($windowsPath)) return '';
    $path = preg_replace('/^[A-Z]:/i', '', $windowsPath);
    $path = str_replace('\\', '/', $path);
    $path = str_replace('/xampp/htdocs/', '/', $path);
    if (strpos($path, '/') !== 0) {
        $path = '/' . $path;
    }
    return $path;
}

// --- Database Connection ---
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
        ]
    );
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// --- Get the Date (Today, Yesterday, or Tomorrow) ---
$currentDate = date('Y-m-d'); // Today's date in YYYY-MM-DD format

if (isset($_GET['date']) && !empty($_GET['date'])) {
    $currentDate = $_GET['date'];
    // Basic validation to prevent invalid dates
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $currentDate)) {
        $currentDate = date('Y-m-d'); // Reset to today if invalid
    }
}

// Calculate Yesterday and Tomorrow
$yesterday = date('Y-m-d', strtotime('-1 day', strtotime($currentDate)));
$tomorrow = date('Y-m-d', strtotime('+1 day', strtotime($currentDate)));

// --- Prepare the query ---
$stmt = $pdo->prepare("
    SELECT
        hp.*,
        ft.type as formwork_type,
        s1.dimension_1 as left_dim1,
        s1.dimension_2 as left_dim2,
        s1.dimension_3 as left_dim3,
        s1.dimension_4 as left_dim4,
        s1.dimension_5 as left_dim5,
        s1.left_panel as left_panel_full,
        s1.right_panel as right_panel_full,
        s2.dimension_1 as right_dim1,
        s2.dimension_2 as right_dim2,
        s2.dimension_3 as right_dim3,
        s2.dimension_4 as right_dim4,
        s2.dimension_5 as right_dim5
    FROM hpc_panels hp
    LEFT JOIN formwork_types ft ON
        hp.width = ft.width AND
        hp.length BETWEEN 0 AND ft.max_length *1000
    LEFT JOIN sarotah s1 ON hp.address = SUBSTRING_INDEX(s1.left_panel, '-(', 1)
    LEFT JOIN sarotah s2 ON hp.address = SUBSTRING_INDEX(s2.right_panel, '-(', 1)
    WHERE DATE(hp.assigned_date) = ?
");
$stmt->execute([$currentDate]);
$panels = $stmt->fetchAll();

// --- Sarotah Description Function ---
function generateSarotahDescription($panel) {
    $descriptions = [];

    // Combined Scenarios: Handle 2 or 4 dimensions
    if (!empty($panel['left_dim1']) && !empty($panel['left_dim2'])) {
        if (!empty($panel['left_dim3']) && !empty($panel['left_dim4'])) {
            // If dim3 and dim4 are available
            $descriptions[] = [
                'type' => 'floor',
                'left' => $panel['left_dim2'],
                'right' => $panel['right_dim3']
            ];
            $descriptions[] = [
                'type' => 'wall',
                'left' => $panel['left_dim1'] + $panel['left_dim2'],
                'right' => $panel['right_dim3'] + $panel['right_dim4']
            ];
        } else {
            // If dim3 and dim4 are not available
            $descriptions[] = [
                'type' => 'floor',
                'left' => $panel['left_dim1'],
                'right' => $panel['right_dim2'] ?? $panel['left_dim2']
            ];
        }
    }

    return $descriptions;
}

?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>جزئیات پنل</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <style>
        /* ... (Your existing styles, keep .panel-svg, etc.) ... */
        @font-face {
            font-family: 'Vazir';
            src: url('https://cdn.jsdelivr.net/gh/rastikerdar/vazir-font@v30.1.0/dist/Vazir-Regular.woff2') format('woff2');
        }
        body {
            font-family: 'Vazir', sans-serif;
        }
       .panel-svg {
            width: 100%;
            height: 500px;
            background-color: #f8f9fa;
            border: 1px solid #e2e8f0;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            padding: 1rem;
            position: relative;
            overflow: hidden;
        }
        .panel-svg-container {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
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
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
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
        .date-section {
            margin-bottom: 2rem;
            padding: 1rem;
            border-bottom: 2px solid #e2e8f0;
        }

        /* New Styles */
        .date-navigation {
            display: flex;
            justify-content: space-between; /* Distribute items evenly */
            align-items: center;
            margin-bottom: 1.5rem;
            padding: 0.5rem 1rem;
            background-color: #f8f9fa;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
        }
        .date-navigation a {
            padding: 0.5rem 1rem;
            background-color: #4f46e5; /* Indigo-600 */
            color: white;
            border-radius: 0.375rem;
            text-decoration: none;
            transition: background-color 0.2s ease;
            font-size: 0.9rem;
        }
        .date-navigation a:hover {
            background-color: #4338ca; /* Indigo-700 */
        }
        .current-date {
            font-size: 1.25rem;
            font-weight: 600;
            text-align: center;
            white-space: nowrap; /* Prevents text wrapping */

        }
        /* Responsive adjustments */
        @media (max-width: 768px) { /* Tailwind's 'md' breakpoint */
            .date-navigation {
                flex-direction: column; /* Stack items vertically */
            }
            .date-navigation a {
                margin-bottom: 0.5rem; /* Spacing between buttons */
                width: 100%; /* Full-width buttons */
                text-align: center;
            }
            .current-date{
               margin-bottom: 0.5rem; /* Spacing between buttons */
                width: 100%; /* Full-width buttons */
                text-align: center;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold">جزئیات پنل</h1>
        <a href="steps.php"
        class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-green-600">شروع مراحل کار </a>
        <?php if ($role === 'admin'): ?>
                <a href="admin_panel_search.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600 mr-4">بازگشت</a>
            <?php endif; ?>
          
    </div>

    <!-- Date Navigation -->
    <div class="date-navigation">
        <a href="?date=<?php echo $yesterday; ?>">روز قبل</a>
          <div class="current-date">
            <span id="shamsiDate"></span>
            (<span id="gregorianDate"></span>)
        </div>
        <a href="?date=<?php echo $tomorrow; ?>">روز بعد</a>
    </div>

    <script>
        const options = {
            weekday: "long",
            year: "numeric",
            month: "numeric",
            day: "numeric",
            timeZone: "Asia/Tehran"
        };
        const date = new Date("<?php echo $currentDate; ?>");
        document.getElementById("shamsiDate").textContent = date.toLocaleDateString("fa-IR", options); // Shamsi first
        document.getElementById("gregorianDate").textContent = date.toLocaleDateString("en-US", options); // Gregorian in ()

    </script>

    <?php if (!empty($panels)): ?>
        <?php foreach ($panels as $panel): ?>
            <?php
            if (!empty($panel['svg_path'])) {
                $panel['svg_url'] = getWebPath($panel['svg_path']);
            }
            ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
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
                            <div>
                                <p class="font-semibold text-gray-600">وضعیت:</p>
                                <p class="capitalize"><?php
                                    echo $panel['status'] === 'pending' ? 'در انتظار' :
                                        ($panel['status'] === 'completed' ? 'تکمیل شده' :
                                            htmlspecialchars($panel['status']));
                                    ?></p>
                            </div>
                            <div>
                               <p class="font-semibold text-gray-600">تاریخ تحویل:</p>
                                 <p id="shamsiDate-<?php echo $panel['id']; ?>"></p> <!--  Shamsi -->
                                <p id="gregorianDate-<?php echo $panel['id']; ?>"></p>  <!--  Gregorian -->


                                <script>
                                    const options_<?php echo $panel['id']; ?> = {
                                        weekday: "long",
                                        year: "numeric",
                                        month: "numeric",
                                        day: "numeric",
                                        timeZone: "Asia/Tehran"
                                    };
                                    const date_<?php echo $panel['id']; ?> = new Date("<?php echo $panel['assigned_date']; ?>");
                                     document.getElementById("shamsiDate-<?php echo $panel['id']; ?>").textContent = "تاریخ:" + " " + date_<?php echo $panel['id']; ?>.toLocaleDateString("fa-IR", options_<?php echo $panel['id']; ?>);
                                    document.getElementById("gregorianDate-<?php echo $panel['id']; ?>").textContent =  "تاریخ:" + " " + date_<?php echo $panel['id']; ?>.toLocaleDateString("en-US", options_<?php echo $panel['id']; ?>);

                                </script>

                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">مساحت:</p>
                                <p><?php echo htmlspecialchars($panel['area']); ?> متر مربع</p>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-600">حجم:</p>
                                <p><?php echo number_format($panel['area'] * 0.03, 3); ?> متر مکعب</p>
                            </div>
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
                    <div class="panel-svg mb-8" id="svgContainer-<?php echo $panel['id']; ?>">
                        <div class="panel-svg-container" id="svgWrapper-<?php echo $panel['id']; ?>">
                            <img src="<?php echo htmlspecialchars($panel['svg_url']); ?>"
                                 alt="Panel SVG"
                                 id="svgImage-<?php echo $panel['id']; ?>"
                                 onerror="console.log('Failed to load SVG:', this.src); this.after('Failed to load SVG image');" />
                        </div>
                        <div class="zoom-controls">
                            <button class="zoom-btn zoomIn" data-panel-id="<?php echo $panel['id']; ?>" title="Zoom In">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19"></line>
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                            </button>
                            <button class="zoom-btn zoomOut" data-panel-id="<?php echo $panel['id']; ?>" title="Zoom Out">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                            </button>
                            <button class="zoom-btn resetZoom" data-panel-id="<?php echo $panel['id']; ?>" title="Reset Zoom">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
                                    <path d="M3 3v5h5"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="zoom-level" id="zoomLevel-<?php echo $panel['id']; ?>">100%</div>
                    </div>
                <?php endif; ?>

                <!-- Sarotah Details -->
                <?php if ($panel['left_dim1'] || $panel['right_dim1']): ?>
                    <div class="border-t pt-6">
                        <h2 class="text-xl font-bold mb-4">جزئیات سر و ته</h2>
                        <div class="space-y-4">
                            <?php $descriptions = generateSarotahDescription($panel); ?>
                            <?php if (!empty($descriptions)): ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    
                                    <div>
                                        <h3 class="font-semibold text-gray-600 mb-2">ابعاد شرقی</h3>
                                        <div class="space-y-2">
                                            <?php foreach ($descriptions as $desc): ?>
                                                <p class="<?php echo $desc['type'] === 'floor' ? 'bg-gray-50' : 'bg-green-50'; ?> p-2 rounded">
                                                    <?php
                                                    $text = $desc['type'] === 'floor'
                                                        ? "فاصله از مرکز تا پلیت کف E: {$desc['right']} میلیمتر"
                                                        : "فاصله از مرکز تا پلیت دیواره E: {$desc['right']} میلیمتر";
                                                    echo htmlspecialchars($text);
                                                    ?>
                                                </p>
                                            <?php endforeach; ?>
                                            <div class="grid grid-cols-4 gap-2">
                                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                                    <div class="bg-gray-50 p-2 rounded">
                                                        <span class="block text-xs text-gray-500">D<?php echo $i; ?></span>
                                                        <?php echo htmlspecialchars($panel["right_dim$i"] ?? ''); ?>
                                                    </div>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-600 mb-2">ابعاد غربی</h3>
                                        <div class="space-y-2">
                                            <?php foreach ($descriptions as $desc): ?>
                                                <p class="<?php echo $desc['type'] === 'floor' ? 'bg-gray-50' : 'bg-green-50'; ?> p-2 rounded">
                                                    <?php
                                                    $text = $desc['type'] === 'floor'
                                                        ? "فاصله از مرکز تا پلیت کف W: {$desc['left']} میلیمتر"
                                                        : "فاصله از مرکز تا پلیت دیواره W: {$desc['left']} میلیمتر";
                                                    echo htmlspecialchars($text);
                                                    ?>
                                                </p>
                                            <?php endforeach; ?>
                                            <div class="grid grid-cols-4 gap-2">
                                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                                    <div class="bg-gray-50 p-2 rounded">
                                                        <span class="block text-xs text-gray-500">D<?php echo $i; ?></span>
                                                        <?php echo htmlspecialchars($panel["left_dim$i"] ?? ''); ?>
                                                    </div>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded">
            هیچ پنلی برای تاریخ انتخاب شده یافت نشد.
        </div>
    <?php endif; ?>
</div>

<script>
    // --- Zooming and Panning Function (OUTSIDE DOMContentLoaded) ---
    function setupZoomAndPan(containerId, wrapperId, imgId, zoomLevelId) {
        const container = document.getElementById(containerId);
        const wrapper = document.getElementById(wrapperId);
        const img = document.getElementById(imgId);
        const zoomLevelDisplay = document.getElementById(zoomLevelId);

        if (!img) return;

        let scale = 1;
        let panning = false;
        let pointX = 0;
        let pointY = 0;
        let start = {x: 0, y: 0};

        function updateZoomLevel() {
            zoomLevelDisplay.style.display = 'block';
            zoomLevelDisplay.textContent = `${Math.round(scale * 100)}%`;
            setTimeout(() => {
                zoomLevelDisplay.style.display = 'none';
            }, 1500);
        }

        function setTransform() {
            img.style.transform = `translate(${pointX}px, ${pointY}px) scale(${scale})`;
        }

        function resetTransform() {
            scale = 1;
            pointX = 0;
            pointY = 0;
            setTransform();
            updateZoomLevel();
            container.classList.remove('zoomed');
        }

        wrapper.addEventListener('wheel', (e) => {
            e.preventDefault();
            const xs = (e.clientX - pointX) / scale;
            const ys = (e.clientY - pointY) / scale;
            const delta = -e.deltaY;
            const newScale = scale * (1 - delta / 800);
            scale = Math.min(Math.max(1, newScale), 4);
            pointX = e.clientX - xs * scale;
            pointY = e.clientY - ys * scale;
            setTransform();
            updateZoomLevel();
            if (scale > 1) {
                container.classList.add('zoomed');
            } else {
                container.classList.remove('zoomed');
            }
        });

        // Touch zoom
        let initialDistance = 0;
        let initialScale = 1;

        wrapper.addEventListener('touchstart', (e) => {
            if (e.touches.length === 2) {
                e.preventDefault();
                initialDistance = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                initialScale = scale;
            }
        });

        wrapper.addEventListener('touchmove', (e) => {
            if (e.touches.length === 2) {
                e.preventDefault();
                const distance = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                const newScale = initialScale * (distance / initialDistance);
                scale = Math.min(Math.max(1, newScale), 4);
                setTransform();
                updateZoomLevel();
                if (scale > 1) {
                    container.classList.add('zoomed');
                } else {
                    container.classList.remove('zoomed');
                }
            }
        });

        wrapper.addEventListener('mousedown', (e) => {
            e.preventDefault();
            if (scale > 1) {
                panning = true;
                start = {x: e.clientX - pointX, y: e.clientY - pointY};
            }
        });

        wrapper.addEventListener('mousemove', (e) => {
            e.preventDefault();
            if (panning && scale > 1) {
                pointX = e.clientX - start.x;
                pointY = e.clientY - start.y;
                setTransform();
            }
        });

        wrapper.addEventListener('mouseup', () => {
            panning = false;
        });

        wrapper.addEventListener('mouseleave', () => {
            panning = false;
        });

        // Double click to zoom
        wrapper.addEventListener('dblclick', (e) => {
            e.preventDefault();
            if (scale === 1) {
                scale = 2;
                pointX = e.clientX - (e.clientX - pointX) * 2;
                pointY = e.clientY - (e.clientY - pointY) * 2;
                container.classList.add('zoomed');
            } else {
                resetTransform();
            }
            setTransform();
            updateZoomLevel();
        });

        return {resetTransform}; // Return reset function
    }


    document.addEventListener('DOMContentLoaded', function () {


        const resetFunctions = [];
        // Set up zoom and pan for each panel

        <?php
        foreach ($panels as $panel):
        ?>

        <?php if (!empty($panel['svg_url'])): ?>
        const resetFunc<?php echo $panel['id']; ?> = setupZoomAndPan(
            'svgContainer-<?php echo $panel['id']; ?>',
            'svgWrapper-<?php echo $panel['id']; ?>',
            'svgImage-<?php echo $panel['id']; ?>',
            'zoomLevel-<?php echo $panel['id']; ?>'
        );
        resetFunctions.push(resetFunc<?php echo $panel['id']; ?>);


        //Zoom buttons
        document.querySelector('.zoomIn[data-panel-id="<?php echo $panel['id']; ?>"]').addEventListener('click', () => {
            const img = document.getElementById('svgImage-<?php echo $panel['id']; ?>');
            const container = document.getElementById('svgContainer-<?php echo $panel['id']; ?>');
            let scale = parseFloat(img.style.transform.match(/scale\(([^)]+)\)/)?.[1] || 1);
            scale = Math.min(scale * 1.2, 4);
            img.style.transform = img.style.transform.replace(/scale\([^)]*\)/, `scale(${scale})`);

            // updateZoomLevel
            const zoomLevelDisplay = document.getElementById('zoomLevel-<?php echo $panel['id']; ?>');
            zoomLevelDisplay.style.display = 'block';
            zoomLevelDisplay.textContent = `${Math.round(scale * 100)}%`;
            setTimeout(() => {
                zoomLevelDisplay.style.display = 'none';
            }, 1500);

            if (scale > 1) container.classList.add('zoomed');
        });

        document.querySelector('.zoomOut[data-panel-id="<?php echo $panel['id']; ?>"]').addEventListener('click', () => {
            const img = document.getElementById('svgImage-<?php echo $panel['id']; ?>');
            const container = document.getElementById('svgContainer-<?php echo $panel['id']; ?>');
            let scale = parseFloat(img.style.transform.match(/scale\(([^)]+)\)/)?.[1] || 1);
            scale = Math.max(scale / 1.2, 1);
            img.style.transform = img.style.transform.replace(/scale\([^)]*\)/, `scale(${scale})`);

            // updateZoomLevel
            const zoomLevelDisplay = document.getElementById('zoomLevel-<?php echo $panel['id']; ?>');
            zoomLevelDisplay.style.display = 'block';
            zoomLevelDisplay.textContent = `${Math.round(scale * 100)}%`;
            setTimeout(() => {
                zoomLevelDisplay.style.display = 'none';
            }, 1500);


            if (scale === 1) container.classList.remove('zoomed');
        });

        document.querySelector('.resetZoom[data-panel-id="<?php echo $panel['id']; ?>"]').addEventListener('click', () => {
            resetFunc<?php echo $panel['id']; ?>.resetTransform();
        });


        <?php endif; ?>
        <?php endforeach;
        ?>
    });
</script>
</body>
</html>