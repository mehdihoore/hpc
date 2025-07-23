<?php
// public_html/ghom/viewer.php (OPTIMIZED VERSION)

require_once __DIR__ . '/../../sercon/bootstrap.php';
secureSession();

if (!isLoggedIn()) {
    http_response_code(403);
    die("Access Denied.");
}

// --- Get Parameters ---
$plan_file = $_GET['plan'] ?? null;
$highlight_type = $_GET['highlight'] ?? 'all';
$highlight_status = $_GET['highlight_status'] ?? 'all';

if (!$plan_file || !preg_match('/^[\w\.-]+\.svg$/i', $plan_file)) {
    http_response_code(400);
    die("Error: Invalid plan file name.");
}

// --- Fetch Element Data (OPTIMIZED) ---
try {
    $pdo = getProjectDBConnection('ghom');
    // Add LIMIT to prevent huge datasets
    $stmt = $pdo->prepare("
        SELECT e.element_id, e.x_coord, e.y_coord, COALESCE(i_status.final_status, 'Pending') as final_status
        FROM elements e
        LEFT JOIN (
            SELECT SUBSTRING_INDEX(element_id, '-', 3) as base_element_id,
                   CASE
                       WHEN MAX(CASE WHEN overall_status = 'Not OK' THEN 1 ELSE 0 END) = 1 THEN 'Not OK'
                       WHEN MAX(CASE WHEN overall_status = 'OK' THEN 1 ELSE 0 END) = 1 THEN 'OK'
                       WHEN MAX(CASE WHEN contractor_status = 'Ready for Inspection' THEN 1 ELSE 0 END) = 1 THEN 'Ready for Inspection'
                       ELSE 'Pending'
                   END as final_status
            FROM inspections GROUP BY base_element_id
        ) AS i_status ON e.element_id = i_status.base_element_id
        WHERE e.plan_file = ? AND e.x_coord IS NOT NULL AND e.y_coord IS NOT NULL
        LIMIT 5000
    ");
    $stmt->execute([$plan_file]);
    $elements_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// --- COLOR CONFIGURATION ---
$element_styles_config = [
    'GFRC' => '#ff9966',
    'GLASS' => '#eef595da',
    'Mullion' => 'rgba(128, 128, 128, 0.9)',
    'Transom' => 'rgba(169, 169, 169, 0.9)',
    'Bazshow' => 'rgba(169, 169, 169, 0.9)',
    'Zirsazi' => '#2464ee',
    'STONE' => '#4c28a1'
];
$inactive_fill = '#d3d3d3';

// --- GENERATE STYLES ---
$style_block = "\n<style>\n";
foreach ($element_styles_config as $group_id => $bright_color) {
    $color_to_use = ($highlight_type === 'all' || strtolower($group_id) === strtolower($highlight_type)) ? $bright_color : $inactive_fill;
    $selector = "#{$group_id} path, #{$group_id} rect, #{$group_id} polygon, #{$group_id} circle";
    $style_block .= "\t{$selector} { fill: {$color_to_use} !important; stroke: #333 !important; stroke-width: 0.5px !important; }\n";
}
$style_block .= "</style>\n";

// --- Read and Process SVG ---
$svg_path = realpath(__DIR__ . '/assets/svg/') . '/' . $plan_file;
if (!file_exists($svg_path)) {
    http_response_code(404);
    die("Error: SVG file not found.");
}

// Check file size to prevent memory issues
$file_size = filesize($svg_path);
if ($file_size > 50 * 1024 * 1024) { // 50MB limit
    die("Error: SVG file too large to process.");
}

$svg_content = file_get_contents($svg_path);
$end_of_svg_tag = strpos($svg_content, '>');
if ($end_of_svg_tag !== false) {
    $svg_content = substr_replace($svg_content, $style_block, $end_of_svg_tag + 1, 0);
}

$pageTitle = "نمایشگر نقشه: " . escapeHtml($plan_file);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viewer: <?php echo escapeHtml($plan_file); ?></title>
    <style>
        body,
        html {
            margin: 0;
            padding: 0;
            font-family: 'Samim', sans-serif;
            height: 100vh;
            overflow: hidden;
        }

        .viewer-wrapper {
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        #viewer-toolbar {
            padding: 8px;
            background: #fff;
            border-bottom: 1px solid #ccc;
            text-align: left;
            flex-shrink: 0;
            direction: ltr;
        }

        #viewer-toolbar button {
            padding: 5px 10px;
            margin: 0 5px;
            font-family: sans-serif;
            cursor: pointer;
        }

        #legend {
            padding: 5px 10px;
            float: right;
        }

        #legend span {
            margin: 0 10px;
            vertical-align: middle;
        }

        .legend-color {
            display: inline-block;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            border: 1px solid #555;
            vertical-align: middle;
            margin-left: 5px;
        }

        #svg-container-wrapper {
            flex-grow: 1;
            overflow: hidden;
            background: #e9ecef;
            cursor: grab;
            position: relative;
        }

        #svg-container-wrapper.grabbing {
            cursor: grabbing;
        }

        #svg-container {
            width: 100%;
            height: 100%;
            position: relative;
        }

        #svg-container svg {
            width: 100%;
            height: 100%;
            transform-origin: 0 0;
        }

        .highlight-marker {
            stroke: #000;
            stroke-width: 1px;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .highlight-marker:hover {
            stroke-width: 3px;
            transform: scale(1.3);
        }

        .svg-tooltip {
            position: absolute;
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            pointer-events: none;
            display: none;
            z-index: 1000;
        }

        #loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 18px;
            color: #666;
        }

        @media print {
            #viewer-toolbar {
                display: none;
            }

            #legend {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="viewer-wrapper">
        <div id="viewer-toolbar">
            <div id="legend">
                <span style="font-weight: bold;">راهنما:</span>
                <span><span class="legend-color" style="background-color: #28a745;"></span>تایید شده</span>
                <span><span class="legend-color" style="background-color: #dc3545;"></span>رد شده</span>
                <span><span class="legend-color" style="background-color: #ffc107;"></span>آماده بازرسی</span>
                <span><span class="legend-color" style="background-color: #cccccc;"></span>در انتظار</span>
            </div>
            <button id="zoom-in-btn">+</button>
            <button id="zoom-out-btn">-</button>
            <button id="zoom-reset-btn">ریست</button>
            <button id="print-btn">چاپ</button>
            <button id="download-btn">دانلود SVG</button>
            <button id="debug-btn">Debug Info</button>
        </div>

        <div id="svg-container-wrapper">
            <div id="svg-container">
                <div id="loading">در حال بارگذاری...</div>
                <?php echo $svg_content; ?>
            </div>
            <div class="svg-tooltip"></div>
        </div>
    </div>

    <script>
        // Configuration
        const ELEMENTS_DATA = <?php echo json_encode($elements_data); ?>;
        const HIGHLIGHT_STATUS = "<?php echo escapeHtml($highlight_status); ?>";
        const STATUS_COLORS = {
            'OK': '#28a745',
            'Not OK': '#dc3545',
            'Ready for Inspection': '#ffc107',
            'Pending': '#cccccc'
        };

        // Pan and zoom variables
        let scale = 1;
        let panX = 0;
        let panY = 0;
        let isPanning = false;
        let startX = 0;
        let startY = 0;

        document.addEventListener('DOMContentLoaded', function() {
            const wrapper = document.getElementById('svg-container-wrapper');
            const container = document.getElementById('svg-container');
            const tooltip = document.querySelector('.svg-tooltip');
            const loading = document.getElementById('loading');

            // Wait for SVG to be ready
            setTimeout(() => {
                const svgElement = container.querySelector('svg');
                if (!svgElement) {
                    console.error("SVG Element not found");
                    loading.textContent = "خطا: فایل SVG یافت نشد";
                    return;
                }

                loading.style.display = 'none';
                initializeViewer(svgElement, wrapper, tooltip);
            }, 100);
        });

        function initializeViewer(svgElement, wrapper, tooltip) {
            // Setup pan and zoom
            setupPanAndZoom(svgElement, wrapper);

            // Add circles (optimized)
            addStatusCircles(svgElement, tooltip);

            // Setup toolbar buttons
            setupToolbar(svgElement);
        }

        function setupPanAndZoom(svgElement, wrapper) {
            function updateTransform() {
                svgElement.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
            }

            wrapper.addEventListener('mousedown', e => {
                if (e.target.classList.contains('highlight-marker')) return;
                isPanning = true;
                wrapper.classList.add('grabbing');
                startX = e.clientX - panX;
                startY = e.clientY - panY;
                e.preventDefault();
            });

            wrapper.addEventListener('mousemove', e => {
                if (isPanning) {
                    panX = e.clientX - startX;
                    panY = e.clientY - startY;
                    updateTransform();
                }
            });

            wrapper.addEventListener('mouseup', () => {
                isPanning = false;
                wrapper.classList.remove('grabbing');
            });

            wrapper.addEventListener('mouseleave', () => {
                isPanning = false;
                wrapper.classList.remove('grabbing');
            });

            wrapper.addEventListener('wheel', e => {
                e.preventDefault();
                const rect = wrapper.getBoundingClientRect();
                const xs = (e.clientX - rect.left - panX) / scale;
                const ys = (e.clientY - rect.top - panY) / scale;
                const delta = e.deltaY > 0 ? 0.9 : 1.1;
                const newScale = Math.max(0.1, Math.min(10, scale * delta));

                panX += xs * scale - xs * newScale;
                panY += ys * scale - ys * newScale;
                scale = newScale;
                updateTransform();
            });

            // Zoom buttons
            document.getElementById('zoom-in-btn').onclick = () => {
                scale = Math.min(10, scale * 1.2);
                updateTransform();
            };

            document.getElementById('zoom-out-btn').onclick = () => {
                scale = Math.max(0.1, scale / 1.2);
                updateTransform();
            };

            document.getElementById('zoom-reset-btn').onclick = () => {
                scale = 1;
                panX = 0;
                panY = 0;
                updateTransform();
            };
        }

        function addStatusCircles(svgElement, tooltip) {
            // Debug: Log the data we received
            console.log('Elements data:', ELEMENTS_DATA);
            console.log('SVG viewBox:', svgElement.getAttribute('viewBox'));
            console.log('SVG width/height:', svgElement.getAttribute('width'), svgElement.getAttribute('height'));

            if (!ELEMENTS_DATA || ELEMENTS_DATA.length === 0) {
                console.warn('No elements data available for circles');
                return;
            }

            // Create highlight layer
            const highlightLayer = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            highlightLayer.id = 'status-highlight-layer';
            svgElement.appendChild(highlightLayer);

            // Add a test circle first to verify coordinate system
            const testCircle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            testCircle.setAttribute('cx', '2719.95');
            testCircle.setAttribute('cy', '790.878');
            testCircle.setAttribute('r', '20');
            testCircle.setAttribute('fill', 'red');
            testCircle.setAttribute('stroke', 'black');
            testCircle.setAttribute('stroke-width', '3');
            testCircle.setAttribute('id', 'test-circle');
            highlightLayer.appendChild(testCircle);
            console.log('Added test circle at 2719.95, 790.878');

            // Get SVG coordinate system info
            const viewBox = svgElement.getAttribute('viewBox');
            let svgWidth = parseFloat(svgElement.getAttribute('width')) || svgElement.getBBox().width;
            let svgHeight = parseFloat(svgElement.getAttribute('height')) || svgElement.getBBox().height;

            console.log('SVG dimensions:', svgWidth, svgHeight);

            // Add circles in batches to prevent blocking
            const batchSize = 50;
            let index = 0;
            let addedCount = 0;

            function addBatch() {
                const endIndex = Math.min(index + batchSize, ELEMENTS_DATA.length);

                for (let i = index; i < endIndex; i++) {
                    const element = ELEMENTS_DATA[i];

                    // Validate coordinates
                    const x = parseFloat(element.x_coord);
                    const y = parseFloat(element.y_coord);

                    if (isNaN(x) || isNaN(y)) {
                        console.warn('Invalid coordinates for element:', element.element_id, x, y);
                        continue;
                    }

                    console.log(`Processing element ${i}: ID=${element.element_id}, Status="${elementStatus}", Filter="${filterStatus}", Match=${elementStatus === filterStatus || HIGHLIGHT_STATUS === 'all'}`);

                    // Fix status matching - trim whitespace and handle case sensitivity
                    const elementStatus = (element.final_status || '').toString().trim();
                    const filterStatus = HIGHLIGHT_STATUS.toString().trim();

                    if (HIGHLIGHT_STATUS === 'all' || elementStatus === filterStatus) {
                        const color = STATUS_COLORS[element.final_status] || '#800080';
                        const marker = document.createElementNS('http://www.w3.org/2000/svg', 'circle');

                        // Set circle attributes
                        marker.setAttribute('cx', x);
                        marker.setAttribute('cy', y);
                        marker.setAttribute('r', '15'); // Make circles slightly larger for visibility
                        marker.setAttribute('fill', color);
                        marker.setAttribute('fill-opacity', '0.7');
                        marker.setAttribute('stroke', '#000');
                        marker.setAttribute('stroke-width', '2');
                        marker.setAttribute('class', 'highlight-marker');

                        // Add data attributes for debugging
                        marker.setAttribute('data-element-id', element.element_id);
                        marker.setAttribute('data-status', element.final_status);

                        // Tooltip events
                        marker.addEventListener('mouseenter', e => {
                            tooltip.style.display = 'block';
                            tooltip.textContent = `ID: ${element.element_id} | وضعیت: ${element.final_status} | X: ${x}, Y: ${y}`;
                        });

                        marker.addEventListener('mousemove', e => {
                            tooltip.style.left = `${e.pageX + 15}px`;
                            tooltip.style.top = `${e.pageY + 15}px`;
                        });

                        marker.addEventListener('mouseleave', () => {
                            tooltip.style.display = 'none';
                        });

                        // Click event for debugging
                        marker.addEventListener('click', () => {
                            console.log('Clicked element:', element);
                        });

                        highlightLayer.appendChild(marker);
                        addedCount++;

                        // Debug first few circles
                        if (addedCount <= 5) {
                            console.log(`Added circle ${addedCount}: ID=${element.element_id}, X=${x}, Y=${y}, Status=${element.final_status}, Color=${color}`);
                        }
                    }
                }

                index = endIndex;

                if (index < ELEMENTS_DATA.length) {
                    requestAnimationFrame(addBatch);
                } else {
                    console.log(`Finished adding ${addedCount} circles out of ${ELEMENTS_DATA.length} elements`);

                    // If no circles were added, show debug info
                    if (addedCount === 0) {
                        console.warn('No circles were added. Debug info:');
                        console.log('HIGHLIGHT_STATUS:', HIGHLIGHT_STATUS);
                        console.log('Available statuses:', [...new Set(ELEMENTS_DATA.map(e => e.final_status))]);
                        console.log('Sample element:', ELEMENTS_DATA[0]);
                    }
                }
            }

            addBatch();
        }

        function setupToolbar(svgElement) {
            document.getElementById('print-btn').onclick = () => window.print();

            document.getElementById('download-btn').onclick = () => {
                try {
                    const svgData = new XMLSerializer().serializeToString(svgElement);
                    const blob = new Blob([svgData], {
                        type: 'image/svg+xml;charset=utf-8'
                    });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = '<?php echo escapeHtml($plan_file); ?>';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                } catch (error) {
                    alert('خطا در دانلود فایل: ' + error.message);
                }
            };

            document.getElementById('debug-btn').onclick = () => {
                const debugInfo = {
                    'Elements Data Count': ELEMENTS_DATA.length,
                    'Highlight Status': HIGHLIGHT_STATUS,
                    'Available Statuses': [...new Set(ELEMENTS_DATA.map(e => e.final_status))],
                    'SVG ViewBox': svgElement.getAttribute('viewBox'),
                    'SVG Width': svgElement.getAttribute('width') || 'not set',
                    'SVG Height': svgElement.getAttribute('height') || 'not set',
                    'Circles Added': document.querySelectorAll('.highlight-marker').length,
                    'Sample Coordinates': ELEMENTS_DATA.slice(0, 3).map(e => ({
                        id: e.element_id,
                        x: e.x_coord,
                        y: e.y_coord,
                        status: e.final_status
                    }))
                };

                console.log('=== DEBUG INFO ===');
                Object.entries(debugInfo).forEach(([key, value]) => {
                    console.log(`${key}:`, value);
                });

                alert('Debug info logged to console. Press F12 to view.');
            };
        }
    </script>
</body>

</html>