<?php
// download.php

// --- Configuration and Functions ---
// Adjust paths VERY carefully based on download.php's actual location relative to sercon/ and includes/
$config_path = __DIR__ . '/../../sercon/config.php'; // If sercon is one level above where download.php is
$functions_path = __DIR__ . '/includes/functions.php'; // If includes is in the same dir as download.php

// Check and include config
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    error_log("Download Error: Config file not found at: " . $config_path);
    http_response_code(500);
    die("Server configuration error."); // Keep output minimal on error
}

// Check and include functions
if (file_exists($functions_path)) {
    require_once $functions_path;
} else {
    error_log("Download Error: Functions file not found at: " . $functions_path);
    http_response_code(500);
    die("Server configuration error (functions).");
}



secureSession(); // Start/resume session securely

// --- Define Roles Allowed to DOWNLOAD Files ---
// Should match who can view the file list
$allowed_roles_for_viewing = ['admin', 'superuser', 'supervisor', 'planner', 'user']; // <<< Added all roles

    // --- Authorization Check ---
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles_for_viewing)) {
    http_response_code(401); // Unauthorized
    // Avoid echoing JSON here, just a simple message or nothing
    die("Unauthorized"); // Stop execution
    }

    // --- Get and Validate Parameters ---
    $packingListNumber=filter_input(INPUT_GET, 'packing_list_number' , FILTER_SANITIZE_STRING);
    $filename=filter_input(INPUT_GET, 'filename' , FILTER_SANITIZE_STRING); // Basic sanitization first

    if (empty($packingListNumber) || empty($filename)) {
    http_response_code(400); // Bad Request
    die("Invalid request parameters.");
    }

    // --- Security: Validate Packing List Number and Filename Format ---
    // Prevent directory traversal and invalid characters. Adjust patterns as needed.
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $packingListNumber)) {
    http_response_code(400);
    logError("Download attempt with invalid packing list number format: " . $packingListNumber);
     die(" Invalid packing list number format.");
    }
    // Stricter filename validation (allow dots, alphanumeric, underscore, hyphen)
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename) || strpos($filename, '..' ) !==false) {
    http_response_code(400);
    logError("Download attempt with invalid filename: " . $filename);
    die(" Invalid filename.");
    }

    // --- Construct and Validate File Path ---
    // Adjust base path carefully! This assumes 'uploads' is in the same directory as download.php
    $uploadDirBase=realpath(__DIR__ . '/uploads/packing_lists' );
    if (!$uploadDirBase) {
    logError("Download Error: Base upload directory does not exist or is inaccessible: " . __DIR__ . '/uploads/packing_lists');
    http_response_code(500);
    die(" Server error: Upload directory not found.");
    }

    $filePath=$uploadDirBase . '/' . $packingListNumber . '/' . $filename;

    // Security Check: Ensure the final path is still within the intended base directory
    if (strpos(realpath($filePath), $uploadDirBase) !==0) {
    http_response_code(400);
    logError("Download attempt path traversal detected: " . $filePath);
    die(" Invalid file path.");
    }


    // --- Check File Existence and Readability ---
    if (file_exists($filePath) && is_readable($filePath)) {

    // --- Prepare for Download - CRITICAL section for corruption ---

    // 1. Disable error reporting temporarily to prevent errors breaking the download stream
    error_reporting(0);
    @ini_set('display_errors', 0); // Use @ to suppress potential warning if ini_set is disabled

    // 2. Clean any potential output buffers *before* sending headers
    // Loop to clear all nested buffers if any exist
    while (ob_get_level()> 0) {
    ob_end_clean();
    }

    // 3. Set Headers
    header('Content-Description: File Transfer');
    // Use a specific Content-Type if possible, otherwise octet-stream
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);
    header('Content-Type: ' . ($mimeType ?: 'application/octet-stream')); // Fallback to octet-stream
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"'); // Use the original $filename
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));

    // 4. Read the file and output it
    // Use readfile() as it's memory efficient
    $bytesRead = readfile($filePath);

    // 5. Ensure script termination *immediately* after file read
    // Check if readfile() succeeded (returned bytes or false on failure)
    if ($bytesRead === false) {
    logError("Download Error: readfile() failed for path: " . $filePath);
    // Don't output anything here as headers might be partially sent
    }
    exit; // IMPORTANT: Stop script execution immediately

    } else {
    http_response_code(404); // Not Found
    logError("Download Error: File not found or not readable: " . $filePath);
    die("File not found.");
    }
    ?>