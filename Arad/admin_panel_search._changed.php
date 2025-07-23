<?php
// admin_panel_search.php (Located inside /Fereshteh/ or /Arad/)
require_once __DIR__ . '/../../sercon/bootstrap.php'; // Go up one level to find sercon/

secureSession(); // Initializes session and security checks

// Determine which project this instance of the file belongs to.
// This is simple hardcoding based on folder. More complex routing could derive this.
$current_file_path = __DIR__;
$expected_project_key = null;
if (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Fereshteh') !== false) {
    $expected_project_key = 'fereshteh';
} elseif (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Arad') !== false) {
    $expected_project_key = 'arad';
} else {
    // If the file is somehow not in a recognized project folder, handle error
    logError("admin_panel_search.php accessed from unexpected path: " . $current_file_path);
    die("خطای پیکربندی: پروژه قابل تشخیص نیست.");
}


// --- Authorization ---
// 1. Check if logged in
if (!isLoggedIn()) {
    header('Location: /login.php'); // Redirect to common login
    exit();
}
// 2. Check if user has selected ANY project
if (!isset($_SESSION['current_project_config_key'])) {
    logError("Access attempt to {$expected_project_key}/admin_panel_search.php without project selection. User ID: " . $_SESSION['user_id']);
    header('Location: /select_project.php'); // Redirect to project selection
    exit();
}
// 3. Check if the selected project MATCHES the folder this file is in
if ($_SESSION['current_project_config_key'] !== $expected_project_key) {
    logError("Project context mismatch. Session has '{$_SESSION['current_project_config_key']}', expected '{$expected_project_key}'. User ID: " . $_SESSION['user_id']);
    // Maybe redirect to select_project or show an error specific to context mismatch
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}
// 4. Check user role access for this specific page
$allowed_roles = ['admin', 'supervisor', 'planner', 'user', 'superuser']; // Added superuser for completeness
if (!in_array($_SESSION['role'], $allowed_roles)) {
    logError("Role '{$_SESSION['role']}' unauthorized access attempt to {$expected_project_key}/admin_panel_search.php. User ID: " . $_SESSION['user_id']);
    // Redirect to a project-specific unauthorized page or dashboard
    header('Location: dashboard.php?msg=unauthorized'); // Assumes dashboard.php exists in the project folder
    exit();
}
// --- End Authorization ---

if (!function_exists('translate_panel_data_to_persian')) {
    function translate_panel_data_to_persian($key, $english_value)
    {
        if ($english_value === null || $english_value === '') return '-'; // Handle empty or null

        $translations = [
            'zone' => [
                'zone 1' => 'زون ۱',
                'zone 2' => 'زون ۲',
                'zone 3' => 'زون ۳',
                'zone 4' => 'زون ۴',
                'zone 5' => 'زون ۵',
                'zone 6' => 'زون ۶',
                'zone 7' => 'زون ۷',
                'zone 8' => 'زون ۸',
                'zone 9' => 'زون ۹',
                'zone 10' => 'زون ۱۰',
                'zone 11' => 'زون ۱۱',
                'zone 12' => 'زون ۱۲',
                'zone 13' => 'زون ۱۳',
                'zone 14' => 'زون ۱۴',
                'zone 15' => 'زون ۱۵',
                'zone 16' => 'زون ۱۶',
                'zone 17' => 'زون ۱۷',
                'zone 18' => 'زون ۱۸',
                'zone 19' => 'زون ۱۹',
                'zone 20' => 'زون ۲۰',
                'zone 21' => 'زون ۲۱',
                'zone 22' => 'زون ۲۲',
                'zone 23' => 'زون ۲۳',
                'zone 24' => 'زون ۲۴',
                'zone 25' => 'زون ۲۵',
                'zone 26' => 'زون ۲۶',
                'zone 27' => 'زون ۲۷',
                'zone 28' => 'زون ۲۸',
                'zone 29' => 'زون ۲۹',
                'zone 30' => 'زون ۳۰',
            ],
            'panel_position' => [ // Assuming 'type' column stores panel_position
                'terrace edge' => 'لبه تراس',
                'wall panel'   => 'پنل دیواری', // Example
                // Add other positions
            ],
            'status' => [ // For consistency, though your JS handles this mostly
                'pending'    => 'در انتظار تخصیص',
                'planned'    => 'برنامه ریزی شده',
                'mesh'       => 'مش بندی',
                'concreting' => 'قالب‌بندی/بتن ریزی',
                'assembly'   => 'فیس کوت', // Assuming 'assembly' is فیس کوت
                'completed'  => 'تکمیل شده',
                'shipped'    => 'ارسال شده'
            ],
            // NEW: Add translation for plan_checked status
            'plan_checked' => [
                '1' => 'تایید شده',
                '0' => 'تایید نشده',
            ]
            // Add other keys if needed, e.g., 'formwork_type'
        ];

        if (isset($translations[$key][$english_value])) {
            return $translations[$key][$english_value];
        }
        return escapeHtml($english_value); // Fallback to English if no translation
    }
}

