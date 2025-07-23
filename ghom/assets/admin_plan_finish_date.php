<?php


// --- Function for date conversion (if not already included) ---
if (!function_exists('gregorianToShamsi')) {
    function gregorianToShamsi($date)
    {
        if (empty($date) || $date == '0000-00-00') {
            return ''; // Return empty for invalid input
        }
        try {
            // Use PHP's DateTime for parsing
            $dt = new DateTime($date);
            $year = (int)$dt->format('Y');
            $month = (int)$dt->format('m');
            $day = (int)$dt->format('d');

            // --- Accurate Gregorian to Shamsi Calculation Logic ---
            $gDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
            $gy = $year - 1600;
            $gm = $month - 1;
            $gd = $day - 1;

            $gDayNo = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
            $gDayNo += $gDays[$gm];
            if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) {
                $gDayNo++; // Leap year correction
            }
            $gDayNo += $gd;

            $jDayNo = $gDayNo - 79;
            $jNp = floor($jDayNo / 12053); // Number of 33-year cycles
            $jDayNo = $jDayNo % 12053;

            $jy = 979 + 33 * $jNp + 4 * floor($jDayNo / 1461); // Calculate the year
            $jDayNo %= 1461;

            if ($jDayNo >= 366) {
                $jy += floor(($jDayNo - 1) / 365);
                $jDayNo = ($jDayNo - 1) % 365;
            }

            // Determine month and day
            $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
            for ($i = 0; $i < 12; $i++) {
                if ($jDayNo < $jDaysInMonth[$i]) {
                    break;
                }
                $jDayNo -= $jDaysInMonth[$i];
            }
            $jm = $i + 1;
            $jd = $jDayNo + 1;

            return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
            // --- End of Calculation Logic ---

        } catch (Exception $e) {
            error_log("Error in gregorianToShamsi for date '$date': " . $e->getMessage()); // Log error
            return 'تاریخ نامعتبر'; // Return error string
        }
    }
}


