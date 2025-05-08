<?php
// public_html/logout.php
require_once __DIR__ . '/../sercon/bootstrap.php'; // Use the new bootstrap

initializeSession(); // Ensure the session is started and available

// --- LOG LOGOUT ACTIVITY ---
// The log_activity function (defined in bootstrap.php) will connect to hpc_common.
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    // For a general logout, the project_id can be null,
    // or if you want to log out from a specific project context, you could pass it.
    // For simplicity, we'll assume general logout.
    $current_project_id_for_log = isset($_SESSION['current_project_id']) ? $_SESSION['current_project_id'] : null;

    log_activity(
        $_SESSION['user_id'],
        $_SESSION['username'],
        'logout', // activity_type
        'User logged out successfully.', // details
        $current_project_id_for_log // project_id (optional, could be null if it's a general system logout)
    );
} else {
    // If user_id or username isn't in session, log an anonymous/incomplete logout if desired.
    // logError("Logout attempt without full session data."); // Using your global logError
}

// --- END LOG ACTIVITY ---

// Unset all of the session variables.
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
// Note: This will destroy the session, not just the session data!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Finally, destroy the session.
session_destroy();

// Redirect to the login page
header('Location: login.php');
exit();
?>