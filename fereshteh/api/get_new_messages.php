<?php
// api/get_new_messages.php

// IMPORTANT: Ensure error reporting is off for API endpoints in production
// error_reporting(0);
// ini_set('display_errors', 0);

// Set content type BEFORE any output
header('Content-Type: application/json');

// Only include the main config file, assuming it loads all dependencies
require_once __DIR__ . '/../../../sercon/config_fereshteh.php'; // Adjust path if needed

// secureSession() should start the session AND define/load isLoggedIn() via config_fereshteh.php
secureSession();

// Check if user is logged in
// This function MUST be available after secureSession() / including config_fereshteh.php
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}


// Get current user ID
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    logError("Session user_id not set in get_new_messages.php after secureSession");
    echo json_encode(['success' => false, 'message' => 'Authentication session error.']);
    exit;
}
$currentUserId = $_SESSION['user_id'];
// Get 'with' User ID
$withUserId = filter_input(INPUT_GET, 'with', FILTER_VALIDATE_INT);
// Get 'since' timestamp
$since = $_GET['since'] ?? '1970-01-01 00:00:00';
// --- ESTABLISH DB CONNECTION ONCE ---
try {
    $pdo = connectDB(); // Connect to DB here
} catch (PDOException $e) {
    logError("Database connection error at start of get_new_messages.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit;
}
// --- END DB CONNECTION ---

// --- GUEST CHECK ---
if (function_exists('isGuest') && isGuest()) { // Check function exists too
    if (!$withUserId) { // Need the 'with' ID for the check
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing user ID (\'with\') for guest check.']);
        exit;
    }
    try { // Add try/catch around DB operations
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND can_chat_with_guests = 1");
        $stmt_check->execute([':id' => $withUserId]);
        if ($stmt_check->fetchColumn() == 0) {
            http_response_code(403); // Forbidden
            // Guests *can* still poll, but they will get an empty message array if forbidden
            // So instead of exiting, we just send back an empty success response
            echo json_encode(['success' => true, 'messages' => []]);
            exit;
            // Original exit logic:
            // echo json_encode(['success' => false, 'message' => 'Guests cannot retrieve messages for this user.']);
            // exit;
        }
    } catch (PDOException $e) {
        logError("Database error during guest check in get_new_messages.php: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error checking permissions.']);
        exit;
    }
}
// --- END GUEST CHECK ---
// Validate 'with' User ID (if not already checked by guest logic)
if (!$withUserId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing user ID parameter (\'with\').']);
    exit;
}


try {

    // Fetch new messages - USE UNIQUE PLACEHOLDERS
    $stmt = $pdo->prepare("
        SELECT m.*, u_sender.first_name as sender_fname, u_sender.last_name as sender_lname,
               u_sender.avatar_path as sender_image
        FROM messages m
        JOIN users u_sender ON m.sender_id = u_sender.id
        WHERE ((m.sender_id = :current_user_1 AND m.receiver_id = :with_user_1)
           OR (m.sender_id = :with_user_2 AND m.receiver_id = :current_user_2))
           AND m.timestamp > :since
           AND m.is_deleted = 0
        ORDER BY m.timestamp ASC
    ");

    $params = [
        ':current_user_1' => $currentUserId,
        ':with_user_1'    => $withUserId,
        ':with_user_2'    => $withUserId,
        ':current_user_2' => $currentUserId,
        ':since'          => $since
    ];

    $stmt->execute($params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // Prepare the messages for JSON response
    foreach ($messages as &$message) {
        // Sanitize output and prepare data
        $message['sender_name'] = htmlspecialchars($message['sender_fname'] . ' ' . $message['sender_lname']);
        $message['sender_image'] = $message['sender_image'] ? htmlspecialchars($message['sender_image']) : 'assets/images/default-avatar.png'; // Provide default
        if (!empty($message['timestamp']) && function_exists('jdate')) {
            $unixTs = strtotime($message['timestamp']);
            if ($unixTs !== false) {
                $message['persian_timestamp'] = jdate('Y/m/d H:i', $unixTs);
            } else {
                $message['persian_timestamp'] = 'زمان نامعتبر';
            }
        } else {
            // Use raw timestamp or a placeholder if jdate not available/timestamp empty
            $message['persian_timestamp'] = date('Y-m-d H:i', strtotime($message['timestamp'] ?? 'now'));
        }
        // If there's a file path, prepare display info
        if (!empty($message['file_path'])) {
            $message['file_name'] = basename($message['file_path']); // Basename is generally safe
            $message['file_path'] = htmlspecialchars($message['file_path']); // Sanitize path for output
            $filePath = __DIR__ . '/../uploads/messages/' . $message['file_path']; // Use absolute path for file_exists

            if (file_exists($filePath)) {
                $message['file_size'] = filesize($filePath); // Get size if file exists
            } else {
                 $message['file_size'] = 0; // Or null, indicate missing
                 logError("File not found for message ID {$message['id']}: " . $filePath);
            }
        }
        

        unset($message['sender_fname'], $message['sender_lname']); // Keep unsetting if needed
    }

    unset($message); // Unset reference

    echo json_encode([
        'success' => true,
        'messages' => $messages
    ]);

} catch (PDOException $e) {
    // Log the detailed error on the server
    logError("Database error on get_new_messages.php (SQLSTATE[{$e->getCode()}]): " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'success' => false,
        'message' => 'An internal error occurred while fetching messages.' // User-friendly message
    ]);
} catch (Throwable $e) { // Catch other potential errors
    logError("General error on get_new_messages.php: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
     echo json_encode([
        'success' => false,
        'message' => 'An unexpected internal error occurred.'
    ]);
}

?>