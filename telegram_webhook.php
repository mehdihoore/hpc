<?php

// --- Configuration ---
define('TELEGRAM_BOT_TOKEN', '7971076421:AAEp9HpGooR-JXnypvC73qZtG03BZ4biu-A'); // Replace with your Bot Token

// **IMPORTANT:** Paths MUST be absolute paths on your server.
// Find your cPanel username: often visible in the File Manager path.
define('ALLOWED_CHATS_FILE', '/home/alumglas/telpat/allowed_chats.txt');
// Path to the *full* backup script
define('BACKUP_SCRIPT_PATH', '/home/alumglas/telpat/daily_backup.php');
define('PHP_EXECUTABLE_PATH', '/usr/bin/php'); // Common path, check with host if unsure (or try /usr/local/bin/php)

// Additional backup options for telegram commands
// ** Ensure these files exist and are configured to perform ONLY that type of backup **
define('BACKUP_FILES_ONLY_PATH', '/home/alumglas/telpat/files_backup.php'); // Script for files-only backup
define('BACKUP_DB_ONLY_PATH', '/home/alumglas/telpat/db_backup.php'); // Script for database-only backup

// --- Helper Functions ---

/**
 * Sends a simple text message back to a specific Telegram chat.
 * @param int $chatId
 * @param string $messageText
 * @return bool True on success, false on failure.
 */
function sendTelegramMessage(int $chatId, string $messageText): bool
{
    $apiUrl = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $postData = [
        'chat_id' => $chatId,
        'text' => $messageText,
        'parse_mode' => 'HTML' // Optional: Allows basic HTML formatting like <b>bold</b>
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData)); // Use http_build_query for simple text data
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Short timeout for sending messages

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        error_log("Telegram sendMessage failed: HTTP {$httpCode} - cURL Error: {$curlError} - Response: {$response}");
        return false;
    }

    $responseData = json_decode($response, true);
    if (!$responseData || !$responseData['ok']) {
        error_log("Telegram API Error sending message: " . $response);
        return false;
    }
    return true;
}

/**
 * Checks if a given Chat ID is in the allowed list file.
 * @param int $chatId
 * @return bool
 */
