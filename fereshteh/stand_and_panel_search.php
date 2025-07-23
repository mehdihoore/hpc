<?php
ini_set('display_errors', 1); // Enable error display for debugging
error_reporting(E_ALL);
ob_start(); // Start output buffering

// --- AJAX REQUEST HANDLING ---
// This block is now at the top to handle AJAX requests before any HTML is sent.
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    // Set content type to JSON immediately
    header('Content-Type: application/json');
    $pdo = null; // Initialize PDO variable for this scope

    try {
        // --- BOOTSTRAP AND DB CONNECTION FOR AJAX ---
        require_once __DIR__ . '/../../sercon/bootstrap.php';
        secureSession(); // Ensure session is secure

        // Basic check if user is logged in for the API endpoint
        if (!isLoggedIn()) {
            http_response_code(403); // Forbidden
            echo json_encode(['success' => false, 'message' => 'Access Denied. Please log in.']);
            exit();
        }

        $pdo = getProjectDBConnection(); // Get DB connection

        // --- QUERY LOGIC ---
        // Base query now fetches all data for client-side filtering. Position column removed.
        $sql = "
            SELECT
                psa.stand_id,
                psa.panel_id,
                hp.address AS panel_address,
                hp.type AS panel_type,
                hp.area AS panel_area,
                hp.width AS panel_width,
                hp.length AS panel_length,
                hp.Proritization AS panel_status,
                psa.truck_id,
                psa.shipment_id
            FROM
                panel_stand_assignments psa
            LEFT JOIN
                hpc_panels hp ON psa.panel_id = hp.id
            ORDER BY psa.stand_id, hp.address
        "; // Simple default order

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Send successful JSON response
        echo json_encode(['success' => true, 'data' => $results]);
    } catch (Throwable $e) { // Catch any error or exception
        if (function_exists('logError')) {
            logError("AJAX Error in stand_and_panel_search.php: " . $e->getMessage() . " on line " . $e->getLine());
        }

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'A server error occurred: ' . $e->getMessage()
        ]);
    }
    exit(); // Crucial: stop script execution here for all AJAX requests
}


// --- FULL PAGE LOAD LOGIC (if not an AJAX request) ---

require_once __DIR__ . '/../../sercon/bootstrap.php';

secureSession();
$expected_project_key = 'fereshteh'; // Or your relevant project key
$current_project_config_key = $_SESSION['current_project_config_key'] ?? null;

if (!isLoggedIn()) {
    header('Location: /login.php');
    exit();
}

if ($current_project_config_key !== $expected_project_key) {
    logError("Stand search page accessed with incorrect project context. Session: {$current_project_config_key}, Expected: {$expected_project_key}, User: {$_SESSION['user_id']}");
}

// --- ROLE-BASED ACCESS CONTROL ---
$all_view_roles = ['admin', 'superuser', 'planner', 'user', 'supervisor', 'receiver'];
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $all_view_roles)) {
    header('Location: /login');
    exit('Access Denied.');
}

// --- PAGE SETUP ---
$pageTitle = 'جستجوی پنل و خرک'; // Persian Title
if ($_SESSION['current_project_config_key'] === 'fereshteh') {
    $pageTitle .= ' - پروژه فرشته';
} elseif ($_SESSION['current_project_config_key'] === 'arad') {
    $pageTitle .= ' - پروژه آراد';
}

require_once 'header.php'; // Include your standard header

