<?php
// /ghom/api/get_element_details.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';

// Check for required parameter
if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Element ID is required.']);
    exit();
}

$elementId = $_GET['id'];
$db = getProjectDBConnection('ghom'); // Assumes you have this function in your bootstrap

try {
    $stmt = $db->prepare("SELECT * FROM elements WHERE element_id = ?");
    $stmt->execute([$elementId]);
    $element = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($element) {
        echo json_encode(['status' => 'success', 'data' => $element]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Element not found.']);
    }
} catch (PDOException $e) {
    logError("API Error in get_element_details.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
