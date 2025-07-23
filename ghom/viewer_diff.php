<?php
// public_html/ghom/viewer.php (FINAL - Case-Insensitive Matching)

require_once __DIR__ . '/../../sercon/bootstrap.php';
secureSession();

if (!isLoggedIn()) {
    http_response_code(403);
    die("Access Denied.");
}

$plan_file = $_GET['plan'] ?? null;
$highlight_type = $_GET['highlight'] ?? null;

if (!$plan_file || !preg_match('/^[\w\.-]+\.svg$/i', $plan_file)) {
    http_response_code(400);
    die("Error: Invalid plan file name.");
}

// --- CONFIGURATION REPLICATED FROM Ghom_app.js ---
// This is the "source of truth", matching your JS file.
$element_styles_config = [
    // SVG Group ID => Color
    'GFRC'          => '#ff9966',
    'GLASS'         => '#eef595da', // The yellowish color
    'Mullion'       => 'rgba(128, 128, 128, 0.9)',
    'Transom'       => 'rgba(169, 169, 169, 0.9)',
    'Bazshow'       => 'rgba(169, 169, 169, 0.9)',
    'Zirsazi'       => '#2464ee',
    'STONE'         => '#4c28a1',
    'Box_40x80x4'   => '#f08080',
    'Box_40x20'     => '#f08080',
    'tasme'         => '#f08080',
    'nabshi_tooli'  => '#f08080',
    // We only need the groups that should be colored. The rest can be ignored.
];

$inactive_fill = '#d3d3d3';   // Muted Gray for non-highlighted items

try {
    $style_block = "\n<style>\n";

    // Loop through our PHP configuration
    foreach ($element_styles_config as $group_id => $bright_color) {
        $color_to_use = $inactive_fill; // Default to inactive gray

        // --- THE CRITICAL FIX ---
        // Use case-insensitive comparison (strtolower) to match 'Glass' with 'GLASS'
        if ($highlight_type && strtolower($group_id) === strtolower($highlight_type)) {
            // If they match, use the bright color.
            $color_to_use = $bright_color;
        }

        // Generate the specific CSS rule for this group ID
        $selector = "#{$group_id} path, #{$group_id} rect, #{$group_id} polygon, #{$group_id} circle";
        $style_block .= "\t{$selector} { fill: {$color_to_use} !important; stroke: #333 !important; stroke-width: 0.5px !important; }\n";
    }
    $style_block .= "</style>\n";

    // --- SVG file reading and injection (this part is correct) ---
    $svg_path = realpath(__DIR__ . '/assets/svg/') . '/' . $plan_file;
    if (!file_exists($svg_path)) {
        http_response_code(404);
        die("Error: SVG file not found.");
    }

    $svg_content = file_get_contents($svg_path);
    // Inject the styles right after the opening <svg> tag
    $svg_content = preg_replace('/<svg[^>]*>/', '$0' . $style_block, $svg_content, 1);

    header('Content-Type: image/svg+xml');
    echo $svg_content;
} catch (Exception $e) {
    // Error handling
    http_response_code(500);
    logError("Viewer.php Error: " . $e->getMessage());
    $error_svg = '<svg width="600" height="100" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#f8d7da"/><text x="10" y="50">Error processing SVG file.</text></svg>';
    header('Content-Type: image/svg+xml');
    echo $error_svg;
}
