<?php
// ghom/tools/optimize_svg_files.php
// A script to be run once from the command line to permanently optimize all SVG files.
// Usage: Open your server's terminal, navigate to the public_html directory, and run:
// php ghom/tools/optimize_svg_files.php

set_time_limit(0);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);

// --- CONFIGURATION ---
$svgDirectory = __DIR__ . '/../assets/svg/';
$backupDirectory = __DIR__ . '/../assets/svg_backup/';

if (!is_dir($svgDirectory)) {
    die("ERROR: SVG directory not found at: $svgDirectory\n");
}
if (!is_dir($backupDirectory)) {
    if (!mkdir($backupDirectory, 0755, true)) {
        die("ERROR: Could not create backup directory at: $backupDirectory\n");
    }
}

class SVGOptimizer
{

    public function optimizeFile($inputPath, $outputPath, $backupPath = null)
    {
        if (!file_exists($inputPath)) {
            echo "File not found, skipping: $inputPath\n";
            return null;
        }

        // Backup original file if a backup path is provided
        if ($backupPath) {
            $backupFilePath = $backupPath . basename($inputPath);
            if (!file_exists($backupFilePath)) {
                copy($inputPath, $backupFilePath);
            }
        }

        $originalSize = filesize($inputPath);
        $svgContent = file_get_contents($inputPath);

        echo "Optimizing: " . basename($inputPath) . " (Original: " . $this->formatBytes($originalSize) . ")\n";

        // Apply optimizations
        $optimizedContent = $this->optimizeSVGContent($svgContent);

        // Save optimized file (overwriting the original)
        file_put_contents($outputPath, $optimizedContent);

        // We must clear the stat cache to get the new file size accurately
        clearstatcache();
        $newSize = filesize($outputPath);

        if ($originalSize > 0) {
            $savings = (($originalSize - $newSize) / $originalSize) * 100;
            echo "  -> Optimized: " . $this->formatBytes($newSize) . " (Saved: " . number_format($savings, 1) . "%)\n";
        } else {
            $savings = 0;
            echo "  -> Optimized: " . $this->formatBytes($newSize) . " (Original was empty)\n";
        }

        return [
            'original_size' => $originalSize,
            'new_size' => $newSize,
            'savings_percent' => $savings
        ];
    }

    private function optimizeSVGContent($svgContent)
    {
        // 1. Remove unnecessary metadata and comments
        $svgContent = preg_replace('/<!--[\s\S]*?-->/s', '', $svgContent);
        $svgContent = preg_replace('/<\?xml.*?\?>/s', '', $svgContent);
        $svgContent = preg_replace('/<!DOCTYPE.*?>/s', '', $svgContent);
        $svgContent = preg_replace('/<metadata.*?>.*?<\/metadata>/is', '', $svgContent);
        $svgContent = preg_replace('/<sodipodi:namedview.*?>.*?<\/sodipodi:namedview>/is', '', $svgContent);
        $svgContent = preg_replace('/<inkscape:.*?>.*?<\/inkscape:.*?>/is', '', $svgContent);

        // 2. Remove editor-specific attributes and namespaces
        $svgContent = preg_replace('/\s?(inkscape|sodipodi):[a-zA-Z0-9_-]+=".*?"/i', '', $svgContent);
        $svgContent = preg_replace('/\s?xmlns:(inkscape|sodipodi)=".*?"/i', '', $svgContent);

        // 3. Remove unnecessary whitespace
        $svgContent = preg_replace('/>\s+</', '><', $svgContent);
        $svgContent = trim($svgContent);

        // 4. Optimize decimal precision (keep 2 decimal places max)
        $svgContent = preg_replace_callback('/-?\d+\.\d{3,}/', function ($matches) {
            return number_format((float)$matches[0], 2, '.', '');
        }, $svgContent);

        // 5. Remove trailing zeros from decimals (e.g., 25.50 -> 25.5, 30.0 -> 30)
        $svgContent = preg_replace('/(\.\d*?)0+\b/', '$1', $svgContent);
        $svgContent = preg_replace('/\.0\b/', '', $svgContent);

        // 6. Optimize path data: remove space before commands and between numbers
        $svgContent = preg_replace('/ ([MLHVCSQTAZmlhvcsqtaz])/', '$1', $svgContent);
        $svgContent = preg_replace('/,/', ' ', $svgContent); // Commas are optional
        $svgContent = preg_replace('/\s+/', ' ', $svgContent); // Collapse multiple spaces
        $svgContent = preg_replace('/ -/', '-', $svgContent); // Remove space before negative numbers

        // 7. Convert RGB colors to shorter hex format
        $svgContent = preg_replace_callback('/rgb\((\d+),\s*(\d+),\s*(\d+)\)/', function ($matches) {
            return sprintf("#%02x%02x%02x", $matches[1], $matches[2], $matches[3]);
        }, $svgContent);

        // 8. Remove unnecessary group tags
        $svgContent = preg_replace('/<g>\s*<\/g>/', '', $svgContent);

        return $svgContent;
    }

    public function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

// --- SCRIPT EXECUTION ---
echo "Starting SVG Optimization Process...\n";
echo "Source Directory: $svgDirectory\n";
echo "Backup Directory: $backupDirectory\n\n";

$optimizer = new SVGOptimizer();
$totalOriginalSize = 0;
$totalNewSize = 0;
$fileCount = 0;

$files = new DirectoryIterator($svgDirectory);

foreach ($files as $fileinfo) {
    if ($fileinfo->isFile() && strtolower($fileinfo->getExtension()) === 'svg') {
        $filePath = $fileinfo->getPathname();
        $stats = $optimizer->optimizeFile($filePath, $filePath, $backupDirectory);

        if ($stats) {
            $totalOriginalSize += $stats['original_size'];
            $totalNewSize += $stats['new_size'];
            $fileCount++;
        }
        echo "----------------------------------------\n";
    }
}

echo "\n--- OPTIMIZATION COMPLETE ---\n";
echo "Files Processed: $fileCount\n";
echo "Total Original Size: " . $optimizer->formatBytes($totalOriginalSize) . "\n";
echo "Total Optimized Size: " . $optimizer->formatBytes($totalNewSize) . "\n";

if ($totalOriginalSize > 0) {
    $totalSavings = (($totalOriginalSize - $totalNewSize) / $totalOriginalSize) * 100;
    echo "Total Space Saved: " . $optimizer->formatBytes($totalOriginalSize - $totalNewSize) . " (" . number_format($totalSavings, 1) . "%)\n";
}
echo "Backups of original files are saved in: $backupDirectory\n";
