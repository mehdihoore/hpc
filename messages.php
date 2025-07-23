<?php
// messages.php
require_once __DIR__ . '/../sercon/bootstrap.php'; // Adjust path

if (file_exists(__DIR__ . '/includes/jdf.php')) {
    require_once __DIR__ . '/includes/jdf.php';
}


secureSession(); // Initializes session, applies security, and checks login internally
// secureSession() from bootstrap.php should ideally handle the isLoggedIn check and redirect.
// If not, the manual check is a fallback.
if (!isLoggedIn()) { // isLoggedIn() should be in bootstrap.php
    header('Location: login.php'); // Common login page
    exit;
}
$pageTitle = 'پیام‌ها';

$currentUserId = $_SESSION['user_id'];
$selectedUserId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT); // User to chat with

try {
    $pdo = getCommonDBConnection(); // Connect if header didn't already

    // Get list of all users (except self) to potentially message
    try {
        if (isGuest()) {
            // Guests only see users flagged as 'can_chat_with_guests'
            $stmt_users = $pdo->prepare("SELECT id, first_name, last_name, avatar_path, last_activity
                                     FROM users
                                     WHERE can_chat_with_guests = 1
                                     ORDER BY first_name, last_name");
            $stmt_users->execute(); // No parameter needed here
        } else {
            // Non-guests see everyone except themselves
            $stmt_users = $pdo->prepare("SELECT id, first_name, last_name, avatar_path, last_activity
                                     FROM users
                                     WHERE id != :currentUserId
                                     ORDER BY first_name, last_name");
            $stmt_users->execute([':currentUserId' => $currentUserId]);
        }
        $allUsers = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logError("Error fetching users: " . $e->getMessage());
        $allUsers = [];
    }

    // Get unread message counts for notifications
    try {
        $stmt_unread = $pdo->prepare("
            SELECT sender_id, COUNT(*) as unread_count 
            FROM messages 
            WHERE receiver_id = ? AND is_read = 0 
            GROUP BY sender_id
        ");
        $stmt_unread->execute([$currentUserId]);
        $unreadCounts = [];
        while ($row = $stmt_unread->fetch(PDO::FETCH_ASSOC)) {
            $unreadCounts[$row['sender_id']] = $row['unread_count'];
        }
    } catch (PDOException $e) {
        logError("Error fetching unread message counts: " . $e->getMessage());
        $unreadCounts = [];
    }

    $conversation = [];
    $selectedUserName = 'کاربر انتخاب نشده';
    $selectedUserImage = null;
    if ($selectedUserId) {
        // Get selected user's name and profile image
        try {
            $stmt_name = $pdo->prepare("SELECT first_name, last_name, avatar_path FROM users WHERE id = ?");
            $stmt_name->execute([$selectedUserId]);
            $selectedUser = $stmt_name->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            logError("Error fetching selected user's details: " . $e->getMessage());
            $selectedUser = null;
        }
        $allowChat = false;
        if (isGuest()) {
            // Check if the selected user is allowed for guests
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND can_chat_with_guests = 1");
            $stmt_check->execute([':id' => $selectedUserId]);
            if ($stmt_check->fetchColumn() > 0) {
                $allowChat = true;
            }
        } else {
            // Non-guests can chat with anyone (except potentially other guests?)
            $allowChat = true; // Add more rules if needed
        }

        if (!$allowChat) {
            logError("Guest user ID {$currentUserId} tried to access chat with restricted user ID {$selectedUserId}");
            $selectedUserId = null; // Prevent loading this chat
            $selectedUser = null;
            logError("Guest Status: " . (isGuest() ? 'YES' : 'NO') . ", Selected User ID: {$selectedUserId}, AllowChat: " . ($allowChat ? 'YES' : 'NO'));
        }

        if ($selectedUserId && $selectedUser && $allowChat) {
            $selectedUserName = htmlspecialchars($selectedUser['first_name'] . ' ' . $selectedUser['last_name']);
            $selectedUserImage = $selectedUser['avatar_path'] ?: 'assets/images/default-avatar.jpg';

            // Fetch conversation
            try {
                $stmt_conv = $pdo->prepare("
                    SELECT m.*, u_sender.first_name as sender_fname, u_sender.last_name as sender_lname,
                           u_sender.avatar_path as sender_image
                    FROM messages m
                    JOIN users u_sender ON m.sender_id = u_sender.id
                    WHERE (m.sender_id = :current_user_1 AND m.receiver_id = :selected_user_1)
                       OR (m.sender_id = :selected_user_2 AND m.receiver_id = :current_user_2)
                    ORDER BY m.timestamp ASC
                ");
                // **** CORRECTED EXECUTE ****
                $stmt_conv->execute([
                    'current_user_1' => $currentUserId,
                    'selected_user_1' => $selectedUserId,
                    'selected_user_2' => $selectedUserId, // Bind the selected user again for the second placeholder
                    'current_user_2' => $currentUserId  // Bind the current user again for the second placeholder
                ]);
                $conversation = $stmt_conv->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // It's good practice to include the SQL state in the log for debugging
                logError("Error fetching conversation (SQLSTATE[{$e->getCode()}]): " . $e->getMessage());
                $conversation = [];
            }


            // Mark messages from selected user as read
            try {
                $stmt_mark_read = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
                $stmt_mark_read->execute([$selectedUserId, $currentUserId]);
            } catch (PDOException $e) {
                logError("Error marking messages as read: " . $e->getMessage());
            }

            // Remove from unread counts
            if (isset($unreadCounts[$selectedUserId])) {
                unset($unreadCounts[$selectedUserId]);
            }
        } else {
            // Make sure conversation is empty if chat not allowed or user invalid
            $conversation = [];
            if ($selectedUserId && !$allowChat) {
                // Optionally display a message in the chat area?
                // e.g., echo "<p class='text-center text-danger'>شما اجازه گفتگو با این کاربر را ندارید.</p>";
            }
            //$selectedUserId = null; // Invalid user selected
        }
    }
} catch (PDOException $e) {
    logError("Database connection error on messages.php: " . $e->getMessage());
    echo "<div class='alert alert-danger'>خطای پایگاه داده هنگام بارگیری پیام ها.</div>";
    $allUsers = [];
    $conversation = [];
    $unreadCounts = [];
}

// Function to get file extension icon
function getFileIcon($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    switch ($ext) {
        case 'pdf':
            return 'fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fa-file-word';
        case 'xls':
        case 'xlsx':
            return 'fa-file-excel';
        case 'ppt':
        case 'pptx':
            return 'fa-file-powerpoint';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
            return 'fa-file-image';
        case 'zip':
        case 'rar':
            return 'fa-file-archive';
        case 'txt':
            return 'fa-file-alt';
        case 'mp3':
        case 'wav':
        case 'ogg':
            return 'fa-file-audio';
        default:
            return 'fa-file';
    }
}

// Function to check if file is an audio file
function isAudioFile($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['mp3', 'wav', 'ogg']);
}

// Function to check if file is an image
function isImageFile($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif']);
}

// Maximum upload size in MB
$maxUploadSize = 1024;
require_once 'header_common.php'; // Includes DB connection, auth check, activity update

?>

<style>
    :root {
        --primary-color: #2563eb;
        --hover-color: #1d4ed8;
        --background-light: #f9fafb;
        --border-color: #e5e7eb;
        --text-primary: #333;
        --text-secondary: #6b7280;
        --message-sent: #dbeafe;
        --message-received: #e5e7eb;
        --message-sent-hover: #bfdbfe;
        --message-received-hover: #d1d5db;
        --online-indicator: #10b981;
    }

    .messages-container {
        max-width: 1100px;
        margin: 2rem auto;
        display: flex;
        gap: 1rem;
        min-height: 75vh;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        border-radius: 12px;
        overflow: hidden;
    }

    .user-list-panel {
        width: 300px;
        background: white;
        border-right: 1px solid var(--border-color);
        display: flex;
        flex-direction: column;
    }

    .user-list-search {
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
    }

    .user-list-search input {
        width: 100%;
        padding: 0.75rem;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        background-color: var(--background-light);
    }

    .user-list {
        flex-grow: 1;
        overflow-y: auto;
        padding: 0;
    }

    .user-list h4 {
        padding: 1rem;
        margin: 0;
        background: var(--background-light);
        border-bottom: 1px solid var(--border-color);
        font-size: 1rem;
    }

    .user-list ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .user-item {
        border-bottom: 1px solid var(--border-color);
    }

    .user-link {
        display: flex;
        align-items: center;
        padding: 0.75rem 1rem;
        text-decoration: none;
        color: var(--text-primary);
        transition: background-color 0.2s;
    }

    .user-link:hover {
        background: #f3f4f6;
    }

    .user-link.active {
        background: #e9f0fd;
        border-right: 3px solid var(--primary-color);
    }

    .user-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        margin-right: 12px;
        object-fit: cover;
        background: #e5e7eb;
    }

    .mesag_user-info {
        flex-grow: 1;
    }

    .user-name {
        font-weight: 600;
        margin-bottom: 2px;
    }

    .user-status {
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    .user-badge {
        background-color: var(--primary-color);
        color: white;
        border-radius: 50%;
        min-width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        padding: 0 4px;
    }

    .chat-panel {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        background: white;
    }

    .chat-header {
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
        font-weight: bold;
        background: white;
        display: flex;
        align-items: center;
    }

    .chat-header-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        margin-right: 12px;
        object-fit: cover;
    }

    .chat-header-info {
        flex-grow: 1;
    }

    .chat-header-name {
        font-weight: 600;
        font-size: 1.1rem;
    }

    .chat-header-status {
        font-size: 0.8rem;
        color: var(--text-secondary);
        font-weight: normal;
    }

    .chat-messages {
        flex-grow: 1;
        padding: 1.5rem;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        background-color: #f8fafc;
    }

    .message-wrapper {
        display: flex;
        flex-direction: column;
        max-width: 80%;
    }

    .message-wrapper.sent {
        align-self: flex-end;
        align-items: flex-end;
    }

    .message-wrapper.received {
        align-self: flex-start;
        align-items: flex-start;
    }

    .message-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        margin-bottom: 5px;
    }

    .message-bubble {
        padding: 0.75rem 1rem;
        border-radius: 18px;
        line-height: 1.5;
        word-wrap: break-word;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        transition: background-color 0.2s;
    }

    .message-bubble:hover {
        cursor: pointer;
    }

    .message-bubble.sent {
        background-color: var(--message-sent);
        border-bottom-right-radius: 5px;
    }

    .message-bubble.sent:hover {
        background-color: var(--message-sent-hover);
    }

    .message-bubble.received {
        background-color: var(--message-received);
        border-bottom-left-radius: 5px;
    }

    .message-bubble.received:hover {
        background-color: var(--message-received-hover);
    }

    .message-bubble {
        position: relative;
        /* Needed for absolute positioning of actions */
    }

    .message-bubble:hover .admin-actions {
        display: inline-block !important;
    }

    .btn-admin {
        background: none;
        border: none;
        cursor: pointer;
        padding: 2px 4px;
        font-size: 0.8em;
    }

    .btn-admin:hover {
        opacity: 0.7;
    }

    .message-meta {
        font-size: 0.75rem;
        color: var(--text-secondary);
        margin-top: 4px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .message-attachment {
        margin-top: 8px;
        max-width: 100%;
    }

    .attachment-preview {
        max-width: 200px;
        max-height: 200px;
        border-radius: 8px;
        object-fit: cover;
        cursor: pointer;
        border: 1px solid var(--border-color);
    }

    .file-attachment {
        display: flex;
        align-items: center;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 10px;
        gap: 10px;
        max-width: 100%;
    }

    .file-icon {
        font-size: 1.5rem;
        color: var(--primary-color);
    }

    .file-info {
        flex-grow: 1;
        overflow: hidden;
    }

    .file-name {
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .file-size {
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    .file-download {
        color: var(--primary-color);
        text-decoration: none;
        font-size: 1.2rem;
    }

    .audio-player {
        width: 300px;
        max-width: 100%;
        margin-top: 5px;
    }

    .audio-player audio {
        width: 100%;
        height: 40px;
        display: block;
    }

    .chat-input {
        padding: 1rem;
        background: white;
        border-top: 1px solid var(--border-color);
    }

    .chat-input-form {
        display: flex;
        flex-direction: column;
    }

    .input-controls {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }

    .textarea-wrapper {
        position: relative;
        flex-grow: 1;
    }

    .chat-input textarea {
        width: 100%;
        resize: none;
        padding: 0.75rem;
        border-radius: 20px;
        border: 1px solid var(--border-color);
        background: var(--background-light);
        font-family: inherit;
        max-height: 120px;
        overflow-y: auto;
    }

    .chat-input textarea:focus {
        outline: none;
        border-color: var(--primary-color);
    }

    .input-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .btn-icon,
    .btn-send {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: transparent;
        border: none;
        cursor: pointer;
        color: #555;
        transition: background-color 0.2s;
    }

    .btn-icon {
        color: var(--text-secondary);

    }

    .btn-icon:hover {
        background-color: rgb(6, 65, 117);
        color: var(--primary-color);
    }

    .btn-send {
        background: var(--primary-color);
        color: white;
        border: none;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.2s;
    }

    .btn-send:hover {
        background: var(--hover-color);
    }

    .btn-send:disabled {
        background: #a0aec0;
        cursor: not-allowed;
    }

    .attachment-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 8px;
    }

    .attachment-item {
        position: relative;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 5px 25px 5px 10px;
        background: var(--background-light);
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .attachment-remove {
        position: absolute;
        right: 5px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        cursor: pointer;
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        justify-content: center;
        width: 16px;
        height: 16px;
    }

    .attachment-remove:hover {
        color: #ef4444;
    }

    .selected-files-preview {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 8px;
    }

    #message-status {
        font-size: 0.8rem;
        margin-top: 5px;
    }

    /* Record audio button styling */
    .btn-record {
        position: relative;
    }

    .btn-record.recording {
        color: #ef4444;
        animation: pulse 1.5s infinite;
    }

    @keyframes pulse {
        0% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
        }

        70% {
            box-shadow: 0 0 0 10px rgba(239, 68, 68, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
        }
    }

    /* Recording timer */
    .recording-timer {
        position: absolute;
        bottom: -20px;
        left: 50%;
        transform: translateX(-50%);
        font-size: 0.75rem;
        color: #ef4444;
        white-space: nowrap;
    }

    /* Modal for image preview */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .modal-content {
        max-width: 90%;
        max-height: 90%;
    }

    .modal-close {
        position: absolute;
        top: 15px;
        right: 25px;
        color: white;
        font-size: 35px;
        font-weight: bold;
        cursor: pointer;
    }

    .content-wrapper .mobile-only {
        /* SCOPED */
        display: none;

    }

    /* Mobile responsiveness */
    @media (max-width: 768px) {

        /* Ensure main container behaves correctly */
        .content-wrapper .messages-container {
            /* SCOPED */
            height: 100vh;
            /* Full viewport height */
            max-height: 100vh;
            position: relative;
            /* For positioning the overlay/panel */
            overflow: hidden;
            /* Prevent main page scroll when panel is open */
            display: block;
            /* Override desktop flex */
        }


        /* Show the toggle/close buttons */
        .content-wrapper .mobile-only {
            /* SCOPED */
            display: flex;
            /* Show hamburger */
            margin-right: 10px;
        }

        /* Position and hide the panel */
        .content-wrapper .user-list-panel {
            /* SCOPED */
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 80%;
            max-width: 300px;
            height: 100vh;
            z-index: 101;
            background: white;
            border-right: 1px solid var(--border-color);
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
            transform: translateX(-105%);
            transition: transform 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            /* Reset potential conflicting properties */
            order: initial;
            flex-shrink: initial;
            flex-grow: initial;
            max-height: initial;
            /* Override previous setting */
        }

        /* Style for showing the panel */
        .content-wrapper .user-list-panel.show {
            /* SCOPED */
            transform: translateX(0);
        }

        /* Overlay styles */
        .content-wrapper .mobile-overlay {
            /* SCOPED */
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 100;
        }

        .content-wrapper .mobile-overlay.show {
            /* SCOPED */
            display: block;
        }

        /* Chat Panel - Ensure it takes full height and allows internal scroll */
        .content-wrapper .chat-panel {
            /* SCOPED */
            display: flex;
            /* Use flexbox to structure vertically */
            flex-direction: column;
            height: 100%;
            /* Fill the messages-container */
            width: 100%;
            background: white;
            /* Remove fixed order */
            order: initial;
        }

        /* Ensure chat messages can scroll within the chat panel */
        .content-wrapper .chat-messages {
            /* SCOPED */
            flex-grow: 1;
            /* Allow this to take all available space */
            overflow-y: auto;
            /* Enable vertical scrolling */
            padding: 1rem;
            /* Add padding around messages */
            background-color: #f8fafc;
            /* Message background */
        }

        /* Chat input stays at the bottom */
        .content-wrapper .chat-input {
            /* SCOPED */
            flex-shrink: 0;
            /* Don't shrink input area */
            padding: 0.75rem;
            background: white;
            border-top: 1px solid var(--border-color);
        }

        .content-wrapper .input-controls {
            /* SCOPED */
            flex-direction: column;
            /* Stack items vertically */
            align-items: stretch;
            /* Make children fill width */
            gap: 0.5rem;
            /* Maintain vertical gap */
        }

        .content-wrapper .textarea-wrapper {
            /* SCOPED */
            width: 100%;
            order: 1;
            /* Ensure textarea is first */
        }

        .content-wrapper .chat-input textarea {
            /* SCOPED */
            min-height: 45px;
            /* Minimum initial height */
            max-height: 120px;
            /* Limit expansion */
            border-radius: 18px;
            /* Match Telegram style more? */
            padding: 10px 15px;
            /* Adjust padding */
            box-sizing: border-box;
            /* Ensure padding is included in width/height */
            min-height: 45px;
            max-height: 120px;
        }

        .content-wrapper .input-actions {
            /* SCOPED */
            width: 100%;
            /* Take full width */
            order: 2;
            /* Ensure buttons are second */
            justify-content: space-between;
            /* Distribute buttons horizontally */
            padding-top: 0.25rem;
            /* Optional: small space above buttons */
        }

        .content-wrapper .btn-icon,
        .content-wrapper .btn-send {
            /* SCOPED */
            width: 44px;
            height: 44px;
            /* Maybe remove margin-right if it was only for desktop flex gap */
        }

        .content-wrapper .chat-header {
            /* SCOPED */
            flex-shrink: 0;
            /* Don't shrink header */
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            /* Adjust padding if needed */
            border-bottom: 1px solid var(--border-color);
        }

        /* Styles for the Close Button inside the panel */
        .content-wrapper .close-user-list-btn {
            /* SCOPED */
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(0, 0, 0, 0.1);
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2em;
            font-weight: bold;
            color: #666;
            cursor: pointer;
            z-index: 102;
        }

        .content-wrapper .close-user-list-btn:hover {
            /* SCOPED */
            background: rgba(0, 0, 0, 0.2);
        }

    }

    /* End of @media query */




    /* Loading spinner */
    .loading-spinner {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        border-top-color: white;
        animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* Emoji picker */
    .emoji-picker {
        position: absolute;
        bottom: 100%;
        left: 0;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        z-index: 100;
        display: none;
    }

    .emoji-picker.show {
        display: block;
    }

    .emoji-categories {
        display: flex;
        border-bottom: 1px solid var(--border-color);
    }

    .emoji-category {
        padding: 8px;
        cursor: pointer;
    }

    .emoji-category.active {
        border-bottom: 2px solid var(--primary-color);
    }

    .emoji-list {
        display: grid;
        grid-template-columns: repeat(8, 1fr);
        padding: 8px;
        max-height: 200px;
        overflow-y: auto;
    }

    .emoji-item {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        padding: 5px;
        cursor: pointer;
        border-radius: 4px;
    }

    .emoji-item:hover {
        background-color: var(--background-light);
    }

    .mobile-only {
        display: none;
        /* Hidden by default */
        margin-right: 10px;
        /* Add some spacing */
    }
</style>

<div class="content-wrapper">
    <div class="messages-container">
        <div class="mobile-overlay" id="mobile-overlay"></div>
        <!-- User List -->
        <div class="user-list-panel" id="user-list-panel">
            <button type="button" class="close-user-list-btn mobile-only" title="بستن لیست کاربران">×</button>
            <div class="user-list-search">
                <input type="text" id="user-search" placeholder="جستجوی کاربر..." />
            </div>
            <div class="user-list">
                <h4>کاربران</h4>
                <ul id="user-list">
                    <?php foreach ($allUsers as $user): ?>
                        <?php
                        $hasUnread = isset($unreadCounts[$user['id']]) && $unreadCounts[$user['id']] > 0;
                        $userImage = $user['avatar_path'] ?: 'assets/images/default-avatar.jpg';
                        // --- Online Status Logic ---
                        $isOnline = false;
                        $onlineThreshold = 300; // 5 minutes in seconds
                        if (!empty($user['last_activity'])) {
                            $lastActivityTimestamp = strtotime($user['last_activity']);
                            if ($lastActivityTimestamp !== false) { // Check if strtotime parsed successfully
                                $currentTime = time(); // Current Unix timestamp (UTC)
                                if (($currentTime - $lastActivityTimestamp) < $onlineThreshold) {
                                    $isOnline = true;
                                }
                            } else {
                                // Log error if timestamp format is invalid
                                logError("Invalid last_activity format for user ID {$user['id']}: " . $user['last_activity']);
                            }
                        }
                        // --- End Online Status Logic ---
                        ?>
                        <li class="user-item">
                            <a href="messages.php?user_id=<?= $user['id'] ?>"
                                class="user-link <?= ($selectedUserId == $user['id']) ? 'active' : '' ?>">
                                <img src="<?= htmlspecialchars($userImage) ?>" alt="" class="user-avatar">
                                <div class="user-info">
                                    <div class="user-name">
                                        <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
                                    <div class="user-status">
                                        <?php if ($hasUnread): ?>
                                            <span style="color: var(--primary-color); font-weight: bold;">پیام جدید</span>
                                        <?php elseif ($isOnline): ?>
                                            <span style="color: var(--online-indicator);">آنلاین</span>
                                        <?php else: ?>
                                            <span>آفلاین</span>
                                            <?php /* Show last seen*/
                                            $lastActivityTimestamp = strtotime($user['last_activity']);
                                            if ($lastActivityTimestamp) {
                                                // Requires jdf.php to be available here too
                                                echo ' - ' . jdate('Y/m/d H:i', $lastActivityTimestamp);
                                            }
                                            ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($hasUnread): ?>
                                    <div class="user-badge"><?= $unreadCounts[$user['id']] ?></div>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($allUsers)): ?>
                        <li class="user-item">
                            <div class="user-link">کاربری یافت نشد.</div>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Chat Area -->
        <div class="chat-panel">
            <div class="chat-header">
                <!-- 1. Put the toggle button HERE - always present -->
                <button type="button" id="toggle-user-list-btn" class="btn-icon mobile-only">
                    <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 0 24 24" width="24px" fill="#5f6368">
                        <path d="M0 0h24v24H0V0z" fill="none" />
                        <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" />
                    </svg>
                </button>
                <?php if ($selectedUserId && $selectedUser): ?>
                    <img src="<?= htmlspecialchars($selectedUserImage) ?>" alt="" class="chat-header-avatar">
                    <div class="chat-header-info">
                        <div class="chat-header-name"><?= $selectedUserName ?></div>
                        <div class="chat-header-status">آنلاین</div>
                    </div>
                <?php else: ?>
                    <div class="chat-header-info">
                        <span style="font-weight: normal; font-size: 0.9em;">لطفا یک کاربر را برای شروع گفتگو انتخاب کنید.</span>
                    </div>
                <?php endif; ?>
            </div><!-- End chat-header -->



            <div class="chat-messages" id="chat-messages-area">


                <?php if ($selectedUserId && $selectedUser): ?>

                    <?php // Check if the conversation is empty 
                    ?>
                    <?php if (empty($conversation)): ?>
                        <p class="text-muted text-center">هنوز پیامی رد و بدل نشده است.</p>
                    <?php else: // Conversation exists, loop through messages 
                    ?>
                        <?php foreach ($conversation as $msg): ?>
                            <?php // Setup variables needed for this message 
                            ?>
                            <?php
                            $isSent = $msg['sender_id'] == $currentUserId;
                            $senderImage = $msg['sender_image'] ?: 'assets/images/default-avatar.jpg'; // Consider using a default image consistent with other parts
                            // --- Convert Timestamp to Persian ---
                            $persianTimestamp = 'تاریخ نامعتبر'; // Default
                            if (!empty($msg['timestamp'])) {
                                $unixTimestamp = strtotime($msg['timestamp']);
                                if ($unixTimestamp !== false && function_exists('jdate')) {
                                    $persianTimestamp = jdate('Y/m/d H:i', $unixTimestamp);
                                } else {
                                    $persianTimestamp = date('Y-m-d H:i', $unixTimestamp ?: time());
                                    if (function_exists('logError')) {
                                        logError("jdate function not found or invalid timestamp for message ID {$msg['id']}: " . $msg['timestamp']);
                                    }
                                }
                            }
                            // --- End Persian Timestamp ---
                            ?>
                            <div class="message-wrapper <?= $isSent ? 'sent' : 'received' ?>" data-message-id="<?= $msg['id'] ?>">
                                <?php // Show avatar for received messages 
                                ?>
                                <?php if (!$isSent): ?>
                                    <img src="<?= htmlspecialchars($senderImage) ?>" alt="" class="message-avatar">
                                <?php endif; ?>

                                <div class="message-bubble <?= $isSent ? 'sent' : 'received' ?>">
                                    <!-- Admin Actions -->
                                    <?php if (function_exists('isAdmin') && isAdmin()): ?>
                                        <div class="admin-actions" style="position: absolute; top: -5px; <?= $isSent ? 'left: -5px;' : 'right: -5px;' ?> background: rgba(255,255,255,0.7); border-radius: 5px; padding: 2px; display: none; z-index: 10;">
                                            <button type="button" class="btn-admin edit-msg-btn" title="ویرایش">✏️</button>
                                            <button type="button" class="btn-admin delete-msg-btn" title="حذف">🗑️</button>
                                        </div>
                                    <?php endif; ?>
                                    <!-- End Admin Actions -->
                                    <?php // Container for text content (important for JS edit) 
                                    ?>
                                    <div class="message-content-display">
                                        <?php if (!empty($msg['message_content'])): ?>
                                            <?= nl2br(htmlspecialchars($msg['message_content'])) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php // ----- START Attachment Block ----- 
                                    ?>
                                    <?php if (!empty($msg['file_path'])): ?>
                                        <div class="message-attachment">
                                            <?php // ----- Correct Inner Conditions START ----- 
                                            ?>
                                            <?php if (isAudioFile($msg['file_path'])): ?>
                                                <div class="audio-player">
                                                    <audio controls>
                                                        <source src="uploads/messages/<?= htmlspecialchars($msg['file_path']) ?>" type="audio/mpeg">
                                                        مرورگر شما از پخش صدا پشتیبانی نمی‌کند.
                                                    </audio>
                                                </div>
                                            <?php elseif (isImageFile($msg['file_path'])): ?>
                                                <img src="uploads/messages/<?= htmlspecialchars($msg['file_path']) ?>" alt="تصویر پیام"
                                                    class="attachment-preview image-preview"
                                                    data-full-src="uploads/messages/<?= htmlspecialchars($msg['file_path']) ?>">
                                            <?php else: ?>
                                                <?php // This is the block for SVG, PDF, etc. 
                                                ?>
                                                <div class="file-attachment">
                                                    <?php // Decide if you want a generic icon or specific for SVG etc. 
                                                    ?>
                                                    <!-- Using Font Awesome example, replace if needed -->
                                                    <i class="fas <?= getFileIcon($msg['file_path']) ?> file-icon"></i>
                                                    <div class="file-info">
                                                        <div class="file-name"><?= htmlspecialchars(basename($msg['file_path'])) ?></div>
                                                        <div class="file-size">
                                                            <?php
                                                            $fileFullPath = __DIR__ . '/uploads/messages/' . $msg['file_path'];
                                                            $fileSize = file_exists($fileFullPath) ? @filesize($fileFullPath) : false;
                                                            echo ($fileSize !== false && function_exists('formatFileSize')) ? formatFileSize($fileSize) : ($fileSize !== false ? $fileSize . ' B' : 'نامشخص');
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <a href="/uploads/messages/<?= htmlspecialchars($msg['file_path']) ?>"
                                                        download class="file-download">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                                            <polyline points="7 10 12 15 17 10"></polyline>
                                                            <line x1="12" y1="15" x2="12" y2="3"></line>
                                                        </svg>
                                                    </a>
                                                </div>
                                            <?php endif; ?> <?php // ----- Correct Inner Conditions END ----- 
                                                            ?>
                                        </div>

                                        <?php // Display caption if it exists (Still inside the main file_path check) 
                                        ?>
                                        <?php if (!empty($msg['caption'])): ?>
                                            <div class="message-caption" style="margin-top: 5px; font-size: 0.9em; color: var(--text-secondary); padding-left: 5px; padding-right: 5px;">
                                                <?= nl2br(htmlspecialchars($msg['caption'])) ?>
                                            </div>
                                        <?php endif; ?>

                                    <?php endif; ?> <?php // ----- END Attachment Block (Closes the outer if file_path) ----- 
                                                    ?>

                                </div> <?php // End message-bubble 
                                        ?>

                                <div class="message-meta" dir="ltr" style="text-align: <?= $isSent ? 'right' : 'left' ?>;">
                                    <!-- Use the Persian timestamp -->
                                    <span dir="rtl"><?= $persianTimestamp ?></span>
                                    <?php if ($isSent): ?>
                                        <?= $msg['is_read'] ? ' <i class="fas fa-check-double" title="خوانده شده"></i>' : ' <i class="fas fa-check" title="ارسال شده"></i>' ?>
                                    <?php endif; ?>
                                    <?php if ($msg['edited_at']): ?>
                                        <span style="font-style: italic; font-size: 0.9em;" title="<?= jdate('Y/m/d H:i', strtotime($msg['edited_at'])) ?>">(ویرایش شده)</span>
                                    <?php endif; ?>
                                </div>
                            </div><?php // End message-wrapper 
                                    ?>
                        <?php endforeach; // End conversation loop 
                        ?>
                    <?php endif; // End else for conversation exists 
                    ?>
                    <?php // Handle case where a user ID was provided, but the user wasn't found 
                    ?>
                <?php elseif ($selectedUserId && !$selectedUser): ?>
                    <p class="text-muted text-center">کاربر انتخاب شده معتبر نیست.</p>
                <?php endif; // End outer check for selected user 
                ?>

                <?php // If no user is selected ($selectedUserId is null/false), this div will be empty, showing no messages 
                ?>

            </div> <?php // End chat-messages 
                    ?>

            <?php if ($selectedUserId): ?>
                <div class="chat-input">
                    <form id="message-form" class="chat-input-form" enctype="multipart/form-data">
                        <div class="selected-files-preview" id="selected-files-preview"></div>
                        <div id="caption-input-wrapper" style="margin-bottom: 8px; display: none;">
                            <input type="text" name="caption" id="caption-input" placeholder="افزودن توضیح برای فایل‌ها..." style="width: 100%; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 8px;">
                        </div>
                        <div class="input-controls">
                            <div class="textarea-wrapper">
                                <textarea name="message_content" id="message-input" placeholder="پیام خود را بنویسید..."
                                    rows="1"></textarea>
                                <div class="emoji-picker" id="emoji-picker">
                                    <!-- Emoji picker will be populated by JavaScript -->
                                </div>
                            </div>

                            <div class="input-actions">
                                <button type="button" id="emoji-btn" class="btn-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor" width="24" height="24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15.182 15.182a4.5 4.5 0 0 1-6.364 0M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75s.168-.75.375-.75S9.75 9.336 9.75 9.75Zm.375 0h.008v.015h-.008V9.75Zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75Zm.375 0h.008v.015h-.008V9.75Z" />
                                    </svg>
                                </button>

                                <button type="button" id="attach-btn" class="btn-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor" width="24" height="24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.122 2.122l7.81-7.81" />
                                    </svg>
                                </button>

                                <button type="button" id="record-btn" class="btn-icon btn-record">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor" width="24" height="24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                                    </svg>
                                    <span class="recording-timer" id="recording-timer" style="display: none;">00:00</span>
                                </button>

                                <input type="hidden" name="receiver_id" value="<?= $selectedUserId ?>">
                                <input type="file" name="attachments[]" id="attachment-input" multiple
                                    style="display: none;">
                                <button type="submit" id="send-btn" class="btn-send" disabled>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <line x1="22" y1="2" x2="11" y2="13"></line>
                                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div id="message-status"></div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Image Preview Modal -->
<div id="image-modal" class="modal" style="display: none;">
    <span class="modal-close">&times;</span>
    <img class="modal-content" id="modal-image">
</div>
<?php
// Add this PHP block *before* the <script> tag or early inside it
$lastMsg = !empty($conversation) ? end($conversation) : null;
// Check if $lastMsg is an array and has the timestamp key
$lastTimestampValue = ($lastMsg && is_array($lastMsg) && isset($lastMsg['timestamp'])) ? $lastMsg['timestamp'] : '1970-01-01 00:00:00';
?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const messageForm = document.getElementById('message-form');
        const messageInput = document.getElementById('message-input');
        const chatMessagesArea = document.getElementById('chat-messages-area');
        const messageStatus = document.getElementById('message-status');
        const sendBtn = document.getElementById('send-btn');
        const selectedUserId = <?= json_encode($selectedUserId) ?>;
        const currentUserId = <?= json_encode($currentUserId) ?>;
        const attachBtn = document.getElementById('attach-btn');
        const attachmentInput = document.getElementById('attachment-input');
        const selectedFilesPreview = document.getElementById('selected-files-preview');
        const userSearch = document.getElementById('user-search');
        const userList = document.getElementById('user-list');
        const recordBtn = document.getElementById('record-btn');
        const recordingTimer = document.getElementById('recording-timer');
        const emojiBtn = document.getElementById('emoji-btn');
        const emojiPicker = document.getElementById('emoji-picker');
        const imageModal = document.getElementById('image-modal');
        const modalImage = document.getElementById('modal-image');
        const modalClose = document.querySelector('.modal-close');
        const userListPanel = document.getElementById('user-list-panel');
        const userListToggleBtn = document.getElementById('toggle-user-list-btn');
        const mobileOverlay = document.getElementById('mobile-overlay'); // Get overlay
        const isAdmin = <?= json_encode(isAdmin()) ?>; // Pass admin status from PHP
        const captionInputWrapper = document.getElementById('caption-input-wrapper');
        const captionInput = document.getElementById('caption-input');
        const closeUserListBtn = document.querySelector('.close-user-list-btn'); // Make sure this line exists and is NOT commented out

        if (userListToggleBtn && userListPanel) {
            // console.log("Mobile toggle button found. Adding listener.");
            userListToggleBtn.addEventListener('click', function() {
                // console.log("Mobile toggle button CLICKED!");
                userListPanel.classList.toggle('show');
                if (mobileOverlay) {
                    mobileOverlay.classList.toggle('show');
                }
            });
        } else {
            // console.error("Mobile toggle button or panel not found!");
        }

        // --- Close on Overlay Click ---
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', function() {
                // console.log("Mobile overlay CLICKED!");
                userListPanel.classList.remove('show');
                mobileOverlay.classList.remove('show');
            });
        }

        // --- ADDED: Close on Explicit Close Button Click ---
        if (closeUserListBtn && userListPanel) {
            closeUserListBtn.addEventListener('click', function() {
                // console.log("Close button CLICKED!");
                userListPanel.classList.remove('show');
                if (mobileOverlay) {
                    mobileOverlay.classList.remove('show');
                }
            });
        }

        let mediaRecorder;
        let audioChunks = [];
        let isRecording = false;
        let recordingInterval;
        let recordingSeconds = 0;
        let selectedFiles = [];

        // Scroll to bottom on load if chat is active
        if (chatMessagesArea) {
            chatMessagesArea.scrollTop = chatMessagesArea.scrollHeight;
        }

        // Auto-resize textarea
        function autoResizeTextarea() {
            messageInput.style.height = 'auto';
            messageInput.style.height = (messageInput.scrollHeight <= 120) ? messageInput.scrollHeight + 'px' : '120px';
        }
        if (isAdmin && chatMessagesArea) {
            chatMessagesArea.addEventListener('click', function(e) {
                const editBtn = e.target.closest('.edit-msg-btn');
                const deleteBtn = e.target.closest('.delete-msg-btn');
                const messageWrapper = e.target.closest('.message-wrapper');
                if (!messageWrapper) return;
                const messageId = messageWrapper.dataset.messageId;

                // Handle Edit
                if (editBtn) {
                    handleEditMessage(messageId, messageWrapper);
                }

                // Handle Delete
                if (deleteBtn) {
                    handleDeleteMessage(messageId, messageWrapper);
                }
            });
        }

        function handleEditMessage(messageId, wrapper) {
            const contentDisplay = wrapper.querySelector('.message-content-display');
            if (!contentDisplay) return; // Can only edit text messages for now

            const currentContent = contentDisplay.textContent.trim(); // Simple text grab
            // More robust: fetch original content? Or store it? For simplicity, use displayed text.

            // Replace content with textarea and save button
            contentDisplay.innerHTML = `
        <textarea class="edit-textarea" style="width: 95%; min-height: 50px; border: 1px solid #ccc; margin-bottom: 5px;">${htmlspecialchars(currentContent)}</textarea>
        <button type="button" class="save-edit-btn" data-id="${messageId}">ذخیره</button>
        <button type="button" class="cancel-edit-btn">لغو</button>
    `;

            const saveBtn = contentDisplay.querySelector('.save-edit-btn');
            const cancelBtn = contentDisplay.querySelector('.cancel-edit-btn');
            const editArea = contentDisplay.querySelector('.edit-textarea');

            saveBtn.onclick = () => {
                const newContent = editArea.value.trim();
                if (newContent === '') return; // Or allow empty?

                fetch('api/edit_message.php', {
                        method: 'POST', // Or PUT
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            message_id: messageId,
                            new_content: newContent
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Restore display with new content
                            contentDisplay.innerHTML = nl2br(htmlspecialchars(data.new_content || newContent));
                            // Add edited marker (if not already present)
                            let meta = wrapper.querySelector('.message-meta');
                            if (meta && !meta.querySelector('.edited-marker')) {
                                const editedSpan = document.createElement('span');
                                editedSpan.className = 'edited-marker';
                                editedSpan.style.cssText = 'font-style: italic; font-size: 0.9em; margin-left: 5px;';
                                editedSpan.textContent = '(ویرایش شده)';
                                meta.appendChild(editedSpan); // Append may need adjustment
                            }
                        } else {
                            alert('خطا در ویرایش پیام: ' + data.message);
                            // Restore original content on error
                            contentDisplay.innerHTML = nl2br(htmlspecialchars(currentContent));
                        }
                    })
                    .catch(err => {
                        console.error("Edit fetch error:", err);
                        alert('خطای شبکه در هنگام ویرایش.');
                        contentDisplay.innerHTML = nl2br(htmlspecialchars(currentContent));
                    });
            };

            cancelBtn.onclick = () => {
                contentDisplay.innerHTML = nl2br(htmlspecialchars(currentContent));
            };
        }

        function handleDeleteMessage(messageId, wrapper) {
            if (confirm('آیا از حذف این پیام مطمئن هستید؟')) {
                fetch('api/delete_message.php', {
                        method: 'POST', // Or DELETE
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            message_id: messageId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            wrapper.style.transition = 'opacity 0.5s ease';
                            wrapper.style.opacity = '0';
                            setTimeout(() => wrapper.remove(), 500); // Remove after fade
                        } else {
                            alert('خطا در حذف پیام: ' + data.message);
                        }
                    })
                    .catch(err => {
                        console.error("Delete fetch error:", err);
                        alert('خطای شبکه در هنگام حذف.');
                    });
            }
        }

        // Format file size to readable format
        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            else if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            else return (bytes / 1048576).toFixed(1) + ' MB';
        }

        // Update send button state
        function updateSendButtonState() {
            if (sendBtn && messageInput) {
                const hasText = messageInput.value.trim() !== '';
                const hasFiles = selectedFiles.length > 0;
                sendBtn.disabled = !(hasText || hasFiles || isRecording);
            }
        }

        // Handle user search
        if (userSearch && userList) {
            userSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const userItems = userList.querySelectorAll('.user-item');

                userItems.forEach(item => {
                    const userName = item.querySelector('.user-name').textContent.toLowerCase();
                    if (userName.includes(searchTerm)) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        }

        // Handle file selection
        if (attachBtn && attachmentInput) {
            attachBtn.addEventListener('click', function() {
                attachmentInput.click();
            });

            attachmentInput.addEventListener('change', function() {
                handleFileSelection(this.files);
            });
        }

        // Handle files being dropped on the chat input
        if (messageInput) {
            messageInput.addEventListener('dragover', function(e) {
                e.preventDefault();
                messageInput.classList.add('drag-over');
            });

            messageInput.addEventListener('dragleave', function() {
                messageInput.classList.remove('drag-over');
            });

            messageInput.addEventListener('drop', function(e) {
                e.preventDefault();
                messageInput.classList.remove('drag-over');
                if (e.dataTransfer.files.length > 0) {
                    handleFileSelection(e.dataTransfer.files);
                }
            });

            messageInput.addEventListener('input', function() {
                autoResizeTextarea();
                updateSendButtonState();
            });

            // Initially resize the textarea
            autoResizeTextarea();
        }

        // Handle file selection preview
        function handleFileSelection(files) {
            if (!selectedFilesPreview || !files) return;

            const maxFiles = 10;
            const maxSize = 1024 * 1024 * 1024; // 20MB in bytes

            // Check if adding these files would exceed the limit
            if (selectedFiles.length + files.length > maxFiles) {
                if (messageStatus) {
                    messageStatus.textContent = `حداکثر ${maxFiles} فایل می‌توانید انتخاب کنید.`;
                    messageStatus.style.color = 'red';
                    setTimeout(() => {
                        messageStatus.textContent = '';
                    }, 3000);
                }
                return;
            }

            // Process each file
            for (let i = 0; i < files.length; i++) {
                const file = files[i];

                // Check file size
                if (file.size > maxSize) {
                    if (messageStatus) {
                        messageStatus.textContent = `فایل ${file.name} بزرگتر از حد مجاز (1GB) است.`;
                        messageStatus.style.color = 'red';
                        setTimeout(() => {
                            messageStatus.textContent = '';
                        }, 3000);
                    }
                    continue;
                }

                // Add to selected files
                selectedFiles.push(file);

                // Create preview element
                const previewItem = document.createElement('div');
                previewItem.className = 'attachment-item';

                // Choose icon based on file type
                let iconClass = 'fa-file';
                if (file.type.startsWith('image/')) iconClass = 'fa-file-image';
                else if (file.type.startsWith('audio/')) iconClass = 'fa-file-audio';
                else if (file.type.startsWith('video/')) iconClass = 'fa-file-video';
                else if (file.type.includes('pdf')) iconClass = 'fa-file-pdf';
                else if (file.type.includes('word') || file.type.includes('doc')) iconClass = 'fa-file-word';
                else if (file.type.includes('excel') || file.type.includes('sheet')) iconClass = 'fa-file-excel';

                // Create preview content
                previewItem.innerHTML = `
            <i class="fas ${iconClass}"></i>
            <span>${file.name}</span>
            <button type="button" class="attachment-remove" data-index="${selectedFiles.length - 1}">
                <i class="fas fa-times"></i>
            </button>
        `;

                // Add to preview container
                selectedFilesPreview.appendChild(previewItem);
            }
            if (selectedFiles.length > 0) {
                captionInputWrapper.style.display = 'block';
            } else {
                captionInputWrapper.style.display = 'none';
                captionInput.value = ''; // Clear caption if no files
            }
            // Show caption input if files are selected
            // Update send button state
            updateSendButtonState();

            // Reset the file input so the same file can be selected again if removed
            if (attachmentInput) {
                attachmentInput.value = '';
            }
        }

        // Handle removing selected files
        if (selectedFilesPreview) {
            selectedFilesPreview.addEventListener('click', function(e) {
                if (e.target.closest('.attachment-remove')) {
                    const btn = e.target.closest('.attachment-remove');
                    const index = parseInt(btn.dataset.index);

                    // Remove file from array
                    selectedFiles.splice(index, 1);

                    // Remove preview item
                    btn.closest('.attachment-item').remove();

                    // Update indices of remaining preview items
                    const remainingBtns = selectedFilesPreview.querySelectorAll('.attachment-remove');
                    remainingBtns.forEach((btn, i) => {
                        btn.dataset.index = i;
                    });
                    if (selectedFiles.length === 0) {
                        captionInputWrapper.style.display = 'none';
                        captionInput.value = '';
                    }
                    // Update send button state
                    updateSendButtonState();
                }
            });
        }

        // Handle audio recording
        if (recordBtn) {
            recordBtn.addEventListener('click', function() {
                if (isRecording) {
                    stopRecording();
                } else {
                    startRecording();
                }
            });
        }

        // Start audio recording
        function startRecording() {
            // Check if browser supports MediaRecorder
            if (!navigator.mediaDevices || !MediaRecorder) {
                if (messageStatus) {
                    messageStatus.textContent = 'مرورگر شما از ضبط صدا پشتیبانی نمی‌کند.';
                    messageStatus.style.color = 'red';
                    setTimeout(() => {
                        messageStatus.textContent = '';
                    }, 3000);
                }
                return;
            }

            navigator.mediaDevices.getUserMedia({
                    audio: true
                })
                .then(stream => {
                    mediaRecorder = new MediaRecorder(stream);
                    audioChunks = [];

                    mediaRecorder.ondataavailable = e => {
                        audioChunks.push(e.data);
                    };

                    mediaRecorder.onstop = () => {
                        const audioBlob = new Blob(audioChunks, {
                            type: 'audio/mpeg'
                        });
                        const audioFile = new File([audioBlob], `voice_message_${Date.now()}.mp3`, {
                            type: 'audio/mpeg'
                        });
                        handleFileSelection([audioFile]);

                        // Stop all tracks to release the microphone
                        stream.getTracks().forEach(track => track.stop());
                    };

                    // Start recording
                    mediaRecorder.start();
                    isRecording = true;
                    if (recordBtn) {
                        recordBtn.classList.add('recording');
                    }

                    // Start timer
                    recordingSeconds = 0;
                    if (recordingTimer) {
                        recordingTimer.textContent = '00:00';
                        recordingTimer.style.display = 'block';
                    }

                    recordingInterval = setInterval(() => {
                        recordingSeconds++;
                        const minutes = Math.floor(recordingSeconds / 60).toString().padStart(2, '0');
                        const seconds = (recordingSeconds % 60).toString().padStart(2, '0');
                        if (recordingTimer) {
                            recordingTimer.textContent = `${minutes}:${seconds}`;
                        }

                        // Stop recording after 5 minutes
                        if (recordingSeconds >= 300) {
                            stopRecording();
                        }
                    }, 1000);

                    updateSendButtonState();
                })
                .catch(error => {
                    console.error('Error accessing microphone:', error);
                    // Display user-friendly message
                    if (messageStatus) {
                        let userMessage = 'خطا در دسترسی به میکروفون.';
                        if (error.name === 'NotFoundError') {
                            userMessage = 'میکروفونی یافت نشد. لطفا از اتصال صحیح آن و فعال بودن در سیستم اطمینان حاصل کنید.';
                        } else if (error.name === 'NotAllowedError') {
                            userMessage = 'دسترسی به میکروفون توسط شما یا مرورگر رد شده است. لطفا تنظیمات مرورگر را بررسی کنید.';
                        }
                        messageStatus.textContent = userMessage;
                        messageStatus.style.color = 'red';
                        setTimeout(() => {
                            messageStatus.textContent = '';
                        }, 5000); // Show message longer
                    }
                    // Ensure recording state is reset if it failed to start
                    isRecording = false;
                    if (recordBtn) recordBtn.classList.remove('recording');
                    clearInterval(recordingInterval);
                    if (recordingTimer) recordingTimer.style.display = 'none';
                    updateSendButtonState();
                });
        }

        // Stop audio recording
        function stopRecording() {
            if (mediaRecorder && isRecording) {
                mediaRecorder.stop();
                isRecording = false;
                if (recordBtn) {
                    recordBtn.classList.remove('recording');
                }
                clearInterval(recordingInterval);
                if (recordingTimer) {
                    recordingTimer.style.display = 'none';
                }
                updateSendButtonState();
            }
        }


        // Handle emoji picker
        if (emojiBtn && emojiPicker) {
            // Initialize emoji picker
            function initEmojiPicker() {
                const emojis = [{
                        category: 'خنده',
                        items: ['😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '🥲', '☺️', '😊', '😇', '🙂', '🙃', '😉', '😌', '😍', '🥰', '😘']
                    },
                    {
                        category: 'علامت',
                        items: ['👍', '👎', '👌', '🤌', '👏', '🙌', '🤝', '🤲', '🙏', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👆', '👇', '☝️']
                    },
                    {
                        category: 'حیوان',
                        items: ['🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐻‍❄️', '🐨', '🐯', '🦁', '🐮', '🐷', '🐸', '🐵', '🐔', '🐧', '🐦']
                    },
                    {
                        category: 'غذا',
                        items: ['🍎', '🍐', '🍊', '🍋', '🍌', '🍉', '🍇', '🍓', '🫐', '🍈', '🍒', '🍑', '🥭', '🍍', '🥥', '🥝', '🍅', '🍆', '🥑']
                    },
                    {
                        category: 'آبجکت',
                        items: ['⌚️', '📱', '💻', '⌨️', '🖥', '🖨', '🖱', '🖲', '📲', '📞', '☎️', '📟', '📠', '📺', '📻', '🧭', '⏱', '⏲', '⏰']
                    }
                ];

                // Create emoji picker HTML
                let emojiHTML = '<div class="emoji-categories">';
                emojis.forEach((category, i) => {
                    emojiHTML += `<div class="emoji-category ${i === 0 ? 'active' : ''}" data-category="${i}">${category.category}</div>`;
                });
                emojiHTML += '</div><div class="emoji-list">';

                // Add first category emojis
                emojis[0].items.forEach(emoji => {
                    emojiHTML += `<div class="emoji-item">${emoji}</div>`;
                });
                emojiHTML += '</div>';

                emojiPicker.innerHTML = emojiHTML;

                // Handle category selection
                const categories = emojiPicker.querySelectorAll('.emoji-category');
                categories.forEach(category => {
                    category.addEventListener('click', function() {
                        const categoryIndex = this.dataset.category;
                        const emojiList = emojiPicker.querySelector('.emoji-list');

                        // Update active category
                        categories.forEach(cat => cat.classList.remove('active'));
                        this.classList.add('active');

                        // Update emoji list
                        let listHTML = '';
                        emojis[categoryIndex].items.forEach(emoji => {
                            listHTML += `<div class="emoji-item">${emoji}</div>`;
                        });
                        emojiList.innerHTML = listHTML;
                    });
                });

                // Handle emoji selection
                emojiPicker.addEventListener('click', function(e) {
                    if (e.target.classList.contains('emoji-item') && messageInput) {
                        const emoji = e.target.textContent;
                        // Insert emoji at cursor position
                        const cursorPos = messageInput.selectionStart;
                        const textBefore = messageInput.value.substring(0, cursorPos);
                        const textAfter = messageInput.value.substring(cursorPos);
                        messageInput.value = textBefore + emoji + textAfter;
                        messageInput.focus();
                        messageInput.selectionStart = cursorPos + emoji.length;
                        messageInput.selectionEnd = cursorPos + emoji.length;

                        // Update textarea height and send button state
                        autoResizeTextarea();
                        updateSendButtonState();
                    }
                });
            }

            // Initialize emoji picker
            initEmojiPicker();

            // Toggle emoji picker
            emojiBtn.addEventListener('click', function() {
                emojiPicker.classList.toggle('show');
            });

            // Close emoji picker when clicking outside
            document.addEventListener('click', function(e) {
                if (emojiBtn && emojiPicker && !emojiBtn.contains(e.target) && !emojiPicker.contains(e.target)) {
                    emojiPicker.classList.remove('show');
                }
            });
        }


        // Handle sending messages
        if (messageForm && selectedUserId) {
            messageForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const messageContent = messageInput?.value.trim() || '';
                const captionValue = captionInput.value.trim(); // Get caption
                // Check if there's content to send (text or files)
                if (!messageContent && selectedFiles.length === 0) {
                    return;
                }

                // Show sending status
                if (messageStatus) {
                    messageStatus.textContent = 'در حال ارسال...';
                    messageStatus.style.color = 'orange';
                }

                if (sendBtn) {
                    sendBtn.disabled = true;
                    sendBtn.innerHTML = '<div class="loading-spinner"></div>';
                }

                // Prepare form data
                const formData = new FormData();
                formData.append('receiver_id', selectedUserId);
                formData.append('message_content', messageContent);
                // Add caption if files are attached and caption has value
                if (selectedFiles.length > 0 && captionValue) {
                    formData.append('caption', captionValue);
                }
                // Add selected files
                selectedFiles.forEach(file => {
                    // ** Add this line back! **
                    formData.append('attachments[]', file); // Use 'attachments[]' as the name for PHP

                    // Optional: Keep for debugging audio size
                    if (file.type === 'audio/mpeg') {
                        console.log("Audio blob size being sent:", file.size, "bytes");
                    }
                });


                // Send the message
                fetch('api/send_message.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Clear input and reset UI
                            messageInput.value = '';
                            autoResizeTextarea();
                            selectedFiles = [];
                            selectedFilesPreview.innerHTML = '';
                            messageStatus.textContent = 'ارسال شد.';
                            messageStatus.style.color = 'green';
                            sendBtn.innerHTML = ` 
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                `;
                            sendBtn.disabled = true;
                            captionInput.value = ''; // Clear caption input
                            captionInputWrapper.style.display = 'none'; // Hide caption input
                            // Add message to chat


                            // Clear status after 3 seconds
                            setTimeout(() => {
                                messageStatus.textContent = '';
                            }, 3000);
                        } else {
                            messageStatus.textContent = 'خطا: ' + (data.message || 'ارسال ناموفق بود.');
                            messageStatus.style.color = 'red';
                            sendBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                `;
                            updateSendButtonState(); // Re-enable based on content
                        }
                    })
                    .catch(error => {
                        console.error('Send Message Error:', error);
                        messageStatus.textContent = 'خطا در ارتباط با سرور.';
                        messageStatus.style.color = 'red';
                        sendBtn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                `;
                        updateSendButtonState(); // Re-enable based on content
                    });
            });
        }

        // Function to append a new message to the chat
        function appendNewMessage(message, isSent) {
            const wrapper = document.createElement('div');
            wrapper.classList.add('message-wrapper', isSent ? 'sent' : 'received');

            // Avatar for received messages
            if (!isSent && message.sender_image) {
                const avatar = document.createElement('img');
                avatar.src = message.sender_image || 'assets/images/default-avatar.jpg';
                avatar.alt = '';
                avatar.classList.add('message-avatar');
                wrapper.appendChild(avatar);
            }

            // Message bubble
            const bubble = document.createElement('div');
            bubble.classList.add('message-bubble', isSent ? 'sent' : 'received');

            // Message content
            if (message.message_content) {
                bubble.innerHTML = nl2br(htmlspecialchars(message.message_content));
            }

            // Attachments
            if (message.file_path) {
                const attachmentDiv = document.createElement('div');
                attachmentDiv.classList.add('message-attachment');

                if (isAudioFile(message.file_path)) {
                    // Audio attachment
                    attachmentDiv.innerHTML = `
                    <div class="audio-player">
                        <audio controls>
                            <source src="uploads/messages/${message.file_path}" type="audio/mpeg">
                            مرورگر شما از پخش صدا پشتیبانی نمی‌کند.
                        </audio>
                    </div>
                `;
                } else if (isImageFile(message.file_path)) {
                    // Image attachment
                    attachmentDiv.innerHTML = `
                    <img src="uploads/messages/${message.file_path}" 
                         alt="تصویر پیام" 
                         class="attachment-preview image-preview"
                         data-full-src="uploads/messages/${message.file_path}">
                `;
                } else {
                    // Other file attachment
                    const fileSize = message.file_size ? formatFileSize(message.file_size) : 'نامشخص';
                    attachmentDiv.innerHTML = `
            <div class="file-attachment">
                <i class="fas ${getFileIcon(message.file_path)} file-icon"></i>
                <div class="file-info">
                    <div class="file-name">${htmlspecialchars(message.file_name || basename(message.file_path))}</div>
                    <div class="file-size">${fileSize}</div>
                </div>
             
                <a href="/uploads/messages/${htmlspecialchars(message.file_path)}" 
                    download 
                    class="file-download"> 
                 
             
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                </a>
            </div>
        `;
                }

                bubble.appendChild(attachmentDiv);
                // Append caption if present
                if (message.caption) {
                    const captionDiv = document.createElement('div');
                    captionDiv.classList.add('message-caption');
                    // Style similarly to PHP version or use CSS class
                    captionDiv.style.cssText = "margin-top: 5px; font-size: 0.9em; color: #6b7280; padding-left: 5px; padding-right: 5px;";
                    captionDiv.innerHTML = nl2br(htmlspecialchars(message.caption));
                    bubble.appendChild(captionDiv); // Append caption *after* attachment
                }
            }

            wrapper.appendChild(bubble);

            // Message metadata
            const meta = document.createElement('div');
            meta.classList.add('message-meta');
            meta.setAttribute('dir', 'ltr');
            meta.style.textAlign = isSent ? 'right' : 'left'; // Align meta text
            // **** USE PRE-FORMATTED TIMESTAMP ****
            const timestampSpan = document.createElement('span');
            timestampSpan.setAttribute('dir', 'rtl'); // Ensure RTL for Persian date
            timestampSpan.textContent = message.persian_timestamp || new Date(message.timestamp || Date.now()).toLocaleString(); // Use formatted, fallback to Gregorian if missing
            meta.appendChild(timestampSpan);
            // **** END TIMESTAMP CHANGE ****
            if (isSent) {
                const icon = document.createElement('i');
                // Check if Font Awesome is still needed/used here
                icon.classList.add('fas', message.is_read ? 'fa-check-double' : 'fa-check');
                icon.title = message.is_read ? 'خوانده شده' : 'ارسال شده';
                icon.style.marginLeft = '5px'; // Add space if needed
                meta.appendChild(icon);
            }
            // Append edited marker if needed
            if (message.edited_at) {
                const editedSpan = document.createElement('span');
                editedSpan.className = 'edited-marker';
                editedSpan.style.cssText = 'font-style: italic; font-size: 0.9em; margin-left: 5px;';
                editedSpan.textContent = '(ویرایش شده)';
                // Optionally add title with persian edited date if available from API
                // editedSpan.title = message.persian_edited_at || message.edited_at;
                meta.appendChild(editedSpan);
            }
            wrapper.appendChild(meta);

            // Add to chat area
            chatMessagesArea.appendChild(wrapper);
            chatMessagesArea.scrollTop = chatMessagesArea.scrollHeight;
        }

        // Handle image preview clicks
        chatMessagesArea.addEventListener('click', function(e) {
            if (e.target.classList.contains('image-preview')) {
                const fullSrc = e.target.dataset.fullSrc;
                modalImage.src = fullSrc;
                imageModal.style.display = 'flex';
            }
        });

        // Close modal on X click
        if (modalClose) {
            modalClose.addEventListener('click', function() {
                imageModal.style.display = 'none';
            });
        }

        // Close modal on outside click
        imageModal.addEventListener('click', function(e) {
            if (e.target === imageModal) {
                imageModal.style.display = 'none';
            }
        });

        // Helper function to check if a file is an audio file
        function isAudioFile(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            return ['mp3', 'wav', 'ogg', 'm4a'].includes(ext);
        }

        // Helper function to check if a file is an image
        function isImageFile(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            return ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
        }

        // Helper function to get file icon
        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();

            switch (ext) {
                case 'pdf':
                    return 'fa-file-pdf';
                case 'doc':
                case 'docx':
                    return 'fa-file-word';
                case 'xls':
                case 'xlsx':
                    return 'fa-file-excel';
                case 'ppt':
                case 'pptx':
                    return 'fa-file-powerpoint';
                case 'jpg':
                case 'jpeg':
                case 'png':
                case 'gif':
                    return 'fa-file-image';
                case 'zip':
                case 'rar':
                    return 'fa-file-archive';
                case 'txt':
                    return 'fa-file-alt';
                case 'mp3':
                case 'wav':
                case 'ogg':
                    return 'fa-file-audio';
                default:
                    return 'fa-file';
            }
        }

        // Helper functions needed in JS (same as PHP)
        function htmlspecialchars(str) {
            if (typeof str !== 'string') return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return str.replace(/[&<>"']/g, function(m) {
                return map[m];
            });
        }

        function nl2br(str) {
            return (str + '').replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '$1<br>$2');
        }

        function basename(path) {
            return path.split('/').pop().split('\\').pop();
        }

        // Poll for new messages
        if (selectedUserId && chatMessagesArea) {
            let lastTimestamp = <?= json_encode($lastTimestampValue) ?>;
            setInterval(() => {
                fetch(`api/get_new_messages.php?with=${selectedUserId}&since=${encodeURIComponent(lastTimestamp)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.messages.length > 0) {
                            data.messages.forEach(msg => {
                                // Add each new message to the chat
                                appendNewMessage(msg, msg.sender_id == currentUserId);
                                lastTimestamp = msg.timestamp;
                            });

                            // Mark messages as read
                            fetch('api/mark_messages_read.php', {
                                method: 'POST',
                                body: JSON.stringify({
                                    sender_id: selectedUserId
                                }),
                                headers: {
                                    'Content-Type': 'application/json'
                                }
                            });
                        }
                    })
                    .catch(error => console.error("Polling error:", error));
            }, 5000); // Poll every 5 seconds
        }
    });
</script>

<?php
// Helper function to format file size
function formatFileSize($bytes)
{
    if ($bytes < 1024)
        return $bytes . ' B';
    else if ($bytes < 1048576)
        return round($bytes / 1024, 1) . ' KB';
    else
        return round($bytes / 1048576, 1) . ' MB';
}

require_once 'footer_common.php';
?>