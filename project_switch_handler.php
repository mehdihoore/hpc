<?php
// public_html/project_switch_handler.php
require_once __DIR__ . '/../sercon/bootstrap.php';

secureSession(); // Start/resume session and apply security

if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_to_project_id'])) {
    // CSRF Check
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        logError("CSRF token mismatch in project_switch_handler.php. User ID: " . $_SESSION['user_id']);
        header('Location: /select_project.php?msg=csrf_error'); // Redirect with error
        exit();
    }

    $switch_to_project_id = filter_input(INPUT_POST, 'switch_to_project_id', FILTER_VALIDATE_INT);
    $user_id = $_SESSION['user_id'];

    if ($switch_to_project_id) {
        try {
            $pdo_common = getCommonDBConnection();
            // Verify user has access to this project and get project details
            $stmt = $pdo_common->prepare("
                SELECT p.project_id, p.project_name, p.project_code, p.config_key, p.ro_config_key, p.base_path
                FROM projects p
                JOIN user_projects up ON p.project_id = up.project_id
                WHERE up.user_id = ? AND p.project_id = ? AND p.is_active = TRUE
            ");
            $stmt->execute([$user_id, $switch_to_project_id]);
            $project_to_switch_to = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($project_to_switch_to) {
                // Update session variables for the new project
                $_SESSION['current_project_id'] = $project_to_switch_to['project_id'];
                $_SESSION['current_project_name'] = $project_to_switch_to['project_name'];
                $_SESSION['current_project_code'] = $project_to_switch_to['project_code'];
                $_SESSION['current_project_config_key'] = $project_to_switch_to['config_key'];
                $_SESSION['current_project_ro_config_key'] = $project_to_switch_to['ro_config_key'];
                $_SESSION['current_project_base_path'] = $project_to_switch_to['base_path'];

                log_activity(
                    $user_id,
                    $_SESSION['username'],
                    'project_switch',
                    "Switched to project: " . $project_to_switch_to['project_name'],
                    $project_to_switch_to['project_id']
                );

                // Regenerate CSRF token after successful switch and session update
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                // Redirect to the dashboard of the new project
                $redirect_url = rtrim($project_to_switch_to['base_path'], '/') . '/admin_panel_search.php'; // Or a role-based page
                header('Location: ' . $redirect_url);
                exit();
            } else {
                logError("User ID {$user_id} attempted to switch to unauthorized/inactive project ID: {$switch_to_project_id}");
                header('Location: /select_project.php?msg=switch_denied');
                exit();
            }
        } catch (Exception $e) {
            logError("Error in project_switch_handler.php: " . $e->getMessage());
            header('Location: /select_project.php?msg=switch_error');
            exit();
        }
    }
}

// Fallback redirect if POST data is incorrect or direct access
header('Location: /select_project.php');
exit();
