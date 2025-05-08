<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Config Inclusion</title>
</head>
<body>
    <h1>Testing Config File Inclusion</h1>
    <?php
    // Define the path to the config file
    $config_path = __DIR__ . '/../../sercon/config_fereshteh.php';
    
    // Check if the file exists
    if (file_exists($config_path)) {
        require_once $config_path;
        echo '<p style="color: green;">Config file included successfully!</p>';
    } else {
        die('<p style="color: red;">Config file not found.</p>');
    }
    ?>
</body>
</html>