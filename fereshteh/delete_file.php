<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once 'includes/jdf.php';
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
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$allroles = ['admin', 'supervisor', 'planner', 'cnc_operator', 'superuser', 'user'];
$authroles = ['admin', 'supervisor', 'superuser'];
$readonlyroles = ['planner', 'cnc_operator', 'user'];

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $allroles)) {
    header('Location: login.php');
    exit('Access Denied.');
}
$user_id = $_SESSION['user_id'];
$pdo = null; // Initialize
try {
    // Get PROJECT-SPECIFIC database connection
    $pdo = getProjectDBConnection(); // Uses session key ('fereshteh' or 'arad')
} catch (Exception $e) {
    logError("DB Connection failed in {$expected_project_key}/concrete_tests.php: " . $e->getMessage());
    die("خطا در اتصال به پایگاه داده پروژه.");
}

$attachmentId = $_POST['attachment_id'] ?? null;

if (!$attachmentId) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid request');
}
try {


        // Get file path
        $stmt = $pdo->prepare("SELECT file_path FROM hpc_panel_attachments WHERE id = ?");
        $stmt->execute([$attachmentId]);
        $filePath = $stmt->fetchColumn();

        // Delete from database
        $stmt = $pdo->prepare("DELETE FROM hpc_panel_attachments WHERE id = ?");
        $stmt->execute([$attachmentId]);
        // Delete file.  Check if file exists first.
        if ($filePath && file_exists($filePath)) {
            if (unlink($filePath)) { // Attempt to delete the file
                echo json_encode(['success' => true]);
            } else {
               header('HTTP/1.1 500 Internal Server Error');
               echo json_encode(['success' => false, 'message' => 'Failed to delete file.']);
            }
        }
         else {
           echo json_encode(['success' => true, 'message'=> 'File not exists, record deleted!']);//File not exists, but record deleted.
         }

    } catch (PDOException $e) {
        error_log($e->getMessage()); // Always log database errors
         header('HTTP/1.1 500 Internal Server Error'); // Use appropriate status code
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }