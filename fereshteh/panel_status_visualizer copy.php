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
    // Log attempted context mismatch
    error_log("Project context mismatch: {$current_project_config_key}, Expected: {$expected_project_key} by {$_SESSION['user_id']}");
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}
$allowed_roles = ['admin', 'supervisor', 'planner', 'cnc_operator', 'superuser', 'user']; // Add other roles as needed
if (!in_array($_SESSION['role'], $allowed_roles)) {
    logError("Unauthorized role '{$_SESSION['role']}' attempt on {$expected_project_key} map. User: {$_SESSION['user_id']}");
    header('Location: dashboard.php?msg=unauthorized');
    exit();
}
// --- End Authorization ---
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    error_log("{$expected_project_key}/panel_status.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// Define status colors and Persian translations
$status_colors = [
    'pending' => '#cccccc',       // Light gray for pending     // Orange for Mesh
    'Concreting' => '#ff9900',    // Green for Concreting
    'Assembly' => '#0066ff',      // Blue for Assembly
    'completed' => '#009900'     // Dark green for completed
    // Dark orange for Formwork
];

$status_map_persian = [
    'pending' => 'در انتظار',
    'Concreting' => 'قالب‌بندی/بتن ریزی',
    'Assembly' => 'فیس کوت',
    'completed' => 'تکمیل شده'

];


$stmtn = $pdo->prepare("SELECT address, status FROM hpc_panels WHERE address LIKE 'N-%'");
$stmtn->execute();
$panelsn = $stmtn->fetchAll(PDO::FETCH_ASSOC);

$panel_datan = [];
foreach ($panelsn as $panel) {
    $address = $panel['address'];
    $status = $panel['status'];
    $panel_datan[$address] = [
        'status' => $status,
        'persian_status' => $status_map_persian[$status] ?? $status,
        'color' => $status_colors[$status] ?? '#cccccc',
    ];
}

$stmts = $pdo->prepare("SELECT address, status FROM hpc_panels WHERE address LIKE 'S-%'");
$stmts->execute();
$panelss = $stmts->fetchAll(PDO::FETCH_ASSOC);

$panel_datas = [];
foreach ($panelss as $panel) {
    $address = $panel['address'];
    $status = $panel['status'];
    $panel_datas[$address] = [
        'status' => $status,
        'persian_status' => $status_map_persian[$status] ?? $status,
        'color' => $status_colors[$status] ?? '#cccccc',
    ];
}

// Load the SVG file
$svg_file1 = file_get_contents('panel_status_maps.svg');
$svg_file2 = file_get_contents('panel_status_mapn.svg');

// Function to generate JavaScript to modify SVG
function generateSvgJavaScript($panel_data, $svgContainerId)
{
    $js = "
      document.addEventListener('DOMContentLoaded', function() {
        const svgContainerDiv = document.getElementById('{$svgContainerId}'); // Use passed ID
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
            if (width && height) { // Ensure width and height are available
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
        
        // Find all text elements in SVG and create a map for quick lookup
          const textElements = {};
        const allTextElements = svgElement.querySelectorAll('text');
        allTextElements.forEach(textEl => {
            const content = textEl.textContent.trim();
            textElements[content] = textEl;
        });
        
        // Process each panel and add lines
        for (const address in panelData) {
            const panelPoints = extractPanelPoints(address);
            if (!panelPoints) continue;
            
            const firstPoint = panelPoints.first_point;
            const secondPoint = panelPoints.second_point;
            
            // Find corresponding elements in SVG using our map
            const firstElement = textElements[firstPoint];
            const secondElement = textElements[secondPoint];
            
            if (firstElement && secondElement) {
                // Create line as a separate SVG element that doesn't affect text positioning
                const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                
                // Get coordinates from the text elements - not modifying the text elements themselves
                const firstX = parseFloat(firstElement.getAttribute('x'));
                const firstY = parseFloat(firstElement.getAttribute('y'));
                const secondX = parseFloat(secondElement.getAttribute('x'));
                const secondY = parseFloat(secondElement.getAttribute('y'));
                
                // Set line attributes - connecting between text elements but not moving them
                line.setAttribute('x1', firstX);
                line.setAttribute('y1', firstY);
                line.setAttribute('x2', secondX);
                line.setAttribute('y2', secondY);
                line.setAttribute('stroke', panelData[address].color);
                line.setAttribute('stroke-width', '5');
                line.setAttribute('data-address', address);
                line.setAttribute('data-status', panelData[address].persian_status);
                
                // Add hover events
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
                
                // Add line to SVG - important: add to the end of the SVG to ensure it appears above other elements
                svgElement.appendChild(line); // Append to the correct svgElement
            }
        }
        
        // Make sure text elements remain at their original positions
        allTextElements.forEach(textEl => {
            // Ensure text stays at original position by creating a pointer to it in the parent
            const originalX = textEl.getAttribute('x');
            const originalY = textEl.getAttribute('y');
            
            // Add a class to make text easier to select and style
            textEl.classList.add('panel-label');
            
            // Ensure the text stays in place
            textEl.style.transformOrigin = 'center';
        });
    });
    
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
    }";

    return $js;
}

// Define a legend for status colors
function generateStatusLegend($status_colors, $status_map_persian)
{
    $legend = '<div class="status-legend" style="margin: 20px 0; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">';
    $legend .= '<h3 style="margin-top: 0;">راهنمای وضعیت پنل‌ها</h3>';
    $legend .= '<ul style="list-style-type: none; padding: 0;">';

    foreach ($status_map_persian as $status => $persian) {
        $color = $status_colors[$status] ?? '#cccccc';
        $legend .= sprintf(
            '<li style="display: flex; align-items: center; margin-bottom: 5px;"><span style="display: inline-block; width: 20px; height: 20px; background-color: %s; margin-left: 10px; border: 1px solid #000;"></span>%s</li>',
            $color,
            $persian
        );
    }

    $legend .= '</ul></div>';
    return $legend;
}
if ($_SESSION['current_project_config_key'] === 'fereshteh') {
    $pageTitle = 'صفحه نمای شمالی و جنوبی - پروژه فرشته'; // Force read-only for this project
} elseif ($_SESSION['current_project_config_key'] === 'arad') {
    $pageTitle = 'صفحه نمای شمالی و جنوبی - پروژه آراد'; // Force read-only for this project
} else {
    $pageTitle = 'صفحه نمای شمالی- پروژه نامشخص'; // Force read-only for this project
}
require_once 'header.php';
// Generate the final HTML
$js_coden = generateSvgJavaScript($panel_datan, 'panel-svg-container-north');
$js_codes = generateSvgJavaScript($panel_datas, 'panel-svg-container-south');
$legend_html = generateStatusLegend($status_colors, $status_map_persian);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>وضعیت پنل‌ها</title>
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

        /* Add additional styling for the SVG elements */
        svg {
            max-width: 100%;
            height: auto;
        }

        svg text {
            pointer-events: all;
            cursor: default;
            /* Ensure text isn't affected by other elements */
            dominant-baseline: middle;
            position: relative !important;
        }

        svg line {
            cursor: pointer;
            pointer-events: stroke;
            stroke-linecap: round;
        }
    </style>
</head>

<body>
    <!-- نمای شمالی -->
    <div class="container">
        <h1>نمایش وضعیت پنل ها در نمای شمالی</h1>
        <?php echo $legend_html; ?>
        <div class="svg-container">
            <div id="panel-svg-container-north"> <!-- UNIQUE ID -->
                <?php echo $svg_file2; ?>
            </div>
        </div>
        <div class="panel-info">
            <p>نمایش وضعیت <?php echo count($panel_datan); ?> پنل</p>
            <button id="download-svg-north" class="btn btn-primary">دانلود نقشه</button> <!-- UNIQUE ID -->
        </div>
    </div>

    <!-- نمای جنوبی -->
    <div class="container">
        <h1>نمایش وضعیت پنل ها در نمای جنوبی</h1>
        <?php echo $legend_html; ?>
        <div class="svg-container">
            <div id="panel-svg-container-south"> <!-- UNIQUE ID -->
                <?php echo $svg_file1; ?>
            </div>
        </div>
        <div class="panel-info">
            <p>نمایش وضعیت <?php echo count($panel_datas); ?> پنل</p>
            <button id="download-svg-south" class="btn btn-primary">دانلود نقشه</button> <!-- UNIQUE ID -->
        </div>
    </div>

    <script>
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

        <?php echo $js_codes; // For South 
        ?>
        <?php echo $js_coden; // For North 
        ?>

        // Add download functionality
        document.getElementById('download-svg-south').addEventListener('click', function() {
            const svgElement = document.getElementById('panel-svg-container-south').querySelector('svg');
            const svgData = new XMLSerializer().serializeToString(svgElement);
            const svgBlob = new Blob([svgData], {
                type: 'image/svg+xml;charset=utf-8'
            });
            const svgUrl = URL.createObjectURL(svgBlob);

            const downloadLink = document.createElement('a');
            downloadLink.href = svgUrl;
            downloadLink.download = 'panel_status_map_south.svg'; // Unique filename
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        });
        <?php echo $js_coden; ?>
        document.getElementById('download-svg-north').addEventListener('click', function() {
            const svgElement = document.getElementById('panel-svg-container-north').querySelector('svg');
            const svgData = new XMLSerializer().serializeToString(svgElement);
            const svgBlob = new Blob([svgData], {
                type: 'image/svg+xml;charset=utf-8'
            });
            const svgUrl = URL.createObjectURL(svgBlob);

            const downloadLink = document.createElement('a');
            downloadLink.href = svgUrl;
            downloadLink.download = 'panel_status_map_north.svg'; // Unique filename
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        });
    </script>
    <?php
    // Include footer
    include('footer.php');
    ?>
</body>

</html>