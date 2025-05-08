<?php
// api/mark_messages_read.php
require_once __DIR__ . '/../sercon/bootstrap.php';  // Adjust path as needed
require_once '../includes/functions.php';  // Adjust path as needed

// Start session if not already started
secureSession();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false, 
        'message' => 'User not authenticated'
    ]);
    exit;
}

// Get current user ID from session
$currentUserId = $_SESSION['user_id'];

// Get request body data
$data = json_decode(file_get_contents('php://input'), true);

// Check if sender_id exists in the request
if (empty($data['sender_id'])) {
    echo json_encode([
        'success' => false, 
        'message' => 'Sender ID is required'
    ]);
    exit;
}

$senderId = filter_var($data['sender_id'], FILTER_VALIDATE_INT);

if (!$senderId) {
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid sender ID'
    ]);
    exit;
}

try {
    $pdo = getCommonDBConnection();
    
    // Prepare and execute the update query
    $stmt = $pdo->prepare("
        UPDATE messages 
        SET is_read = 1 
        WHERE sender_id = :sender_id 
        AND receiver_id = :receiver_id 
        AND is_read = 0
    ");
    
    $stmt->execute([
        ':sender_id' => $senderId,
        ':receiver_id' => $currentUserId
    ]);
    
    $updatedRows = $stmt->rowCount();
    
    echo json_encode([
        'success' => true,
        'marked_read' => $updatedRows
    ]);
    
} catch (PDOException $e) {
    // Log error but don't expose details to client
    error_log("Database error in mark_messages_read.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
}
?>