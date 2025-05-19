<?php
// admin_formwork_availability.php (v3 - with improved UI for availability tracking)
require_once __DIR__ . '/../../sercon/bootstrap.php'; // Ensure you have your DB connection details
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
// --- Handle POST Request for Changing Available Count ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_available_count') {
    $pdo = getProjectDBConnection();
    $typeToChange = $_POST['formwork_type'] ?? null;
    // Change can be +1 (make available) or -1 (make unavailable)
    $change = filter_input(INPUT_POST, 'change', FILTER_VALIDATE_INT, ['options' => ['min_range' => -1, 'max_range' => 1]]);

    if ($typeToChange && ($change === 1 || $change === -1)) {
        try {
            $pdo->beginTransaction();

            // Lock the row to prevent race conditions
            $stmt_select = $pdo->prepare("SELECT total_count, available_count FROM available_formworks WHERE formwork_type = :type FOR UPDATE");
            $stmt_select->execute([':type' => $typeToChange]);
            $current = $stmt_select->fetch(PDO::FETCH_ASSOC);

            if ($current) {
                $newAvailableCount = $current['available_count'] + $change;

                // Validate the change
                if ($newAvailableCount >= 0 && $newAvailableCount <= $current['total_count']) {
                    $stmt_update = $pdo->prepare("UPDATE available_formworks SET available_count = :new_count WHERE formwork_type = :type");
                    $stmt_update->execute([':new_count' => $newAvailableCount, ':type' => $typeToChange]);
                    $pdo->commit();
                    
                    // Pass the changed formwork type as highlighted parameter
                    header("Location: admin_formwork_availability.php?updated=1&highlighted_type=" . urlencode($typeToChange) . "&action=" . ($change > 0 ? "increment" : "decrement"));
                    exit();
                } else {
                    // Invalid change (e.g., trying to go below 0 or above total)
                    $pdo->rollBack();
                    header("Location: admin_formwork_availability.php?error=3"); // Error code for invalid range
                    exit();
                }
            } else {
                // Formwork type not found
                $pdo->rollBack();
                header("Location: admin_formwork_availability.php?error=4"); // Error code for not found
                exit();
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error changing formwork available count: " . $e->getMessage());
            header("Location: admin_formwork_availability.php?error=1"); // Generic DB error
            exit();
        }
    } else {
        // Invalid data submitted
        header("Location: admin_formwork_availability.php?error=2"); // Invalid input error
        $pdo = null;
        exit();
    }
}

// --- Fetch Data for Display ---
$formworks = [];
$totalTypes = 0;
$totalInstances = 0;
$totalAvailableInstances = 0;
$totalUnavailableInstances = 0;
$availableTypeCount = 0; // Count of types with at least one available
$fullyUnavailableTypeCount = 0; // Count of types with zero available

try {
    $pdo = getProjectDBConnection();
    // Fetch total_count and available_count
    $stmt = $pdo->query("SELECT formwork_type, total_count, available_count FROM available_formworks ORDER BY formwork_type ASC");
    $formworks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pdo = null;

    $totalTypes = count($formworks);
    foreach ($formworks as $fw) {
        $totalInstances += $fw['total_count'];
        $totalAvailableInstances += $fw['available_count'];
        if ($fw['available_count'] > 0) {
            $availableTypeCount++;
        }
        if ($fw['available_count'] == 0) {
             $fullyUnavailableTypeCount++;
        }
    }
    $totalUnavailableInstances = $totalInstances - $totalAvailableInstances;

} catch (PDOException $e) {
    error_log("Error fetching formwork availability: " . $e->getMessage());
    die("Database error loading formwork list.");
}

// Get highlighted item if present
$highlightedType = isset($_GET['highlighted_type']) ? $_GET['highlighted_type'] : null;
$highlightAction = isset($_GET['action']) ? $_GET['action'] : null;

$pageTitle = 'مدیریت موجودی قالب‌ها';
require_once 'header.php'; // Include your standard header

// Add JS for highlighting animation
echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        const highlightedRow = document.getElementById("highlighted-row");
        if (highlightedRow) {
            highlightedRow.scrollIntoView({ behavior: "smooth", block: "center" });
        }
    });
