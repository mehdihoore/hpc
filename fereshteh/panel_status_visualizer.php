<?php
//panel_status_visualizer.php
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
    error_log("Project context mismatch: {$current_project_config_key}, Expected: {$expected_project_key} by {$_SESSION['user_id']}");
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}
$allowed_roles = ['admin', 'supervisor', 'planner', 'cnc_operator', 'superuser', 'user'];
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
    error_log("{$expected_project_key}/panel_status.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

$status_colors = [
    'pending' => '#cccccc',
    'shipped' => '#ff0000'
];
$status_map_persian = [
    'pending' => 'ارسال نشده',
    'shipped' => 'ارسال شده'
];

$stmtn = $pdo->prepare("SELECT address, packing_status FROM hpc_panels WHERE address LIKE 'N-%'");
$stmtn->execute();
$panelsn_raw = $stmtn->fetchAll(PDO::FETCH_ASSOC);
$shipped_countn = 0;
$panel_datan = [];
foreach ($panelsn_raw as $panel) {
    if (isset($panel['packing_status']) && strtolower($panel['packing_status']) === 'shipped') {
        $shipped_countn++;
    }
    $address = $panel['address'];
    $status = $panel['packing_status'] ?? 'pending';
    $panel_datan[$address] = [
        'packing_status' => $status,
        'persian_status' => $status_map_persian[$status] ?? $status,
        'color' => $status_colors[$status] ?? '#cccccc',
    ];
}

$stmts = $pdo->prepare("SELECT address, packing_status FROM hpc_panels WHERE address LIKE 'S-%'");
$stmts->execute();
$panelss_raw = $stmts->fetchAll(PDO::FETCH_ASSOC);
$shipped_counts = 0;
$panel_datas = [];
foreach ($panelss_raw as $panel) {
    if (isset($panel['packing_status']) && strtolower($panel['packing_status']) === 'shipped') {
        $shipped_counts++;
    }
    $address = $panel['address'];
    $status = $panel['packing_status'] ?? 'pending';
    $panel_datas[$address] = [
        'packing_status' => $status,
        'persian_status' => $status_map_persian[$status] ?? $status,
        'color' => $status_colors[$status] ?? '#cccccc',
    ];
}

$svg_file1 = file_get_contents('panel_status_maps.svg');
$svg_file2 = file_get_contents('panel_status_mapn.svg');

function generateSvgJavaScript($panel_data, $svgContainerId)
{
    // This function remains the same as your working version
    // It applies the panel data to the SVG
    $js = "
      document.addEventListener('DOMContentLoaded', function() {
        const svgContainerDiv = document.getElementById('{$svgContainerId}');
        if (!svgContainerDiv) {
            console.error('SVG container with ID {$svgContainerId} not found.');
            return;
        }
        const svgElement = svgContainerDiv.querySelector('svg');
        if (!svgElement) {
            console.error('SVG element not found within {$svgContainerId}.');
            return;
        }
        
        const panelData = " . json_encode($panel_data) . ";
        
        if (!svgElement.getAttribute('viewBox')) {
            const width = svgElement.getAttribute('width') || svgElement.getBoundingClientRect().width;
            const height = svgElement.getAttribute('height') || svgElement.getBoundingClientRect().height;
            if (width && height) {
                svgElement.setAttribute('viewBox', `0 0 \${width} \${height}`);
                svgElement.setAttribute('preserveAspectRatio', 'xMidYMid meet');
            } else {
                console.warn('Could not determine SVG dimensions to set viewBox for {$svgContainerId}.');
            }
        }
        
        const tooltip = document.createElement('div');
        tooltip.style.position = 'absolute';
        tooltip.style.padding = '10px';
        tooltip.style.background = 'rgba(0, 0, 0, 0.8)';
        tooltip.style.color = '#fff';
        tooltip.style.borderRadius = '5px';
        tooltip.style.pointerEvents = 'none';
        tooltip.style.zIndex = '1000';
        tooltip.style.display = 'none';
        document.body.appendChild(tooltip);
        
        const textElements = {};
        const allTextElements = svgElement.querySelectorAll('text');
        allTextElements.forEach(textEl => {
            const content = textEl.textContent.trim();
            textElements[content] = textEl;
        });
        
        for (const address in panelData) {
            const panelPoints = extractPanelPoints(address); 
            if (!panelPoints) continue;
            
            const firstPoint = panelPoints.first_point;
            const secondPoint = panelPoints.second_point;
            
            const firstElement = textElements[firstPoint];
            const secondElement = textElements[secondPoint];
            
            if (firstElement && secondElement) {
                const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                const firstX = parseFloat(firstElement.getAttribute('x'));
                const firstY = parseFloat(firstElement.getAttribute('y'));
                const secondX = parseFloat(secondElement.getAttribute('x'));
                const secondY = parseFloat(secondElement.getAttribute('y'));
                
                line.setAttribute('x1', firstX);
                line.setAttribute('y1', firstY);
                line.setAttribute('x2', secondX);
                line.setAttribute('y2', secondY);
                line.setAttribute('stroke', panelData[address].color);
                line.setAttribute('stroke-width', '5');
                line.setAttribute('data-address', address);
                line.setAttribute('data-status', panelData[address].persian_status);
                
                line.addEventListener('mouseover', function(e) {
                    tooltip.innerHTML = `آدرس: \${address} <br> وضعیت: \${panelData[address].persian_status}`;
                    tooltip.style.display = 'block';
                    tooltip.style.left = e.pageX + 10 + 'px';
                    tooltip.style.top = e.pageY + 10 + 'px';
                    this.setAttribute('stroke-width', '8');
                });
                
                line.addEventListener('mousemove', function(e) {
                    tooltip.style.left = e.pageX + 10 + 'px';
                    tooltip.style.top = e.pageY + 10 + 'px';
                });
                
                line.addEventListener('mouseout', function() {
                    tooltip.style.display = 'none';
                    this.setAttribute('stroke-width', '5');
                });
                
                svgElement.appendChild(line);
            }
        }
        
        allTextElements.forEach(textEl => {
            textEl.classList.add('panel-label');
            textEl.style.transformOrigin = 'center';
        });
    });";
    return $js;
}

function generateStatusLegend($status_colors, $status_map_persian, $shipped_count = 0, $region_label = '')
{
    // This function remains the same
    $legend = '<div class="status-legend" style="margin: 20px 0; padding: 15px; border: 1px solid #ccc; border-radius: 8px; background-color: #fcfcfc;">';
    $legend .= '<h3 style="margin: 0 0 10px 0; font-size: 1.1em;">راهنمای وضعیت پنل‌ها' . ($region_label ? ' - ' . htmlspecialchars($region_label) : '') . '</h3>';
    $legend .= '<ul style="list-style-type: none; padding: 0; margin: 0;">';
    foreach ($status_map_persian as $status => $persian) {
        $color = $status_colors[$status] ?? '#cccccc';
        $is_shipped = strtolower($status) === 'shipped';
        $legend .= '<li style="display: flex; align-items: center; justify-content: normal; margin-bottom: 8px;">';

        // Status label with color
        $legend .= '<div style="display: flex; align-items: center;">';
        $legend .= '<span style="display: inline-block; width: 18px; height: 18px; background-color: ' . $color . '; margin-left: 10px; border: 1px solid #000; border-radius: 3px;"></span>';
        $legend .= htmlspecialchars($persian);
        $legend .= '</div>';
        if ($is_shipped) {
            $legend .= '<span style="display: inline-block; min-width: 26px; height: 26px; background-color:rgb(230, 22, 22); color: white; text-align: center; line-height: 26px; border-radius: 50%; font-size: 0.85em; font-weight: bold;">' . $shipped_count . '</span>';
        }

        $legend .= '</li>';
    }

    $legend .= '</ul>';
    $legend .= '</div>';

    return $legend;
}

if ($_SESSION['current_project_config_key'] === 'fereshteh') {
    $pageTitle = 'صفحه نمای شمالی و جنوبی - پروژه فرشته';
} elseif ($_SESSION['current_project_config_key'] === 'arad') {
    $pageTitle = 'صفحه نمای شمالی و جنوبی - پروژه آراد';
} else {
    $pageTitle = 'صفحه نمای شمالی- پروژه نامشخص';
}

require_once 'header.php';
$js_coden = generateSvgJavaScript($panel_datan, 'panel-svg-container-north');
$js_codes = generateSvgJavaScript($panel_datas, 'panel-svg-container-south');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>وضعیت پنل‌ها</title>
    <!-- REMOVED jsPDF and svg2pdf.js script tags -->
    <style>
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
        }

        body {
            font-family: 'Vazir', sans-serif;
            direction: rtl;
            text-align: right;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .svg-container {
            border: 1px solid #ddd;
            overflow: auto;
            max-width: 100%;
            max-height: 80vh;
        }

        h1 {
            color: #333;
        }

        .panel-info {
            margin-top: 20px;
            padding: 10px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }

        svg {
            max-width: 100%;
            height: auto;
        }

        svg text {
            pointer-events: all;
            cursor: default;
            /* dominant-baseline: middle; */
            /* REMOVE or comment this out for now */
            /* position: relative !important; */
            /* REMOVE this, it's not standard for SVG text */

            /* If you want to ensure text elements from the SVG *without* an explicit font-family get Vazir: */
            /* font-family: 'Vazir', sans-serif; */
        }


        svg line {
            cursor: pointer;
            pointer-events: stroke;
            stroke-linecap: round;
        }

        .btn {
            display: inline-block;
            font-weight: 400;
            text-align: center;
            white-space: nowrap;
            vertical-align: middle;
            user-select: none;
            border: 1px solid transparent;
            padding: .375rem .75rem;
            font-size: 1rem;
            line-height: 1.5;
            border-radius: .25rem;
            transition: color .15s ease-in-out, background-color .15s ease-in-out, border-color .15s ease-in-out, box-shadow .15s ease-in-out;
            margin-left: 5px;
            /* Added some margin for all buttons */
        }

        .btn-primary {
            color: #fff;
            background-color: #007bff;
            border-color: #007bff;
        }

        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #0056b3;
        }

        .btn-info {
            color: #fff;
            background-color: #17a2b8;
            border-color: #17a2b8;
        }

        /* For Print button */
        .btn-info:hover {
            background-color: #117a8b;
            border-color: #117a8b;
        }
    </style>
