<?php
require_once 'includes/jdf.php';

// Validate input
$production_date = isset($_POST['production_date']) ? trim($_POST['production_date']) : '';
$break_date = isset($_POST['break_date']) ? trim($_POST['break_date']) : '';

// Convert Jalali dates to Gregorian
function jalali_to_gregorian_date($jalali_date) {
    $parts = explode('/', $jalali_date);
    if (count($parts) !== 3) return false;
    
    $j_y = intval($parts[0]);
    $j_m = intval($parts[1]);
    $j_d = intval($parts[2]);
    
    $g_date = jalali_to_gregorian($j_y, $j_m, $j_d);
    return $g_date[0] . '-' . $g_date[1] . '-' . $g_date[2];
}

// Calculate age
function calculate_sample_age($prod_date, $break_date) {
    $prod_g = jalali_to_gregorian_date($prod_date);
    $break_g = jalali_to_gregorian_date($break_date);
    
    if (!$prod_g || !$break_g) return '0';
    
    $prod = new DateTime($prod_g);
    $break = new DateTime($break_g);
    $interval = $prod->diff($break);
    
    return $interval->days;
}

echo calculate_sample_age($production_date, $break_date);