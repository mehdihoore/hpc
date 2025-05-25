<?php

// config.php

// **Database Settings** //
define('DB_HOST', 'localhost');      // Usually 'localhost' if the database is on the same server
define('DB_NAME', 'sabaatir_sabaat'); // Your database name
define('DB_USER', 'sabaatir_mehdihoore');    // Replace with your database username
define('DB_PASS', 'Taraze@davazdah12'); // Replace with your database password
define('DB_CHARSET', 'utf8mb4');     // Database character set

// **Application Settings (Optional - Add more as needed)** //
define('APP_ENV', 'development'); // 'development' or 'production'
// In 'development', you might show more detailed errors.
// In 'production', you'd log errors and show generic messages.

define('BASE_URL', 'https://sabaat.ir'); // Replace with your actual domain or http://localhost/yourproject/public_html during development
define('SITE_TITLE', 'مهدی هوره - آثار و اندیشه‌ها'); // Your default site title (can be overridden per page)

// **Error Reporting (Adjust for development/production)** //
switch (APP_ENV) {
    case 'development':
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);
        break;
    case 'production':
    default:
        ini_set('display_errors', 0);
        ini_set('display_startup_errors', 0);
        error_reporting(E_ALL); // Still log all errors
        // You would typically set up error logging to a file in production:
        // ini_set('log_errors', 1);
        // ini_set('error_log', '/path/to/your/php-error.log'); // Ensure this path is writable by the web server
        break;
}

// **Timezone (Important for date/time functions)** //
date_default_timezone_set('Asia/Tehran'); // Set to your preferred timezone

// **Security Settings** //
// Generate a long, random, and unique string for this.
// You can use an online generator or PHP's random_bytes() / bin2hex() once to create it.
// Example: bin2hex(random_bytes(32))
define('CSRF_TOKEN_SECRET', '87ded6d5d1eab04f2c64dc0d80831a657ad58edb0decde65c6e668fc03812435');
// define('ENCRYPTION_KEY', 'your_other_very_long_random_secret_string_for_encryption'); // For other encryption needs


// **Function to create a PDO database connection (example)**
function getPDOConnection()
{
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on error
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch associative arrays by default
        PDO::ATTR_EMULATE_PREPARES   => false,                  // Use real prepared statements
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (\PDOException $e) {
        if (APP_ENV === 'development') {
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        } else {
            error_log("Database Connection Error: " . $e->getMessage());
            exit("A database error occurred. Please try again later.");
        }
    }
}
