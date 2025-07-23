<?php
// public_html/ghom/viewer.php (FINAL VERSION with Touch, Counts, and Flicker Fix)

require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once __DIR__ . '/includes/jdf.php';
secureSession();

if (!isLoggedIn()) {
    http_response_code(403);
    die("Access Denied.");
}

// --- Persian Date Conversion Function ---
function toPersianDate($gregorian_date)
{
    if (empty($gregorian_date)) return null;
    $timestamp = strtotime($gregorian_date);
    if ($timestamp === false) return $gregorian_date;
    return jdate('Y/m/d', $timestamp, '', 'Asia/Tehran', 'fa');
}

// --- Get Parameters ---
$plan_file = $_GET['plan'] ?? null;
$highlight_type = $_GET['highlight'] ?? 'all';
$highlight_status = $_GET['highlight_status'] ?? 'all';

if (!$plan_file || !preg_match('/^[\w\.-]+\.svg$/i', $plan_file)) {
    http_response_code(400);
    die("Error: Invalid plan file name.");
}

// Initialize status counts
$status_counts = [
    'OK' => 0,
    'Not OK' => 0,
    'Ready for Inspection' => 0,
    'Pending' => 0
];

// --- Fetch Element Data ---
try {
    $pdo = getProjectDBConnection('ghom');
    $stmt = $pdo->prepare("
        SELECT 
            e.element_id, e.x_coord, e.y_coord, e.element_type, e.zone_name, e.floor_level,
            COALESCE(i.final_status, 'Pending') as final_status, i.inspection_date, i.notes, i.contractor_status, 
            i.contractor_notes, i.contractor_date
        FROM elements e
        LEFT JOIN (
            SELECT 
                insp.element_id, insp.inspection_date, insp.notes, insp.contractor_status,
                insp.contractor_notes, insp.contractor_date, COALESCE(istat.final_status, 'Pending') as final_status
            FROM inspections insp
            INNER JOIN (
                SELECT element_id, MAX(inspection_date) as max_date
                FROM inspections GROUP BY element_id
            ) latest ON insp.element_id = latest.element_id AND insp.inspection_date = latest.max_date
            LEFT JOIN (
                SELECT 
                    SUBSTRING_INDEX(element_id, '-', 3) as base_element_id,
                    CASE
                        WHEN MAX(CASE WHEN overall_status = 'Not OK' THEN 1 ELSE 0 END) = 1 THEN 'Not OK'
                        WHEN MAX(CASE WHEN overall_status = 'OK' THEN 1 ELSE 0 END) = 1 THEN 'OK'
                        WHEN MAX(CASE WHEN contractor_status = 'Ready for Inspection' THEN 1 ELSE 0 END) = 1 THEN 'Ready for Inspection'
                        ELSE 'Pending'
                    END as final_status
                FROM inspections GROUP BY base_element_id
            ) istat ON SUBSTRING_INDEX(insp.element_id, '-', 3) = istat.base_element_id
        ) AS i ON e.element_id = i.element_id
        WHERE e.plan_file = ? AND e.x_coord IS NOT NULL AND e.y_coord IS NOT NULL
        LIMIT 5000
    ");
    $stmt->execute([$plan_file]);
    $elements_data_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process dates and count statuses
    $elements_data = array_map(function ($el) use (&$status_counts) {
        $el['inspection_date_persian'] = toPersianDate($el['inspection_date']);
        $el['contractor_date_persian'] = toPersianDate($el['contractor_date']);

        // Increment the count for the element's status
        $status = $el['final_status'] ?? 'Pending';
        if (array_key_exists($status, $status_counts)) {
            $status_counts[$status]++;
        } else {
            $status_counts['Pending']++;
        }

        return $el;
    }, $elements_data_raw);
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// --- STYLING & SVG PREPARATION ---
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

$style_block = "\n";
foreach ($element_styles_config as $group_id => $bright_color) {
    $is_active = ($highlight_type === 'all' || strtolower($group_id) === strtolower($highlight_type));
    $color_to_use = $is_active ? $bright_color : $inactive_fill;
    $selector = "#{$group_id} path, #{$group_id} rect, #{$group_id} polygon, #{$group_id} circle, #{$group_id} ellipse, #{$group_id} line, #{$group_id} polyline";
    $style_block .= "\t{$selector} { fill: {$color_to_use} !important; stroke: #333 !important; stroke-width: 0.5px !important; }\n";
}

$svg_path = realpath(__DIR__ . '/assets/svg/') . '/' . $plan_file;
if (!file_exists($svg_path)) {
    http_response_code(404);
    die("Error: SVG file not found.");
}
$svg_content = file_get_contents($svg_path);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Viewer: <?php echo escapeHtml($plan_file); ?></title>
    <style>
        body,
        html {
            margin: 0;
            padding: 0;
            font-family: 'Samim', sans-serif;
            height: 100vh;
            overflow: hidden;
            background-color: #f0f0f0;
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
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap-reverse;
            gap: 10px;
        }

        #viewer-toolbar .controls {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
        }

        #viewer-toolbar button {
            padding: 8px 14px;
            margin: 0 4px;
            cursor: pointer;
            border-radius: 4px;
            border: 1px solid #ccc;
            background-color: #f8f9fa;
            font-size: 1rem;
        }

        #legend {
            padding: 5px 10px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 15px;
            flex-grow: 1;
        }

        #legend span {
            display: inline-flex;
            align-items: center;
            margin: 0;
            font-size: 13px;
        }

        .legend-color {
            display: inline-block;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            border: 1px solid #555;
            vertical-align: middle;
            margin-left: 6px;
        }

        .legend-count {
            font-weight: bold;
            margin-right: 4px;
            background-color: #e9ecef;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
        }

        #svg-container-wrapper {
            flex-grow: 1;
            overflow: hidden;
            background: #e9ecef;
            cursor: grab;
            position: relative;
            touch-action: none;
            /* Prevents default touch actions like scrolling */
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
            position: absolute;
            top: 0;
            left: 0;
        }

        .highlight-marker {
            stroke: #000;
            stroke-width: 1px;
            transition: transform 0.15s ease, stroke-width 0.15s ease;
            cursor: pointer;
            transform-origin: center;
        }

        .marker-hover {
            stroke-width: 2px;
            transform: scale(1.2);
        }

        .svg-tooltip {
            position: fixed;
            background-color: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            pointer-events: none;
            z-index: 10000;
            max-width: 350px;
            line-height: 1.6;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.15s ease-in-out;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            white-space: nowrap;
        }

        .tooltip-visible {
            opacity: 1;
            visibility: visible;
        }

        .svg-tooltip strong {
            color: #ffc107;
        }

        #loading,
        #filter-no-results-message {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            font-size: 18px;
            z-index: 1000;
        }

        #loading {
            top: 50%;
            transform: translate(-50%, -50%);
        }

        #filter-no-results-message {
            top: 20px;
            background-color: rgba(255, 193, 7, 0.9);
            color: #333;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            display: none;
        }

        @media print {

            #viewer-toolbar,
            #filter-no-results-message,
            .svg-tooltip {
                display: none !important;
            }
        }
    </style>
