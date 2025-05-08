<?php
require_once __DIR__ . '/../../sercon/bootstrap.php';
header('Content-Type: application/json');
secureSession();

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$messageId = filter_var($input['message_id'] ?? null, FILTER_VALIDATE_INT);

if (!$messageId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid message ID.']);
    exit;
}

try {
    $pdo = getCommonDBConnection();
    // Soft delete: UPDATE messages SET is_deleted = 1, message_content = NULL, file_path = NULL, caption = NULL WHERE id = :id
    // Or Hard delete: DELETE FROM messages WHERE id = :id
    $stmt = $pdo->prepare("UPDATE messages SET is_deleted = 1, message_content = NULL, file_path = NULL, caption = NULL WHERE id = :id"); // Soft delete example
    if ($stmt->execute([':id' => $messageId])) {
        echo json_encode(['success' => true, 'message' => 'Message deleted.']);
    } else {
        throw new PDOException("Execute failed.");
    }
} catch (PDOException $e) {
    logError("Admin Delete Message Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error deleting message.']);
}
