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
$newContent = trim(htmlspecialchars($input['new_content'] ?? '', ENT_QUOTES, 'UTF-8')); // Sanitizing input

if (!$messageId || $newContent === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    exit;
}

try {
    $pdo = getCommonDBConnection();
    $stmt = $pdo->prepare("UPDATE messages SET message_content = :content, edited_at = NOW() WHERE id = :id");
    if ($stmt->execute([':content' => $newContent, ':id' => $messageId])) {
        echo json_encode(['success' => true, 'message' => 'Message updated.', 'new_content' => $newContent]);
    } else {
        throw new PDOException("Execute failed.");
    }
} catch (PDOException $e) {
    logError("Admin Edit Message Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error updating message.']);
}
