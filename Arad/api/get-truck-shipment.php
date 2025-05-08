<?php
// api/get-truck-shipment.php

// Adjust the paths based on the location of this 'api' folder
// If 'api' is directly inside your project root:
$config_path = __DIR__ . '/../../../sercon/config_fereshteh.php';
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    die('Config file not found.');
}
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/jdf.php';

secureSession(); // Start or resume session securely

header('Content-Type: application/json'); // Set response type

// --- Response Structure ---
$response = [
    'success' => false,
    'message' => 'An error occurred.',
    'shipment' => null,
    'default_destination' => null // To send truck's default destination if no shipment exists
];

// --- Authentication Check ---
// You might want more specific role checks depending on your security needs
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Authentication required.';
    echo json_encode($response);
    exit();
}

// --- Input Validation ---
if (!isset($_GET['truck_id']) || !filter_var($_GET['truck_id'], FILTER_VALIDATE_INT)) {
    $response['message'] = 'Invalid or missing truck ID.';
    echo json_encode($response);
    exit();
}
$truckId = (int)$_GET['truck_id'];

try {
    $pdo = connectDB(); // Use your database connection function

    // --- Database Query ---
    // Fetch the latest shipment details for the given truck ID
    // Also fetch the truck's default destination as a fallback
    $sql = "SELECT
                s.id,                 -- Shipment ID
                s.packing_list_number,
                s.shipping_date,
                s.shipping_time,
                s.destination AS shipment_destination,
                s.status,
                s.notes,
                s.stands_sent,        -- <<< ADDED THIS
                s.stands_returned,    -- <<< ADDED THIS (for future use)
                s.stands_return_date, -- <<< ADDED THIS (for future use)
                t.destination AS truck_default_destination
            FROM trucks t
            LEFT JOIN shipments s ON t.id = s.truck_id
            WHERE t.id = :truck_id
            ORDER BY s.created_at DESC, s.id DESC -- Get the most recent shipment
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':truck_id', $truckId, PDO::PARAM_INT);
    $stmt->execute();

    $result = $stmt->fetch(PDO::FETCH_ASSOC);


    if ($result && $result['id'] !== null) { // Check if a shipment record was actually joined and found
        $shipment = [
            'id' => $result['id'],
            'packing_list_number' => $result['packing_list_number'],
            'shipping_date' => $result['shipping_date'],
            'shipping_time' => null, // Initialize
            'destination' => $result['shipment_destination'] ?? $result['truck_default_destination'], // Prioritize shipment destination
            'status' => $result['status'],
            'stands_sent' => isset($result['stands_sent']) ? (int)$result['stands_sent'] : 0, // Default to 0 if null
            'stands_returned' => isset($result['stands_returned']) ? (int)$result['stands_returned'] : 0,
            'stands_return_date' => $result['stands_return_date'], // Keep as is (could be null)
            'notes' => $result['notes'],
            'shipping_date_persian' => '', // Initialize
            'stands_return_date_persian' => ''
        ];

        // --- Data Processing ---

        // Format Persian Date
        if (!empty($shipment['shipping_date'])) {
            try {
                // Assumes shipping_date is stored as YYYY-MM-DD
                list($gy, $gm, $gd) = explode('-', $shipment['shipping_date']);
                // Convert Gregorian to Persian using jdf
                // Ensure jdf function is loaded correctly and handles potential errors
                $shipment['shipping_date_persian'] = jdate('Y/m/d', mktime(0, 0, 0, $gm, $gd, $gy), '', 'Asia/Tehran', 'fa');
            } catch (Exception $e) {
                // Log error if date conversion fails
                logError("Error formatting Persian date for shipment ID {$shipment['id']} (Truck {$truckId}): " . $e->getMessage());
                // Keep Gregorian date as fallback for display if conversion fails
                $shipment['shipping_date_persian'] = $shipment['shipping_date'];
            }
        }
        if (!empty($shipment['stands_return_date'])) {
            try {
                list($gy, $gm, $gd) = explode('-', $shipment['stands_return_date']);
                $shipment['stands_return_date_persian'] = jdate('Y/m/d', mktime(0, 0, 0, $gm, $gd, $gy), '', 'Asia/Tehran', 'fa');
            } catch (Exception $e) {
                logError("Error formatting Persian date for stands_return_date (Shipment ID {$shipment['id']}): " . $e->getMessage());
                $shipment['stands_return_date_persian'] = $shipment['stands_return_date']; // Fallback to Gregorian date
            }
        }

        // Format Time (e.g., ensure HH:MM format if seconds are stored)
        if (!empty($result['shipping_time'])) {
            $timeParts = explode(':', $result['shipping_time']);
            if (count($timeParts) >= 2) {
                $shipment['shipping_time'] = str_pad($timeParts[0], 2, '0', STR_PAD_LEFT) . ':' . str_pad($timeParts[1], 2, '0', STR_PAD_LEFT);
            } else {
                $shipment['shipping_time'] = $result['shipping_time']; // Keep original if format is unexpected
            }
        }


        $response['success'] = true;
        $response['shipment'] = $shipment; // Assign the processed shipment data
        unset($response['message']); // Clear the default error message

    } else if ($result) { // Truck exists, but no shipment yet
        $response['success'] = true; // Still a successful operation
        $response['shipment'] = null; // Indicate no shipment found
        $response['message'] = 'No existing shipment found for this truck.';
        $response['default_destination'] = $result['truck_default_destination']; // Provide truck's destination
    } else {
        // Truck ID itself was not found
        $response['success'] = false;
        $response['message'] = 'Truck not found.';
    }
} catch (PDOException $e) {
    logError("Database error in get-truck-shipment.php for truck_id {$truckId}: " . $e->getMessage()); // Use your logging function
    $response['message'] = 'Database error while fetching shipment details.'; // Keep messages generic for the client
} catch (Exception $ex) {
    logError("General error in get-truck-shipment.php for truck_id {$truckId}: " . $ex->getMessage());
    $response['message'] = 'An unexpected error occurred.';
}

// --- Output JSON ---
echo json_encode($response);
exit();
