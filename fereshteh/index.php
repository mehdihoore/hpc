<?php
// index.php - The Front Controller

require_once __DIR__ . '/../sercon/config_fereshteh.php';
secureSession();

// --- Basic Routing ---
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME']; // Usually /index.php

// Extract the base route (without query parameters)
$baseRoute = strtok($requestUri, '?');
$baseRoute = trim($baseRoute, '/');

 // --- Simplified Routing Logic ---
$routes = [
    ''              => ['file' => 'login.php', 'roles' => []],
    'login'         => ['file' => 'login.php', 'roles' => []],
    'logout'        => ['file' => 'logout.php', 'roles' => ['admin', 'superuser', 'supervisor', 'planner', 'user', 'receiver', 'cnc_operator', 'guest']],
    'register'      => ['file' => 'registration.php', 'roles' => []],
    'registration'  => ['file' => 'registration.php', 'roles' => []],

    // Admin routes
    'admin'         => ['file' => 'admin.php', 'roles' => ['admin', 'superuser']],
    'admin/users'   => ['file' => 'admin.php', 'roles' => ['admin', 'superuser']],
    'admin/panels'  => ['file' => 'admin_panel_search.php', 'roles' => ['admin', 'superuser', 'supervisor', 'planner']],
    'admin/panels/search' => ['file' => 'admin_panel_search.php', 'roles' => ['admin', 'superuser', 'supervisor', 'planner']],
    'admin/select'  => ['file' => 'admin_select.php', 'roles' => ['admin', 'superuser', 'planner']],
    'admin/formworks' => ['file' => 'manage_formworks.php', 'roles' => ['admin', 'superuser', 'supervisor', 'planner']],
    'admin/assign_date' => ['file' => 'admin_assigne_date.php', 'roles' => ['admin', 'superuser', 'planner']],
    'admin/activity' => ['file' => 'activity_log.php', 'roles' => ['admin', 'superuser', 'planner']],
    'admin/records' => ['file' => 'manage_records.php', 'roles' => ['admin', 'superuser', 'supervisor', 'planner']],

    // Panel and user routes
    'panel'           => ['file' => 'panel_detail.php', 'roles' => ['admin', 'superuser', 'supervisor', 'planner']],
    'new_panels'      => ['file' => 'new_panels.php', 'roles' => ['admin', 'superuser', 'supervisor', 'user', 'planner']],
    'blocks'          => ['file' => 'sort.php', 'roles' => ['admin', 'superuser', 'supervisor', 'user', 'planner']],
    'upload'          => ['file' => 'upload_panel.php', 'roles' => ['admin', 'superuser', 'planner']],
    'user/panel'      => ['file' => 'panel_detail_user.php', 'roles' => ['admin', 'superuser', 'user', 'planner']],
    'user/tracking'   => ['file' => 'panel-tracking.php', 'roles' => ['admin', 'superuser', 'user', 'planner']],

    // Polystyrene Management (single route, handle query parameters within the file)
    'polystyrene_management' => ['file' => 'polystyrene_management.php', 'roles' => ['admin', 'superuser', 'cnc_operator', 'planner']],
    'messages' => ['file' => 'messages.php', 'roles' => ['admin', 'superuser', 'supervisor', 'planner', 'user', 'receiver', 'cnc_operator', 'guest']],
];

// --- Determine the file to include and check authorization ---
$fileToInclude = null;
$authorized = false;

if (array_key_exists($baseRoute, $routes)) {
    $routeInfo = $routes[$baseRoute];
    $fileToInclude = $routeInfo['file'];
    $requiredRoles = $routeInfo['roles'];

    // --- Authorization Check ---
    if (empty($requiredRoles) || (isset($_SESSION['user_id'], $_SESSION['role']) && in_array($_SESSION['role'], $requiredRoles))) {
        $authorized = true;
    }
}

 // Handle login redirects based on role (BEFORE including the file)
if ($baseRoute === 'login' && isset($_SESSION['user_id'], $_SESSION['role'])) {
    $authorized = false; // Prevent including login.php if user logged in.
    $role = $_SESSION['role'];
    if ($role === 'admin' || $role === 'superuser') {
        header('Location: /admin');
    } elseif (in_array($role, ['supervisor', 'user', 'planner'])) {
        header('Location: /admin_panel_search.php');
    } elseif (in_array($role, ['cnc_operator', 'planner'])) {
        header('Location: /admin_panel_search.php'); // Correct redirect
    }
    exit();
}

if ($authorized && $fileToInclude !== null) {
    if (file_exists($fileToInclude)) {
        $currentRoute = $baseRoute; // Set the $currentRoute variable
        require_once 'header.php';
        require_once $fileToInclude;
    } else {
        // 500 Internal Server Error
        http_response_code(500);
        echo "<h1>500 Internal Server Error</h1><p>The requested file ($fileToInclude) is misconfigured.</p>";
        exit();
    }
} elseif (!isset($_SESSION['user_id'])) {
    header('Location: /login'); // Redirect to login if not logged in.
    exit();
} else {
  // 403 Forbidden
    http_response_code(403);
     include 'error403.php';  // Show a 403 error page
    exit();
}

?>