// --- Database Operations ---
$allPanels = [];
$prioritizationLevels = $pageData['prioritizationLevels'] ?? [];
$formworkTypeNames = [];
$uniquePanelAddresses = [];
$uniqueCompletedPanelAddresses = [];
$uniqueAssignedDatePanelAddresses = [];
$uniqushippedAddresses = [];

try {
    // Get the connection for the CURRENT project based on session data
    // The session variable 'current_project_config_key' should be 'fereshteh' or 'arad'
    $pdo = getProjectDBConnection(); // Reads from $_SESSION['current_project_config_key']

    // Fetch All Panels for this project
    // The query remains the same, it just runs on the selected project's DB now.
    // NEW: Added hp.plan_checked to the SELECT statement
    $stmt = $pdo->prepare("
        SELECT hp.*
        FROM hpc_panels hp
        ORDER BY hp.Proritization ASC, hp.id DESC
    ");
    $stmt->execute();
    $allPanels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Distinct Prioritization Levels for this project
    $stmtPrio = $pdo->query("SELECT DISTINCT Proritization FROM hpc_panels WHERE Proritization IS NOT NULL AND Proritization != '' ORDER BY Proritization ASC");
    $prioritizationLevels = $stmtPrio->fetchAll(PDO::FETCH_COLUMN);

    // Fetch Formwork Types for Filter for this project
    $stmtFormwork = $pdo->query("SELECT formwork_type FROM available_formworks ORDER BY formwork_type ASC");
    $formworkTypeNames = $stmtFormwork->fetchAll(PDO::FETCH_COLUMN);

    // Calculate unique addresses based on the fetched panels for *this project*
    $uniquePanelAddresses = array_unique(array_column($allPanels, 'address'));
    $uniqueCompletedPanelAddresses = array_unique(array_column(array_filter($allPanels, fn($p) => $p['status'] == 'completed'), 'address'));
    $uniqueAssignedDatePanelAddresses = array_unique(array_column(array_filter($allPanels, fn($p) => !empty($p['assigned_date'])), 'address'));
    $uniqushippedAddresses = array_unique(array_column(array_filter($allPanels, fn($p) => $p['packing_status'] == 'shipped'), 'address'));
} catch (PDOException $e) {
    logError("Database error in {$expected_project_key}/admin_panel_search.php: " . $e->getMessage());
    die("خطای پایگاه داده در بارگذاری اطلاعات پروژه رخ داد. لطفا با پشتیبانی تماس بگیرید.");
} catch (Exception $e) { // Catch exceptions from getProjectDBConnection if config key is missing
    logError("Configuration or Connection error in {$expected_project_key}/admin_panel_search.php: " . $e->getMessage());
    die("خطای پیکربندی یا اتصال پایگاه داده پروژه رخ داد.");
}
function getAdminPanelSearchData(PDO $projectPdo): array
{
    $data = [
        'allPanels' => [],
        'prioritizationLevels' => [],
        'formworkTypeNames' => [],
        'uniquePanelAddresses' => [],
        'uniqueCompletedPanelAddresses' => [],
        'uniqueAssignedDatePanelAddresses' => [],
        'uniqushippedAddresses' => [],
    ];

    // Fetch All Panels for this project
    // NEW: Added hp.plan_checked to the SELECT statement
    $stmt = $projectPdo->prepare("
        SELECT hp.id, hp.address, hp.instance_number, hp.total_in_batch,hp.full_address_identifier, hp.type, hp.area, hp.width, hp.length,
               hp.status, hp.assigned_date, hp.planned_finish_date, hp.formwork_type, hp.packing_status,
               hp.truck_id, hp.shipping_date, hp.Proritization, hp.plan_checked
        FROM hpc_panels hp
        ORDER BY hp.Proritization ASC, hp.id DESC
    ");
    $stmt->execute();
    $data['allPanels'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Distinct Prioritization Levels (Zones) for this project
    $stmtPrio = $projectPdo->query("SELECT DISTINCT Proritization FROM hpc_panels WHERE Proritization IS NOT NULL AND Proritization != '' ORDER BY Proritization ASC");
    $data['prioritizationLevels'] = $stmtPrio->fetchAll(PDO::FETCH_COLUMN);

    // Fetch Formwork Types for Filter for this project
    // This assumes 'available_formworks' table exists in the project DB
    $stmtFormwork = $projectPdo->query("SELECT DISTINCT formwork_type FROM available_formworks WHERE formwork_type IS NOT NULL AND formwork_type != '' ORDER BY formwork_type ASC");
    $data['formworkTypeNames'] = $stmtFormwork->fetchAll(PDO::FETCH_COLUMN);

    // Calculate unique addresses based on the fetched panels for *this project*
    if (!empty($data['allPanels'])) {
        $data['uniquePanelAddresses'] = array_values(array_unique(array_column($data['allPanels'], 'address')));
        $data['uniqueCompletedPanelAddresses'] = array_values(array_unique(array_column(array_filter($data['allPanels'], fn($p) => $p['status'] == 'completed'), 'address')));
        $data['uniqueAssignedDatePanelAddresses'] = array_values(array_unique(array_column(array_filter($data['allPanels'], fn($p) => !empty($p['assigned_date'])), 'address')));
        $data['uniqushippedAddresses'] = array_values(array_unique(array_column(array_filter($data['allPanels'], fn($p) => $p['packing_status'] == 'shipped'), 'address')));
    }
    return $data;
}
// --- HTML Starts Here ---
// Update title to include project name from session
$pageTitle = "جستجو و فیلتر پنل ها - " . escapeHtml($_SESSION['current_project_name'] ?? 'پروژه');

// Include the project-specific header file. Assumes header.php is in the same folder (Fereshteh/ or Arad/)
require_once __DIR__ . '/header.php';
?>

<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="al-1 mb-4">جهت مشاهده جزییات روی لینک آبی (نام پنل) کلیک کنید</div>

        <!-- Filter Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">

            <!-- Search -->
            <div class="bg-white p-4 rounded-lg shadow-md">
                <label for="searchInput" class="block text-sm font-medium text-gray-700 mb-1">جستجو (آدرس)</label>
                <input type="text" id="searchInput" class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-400" placeholder="بخشی از آدرس...">
            </div>

            <!-- Prioritization Filter -->
            <div class="bg-white p-4 rounded-lg shadow-md">
                <label for="prioritizationFilter" class="block text-sm font-medium text-gray-700 mb-1">زون</label>
                <select id="prioritizationFilter" class="w-full p-2 border rounded">
                    <option value="">همه زون‌ها</option>
                    <?php foreach ($prioritizationLevels as $prio_english): ?>
                        <option value="<?php echo escapeHtml($prio_english); ?>"
                            <?php // Default selection logic if needed, e.g.,
                            // if ($prio_english === 'zone 1') echo 'selected';
                            ?>>
                            <?php echo translate_panel_data_to_persian('zone', $prio_english); ?>
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

            <!-- NEW: Plan Checked Filter -->
            <div class="bg-white p-4 rounded-lg shadow-md">
                <label for="planCheckedFilter" class="block text-sm font-medium text-gray-700 mb-1">وضعیت نقشه</label>
                <select id="planCheckedFilter" class="w-full p-2 border rounded">
                    <option value="">همه</option>
                    <option value="1">تایید شده</option>
                    <option value="0">تایید نشده</option>
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



    <link rel="stylesheet" href="/assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="/assets/css/persian-datepicker-dark.min.css">
    <!-- Include Datepicker and Moment JS -->
    <script src="/assets/js/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/moment.min.js"></script>
    <script src="/assets/js/moment-jalaali.js"></script>
    <script src="/assets/js/persian-datepicker.min.js"></script>
    <script src="/assets/js/mobile-detect.min.js"></script>
    <!-- <script src="main.min.js"></script> --> <!-- Only if it contains generic functions -->

    <script>
        // Pass ALL panels to JavaScript, including instance_number and total_in_batch
        const allPanelsData = <?php echo json_encode($allPanels ?? []); ?>;

        // --- DOM Element References ---
        const searchInput = document.getElementById('searchInput');
        const prioritizationFilter = document.getElementById('prioritizationFilter');
        const formworkFilter = document.getElementById('formworkFilter');
        const statusFilter = document.getElementById('statusFilter');
        const planCheckedFilter = document.getElementById('planCheckedFilter'); // NEW: Reference for the new filter
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
        const totalPanelsCountSpan = document.getElementById('totalPanelsCount');

        // --- Filter State Variables ---
        let assignedStartDateGregorian = null;
        let assignedEndDateGregorian = null;
        let plannedStartDateGregorian = null;
        let plannedEndDateGregorian = null;

        const translationsJS = {
            zone: {
                'zone 1': 'زون ۱',
                'zone 2': 'زون ۲',
                'zone 3': 'زون ۳',
                'zone 4': 'زون ۴',
                'zone 5': 'زون ۵',
                'zone 6': 'زون ۶',
                'zone 7': 'زون ۷',
                'zone 8': 'زون ۸',
                'zone 9': 'زون ۹',
                'zone 10': 'زون ۱۰',
                'zone 11': 'زون ۱۱',
                'zone 12': 'زون ۱۲',
                'zone 13': 'زون ۱۳',
                'zone 14': 'زون ۱۴',
                'zone 15': 'زون ۱۵',
                'zone 16': 'زون ۱۶',
                'zone 17': 'زون ۱۷',
                'zone 18': 'زون ۱۸',
                'zone 19': 'زون ۱۹',
                'zone 20': 'زون ۲۰',
                'zone 21': 'زون ۲۱',
                'zone 22': 'زون ۲۲',
                'zone 23': 'زون ۲۳',
                'zone 24': 'زون ۲۴',
                'zone 25': 'زون ۲۵',
                'zone 26': 'زون ۲۶',
                'zone 27': 'زون ۲۷',
                'zone 28': 'زون ۲۸',
                'zone 29': 'زون ۲۹',
                'zone 30': 'زون ۳۰',
            },
            panel_position: { // Key should match what you use in displayResults for panel.type
                'terrace edge': 'لبه تراس',
                'wall panel': 'پنل دیواری',
            },
            status: { // Your existing status map can serve this purpose
                'pending': 'در انتظار تخصیص',
                'planned': 'برنامه ریزی شده',
                'mesh': 'مش بندی',
                'concreting': 'قالب‌بندی/بتن ریزی',
                'assembly': 'فیس کوت',
                'completed': 'تکمیل شده',
                'shipped': 'ارسال شده'
            },
            // NEW: Add translation for plan_checked status
            plan_checked: {
                '1': 'تایید شده',
                '0': 'تایید نشده',
                'null': '-'
            }
        };

        function translateJs(key, englishValue) {
            if (englishValue === null || englishValue === undefined || englishValue === '') return '-';
            // Use String() to handle numeric keys like 0 and 1
            const valueStr = String(englishValue);
            if (translationsJS[key] && translationsJS[key][valueStr.toLowerCase()]) { // Convert to lowercase for robust matching
                return translationsJS[key][valueStr.toLowerCase()];
            }
            return valueStr; // Fallback
        }
        // --- Helper: Client-side Shamsi Formatting ---
        function formatShamsi(gregorianDate) {
            if (!gregorianDate || gregorianDate === '0000-00-00' || gregorianDate === null) return '';
            try {
                if (typeof moment === 'function' && typeof moment.loadPersian === 'function') {
                    moment.loadPersian({
                        usePersianDigits: false,
                        dialect: 'persian-modern'
                    });
                    const datePart = gregorianDate.split(' ')[0];
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(datePart)) {
                        return ''; // Return empty for invalid format
                    }
                    return moment(datePart, 'YYYY-MM-DD').format('jYYYY/jMM/jDD');
                }
                return gregorianDate; // Fallback
            } catch (e) {
                console.warn("Shamsi formatting error for:", gregorianDate, e);
                return 'تاریخ نامعتبر';
            }
        }

        function getPanelStatusKey(panel) {
            if (panel.packing_status && panel.packing_status.toLowerCase() === 'shipped') {
                return 'shipped';
            }
            const hasAssignedDate = panel.assigned_date && panel.assigned_date !== '0000-00-00' && panel.assigned_date !== null && !panel.assigned_date.startsWith('0000-00-00');
            if (!hasAssignedDate) {
                return 'pending';
            }
            const currentStatus = panel.status ? panel.status.toLowerCase() : null;
            if (!currentStatus || currentStatus === 'pending') {
                return 'planned';
            }
            switch (currentStatus) {
                case 'mesh':
                    return 'mesh';
                case 'concreting':
                    return 'concreting';
                case 'assembly':
                    return 'assembly';
                case 'completed':
                case 'panel.status':
                    return 'completed';
                default:
                    return 'planned';
            }
        }

        // --- Display Results Function ---
        function displayResults(panelsToDisplay) {
            if (!resultsContainer) return; // Guard if container not found

            resultsContainer.innerHTML = panelsToDisplay.map(panel => {
                const statusKey = getPanelStatusKey(panel);
                const statusColorMap = {
                    'pending': 'text-yellow-700',
                    'planned': 'text-cyan-700',
                    'assembly': 'text-red-700',
                    'mesh': 'text-orange-700',
                    'concreting': 'text-purple-700',
                    'completed': 'text-green-700',
                    'shipped': 'text-blue-700',
                };
                const statusBgColorMap = {
                    'pending': 'bg-yellow-100',
                    'planned': 'bg-cyan-100',
                    'assembly': 'bg-red-100',
                    'mesh': 'bg-orange-100',
                    'concreting': 'bg-purple-100',
                    'completed': 'bg-green-100',
                    'shipped': 'bg-blue-100',
                };

                const statusDisplayText = translateJs('status', statusKey);
                const statusTextColor = statusColorMap[statusKey] || 'text-gray-700';
                const panelContainerBgClass = statusBgColorMap[statusKey] || 'bg-white';

                const assignedDateShamsi = formatShamsi(panel.assigned_date);
                const assigndateForLink = panel.assigned_date ? panel.assigned_date.split(' ')[0] : null;
                const plannedFinishDateShamsi = formatShamsi(panel.planned_finish_date);

                const panelCode = panel.address || 'کد نامشخص';
                const panelZoneDisplay = translateJs('zone', panel.Proritization);
                const panelLocationDisplay = translateJs('panel_position', panel.type);
                const instanceInfo = (panel.instance_number && panel.total_in_batch) ? ` (${panel.instance_number} از ${panel.total_in_batch})` : '';

                // NEW: Get translated plan checked status
                const planCheckedText = translateJs('plan_checked', panel.plan_checked);
                const planCheckedColor = panel.plan_checked == '1' ? 'text-green-700' : (panel.plan_checked == '0' ? 'text-red-700' : 'text-gray-500');


                return `
                <div class="${panelContainerBgClass} p-4 rounded-lg shadow-md flex flex-col">
                    <div class="border-b pb-3 mb-3">
                        <h3 class="text-lg font-bold text-blue-600 mb-2 truncate">
                            <a href="panel_detail.php?id=${panel.id}" class="hover:underline" title="جزئیات ${panelCode}${instanceInfo}">
                                ${panelCode}${instanceInfo}
                            </a>
                        </h3>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                            <div><p class="font-semibold text-gray-500">زون:</p><p>${panelZoneDisplay}</p></div>
                            <div><p class="font-semibold text-gray-500">موقعیت:</p><p> طبقه ${panel.floor || '-'}</p></div>
                            <div><p class="font-semibold text-gray-500">وضعیت:</p><p class="capitalize ${statusTextColor}">${statusDisplayText}</p></div>
                            <div><p class="font-semibold text-gray-500">طول:</p><p dir="ltr">${parseInt(panel.length) || '-'} mm</p></div>
                            <div><p class="font-semibold text-gray-500">عرض:</p><p dir="ltr">${parseInt(panel.width) || '-'} mm</p></div>
                            <div>
                                <p class="font-semibold text-gray-500">تاریخ شروع:</p>
                                <p>
                                    ${assigndateForLink ?
                                    `<a href="new_panels.php?date=${assigndateForLink}" class="hover:underline" title="مراحل کار در تاریخ : ${assignedDateShamsi || ''}">${assignedDateShamsi || '-'}</a>` :
                                    '-'
                                    }
                                </p>
                            </div>
                            <div><p class="font-semibold text-gray-500">تاریخ پایان(برنامه‌ای):</p><p>${plannedFinishDateShamsi || '-'}</p></div>
                            <div><p class="font-semibold text-gray-500">نوع قالب:</p><p>${panel.formwork_type || '-'}</p></div>
                            <!-- NEW: Display Plan Checked Status -->
                            <div><p class="font-semibold text-gray-500">وضعیت نقشه:</p><p class="${planCheckedColor}">${planCheckedText}</p></div>
                        </div>
                    </div>
                </div>
                `;
            }).join('');

            if (resultsCountNumber) {
                resultsCountNumber.textContent = panelsToDisplay.length;
            }
        }

        function filterPanels() {
            const searchText = searchInput.value.toLowerCase();
            const selectedProritization = prioritizationFilter.value;
            const selectedFormworkType = formworkFilter.value;
            const selectedStatusFilterValue = statusFilter.value;
            const selectedPlanChecked = planCheckedFilter.value; // NEW: Get value from the new filter
            const minLen = minLengthFilter.value ? parseInt(minLengthFilter.value) : null;
            const maxLen = maxLengthFilter.value ? parseInt(maxLengthFilter.value) : null;
            const minWid = minWidthFilter.value ? parseInt(minWidthFilter.value) : null;
            const maxWid = maxWidthFilter.value ? parseInt(maxWidthFilter.value) : null;

            const assignStart = assignedStartDateGregorian;
            const assignEnd = assignedEndDateGregorian;
            const planStart = plannedStartDateGregorian;
            const planEnd = plannedEndDateGregorian;

            const filteredPanels = allPanelsData.filter(panel => {
                const panelActualStatusKey = getPanelStatusKey(panel);
                const matchesPrio = !selectedProritization || (panel.Proritization && panel.Proritization.toLowerCase() === selectedProritization.toLowerCase());
                const matchesSearch = !searchText || (panel.address && panel.address.toLowerCase().includes(searchText));
                const matchesFormwork = !selectedFormworkType || (panel.formwork_type && panel.formwork_type.toLowerCase() === selectedFormworkType.toLowerCase());
                const matchesStatus = !selectedStatusFilterValue || panelActualStatusKey === selectedStatusFilterValue;

                // NEW: Logic for the plan_checked filter
                // It handles the "All" case (value is "") and compares the panel's value.
                // Using == for loose comparison as value from select is a string and from DB might be number.
                const matchesPlanChecked = !selectedPlanChecked || panel.plan_checked == selectedPlanChecked;

                const matchesLength = (minLen === null || (panel.length !== null && panel.length >= minLen)) &&
                    (maxLen === null || (panel.length !== null && panel.length <= maxLen));
                const matchesWidth = (minWid === null || (panel.width !== null && panel.width >= minWid)) &&
                    (maxWid === null || (panel.width !== null && panel.width <= maxWid));

                let panelAssignedDate = null;
                if (panel.assigned_date && panel.assigned_date !== '0000-00-00' && panel.assigned_date !== null) {
                    panelAssignedDate = panel.assigned_date.split(' ')[0];
                }
                let panelPlannedDate = null;
                if (panel.planned_finish_date && panel.planned_finish_date !== '0000-00-00' && panel.planned_finish_date !== null) {
                    panelPlannedDate = panel.planned_finish_date.split(' ')[0];
                }

                const matchesAssignDate = (
                    (!assignStart && !assignEnd) ||
                    (assignStart && !assignEnd && panelAssignedDate && panelAssignedDate >= assignStart) ||
                    (!assignStart && assignEnd && panelAssignedDate && panelAssignedDate <= assignEnd) ||
                    (assignStart && assignEnd && panelAssignedDate && panelAssignedDate >= assignStart && panelAssignedDate <= assignEnd) ||
                    (!panelAssignedDate && !assignStart && !assignEnd)
                );
                const matchesPlanDate = (
                    (!planStart && !planEnd) ||
                    (planStart && !planEnd && panelPlannedDate && panelPlannedDate >= planStart) ||
                    (!planStart && planEnd && panelPlannedDate && panelPlannedDate <= planEnd) ||
                    (planStart && planEnd && panelPlannedDate && panelPlannedDate >= planStart && panelPlannedDate <= planEnd) ||
                    (!panelPlannedDate && !planStart && !planEnd)
                );

                // NEW: Added matchesPlanChecked to the return condition
                return matchesSearch && matchesPrio && matchesFormwork && matchesStatus && matchesPlanChecked &&
                    matchesLength && matchesWidth && matchesAssignDate && matchesPlanDate;
            });

            displayResults(filteredPanels);
        }

        // --- Initialize Date Pickers ---
        $(document).ready(function() {
            const datePickerOptions = {
                format: 'YYYY/MM/DD',
                initialValue: false,
                autoClose: true,
                persianDigit: false,
                observer: true,
                theme: "dark",
                calendar: {
                    persian: {
                        locale: 'fa',
                        leapYearMode: 'astronomical'
                    }
                },
                onSelect: function(unix) {
                    const gregDate = new Date(unix);
                    const year = gregDate.getFullYear();
                    const month = String(gregDate.getMonth() + 1).padStart(2, '0');
                    const day = String(gregDate.getDate()).padStart(2, '0');
                    const gregorianValue = `${year}-${month}-${day}`;
                    const inputId = this.model.inputElement.id;

                    if (inputId === 'assignedStartDateFilter') assignedStartDateGregorian = gregorianValue;
                    else if (inputId === 'assignedEndDateFilter') assignedEndDateGregorian = gregorianValue;
                    else if (inputId === 'plannedStartDateFilter') plannedStartDateGregorian = gregorianValue;
                    else if (inputId === 'plannedEndDateFilter') plannedEndDateGregorian = gregorianValue;
                    filterPanels();
                }
            };

            $(".persian-datepicker").each(function() {
                $(this).persianDatepicker(datePickerOptions);
                $(this).on('input', function() {
                    if ($(this).val() === '') {
                        const clearedInputId = this.id;
                        if (clearedInputId === 'assignedStartDateFilter') assignedStartDateGregorian = null;
                        else if (clearedInputId === 'assignedEndDateFilter') assignedEndDateGregorian = null;
                        else if (clearedInputId === 'plannedStartDateFilter') plannedStartDateGregorian = null;
                        else if (clearedInputId === 'plannedEndDateFilter') plannedEndDateGregorian = null;
                        filterPanels();
                    }
                });
            });

            // --- Add Event Listeners for other filters ---
            if (searchInput) searchInput.addEventListener('input', filterPanels);
            if (prioritizationFilter) prioritizationFilter.addEventListener('change', filterPanels);
            if (formworkFilter) formworkFilter.addEventListener('change', filterPanels);
            if (statusFilter) statusFilter.addEventListener('change', filterPanels);
            if (planCheckedFilter) planCheckedFilter.addEventListener('change', filterPanels); // NEW: Event listener for the new filter
            if (minLengthFilter) minLengthFilter.addEventListener('input', filterPanels);
            if (maxLengthFilter) maxLengthFilter.addEventListener('input', filterPanels);
            if (minWidthFilter) minWidthFilter.addEventListener('input', filterPanels);
            if (maxWidthFilter) maxWidthFilter.addEventListener('input', filterPanels);

            if (prioritizationFilter) {
                const zone1Option = prioritizationFilter.querySelector('option[value="zone 1"]');
                if (zone1Option) {
                    prioritizationFilter.value = 'zone 1';
                } else {
                    const p1Option = prioritizationFilter.querySelector('option[value="P1"]');
                    if (p1Option) {
                        prioritizationFilter.value = 'P1';
                    }
                }
            }

            if (totalPanelsCountSpan && allPanelsData) {
                totalPanelsCountSpan.textContent = allPanelsData.length;
            }

            filterPanels();
        });
    </script>
    <?php require_once 'footer.php'; ?>
</body>

</html>