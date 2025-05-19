<?php
// index.php - Main entry point, redirects to login or dashboard

// It's crucial to include bootstrap here if your session functions rely on it,
// or if login.php itself doesn't robustly handle session startup.
// Assuming login.php or bootstrap.php handles session_start() appropriately.
require_once __DIR__ . '/../sercon/bootstrap.php'; // Path from htdocs to sercon

initializeSession(); // Make sure session is available

if (isLoggedIn()) {
    // If user is logged in, where should they go?
    // To project selection, or their dashboard if a project is already selected.
    if (isset($_SESSION['current_project_id']) && isset($_SESSION['current_project_base_path'])) {
        // Construct dashboard URL for the current project
        // Example: /Fereshteh/dashboard.php
        $dashboard_url = rtrim($_SESSION['current_project_base_path'], '/') . '/admin_panel_search.php';
        header('Location: ' . $dashboard_url);
        exit();
    } else {
        // No project selected, send to project selection page.
        header('Location: /select_project.php'); // Assuming select_project.php is in htdocs root
        exit();
    }
} else {
    // User is not logged in, show the login page.
    // You can either redirect to login.php or directly include its content.
    // Redirecting is often cleaner for URL consistency.
    header('Location: /login.php'); // Assuming login.php is in htdocs root
    exit();
}
