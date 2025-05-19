<?php
// download.php

// --- Configuration and Functions ---
// Adjust paths VERY carefully based on download.php's actual location relative to sercon/ and includes/
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once __DIR__ . '/includes/jdf.php';
secureSession();
$expected_project_key = 'arad'; // HARDCODED FOR THIS FILE
$current_project_config_key = $_SESSION['current_project_config_key'] ?? null;

if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}
if ($current_project_config_key !== $expected_project_key) {
    logError("Concrete test manager accessed with incorrect project context. Session: {$current_project_config_key}, Expected: {$expected_project_key}, User: {$_SESSION['user_id']}");
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
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
// Improved parameter retrieval and sanitization
$packingListNumber = $_GET['packing_list_number'] ?? '';
$filename = $_GET['filename'] ?? '';

// Trim and strictly allow only safe characters (alphanumeric, underscore, hyphen, dot for filename)
$packingListNumber = trim($packingListNumber);
$filename = trim($filename);

// Optionally, log a warning if suspicious input is detected (before regex validation below)
if (preg_match('/[^a-zA-Z0-9_-]/', $packingListNumber)) {
    logError("Warning: Suspicious characters in packing_list_number: " . $packingListNumber);
}
if (preg_match('/[^a-zA-Z0-9._-]/', $filename)) {
    logError("Warning: Suspicious characters in filename: " . $filename);
}

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
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename) || strpos($filename, '..') !== false) {
    http_response_code(400);
    logError("Download attempt with invalid filename: " . $filename);
    die(" Invalid filename.");
}

// --- Construct and Validate File Path ---
// Adjust base path carefully! This assumes 'uploads' is in the same directory as download.php
$uploadDirBase = realpath(__DIR__ . '/uploads/packing_lists');
if (!$uploadDirBase) {
    logError("Download Error: Base upload directory does not exist or is inaccessible: " . __DIR__ . '/uploads/packing_lists');
    http_response_code(500);
    die(" Server error: Upload directory not found.");
}

$filePath = $uploadDirBase . '/' . $packingListNumber . '/' . $filename;

// Security Check: Ensure the final path is still within the intended base directory
if (strpos(realpath($filePath), $uploadDirBase) !== 0) {
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
    while (ob_get_level() > 0) {
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
