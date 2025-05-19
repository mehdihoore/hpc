<?php
// api/upload-packing-list.php

// --- Configuration and Functions ---
ini_set('display_errors', 1); // Enable error display for debugging
error_reporting(E_ALL);
ob_start(); // Start output buffering to catch stray output/errors
require __DIR__ . '/../../../sercon/bootstrap.php';
require_once  __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/jdf.php';

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


if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

$current_user_id = $_SESSION['user_id']; // Get current user ID
$report_key = 'save-truck-schedule'; // HARDCODED FOR THIS FILE
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/api/save-truck-schedule.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// --- Define Roles Allowed to UPLOAD Files ---
$allowed_upload_roles = ['admin', 'superuser', 'receiver', 'supervisor']; // <<< ADD YOUR NEW ROLE(S) HERE

// --- Authorization Check ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_upload_roles)) {
    http_response_code(401); // Unauthorized
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not have permission to upload files.']);
    exit;
}

// --- Basic Request Validation ---
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST is allowed.']);
    exit();
}

// --- File Upload Validation ---
if (!isset($_FILES['packing_list'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'No file uploaded (expected "packing_list" field).']);
    exit();
}

$uploadedFile = $_FILES['packing_list'];

// Check for upload errors
if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400); // Bad Request (or 500 depending on error)
    // Provide a more user-friendly error message based on the code
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds maximum size allowed by server configuration.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds maximum size specified in the HTML form.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
    ];
    $errorMessage = $uploadErrors[$uploadedFile['error']] ?? 'Unknown upload error.';
    logError("File Upload Error: Code " . $uploadedFile['error'] . " - " . $errorMessage . " - Original Name: " . ($uploadedFile['name'] ?? 'N/A'));
    echo json_encode(['success' => false, 'message' => 'Upload failed: ' . $errorMessage]);
    exit();
}

// --- Optional: Add File Size Limit ---
$maxFileSize = 10 * 1024 * 1024; // 10 MB (adjust as needed)
if ($uploadedFile['size'] > $maxFileSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File is too large. Maximum size allowed is ' . ($maxFileSize / 1024 / 1024) . ' MB.']);
    exit();
}

// --- Optional: Add Allowed MIME Type Check ---
$allowedMimeTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    // Add other allowed types if necessary
];
$fileMimeType = mime_content_type($uploadedFile['tmp_name']); // More reliable than $_FILES['type']
if (!in_array($fileMimeType, $allowedMimeTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file type (' . $fileMimeType . '). Allowed types are PDF, JPG, PNG, GIF.']);
    exit();
}


// --- Packing List Number Validation ---
if (!isset($_POST['packing_list_number']) || empty($_POST['packing_list_number'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Packing list number is required.']);
    exit();
}

$packingListNumber = $_POST['packing_list_number'];

// --- Security: Validate Packing List Number Format ---
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $packingListNumber)) {
    http_response_code(400);
    logError("Upload attempt with invalid packing list number format: " . $packingListNumber);
    echo json_encode(['success' => false, 'message' => 'Invalid packing list number format.']);
    exit;
}

// --- Prepare Directory ---
// Construct path relative to this script
$uploadDirBase = realpath(__DIR__ . '/../uploads/packing_lists');
if (!$uploadDirBase) {
    logError("Upload Error: Base upload directory does not exist or is inaccessible: " . __DIR__ . '/../uploads/packing_lists');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration error (upload path).']);
    exit;
}
$uploadDir = $uploadDirBase . '/' . $packingListNumber . '/';

// Create directory if it doesn't exist
if (!is_dir($uploadDir)) {
    // Use @ to suppress warning if directory already exists due to race condition
    if (!@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) { // Check again after trying
        logError("Upload Error: Failed to create upload directory: " . $uploadDir);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create destination directory. Check permissions.']);
        exit();
    }
}


// --- Handle Filename and Duplicates ---

// 1. Get the original filename safely using basename()
$originalFilename = basename($uploadedFile["name"]);

// 2. Sanitize the base filename (remove potentially problematic characters)
// Allows letters (Unicode), numbers, spaces, dots, underscores, hyphens. Replaces others with _.
$safeBaseFilename = preg_replace('/[^\p{L}\p{N}\s._-]+/u', '_', $originalFilename);
// Optional: Trim whitespace from ends
$safeBaseFilename = trim($safeBaseFilename);
// Optional: Replace multiple spaces/underscores with a single one
$safeBaseFilename = preg_replace('/[\s_]+/', '_', $safeBaseFilename);

// 3. Separate filename into parts for duplicate checking
$pathInfo = pathinfo($safeBaseFilename);
$baseName = $pathInfo['filename']; // Filename without extension
$extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : ''; // Extension with dot, or empty string

// 4. Check for duplicates and generate unique name if necessary
$counter = 1;
$finalFilename = $safeBaseFilename; // Start with the sanitized name
$targetPath = $uploadDir . $finalFilename;

while (file_exists($targetPath)) {
    // File exists, create a new name like filename(1).ext, filename(2).ext
    $finalFilename = $baseName . '(' . $counter . ')' . $extension;
    $targetPath = $uploadDir . $finalFilename;
    $counter++;
}

// --- Move the Uploaded File ---
if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
    logError("File Upload Success: User " . ($_SESSION['user_id'] ?? 'N/A') . " uploaded '$finalFilename' for PL# '$packingListNumber'");
    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully.',
        'filename' => $finalFilename // Return the final potentially modified filename
    ]);
} else {
    // Log detailed error if possible
    $error = error_get_last();
    logError("Upload Error: Failed to move uploaded file '$originalFilename' (tmp: {$uploadedFile['tmp_name']}) to '$targetPath'. Error: " . ($error['message'] ?? 'Unknown OS error'));
    http_response_code(500); // Internal Server Error
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file. Check server permissions or disk space.']);
}
