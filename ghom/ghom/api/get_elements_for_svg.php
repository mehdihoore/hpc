<?php
// /ghom/api/get_elements_for_svg.php

header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

// Get the requested SVG filename from the query parameter
$svgFile = filter_input(INPUT_GET, 'svg_file', FILTER_SANITIZE_STRING);
if (empty($svgFile)) {
    echo json_encode(['error' => 'SVG file name is required.']);
    exit();
}

$db = getProjectDBConnection('ghom');

try {
    // Select all necessary data for the elements belonging to the specified SVG file
    $stmt = $db->prepare(
        "SELECT element_id, element_type, x_coord, y_coord, panel_orientation 
         FROM elements 
         WHERE svg_file_name = ?"
    );
    $stmt->execute([$svgFile]);
    $elements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $elements]);
} catch (PDOException $e) {
    logError("API Error in get_elements_for_svg.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
