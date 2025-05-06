<?php
require_once 'includes/jdf.php';

$gregorianDate = $_GET['date'] ?? null;

if ($gregorianDate) {
    try {
        // Convert Gregorian date string to timestamp
        $timestamp = strtotime($gregorianDate);
         if ($timestamp === false) {
            // Invalid date format
            header('Content-Type: text/plain');
            echo ''; // Return empty string on error
            exit();
        }

        // Convert timestamp to Jalali date using jdate()
        $jalaliDate = jdate('Y/m/d', $timestamp, '', 'Asia/Tehran');
         header('Content-Type: text/plain');
        echo $jalaliDate; // Output the Jalali date
       exit();

    } catch (Exception $e) {
        error_log("Error in convert_date.php: " . $e->getMessage()); // Log errors
        header('Content-Type: text/plain');
        echo ''; // Return empty string on error
        exit;
    }
}
 header('Content-Type: text/plain');
echo ''; // Return empty string if no date provided