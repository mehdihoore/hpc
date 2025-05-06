<?php
// api/get-destinations.php
$config_path = __DIR__ . '/../../../sercon/config.php';
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    die('Config file not found.');
}
require_once '../includes/functions.php';

secureSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superuser')) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

try {
    $pdo = connectDB();

    // Get distinct destinations from the DESTINATIONS table
    $stmt = $pdo->query("SELECT name FROM destinations ORDER BY name");
    $destinations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['success' => true, 'destinations' => $destinations]);

} catch (PDOException $e) {
    logError("Database error in get-destinations.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>