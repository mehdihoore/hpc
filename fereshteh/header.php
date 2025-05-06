<?php
// header.php

// Check if user is logged in.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// --- Update Last Activity ---
try {
    $pdo = connectDB(); // Make sure connection is established before this
    if ($_SESSION['user_id'] != 1) { // Exclude user with ID 1
        $stmt_update_activity = $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
        $stmt_update_activity->execute([$_SESSION['user_id']]);
    }
} catch (PDOException $e) {
    logError("Database error in header.php updating last_activity: " . $e->getMessage());
    // Don't exit, just log the error, header should still load
}
// --- End Update Last Activity ---
$totalUnreadCount = 0; // Initialize count

// Check if user is logged in and essential variables exist
if (function_exists('isLoggedIn') && isLoggedIn() && isset($_SESSION['user_id'])) {

    $currentUserIdHeader = $_SESSION['user_id']; // Use a distinct variable name if needed

    // Check if $pdo connection exists (assuming config_fereshteh.php or header.php sets it up)
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            // Prepare statement to count total unread messages for the user
            // Also ensure we don't count messages marked as deleted (if using soft delete)
            $stmt_total_unread = $pdo->prepare("
                SELECT COUNT(*) as total_unread_count
                FROM messages
                WHERE receiver_id = :user_id
                AND is_read = 0
                AND is_deleted = 0
            "); // Added is_deleted check

            
                $stmt_total_unread->execute([':user_id' => $currentUserIdHeader]);
                $result = $stmt_total_unread->fetch(PDO::FETCH_ASSOC);

                // If the query was successful and returned a result, update the count
                if ($result) {
                    $totalUnreadCount = (int) $result['total_unread_count'];
                }
           
        } catch (PDOException $e) {
            // Log error if query fails, but don't break the page
            if (function_exists('logError')) {
                logError("Error fetching total unread count in header: " . $e->getMessage());
            }
            $totalUnreadCount = 0; // Keep count at 0 on error
        }
    } else {
        // Optional: Log if PDO connection isn't available when expected
        if (function_exists('logError')) {
            logError("PDO connection not available in header.php for unread count.");
        }
    }
}

// --- End of added block ---

$pageTitle = isset($pageTitle) ? $pageTitle : 'پنل مدیریت'; // Default title
$current_page = basename($_SERVER['PHP_SELF']); // Get the current page filename

// Pre-calculate roleText and role for easier use.
$role = $_SESSION['role'] ?? '';
$roleText = '';
switch ($role) {
    case 'admin':
        $roleText = 'مدیر';
        break;
    case 'supervisor':
        $roleText = 'سرپرست';
        break;
    case 'planner':
        $roleText = 'طراح';
        break;
    case 'cnc_operator':
        $roleText = 'اپراتور CNC';
        break;
    case 'superuser':
        $roleText = 'سوپر یوزر';
        break;
    case 'receiver':
        $roleText = 'نصاب';
        break;
    default:
        $roleText = 'کاربر';
}