function isChatAllowed(int $chatId): bool
{
    if (!file_exists(ALLOWED_CHATS_FILE) || !is_readable(ALLOWED_CHATS_FILE)) {
        error_log("Error: Allowed chats file not found or not readable: " . ALLOWED_CHATS_FILE);
        return false; // Fail securely
    }

    // Read file line by line, trimming whitespace and converting to int
    $allowedIds = file(ALLOWED_CHATS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($allowedIds === false) {
        error_log("Error reading allowed chats file: " . ALLOWED_CHATS_FILE);
        return false;
    }

    $allowedIds = array_map('trim', $allowedIds);
    $allowedIds = array_map('intval', $allowedIds); // Convert to integers

    return in_array($chatId, $allowedIds, true); // Strict comparison
}

/**
 * Execute a backup script and handle logging and messaging
 * @param int $chatId The chat ID to send status messages to
 * @param string $scriptPath Full path to the backup script to execute
 * @param string $backupType Description of what's being backed up (for messages)
 * @return bool Success status of *starting* the script
 */
function executeBackupScript(int $chatId, string $scriptPath, string $backupType = "full"): bool
{
    if (!file_exists($scriptPath)) {
        error_log("Backup script not found: " . $scriptPath);
        sendTelegramMessage($chatId, "⚠️ خطا: اسکریپت پشتیبان‌گیری پیدا نشد: " . basename($scriptPath));
        return false;
    }

    $command = escapeshellcmd(PHP_EXECUTABLE_PATH) . ' ' . escapeshellarg($scriptPath);
    $command .= ' >> ' . escapeshellarg(__DIR__ . '/backup_runner.log') . ' 2>&1 &';

    error_log("Executing {$backupType} backup command for Chat ID {$chatId}: " . $command);

    unset($outputLines);
    exec($command, $outputLines, $returnVar);

    if ($returnVar === 0) {
        error_log("{$backupType} backup script started successfully (Return code: 0) triggered by Chat ID: {$chatId}");
        return true;
    } else {
        error_log("{$backupType} backup script FAILED TO START (Return code: {$returnVar}) triggered by Chat ID: {$chatId}. Output: " . implode("\n", $outputLines));
        sendTelegramMessage($chatId, "⚠️ خطایی در شروع فرآیند پشتیبان‌گیری {$backupType} رخ داد. لطفاً لاگ‌های سرور را بررسی کنید.");
        return false;
    }
}

/**
 * Sends help message with available commands
 * @param int $chatId
 */
function sendHelpMessage(int $chatId): void
{
    $helpText = "<b>دستورات موجود برای پشتیبان‌گیری:</b>\n\n" .
        "/backup - اجرای پشتیبان‌گیری کامل (پایگاه داده + فایل‌ها)\n" .
        "/backup_db - فقط پشتیبان‌گیری پایگاه داده\n" .
        "/backup_files - فقط پشتیبان‌گیری فایل‌ها\n\n" .
        "/help - نمایش این پیام راهنما";

    sendTelegramMessage($chatId, $helpText);
}

// --- Main Webhook Logic ---

$webhookLogDir = __DIR__ . '/logs';
if (!is_dir($webhookLogDir)) {
    @mkdir($webhookLogDir, 0755, true);
}
ini_set('error_log', $webhookLogDir . '/webhook_error.log');
error_log("--- Webhook Accessed ---");

$input = file_get_contents('php://input');
if (!$input) {
    error_log("Webhook received empty input.");
    http_response_code(400);
    exit('No input received.');
}

$update = json_decode($input, true);
if ($update === null && json_last_error() !== JSON_ERROR_NONE) {
    error_log("Webhook failed to decode JSON: " . json_last_error_msg() . " - Input: " . $input);
    http_response_code(400);
    exit('Invalid JSON received.');
}

if (!isset($update['message'])) {
    error_log("Webhook ignored non-message update: " . $input);
    http_response_code(200);
    exit('OK');
}

$message = $update['message'];
$chatId = $message['chat']['id'] ?? null;
$messageText = trim($message['text'] ?? '');
$userId = $message['from']['id'] ?? null;
$firstName = $message['from']['first_name'] ?? 'User';

if (!$chatId || !$userId || empty($messageText)) {
    error_log("Webhook received message with missing chat_id, user_id, or text: " . $input);
    http_response_code(400);
    exit('Missing required message data.');
}

if (!isChatAllowed($chatId)) {
    error_log("Unauthorized access attempt from Chat ID: {$chatId}, User ID: {$userId}, Name: {$firstName}, Command: '{$messageText}'");
    sendTelegramMessage($chatId, "⛔ متأسفیم، شما مجاز به استفاده از این ربات نیستید.");
    http_response_code(200);
    exit('Unauthorized.');
}

$command = strtolower($messageText);
$backupStarted = false;

error_log("Authorized command '{$command}' received from Chat ID: {$chatId}, User ID: {$userId}, Name: {$firstName}");

switch ($command) {
    case '/backup':
        sendTelegramMessage($chatId, "⏳ پشتیبان‌گیری کامل (پایگاه داده + فایل‌ها) آغاز شد. به زودی فایل‌ها را دریافت خواهید کرد...");
        if (executeBackupScript($chatId, BACKUP_SCRIPT_PATH, "full")) {
            $backupStarted = true;
        }
        break;

    case '/backup_db':
        if (file_exists(BACKUP_DB_ONLY_PATH)) {
            sendTelegramMessage($chatId, "⏳ پشتیبان‌گیری فقط از پایگاه داده آغاز شد. به زودی فایل‌ها را دریافت خواهید کرد...");
            if (executeBackupScript($chatId, BACKUP_DB_ONLY_PATH, "database")) {
                $backupStarted = true;
            }
        } else {
            error_log("DB-only backup script missing (" . BACKUP_DB_ONLY_PATH . "). Falling back to full backup for Chat ID: {$chatId}.");
            sendTelegramMessage($chatId, "⚠️ اسکریپت پشتیبان‌گیری فقط پایگاه داده پیدا نشد. پشتیبان‌گیری <b>کامل</b> به جای آن آغاز می‌شود...");
            if (executeBackupScript($chatId, BACKUP_SCRIPT_PATH, "full (fallback from db)")) {
                $backupStarted = true;
            }
        }
        break;

    case '/backup_files':
        if (file_exists(BACKUP_FILES_ONLY_PATH)) {
            sendTelegramMessage($chatId, "⏳ پشتیبان‌گیری فقط از فایل‌ها آغاز شد. به زودی فایل‌ها را دریافت خواهید کرد...");
            if (executeBackupScript($chatId, BACKUP_FILES_ONLY_PATH, "files")) {
                $backupStarted = true;
            }
        } else {
            error_log("Files-only backup script missing (" . BACKUP_FILES_ONLY_PATH . "). Falling back to full backup for Chat ID: {$chatId}.");
            sendTelegramMessage($chatId, "⚠️ اسکریپت پشتیبان‌گیری فقط فایل‌ها پیدا نشد. پشتیبان‌گیری <b>کامل</b> به جای آن آغاز می‌شود...");
            if (executeBackupScript($chatId, BACKUP_SCRIPT_PATH, "full (fallback from files)")) {
                $backupStarted = true;
            }
        }
        break;

    case '/help':
        sendHelpMessage($chatId);
        break;

    case '/start':
        sendTelegramMessage($chatId, "به ربات پشتیبان‌گیری خوش آمدید! 🤖\n\nاز دستورات زیر برای اجرای پشتیبان‌گیری استفاده کنید.");
        sendHelpMessage($chatId);
        break;

    default:
        error_log("Received unknown command '{$messageText}' from authorized Chat ID: {$chatId}, User ID: {$userId}");
        sendTelegramMessage($chatId, "❓ متأسفم، دستور '<code>" . htmlspecialchars($messageText) . "</code>' را نمی‌فهمم. برای مشاهده دستورات موجود، /help را امتحان کنید.");
        break;
}

http_response_code(200);
if ($backupStarted) {
    error_log("Webhook finished processing backup command '{$command}' for Chat ID {$chatId}.");
} else {
    error_log("Webhook finished processing command '{$command}' for Chat ID {$chatId}.");
}
exit('OK');
