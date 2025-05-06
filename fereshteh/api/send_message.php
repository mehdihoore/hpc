<?php
// api/send_message.php
require_once __DIR__ . '/../../../sercon/config.php';  // Adjust path as needed
// require_once '../includes/functions.php'; // Likely redundant if config.php includes it. Keep if necessary.

// Start session and apply security settings
secureSession();

// Set content type to JSON BEFORE any output
header('Content-Type: application/json');

// --- Error Reporting for Debugging (REMOVE or set to 0 in Production) ---
error_reporting(E_ALL);
ini_set('display_errors', 1); // Helps see errors during development
// ----------------------------------------------------------------------

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401); // Unauthorized
    echo json_encode([
        'success' => false,
        'message' => 'User not authenticated'
    ]);
    exit;
}

// Get current user ID from session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized
    logError("Session user_id not set in send_message.php after secureSession");
    echo json_encode([
        'success' => false,
        'message' => 'Authentication session error.'
    ]);
    exit;
}
$currentUserId = $_SESSION['user_id'];
// Get receiver ID
$receiverId = filter_input(INPUT_POST, 'receiver_id', FILTER_VALIDATE_INT);
try {
    $pdo = connectDB(); // Connect to DB here
} catch (PDOException $e) {
    logError("Database connection error at start of send_message.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit;
}

// --- GUEST CHECK (Now $pdo is available) ---
if (isGuest()) {
    if (!$receiverId) { // Need receiver ID for the check
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing receiver ID for guest check.']);
        exit;
    }
    try { // Add try/catch around DB operations
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND can_chat_with_guests = 1");
        $stmt_check->execute([':id' => $receiverId]);
        if ($stmt_check->fetchColumn() == 0) {
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'Guests cannot send messages to this user.']);
            exit;
        }
    } catch (PDOException $e) {
        logError("Database error during guest check in send_message.php: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error checking permissions.']);
        exit;
    }
}
// --- END GUEST CHECK ---


// Validate receiver ID
if (!$receiverId) {
    http_response_code(400); // Bad Request
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or missing receiver ID'
    ]);
    exit;
}

// Get message content (use default filter, FILTER_SANITIZE_STRING is deprecated in PHP 8+)
$messageContent = trim(filter_input(INPUT_POST, 'message_content') ?? ''); // Default filter is usually fine
$caption = trim(filter_input(INPUT_POST, 'caption') ?? ''); // Get caption
// Create uploads directory if it doesn't exist
$baseDir = dirname(__DIR__); // This gets /home/alumglas/public_html
$uploadDir = $baseDir . '/uploads/messages/'; // Cleaner path
if (!file_exists($uploadDir)) {
    // Attempt to create directory recursively with more permissive permissions for debugging if needed
    if (!mkdir($uploadDir, 0775, true)) { // 0775 is often better than 0755
        logError("Failed to create upload directory: {$uploadDir}");
        http_response_code(500); // Internal Server Error
        echo json_encode([
            'success' => false,
            'message' => 'Server error: Cannot create upload directory.'
        ]);
        exit;
    }
}
if (!is_writable($uploadDir)) {
    logError("Upload directory is not writable: {$uploadDir}");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: Upload directory not writable.'
    ]);
    exit;
}


$uploadedFileDetails = []; // Store details of successfully uploaded files [{path: '...', name: '...'}, ...]

