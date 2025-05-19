<?php
// api/get-packing-list-files.php

// --- Configuration and Functions ---
ini_set('display_errors', 1); // Enable error display for debugging
error_reporting(E_ALL);
ob_start(); // Start output buffering to catch stray output/errors
require __DIR__ . '/../../../sercon/bootstrap.php';
require_once  __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/jdf.php';

secureSession();
$expected_project_key = 'fereshteh'; // HARDCODED FOR THIS FILE
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
$report_key = 'get-packing-list-files'; // HARDCODED FOR THIS FILE
// DB Connection (Read-only needed)
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/api/get-packing-list-files.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

// --- Define Roles Allowed to VIEW Files ---
// These should match the roles allowed to access truck-assignment.php
$allowed_roles_for_viewing = ['admin', 'superuser', 'supervisor', 'planner', 'user']; // <<< Added all roles

// --- Authorization Check ---
// Check if user is logged in AND has an allowed role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles_for_viewing)) {
    http_response_code(401); // Correct HTTP status code for Unauthorized
    header('Content-Type: application/json'); // Ensure correct content type
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit; // Stop execution
}

// --- Proceed if Authorized ---

header('Content-Type: application/json'); // Set response type

$packingListNumber = filter_input(INPUT_GET, 'packing_list_number', FILTER_SANITIZE_STRING); // Safer way to get GET param

if (empty($packingListNumber)) { // Check if empty after sanitization
    http_response_code(400); // Bad Request status code
    echo json_encode(['success' => false, 'message' => 'Missing or invalid packing_list_number parameter.']);
    exit;
}

// --- Security: Validate Packing List Number Format ---
// Prevent directory traversal and invalid characters.
// Adjust the pattern if your PL numbers have other allowed characters.
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $packingListNumber)) {
    http_response_code(400);
    logError("Invalid packing list number format received: " . $packingListNumber); // Log the attempt
    echo json_encode(['success' => false, 'message' => 'Invalid packing list number format.']);
    exit;
}

// Construct the directory path relative to *this* script's location
$uploadDirBase = realpath(__DIR__ . '/../uploads/packing_lists'); // Get real path for base
if (!$uploadDirBase) {
    logError("Base upload directory does not exist or is inaccessible: " . __DIR__ . '/../uploads/packing_lists');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration error (upload path).']);
    exit;
}
$targetDir = $uploadDirBase . '/' . $packingListNumber . '/';


// --- Fetch Files ---
try {
    if (!is_dir($targetDir)) {
        // Directory doesn't exist, meaning no files uploaded for this PL# yet.
        // This is a successful state (found 0 files), not an error.
        echo json_encode(['success' => true, 'files' => []]);
        exit;
    }

    $files = [];
    // Using DirectoryIterator is generally cleaner and safer than scandir
    $iterator = new DirectoryIterator($targetDir);
    foreach ($iterator as $fileinfo) {
        // Skip dots ('.', '..') and ensure it's a file (not a subdirectory)
        if (!$fileinfo->isDot() && $fileinfo->isFile()) {
            $files[] = $fileinfo->getFilename();
        }
    }
    sort($files); // Optional: Sort files alphabetically

    echo json_encode(['success' => true, 'files' => $files]);
} catch (Exception $e) {
    // Catch potential errors during file system access (e.g., permissions)
    logError("Error accessing packing list directory '$targetDir': " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error retrieving file list.']);
    exit;
}
