<?php
// item_reports.php (Located inside /Arad/) - REVISED & COMPLETE

require_once __DIR__ . '/../../sercon/bootstrap.php';

secureSession();

$current_file_path = __DIR__;
$expected_project_key = null;
if (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Fereshteh') !== false) {
    $expected_project_key = 'fereshteh';
} elseif (strpos($current_file_path, DIRECTORY_SEPARATOR . 'Arad') !== false) {
    $expected_project_key = 'arad';
} else {
    logError("item_reports.php accessed from unexpected path: " . $current_file_path);
    die("خطای پیکربندی: پروژه قابل تشخیص نیست.");
}

// --- Authorization ---
if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}
if (!isset($_SESSION['current_project_config_key']) || $_SESSION['current_project_config_key'] !== $expected_project_key) {
    logError("Project context mismatch or no project selected for item_reports.php. User ID: " . ($_SESSION['user_id'] ?? 'N/A'));
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}
$allowed_roles = ['admin', 'supervisor', 'superuser', 'planner', 'user'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    logError("Role '{$_SESSION['role']}' unauthorized access attempt to {$expected_project_key}/item_reports.php. User ID: " . $_SESSION['user_id']);
    header('Location: dashboard.php?msg=unauthorized');
    exit();
}



$reportData = [];
$summaryData = [];

// Filter parameters
$filterItemName = $_GET['item_name'] ?? '';
$filterFloor = $_GET['floor'] ?? '';
$filterLocationCode = $_GET['location_code'] ?? '';
$filterStatusSent = $_GET['status_sent'] ?? '';
$filterStatusMade = $_GET['status_made'] ?? '';
$filterStartDate = $_GET['start_date'] ?? '';
$filterEndDate = $_GET['end_date'] ?? '';
$filterComponentType = $_GET['component_type'] ?? '';
$filterComponentName = $_GET['component_name'] ?? '';

// Self-contained Jalaali date formatter
if (!function_exists('formatShamsi')) {
    function formatShamsi($gregorianDate)
    {
        if (empty($gregorianDate) || $gregorianDate === '0000-00-00 00:00:00') return '-';
        try {
            $date = new DateTime($gregorianDate);
            $gy = (int)$date->format('Y');
            $gm = (int)$date->format('m');
            $gd = (int)$date->format('d');
            $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
            $jy = ($gy <= 1600) ? 979 : ($gy - 1600) + 979;
            $gy -= 1600;
            $leapy = ($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0);
            $doy = $g_d_m[$gm - 1] + $gd;
            if ($leapy && $gm > 2) $doy++;
            $doy -= 79;
            if ($doy <= 0) {
                $doy += 365 + ($leapy ? 1 : 0);
                $jy--;
            }
            $j_d_m_sum = array(0, 31, 62, 93, 124, 155, 186, 186, 186, 186, 186, 186);
            $jd = $doy;
            $jm = 1;
            while ($jm <= 12 && $jd > ($j_d_m_sum[$jm - 1] + ($jm <= 6 ? 31 : ($jm <= 11 ? 30 : 29 + ($jy % 33 % 4 == 1))))) $jm++;
            $jd -= $j_d_m_sum[$jm - 1];
            return "$jy/$jm/$jd";
        } catch (Exception $e) {
            return 'تاریخ نامعتبر';
        }
    }
}


