<?php
// date_utils.php

/**
 * Convert Gregorian date to Shamsi (Persian) date
 * @param string $date Date in Y-m-d H:i:s format
 * @return string Shamsi date
 */
function gregorianToShamsi($date) {
    if (empty($date)) return '';
    
    $datetime = new DateTime($date);
    $gregorianDate = $datetime->format('Y-m-d');
    list($gy, $gm, $gd) = explode('-', $gregorianDate);
    
    $jDateArray = gregorian_to_jalali($gy, $gm, $gd);
    
    // Get time component if it exists
    $time = $datetime->format('H:i:s');
    
    // Format the date in Persian
    $months = array(
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند'
    );
    
    return $jDateArray[2] . ' ' . $months[$jDateArray[1]] . ' ' . $jDateArray[0] . ' ' . $time;
}

/**
 * Convert Gregorian date to Jalali date
 */
function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100))
        + ((int)(($gy2 + 399) / 400)) - 80 + $gd + $g_d_m[$gm - 1];
    $jy += 33 * ((int)($days / 12053));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    $jy += (int)(($days - 1) / 365);
    
    if ($days > 365) $days = ($days - 1) % 365;
    
    $jm = ($days < 186) ? 1 + (int)($days / 31) : 7 + (int)(($days - 186) / 30);
    $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
    
    return array($jy, $jm, $jd);
}
if (!function_exists('gregorianToShamsi')) {
    function gregorianToShamsi($g_date)
    {
        if (empty($g_date) || $g_date == '0000-00-00' || $g_date == null || !preg_match('/^\d{4}-\d{2}-\d{2}/', $g_date)) {
            return ''; // Invalid input format or empty
        }
        $date_part = explode(' ', $g_date)[0]; // Take only date part
        list($g_y, $g_m, $g_d) = explode('-', $date_part);
        // Use the existing jdf jalali_to_gregorian internal logic (or your preferred library)
        // This is a placeholder for the actual conversion logic from jdf.php
        // Ensure your included jdf.php has the `jdate` or equivalent functions used below
        if (function_exists('jdate')) {
            // Assuming jdate can format from a timestamp
            $timestamp = strtotime($g_date);
            if ($timestamp === false) return ''; // Invalid date for strtotime
            return jdate('Y/m/d', $timestamp, '', 'Asia/Tehran', 'en'); // 'en' for latin digits output
        } else {
            // Fallback or error if jdate isn't available - your previous manual logic could go here
            error_log("jdate function not found in jdf.php for gregorianToShamsi");
            return ''; // Or implement your manual conversion
        }
    }
}

// Function to convert Shamsi (YYYY/MM/DD) to Gregorian (YYYY-MM-DD)
if (!function_exists('shamsiToGregorian')) {
    function shamsiToGregorian($sh_date)
    {
        if (empty($sh_date) || !preg_match('/^\d{4}\/\d{2}\/\d{2}$/', toLatinDigitsPhp($sh_date))) {
            return ''; // Invalid input format or empty
        }
        $sh_date_latin = toLatinDigitsPhp($sh_date);
        list($sh_y, $sh_m, $sh_d) = explode('/', $sh_date_latin);
        // Use the existing jdf internal logic (or your preferred library)
        if (function_exists('jalali_to_gregorian')) {
            $g_arr = jalali_to_gregorian((int)$sh_y, (int)$sh_m, (int)$sh_d);
            if ($g_arr) {
                return sprintf('%04d-%02d-%02d', $g_arr[0], $g_arr[1], $g_arr[2]);
            } else {
                error_log("jalali_to_gregorian failed for: " . $sh_date);
                return '';
            }
        } else {
            error_log("jalali_to_gregorian function not found in jdf.php");
            return '';
        }
    }
}

// Function to convert Persian/Arabic numerals to Latin (0-9)
if (!function_exists('toLatinDigitsPhp')) {
    function toLatinDigitsPhp($num)
    {
        if ($num === null || !is_scalar($num)) return '';
        return str_replace(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], range(0, 9), strval($num));
    }
}

// Function to convert Latin numerals (0-9) to Farsi
if (!function_exists('toFarsiDigits')) {
    function toFarsiDigits($num)
    {
        if ($num === null) return '';
        $farsi_array   = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $english_array = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($english_array, $farsi_array, (string)$num);
    }
}
?>