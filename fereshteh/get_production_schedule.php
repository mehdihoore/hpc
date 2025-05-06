<?php
// get_production_schedule.php
require_once 'config_fereshteh.php';
require_once 'date_utils.php';
require_once 'includes/jdf.php';

header('Content-Type: application/json');

try {
    $pdo = connectDB();
    
    $date = $_GET['date'] ?? date('Y-m-d');
    
    $query = "
        SELECT 
            po.*,
            hp.address as panel_address,
            hp.assigned_date
        FROM polystyrene_orders po
        JOIN hpc_panels hp ON po.hpc_panel_id = hp.id
        WHERE DATE(po.production_start_date) <= ?
        AND DATE(po.production_end_date) >= ?
        ORDER BY po.required_delivery_date ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$date, $date]);
    
    $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert dates to Shamsi
    foreach ($schedule as &$item) {
        $delivery_date = explode('-', $item['required_delivery_date']);
        $shamsi_date = gregorian_to_jalali(
            intval($delivery_date[0]),
            intval($delivery_date[1]),
            intval($delivery_date[2])
        );
        $item['delivery_date'] = implode('/', $shamsi_date);
    }
    
    echo json_encode($schedule);
} catch (Exception $e) {
    logError("Error in get_production_schedule.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'خطا در دریافت برنامه تولید']);
}
?>