try {
    $pdo = getProjectDBConnection();

    // --- Build WHERE clause and parameters dynamically ---
    $whereClauses = ["1=1"];
    $params = [
        ':base_dir_path' => __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'item_documents' . DIRECTORY_SEPARATOR,
        ':base_url_path' => '/Arad/uploads/item_documents/'
    ];

    if (!empty($filterItemName)) {
        $whereClauses[] = "hi.item_name = :item_name";
        $params[':item_name'] = $filterItemName;
    }
    if ($filterFloor !== '') {
        $whereClauses[] = "hi.floor = :floor";
        $params[':floor'] = (int)$filterFloor;
    }
    if (!empty($filterLocationCode)) {
        $whereClauses[] = "hi.location_code LIKE :location_code";
        $params[':location_code'] = '%' . $filterLocationCode . '%';
    }
    if ($filterStatusSent !== '') {
        $whereClauses[] = "hi.status_sent = :status_sent";
        $params[':status_sent'] = (int)$filterStatusSent;
    }
    if ($filterStatusMade !== '') {
        $whereClauses[] = "hi.status_made = :status_made";
        $params[':status_made'] = (int)$filterStatusMade;
    }
    if (!empty($filterStartDate)) {
        $whereClauses[] = "DATE(hi.created_at) >= :start_date";
        $params[':start_date'] = $filterStartDate;
    }
    if (!empty($filterEndDate)) {
        $whereClauses[] = "DATE(hi.created_at) <= :end_date";
        $params[':end_date'] = $filterEndDate;
    }
    if (!empty($filterComponentType)) {
        $whereClauses[] = "hi.id IN (SELECT hpc_item_id FROM item_components WHERE component_type = :component_type)";
        $params[':component_type'] = $filterComponentType;
    }
    if (!empty($filterComponentName)) {
        $whereClauses[] = "hi.id IN (SELECT hpc_item_id FROM item_components WHERE component_name LIKE :component_name)";
        $params[':component_name'] = '%' . $filterComponentName . '%';
    }

    $whereSql = implode(" AND ", $whereClauses);

    // --- Main Report Query ---
    $sql = "SELECT hi.id, hi.item_name, hi.item_code, hi.floor, hi.location_code, hi.quantity,
                   hi.status_sent, hi.status_made, hi.created_at,
                   GROUP_CONCAT(DISTINCT CONCAT(ic.component_name, ' (', ic.component_quantity, ' ', ic.unit, ')') ORDER BY ic.component_type SEPARATOR '|||') AS components_summary,
                   GROUP_CONCAT(DISTINCT CONCAT(f.file_name, ':', REPLACE(f.file_path, :base_dir_path, :base_url_path)) ORDER BY f.file_name SEPARATOR '|||') AS files_summary
            FROM hpc_items hi
            LEFT JOIN item_components ic ON hi.id = ic.hpc_item_id
            LEFT JOIN item_files f ON hi.id = f.hpc_item_id
            WHERE $whereSql
            GROUP BY hi.id
            ORDER BY hi.floor ASC, hi.item_code ASC, hi.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Summary Statistics Query ---
    $summaryParams = $params;
    unset($summaryParams[':base_dir_path'], $summaryParams[':base_url_path']); // Not needed for summary

    $summarySql = "SELECT hi.item_name,
                          SUM(hi.quantity) AS total_item_quantity,
                          SUM(CASE WHEN ic.component_type = 'plates' AND ic.unit = 'کیلوگرم' THEN ic.component_quantity * COALESCE(NULLIF(REPLACE(ic.attribute_3, ',', ''), ''), 0) ELSE 0 END) AS total_plate_weight,
                          SUM(CASE WHEN ic.component_type = 'profiles' AND ic.unit = 'متر' AND ic.attribute_2 IS NOT NULL THEN hi.quantity * ic.component_quantity * COALESCE(NULLIF(REPLACE(ic.attribute_2, ',', ''), ''), 0) ELSE 0 END) AS total_profile_weight
                   FROM hpc_items hi
                   LEFT JOIN item_components ic ON hi.id = ic.hpc_item_id
                   WHERE $whereSql
                   GROUP BY hi.item_name ORDER BY hi.item_name ASC";

    $stmtSummary = $pdo->prepare($summarySql);
    $stmtSummary->execute($summaryParams);
    $summaryData = $stmtSummary->fetchAll(PDO::FETCH_ASSOC);

    // --- Fetch filter options ---
    $itemNames = $pdo->query("SELECT DISTINCT item_name FROM hpc_items ORDER BY item_name ASC")->fetchAll(PDO::FETCH_COLUMN);
    $componentTypes = $pdo->query("SELECT DISTINCT component_type FROM item_components WHERE component_type IS NOT NULL AND component_type != '' ORDER BY component_type ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    logError("Database error fetching report data: " . $e->getMessage());
    die("خطا در بارگذاری گزارش: " . $e->getMessage());
}
$pageTitle = "گزارشات قطعات - " . escapeHtml($_SESSION['current_project_name'] ?? 'پروژه');
require_once __DIR__ . '/header.php';
?>

<body class="bg-gray-100 font-sans text-right" dir="rtl">
    <div class="container mx-auto px-4 py-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">گزارشات قطعات</h2>

        <div class="bg-white p-6 rounded-lg shadow-md mb-8">
            <form action="item_reports.php" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label for="filterItemName" class="block text-sm font-medium text-gray-700 mb-1">آیتم:</label>
                    <select id="filterItemName" name="item_name" class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-400">
                        <option value="">همه آیتم‌ها</option>
                        <?php foreach ($itemNames as $name): ?>
                            <option value="<?php echo escapeHtml($name); ?>" <?php echo ($filterItemName === $name) ? 'selected' : ''; ?>>
                                <?php echo escapeHtml($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="filterFloor" class="block text-sm font-medium text-gray-700 mb-1">طبقه:</label>
                    <input type="number" id="filterFloor" name="floor" value="<?php echo escapeHtml($filterFloor); ?>" class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-400" placeholder="شماره طبقه">
                </div>
                <div>
                    <label for="filterLocationCode" class="block text-sm font-medium text-gray-700 mb-1">کد موقعیت:</label>
                    <input type="text" id="filterLocationCode" name="location_code" value="<?php echo escapeHtml($filterLocationCode); ?>" class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-400" placeholder="کد موقعیت/بچ">
                </div>
                <div>
                    <label for="filterStatusSent" class="block text-sm font-medium text-gray-700 mb-1">وضعیت ارسال:</label>
                    <select id="filterStatusSent" name="status_sent" class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-400">
                        <option value="">همه</option>
                        <option value="1" <?php echo ($filterStatusSent === '1') ? 'selected' : ''; ?>>ارسال شده</option>
                        <option value="0" <?php echo ($filterStatusSent === '0') ? 'selected' : ''; ?>>ارسال نشده</option>
                    </select>
                </div>
                <div>
                    <label for="filterStatusMade" class="block text-sm font-medium text-gray-700 mb-1">وضعیت ساخت:</label>
                    <select id="filterStatusMade" name="status_made" class="w-full p-2 border rounded focus:ring-2 focus:ring-blue-400">
                        <option value="">همه</option>
                        <option value="1" <?php echo ($filterStatusMade === '1') ? 'selected' : ''; ?>>ساخته شده</option>
                        <option value="0" <?php echo ($filterStatusMade === '0') ? 'selected' : ''; ?>>ساخته نشده</option>
                    </select>
                </div>
                <div>
                    <label for="startDateFilter" class="block text-sm font-medium text-gray-700 mb-1">تاریخ ثبت (از):</label>
                    <input type="text" id="startDateFilter" name="start_date" value="<?php echo escapeHtml($filterStartDate); ?>"
                        class="w-full p-2 border rounded persian-datepicker" placeholder="YYYY-MM-DD" autocomplete="off">
                </div>
                <div>
                    <label for="endDateFilter" class="block text-sm font-medium text-gray-700 mb-1">تاریخ ثبت (تا):</label>
                    <input type="text" id="endDateFilter" name="end_date" value="<?php echo escapeHtml($filterEndDate); ?>"
                        class="w-full p-2 border rounded persian-datepicker" placeholder="YYYY-MM-DD" autocomplete="off">
                </div>
                <div class="col-span-full text-center mt-4">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg shadow-md">اعمال فیلتر</button>
                    <a href="item_reports.php" class="bg-gray-400 hover:bg-gray-500 text-white font-bold py-2 px-6 rounded-lg shadow-md mr-2">پاک کردن فیلترها</a>
                </div>
            </form>
        </div>

        <?php if (!empty($summaryData)): ?>
            <div class="bg-blue-50 p-4 rounded-lg shadow-md mb-8">
                <h3 class="text-lg font-bold text-blue-800 mb-3">خلاصه گزارش (فیلتر شده):</h3>
                <!-- Summary content here -->
            </div>
        <?php endif; ?>

        <div class="overflow-x-auto bg-white rounded-lg shadow-md">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">آیتم</th>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">کد</th>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">طبقه</th>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">کد موقعیت</th>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تعداد</th>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">وضعیت ارسال</th>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">وضعیت ساخت</th>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">اجزا</th>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">مدارک</th>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تاریخ ثبت</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="report_table_body">
                    <?php if (empty($reportData)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-6 text-gray-500">هیچ گزارشی با فیلترهای انتخاب شده یافت نشد.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reportData as $row): ?>
                            <tr data-item-id="<?php echo escapeHtml($row['id']); ?>">
                                <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-900"><?php echo escapeHtml($row['item_name']); ?></td>
                                <td contenteditable="true" class="py-3 px-4 whitespace-nowrap text-sm text-gray-900 editable" data-field="item_code"><?php echo escapeHtml($row['item_code']); ?></td>
                                <td contenteditable="true" class="py-3 px-4 whitespace-nowrap text-sm text-gray-900 editable" data-field="floor"><?php echo escapeHtml($row['floor']); ?></td>
                                <td contenteditable="true" class="py-3 px-4 whitespace-nowrap text-sm text-gray-900 editable" data-field="location_code"><?php echo escapeHtml($row['location_code'] ?? '-'); ?></td>
                                <td contenteditable="true" class="py-3 px-4 whitespace-nowrap text-sm text-gray-900 editable" data-field="quantity"><?php echo escapeHtml($row['quantity']); ?></td>
                                <td class="py-3 px-4 whitespace-nowrap text-sm editable-checkbox" data-field="status_sent">
                                    <input type="checkbox" <?php echo $row['status_sent'] ? 'checked' : ''; ?> class="form-checkbox h-4 w-4 text-blue-600 rounded">
                                    <span class="mr-1 <?php echo $row['status_sent'] ? 'text-green-600' : 'text-red-600'; ?>"><?php echo $row['status_sent'] ? 'ارسال شده' : 'ارسال نشده'; ?></span>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-sm editable-checkbox" data-field="status_made">
                                    <input type="checkbox" <?php echo $row['status_made'] ? 'checked' : ''; ?> class="form-checkbox h-4 w-4 text-blue-600 rounded">
                                    <span class="mr-1 <?php echo $row['status_made'] ? 'text-green-600' : 'text-red-600'; ?>"><?php echo $row['status_made'] ? 'ساخته شده' : 'ساخته نشده'; ?></span>
                                </td>
                                <td class="py-3 px-4 text-sm text-gray-700">
                                    <?php if (!empty($row['components_summary'])): ?>
                                        <ul class="list-disc list-inside space-y-1 text-xs"><?php
                                                                                            foreach (explode('|||', $row['components_summary']) as $comp) echo '<li>' . escapeHtml($comp) . '</li>';
                                                                                            ?></ul>
                                        <?php else: ?>-<?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-sm text-gray-700">
                                    <?php if (!empty($row['files_summary'])): ?>
                                        <ul class="list-disc list-inside space-y-1 text-xs"><?php
                                                                                            foreach (explode('|||', $row['files_summary']) as $fileEntry) {
                                                                                                list($fileName, $filePath) = explode(':', $fileEntry, 2);
                                                                                                echo '<li><a href="' . escapeHtml($filePath) . '" target="_blank" class="text-blue-600 hover:underline">' . escapeHtml($fileName) . '</a></li>';
                                                                                            }
                                                                                            ?></ul>
                                        <?php else: ?>-<?php endif; ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-900 ltr-text"><?php echo formatShamsi($row['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <link rel="stylesheet" href="/assets/css/persian-datepicker-dark.min.css">
    <script src="/assets/js/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/persian-date.min.js"></script>
    <script src="/assets/js/persian-datepicker.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Date Picker ---
            $(".persian-datepicker").persianDatepicker({
                format: 'YYYY-MM-DD',
                autoClose: true,
                initialValue: false,
                observer: true,
                persianDigit: false,
                calendar: {
                    persian: {
                        locale: 'fa',
                        leapYearMode: 'astronomical'
                    }
                } // Use 'en' locale for gregorian output
            });

            // --- Inline Editing Logic ---
            const reportTableBody = document.getElementById('report_table_body');

            reportTableBody.addEventListener('focusout', function(event) {
                const target = event.target;
                if (target.classList.contains('editable') && target.hasAttribute('contenteditable')) {
                    const originalValue = target.dataset.originalValue || target.textContent;
                    const newValue = target.textContent.trim();
                    if (originalValue !== newValue) {
                        const itemId = target.closest('tr').dataset.itemId;
                        const field = target.dataset.field;
                        sendUpdate(itemId, field, newValue, target);
                    }
                }
            });

            reportTableBody.addEventListener('focusin', function(event) {
                const target = event.target;
                if (target.classList.contains('editable')) {
                    target.dataset.originalValue = target.textContent;
                }
            });

            reportTableBody.addEventListener('change', function(event) {
                const target = event.target;
                if (target.type === 'checkbox' && target.closest('.editable-checkbox')) {
                    const cell = target.closest('.editable-checkbox');
                    const itemId = cell.closest('tr').dataset.itemId;
                    const field = cell.dataset.field;
                    const newValue = target.checked ? 1 : 0;
                    sendUpdate(itemId, field, newValue, cell);
                }
            });

            function sendUpdate(itemId, field, value, element) {
                let originalBg = element.style.backgroundColor;
                element.style.backgroundColor = '#fff3cd'; // Yellow: Saving...

                fetch('update_item_field.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `itemId=${encodeURIComponent(itemId)}&field=${encodeURIComponent(field)}&value=${encodeURIComponent(value)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            element.style.backgroundColor = '#d4edda'; // Green: Success
                            // Update status text for checkboxes
                            if (element.classList.contains('editable-checkbox')) {
                                const statusTextSpan = element.querySelector('span');
                                const checkbox = element.querySelector('input[type=checkbox]');
                                const isChecked = checkbox.checked;
                                statusTextSpan.textContent = isChecked ? (field === 'status_sent' ? 'ارسال شده' : 'ساخته شده') : (field === 'status_sent' ? 'ارسال نشده' : 'ساخته نشده');
                                statusTextSpan.className = 'mr-1 ' + (isChecked ? 'text-green-600' : 'text-red-600');
                            }
                        } else {
                            throw new Error(data.message || 'Unknown error');
                        }
                    })
                    .catch(error => {
                        console.error('Update Error:', error);
                        alert('خطا در ذخیره سازی: ' + error.message);
                        element.style.backgroundColor = '#f8d7da'; // Red: Error
                        // Revert logic would go here if needed
                    })
                    .finally(() => {
                        setTimeout(() => {
                            element.style.backgroundColor = originalBg;
                        }, 2000);
                    });
            }

            // Chart 1: Items per Floor (Bar Chart)
            const floorCtx = document.getElementById('itemsPerFloorChart');
            if (floorCtx && floorChartData.length > 0) {
                const floorLabels = floorChartData.map(item => `طبقه ${item.floor}`);
                const floorQuantities = floorChartData.map(item => item.total_quantity);

                new Chart(floorCtx, {
                    type: 'bar',
                    data: {
                        labels: floorLabels,
                        datasets: [{
                            label: 'تعداد کل آیتم‌ها',
                            data: floorQuantities,
                            backgroundColor: 'rgba(54, 162, 235, 0.6)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            }

            // Chart 2: Sent Status (Doughnut Chart)
            const sentCtx = document.getElementById('sentStatusChart');
            if (sentCtx && statusChartData) {
                new Chart(sentCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['ارسال شده', 'ارسال نشده'],
                        datasets: [{
                            data: [statusChartData.sent_count, statusChartData.not_sent_count],
                            backgroundColor: ['#28a745', '#dc3545'], // Green, Red
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top'
                            }
                        }
                    }
                });
            }

            // Chart 3: Made Status (Doughnut Chart)
            const madeCtx = document.getElementById('madeStatusChart');
            if (madeCtx && statusChartData) {
                new Chart(madeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['ساخته شده', 'ساخته نشده'],
                        datasets: [{
                            data: [statusChartData.made_count, statusChartData.not_made_count],
                            backgroundColor: ['#17a2b8', '#ffc107'], // Teal, Yellow
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top'
                            }
                        }
                    }
                });
            }
        });
    </script>
    <?php require_once __DIR__ . '/footer.php'; ?>
</body>

</html>