// --- DETAILED FILE UPLOAD HANDLING ---
if (!empty($_FILES['attachments'])) { // Check if the key 'attachments' exists
    logError("Attachments received: " . print_r($_FILES['attachments'], true)); // Log the whole structure

    // Check if the sub-arrays exist (important!)
    if (isset($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {

        foreach ($_FILES['attachments']['name'] as $index => $fileName) {
            // Check if other corresponding keys exist for this index
            if (!isset($_FILES['attachments']['error'][$index])) {
                logError("Missing error code for attachment index {$index}. Skipping.");
                continue;
            }

            $fileError = $_FILES['attachments']['error'][$index];
            $tmpName = $_FILES['attachments']['tmp_name'][$index] ?? 'N/A';
            $fileSize = $_FILES['attachments']['size'][$index] ?? 0;
            $fileType = $_FILES['attachments']['type'][$index] ?? 'N/A'; // Get actual type

            logError("Processing Attachment #{$index}: Name='{$fileName}', Temp='{$tmpName}', Size='{$fileSize}', Type='{$fileType}', ErrorCode='{$fileError}'");

            // **CRUCIAL: Check the error code FIRST**
            if ($fileError === UPLOAD_ERR_OK) {

                // Validate file size (20MB max)
                $maxFileSize = 20 * 1024 * 1024; // 20MB in bytes
                if ($fileSize === 0) {
                    // This check helps catch issues where the file arrives empty *before* the size limit check
                    logError("ERROR: File '{$fileName}' has size 0 bytes upon arrival (ErrorCode was OK). Upload potentially failed silently or tmp_dir issue.");
                    continue; // Skip this file
                }
                if ($fileSize > $maxFileSize) {
                    logError("ERROR: File '{$fileName}' ({$fileSize} bytes) exceeds max size ({$maxFileSize} bytes).");
                    // Optionally: Send specific error back? For now, just skip.
                    continue;
                }

                // **Check temporary file size BEFORE moving**
                if (!file_exists($tmpName) || !is_readable($tmpName)) {
                    logError("ERROR: Temporary file '{$tmpName}' for '{$fileName}' does not exist or is not readable before move. Upload likely failed due to limits or tmp_dir issues.");
                    continue; // Skip this file
                }
                $tempFileSize = @filesize($tmpName);
                logError("Temp file '{$tmpName}' size BEFORE move: {$tempFileSize} bytes.");

                if ($tempFileSize === false || $tempFileSize === 0) {
                    logError("ERROR: Temporary file '{$tmpName}' is empty or inaccessible before move despite ErrorCode being OK.");
                    continue; // Skip this file
                }

                // Create a unique filename
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (empty($fileExt) && $fileType === 'audio/mpeg') {
                    $fileExt = 'mp3'; // Handle voice recording name from JS blob type
                }
                // Basic sanitization for extension
                $fileExt = preg_replace('/[^a-z0-9]/', '', $fileExt) ?: 'dat';

                $uniqueFileName = uniqid('msg_') . '_' . time() . '.' . $fileExt;
                $targetFilePath = $uploadDir . $uniqueFileName; // Absolute path

                logError("Attempting to move '{$tmpName}' to '{$targetFilePath}'");

                if (move_uploaded_file($tmpName, $targetFilePath)) {
                    // **Check file size AFTER moving**
                    $finalSize = @filesize($targetFilePath);
                    logError("SUCCESS: File moved to '{$targetFilePath}'. Final Size='{$finalSize}' bytes.");

                    if ($finalSize > 0) {
                        // Store details of the successfully saved file
                        $uploadedFileDetails[] = [
                            'path' => $uniqueFileName, // Store relative path for DB
                            'name' => $fileName // Store original name for display
                        ];
                    } else {
                        logError("ERROR: File '{$targetFilePath}' has 0 bytes AFTER successful move! Check filesystem/disk space on destination.");
                        @unlink($targetFilePath); // Clean up the 0-byte file
                    }
                } else {
                    logError("ERROR: move_uploaded_file failed for '{$tmpName}' to '{$targetFilePath}'. Check destination directory permissions ({$uploadDir}).");
                }
            } else {
                // **Log the specific upload error**
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'Exceeded upload_max_filesize directive in php.ini.',
                    UPLOAD_ERR_FORM_SIZE  => 'Exceeded MAX_FILE_SIZE directive specified in the HTML form (if used).',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
                ];
                $errorMessage = $uploadErrors[$fileError] ?? 'Unknown upload error code: ' . $fileError;
                logError("ERROR: Upload failed for '{$fileName}'. Code: {$fileError} ({$errorMessage})");
            }
        } // End foreach loop
    } else {
        logError("WARNING: \$_FILES['attachments'] received, but 'name' key is missing or not an array. Structure: " . print_r($_FILES['attachments'], true));
    }
}
// --- END FILE HANDLING ---


// --- DATABASE INSERTION ---
// Decide how to handle multiple uploads: one message per file, or one message with first/last file?
// This example creates ONE message. It uses the message content, and attaches the *first* successfully uploaded file.
$finalFilePath = $uploadedFileDetails[0]['path'] ?? null;
$originalFileNameForResponse = $uploadedFileDetails[0]['name'] ?? null;

if (empty($finalFilePath)) {
    $caption = '';
}
// Make sure we have either message content or at least one successfully uploaded file
if (empty($messageContent) && empty($uploadedFileDetails)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Message content or a valid attachment is required.'
    ]);
    exit;
}

