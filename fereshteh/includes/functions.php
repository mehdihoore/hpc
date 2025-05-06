<?php
// includes/functions.php

/**
 * Translate status to Persian
 */
function translate_status($status) {
    $translations = [
        'pending' => 'در انتظار',
        'ordered' => 'سفارش داده شده',
        'in_production' => 'در حال تولید',
        'produced' => 'تولید شده',
        'delivered' => 'تحویل داده شده'
    ];

    return $translations[$status] ?? $status;
}


function fetchAllTruckPanelData(PDO $pdo) {
    // Get all trucks with shipment status
    $trucksStmt = $pdo->query("SELECT t.*, s.status as shipment_status FROM trucks t LEFT JOIN shipments s ON t.id = s.truck_id ORDER BY t.id DESC");
    $trucks = $trucksStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all available panels
    $availablePanelsStmt = $pdo->query("
        SELECT id, address, type, area, width, length, status
        FROM hpc_panels
        WHERE status = 'completed'
        AND concrete_end_time IS NOT NULL
        AND (packing_status = 'pending' OR packing_status IS NULL)
        ORDER BY id
    ");
    $availablePanels = $availablePanelsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all assigned panels
   $assignedPanelsStmt = $pdo->query("
        SELECT p.id, p.address, p.type, p.width, p.length, p.truck_id, p.packing_status, t.truck_number
        FROM hpc_panels p
        JOIN trucks t ON p.truck_id = t.id
        WHERE p.truck_id IS NOT NULL  
        ORDER BY t.truck_number, p.id
    ");
    $assignedPanels = $assignedPanelsStmt->fetchAll(PDO::FETCH_ASSOC);


    // Group assigned panels by truck
    $panelsByTruck = [];
    foreach ($assignedPanels as $panel) {
        $truckId = $panel['truck_id'];
        if (!isset($panelsByTruck[$truckId])) {
            $panelsByTruck[$truckId] = [];
        }
        $panelsByTruck[$truckId][] = $panel;
    }
    // *** Get the count of completed panels ***
    $stmt = $pdo->query("SELECT COUNT(*) AS completed_count FROM hpc_panels WHERE status = 'completed'
        AND concrete_end_time IS NOT NULL
        AND (packing_status = 'pending' OR packing_status IS NULL)");
    $completedCountResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $completedCount = $completedCountResult['completed_count'];

    return [
        'trucks' => $trucks,
        'availablePanels' => $availablePanels,
        'panelsByTruck' => $panelsByTruck,
        'completedCount' => $completedCount,
    ];
}
/**
 * Secure file upload function
 */
function secure_file_upload($file, $allowed_types, $upload_dir) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'An upload error occurred. Error code: ' . $file['error']];
    }

    $file_tmp = $file['tmp_name'];
    $file_name = $file['name'];
    $file_size = $file['size'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    // Validate file type
    if (!in_array($file_ext, $allowed_types)) {
        return ['error' => 'نوع فایل مجاز نیست'];
    }

    // Validate file size
    if ($file_size > MAX_FILE_SIZE) {
        return ['error' => 'File size exceeds the limit.'];
    }

    // Ensure unique filename
    $target_path = $upload_dir . $file_name;
    $file_name_without_ext = pathinfo($file_name, PATHINFO_FILENAME);
    $counter = 1;
    while (file_exists($target_path)) {
        $new_file_name = $file_name_without_ext . '_' . $counter . '.' . $file_ext;
        $target_path = $upload_dir . $new_file_name;
        $counter++;
    }

    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Check for file existence before moving
    if (!file_exists($file_tmp)) {
        return ['error' => 'Uploaded file not found.'];
    }

    // Move file
    if (move_uploaded_file($file_tmp, $target_path)) {
        return [
            'success' => true,
            'file_path' => $target_path,
            'file_name' => basename($target_path)
        ];
    }

    return ['error' => 'خطا در آپلود فایل'];
}

/**
 * Get user role permissions.  Uses 'array_key_exists' for robustness.
 */
function get_user_permissions($role) {
    // Define ALL possible permissions with default values (false)
    $default_permissions = [
        'view_all' => false,
        'edit_all' => false,
        'delete_all' => false,
        'upload_files' => false,
        'download_files' => false, // Everyone should be able to download
        'update_status' => false,
        'view_orders' => false,
        'update_dates' => false, // Make sure to include this
        // ... add any other permissions you might have or add later
    ];

    // Define role-specific permissions (overrides defaults)
    $permissions = [
        'admin' => [
            'view_all' => true,
            'edit_all' => true,
            'delete_all' => true,
            'upload_files' => true,
            'download_files' => true, // keep it
            'update_status' => true,
            'view_orders' => true,
            'update_dates' => true, // Add missing permissions

        ],
        'supervisor' => [
            'view_all' => true,
            'edit_all' => true,
            'upload_files' => true,
            'download_files' => true, // keep it
            'update_status' => true,
            'view_orders' => true,
            'update_dates' => true, // Add missing permissions
        ],
        'planner' => [
            'view_assigned' => true,  // Keep this, as it's different from view_all
            'edit_all' => true, // Add/Correct this
            'upload_files' => true,
            'download_files' => true,  // keep it
            'update_status' => true,
            'view_orders' => false,
            'update_dates' => true,   // Add this
        ],
        'cnc_operator' => [
            'view_orders' => true,
            'download_files' => true, // keep it
            'update_status' => true,
            'update_dates' => true,   // keep
            // Don't add edit_all: false here. Let it inherit from defaults.
        ],
        'user' => [
            'view_assigned' => false,
            'download_files' => true, // keep it
            'update_status' => false,
            'view_orders' => false,
             'update_dates' => false, // Add missing permissions
        ]
    ];

    // Get the role's permissions, or default to 'user' if the role is invalid
    $role_permissions = $permissions[$role] ?? $permissions['user'];

    // Merge the role-specific permissions with the defaults
    // Role-specific permissions will OVERWRITE the defaults.
    return array_merge($default_permissions, $role_permissions);
}


/**
 * Log activity
 */
function log_activity($user_id, $activity_type, $details) {
    global $pdo;
    
    try {
        // Get username for the user_id
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $username = $stmt->fetchColumn();

        // Insert into activity_log with the correct columns
        $stmt = $pdo->prepare("INSERT INTO activity_log 
            (user_id, username, activity_type, details, timestamp) 
            VALUES (?, ?, ?, ?, NOW())");
        
        return $stmt->execute([
            $user_id,
            $username,
            $activity_type,
            $details
        ]);
    } catch (PDOException $e) {
        error_log("Activity Log Error: " . $e->getMessage());
        return false;
    }
}
/**
 * Format file size
 */
function format_file_size($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return number_format($bytes) . ' bytes';
    }
}

/**
 * Format Jalali date nicely
 */
function format_jalali_date($date) {
    if (empty($date)) return '';
    return jdate("Y/m/d", strtotime($date));
}

/**
 * Validate and sanitize input
 */
function sanitize_input($input) {
    if (is_array($input)) {
        return array_map('sanitize_input', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Get Sarotah details
 */
function get_sarotah_details($pdo, $address) {
    $stmt = $pdo->prepare("SELECT left_panel, right_panel FROM sarotah WHERE left_panel LIKE ? OR right_panel LIKE ?");
    $stmt->execute(["$address%", "$address%"]); // Use % for partial matching
    return $stmt->fetch(PDO::FETCH_ASSOC);
}


function formatJalaliDateOrEmpty($date) {
    if (empty($date)) {
        return ''; // Or return 'N/A' if you prefer
    }
    return jdate("Y/m/d", strtotime($date));
}
function get_status_color($status) {
    $colors = [
        'pending' => 'warning',
        'ordered' => 'info',
        'in_production' => 'primary',
        'produced' => 'success',
        'delivered' => 'secondary',
    ];
    return $colors[$status] ?? 'light';
}
