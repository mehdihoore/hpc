<?php
// error403.php
http_response_code(403); // Set the HTTP status code.  Important!
$pageTitle = "خطای ۴۰۳ - دسترسی غیرمجاز"; // Set a *specific* page title
require_once 'header.php'; // Include the header
?>

<div class="content-wrapper">
    <h1>403 Forbidden</h1>
    <p>شما برای دسترسی به این صفحه مجاز نیستید!</p>
</div>

<?php require_once 'footer.php'; ?>