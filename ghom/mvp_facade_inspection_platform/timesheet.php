<?php
// You can add session checks and config includes here if needed for the timesheet later
// require_once __DIR__ . '/../sercon/config.php';
// secureSession();

$pageTitle = 'تقویم کارکرد Timesheet';

// --- Function for date conversion (if needed by PHP parts later, not used by current JS calendar) ---
if (!function_exists('gregorianToShamsi')) {
    function gregorianToShamsi($date)
    { /* ... your function ... */
        if (empty($date) || $date == '0000-00-00') {
            return '';
        }
        try {
            $dt = new DateTime($date);
            $year = (int)$dt->format('Y');
            $month = (int)$dt->format('m');
            $day = (int)$dt->format('d');
            $gDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
            $gy = $year - 1600;
            $gm = $month - 1;
            $gd = $day - 1;
            $gDayNo = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
            $gDayNo += $gDays[$gm];
            if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) {
                $gDayNo++;
            }
            $gDayNo += $gd;
            $jDayNo = $gDayNo - 79;
            $jNp = floor($jDayNo / 12053);
            $jDayNo = $jDayNo % 12053;
            $jy = 979 + 33 * $jNp + 4 * floor($jDayNo / 1461);
            $jDayNo %= 1461;
            if ($jDayNo >= 366) {
                $jy += floor(($jDayNo - 1) / 365);
                $jDayNo = ($jDayNo - 1) % 365;
            }
            $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
            for ($i = 0; $i < 12; $i++) {
                if ($jDayNo < $jDaysInMonth[$i]) break;
                $jDayNo -= $jDaysInMonth[$i];
            }
            $jm = $i + 1;
            $jd = $jDayNo + 1;
            return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        } catch (Exception $e) {
            error_log("Error in gregorianToShamsi for date '$date': " . $e->getMessage());
            return 'تاریخ نامعتبر';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <!-- CSS Includes from your working PHP example -->
    <link href="https://cdn.jsdelivr.net/npm/@fullcalendar/core@5.11.3/main.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/@fullcalendar/daygrid@5.11.3/main.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazir-font@v30.1.0/dist/font-face.css" rel="stylesheet" type="text/css" />

    <!-- Local CSS from your PHP example (ensure paths are correct) -->
    <link href="assets/css/tailwind.min.css" rel="stylesheet">
    <link href="assets/css/fullmain.min.css" rel="stylesheet">
    <link href="assets/css/persian-datepicker.min.css" rel="stylesheet"> <!-- For @majidh1/jalalidatepicker -->

    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/jalalidatepicker.min.css" />
    <!-- Timesheet Calendar Styles (from timesheet.html) -->
    <style>
        /* Paste the full <style> block from the timesheet HTML here, namespaced if necessary */
        body {
            font-family: "Tahoma", "Vazir", sans-serif;
            /* Vazir as fallback from your PHP file */
            /* background-color: #f3f4f6; /* Base from your PHP body */
            /* Keep the timesheet gradient for its container */
        }

        .timesheet-page-container {
            /* Outer container for the whole timesheet page content */
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .ts-container {
            /* Specific container for timesheet elements */
            max-width: 1200px;
            margin: 20px auto;
            /* Added top/bottom margin */
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            overflow: hidden;
        }

        .ts-container .header {
            background: linear-gradient(45deg, #4f46e5, #7c3aed);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }

        .ts-container .header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.1'%3E%3Ccircle cx='7' cy='7' r='7'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") repeat;
        }

        .ts-container .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .ts-container .calendar-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px;
            padding: 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .ts-container .nav-btn {
            background: linear-gradient(45deg, #4f46e5, #7c3aed);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
        }

        .ts-container .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
        }

        .ts-container .month-year {
            font-size: 1.5rem;
            font-weight: bold;
            color: #374151;
        }

        .ts-container .calendar {
            margin: 20px;
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .ts-container .calendar-header {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: linear-gradient(45deg, #4f46e5, #7c3aed);
            color: white;
        }

        .ts-container .day-header {
            padding: 15px;
            text-align: center;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .ts-container .calendar-body {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: #e5e7eb;
        }

        .ts-container .day-cell {
            background: white;
            min-height: 120px;
            padding: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .ts-container .day-cell:hover {
            background: #f8fafc;
            transform: scale(1.02);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .ts-container .day-cell.empty {
            background: #f3f4f6;
            cursor: default;
            opacity: 0.5;
        }

        .ts-container .day-cell.empty:hover {
            transform: none;
            box-shadow: none;
        }

        .ts-container .day-number {
            font-weight: bold;
            font-size: 1.1rem;
            color: #374151;
            margin-bottom: 5px;
        }

        .ts-container .day-info {
            font-size: 0.8rem;
            color: #6b7280;
            flex-grow: 1;
        }

        .ts-container .work-hours {
            background: linear-gradient(45deg, #10b981, #059669);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            margin: 2px 0;
            text-align: center;
        }

        .ts-container .overtime {
            background: linear-gradient(45deg, #f59e0b, #d97706);
            color: white;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.7rem;
        }

        .ts-container .delay {
            background: linear-gradient(45deg, #ef4444, #dc2626);
            color: white;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.7rem;
        }

        .ts-container .modal {
            display: none;
            position: fixed;
            z-index: 1051;
            /* Higher than FullCalendar default, and @majidh1/jalalidatepicker if needed */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }

        .ts-container .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .ts-container .modal h2 {
            color: #374151;
            margin-bottom: 20px;
            text-align: center;
            font-size: 1.5rem;
        }

        .ts-container .form-group {
            margin-bottom: 20px;
        }

        .ts-container .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #374151;
        }

        .ts-container .form-group input,
        .ts-container .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            font-family: "Tahoma", sans-serif;
            transition: all 0.3s ease;
        }

        .ts-container .form-group input[data-jdp] {
            background-color: #fff;
            direction: ltr;
            text-align: right;
        }

        /* For @majidh1/jalalidatepicker */
        .ts-container .form-group input:focus,
        .ts-container .form-group select:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .ts-container .btn-group {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 25px;
        }

        .ts-container .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .ts-container .btn-primary {
            background: linear-gradient(45deg, #4f46e5, #7c3aed);
            color: white;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
        }

        .ts-container .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
        }

        .ts-container .btn-danger {
            background: linear-gradient(45deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .ts-container .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        .ts-container .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .ts-container .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-2px);
        }

        .ts-container .summary-section {
            margin: 20px;
            padding: 25px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .ts-container .summary-section h3 {
            color: #374151;
            margin-bottom: 20px;
            font-size: 1.3rem;
            text-align: center;
        }

        .ts-container .date-range {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .ts-container .date-range .form-group {
            flex-grow: 1;
        }

        .ts-container .date-range button {
            flex-shrink: 0;
            align-self: flex-end;
            margin-bottom: 0;
        }

        .ts-container .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .ts-container .summary-table th,
        .ts-container .summary-table td {
            padding: 15px;
            text-align: center;
            border-bottom: 1px solid #e5e7eb;
        }

        .ts-container .summary-table th {
            background: linear-gradient(45deg, #4f46e5, #7c3aed);
            color: white;
            font-weight: bold;
        }

        .ts-container .summary-table tr:nth-child(even) {
            background: #f8fafc;
        }

        .ts-container .summary-table tr:hover {
            background: #e0f2fe;
            transition: all 0.2s ease;
        }

        .ts-container .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .ts-container .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            transition: transform 0.3s ease;
        }

        .ts-container .stat-card:hover {
            transform: translateY(-5px);
        }

        .ts-container .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .ts-container .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .ts-container .close {
            color: #aaa;
            float: left;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .ts-container .close:hover {
            color: #ef4444;
        }

        @media (max-width: 768px) {
            .ts-container .calendar-body {
                font-size: 0.8rem;
            }

            .ts-container .day-cell {
                min-height: 80px;
                padding: 5px;
            }

            .ts-container .modal-content {
                margin: 10% auto;
                width: 95%;
                padding: 20px;
            }

            .ts-container .date-range {
                flex-direction: column;
                align-items: stretch;
            }

            .ts-container .date-range .form-group,
            .ts-container .date-range button {
                width: 100%;
                margin-bottom: 10px;
            }
        }

        /* Styles from your PHP file's calendar page, if any are relevant beyond FullCalendar defaults */
        body {
            background-color: #f3f4f6;
        }

        /* Base from your PHP file */
        .fc-event {
            cursor: pointer;
        }
    </style>
</head>

<body class="bg-gray-100"> <!-- Class from your PHP file -->

    <?php // require_once 'header.php'; // Your common header if you have one 
    ?>

    <div class="timesheet-page-container">
        <div class="ts-container"> <!-- Timesheet specific container -->
            <div class="header">
                <h1>📅 تقویم کارکرد</h1>
                <p>سیستم مدیریت زمان کاری شما</p>
            </div>
            <div class="calendar-nav">
                <button class="nav-btn" onclick="ts_changeMonth(-1)">← ماه قبل</button>
                <div class="month-year" id="tsMonthYear"></div>
                <button class="nav-btn" onclick="ts_changeMonth(1)">ماه بعد →</button>
            </div>
            <div class="calendar">
                <div class="calendar-header">
                    <div class="day-header">شنبه</div>
                    <div class="day-header">یکشنبه</div>
                    <div class="day-header">دوشنبه</div>
                    <div class="day-header">سه‌شنبه</div>
                    <div class="day-header">چهارشنبه</div>
                    <div class="day-header">پنج‌شنبه</div>
                    <div class="day-header">جمعه</div>
                </div>
                <div class="calendar-body" id="tsCalendarBody"></div>
            </div>
            <div class="summary-section">
                <h3>📊 گزارش کارکرد</h3>
                <div class="date-range">
                    <div class="form-group"><label for="tsStartDate">از تاریخ:</label><input type="text" id="tsStartDate" data-jdp placeholder="YYYY/MM/DD" /></div>
                    <div class="form-group"><label for="tsEndDate">تا تاریخ:</label><input type="text" id="tsEndDate" data-jdp placeholder="YYYY/MM/DD" /></div>
                    <button class="btn btn-primary" onclick="ts_generateReport()">تولید گزارش</button>
                </div>
                <div id="tsReportSection"></div>
            </div>
        </div>
        <div id="tsTimeModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="ts_closeModal()">×</span>
                <h2 id="tsModalTitle">ثبت زمان کاری</h2>
                <form id="tsTimeForm">
                    <div class="form-group"><label for="tsSelectedDate">تاریخ:</label><input type="text" id="tsSelectedDate" readonly /></div>
                    <div class="form-group"><label for="tsStartTime">زمان شروع:</label><input type="time" id="tsStartTime" required /></div>
                    <div class="form-group"><label for="tsEndTime">زمان پایان:</label><input type="time" id="tsEndTime" required /></div>
                    <div class="form-group"><label for="tsStandardHours">ساعات کاری استاندارد:</label><input type="number" id="tsStandardHours" value="8" min="1" max="24" step="0.5" /></div>
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">ذخیره</button>
                        <button type="button" class="btn btn-danger" id="tsDeleteBtn" onclick="ts_deleteRecord()" style="display: none">حذف</button>
                        <button type="button" class="btn btn-secondary" onclick="ts_closeModal()">انصراف</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JS Includes from your PHP example (ensure paths are correct for your new file location) -->
    <script src="assets/js/jquery-3.6.0.min.js"></script>
    <script src="assets/js/plugin-main.min.js"></script> <!-- If this file is important -->
    <script src="assets/js/moment.min.js"></script> <!-- Your trusted local moment -->
    <script src="assets/js/moment-jalaali.js"></script> <!-- Your trusted local moment-jalaali -->
    <script src="assets/js/persian-date.min.js"></script>
    <script src="assets/js/mobile-detect.min.js"></script> <!-- If needed -->

    <!-- FullCalendar JS (from your PHP example, though not directly used by timesheet) -->
    <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/daygrid@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/interaction@5.11.3/main.min.js"></script>

    <!-- @majidh1/jalalidatepicker SCRIPT (for input fields) -->
    <script type="text/javascript" src="https://unpkg.com/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>

    <!-- Main Timesheet Calendar Logic (adapted) -->
    <script>
        // --- Start of Timesheet Calendar JavaScript ---
        window.onerror = function(message, source, lineno, colno, error) {
            console.error("Global error for timesheet:", message, "at", source, lineno, colno, error);
            return true;
        };

        // Use "ts_" prefix for timesheet-specific globals to avoid conflicts
        let ts_currentMoment;
        let ts_currentPersianYear;
        let ts_currentPersianMonth;
        let ts_selectedDateData = null;
        let ts_timeRecords = JSON.parse(localStorage.getItem("timeRecordsTimesheetApp") || "{}"); // Unique key
        const ts_persianMonths = ["فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"];

        let ts_momentJalaaliReady = false;

        // Initialize moment for the timesheet calendar
        // This now runs in an environment with jQuery, persian-date.js etc. already loaded
        if (typeof moment !== 'undefined' && typeof moment.loadPersian === 'function') {
            console.log("Timesheet Page: Attempting moment.loadPersian() and moment.locale('fa')...");
            try {
                // Mimic the call from your working PHP example's formatShamsiClientSide
                moment.loadPersian({
                    usePersianDigits: false,
                    dialect: 'persian-modern'
                });
                moment.locale('fa');

                if (moment.locale() === 'fa') {
                    let testMoment = moment();
                    if (typeof testMoment.jDaysInMonth === 'function' && typeof testMoment.jYear === 'function') {
                        ts_momentJalaaliReady = true;
                        console.log("SUCCESS (Timesheet Page): Moment locale is 'fa' AND jalaali functions are present.");
                    } else {
                        console.error("ERROR (Timesheet Page): Locale set to 'fa', but jalaali functions MISSING. jDaysInMonth:", typeof testMoment.jDaysInMonth, "jYear:", typeof testMoment.jYear);
                    }
                } else {
                    console.error("CRITICAL ERROR (Timesheet Page): Failed to set moment locale to 'fa'. Current locale:", moment.locale());
                }
            } catch (e) {
                console.error("Error during moment.loadPersian() or moment.locale('fa') (Timesheet Page):", e);
            }
        } else {
            console.error("CRITICAL ERROR (Timesheet Page): moment or moment.loadPersian is not defined. Check script paths for local moment.min.js and moment-jalaali.js.");
            if (typeof moment === 'undefined') alert("Local moment.min.js did not load for timesheet!");
            if (typeof moment !== 'undefined' && typeof moment.loadPersian === 'undefined') alert("Local moment-jalaali.js did not load or patch for timesheet!");
        }

        // Timesheet Calendar Functions (prefixed with ts_)
        window.ts_changeMonth = function(direction) {
            if (!ts_currentMoment || typeof ts_currentMoment.add !== 'function') {
                console.error("ts_changeMonth: ts_currentMoment is invalid", ts_currentMoment);
                return;
            }
            if (direction === 1) ts_currentMoment.add(1, 'jMonth');
            else if (direction === -1) ts_currentMoment.subtract(1, 'jMonth');
            ts_currentPersianYear = ts_currentMoment.jYear();
            ts_currentPersianMonth = ts_currentMoment.jMonth();
            ts_generateCalendar();
        };
        window.ts_generateReport = function() {
            /* ... (use ts_timeRecords, and ts_startDate, ts_endDate IDs) ... */
            const startDateJalaliStr = document.getElementById("tsStartDate").value;
            const endDateJalaliStr = document.getElementById("tsEndDate").value;
            if (!startDateJalaliStr || !endDateJalaliStr) {
                alert("لطفاً تاریخ شروع و پایان را برای گزارش انتخاب کنید.");
                return;
            }
            const startMomentJalali = moment(startDateJalaliStr, "jYYYY/jMM/jDD", 'fa').startOf('day');
            const endMomentJalali = moment(endDateJalaliStr, "jYYYY/jMM/jDD", 'fa').endOf('day');
            if (!startMomentJalali.isValid() || !endMomentJalali.isValid()) {
                alert("تاریخ وارد شده برای گزارش معتبر نیست. فرمت مورد انتظار: YYYY/MM/DD");
                return;
            }
            if (endMomentJalali.isBefore(startMomentJalali)) {
                alert("تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد.");
                return;
            }
            const reportSection = document.getElementById("tsReportSection");
            let totalHours = 0,
                totalOvertime = 0,
                totalDelay = 0,
                workDays = 0;
            let tableRowsHtml = "";
            Object.keys(ts_timeRecords).forEach((dateKey_jYYYY_jM_jD) => {
                const record = ts_timeRecords[dateKey_jYYYY_jM_jD];
                const recordMomentJalali = moment(dateKey_jYYYY_jM_jD, "jYYYY-jM-jD", 'fa').startOf('day');
                if (!recordMomentJalali.isValid()) return;
                if (recordMomentJalali.isBetween(startMomentJalali, endMomentJalali, null, "[]")) {
                    totalHours += record.totalHours;
                    totalOvertime += record.overtime;
                    totalDelay += record.delay;
                    workDays++;
                    tableRowsHtml += `<tr><td>${recordMomentJalali.format("jD jMMMM jYYYY")}</td><td>${record.startTime}</td><td>${record.endTime}</td><td>${record.totalHours.toFixed(1)}</td><td>${record.overtime.toFixed(1)}</td><td>${record.delay.toFixed(1)}</td></tr>`;
                }
            });
            reportSection.innerHTML = `<div class="stats-grid"><div class="stat-card"><div class="stat-value">${workDays}</div><div class="stat-label">روز کاری</div></div><div class="stat-card"><div class="stat-value">${totalHours.toFixed(1)}</div><div class="stat-label">کل ساعات کار</div></div><div class="stat-card"><div class="stat-value">${totalOvertime.toFixed(1)}</div><div class="stat-label">اضافه کار</div></div><div class="stat-card"><div class="stat-value">${totalDelay.toFixed(1)}</div><div class="stat-label">تأخیر (کل)</div></div></div><table class="summary-table"><thead><tr><th>تاریخ</th><th>شروع</th><th>پایان</th><th>کل ساعات</th><th>اضافه کار</th><th>تأخیر</th></tr></thead><tbody>${tableRowsHtml || '<tr><td colspan="6">هیچ رکوردی یافت نشد.</td></tr>'}</tbody></table>`;
        };
        window.ts_closeModal = function() {
            document.getElementById("tsTimeModal").style.display = "none";
            ts_selectedDateData = null;
        };
        window.ts_deleteRecord = function() {
            if (ts_selectedDateData && confirm("آیا مطمئن هستید که می‌خواهید این رکورد را حذف کنید؟")) {
                delete ts_timeRecords[ts_selectedDateData.dateKey];
                localStorage.setItem("timeRecordsTimesheetApp", JSON.stringify(ts_timeRecords));
                ts_closeModal();
                ts_generateCalendar();
            }
        };
        window.ts_openTimeModal = function(dayMoment) {
            const modal = document.getElementById("tsTimeModal");
            const year = dayMoment.jYear();
            const monthIndex = dayMoment.jMonth();
            const day = dayMoment.jDate();
            const dateKey = `${year}-${monthIndex + 1}-${day}`;
            const record = ts_timeRecords[dateKey];
            ts_selectedDateData = {
                year: year,
                month: monthIndex + 1,
                day: day,
                dateKey: dateKey
            };
            document.getElementById("tsSelectedDate").value = dayMoment.format("jD jMMMM jYYYY");
            document.getElementById("tsModalTitle").textContent = record ? "ویرایش زمان کاری" : "ثبت زمان کاری";
            if (record) {
                document.getElementById("tsStartTime").value = record.startTime;
                document.getElementById("tsEndTime").value = record.endTime;
                document.getElementById("tsStandardHours").value = record.standardHours;
                document.getElementById("tsDeleteBtn").style.display = "inline-block";
            } else {
                document.getElementById("tsTimeForm").reset();
                document.getElementById("tsSelectedDate").value = dayMoment.format("jD jMMMM jYYYY");
                document.getElementById("tsStandardHours").value = "8";
                document.getElementById("tsDeleteBtn").style.display = "none";
            }
            modal.style.display = "block";
        };

        function ts_generateCalendar() {
            if (!ts_currentMoment || typeof ts_currentMoment.jDaysInMonth !== 'function') {
                console.error("ts_generateCalendar: ts_currentMoment invalid or jDaysInMonth missing.", ts_currentMoment);
                document.getElementById("tsCalendarBody").innerHTML = "<p style='color:red; text-align:center; padding:20px;'>خطا در بارگذاری تقویم کارکرد.</p>";
                document.getElementById("tsMonthYear").textContent = "خطا";
                return;
            }
            const calendarBody = document.getElementById("tsCalendarBody");
            const monthYearDisplay = document.getElementById("tsMonthYear");
            ts_currentMoment.jYear(ts_currentPersianYear).jMonth(ts_currentPersianMonth);
            monthYearDisplay.textContent = `${ts_persianMonths[ts_currentPersianMonth]} ${ts_currentPersianYear}`;
            calendarBody.innerHTML = "";
            const daysInMonth = ts_currentMoment.jDaysInMonth();
            const firstDayOfMonthMoment = ts_currentMoment.clone().jDate(1);
            let firstDayOfWeekOffset = (firstDayOfMonthMoment.day() + 1) % 7;
            for (let i = 0; i < firstDayOfWeekOffset; i++) {
                const emptyCell = document.createElement("div");
                emptyCell.className = "day-cell empty";
                calendarBody.appendChild(emptyCell);
            }
            for (let day = 1; day <= daysInMonth; day++) {
                const dayCell = document.createElement("div");
                dayCell.className = "day-cell";
                const dayMoment = moment().jYear(ts_currentPersianYear).jMonth(ts_currentPersianMonth).jDate(day);
                dayCell.onclick = () => ts_openTimeModal(dayMoment);
                const dateKey = `${ts_currentPersianYear}-${ts_currentPersianMonth + 1}-${day}`;
                const record = ts_timeRecords[dateKey];
                dayCell.innerHTML = `<div class="day-number">${day}</div><div class="day-info">${record ? `<div class="work-hours">${record.totalHours.toFixed(1)} ساعت</div>${record.overtime > 0 ? `<div class="overtime">اضافه: ${record.overtime.toFixed(1)}س</div>` : ""}${record.delay > 0 ? `<div class="delay">تأخیر: ${record.delay.toFixed(1)}س</div>` : ""}` : ""}</div>`;
                if (record) dayCell.style.background = "linear-gradient(135deg, #e0f2fe 0%, #b3e5fc 100%)";
                calendarBody.appendChild(dayCell);
            }
        }

        function ts_calculateHours(startTimeStr, endTimeStr, standardHoursNum) {
            const start = new Date(`2000-01-01T${startTimeStr}:00`);
            const end = new Date(`2000-01-01T${endTimeStr}:00`);
            if (end < start) end.setDate(end.getDate() + 1);
            const diffMilliseconds = end - start;
            const totalHours = diffMilliseconds / (1000 * 60 * 60);
            const overtime = Math.max(0, totalHours - standardHoursNum);
            return {
                totalHours,
                overtime,
                delay: 0,
                startTime: startTimeStr,
                endTime: endTimeStr,
                standardHours: parseFloat(standardHoursNum)
            };
        }

        document.getElementById("tsTimeForm").addEventListener("submit", function(e) {
            e.preventDefault();
            if (!ts_selectedDateData) return;
            const startTime = document.getElementById("tsStartTime").value;
            const endTime = document.getElementById("tsEndTime").value;
            const standardHours = parseFloat(document.getElementById("tsStandardHours").value);
            if (!startTime || !endTime || isNaN(standardHours) || standardHours <= 0) {
                alert("لطفاً تمام فیلدها را با مقادیر معتبر پر کنید.");
                return;
            }
            const calculatedData = ts_calculateHours(startTime, endTime, standardHours);
            ts_timeRecords[ts_selectedDateData.dateKey] = calculatedData;
            localStorage.setItem("timeRecordsTimesheetApp", JSON.stringify(ts_timeRecords));
            ts_closeModal();
            ts_generateCalendar();
        });

        // --- DOMContentLoaded for Timesheet ---
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Timesheet Page DOMContentLoaded: Starting setup...");

            if (ts_momentJalaaliReady) { // Check flag from the script block's early execution
                console.log("Timesheet Page DOMContentLoaded: moment-jalaali confirmed ready.");
                ts_currentMoment = moment();
                ts_currentPersianYear = ts_currentMoment.jYear();
                ts_currentPersianMonth = ts_currentMoment.jMonth();
                ts_generateCalendar();
            } else {
                console.error("Timesheet Page DOMContentLoaded: Calendar setup aborted. moment-jalaali was not ready.");
                document.getElementById("tsCalendarBody").innerHTML = "<p style='color:red; text-align:center; padding:20px;'>خطای بحرانی: تقویم شمسی بارگذاری نشد.</p>";
                document.getElementById("tsMonthYear").textContent = "خطا";
            }

            // Initialize @majidh1/jalalidatepicker for input fields
            setTimeout(function() {
                try {
                    if (typeof jalaliDatepicker !== 'undefined') {
                        console.log("Timesheet Page: Initializing @majidh1/jalalidatepicker inputs...");
                        jalaliDatepicker.startWatch({
                            persianDigits: true,
                            showTodayBtn: true,
                            showEmptyBtn: true,
                            autoReadOnlyInput: true,
                            months: ts_persianMonths,
                            days: ["ش", "ی", "د", "س", "چ", "پ", "ج"],
                            selector: '#tsStartDate, #tsEndDate' // Target only timesheet datepickers
                        });
                        // Set default dates for report inputs
                        // Ensure moment() here uses the 'fa' locale correctly set up earlier
                        const todayJalaliForPicker = moment().format("jYYYY/jMM/jDD");
                        const firstDayOfMonthJalaliForPicker = moment().startOf('jMonth').format("jYYYY/jMM/jDD");

                        const startDateInput = document.getElementById("tsStartDate");
                        const endDateInput = document.getElementById("tsEndDate");
                        if (startDateInput) startDateInput.value = firstDayOfMonthJalaliForPicker;
                        if (endDateInput) endDateInput.value = todayJalaliForPicker;
                        console.log("Timesheet Page: @majidh1/jalalidatepicker inputs initialized.");
                    } else {
                        console.error("Timesheet Page: jalaliDatepicker for inputs is not defined!");
                    }
                } catch (dpError) {
                    console.error("Timesheet Page: Error initializing @majidh1/jalidatepicker for inputs:", dpError);
                }
            }, 250); // Slightly increased delay
        });
        // --- End of Timesheet Calendar JavaScript ---
    </script>

    <!-- Your main.min.js from PHP file, if it contains other global logic -->
    <!-- <script src="js/main.min.js"></script> -->
</body>

</html>