</head>

<body>
    <!-- نمای شمالی -->
    <div class="container">
        <h1>نمایش وضعیت پنل ها در نمای شمالی</h1>
        <?php echo generateStatusLegend($status_colors, $status_map_persian, $shipped_countn, 'شمال'); ?>
        <div class="svg-container">
            <div id="panel-svg-container-north">
                <?php echo $svg_file2; ?>
            </div>
        </div>
        <div class="panel-info">
            <p>نمایش وضعیت <?php echo count($panel_datan); ?> پنل</p>
            <button id="download-svg-north" class="btn btn-primary">دانلود نقشه SVG</button>
            <button id="print-svg-north" class="btn btn-info">چاپ / ذخیره PDF نقشه</button> <!-- CHANGED -->
        </div>
    </div>

    <!-- نمای جنوبی -->
    <div class="container">
        <h1>نمایش وضعیت پنل ها در نمای جنوبی</h1>
        <?php echo generateStatusLegend($status_colors, $status_map_persian, $shipped_counts, 'جنوب'); ?>
        <div class="svg-container">
            <div id="panel-svg-container-south">
                <?php echo $svg_file1; ?>
            </div>
        </div>
        <div class="panel-info">
            <p>نمایش وضعیت <?php echo count($panel_datas); ?> پنل</p>
            <button id="download-svg-south" class="btn btn-primary">دانلود نقشه SVG</button>
            <button id="print-svg-south" class="btn btn-info">چاپ / ذخیره PDF نقشه</button> <!-- CHANGED -->
        </div>
    </div>

    <script>
        // Define extractPanelPoints globally once
        function extractPanelPoints(address) {
            const regex = /([NSEW])-([A-Z])(\d+)-([A-Z])(\d+)/;
            const matches = address.match(regex);
            if (matches) {
                return {
                    direction: matches[1],
                    first_point: matches[2] + matches[3],
                    second_point: matches[4] + matches[5]
                };
            }
            return null;
        }

        <?php echo $js_codes; // For South (applies panel data) 
        ?>
        <?php echo $js_coden; // For North (applies panel data) 
        ?>

        // --- SVG Download Functionality (remains the same) ---
        document.getElementById('download-svg-south').addEventListener('click', function() {
            const svgElement = document.getElementById('panel-svg-container-south').querySelector('svg');
            if (!svgElement) {
                console.error("SVG South not found");
                alert("خطا: SVG نمای جنوبی یافت نشد.");
                return;
            }
            const svgData = new XMLSerializer().serializeToString(svgElement);
            const svgBlob = new Blob([svgData], {
                type: 'image/svg+xml;charset=utf-8'
            });
            const svgUrl = URL.createObjectURL(svgBlob);
            const downloadLink = document.createElement('a');
            downloadLink.href = svgUrl;
            downloadLink.download = 'panel_status_map_south.svg';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
            URL.revokeObjectURL(svgUrl);
        });

        document.getElementById('download-svg-north').addEventListener('click', function() {
            const svgElement = document.getElementById('panel-svg-container-north').querySelector('svg');
            if (!svgElement) {
                console.error("SVG North not found");
                alert("خطا: SVG نمای شمالی یافت نشد.");
                return;
            }
            const svgData = new XMLSerializer().serializeToString(svgElement);
            const svgBlob = new Blob([svgData], {
                type: 'image/svg+xml;charset=utf-8'
            });
            const svgUrl = URL.createObjectURL(svgBlob);
            const downloadLink = document.createElement('a');
            downloadLink.href = svgUrl;
            downloadLink.download = 'panel_status_map_north.svg';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
            URL.revokeObjectURL(svgUrl);
        });

        // --- NEW Print/Save as PDF Functionality ---
        function openSvgForPrinting(svgContainerId, title) {
            const svgContainer = document.getElementById(svgContainerId);
            if (!svgContainer) {
                console.error(`SVG container #${svgContainerId} not found.`);
                alert(`خطا: محفظه SVG برای ${title} یافت نشد.`);
                return;
            }
            const svgElement = svgContainer.querySelector('svg');
            if (!svgElement) {
                console.error(`SVG element in container #${svgContainerId} not found for printing.`);
                alert(`خطا: عنصر SVG برای ${title} یافت نشد.`);
                return;
            }

            const svgData = new XMLSerializer().serializeToString(svgElement);

            // Ensure the Vazir font is available in the new window
            // Path to font must be absolute or correctly relative from the root
            const vazirFontUrl = '/assets/fonts/Vazir-Regular.woff2'; // ADJUST THIS PATH IF NEEDED

            const htmlContent = `
                <!DOCTYPE html>
                <html lang="fa" dir="rtl">
                <head>
                    <meta charset="UTF-8">
                    <title>${title || 'چاپ نقشه'}</title>
                    <style>
                        @font-face {
                            font-family: 'Vazir';
                            src: url('${vazirFontUrl}') format('woff2');
                        }
                        body {
                            margin: 20px; /* Add some margin for better printing */
                            text-align: center; /* Center the SVG */
                            font-family: 'Vazir', sans-serif; /* Apply font to body */
                        }
                          svg {
                            max-width: 100%;
                            max-height: 90vh;
                        }
                       
                       @media print {
                            body {
                                -webkit-print-color-adjust: exact;
                                print-color-adjust: exact;
                                margin: 10mm;
                            }
                            @page {
                                size: auto;
                                margin: 10mm;
                            }
                        }
                    </style>
                </head>
                <body>
                    ${svgData}

                </body>
                </html>
            `; // Note: Escaped 


            const printWindow = window.open('', '_blank');
            if (printWindow) {
                printWindow.document.open();
                printWindow.document.write(htmlContent);
                printWindow.document.close();
                printWindow.focus(); // Bring the new window to the front
            } else {
                alert('لطفاً پاپ‌آپ‌ها را برای این سایت فعال کنید تا بتوانید نقشه را برای چاپ باز کنید.');
            }
        }

        document.getElementById('print-svg-north').addEventListener('click', function() {
            openSvgForPrinting('panel-svg-container-north', 'چاپ نقشه نمای شمالی');
        });

        document.getElementById('print-svg-south').addEventListener('click', function() {
            openSvgForPrinting('panel-svg-container-south', 'چاپ نقشه نمای جنوبی');
        });
    </script>
    <?php
    include('footer.php');
    ?>
</body>

</html>