$onlineUsers = [];
$onlineTimeoutMinutes = 5; // Consider users online if active in the last 5 minutes
try {
    // $pdo should still be connected from the activity update
    $stmt_online = $pdo->prepare("
        SELECT id, first_name, last_name
        FROM users
        WHERE last_activity >= NOW() - INTERVAL :timeout MINUTE
          AND id != :current_user_id -- Exclude self
        ORDER BY first_name, last_name
    ");
    $stmt_online->bindParam(':timeout', $onlineTimeoutMinutes, PDO::PARAM_INT);
    $stmt_online->bindParam(':current_user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt_online->execute();
    $onlineUsers = $stmt_online->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logError("Database error in header.php fetching online users: " . $e->getMessage());
    // Gracefully handle error, $onlineUsers remains empty
}
// --- End Fetch Online Users ---

// Fetch the user's avatar path
// Fetch the user's avatar path from the database
try {
    $pdo = connectDB();
    $stmt = $pdo->prepare("SELECT avatar_path FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $avatarPath = $user['avatar_path'] ?? 'assets/images/default-avatar.jpg';
} catch (PDOException $e) {
    logError("Database error in header.php while fetching avatar path: " . $e->getMessage());
    $avatarPath = 'assets/images/default-avatar.jpg';
}
// Helper function to check access
function hasAccess($requiredRoles)
{
    global $role;
    return in_array($role, $requiredRoles);
}
$current_page_filename = basename($_SERVER['PHP_SELF']); // e.g., "upload_panel.php"
// Function to check if a link is active and return the class string
function getActiveClass($link_filename, $current_filename)
{
    return ($link_filename == $current_filename) ? ' active' : ''; // Add space before 'active'
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HPC Factory - <?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/assets/images/favicon-96x96.png">
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico"> <!-- For older IE -->
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png"> <!-- For Apple devices -->
    <link rel="manifest" href="/assets/images/site.webmanifest"> <!-- PWA manifest (optional) -->
    <link href="/assets/css/tailwind.min.css" rel="stylesheet">
    <!-- <link href="/assets/css/all.min.css" rel="stylesheet"> Removed Font Awesome -->
    <link href="/assets/css/font-face.css" rel="stylesheet">
    <link href="/assets/css/font-face.css" rel="stylesheet" type="text/css" />

    <!--  admin_assigne_date.php -->
    <link href="/assets/css/main.min.css" rel="stylesheet" />
    <link type="module" href="/assets/css/main.min.css" rel="stylesheet" />
    <link href="/assets/css/persian-datepicker.min.css" rel="stylesheet">
    <link href="/assets/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="/assets/css/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* Base Styles */
        @font-face {
            font-family: 'Vazir';
            src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2');
        }


        body {
            font-family: 'Vazir', sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
            text-align: right;
        }

        .main-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .top-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: white;
            padding: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 1rem;
            flex-wrap: wrap;
            /* Allow wrapping on smaller screens */
        }

        .site-logo {
            height: 40px;
            /* Set the height to match your image */
            width: auto;
            /* Maintain aspect ratio */
            margin-right: 1rem;
            /* Add some spacing */
        }

        .profile-pic {
            width: 60px;
            /* Adjust the size as needed */
            height: 60px;
            /* Ensure it stays circular if desired */
            object-fit: cover;
            /* Ensures the image covers the area without distortion */
            border-radius: 50%;
            /* Makes the image round */
        }

        .site-title {
            font-size: 1.5rem;
            font-weight: bold;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
        }

        .user-infoheader {
            background: rgba(255, 255, 255, 0.1);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0 auto;
            /* Center horizontally */
            text-align: center;
        }

        .user-infoheader i {
            font-size: 1.2rem;
        }

        /* Nav Styles */
        .nav-container {
            background: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .navigation {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0.5rem 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: #1e3a8a;
            /* Dark blue text */
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            white-space: nowrap;
            border-right: 3px solid transparent;
            /* Reserve space for active indicator (RTL) */

        }

        .nav-item svg {
            /* Use margin-left for RTL to put space *after* icon */
            margin-left: 0.5rem;
            /* If your layout is LTR, use margin-right */
            /* margin-right: 0.5rem; */
            font-size: 1.1rem;
            /* This might not affect SVG size directly */
            width: 1.1em;
            /* Control SVG size relative to font */
            height: 1.1em;
            fill: currentColor;
            /* Make SVG color match text color */
            flex-shrink: 0;
            /* Prevent icon squishing */
        }

        .nav-item i {
            margin-left: 0.5rem;
            font-size: 1.1rem;
        }

        .nav-item:hover {
            background: #e5e7eb;
            /* Light grey background */
            /* Keep text color on hover or change if desired */
            color: #111827;
            /* Slightly darker text on hover */
            /* transform: translateY(-1px); */
            /* Optional: Can remove/keep hover transform */
        }

        .nav-item.active {
            background-color: #dbeafe;
            /* A light blue background (Tailwind blue-100) */
            color: #1e3a8a;
            /* Keep the dark blue text or make it darker */
            font-weight: 600;
            /* Make text slightly bolder (semibold) */
            border-right-color: #1e3a8a;
            /* Make the reserved border visible (RTL) */

        }

        .logout-btn {
            background: #dc2626;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            margin-right: auto;
        }

        .logout-btn:hover {
            background: #b91c1c;
        }


        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
        }

        /* Content Wrapper for Consistent Padding */
        .content-wrapper {
            padding: 20px;
        }

        .content-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            flex-grow: 1;
        }

        /* Styles from upload_panel.php */
        .container {
            background-color: white;
            border-radius: 12px;
            /* Increased border-radius */
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            /* More pronounced shadow */
            padding: 40px;
            /* Increased padding */
            width: 100%;

            box-sizing: border-box;
        }

        h2 {
            color: #343a40;
            text-align: center;
            margin-bottom: 30px;
            font-size: 1.75em;
        }

        .form-group {
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #343a40;
            font-size: 1.1em;
        }

        select,
        input[type="file"],
        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 12px;
            margin-bottom: 10px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        select:focus,
        input[type="file"]:focus,
        input[type="text"]:focus,
        input[type="number"]:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        input[type="file"]::file-selector-button {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 12px 18px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            margin-right: 12px;
            font-size: 1em;
        }

        input[type="file"]::file-selector-button:hover {
            background-color: #2980b9;
        }

        .file-drop-zone {
            border: 2px dashed #3498db;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            background-color: #f0f8ff;
            transition: all 0.3s ease;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 120px;
        }

        .file-drop-zone.dragover {
            border-color: #2980b9;
            background-color: #e0f0ff;
            box-shadow: 0 0 10px rgba(52, 152, 219, 0.5);
        }

        .file-drop-zone p {
            margin: 0;
            font-size: 1.1em;
            color: #666;
        }

        .btn {
            background-color: #007bff;
            color: white;
            padding: 14px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 18px;
            transition: background-color 0.3s ease, transform 0.2s ease;
            width: 100%;
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }

        .btn:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
        }

        .button-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: space-between;
        }

        .btn.download-template {
            flex: 1;
            min-width: 120px;
            background-color: #28a745;
            border-color: #28a745;
            padding: 10px 15px;
            font-size: 16px;
        }

        .btn.download-template:hover {
            background-color: #218838;
            border-color: #1e7e34;
            transform: none;
        }

        .btn.confirm-upload {
            background-color: #28a745;
            border-color: #28a745;
        }

        .btn.confirm-upload:hover {
            background-color: #218838;
            border-color: #1e7e34;
        }

        .message {
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 1.1em;
            text-align: center;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .preview-container {
            margin-top: 30px;
            padding: 20px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow-x: auto;
        }

        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .preview-table th,
        .preview-table td {
            padding: 12px 15px;
            border: 1px solid #dee2e6;
            text-align: right;
        }

        .preview-table th {
            background-color: #e9ecef;
            font-weight: bold;
            color: #495057;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .example-table {
            width: auto;
            max-width: 100%;
            margin: 20px auto;
            border-collapse: collapse;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            text-align: right;
        }

        .example-table th,
        .example-table td {
            padding: 10px 15px;
            border: 1px solid #ddd;
        }

        .example-table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }

        .example-table td {
            background-color: #fff;
        }

        .file-info {
            display: flex;
            flex-wrap: wrap;
            gap: 25px;
            justify-content: space-between;
            margin-bottom: 20px;
            font-size: 1em;
            color: #6c757d;
        }

        .admin-badge {
            background-color: #28a745;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 1em;
            margin-right: auto;
            white-space: nowrap;
        }

        .logout-btn,
        .other-page-btn {
            color: #007bff;
            text-decoration: none;
            transition: all 0.3s ease;
            padding: 8px 15px;
            border-radius: 6px;
            border: 1px solid #007bff;
            white-space: nowrap;
        }

        .logout-btn:hover,
        .other-page-btn:hover {
            color: #0056b3;
            background-color: #e0f0ff;
            border-color: #0056b3;
            text-decoration: none;
        }

        .validation-message {
            color: #dc3545;
            margin-top: 8px;
            font-size: 1em;
        }

        /* Define a new style option for dark datepicker */
        .datepicker-dark {
            box-sizing: border-box;
            overflow: hidden;
            min-height: 70px;
            display: block;
            width: 220px;
            min-width: 220px;
            padding: 8px;
            position: absolute;
            font: 14px 'Vazir', sans-serif;
            border: 1px solid #4b5563;
            background-color: #1f2937;
            color: #e5e7eb;
            border-radius: 6px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .datepicker-dark .datepicker-day-view .month-grid-box .header .header-row-cell {
            display: block;
            width: 14.2%;
            height: 25px;
            float: right;
            line-height: 25px;
            font: 11px;
            font-weight: bold;
            color: #fff;
        }

        .datepicker-dark .datepicker-navigator .pwt-btn-next,
        .datepicker-dark .datepicker-navigator .pwt-btn-switch,
        .datepicker-dark .datepicker-navigator .pwt-btn-prev {
            display: block;
            float: left;
            height: 28px;
            line-height: 28px;
            font-weight: bold;
            background-color: rgba(250, 250, 250, 0.1);
            color: #e5e7eb;
        }

        .datepicker-dark .toolbox .pwt-btn-today {
            background-color: #e5e7eb;
            float: right;
            display: block;
            font-weight: bold;
            font-size: 11px;
            height: 24px;
            line-height: 24px;
            white-space: nowrap;
            margin: 0 auto;
            margin-left: 5px;
            padding: 0 5px;
            min-width: 50px;
        }

        .datepicker-dark .datepicker-day-view .table-days td span.other-month {
            background-color: "";
            color: #2585eb;
            border: none;
            text-shadow: none;
        }

        @media (max-width: 768px) {
            .header-content {
                justify-content: center;
                /* Center all items on mobile */
            }

            .user-infoheader {
                margin: 1rem 0;
                /* Add margin for spacing */
            }

            .container {
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            h2 {
                font-size: 1.5em;
            }

            .admin-badge {
                margin-right: 0;
            }

            .file-info {
                justify-content: flex-start;
            }

            .button-group {
                flex-direction: column;
            }

            .btn.download-template {
                flex: none;
                width: 100%;
            }

            /* Remove padding override on mobile */
            .content-container {
                padding: 0;
                /* Remove horizontal padding */
            }
        }

        .preview-table td[contenteditable="true"] {
            background-color: #fff3cd;
            cursor: text;
        }

        .preview-table td[contenteditable="true"]:focus {
            outline: 2px solid #ffc107;
            box-shadow: 0 0 5px rgba(255, 193, 7, 0.5);
        }

        .preview-table .edited {
            font-weight: bold;
            color: #007bff;
        }

        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }

            .navigation {
                display: none;
                width: 100%;
                flex-direction: column;
                padding: 0;
            }

            .navigation.active {
                display: flex;

            }

            .nav-item {
                width: 100%;
                border-radius: 0;
                border-bottom: 1px solid #e5e7eb;
                padding: 0.75rem 1rem;
                /* Add padding directly to nav-item */

            }

            .nav-item.active {

                border-right-color: transparent;
                /* Ensure side border is not shown */

                border-bottom-color: #1e3a8a;
                border-bottom-width: 2px;
            }

            .logout-btn {
                margin: 1rem;
                justify-content: center;
            }

            .user-infoheader {
                display: none;
                /* Hide in header on mobile */
            }

            .mobile-user-infoheader {
                display: flex;
                /* Show in nav on mobile */
                padding: 1rem;
                background: rgba(255, 255, 255, 0.05);
                margin-bottom: 1rem;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                align-items: center;
                /* Vertically center items */
                gap: 0.5rem;
                /* Add some spacing */
            }

            .mobile-user-infoheader i {
                font-size: 1.2rem;
                /* Consistent icon size */
            }

            .online-users-container {
                margin-left: 0;
                margin-top: 0.5rem;
                /* Add space below title */
            }

            .online-users-dropdown {
                left: auto;
                /* Let it align naturally or set right: 0 */
                right: 0;
                min-width: 180px;
            }
        }

        .unread-badge {
            display: inline-block;
            /* Allows padding/margins */
            background-color: #dc3545;
            /* Red color (Bootstrap's danger color) - adjust as needed */
            color: white;
            font-size: 0.7em;
            /* Make it small */
            padding: 2px 6px;
            /* Small padding */
            border-radius: 10px;
            /* Make it pill-shaped */
            margin-right: 8px;
            /* Space from the text (adjust if RTL needs margin-left) */
            font-weight: bold;
            line-height: 1;
            /* Keep it vertically tight */
            vertical-align: middle;
            /* Try to align vertically with text */
            min-width: 18px;
            /* Ensure even single digits have some width */
            text-align: center;
        }

        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 1.5rem 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .data-table th {
            background: linear-gradient(to bottom, #f8f9fa, #e9ecef);
            color: #1e3a8a;
            font-weight: bold;
            padding: 1rem;
            text-align: right;
            border-bottom: 2px solid #dee2e6;
        }

        .data-table td {
            padding: 1rem;
            text-align: right;
            border-bottom: 1px solid #e9ecef;
            transition: background-color 0.2s;
        }

        .data-table tr:hover td {
            background-color: #f8f9fa;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            gap: 0.5rem;
            min-width: 100px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .btn i {
            font-size: 0.9em;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-activate {
            background: #059669;
            color: white;
        }

        .btn-activate:hover {
            background: #047857;
        }

        .btn-deactivate {
            background: #dc2626;
            color: white;
        }

        .btn-deactivate:hover {
            background: #b91c1c;
        }

        .btn-admin {
            background: #2563eb;
            color: white;
        }

        .btn-admin:hover {
            background: #1d4ed8;
        }

        .btn-supervisor {
            background: #7c3aed;
            color: white;
        }

        .btn-supervisor:hover {
            background: #6d28d9;
        }

        .btn-user {
            background: #4b5563;
            color: white;
        }

        .btn-user:hover {
            background: #374151;
        }

        .btn-delete {
            background: #dc2626;
            color: white;
        }

        .btn-delete:hover {
            background: #b91c1c;
        }

        .btn-reset {
            background: #f59e0b;
            color: white;
        }

        .btn-reset:hover {
            background: #d97706;
        }

        .btn-container {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            padding: 0.5rem 0;
        }

        .btn-container form {
            margin: 0;
        }

        .status-active {
            color: #059669;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .status-active::before {
            content: '';
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #059669;
            border-radius: 50%;
        }

        .status-inactive {
            color: #dc2626;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .status-inactive::before {
            content: '';
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #dc2626;
            border-radius: 50%;
        }

        .message {
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 8px;
            text-align: right;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: slideIn 0.3s ease-out;
        }

        .message-success {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }

        .message-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .message-warning {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }


        .close {
            position: absolute;
            top: 1rem;
            left: 1rem;
            font-size: 1.5rem;
            color: #6b7280;
            cursor: pointer;
            transition: color 0.2s;
        }

        .close:hover {
            color: #374151;
        }

        /* Animations */
        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .data-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            .btn {
                min-width: auto;
                padding: 0.5rem 0.75rem;
            }

            .btn-container {
                justify-content: center;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
        }

        /* Print Styles */
        @media print {
            .btn-container {
                display: none;
            }

            .data-table {
                box-shadow: none;
            }

            .data-table th {
                background: #f8f9fa !important;
                color: black !important;
            }
        }

        /* Custom SVG Icons */
        .icon-upload {
            width: 18px;
            height: 18px;
            margin-left: 0.5rem;
            fill: currentColor;
            /* Use the text color */
        }

        .icon-search {
            width: 16px;
            height: 16px;
            margin-left: 0.5rem;
            fill: currentColor;
        }

        .icon-calendar {
            width: 16px;
            height: 16px;
            margin-left: 0.5rem;
            fill: currentColor;
        }

        .icon-tasks {
            width: 16px;
            height: 16px;
            margin-left: 0.5rem;
            fill: currentColor;
        }

        .icon-history {
            width: 16px;
            height: 16px;
            margin-left: 0.5rem;
            fill: currentColor;
        }

        .icon-clipboard {
            width: 16px;
            height: 16px;
            margin-left: 0.5rem;
            fill: currentColor;
        }

        .icon-tools {
            width: 16px;
            height: 16px;
            margin-left: 0.5rem;
            fill: currentColor;
        }

        .icon-signout {
            width: 16px;
            height: 16px;
            margin-left: 0.5rem;
            fill: currentColor;
        }

        .icon-user-circle {
            width: 20px;
            /* Adjust as needed */
            height: 20px;
            /* Adjust as needed */
            fill: currentColor;
            /* Use the text color */
        }

        .icon-bars {
            width: 24px;
            /* Adjust as needed */
            height: 24px;
            /* Adjust as needed */
            fill: currentColor;
            /* Use the text color */
        }

        .icon-industry {
            width: 20px;
            /* Adjust as needed */
            height: 20px;
            /* Adjust as needed */
            fill: currentColor;
            /* Use the text color */
        }

        /* admin_assigne_date.php styles */
        .panel-item {
            cursor: move;
            touch-action: none;
        }

        .fc-event {
            cursor: pointer;
            margin: 5px;
            touch-action: none;
        }

        .external-events {
            max-height: 500px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .panel-item.hidden {
            display: none;
        }

        .search-highlight {
            background-color: #fde68a;
        }

        .panel-item.being-dragged {
            opacity: 0.5;
            background-color: #93c5fd;
        }

        /* Add icon styles */
        .fc-icon {
            /*font-family: 'FontAwesome'; REMOVED*/
            font-style: normal;
        }

        /* use svg */
        /*.fc-icon-chevron-left:before {
            content: "\f053";
        }
        .fc-icon-chevron-right:before {
            content: "\f054";
        }*/


        @media (max-width: 768px) {

            /*
            .fc-button {
                padding: 0.75em 1em !important;
                font-size: 1.1em !important;
            }
            */
            /*removed.  Let tailwind handle it*/
            .panel-item {
                padding: 1em !important;
                margin-bottom: 0.75em !important;
            }

            /* Remove padding override on mobile */
            .content-container {
                padding: 0;
                /* Remove horizontal padding */
            }
        }

        @media (max-width: 767px) {

            /* Tailwind's 'md' breakpoint */
            .md\:col-span-1 {
                grid-column: span 4 / span 4;
                /* Make it full width */
            }
        }

        .panel-svg {
            width: 100%;
            height: 300px;
            background-color: #f8f9fa;
            border: 1px solid #e2e8f0;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            padding: 1rem;
        }

        .panel-svg svg {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .table-container {
            overflow-x: auto;
        }

        .action-buttons {
            white-space: nowrap;
        }

        .nav-tabs .nav-link {
            color: #495057;
        }

        .nav-tabs .nav-link.active {
            font-weight: bold;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .modal-dialog {
            max-width: 700px;
        }

        .status-col {
            min-width: 150px;
            /*from manage_formworks.php*/
        }


        <?php
        // Add any additional styles specific to a page.
        if (isset($styles)) {
            echo "<style>\n$styles\n</style>";
        }
        ?>.dataTables_wrapper .dataTables_filter {
            float: right;
            /* Move search box to the right */
            margin-bottom: 1rem;
        }

        .dataTables_wrapper .dataTables_filter input {
            margin-left: 0.5em;
            /* Spacing between label and input */
            display: inline-block;
            /* So it respects the margin */
            width: auto;
            /* Expand to fill container */
            padding: 0.375rem 0.75rem;
            /*For spacing*/
            border-radius: 0.25rem;
            /*Rounded corners*/
            border: 1px solid #ced4da;
            /*Bootstrap default border*/
            box-sizing: border-box;
            /* Include padding and border in the element's total width and height */
        }


        /* Responsive table styles */
        .dataTables_wrapper {
            overflow-x: auto;
        }

        @media (max-width: 768px) {
            .dataTables_wrapper {
                width: 100%;
            }
        }

        .profile-link {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
        }

        .profile-link:hover {
            opacity: 0.8;
        }
    </style>
    <!-- Put all the JavaScript includes *here*, just before the closing </head> tag -->

    <script src="/assets/js/main.min.js"></script>
    <!-- Add the DataTables CSS and JS -->
    <script src="/assets/js/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/popper.min.js"></script>
    <script src="/assets/js/bootstrap.min.js"></script>
    <script type="text/javascript" charset="utf8" src="/assets/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" charset="utf8" src="/assets/js/dataTables.responsive.min.js"></script>
    <script src="/assets/js/persian-date.min.js"></script>
    <script src="/assets/js/persian-datepicker.min.js"></script>
    <script src="/assets/js/mobile-detect.min.js"></script>
</head>

<body>
    <div class="main-container">
        <header class="top-header">
            <div class="header-content">
                <div class="site-logo-container">
                    <a href="/">
                        <img src="/assets/images/alumglass-farsi-logo-H40.png" alt="HPC Factory Logo" class="site-logo">
                    </a>
                    <h1 class="site-title" style="display: flex; align-items: center; gap: 0.5rem;">
                        <!-- Keep the SVG icon *inside* the h1 -->
                        <svg class="icon-industry" viewBox="0 0 24 24" style="flex-shrink: 0;"><?php echo htmlspecialchars($pageTitle); ?>
                            <path d="M19.5 13h-1.8v2.5h-1.5V13h-1.8v2.5h-1.5V13h-1.8v2.5h-1.5V13H8.1v2.5H6.6V13H4.8v8.5h14.7V13zm-6 5h-1.5v-2h1.5v2zm7.5 2h-16V11.5h1.5v.8h1.5v-.8h1.8v.8h1.5v-.8h1.8v.8h1.5v-.8h1.8v.8h1.5v-.8H18v.8h1.5V20zM21 8.9c-.1-.3-.4-.5-.7-.5H3.8c-.3 0-.6.2-.7.5l-1.9 5.3V21h20v-6.8L21 8.9zm-1 10.6H4v-4.5l.9-2.6h14.2l.9 2.6v4.5z" />
                        </svg>
                        <?php echo htmlspecialchars($pageTitle); ?>
                    </h1>
                </div>

                <div class="user-infoheader">
                    <!-- Make the avatar and username clickable to redirect to user's profile -->
                    <a href="profile.php" class="profile-link">
                        <!-- Display user's avatar or default avatar if not set -->
                        <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Profile Picture" class="profile-pic">
                        <!-- Inline SVG for user-circle -->
                        <svg class="icon-user-circle" viewBox="0 0 24 24">
                            <path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 5a3 3 0 1 1 0 6 3 3 0 0 1 0-6zm0 13c-2.67 0-8 1.34-8 4v1h16v-1c0-2.66-5.33-4-8-4z" />
                        </svg>
                        <?php
                        echo htmlspecialchars(
                            ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')
                        );
                        ?>
                        (<?php echo $roleText; ?>)
                    </a>
                </div>
                <a href="logout.php" class="logout-btn">
                    <!-- Inline SVG for sign-out-alt -->
                    <svg class="icon-signout" viewBox="0 0 24 24">
                        <path d="M14.08,15.59L16.67,13H7V11H16.67L14.08,8.41L15.5,7L20.5,12L15.5,17L14.08,15.59M19,3A2,2 0 0,1 21,5V9.67L19,7.67V5H5V19H19V16.33L21,14.33V19A2,2 0 0,1 19,21H5C3.89,21 3,20.1 3,19V5C3,3.89 3.89,3 5,3H19Z" />
                    </svg>
                    خروج
                </a>
                <button class="mobile-menu-btn">
                    <!-- Inline SVG for bars (hamburger menu) -->
                    <svg class="icon-bars" viewBox="0 0 24 24">
                        <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" />
                    </svg>
                </button>
            </div>
        </header>

        <nav class="nav-container">
            <div class="navigation" id="main-nav">

                <?php if (hasAccess(['admin', 'superuser'])): ?>
                    <a href="upload_panel.php" class="nav-item<?php echo getActiveClass('upload_panel.php', $current_page_filename); ?>">
                        <svg class="icon-upload" viewBox="0 0 24 24">
                            <path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z" />
                        </svg>
                        آپلود نقشه ها
                    </a>
                <?php endif; ?>

                <?php if (hasAccess(['admin', 'superuser', 'supervisor', 'planner', 'user'])): ?>
                    <a href="admin_panel_search.php" class="nav-item<?php echo getActiveClass('admin_panel_search.php', $current_page_filename); ?>">
                        <svg class="icon-search" viewBox="0 0 24 24">
                            <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                        </svg>
                        جستجوی پنل‌ها
                    </a>
                <?php endif; ?>

                <?php if (hasAccess(['admin', 'supervisor', 'superuser', 'planner', 'user'])): ?>
                    <a href="manage_formworks.php" class="nav-item<?php echo getActiveClass('manage_formworks.php', $current_page_filename); ?>">
                        <svg class="icon-tools" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14.5v-5h2v5h-2zm1-6.5c-.83 0-1.5-.67-1.5-1.5S11.17 7 12 7s1.5.67 1.5 1.5S12.83 10 12 10z" />
                        </svg>
                        مدیریت قالب‌ها
                    </a>
                <?php endif; ?>

                <?php if (hasAccess(['admin', 'planner', 'cnc_operator', 'superuser'])): ?>
                    <a href="polystyrene_management.php" class="nav-item<?php echo getActiveClass('polystyrene_management.php', $current_page_filename); ?>">
                        <svg class="icon-tools" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-width="2" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14.5v-5h2v5h-2zm1-6.5c-.83 0-1.5-.67-1.5-1.5S11.17 7 12 7s1.5.67 1.5 1.5S12.83 10 12 10z" />
                        </svg>
                        قطعات یونولیتی
                    </a>

                <?php endif; ?>

                <?php if (hasAccess(['admin', 'superuser', 'planner', 'user', 'supervisor'])): ?>
                    <a href="admin_assigne_date.php" class="nav-item<?php echo getActiveClass('admin_assigne_date.php', $current_page_filename); ?>">
                        <svg class="icon-calendar" viewBox="0 0 24 24">
                            <path d="M20 3h-1V1h-2v2H7V1H5v2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 18H4V8h16v13z" />
                        </svg>
                        برنامه ریزی تولید
                    </a>
                <?php endif; ?>

                <?php if (hasAccess(['admin', 'superuser', 'supervisor', 'user', 'planner'])): ?>
                    <a href="new_panels.php" class="nav-item<?php echo getActiveClass('new_panels.php', $current_page_filename); ?>">
                        <svg class="icon-tasks" viewBox="0 0 24 24">
                            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                        </svg>
                        مراحل کار
                    </a>
                <?php endif; ?>
                <?php if (hasAccess(['admin', 'supervisor', 'superuser'])): ?>
                    <a href="concrete_tests.php" class="nav-item<?php echo getActiveClass('concrete_tests.php', $current_page_filename); ?>">
                        <svg class="icon-tools" viewBox="0 0 24 24">
                            <?php /* آیکن Clipboard Check Outline از Material Design Icons */ ?>
                            <path d="M18,18H6V6H18M18,4H6A2,2 0 0,0 4,6V18A2,2 0 0,0 6,20H18A2,2 0 0,0 20,18V6A2,2 0 0,0 18,4M9.7,14.3C9.5,14.5 9.2,14.5 9,14.3L6.7,12C6.5,11.8 6.5,11.5 6.7,11.3L7.4,10.6C7.6,10.4 7.9,10.4 8.1,10.6L9.7,12.2L15.9,6C16.1,5.8 16.4,5.8 16.6,6L17.3,6.7C17.5,6.9 17.5,7.2 17.3,7.4L9.7,14.3Z" />
                        </svg>
                        تست بتن
                    </a>
                <?php endif; ?>
                <?php if (hasAccess(['planner', 'cnc_operator', 'user'])): ?>
                    <a href="concrete.php" class="nav-item<?php echo getActiveClass('concrete.php', $current_page_filename); ?>">
                        <svg class="icon-tools" viewBox="0 0 24 24">
                            <?php /* آیکن Clipboard Check Outline از Material Design Icons */ ?>
                            <path d="M18,18H6V6H18M18,4H6A2,2 0 0,0 4,6V18A2,2 0 0,0 6,20H18A2,2 0 0,0 20,18V6A2,2 0 0,0 18,4M9.7,14.3C9.5,14.5 9.2,14.5 9,14.3L6.7,12C6.5,11.8 6.5,11.5 6.7,11.3L7.4,10.6C7.6,10.4 7.9,10.4 8.1,10.6L9.7,12.2L15.9,6C16.1,5.8 16.4,5.8 16.6,6L17.3,6.7C17.5,6.9 17.5,7.2 17.3,7.4L9.7,14.3Z" />
                        </svg>
                        تست بتن
                    </a>
                <?php endif; ?>
                <?php if (hasAccess(['admin', 'superuser'])): ?>
                    <a href="activity_log.php" class="nav-item<?php echo getActiveClass('activity_log.php', $current_page_filename); ?>">
                        <svg class="icon-history" viewBox="0 0 24 24">
                            <path d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z" />
                        </svg>
                        پیگیری کنش‌ها
                    </a>
                <?php endif; ?>

                <?php if (hasAccess(['admin', 'superuser'])): ?>
                    <a href="manage_records.php" class="nav-item<?php echo getActiveClass('manage_records.php', $current_page_filename); ?>">
                        <svg class="icon-tools" viewBox="0 0 24 24">
                            <path d="M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z" />
                        </svg>
                        ویرایش رکوردها
                    </a>
                <?php endif; ?>

                <?php if (hasAccess(['admin', 'superuser'])): ?>
                    <a href="admin.php" class="nav-item<?php echo getActiveClass('admin.php', $current_page_filename); ?>">
                        <svg class="icon-tools" viewBox="0 0 24 24">
                            <path d="M12,3C7.58,3 4,4.79 4,7V11C4,13.21 7.58,15 12,15C16.42,15 20,13.21 20,11V7C20,4.79 16.42,3 12,3M13,10H11V7H13M13,14H11V12H13M12,17C8.69,17 6,14.31 6,11C6,9.70 6.43,8.47 7.17,7.44C7.54,7.00 8.00,6.66 8.53,6.44C9.50,6.16 10.73,6 12,6C13.27,6 14.50,6.16 15.47,6.44C16.00,6.66 16.46,7.00 16.83,7.44C17.57,8.47 18,9.70 18,11C18,14.31 15.31,17 12,17Z" />
                        </svg>
                        پنل مدیریت
                    </a>
                <?php endif; ?>

                <?php if (hasAccess(['admin', 'superuser', 'supervisor', 'user', 'planner'])): ?>
                    <a href="truck-assignment.php" class="nav-item<?php echo getActiveClass('truck-assignment.php', $current_page_filename); ?>">
                        <svg class="icon-tools" viewBox="0 0 24 24">
                            <path d="M19,3H14.82C14.4,1.84 13.3,1 12,1C10.7,1 9.6,1.84 9.18,3H5C3.9,3 3,3.9 3,5V19C3,20.1 3.9,21 5,21H19C20.1,21 21,20.1 21,19V5C21,3.9 20.1,3 19,3M12,3C12.55,3 13,3.45 13,4C13,4.55 12.55,5 12,5C11.45,5 11,4.55 11,4C11,3.45 11.45,3 12,3M7,7H17V5H19V19H5V5H7V7M12,17V15H17V17H12M12,11V9H17V11H12M8,9V11H7V9H8M8,15V17H7V15H8" />
                        </svg>
                        پکینگ لیست
                    </a>
                <?php endif; ?>

                <?php if (hasAccess(['admin', 'superuser', 'supervisor', 'user', 'planner'])): ?>
                    <a href="dashboard.php" class="nav-item<?php echo getActiveClass('dashboard.php', $current_page_filename); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="7" height="7" rx="1" ry="1" />
                            <rect x="14" y="3" width="7" height="7" rx="1" ry="1" />
                            <rect x="3" y="14" width="7" height="7" rx="1" ry="1" />
                            <rect x="14" y="14" width="7" height="7" rx="1" ry="1" />
                        </svg>
                        داشبورد گزارشات
                    </a>
                <?php endif; ?>
                <?php if (hasAccess(['admin', 'superuser', 'planner', 'supervisor', 'user'])): ?>
                    <a href="hpc_panels_manager.php" class="nav-item<?php echo getActiveClass('hpc_panels_manager.php', $current_page_filename); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="7" height="7" rx="1" ry="1" />
                            <rect x="14" y="3" width="7" height="7" rx="1" ry="1" />
                            <rect x="3" y="14" width="7" height="7" rx="1" ry="1" />
                            <rect x="14" y="14" width="7" height="7" rx="1" ry="1" />
                        </svg>
                        گزارشات دسته‌ای
                    </a>
                <?php endif; ?>

            </div>
            <?php // --- Messages Link Block ---
            // Original access check
           if (hasAccess(['admin', 'superuser', 'supervisor', 'planner', 'user', 'receiver', 'cnc_operator', 'guest'])) : // Adjust roles as needed
            ?>
                <a href="messages.php"
                    class="nav-item<?php echo getActiveClass('messages.php', $current_page_filename); ?>"
                    style="position: relative; display: inline-flex; align-items: center;"> <!-- Add styles for alignment -->

                    <!-- Your existing SVG Icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" style="margin-left: 0.5rem;">
                        <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V8l8 5 8-5v10zm-8-7L4 6h16l-8 5z" />
                    </svg>

                    پیام‌ها <!-- Message Text -->

                    <?php // --- Add the Badge ---
                    // Conditionally display badge only if count > 0
                    if ($totalUnreadCount > 0): ?>
                        <span class="unread-badge"><?= $totalUnreadCount ?></span>
                    <?php endif; ?>
                    <?php // --- End Badge --- 
                    ?>

                </a>
            <?php endif; ?>
            <?php // --- End Messages Link Block --- 
            ?>
        </nav>
        <script>
            document.addEventListener('DOMContentLoaded', function() {

                const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
                const navigation = document.querySelector('.navigation');

                mobileMenuBtn.addEventListener('click', function() {
                    navigation.classList.toggle('active');
                });

                // Close menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!event.target.closest('.nav-container') &&
                        !event.target.closest('.mobile-menu-btn')) {
                        navigation.classList.remove('active');
                    }
                });

                // Close menu when window is resized to desktop view
                window.addEventListener('resize', function() {
                    if (window.innerWidth > 768) {
                        navigation.classList.remove('active');
                    }
                });
            });
        </script>