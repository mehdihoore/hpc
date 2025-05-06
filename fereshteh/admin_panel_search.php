<?php
// admin_panel_search.php
require_once __DIR__ . '/../../sercon/config_fereshteh.php';

// Role check (Admin or Supervisor)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'supervisor', 'planner', 'user'])) {
    header('Location: unauthorized.php');
    exit();
}
secureSession();

// --- Function for Shamsi Date Conversion  ---
if (!function_exists('gregorianToShamsi')) {
    function gregorianToShamsi($date)
    {
        if (empty($date) || $date == '0000-00-00') return '';
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
            if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) $gDayNo++;
            $gDayNo += $gd;
            $jDayNo = $gDayNo - 79;
            $jNp = floor($jDayNo / 12053);
            $jDayNo %= 12053;
            $jy = 979 + 33 * $jNp + 4 * floor($jDayNo / 1461);
            $jDayNo %= 1461;
            if ($jDayNo >= 366) {
                $jy += floor(($jDayNo - 1) / 365);
                $jDayNo = ($jDayNo - 1) % 365;
            }
            $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
            for ($i = 0; $i < 12 && $jDayNo >= $jDaysInMonth[$i]; $i++) $jDayNo -= $jDaysInMonth[$i];
            $jm = $i + 1;
            $jd = $jDayNo + 1;
            return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        } catch (Exception $e) {
            return 'تاریخ نامعتبر';
        }
    }
}

