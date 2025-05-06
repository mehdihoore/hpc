<?php
require_once __DIR__ . '/../../sercon/config_fereshteh.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// VERY IMPORTANT:  Strict authorization check.  Only admins can delete files.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden'); // Proper HTTP status code for unauthorized access
    exit('Unauthorized');
}

$attachmentId = $_POST['attachment_id'] ?? null;

if (!$attachmentId) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid request');
}
try {
        $pdo = connectDB();

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