?>
<!-- Include necessary CSS -->
<link href="/assets/css/bootstrap.rtl.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    body {
        font-family: 'Vazir', sans-serif;
    }

    .search-container {
        max-width: 100%;
        margin: 1rem auto;
        padding: 0 0.5rem;
    }

    /* Mobile-first approach */
    .mobile-filters {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .filter-toggle {
        background: linear-gradient(135deg, #007bff, #0056b3);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 0.5rem;
        font-size: 1rem;
        width: 100%;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
    }

    .filter-toggle:hover {
        background: linear-gradient(135deg, #0056b3, #004085);
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }

    .filter-toggle.active {
        background: linear-gradient(135deg, #28a745, #1e7e34);
    }

    .mobile-filter-group {
        margin-bottom: 1rem;
    }

    .mobile-filter-group label {
        font-weight: 600;
        color: #495057;
        margin-bottom: 0.5rem;
        display: block;
        font-size: 0.9rem;
    }

    .mobile-filter-group input {
        width: 100%;
        padding: 0.75rem;
        border: 2px solid #e9ecef;
        border-radius: 0.5rem;
        font-size: 1rem;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .mobile-filter-group input:focus {
        border-color: #007bff;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        outline: none;
    }

    .filter-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 1rem;
    }

    .filter-actions button {
        flex: 1;
        padding: 0.75rem;
        border-radius: 0.5rem;
        font-size: 0.9rem;
        font-weight: 600;
        border: none;
        transition: all 0.3s ease;
    }

    .btn-clear {
        background-color: #6c757d;
        color: white;
    }

    .btn-clear:hover {
        background-color: #545b62;
        transform: translateY(-1px);
    }

    .btn-search {
        background: linear-gradient(135deg, #28a745, #1e7e34);
        color: white;
    }

    .btn-search:hover {
        background: linear-gradient(135deg, #1e7e34, #155724);
        transform: translateY(-1px);
    }

    /* Table styling for mobile */
    .table-controls {
        padding: 0.75rem;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-bottom: none;
        border-radius: 0.5rem 0.5rem 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .table-controls h4 {
        margin: 0;
        font-size: 1.1rem;
    }

    .results-table {
        font-size: 0.85rem;
    }

    .results-table thead th {
        background-color: #f8f9fa;
        position: sticky;
        top: 0;
        z-index: 10;
        vertical-align: middle;
        padding: 0.75rem 0.5rem;
        white-space: nowrap;
    }

    .results-table th.sortable {
        cursor: pointer;
        padding-right: 30px;
        position: relative;
        user-select: none;
    }

    .results-table th.sortable .sort-icon {
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        opacity: 0.4;
        transition: opacity 0.3s ease;
    }

    .results-table th.sortable:hover .sort-icon {
        opacity: 0.7;
    }

    .results-table th.sortable.sort-asc .sort-icon,
    .results-table th.sortable.sort-desc .sort-icon {
        opacity: 1;
        color: #007bff;
    }

    .table-responsive {
        max-height: 70vh;
        border: 1px solid #dee2e6;
        border-top: none;
        border-radius: 0 0 0.5rem 0.5rem;
    }

    .results-table td {
        padding: 0.75rem 0.5rem;
        vertical-align: middle;
    }

    #loading-indicator {
        display: none;
        text-align: center;
        padding: 2rem;
    }

    .stand-badge {
        display: inline-block;
        min-width: 60px;
        font-size: 0.9em;
        padding: 0.4em 0.6em;
        background: linear-gradient(135deg, #e9ecef, #f8f9fa);
        color: #495057;
        border: 1px solid #ced4da;
        border-radius: 0.25rem;
        font-weight: bold;
        text-align: center;
    }

    .results-count {
        background: linear-gradient(135deg, #e3f2fd, #f3e5f5);
        border: 1px solid #bbdefb;
        border-radius: 0.5rem;
        padding: 0.75rem;
        margin-top: 1rem;
        text-align: center;
        font-weight: 600;
    }

    /* Hide desktop filters on mobile */
    .desktop-filters {
        display: none;
    }

    /* Responsive design */
    @media (min-width: 768px) {
        .search-container {
            padding: 0 1rem;
        }

        .mobile-filters {
            display: none;
        }

        .desktop-filters {
            display: table-row;
        }

        .filter-toggle {
            display: none;
        }

        .results-table {
            font-size: 0.9rem;
        }

        .results-table thead th {
            padding: 0.75rem;
        }

        .results-table td {
            padding: 0.75rem;
        }

        .stand-badge {
            min-width: 80px;
            font-size: 1em;
            padding: 0.4em 0.8em;
        }
    }

    @media (min-width: 1200px) {
        .search-container {
            max-width: 1400px;
            padding: 0 2rem;
        }

        .results-table {
            font-size: 1rem;
        }
    }

    /* Improved mobile table scrolling */
    @media (max-width: 767px) {
        .table-responsive {
            border-radius: 0.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .results-table {
            min-width: 800px;
        }

        .results-table th,
        .results-table td {
            white-space: nowrap;
        }

        .table-controls {
            border-radius: 0.5rem 0.5rem 0 0;
        }

        .table-controls h4 {
            font-size: 1rem;
        }
    }

    /* Animation for filter panel */
    .mobile-filter-content {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease-out;
    }

    .mobile-filter-content.show {
        max-height: 500px;
    }

    /* Improved focus styles for accessibility */
    .mobile-filter-group input:focus,
    .filter-toggle:focus,
    .btn:focus {
        outline: 2px solid #007bff;
        outline-offset: 2px;
    }
</style>

<div class="search-container container-fluid mt-2" dir="rtl">
    <!-- Mobile Filter Panel -->
    <div class="mobile-filters">
        <button type="button" class="filter-toggle" id="mobile-filter-toggle">
            <i class="fa fa-filter"></i>
            <span>فیلترهای جستجو</span>
            <i class="fa fa-chevron-down" id="filter-chevron"></i>
        </button>

        <div class="mobile-filter-content" id="mobile-filter-content">
            <div class="mobile-filter-group">
                <label for="mobile-stand-filter">شماره خرک</label>
                <input type="text" id="mobile-stand-filter" class="mobile-filter-input" data-filter-key="stand_id" placeholder="جستجو در شماره خرک...">
            </div>

            <div class="mobile-filter-group">
                <label for="mobile-address-filter">آدرس پنل</label>
                <input type="text" id="mobile-address-filter" class="mobile-filter-input" data-filter-key="panel_address" placeholder="جستجو در آدرس پنل...">
            </div>

            <div class="mobile-filter-group">
                <label for="mobile-type-filter">نوع پنل</label>
                <input type="text" id="mobile-type-filter" class="mobile-filter-input" data-filter-key="panel_type" placeholder="جستجو در نوع پنل...">
            </div>

            <div class="mobile-filter-group">
                <label for="mobile-dimensions-filter">ابعاد</label>
                <input type="text" id="mobile-dimensions-filter" class="mobile-filter-input" data-filter-key="dimensions" placeholder="جستجو در ابعاد...">
            </div>

            <div class="mobile-filter-group">
                <label for="mobile-area-filter">مساحت</label>
                <input type="text" id="mobile-area-filter" class="mobile-filter-input" data-filter-key="panel_area" placeholder="جستجو در مساحت...">
            </div>

            <div class="mobile-filter-group">
                <label for="mobile-status-filter">زون</label>
                <input type="text" id="mobile-status-filter" class="mobile-filter-input" data-filter-key="panel_status" placeholder="جستجو در زون...">
            </div>

            <div class="mobile-filter-group">
                <label for="mobile-shipment-filter">شماره پکینگ لیست</label>
                <input type="text" id="mobile-shipment-filter" class="mobile-filter-input" data-filter-key="shipment_id" placeholder="جستجو در پکینگ لیست...">
            </div>

            <div class="filter-actions">
                <button type="button" class="btn-clear" id="mobile-clear-filters">
                    <i class="fa fa-times"></i> پاک کردن
                </button>
                <button type="button" class="btn-search" id="mobile-apply-filters">
                    <i class="fa fa-search"></i> اعمال فیلتر
                </button>
            </div>
        </div>
    </div>

    <div id="loading-indicator" class="mt-4">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <p class="mt-2">در حال بارگذاری نتایج...</p>
    </div>

    <div id="results-container" class="mt-4" style="display: none;">
        <div class="table-controls">
            <h4 class="mb-0">لیست تخصیص پنل و خرک</h4>
            <button id="desktop-clear-filters-btn" class="btn btn-secondary btn-sm d-none d-md-inline-block">
                <i class="fa fa-times"></i> پاک کردن فیلترها
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-bordered table-hover results-table" id="main-table">
                <thead class="thead-light">
                    <tr>
                        <th class="sortable" data-sort-key="stand_id">شماره خرک <i class="fa fa-sort sort-icon"></i></th>
                        <th class="sortable" data-sort-key="panel_address">آدرس پنل <i class="fa fa-sort sort-icon"></i></th>
                        <th class="sortable" data-sort-key="panel_type">نوع پنل <i class="fa fa-sort sort-icon"></i></th>
                        <th class="sortable" data-sort-key="panel_width_num">ابعاد (mm) <i class="fa fa-sort sort-icon"></i></th>
                        <th class="sortable" data-sort-key="panel_area_num">مساحت (m²) <i class="fa fa-sort sort-icon"></i></th>
                        <th class="sortable" data-sort-key="panel_status">زون <i class="fa fa-sort sort-icon"></i></th>
                        <th class="sortable" data-sort-key="shipment_id">شماره پکینگ لیست <i class="fa fa-sort sort-icon"></i></th>
                    </tr>
                    <tr class="filter-row desktop-filters">
                        <th><input type="text" class="form-control form-control-sm desktop-filter-input" data-filter-key="stand_id"></th>
                        <th><input type="text" class="form-control form-control-sm desktop-filter-input" data-filter-key="panel_address"></th>
                        <th><input type="text" class="form-control form-control-sm desktop-filter-input" data-filter-key="panel_type"></th>
                        <th><input type="text" class="form-control form-control-sm desktop-filter-input" data-filter-key="dimensions"></th>
                        <th><input type="text" class="form-control form-control-sm desktop-filter-input" data-filter-key="panel_area"></th>
                        <th><input type="text" class="form-control form-control-sm desktop-filter-input" data-filter-key="panel_status"></th>
                        <th><input type="text" class="form-control form-control-sm desktop-filter-input" data-filter-key="shipment_id"></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Table body will be populated by JS -->
                </tbody>
            </table>
        </div>
        <div id="results-count" class="results-count"></div>
    </div>
</div>

<!-- Include jQuery and Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    $(document).ready(function() {
        const resultsContainer = $('#results-container');
        const loadingIndicator = $('#loading-indicator');
        const tableBody = $('#main-table tbody');
        const resultsCountEl = $('#results-count');
        const mobileFilterContent = $('#mobile-filter-content');
        const mobileFilterToggle = $('#mobile-filter-toggle');
        const filterChevron = $('#filter-chevron');

        let allData = [];
        let currentSort = {
            key: 'stand_id',
            direction: 'asc'
        };

        // Mobile filter toggle functionality
        mobileFilterToggle.on('click', function() {
            const isOpen = mobileFilterContent.hasClass('show');
            if (isOpen) {
                mobileFilterContent.removeClass('show');
                filterChevron.removeClass('fa-chevron-up').addClass('fa-chevron-down');
                mobileFilterToggle.removeClass('active');
            } else {
                mobileFilterContent.addClass('show');
                filterChevron.removeClass('fa-chevron-down').addClass('fa-chevron-up');
                mobileFilterToggle.addClass('active');
            }
        });

        function loadInitialData() {
            loadingIndicator.show();
            resultsContainer.hide();

            $.ajax({
                url: window.location.pathname,
                type: 'GET',
                dataType: 'json',
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                },
                success: function(response) {
                    if (response.success) {
                        allData = response.data.map(item => ({
                            ...item,
                            panel_width_num: parseInt(item.panel_width, 10),
                            panel_length_num: parseInt(item.panel_length, 10),
                            panel_area_num: parseFloat(item.panel_area)
                        }));
                        updateTable();
                        resultsContainer.show();
                    } else {
                        renderError(response.message || 'An unknown error occurred.');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                    let msg = jqXHR.responseJSON?.message || 'Failed to fetch data from the server.';
                    renderError(msg);
                },
                complete: function() {
                    loadingIndicator.hide();
                }
            });
        }

        function getActiveFilters() {
            const filters = {};

            // Get filters from both mobile and desktop inputs
            $('.mobile-filter-input, .desktop-filter-input').each(function() {
                const key = $(this).data('filter-key');
                const value = $(this).val().trim().toLowerCase();
                if (value) {
                    filters[key] = value;
                }
            });

            return filters;
        }

        function updateTable() {
            // 1. Get Filters
            const filters = getActiveFilters();

            // 2. Filter Data
            const filteredData = allData.filter(item => {
                return Object.keys(filters).every(key => {
                    let itemValue;
                    if (key === 'dimensions') {
                        itemValue = `${item.panel_width_num} × ${item.panel_length_num}`;
                    } else {
                        itemValue = item[key] ? item[key].toString().toLowerCase() : '';
                    }
                    return itemValue.includes(filters[key]);
                });
            });

            // 3. Sort Data
            filteredData.sort((a, b) => {
                let valA = a[currentSort.key];
                let valB = b[currentSort.key];

                // Handle numeric sort for specific keys
                if (['stand_id', 'panel_width_num', 'panel_area_num', 'shipment_id'].includes(currentSort.key)) {
                    valA = Number(valA) || 0;
                    valB = Number(valB) || 0;
                } else {
                    valA = (valA || '').toString();
                    valB = (valB || '').toString();
                }

                let comparison = 0;
                if (valA > valB) {
                    comparison = 1;
                } else if (valA < valB) {
                    comparison = -1;
                }
                return currentSort.direction === 'desc' ? comparison * -1 : comparison;
            });

            // 4. Render Table
            renderTableBody(filteredData);
        }

        function renderTableBody(data) {
            tableBody.empty();
            if (data.length === 0) {
                tableBody.html('<tr><td colspan="7" class="text-center text-muted py-4">هیچ نتیجه‌ای یافت نشد.</td></tr>');
                resultsCountEl.html('<i class="fa fa-info-circle"></i> تعداد نتایج: 0');
                return;
            }

            const rowsHtml = data.map(row => `
                <tr>
                    <td class="text-center"><span class="stand-badge">${escapeHtml(row.stand_id)}</span></td>
                    <td>${escapeHtml(row.panel_address)}</td>
                    <td>${escapeHtml(row.panel_type)}</td>
                    <td class="text-center">${escapeHtml(row.panel_width_num)} × ${escapeHtml(row.panel_length_num)}</td>
                    <td class="text-center">${escapeHtml(row.panel_area_num.toFixed(3))}</td>
                    <td>${escapeHtml(row.panel_status)}</td>
                    <td class="text-center">${escapeHtml(row.shipment_id)}</td>
                </tr>
            `).join('');

            tableBody.html(rowsHtml);
            resultsCountEl.html(`<i class="fa fa-check-circle text-success"></i> تعداد نتایج: <strong>${data.length}</strong>`);
        }

        function renderError(message) {
            resultsContainer.html(`<div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> <b>خطا:</b> ${escapeHtml(message)}</div>`).show();
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>"']/g, function(match) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [match];
            });
        }

        function syncFilters(sourceElement) {
            const filterKey = $(sourceElement).data('filter-key');
            const value = $(sourceElement).val();

            // Sync between mobile and desktop filters
            $(`.mobile-filter-input[data-filter-key="${filterKey}"], .desktop-filter-input[data-filter-key="${filterKey}"]`)
                .not(sourceElement)
                .val(value);
        }

        function clearAllFilters() {
            $('.mobile-filter-input, .desktop-filter-input').val('');
            updateTable();

            // Close mobile filter panel after clearing
            if (mobileFilterContent.hasClass('show')) {
                mobileFilterToggle.click();
            }
        }

        // --- Event Listeners ---

        // Desktop filter inputs (real-time filtering)
        $('.desktop-filter-input').on('keyup', debounce(function() {
            syncFilters(this);
            updateTable();
        }, 250));

        // Mobile filter inputs (sync only, apply on button click)
        $('.mobile-filter-input').on('input', function() {
            syncFilters(this);
        });

        // Mobile apply filters button
        $('#mobile-apply-filters').on('click', function() {
            updateTable();
            mobileFilterToggle.click(); // Close the filter panel
        });

        // Clear filters buttons
        $('#mobile-clear-filters, #desktop-clear-filters-btn').on('click', clearAllFilters);

        // Sorting functionality
        $('th.sortable').on('click', function() {
            const newSortKey = $(this).data('sort-key');

            if (currentSort.key === newSortKey) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.key = newSortKey;
                currentSort.direction = 'asc';
            }

            // Update UI for sort icons
            $('th.sortable').removeClass('sort-asc sort-desc');
            $('th.sortable .sort-icon').removeClass('fa-sort-up fa-sort-down').addClass('fa-sort');

            $(this).addClass(`sort-${currentSort.direction}`);
            $(this).find('.sort-icon').removeClass('fa-sort').addClass(
                currentSort.direction === 'asc' ? 'fa-sort-up' : 'fa-sort-down'
            );

            updateTable();
        });

        // Enter key support for mobile filters
        $('.mobile-filter-input').on('keypress', function(e) {
            if (e.which === 13) { // Enter key
                $('#mobile-apply-filters').click();
            }
        });

        function debounce(func, delay) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), delay);
            };
        }

        // --- Initial Load ---
        loadInitialData();

        // Add touch-friendly scrolling hint for mobile
        if (window.innerWidth <= 767) {
            setTimeout(function() {
                if (allData.length > 0) {
                    const scrollHint = $('<div class="alert alert-info alert-dismissible fade show mt-2" role="alert">' +
                        '<i class="fa fa-info-circle"></i> ' +
                        'برای مشاهده تمام ستون‌ها، جدول را به چپ و راست بکشید.' +
                        '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                        '</div>');
                    $('#results-container').append(scrollHint);

                    // Auto-hide after 5 seconds
                    setTimeout(function() {
                        scrollHint.alert('close');
                    }, 5000);
                }
            }, 1000);
        }
    });
</script>

<?php require_once 'footer.php'; ?>