try {
    $pdo = connectDB();

    // Insert message into database
    $stmt = $pdo->prepare("
        INSERT INTO messages
        (sender_id, receiver_id, message_content, file_path, caption, timestamp, is_read)
        VALUES (:sender_id, :receiver_id, :message_content, :file_path,:caption,  NOW(), 0)
    ");

    $stmt->execute([
        ':sender_id' => $currentUserId,
        ':receiver_id' => $receiverId,
        ':message_content' => $messageContent,
        ':file_path' => $finalFilePath, // Use the path of the first successful upload, or null
        ':caption' => $caption // Bind caption
    ]);

    $messageId = $pdo->lastInsertId();

    // --- Fetch Sender Info & Build Response ---
    // Fetch the newly inserted message details along with sender info
    $stmt_msg = $pdo->prepare("
        SELECT
            m.*,
            u.first_name as sender_fname,
            u.last_name as sender_lname,
            u.avatar_path as sender_image
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.id = :message_id
    ");
    $stmt_msg->execute([':message_id' => $messageId]);
    $messageData = $stmt_msg->fetch(PDO::FETCH_ASSOC);

    if ($messageData) {
        // Add file details if a file was saved for this message
        if ($messageData['file_path']) {
            $messageData['file_name'] = $originalFileNameForResponse; // Use the original name we saved
            $fullPathCheck = $uploadDir . $messageData['file_path']; // Check absolute path
            $messageData['file_size'] = (file_exists($fullPathCheck)) ? @filesize($fullPathCheck) : 0;
        } else {
            $messageData['file_name'] = null;
            $messageData['file_size'] = 0;
        }
        // Format timestamp consistently (optional, JS can also do this)
        //$messageData['timestamp'] = date('Y-m-d H:i:s', strtotime($messageData['timestamp']));
        if (!empty($messageData['timestamp']) && function_exists('jdate')) {
            $unixTs = strtotime($messageData['timestamp']);
            if ($unixTs !== false) {
                $messageData['persian_timestamp'] = jdate('Y/m/d H:i', $unixTs);
            } else {
                $messageData['persian_timestamp'] = 'زمان نامعتبر';
            }
        } else {
            // Use raw timestamp or a placeholder if jdate not available/timestamp empty
            $messageData['persian_timestamp'] = date('Y-m-d H:i', strtotime($messageData['timestamp'] ?? 'now'));
        }
        echo json_encode([
            'success' => true,
            'message' => $messageData // Send back the detailed message object
        ]);
    } else {
        logError("Failed to fetch message details after insert for ID: {$messageId}");
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to retrieve message details after saving.']);
    }
} catch (PDOException $e) {
    logError("Database error in send_message.php: " . $e->getMessage() . " // SQLSTATE: " . $e->getCode());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while saving message.' // User-friendly message
    ]);
} catch (Throwable $e) { // Catch other potential errors
    logError("General error in send_message.php: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected server error occurred.'
    ]);
}