// --- Database Operations ---
try {
    $pdo = connectDB(); // Use your existing connection function

    // --- Fetch All Panels ---
    // 1. Select new columns: planned_finish_date, Proritization
    // 2. Simplify formwork join: Assuming hp.formwork_type stores the TYPE NAME
    $stmt = $pdo->prepare("
        SELECT
            hp.*
            
       FROM hpc_panels hp

        ORDER BY hp.Proritization ASC, hp.id DESC 
    ");
    $stmt->execute();
    $allPanels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Fetch Distinct Prioritization Levels ---
    $stmtPrio = $pdo->query("SELECT DISTINCT Proritization FROM hpc_panels WHERE Proritization IS NOT NULL AND Proritization != '' ORDER BY Proritization ASC");
    $prioritizationLevels = $stmtPrio->fetchAll(PDO::FETCH_COLUMN);

    // --- Fetch Formwork Types for Filter  ---
    $stmtFormwork = $pdo->query("SELECT formwork_type FROM available_formworks ORDER BY formwork_type ASC");
    $formworkTypeNames = $stmtFormwork->fetchAll(PDO::FETCH_COLUMN);

    $uniquePanelAddresses = array_unique(array_column($allPanels, 'address'));
    $uniqueCompletedPanelAddresses = array_unique(array_column(array_filter($allPanels, fn($p) => $p['status'] == 'completed'), 'address'));
    $uniqueAssignedDatePanelAddresses = array_unique(array_column(array_filter($allPanels, fn($p) => !empty($p['assigned_date'])), 'address'));
    $uniqushippedAddresses = array_unique(array_column(array_filter($allPanels, fn($p) => $p['packing_status'] == 'shipped'), 'address'));
} catch (PDOException $e) {
    logError("Database error in admin_panel_search.php: " . $e->getMessage()); // Log the error
    die("خطای پایگاه داده رخ داد. لطفا با پشتیبانی تماس بگیرید."); // User-friendly message
}

// --- HTML Starts Here ---
$pageTitle = "جستجو و فیلتر پنل ها";
require_once 'header.php'; // Include header
?>

<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="al-1 mb-4">جهت مشاهده جزییات روی لینک آبی (نام پنل) کلیک کنید</div>

        <!-- Filter Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 mb-8">

            <!-- Search -->
            <div class="bg-white p-4 rounded-lg shadow-md">
                <label for="searchInput" class="block text-sm font-medium text-gray-700 mb-1">جستجو (آدرس)</label>
                <input type="text" id="searchInput" class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-400" placeholder="بخشی از آدرس...">
            </div>

            <!-- Prioritization Filter -->
            <div class="bg-white p-4 rounded-lg shadow-md">
                <label for="prioritizationFilter" class="block text-sm font-medium text-gray-700 mb-1">اولویت </label>
                <select id="prioritizationFilter" class="w-full p-2 border rounded">
                    <option value="">همه اولویت‌ها</option>
                    <?php foreach ($prioritizationLevels as $prio): ?>
                        <option value="<?php echo htmlspecialchars($prio); ?>" <?php echo ($prio === 'P1') ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($prio); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status Filter -->
            <div class="bg-white p-4 rounded-lg shadow-md">
                <label for="statusFilter" class="block text-sm font-medium text-gray-700 mb-1">وضعیت</label>
                <select id="statusFilter" class="w-full p-2 border rounded">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="pending">در انتظار تخصیص</option>
                    <option value="planned">برنامه ریزی شده</option>
                    <option value="polystyrene">قالب فوم</option>
                    <option value="mesh">مش بندی</option>
                    <option value="concreting">قالب‌بندی/بتن ریزی</option>
                    <option value="assembly">فیس کوت</option>
                    <option value="completed">تکمیل شده</option>
                    <option value="shipped">ارسال شده</option>
                </select>
            </div>

            <!-- Formwork Type Filter -->
            <div class="bg-white p-4 rounded-lg shadow-md">
                <label for="formworkFilter" class="block text-sm font-medium text-gray-700 mb-1">نوع قالب</label>
                <select id="formworkFilter" class="w-full p-2 border rounded">
                    <option value="">همه انواع</option>
                    <?php foreach ($formworkTypeNames as $ftName): ?>
                        <option value="<?php echo htmlspecialchars($ftName); ?>">
                            <?php echo htmlspecialchars($ftName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Assigned Date Filter -->
            <div class="bg-white p-4 rounded-lg shadow-md">
                <label class="block text-sm font-medium text-gray-700 mb-1">تاریخ شروع (Assigned)</label>
                <div class="flex space-x-2 space-x-reverse">
                    <input type="text" id="assignedStartDateFilter" class="w-1/2 p-2 border rounded persian-datepicker" placeholder="از تاریخ">
                    <input type="text" id="assignedEndDateFilter" class="w-1/2 p-2 border rounded persian-datepicker" placeholder="تا تاریخ">
                </div>
            </div>

            <!-- Planned Finish Date Filter -->
            <div class="bg-white p-4 rounded-lg shadow-md">
                <label class="block text-sm font-medium text-gray-700 mb-1">تاریخ پایان (Planned)</label>
                <div class="flex space-x-2 space-x-reverse">
                    <input type="text" id="plannedStartDateFilter" class="w-1/2 p-2 border rounded persian-datepicker" placeholder="از تاریخ">
                    <input type="text" id="plannedEndDateFilter" class="w-1/2 p-2 border rounded persian-datepicker" placeholder="تا تاریخ">
                </div>
            </div>

            <!-- Length Filter -->
            <div class="bg-white p-4 rounded-lg shadow-md">
                <label class="block text-sm font-medium text-gray-700 mb-1">طول (mm)</label>
                <div class="flex space-x-2 space-x-reverse">
                    <input type="number" id="minLengthFilter" class="w-1/2 p-2 border rounded" placeholder="حداقل">
                    <input type="number" id="maxLengthFilter" class="w-1/2 p-2 border rounded" placeholder="حداکثر">
                </div>
            </div>

            <!-- Width Filter -->
            <div class="bg-white p-4 rounded-lg shadow-md">
                <label class="block text-sm font-medium text-gray-700 mb-1">عرض (mm)</label>
                <div class="flex space-x-2 space-x-reverse">
                    <input type="number" id="minWidthFilter" class="w-1/2 p-2 border rounded" placeholder="حداقل">
                    <input type="number" id="maxWidthFilter" class="w-1/2 p-2 border rounded" placeholder="حداکثر">
                </div>
            </div>

        </div>

        <!-- Results Count -->
        <div class="mb-4 text-lg font-semibold text-gray-700">
            تعداد نتایج: <span id="resultsCountNumber">0</span>
        </div>


        <!-- Results Container -->
        <div id="resultsContainer" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Panels will be displayed here by JavaScript -->
        </div>
    </div>



    <link rel="stylesheet" href="assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="assets/css/persianDatepicker-dark.css">
    <!-- Include Datepicker and Moment JS -->
    <script src="assets/js/jquery-3.6.0.min.js"></script>

    <script src="assets/js/moment.min.js"></script>
    <script src="assets/js/moment-jalaali.js"></script>
    <script src="assets/js/persian-datepicker.min.js"></script>
    <script src="mobile-detect.min.js"></script>
    <!-- <script src="main.min.js"></script> --> <!-- Only if it contains generic functions -->

    <script>
        const allPanels = <?php echo json_encode($allPanels); ?>;
        let filteredPanels = []; // Start with empty or all? Let's filter initially.

        // --- DOM Element References ---
        const searchInput = document.getElementById('searchInput');
        const prioritizationFilter = document.getElementById('prioritizationFilter');
        const formworkFilter = document.getElementById('formworkFilter');
        const statusFilter = document.getElementById('statusFilter');
        const assignedStartDateFilter = document.getElementById('assignedStartDateFilter');
        const assignedEndDateFilter = document.getElementById('assignedEndDateFilter');
        const plannedStartDateFilter = document.getElementById('plannedStartDateFilter');
        const plannedEndDateFilter = document.getElementById('plannedEndDateFilter');
        const minLengthFilter = document.getElementById('minLengthFilter');
        const maxLengthFilter = document.getElementById('maxLengthFilter');
        const minWidthFilter = document.getElementById('minWidthFilter');
        const maxWidthFilter = document.getElementById('maxWidthFilter');
        const resultsContainer = document.getElementById('resultsContainer');
        const resultsCountNumber = document.getElementById('resultsCountNumber');

        // --- Filter State Variables ---
        let assignedStartDateGregorian = null;
        let assignedEndDateGregorian = null;
        let plannedStartDateGregorian = null;
        let plannedEndDateGregorian = null;

        // --- Helper: Client-side Shamsi Formatting ---
        function formatShamsi(gregorianDate) {
            if (!gregorianDate || gregorianDate === '0000-00-00') return '';
            try {
                if (typeof moment === 'function' && typeof moment.loadPersian === 'function') {
                    moment.loadPersian({
                        usePersianDigits: false,
                        dialect: 'persian-modern'
                    });
                    // Handle potential datetime format from DB (take only date part)
                    const datePart = gregorianDate.split(' ')[0];
                    return moment(datePart, 'YYYY-MM-DD').format('jYYYY/jMM/jDD');
                }
                return gregorianDate; // Fallback
            } catch (e) {
                return 'تاریخ نامعتبر';
            }
        }

        function getPanelStatusKey(panel) {
            // Priority 1: Shipped overrides everything
            if (panel.packing_status && panel.packing_status.toLowerCase() === 'shipped') {
                return 'shipped';
            }

            // Priority 2: Check for valid assigned_date
            // A panel is NOT pending if it has a valid assigned date.
            const hasAssignedDate = panel.assigned_date && panel.assigned_date !== '0000-00-00' && !panel.assigned_date.startsWith('0000-00-00');

            if (!hasAssignedDate) {
                // If no assigned date, it's strictly Pending (regardless of panel.status)
                return 'pending';
            }

            // --- At this point, we KNOW assigned_date is valid ---

            const currentStatus = panel.status ? panel.status.toLowerCase() : null;

            // Priority 3: Assigned date exists, but no specific production status yet (null, empty, or 'pending') -> It's Planned
            if (!currentStatus || currentStatus === 'pending') {
                return 'planned';
            }

            // Priority 4: Check for known production/completion statuses (only if assigned date exists)
            switch (currentStatus) {
                case 'polystyrene':
                    return 'polystyrene';
                case 'mesh':
                    return 'mesh';
                case 'concreting':
                    return 'concreting';
                case 'assembly':
                    return 'assembly';
                case 'completed':
                    return 'completed';
                case 'panel.status':
                    return 'completed'; // Treat legacy value as completed
                default:
                    // Has assigned date, but status is unrecognized. Treat as Planned for now.
                    // console.warn(`Unrecognized panel status '${panel.status}' for panel ID ${panel.id} with assigned date. Defaulting key to 'planned'.`);
                    return 'planned';
            }
        }
        // --- Display Results Function ---
        function displayResults(panels) {
            resultsContainer.innerHTML = panels.map(panel => {
                // --- Status Calculation ---
                const statusKey = getPanelStatusKey(panel); // Get the determined status key

                // --- Maps for Text, Text Color, and BACKGROUND Color ---
                const statusTextMap = {
                    'pending': 'در انتظار تخصیص',
                    'planned': 'برنامه ریزی شده',
                    'polystyrene': 'قالب فوم',
                    'assembly': 'فیس کوت',
                    'mesh': 'مش بندی',
                    'concreting': 'قالب‌بندی/بتن ریزی',
                    'completed': 'تکمیل شده',
                    'shipped': 'ارسال شده'
                };
                const statusColorMap = { // For text color
                    'pending': 'text-yellow-700', // Adjusted for contrast on lighter bg
                    'planned': 'text-cyan-700', // Adjusted for contrast
                    'polystyrene': 'text-slate-700', // Adjusted for contrast
                    'assembly': 'text-red-700', // Adjusted for contrast
                    'mesh': 'text-orange-700', // Adjusted for contrast
                    'concreting': 'text-purple-700', // Adjusted for contrast
                    'completed': 'text-green-700', // Adjusted for contrast
                    'shipped': 'text-blue-700', // Adjusted for contrast
                };
                // --- NEW: Map Status Key to Background Color Class ---
                const statusBgColorMap = {
                    'pending': 'bg-yellow-100', // Light yellow
                    'planned': 'bg-cyan-100', // Light cyan
                    'polystyrene': 'bg-slate-100', // Light gray/slate
                    'mesh': 'bg-orange-100', // Light orange
                    'concreting': 'bg-purple-100', // Light purple
                    'assembly': 'bg-red-100', // Light red
                    'completed': 'bg-green-100', // Light green
                    'shipped': 'bg-blue-100', // Light blue
                };

                const statusDisplay = statusTextMap[statusKey] || statusKey;
                const statusColor = statusColorMap[statusKey] || 'text-gray-700'; // Fallback text color
                const panelContainerBgClass = statusBgColorMap[statusKey] || 'bg-white'; // Look up background, fallback to white

                // --- Format Dates for Display ---
                const assignedDateShamsi = formatShamsi(panel.assigned_date);
                const assigndate = panel.assigned_date ? panel.assigned_date.split(' ')[0] : null;
                const plannedFinishDateShamsi = formatShamsi(panel.planned_finish_date);

                // --- Template Literal (uses dynamic background class) ---
                return `
            <div class="${panelContainerBgClass} p-4 rounded-lg shadow-md flex flex-col">
                <div class="border-b pb-3 mb-3">
                    <h2 class="text-lg font-bold text-blue-600 mb-2 truncate">
                        <a href="panel_detail.php?id=${panel.id}" class="hover:underline" title="${panel.address}">
                            ${panel.address}
                        </a>
                    </h2>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div><p class="font-semibold text-gray-500">نوع:</p><p>${panel.type || '-'}</p></div>
                        <div><p class="font-semibold text-gray-500">وضعیت:</p><p class="capitalize ${statusColor}">${statusDisplay}</p></div>
                        <div><p class="font-semibold text-gray-500">طول:</p><p dir="ltr"> ${parseInt(panel.length) || '-'} mm</p></div>
                        <div><p class="font-semibold text-gray-500">عرض:</p><p dir="ltr"> ${parseInt(panel.width) || '-'} mm</p></div>
                    <div>
                    <p class="font-semibold text-gray-500">تاریخ شروع:</p>
                    <p>
                        ${assigndate ?
                        `<a href="new_panels.php?date=${assigndate}" class="hover:underline" title="مراحل کار در تاریخ : ${assignedDateShamsi || ''}">${assignedDateShamsi || '-'}</a>` :
                        '-'
                        }
                    </p>
                    </div>
                    <div><p class="font-semibold text-gray-500">تاریخ پایان(برنامه‌ای):</p><p>${plannedFinishDateShamsi || '-'}</p></div>
                          <div><p class="font-semibold text-gray-500">اولویت:</p><p>${panel.Proritization || '-'}</p></div>
                          <div><p class="font-semibold text-gray-500">قالب:</p><p>${panel.formwork_type || '-'}</p></div>
                      </div>

                    </div>
                </div>
                `;
            }).join('');
            // Update count
            resultsCountNumber.textContent = new Set(panels.map(panel => panel.address)).size;
        }

        function filterPanels() {
            const searchText = searchInput.value.toLowerCase();
            const selectedProritization = prioritizationFilter.value;
            const selectedFormworkType = formworkFilter.value;
            const selectedStatus = statusFilter.value.toLowerCase(); // Selected value from the dropdown
            const minLen = minLengthFilter.value ? parseInt(minLengthFilter.value) : null;
            const maxLen = maxLengthFilter.value ? parseInt(maxLengthFilter.value) : null;
            const minWid = minWidthFilter.value ? parseInt(minWidthFilter.value) : null;
            const maxWid = maxWidthFilter.value ? parseInt(maxWidthFilter.value) : null;

            const assignStart = assignedStartDateGregorian;
            const assignEnd = assignedEndDateGregorian;
            const planStart = plannedStartDateGregorian;
            const planEnd = plannedEndDateGregorian;

            // console.log("Filtering with status:", selectedStatus); // Debug selected filter

            filteredPanels = allPanels.filter(panel => {
                // --- Determine the panel's actual status key using the helper ---
                const panelActualStatusKey = getPanelStatusKey(panel);

                // --- Filter Checks ---
                const matchesSearch = !searchText || panel.address.toLowerCase().includes(searchText);
                const matchesPrio = !selectedProritization || (panel.Proritization && panel.Proritization === selectedProritization);
                const matchesFormwork = !selectedFormworkType || (panel.formwork_type && panel.formwork_type === selectedFormworkType);
                // Filter based on the determined actual status vs the selected dropdown value
                const matchesStatus = !selectedStatus || panelActualStatusKey === selectedStatus;
                const matchesLength = (minLen === null || panel.length >= minLen) && (maxLen === null || panel.length <= maxLen);
                const matchesWidth = (minWid === null || panel.width >= minWid) && (maxWid === null || panel.width <= maxWid);

                // --- Date Filtering (Keep existing logic) ---
                let panelAssignedDate = null;
                if (panel.assigned_date && panel.assigned_date !== '0000-00-00' && !panel.assigned_date.startsWith('0000-00-00')) {
                    panelAssignedDate = panel.assigned_date.split(' ')[0];
                }
                let panelPlannedDate = null;
                if (panel.planned_finish_date && panel.planned_finish_date !== '0000-00-00' && !panel.planned_finish_date.startsWith('0000-00-00')) {
                    panelPlannedDate = panel.planned_finish_date.split(' ')[0];
                }

                const matchesAssignDate = ( /* ... (keep existing date comparison logic) ... */
                    (!assignStart && !assignEnd) ||
                    (assignStart && !assignEnd && panelAssignedDate && panelAssignedDate >= assignStart) ||
                    (!assignStart && assignEnd && panelAssignedDate && panelAssignedDate <= assignEnd) ||
                    (assignStart && assignEnd && panelAssignedDate && panelAssignedDate >= assignStart && panelAssignedDate <= assignEnd) ||
                    // If filtering by date, panel must have a date. If not filtering, panel without date passes.
                    (!panelAssignedDate && !assignStart && !assignEnd)
                );
                const matchesPlanDate = ( /* ... (keep existing date comparison logic) ... */
                    (!planStart && !planEnd) ||
                    (planStart && !planEnd && panelPlannedDate && panelPlannedDate >= planStart) ||
                    (!planStart && planEnd && panelPlannedDate && panelPlannedDate <= planEnd) ||
                    (planStart && planEnd && panelPlannedDate && panelPlannedDate >= planStart && panelPlannedDate <= planEnd) ||
                    // If filtering by date, panel must have a date. If not filtering, panel without date passes.
                    (!panelPlannedDate && !planStart && !planEnd)
                );

                return matchesSearch && matchesPrio && matchesFormwork && matchesStatus &&
                    matchesLength && matchesWidth && matchesAssignDate && matchesPlanDate;
            });

            // console.log("Filtered panels count:", filteredPanels.length); // Debug count
            displayResults(filteredPanels);
        }

        // --- Initialize Date Pickers ---
        $(document).ready(function() {
            // Define common options for all Persian Datepickers on the page
            const datePickerOptions = {
                format: 'YYYY/MM/DD', // Display format in the input field
                initialValue: false, // Don't automatically set an initial value
                autoClose: true, // Close the picker after selection
                persianDigit: false, // IMPORTANT: Display digits in the input as Latin (0-9). Set to true for Persian (۰-۹).
                observer: true, // Automatically re-initialize if the input is added dynamically
                theme: "dark", // Optional: Set a theme for the datepicker (dark/light)
                // --- Essential Fix ---
                calendar: {
                    persian: {
                        locale: 'fa', // Use Persian language/locale
                        leapYearMode: 'astronomical' // Use the more accurate calculation method
                    }
                },
                // --- End Essential Fix ---

                // Function called when a date is selected
                onSelect: function(unix) {
                    // The 'unix' value is the selected date as a JavaScript timestamp (milliseconds since epoch)

                    // --- Convert selected Unix timestamp to Gregorian YYYY-MM-DD ---
                    // Create a standard JavaScript Date object from the timestamp
                    const gregDate = new Date(unix);

                    // Extract year, month (0-indexed, so add 1), and day
                    const year = gregDate.getFullYear();
                    const month = String(gregDate.getMonth() + 1).padStart(2, '0'); // Ensure 2 digits (e.g., 03)
                    const day = String(gregDate.getDate()).padStart(2, '0'); // Ensure 2 digits (e.g., 09)

                    // Format as YYYY-MM-DD string (suitable for backend/filtering)
                    const gregorianValue = `${year}-${month}-${day}`;
                    // --- End Conversion ---

                    // Get the ID of the input field associated with this datepicker instance
                    const inputId = this.model.inputElement.id;
                    console.log("Date selected for input:", inputId, "Gregorian Value:", gregorianValue, "Raw Unix:", unix);

                    // --- Store the Gregorian date based on which input was used ---
                    // Assumes you have these variables declared in a wider scope (e.g., globally or within this ready function)
                    if (inputId === 'assignedStartDateFilter') assignedStartDateGregorian = gregorianValue;
                    else if (inputId === 'assignedEndDateFilter') assignedEndDateGregorian = gregorianValue;
                    else if (inputId === 'plannedStartDateFilter') plannedStartDateGregorian = gregorianValue;
                    else if (inputId === 'plannedEndDateFilter') plannedEndDateGregorian = gregorianValue;
                    // --- End Storing ---

                    // Optional: Log current state of all date filters for debugging
                    console.log("Current Gregorian date filters:", {
                        assignedStart: assignedStartDateGregorian,
                        assignedEnd: assignedEndDateGregorian,
                        plannedStart: plannedStartDateGregorian,
                        plannedEnd: plannedEndDateGregorian
                    });

                    // Trigger the filtering function now that a date has been selected
                    filterPanels();
                }
            };

            // Initialize all elements with the class "persian-datepicker"
            $(".persian-datepicker").each(function() {
                // Apply the defined options to this specific datepicker input
                $(this).persianDatepicker(datePickerOptions);

                // --- Manual Clear Handling ---
                // Attach an 'input' event listener to detect when the input field is cleared (e.g., by backspace/delete)
                $(this).on('input', function() {
                    // Check if the input's value is now empty
                    if ($(this).val() === '') {
                        const clearedInputId = this.id; // Get the ID of the cleared input

                        // Set the corresponding Gregorian variable back to null
                        if (clearedInputId === 'assignedStartDateFilter') assignedStartDateGregorian = null;
                        else if (clearedInputId === 'assignedEndDateFilter') assignedEndDateGregorian = null;
                        else if (clearedInputId === 'plannedStartDateFilter') plannedStartDateGregorian = null;
                        else if (clearedInputId === 'plannedEndDateFilter') plannedEndDateGregorian = null;

                        console.log("Cleared date input:", clearedInputId);

                        // Trigger the filtering function now that a date has been cleared
                        filterPanels();
                    }
                });
                // --- End Manual Clear Handling ---
            });
        });
        // --- Add Event Listeners for other filters ---
        searchInput.addEventListener('input', filterPanels);
        prioritizationFilter.addEventListener('change', filterPanels);
        formworkFilter.addEventListener('change', filterPanels);
        statusFilter.addEventListener('change', filterPanels);
        minLengthFilter.addEventListener('input', filterPanels);
        maxLengthFilter.addEventListener('input', filterPanels);
        minWidthFilter.addEventListener('input', filterPanels);
        maxWidthFilter.addEventListener('input', filterPanels);

        // --- Initial Setup ---
        // Set default prioritization filter if P1 exists
        if (prioritizationFilter.querySelector('option[value="P1"]')) {
            prioritizationFilter.value = 'P1';
        }
        filterPanels(); // Initial filter application (will use default P1)
    </script>
    <?php require_once 'footer.php'; ?>
</body>

</html>