<?php
// error404.php
http_response_code(404); // Set the HTTP status code
$pageTitle = 'پیدا نشد '; // Customize for each page
require_once 'header.php'; // Include the header

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>404 Not Found</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding-top: 50px; }
        h1 { color: #800; }
    </style>
</head>
<div>
    <h1>404 Not Found</h1>
    <p>صفحه پیدا نشد!</p>
</div>
<?php require_once 'footer.php'; ?>