</head>

<body>
    <div class="viewer-wrapper">
        <div id="viewer-toolbar">
            <div id="legend">
                <span><span class="legend-color" style="background-color: #28a745;"></span>تایید شده<span class="legend-count" id="count-ok">0</span></span>
                <span><span class="legend-color" style="background-color: #dc3545;"></span>رد شده<span class="legend-count" id="count-not-ok">0</span></span>
                <span><span class="legend-color" style="background-color: #ffc107;"></span>آماده بازرسی<span class="legend-count" id="count-ready">0</span></span>
                <span><span class="legend-color" style="background-color: #cccccc;"></span>در انتظار<span class="legend-count" id="count-pending">0</span></span>
            </div>
            <div class="controls">
                <button id="zoom-in-btn">+</button> <button id="zoom-out-btn">-</button> <button id="zoom-reset-btn">ریست</button>
                <button id="print-btn">چاپ</button> <button id="download-btn">دانلود SVG</button>
            </div>
        </div>
        <div id="svg-container-wrapper">
            <div id="loading">در حال بارگذاری...</div>
            <div id="svg-container"></div>
            <div id="filter-no-results-message"></div>
            <div class="svg-tooltip"></div>
        </div>
    </div>
    <script>
        const ELEMENTS_DATA = <?php echo json_encode($elements_data); ?>;
        const STATUS_COUNTS = <?php echo json_encode($status_counts); ?>;
        const SVG_CONTENT = <?php echo json_encode($svg_content); ?>;
        const STYLE_RULES = <?php echo json_encode($style_block); ?>;
        const HIGHLIGHT_STATUS = <?php echo json_encode($highlight_status); ?>;
        const STATUS_COLORS = {
            'OK': '#28a745',
            'Not OK': '#dc3545',
            'Ready for Inspection': '#ffc107',
            'Pending': '#cccccc'
        };

        let scale = 1,
            panX = 0,
            panY = 0,
            svgElement = null,
            tooltip = null;
        let tooltipTimeout = null;

        document.addEventListener('DOMContentLoaded', () => {
            const container = document.getElementById('svg-container');
            const loadingEl = document.getElementById('loading');

            container.innerHTML = SVG_CONTENT;
            svgElement = container.querySelector('svg');

            if (!svgElement) {
                loadingEl.textContent = 'خطا: فایل SVG بارگیری نشد';
                return;
            }

            loadingEl.style.display = 'none';
            tooltip = document.querySelector('.svg-tooltip');

            requestAnimationFrame(() => {
                initializeViewer(document.getElementById('svg-container-wrapper'));
            });
        });

        function initializeViewer(wrapper) {
            const styleElement = document.createElementNS('http://www.w3.org/2000/svg', 'style');
            styleElement.textContent = STYLE_RULES;
            svgElement.prepend(styleElement);

            updateLegendCounts();
            setupPanAndZoom(wrapper);
            addStatusCircles();
            setupToolbar();
            setupGlobalTooltipHandling();
        }

        function updateLegendCounts() {
            document.getElementById('count-ok').textContent = STATUS_COUNTS['OK'] || 0;
            document.getElementById('count-not-ok').textContent = STATUS_COUNTS['Not OK'] || 0;
            document.getElementById('count-ready').textContent = STATUS_COUNTS['Ready for Inspection'] || 0;
            document.getElementById('count-pending').textContent = STATUS_COUNTS['Pending'] || 0;
        }

        function setupGlobalTooltipHandling() {
            document.addEventListener('mousemove', e => {
                if (tooltip.classList.contains('tooltip-visible')) {
                    tooltip.style.left = `${e.clientX + 15}px`;
                    tooltip.style.top = `${e.clientY - 10}px`;
                }
            });
            document.getElementById('svg-container-wrapper').addEventListener('mouseleave', () => hideTooltip());
        }

        function showTooltip(content) {
            if (tooltipTimeout) clearTimeout(tooltipTimeout);
            tooltip.innerHTML = content;
            tooltip.classList.add('tooltip-visible');
        }

        function hideTooltip() {
            tooltipTimeout = setTimeout(() => {
                tooltip.classList.remove('tooltip-visible');
            }, 50);
        }

        function setupPanAndZoom(wrapper) {
            let isPanning = false,
                startX = 0,
                startY = 0;
            let initialPinchDistance = null;

            const updateTransform = () => {
                svgElement.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
            };

            const handlePanStart = (clientX, clientY) => {
                isPanning = true;
                wrapper.classList.add('grabbing');
                startX = clientX - panX;
                startY = clientY - panY;
            };

            const handlePanMove = (clientX, clientY) => {
                if (!isPanning) return;
                panX = clientX - startX;
                panY = clientY - startY;
                updateTransform();
            };

            const handlePanEnd = () => {
                isPanning = false;
                wrapper.classList.remove('grabbing');
                initialPinchDistance = null;
            };

            const getPinchDistance = (touches) => Math.hypot(touches[0].clientX - touches[1].clientX, touches[0].clientY - touches[1].clientY);

            const handlePinchStart = (touches) => {
                initialPinchDistance = getPinchDistance(touches);
            };

            const handlePinchMove = (touches) => {
                if (initialPinchDistance === null) return;
                const newDist = getPinchDistance(touches);
                const delta = newDist / initialPinchDistance;

                const rect = wrapper.getBoundingClientRect();
                const midX = (touches[0].clientX + touches[1].clientX) / 2;
                const midY = (touches[0].clientY + touches[1].clientY) / 2;
                const xs = (midX - rect.left - panX) / scale;
                const ys = (midY - rect.top - panY) / scale;

                const newScale = Math.max(0.1, Math.min(10, scale * delta));
                panX += xs * scale - xs * newScale;
                panY += ys * scale - ys * newScale;
                scale = newScale;

                updateTransform();
                initialPinchDistance = newDist; // Update for continuous zoom
            };

            // Mouse Events
            wrapper.addEventListener('mousedown', e => {
                if (e.target.closest('.highlight-marker')) return;
                handlePanStart(e.clientX, e.clientY);
                e.preventDefault();
            });
            wrapper.addEventListener('mousemove', e => handlePanMove(e.clientX, e.clientY));
            wrapper.addEventListener('mouseup', handlePanEnd);
            wrapper.addEventListener('wheel', e => {
                e.preventDefault();
                const r = wrapper.getBoundingClientRect();
                const xs = (e.clientX - r.left - panX) / scale;
                const ys = (e.clientY - r.top - panY) / scale;
                const d = e.deltaY > 0 ? 0.9 : 1.1;
                const newScale = Math.max(0.1, Math.min(10, scale * d));
                panX += xs * scale - xs * newScale;
                panY += ys * scale - ys * newScale;
                scale = newScale;
                updateTransform();
            });

            // Touch Events
            wrapper.addEventListener('touchstart', e => {
                if (e.target.closest('.highlight-marker')) return;
                e.preventDefault();
                if (e.touches.length === 1) handlePanStart(e.touches[0].clientX, e.touches[0].clientY);
                else if (e.touches.length === 2) handlePinchStart(e.touches);
            }, {
                passive: false
            });

            wrapper.addEventListener('touchmove', e => {
                e.preventDefault();
                if (e.touches.length === 1) handlePanMove(e.touches[0].clientX, e.touches[0].clientY);
                else if (e.touches.length === 2) handlePinchMove(e.touches);
            }, {
                passive: false
            });

            wrapper.addEventListener('touchend', e => {
                if (e.touches.length < 2) initialPinchDistance = null;
                if (e.touches.length === 0) handlePanEnd();
            });
        }

        function addStatusCircles() {
            let layer = svgElement.querySelector('#status-highlight-layer');
            if (!layer) {
                layer = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                layer.id = 'status-highlight-layer';
                svgElement.appendChild(layer);
            }
            layer.innerHTML = '';

            const msgDiv = document.getElementById('filter-no-results-message');
            msgDiv.style.display = 'none';

            const frag = document.createDocumentFragment();
            ELEMENTS_DATA.forEach(element => {
                const x = parseFloat(element.x_coord),
                    y = parseFloat(element.y_coord);
                if (isNaN(x) || isNaN(y)) return;
                const status = (element.final_status || 'Pending').trim();
                if (HIGHLIGHT_STATUS === 'all' || status === HIGHLIGHT_STATUS) {
                    const marker = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    marker.setAttribute('cx', x);
                    marker.setAttribute('cy', y);
                    marker.setAttribute('r', '15');
                    marker.setAttribute('fill', STATUS_COLORS[status] || '#800080');
                    marker.setAttribute('fill-opacity', '0.75');
                    marker.setAttribute('class', 'highlight-marker');
                    marker.addEventListener('mouseenter', e => {
                        e.target.classList.add('marker-hover');
                        let content = `<strong>شناسه:</strong> ${element.element_id || 'N/A'}<br>` + `<strong>نوع:</strong> ${element.element_type || 'N/A'}<br>` + `<strong>وضعیت:</strong> ${status}<br>` + `<strong>طبقه:</strong> ${element.floor_level || 'N/A'}<br>`;
                        if (element.inspection_date_persian) content += `<strong>تاریخ بازرسی:</strong> ${element.inspection_date_persian}<br>`;
                        if (element.contractor_date_persian) content += `<strong>تاریخ پیمانکار:</strong> ${element.contractor_date_persian}<br>`;
                        if (element.notes) content += `<strong>یادداشت بازرسی:</strong> ${element.notes}<br>`;
                        if (element.contractor_notes) content += `<strong>یادداشت پیمانکار:</strong> ${element.contractor_notes}<br>`;
                        showTooltip(content);
                    });
                    marker.addEventListener('mouseleave', e => {
                        e.target.classList.remove('marker-hover');
                        hideTooltip();
                    });
                    frag.appendChild(marker);
                }
            });
            layer.appendChild(frag);
            if (layer.children.length === 0 && ELEMENTS_DATA.length > 0 && HIGHLIGHT_STATUS !== 'all') {
                msgDiv.textContent = `هیچ موردی با وضعیت "${HIGHLIGHT_STATUS}" یافت نشد.`;
                msgDiv.style.display = 'block';
            }
        }

        function setupToolbar() {
            const updateAndZoom = (newScale) => {
                scale = newScale;
                svgElement.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
            };
            document.getElementById('zoom-in-btn').onclick = () => updateAndZoom(Math.min(10, scale * 1.2));
            document.getElementById('zoom-out-btn').onclick = () => updateAndZoom(Math.max(0.1, scale / 1.2));
            document.getElementById('zoom-reset-btn').onclick = () => {
                panX = 0;
                panY = 0;
                updateAndZoom(1);
            };
            document.getElementById('print-btn').onclick = () => window.print();
            document.getElementById('download-btn').onclick = () => {
                try {
                    const originalTransform = svgElement.style.transform;
                    svgElement.style.transform = '';
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
                    svgElement.style.transform = originalTransform;
                } catch (error) {
                    alert('خطا در دانلود فایل: ' + error.message);
                }
            };
        }
    </script>
</body>

</html>