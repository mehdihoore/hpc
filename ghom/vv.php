<?php
// Example PHP file to handle element IDs from your JavaScript

// Get element_id from URL parameters or POST data
$element_id = '';
$element_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $element_id = $_GET['element_id'] ?? '';
    $element_type = $_GET['element_type'] ?? '';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $element_id = $_POST['elementId'] ?? $_POST['gfrcElementId_hidden'] ?? '';
    $element_type = $_POST['elementType'] ?? $_POST['gfrcElementType_hidden'] ?? '';
}

// Parse the element ID to understand its structure
function parseElementId($element_id, $element_type)
{
    $parsed = [
        'original_id' => $element_id,
        'base_id' => $element_id,
        'group_id' => '',
        'index' => null,
        'part_name' => null,
        'is_generated' => false
    ];

    // For GFRC elements, check if it has a part name suffix
    if ($element_type === 'GFRC' && strpos($element_id, '-') !== false) {
        $parts = explode('-', $element_id);
        $parsed['part_name'] = array_pop($parts); // Last part is the part name
        $parsed['base_id'] = implode('-', $parts); // Everything before the last dash
    }

    // Check if it's a generated ID pattern: groupId_index or groupId_elem_index
    if (preg_match('/^(.+)_elem_(\d+)$/', $parsed['base_id'], $matches)) {
        $parsed['group_id'] = $matches[1];
        $parsed['index'] = (int)$matches[2];
        $parsed['is_generated'] = true;
    } elseif (preg_match('/^(.+)_(\d+)$/', $parsed['base_id'], $matches)) {
        $parsed['group_id'] = $matches[1];
        $parsed['index'] = (int)$matches[2];
        $parsed['is_generated'] = true;
    }

    return $parsed;
}

// Example usage
$parsed_id = parseElementId($element_id, $element_type);

// Database query examples
function findElementInDatabase($element_id, $element_type)
{
    // Assuming you have a database connection $pdo
    global $pdo;

    try {
        // First, try to find exact match
        $stmt = $pdo->prepare("
            SELECT * FROM inspection_items 
            WHERE element_id = ? AND element_type = ?
        ");
        $stmt->execute([$element_id, $element_type]);
        $result = $stmt->fetchAll();

        if (!empty($result)) {
            return $result;
        }

        // If no exact match and it's a GFRC with part name, try base ID
        $parsed = parseElementId($element_id, $element_type);
        if ($element_type === 'GFRC' && $parsed['part_name']) {
            $stmt = $pdo->prepare("
                SELECT * FROM inspection_items 
                WHERE element_id LIKE ? AND element_type = ?
            ");
            $stmt->execute([$parsed['base_id'] . '%', $element_type]);
            return $stmt->fetchAll();
        }

        return [];
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return [];
    }
}

// Create database record for new element
function createElementRecord($element_id, $element_type, $additional_data = [])
{
    global $pdo;

    $parsed = parseElementId($element_id, $element_type);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO inspections (
                element_id, 
                element_type, 
                zone_name, 
                axis_span, 
                floor_level, 
                contractor, 
                block, 
                plan_file,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $element_id,
            $element_type,
            $additional_data['zoneName'] ?? '',
            $additional_data['axisSpan'] ?? '',
            $additional_data['floorLevel'] ?? '',
            $additional_data['contractor'] ?? '',
            $additional_data['block'] ?? '',
            $additional_data['planFile'] ?? ''
        ]);

        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

// Example API endpoint logic
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['element_id'])) {
    header('Content-Type: application/json');

    $element_data = findElementInDatabase($element_id, $element_type);
    $parsed_id = parseElementId($element_id, $element_type);

    echo json_encode([
        'status' => 'success',
        'element_id' => $element_id,
        'element_type' => $element_type,
        'parsed_id' => $parsed_id,
        'items' => $element_data,
        'debug_info' => [
            'is_generated_id' => $parsed_id['is_generated'],
            'base_id' => $parsed_id['base_id'],
            'part_name' => $parsed_id['part_name'],
            'group_id' => $parsed_id['group_id']
        ]
    ]);
    exit;
}

// Example of handling form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process the inspection data
    $items_json = $_POST['items'] ?? '';
    $items = json_decode($items_json, true);

    // Save to database
    $inspection_id = createElementRecord($element_id, $element_type, $_POST);

    if ($inspection_id) {
        // Save individual checklist items
        foreach ($items as $item) {
            // Save each checklist item to database
            // ... your item saving logic here
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'تفتیش با موفقیت ذخیره شد',
            'inspection_id' => $inspection_id,
            'element_id' => $element_id
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'خطا در ذخیره‌سازی'
        ]);
    }
    exit;
}

// Debug output for testing
if (isset($_GET['debug'])) {
    echo "<h2>Element ID Debug Information</h2>";
    echo "<p><strong>Element ID:</strong> " . htmlspecialchars($element_id) . "</p>";
    echo "<p><strong>Element Type:</strong> " . htmlspecialchars($element_type) . "</p>";

    if ($element_id) {
        $parsed = parseElementId($element_id, $element_type);
        echo "<h3>Parsed ID Information:</h3>";
        echo "<pre>" . print_r($parsed, true) . "</pre>";
    }
}