</script>';
?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold mb-6 text-gray-800"><?php echo $pageTitle; ?></h1>

    <?php if (isset($_GET['updated'])): ?>
        <div class="mb-4 p-3 bg-green-100 border border-green-400 text-green-700 rounded flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
            <div>
                <?php if ($highlightedType && $highlightAction): ?>
                    <span class="font-bold"><?php echo htmlspecialchars($highlightedType); ?></span>
                    <?php if ($highlightAction == "increment"): ?>
                        به موجودی قالب‌های در دسترس اضافه شد.
                    <?php else: ?>
                        از موجودی قالب‌های در دسترس کم شد.
                    <?php endif; ?>
                <?php else: ?>
                    موجودی با موفقیت به‌روزرسانی شد.
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): ?>
        <?php
        $errMsg = "خطا در به‌روزرسانی موجودی.";
        if ($_GET['error'] == 2) $errMsg = "اطلاعات ارسالی نامعتبر است.";
        if ($_GET['error'] == 3) $errMsg = "تعداد درخواستی خارج از محدوده مجاز است (کمتر از 0 یا بیشتر از تعداد کل).";
        if ($_GET['error'] == 4) $errMsg = "نوع قالب یافت نشد.";
        ?>
        <div class="mb-4 p-3 bg-red-100 border border-red-400 text-red-700 rounded flex items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
            <div><?php echo $errMsg; ?> لطفاً دوباره امتحان کنید.</div>
        </div>
    <?php endif; ?>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
         <div class="bg-gray-100 p-4 rounded-lg shadow text-center">
            <div class="text-3xl font-bold text-gray-800"><?php echo $totalTypes; ?></div>
            <div class="text-sm text-gray-600">کل انواع قالب</div>
        </div>
        <div class="bg-blue-100 p-4 rounded-lg shadow text-center">
             <div class="text-3xl font-bold text-blue-800"><?php echo $totalInstances; ?></div>
            <div class="text-sm text-blue-600">کل تعداد قالب‌ها (همه انواع)</div>
        </div>
        <div class="bg-green-100 p-4 rounded-lg shadow text-center">
            <div class="text-3xl font-bold text-green-800"><?php echo $totalAvailableInstances; ?></div>
            <div class="text-sm text-green-600">تعداد قالب‌های در دسترس</div>
        </div>
        <div class="bg-red-100 p-4 rounded-lg shadow text-center">
            <div class="text-3xl font-bold text-red-800"><?php echo $totalUnavailableInstances; ?></div>
            <div class="text-sm text-red-600">تعداد قالب‌های خارج از دسترس</div>
        </div>
    </div>
     <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="bg-yellow-100 p-4 rounded-lg shadow text-center">
            <div class="text-xl font-bold text-yellow-800"><?php echo $availableTypeCount; ?></div>
            <div class="text-sm text-yellow-600">تعداد انواع قالب که حداقل یکی در دسترس دارند</div>
        </div>
        <div class="bg-orange-100 p-4 rounded-lg shadow text-center">
            <div class="text-xl font-bold text-orange-800"><?php echo $fullyUnavailableTypeCount; ?></div>
            <div class="text-sm text-orange-600">تعداد انواع قالب که هیچکدام در دسترس نیستند</div>
        </div>
    </div>

    <!-- Filter Controls -->
    <div class="mb-6">
        <div class="flex flex-wrap gap-2 justify-start">
            <button id="show-all" class="px-3 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-md text-sm font-medium">نمایش همه</button>
            <button id="show-only-available" class="px-3 py-2 bg-green-100 hover:bg-green-200 text-green-800 rounded-md text-sm font-medium">فقط قابل دسترس</button>
            <button id="show-only-unavailable" class="px-3 py-2 bg-red-100 hover:bg-red-200 text-red-800 rounded-md text-sm font-medium">فقط غیر قابل دسترس</button>
            <button id="show-partially-available" class="px-3 py-2 bg-yellow-100 hover:bg-yellow-200 text-yellow-800 rounded-md text-sm font-medium">قالب‌های با موجودی جزئی</button>
        </div>
    </div>

    <!-- Search Box -->
    <div class="mb-6">
        <div class="relative">
            <input type="text" id="formwork-search" placeholder="جستجوی نوع قالب..." class="w-full p-3 pl-10 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Formwork Table -->
    <div class="bg-white p-4 rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="formwork-table">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نوع قالب</th>
                        <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">وضعیت</th>
                        <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">موجودی (در دسترس / کل)</th>
                        <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">عملیات</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($formworks)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center italic">هیچ قالبی یافت نشد.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($formworks as $fw):
                            $canMakeUnavailable = $fw['available_count'] > 0;
                            $canMakeAvailable = $fw['available_count'] < $fw['total_count'];
                            $availabilityRatio = $fw['total_count'] > 0 ? ($fw['available_count'] / $fw['total_count']) * 100 : 0;
                            
                            // Determine availability class
                            $rowClass = '';
                            $availabilityClass = '';
                            $availabilityText = '';
                            
                            if ($fw['available_count'] == 0) {
                                $rowClass = 'formwork-unavailable bg-red-50';
                                $availabilityClass = 'bg-red-100 text-red-800';
                                $availabilityText = 'غیر قابل دسترس';
                            } elseif ($fw['available_count'] == $fw['total_count']) {
                                $rowClass = 'formwork-fully-available bg-green-50';
                                $availabilityClass = 'bg-green-100 text-green-800';
                                $availabilityText = 'کاملاً در دسترس';
                            } else {
                                $rowClass = 'formwork-partially-available bg-yellow-50';
                                $availabilityClass = 'bg-yellow-100 text-yellow-800';
                                $availabilityText = 'موجودی جزئی';
                            }
                            
                            // Check if this row should be highlighted
                            $isHighlighted = ($highlightedType === $fw['formwork_type']);
                            if ($isHighlighted) {
                                $rowClass .= ' transition-all duration-1000';
                            }
                        ?>
                            <tr id="<?php echo $isHighlighted ? 'highlighted-row' : ''; ?>" 
                                class="<?php echo $rowClass; ?> <?php echo $isHighlighted ? 'ring-2 ring-offset-2 ring-blue-500' : ''; ?>"
                                data-formwork-type="<?php echo htmlspecialchars($fw['formwork_type']); ?>">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($fw['formwork_type']); ?>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-xs text-center">
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $availabilityClass; ?>">
                                        <?php echo $availabilityText; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <!-- Progress bar -->
                                    <div class="flex items-center">
                                        <div class="w-full bg-gray-200 rounded-full h-2.5 mr-2">
                                            <div class="h-2.5 rounded-full 
                                                <?php 
                                                    if ($availabilityRatio == 0) echo 'bg-red-500';
                                                    elseif ($availabilityRatio < 50) echo 'bg-orange-500';
                                                    elseif ($availabilityRatio < 100) echo 'bg-yellow-500';
                                                    else echo 'bg-green-500';
                                                ?>" 
                                                style="width: <?php echo $availabilityRatio; ?>%">
                                            </div>
                                        </div>
                                        <div class="flex-shrink-0 w-16 text-sm">
                                            <span class="font-semibold <?php echo $fw['available_count'] > 0 ? 'text-green-700' : 'text-red-700'; ?>">
                                                <?php echo htmlspecialchars($fw['available_count']); ?>
                                            </span> / <?php echo htmlspecialchars($fw['total_count']); ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-center space-x-2 space-x-reverse">
                                    <!-- Make One Unavailable Button -->
                                    <form method="POST" action="admin_formwork_availability.php" class="inline-block">
                                        <input type="hidden" name="action" value="change_available_count">
                                        <input type="hidden" name="formwork_type" value="<?php echo htmlspecialchars($fw['formwork_type']); ?>">
                                        <input type="hidden" name="change" value="-1">
                                        <button type="submit"
                                                class="text-red-600 hover:text-red-900 transition duration-150 ease-in-out px-3 py-1 bg-red-100 hover:bg-red-200 border border-red-300 rounded shadow-sm text-xs disabled:opacity-50 disabled:cursor-not-allowed flex items-center"
                                                <?php echo !$canMakeUnavailable ? 'disabled' : ''; ?>>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                                            </svg>
                                            خارج از دسترس
                                        </button>
                                    </form>

                                    <!-- Make One Available Button -->
                                    <form method="POST" action="admin_formwork_availability.php" class="inline-block">
                                        <input type="hidden" name="action" value="change_available_count">
                                        <input type="hidden" name="formwork_type" value="<?php echo htmlspecialchars($fw['formwork_type']); ?>">
                                        <input type="hidden" name="change" value="1">
                                        <button type="submit"
                                                class="text-green-600 hover:text-green-900 transition duration-150 ease-in-out px-3 py-1 bg-green-100 hover:bg-green-200 border border-green-300 rounded shadow-sm text-xs disabled:opacity-50 disabled:cursor-not-allowed flex items-center"
                                                <?php echo !$canMakeAvailable ? 'disabled' : ''; ?>>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                            </svg>
                                            در دسترس
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- JavaScript for interactivity -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Filter functionality
    const showAll = document.getElementById('show-all');
    const showOnlyAvailable = document.getElementById('show-only-available');
    const showOnlyUnavailable = document.getElementById('show-only-unavailable');
    const showPartiallyAvailable = document.getElementById('show-partially-available');
    const searchInput = document.getElementById('formwork-search');
    
    // Highlight active filter
    function setActiveFilter(activeButton) {
        [showAll, showOnlyAvailable, showOnlyUnavailable, showPartiallyAvailable].forEach(btn => {
            if (btn === activeButton) {
                btn.classList.add('ring-2', 'ring-offset-2');
                if (btn === showAll) btn.classList.add('ring-gray-500');
                if (btn === showOnlyAvailable) btn.classList.add('ring-green-500');
                if (btn === showOnlyUnavailable) btn.classList.add('ring-red-500');
                if (btn === showPartiallyAvailable) btn.classList.add('ring-yellow-500');
            } else {
                btn.classList.remove('ring-2', 'ring-offset-2', 'ring-gray-500', 'ring-green-500', 'ring-red-500', 'ring-yellow-500');
            }
        });
    }
    
    // Filter and search function
    function applyFilters() {
        const searchTerm = searchInput.value.toLowerCase();
        const rows = document.querySelectorAll('#formwork-table tbody tr');
        
        rows.forEach(row => {
            const formworkType = row.querySelector('td:first-child').textContent.toLowerCase();
            const isFullyAvailable = row.classList.contains('formwork-fully-available');
            const isUnavailable = row.classList.contains('formwork-unavailable');
            const isPartiallyAvailable = row.classList.contains('formwork-partially-available');
            
            let shouldShow = true;
            
            // Apply active filter
            if (showOnlyAvailable.classList.contains('ring-2') && !isFullyAvailable && !isPartiallyAvailable) {
                shouldShow = false;
            } else if (showOnlyUnavailable.classList.contains('ring-2') && !isUnavailable) {
                shouldShow = false;
            } else if (showPartiallyAvailable.classList.contains('ring-2') && !isPartiallyAvailable) {
                shouldShow = false;
            }
            
            // Apply search filter
            if (searchTerm && !formworkType.includes(searchTerm)) {
                shouldShow = false;
            }
            
            row.style.display = shouldShow ? '' : 'none';
        });
        
        // Show "no results" message if needed
        const visibleRows = document.querySelectorAll('#formwork-table tbody tr:not([style*="display: none"])');
        const noResultsRow = document.querySelector('#no-results-row');
        
        if (visibleRows.length === 0) {
            if (!noResultsRow) {
                const tbody = document.querySelector('#formwork-table tbody');
                const tr = document.createElement('tr');
                tr.id = 'no-results-row';
                tr.innerHTML = '<td colspan="4" class="px-6 py-4 text-center text-gray-500">هیچ نتیجه‌ای یافت نشد</td>';
                tbody.appendChild(tr);
            } else {
                noResultsRow.style.display = '';
            }
        } else if (noResultsRow) {
            noResultsRow.style.display = 'none';
        }
    }
    
    // Event listeners
    showAll.addEventListener('click', function() {
        setActiveFilter(showAll);
        applyFilters();
    });
    
    showOnlyAvailable.addEventListener('click', function() {
        setActiveFilter(showOnlyAvailable);
        applyFilters();
    });
    
    showOnlyUnavailable.addEventListener('click', function() {
        setActiveFilter(showOnlyUnavailable);
        applyFilters();
    });
    
    showPartiallyAvailable.addEventListener('click', function() {
        setActiveFilter(showPartiallyAvailable);
        applyFilters();
    });
    
    searchInput.addEventListener('input', applyFilters);
    
    // Initialize with all showing
    setActiveFilter(showAll);
    
    // If there's a highlighted row, make sure we wait until animation is done
    const highlightedRow = document.getElementById('highlighted-row');
    if (highlightedRow) {
        setTimeout(function() {
            highlightedRow.classList.remove('ring-2', 'ring-offset-2', 'ring-blue-500');
        }, 3000); // Remove highlight after 3 seconds
    }
});
</script>

<?php require_once 'footer.php'; // Include your standard footer ?>