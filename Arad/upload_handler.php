<?php
// upload_handler.php (Located inside /Arad/ or /Fereshteh/)
// This script handles AJAX file uploads from the modal.

require_once __DIR__ . '/../../sercon/bootstrap.php'; // Adjust path as needed

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'uploaded_files' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
    $uploadDir = __DIR__ . '/uploads/item_documents/'; // Temporary upload directory
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true); // Create directory if it doesn't exist
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf', 'application/dwg', 'application/vnd.autocad.dwg', 'application/octet-stream']; // Add more as needed
    $maxFileSize = 100 * 1024 * 1024; // 100 MB

    $uploadedCount = 0;
    foreach ($_FILES['files']['name'] as $key => $fileName) {
        $fileTmpName = $_FILES['files']['tmp_name'][$key];
        $fileError = $_FILES['files']['error'][$key];
        $fileSize = $_FILES['files']['size'][$key];
        $fileType = mime_content_type($fileTmpName);

        if ($fileError === UPLOAD_ERR_OK) {
            if (in_array($fileType, $allowedTypes) && $fileSize <= $maxFileSize) {
                $newFileName = uniqid('temp_file_') . '_' . basename($fileName);
                $destination = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmpName, $destination)) {
                    $response['uploaded_files'][] = [
                        'name' => $fileName,
                        'path' => $destination, // Store the full server path for later use
                        'type' => $fileType
                    ];
                    $uploadedCount++;
                } else {
                    logError("Failed to move temporary uploaded file: {$fileName}");
                    $response['message'] = 'خطا در ذخیره سازی موقت یک یا چند فایل.';
                }
            } else {
                logError("Invalid temp file type or size: {$fileName}");
                $response['message'] = 'فایل(های) نامعتبر یا خیلی بزرگ هستند.';
            }
        } else if ($fileError !== UPLOAD_ERR_NO_FILE) {
            logError("Temporary file upload error: {$fileError}");
            $response['message'] = 'خطا در آپلود فایل (کد خطا: ' . $fileError . ')';
        }
    }

    if ($uploadedCount > 0) {
        $response['success'] = true;
        $response['message'] = 'فایل‌ها با موفقیت آپلود شدند.';
    } else if (empty($response['message'])) {
        $response['message'] = 'هیچ فایلی برای آپلود انتخاب نشد یا خطایی رخ نداد.';
    }
} else {
    $response['message'] = 'درخواست نامعتبر.';
}

echo json_encode($response);
exit();