?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Add your CSS links here (FullCalendar, Tailwind, custom styles) -->
    <link href="https://cdn.jsdelivr.net/npm/@fullcalendar/core@5.11.3/main.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/@fullcalendar/daygrid@5.11.3/main.min.css" rel="stylesheet" />

    <!-- Add Font from jsdelivr -->
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazir-font@v30.1.0/dist/font-face.css" rel="stylesheet" type="text/css" />

    <!-- Load scripts from jsdelivr -->
    <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/daygrid@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@fullcalendar/interaction@5.11.3/main.min.js"></script>

    <!-- Load jQuery from code.jquery.com (allowed in CSP) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Load other local resources -->
    <link href="css/tailwind.min.css" rel="stylesheet">
    <link href="css/fullmain.min.css" rel="stylesheet">
    <link href="css/persian-datepicker.min.css" rel="stylesheet">

    <!-- Add FontAwesome for icons (from jsdelivr) -->
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css" rel="stylesheet">

    <style>
        /* Add necessary styles from admin_assign_date.php or customize */
        body {
            font-family: 'Vazir', sans-serif;
        }

        .panel-list-item {
            /* Style for list items */
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border: 1px solid #e2e8f0;
            margin-bottom: 8px;
            border-radius: 6px;
            background-color: white;
            cursor: grab;
            /* Show that the panel is draggable */
        }

        .panel-list-item:active {
            cursor: grabbing;
            /* Change cursor when dragging */
        }

        .panel-info {
            flex-grow: 1;
            margin-right: 15px;
        }

        .panel-actions input[type="number"] {
            width: 70px;
            /* Adjust width as needed */
            padding: 5px;
            border: 1px solid #cbd5e0;
            border-radius: 4px;
            text-align: center;
            margin-left: 5px;
        }

        .panel-actions button {
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: background-color 0.2s;
        }

        .set-finish-btn {
            background-color: #3b82f6;
            color: white;
        }

        .set-finish-btn:hover {
            background-color: #2563eb;
        }

        .set-finish-btn:disabled {
            background-color: #9ca3af;
            cursor: not-allowed;
        }

        /* Add other required styles from admin_assign_date.php (header, nav, layout etc.) */


        /* Minimal required styles - ADD MORE FROM admin_assign_date.php */
        body {
            font-family: 'Vazir', sans-serif;
            background-color: #f3f4f6;
        }

        .container {
            max-width: 1200px;
        }

        .fc-event {
            cursor: pointer;
        }

        /* Allow clicking calendar events */
        .toast {
            /* Simple toast notification style */
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            z-index: 1050;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
        }

        .toast.show {
            opacity: 1;
        }

        .panel-list-item.hidden {
            display: none;
        }

        .search-highlight {
            background-color: #fde68a;
            /* yellow highlight */
            font-weight: bold;
        }

        #panelListStats {
            /* Style for the count display */
            font-size: 0.875rem;
            color: #6b7280;
            /* gray-500 */
            margin-bottom: 0.75rem;
            /* mb-3 */
            padding: 0 0.5rem;
            /* px-2 */
        }

        .filter-controls input[type="text"] {
            width: 100%;
            padding: 0.5rem;
            /* p-2 */
            border: 1px solid #d1d5db;
            /* border-gray-300 */
            border-radius: 0.375rem;
            /* rounded-md */
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .filter-controls label {
            display: block;
            margin-bottom: 0.25rem;
            /* mb-1 */
            font-size: 0.875rem;
            /* text-sm */
            font-weight: 500;
            /* font-medium */
            color: #374151;
            /* gray-700 */
        }

        /* New styles for drag and drop */
        .panel-list-item.fc-dragging {
            opacity: 0.7;
            background-color: #f3f4f6;
        }

        /* Legend for calendar events */
        .calendar-legend {
            display: flex;
            justify-content: flex-start;
            gap: 1rem;
            margin-bottom: 0.5rem;
            padding: 0.5rem;
        }

        .legend-item {
            display: flex;
            align-items: center;
            font-size: 0.75rem;
            color: #374151;
        }

        .legend-color {
            width: 1rem;
            height: 1rem;
            margin-left: 0.25rem;
            border-radius: 0.125rem;
        }

        .color-assigned {
            background-color: #10b981;
        }

        .color-planned {
            background-color: #3b82f6;
        }
    </style>
</head>

<body class="bg-gray-100">
    <!-- Include Header -->





    <div class="container mx-auto px-4 py-8">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

            <!-- Panel List Section -->
            <div class="md:col-span-1 bg-white p-4 rounded-lg shadow-md">
                <h3 class="text-lg font-bold mb-4">پنل‌های آماده تعیین تاریخ پایان</h3>

                <!-- Filter Controls -->
                <div class="filter-controls mb-4 pb-4 border-b border-gray-200">
                    <label for="searchAddressInput" class="block mb-1 text-sm font-medium text-gray-700">جستجو بر اساس آدرس:</label>
                    <input type="text" id="searchAddressInput" placeholder="بخشی از آدرس را وارد کنید..." class="w-full p-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">

                    <!-- Drag & Drop Hint -->
                    <p class="text-sm text-gray-500 mt-2">
                        <i class="fas fa-info-circle"></i>
                        برای تعیین تاریخ پایان، میتوانید پنل را به روز مورد نظر در تقویم بکشید و رها کنید.
                    </p>
                </div>

                <!-- Panel Stats -->
                <div id="panelListStats" class="text-sm text-gray-600 mb-3 px-2">
                    نمایش: <span id="visiblePanelCount">0</span> / <span id="totalPanelCount"></span>
                </div>

                <div id="panelListContainer" class="space-y-2 overflow-y-auto" style="max-height: 55vh;"> <!-- Added ID -->

                    <p class="text-gray-500 px-2">هیچ پنلی با تاریخ تحویل و نقشه تایید شده یافت نشد.</p>


                    <div class="panel-list-item"

                        <div class="panel-info">
                        <span class="panel-address-display font-semibold"></span>
                        <span class="assigned-date-display text-sm text-gray-600 block">
                            تاریخ تحویل:
                        </span>
                        <span class="current-finish-date text-sm text-blue-600 block">
                            تاریخ پایان فعلی:
                    </div>
                    <div class="panel-actions flex items-center">
                        <label for="days-<?php echo $panel['id']; ?>" class="text-sm ml-1">افزودن:</label>
                        <input type="number" min="0" step="1" id="days-
                                    <button class=" set-finish-btn ml-2">ثبت</button>
                    </div>
                </div>

            </div>
        </div>

        <!-- Calendar Display Section -->
        <div class="md:col-span-2 bg-white p-4 rounded-lg shadow-md">
            <h3 class="text-lg font-bold mb-4">تقویم تاریخ‌های پایان برنامه‌ریزی شده</h3>

            <!-- Calendar Legend -->
            <div class="calendar-legend mb-3">
                <div class="legend-item">
                    <div class="legend-color color-assigned"></div>
                    <span>تاریخ تحویل</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color color-planned"></div>
                    <span>تاریخ پایان برنامه‌ریزی شده</span>
                </div>
            </div>

            <div id="calendar"></div>
        </div>

    </div>
    </div>

    <!-- Toast Notification Element -->
    <div id="toastNotification" class="toast"></div>

    <!-- Include Footer -->



    <script src="js/jquery-3.6.0.min.js"></script>

    <!-- Load the plugin that provides persianDatepicker -->
    <script src="js/plugin-main.min.js"></script>

    <!-- Other dependencies -->
    <script src="js/moment.min.js"></script>
    <script src="js/moment-jalaali.js"></script>
    <script src="js/persian-date.min.js"></script>
    <script src="mobile-detect.min.js"></script>

    <!-- Your main code -->
    <script src="js/main.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            const panelListContainer = document.querySelector('#panelListContainer'); // Container for panel items
            const toastEl = document.getElementById('toastNotification');
            const searchAddressInput = document.getElementById('searchAddressInput'); // Get search input
            const visiblePanelCountEl = document.getElementById('visiblePanelCount'); // Get count span
            const totalPanelCountEl = document.getElementById('totalPanelCount'); // Total count set by PHP initially
            let toastTimeout;

            function showToast(message, isSuccess = true) {
                toastEl.textContent = message;
                toastEl.style.backgroundColor = isSuccess ? 'rgba(40, 167, 69, 0.8)' : 'rgba(220, 53, 69, 0.8)'; // Green for success, Red for error
                toastEl.classList.add('show');

                clearTimeout(toastTimeout); // Clear previous timeout if any
                toastTimeout = setTimeout(() => {
                    toastEl.classList.remove('show');
                }, 3000); // Hide after 3 seconds
            }

            function formatShamsiClientSide(gregorianDate) {
                if (!gregorianDate || gregorianDate === '0000-00-00') {
                    return ''; // Return empty if no valid date
                }
                try {
                    // Ensure moment.js and moment-jalaali.js are loaded
                    if (typeof moment === 'function' && typeof moment.loadPersian === 'function') {
                        moment.loadPersian({
                            usePersianDigits: false,
                            dialect: 'persian-modern'
                        });
                        return moment(gregorianDate, 'YYYY-MM-DD').format('jYYYY/jMM/jDD');
                    } else {
                        console.warn('Moment.js or moment-jalaali not loaded correctly for client-side formatting.');
                        return gregorianDate; // Fallback
                    }
                } catch (e) {
                    console.error("Error formatting date client-side:", gregorianDate, e);
                    return 'تاریخ نامعتبر';
                }
            }

            // --- Function to Filter Panel List ---
            function filterPanelList() {
                if (!panelListContainer || !searchAddressInput || !visiblePanelCountEl) return; // Exit if elements not found

                const searchText = searchAddressInput.value.toLowerCase().trim();
                const allPanelItems = panelListContainer.querySelectorAll('.panel-list-item');
                let visibleCount = 0;

                allPanelItems.forEach(item => {
                    const panelAddressData = item.getAttribute('data-panel-address'); // Lowercase address from data
                    const addressDisplaySpan = item.querySelector('.panel-address-display'); // Target the display span
                    const originalAddressText = addressDisplaySpan ? addressDisplaySpan.textContent : ''; // Get current text for resetting highlight

                    let isVisible = false;

                    // Check address filter
                    if (!searchText || (panelAddressData && panelAddressData.includes(searchText))) {
                        isVisible = true;
                    }

                    // Show/Hide item
                    if (isVisible) {
                        item.classList.remove('hidden');
                        visibleCount++;

                        // Apply highlighting
                        if (searchText && addressDisplaySpan) {
                            try {
                                const regex = new RegExp(`(${searchText.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&')})`, 'gi');
                                // Highlight based on the original text content before potential previous highlighting
                                const textToHighlight = item.hasAttribute('data-original-address') ? item.getAttribute('data-original-address') : originalAddressText;
                                if (!item.hasAttribute('data-original-address')) {
                                    // Store original address text for restoring after search changes
                                    item.setAttribute('data-original-address', textToHighlight);
                                }

                                // Apply highlighting with span elements
                                addressDisplaySpan.innerHTML = textToHighlight.replace(regex, '<span class="search-highlight">$1</span>');
                            } catch (e) {
                                console.error("Error in search highlighting:", e);
                                addressDisplaySpan.textContent = originalAddressText; // Reset on error
                            }
                        } else if (addressDisplaySpan) {
                            // Reset highlighting when search is cleared
                            const originalText = item.getAttribute('data-original-address') || originalAddressText;
                            addressDisplaySpan.textContent = originalText;
                        }
                    } else {
                        item.classList.add('hidden');
                    }
                });

                // Update counter
                visiblePanelCountEl.textContent = visibleCount.toString();
            }

            // --- Calendar Initialization ---
            let calendar = new FullCalendar.Calendar(calendarEl, {
                locale: 'fa', // Persian calendar
                initialView: 'dayGridMonth',
                height: 'auto',
                headerToolbar: {
                    start: 'title',
                    center: '',
                    end: 'prev,next today'
                },
                buttonText: {
                    today: 'امروز'
                },
                direction: 'rtl', // For RTL layout
                firstDay: 6, // Start week from Saturday (6 in FullCalendar)

                // Event handlers
                eventClick: function(info) {
                    // Handle click on calendar events
                    const eventType = info.event.extendedProps.type;
                    const panelId = info.event.extendedProps.panelId;

                    if (eventType === 'planned_finish') {
                        if (confirm('آیا می‌خواهید این تاریخ پایان را حذف کنید؟')) {
                            removePlannedFinishDate(panelId);
                        }
                    }
                    // No action for assigned dates (they can't be deleted from this page)
                },

                // Enable dropping panels onto calendar
                droppable: true,
                editable: false, // Don't allow events to be moved once placed

                // Handle drops from external elements (our panel list)
                drop: function(info) {
                    // Get date from drop info
                    const date = info.date;
                    const formattedDate = moment(date).format('YYYY-MM-DD');

                    // Get panel ID from dragged element
                    const panelId = info.draggedEl.getAttribute('data-panel-id');

                    // Call the API to update the database
                    setFinishDateByDrop(panelId, formattedDate);
                },

                // Load events when the calendar is rendered
                events: function(info, successCallback, failureCallback) {
                    // Fetch events via AJAX
                    $.ajax({
                        url: window.location.href, // Same page
                        method: 'POST',
                        data: {
                            action: 'getPlannedFinishPanelsForDisplay'
                        },
                        dataType: 'json',
                        success: function(data) {
                            successCallback(data);
                        },
                        error: function(error) {
                            console.error("Error fetching calendar events:", error);
                            failureCallback(error);
                        }
                    });
                }
            });
            calendar.render();

            // --- Initialize Draggable Panels ---
            // Make all panel list items draggable
            document.querySelectorAll('.panel-list-item').forEach(function(panelItem) {
                new FullCalendar.Draggable(panelItem, {
                    itemSelector: '.panel-list-item',
                    eventData: function(eventEl) {
                        return {
                            title: eventEl.querySelector('.panel-address-display').textContent,
                            backgroundColor: '#3b82f6', // Blue color for planned date events
                            borderColor: '#2563eb'
                        };
                    }
                });
            });

            // --- Handle API Calls ---

            // Set Planned Finish Date via Button + Days Input
            panelListContainer.addEventListener('click', function(e) {
                if (e.target.classList.contains('set-finish-btn')) {
                    const panelItem = e.target.closest('.panel-list-item');
                    const panelId = panelItem.getAttribute('data-panel-id');
                    const daysInput = panelItem.querySelector('.days-input');
                    const daysToAdd = parseInt(daysInput.value, 10);

                    if (!isNaN(daysToAdd) && daysToAdd >= 0) {
                        setPlannedFinishDate(panelId, daysToAdd);
                    } else {
                        showToast('لطفاً تعداد روز صحیح را وارد کنید.', false);
                    }
                }
            });

            // Function to set planned finish date via button
            function setPlannedFinishDate(panelId, daysToAdd) {
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        action: 'setPlannedFinishDate',
                        panelId: panelId,
                        daysToAdd: daysToAdd
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Update panel item to show the new planned finish date
                            const panelItem = document.querySelector(`.panel-list-item[data-panel-id="${panelId}"]`);
                            if (panelItem) {
                                const finishDateDisplay = panelItem.querySelector('.current-finish-date');
                                if (finishDateDisplay) {
                                    finishDateDisplay.textContent = 'تاریخ پایان فعلی: ' + formatShamsiClientSide(response.planned_finish_date);
                                }
                            }

                            // Refresh calendar to show the new event
                            calendar.refetchEvents();

                            // Show success message
                            showToast(response.message, true);
                        } else {
                            showToast(response.message, false);
                        }
                    },
                    error: function(xhr) {
                        let errorMsg = 'خطا در ارتباط با سرور';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        showToast(errorMsg, false);
                    }
                });
            }

            // Function to set planned finish date via drag and drop
            function setFinishDateByDrop(panelId, date) {
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        action: 'setFinishDateByDrop',
                        panelId: panelId,
                        date: date
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Update panel item to show the new planned finish date
                            const panelItem = document.querySelector(`.panel-list-item[data-panel-id="${panelId}"]`);
                            if (panelItem) {
                                const finishDateDisplay = panelItem.querySelector('.current-finish-date');
                                if (finishDateDisplay) {
                                    finishDateDisplay.textContent = 'تاریخ پایان فعلی: ' + formatShamsiClientSide(response.planned_finish_date);
                                }
                            }

                            // Refresh calendar to show the new event
                            calendar.refetchEvents();

                            // Show success message
                            showToast(`تاریخ پایان برای ${response.address} با موفقیت تنظیم شد`, true);
                        } else {
                            showToast(response.message, false);
                        }
                    },
                    error: function(xhr) {
                        let errorMsg = 'خطا در ارتباط با سرور';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        showToast(errorMsg, false);
                    }
                });
            }

            // Function to remove planned finish date
            function removePlannedFinishDate(panelId) {
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        action: 'removePlannedFinishDate',
                        panelId: panelId
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            // Update panel item to clear the planned finish date
                            const panelItem = document.querySelector(`.panel-list-item[data-panel-id="${panelId}"]`);
                            if (panelItem) {
                                const finishDateDisplay = panelItem.querySelector('.current-finish-date');
                                if (finishDateDisplay) {
                                    finishDateDisplay.textContent = 'تاریخ پایان فعلی: ';
                                }
                            }

                            // Refresh calendar to update events
                            calendar.refetchEvents();

                            // Show success message
                            showToast(response.message, true);
                        } else {
                            showToast(response.message, false);
                        }
                    },
                    error: function(xhr) {
                        let errorMsg = 'خطا در ارتباط با سرور';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        showToast(errorMsg, false);
                    }
                });
            }

            // --- Event Listeners ---

            // Add event listener for search input
            searchAddressInput.addEventListener('input', filterPanelList);

            // Initial filter to set the counter
            filterPanelList();
        });
    </script>

</body>

</html>