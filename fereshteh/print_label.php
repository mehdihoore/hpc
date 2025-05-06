<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start(); // Start output buffering *before* any output
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../sercon/config.php';
require_once 'includes/jdf.php'; // For jdate()
require_once 'includes/functions.php'; // For escapeHtml etc.

// Configuration for QR Code Images
define('QRCODE_IMAGE_WEB_PATH', '/panel_qrcodes/'); // MUST end with a slash '/'
define('QRCODE_SERVER_BASE_PATH', $_SERVER['DOCUMENT_ROOT']); // Usually the web root

// --- Get Filter/Sort/Status Parameters ---
$prioritizationFilter = filter_input(INPUT_GET, 'prioritization', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$sortBy = filter_input(INPUT_GET, 'sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$statusFilter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_FULL_SPECIAL_CHARS); // <-- New Status Filter

// --- Set Defaults ---
if (empty($prioritizationFilter)) $prioritizationFilter = 'all'; // Default to all prioritizations unless specified
if (empty($sortBy)) $sortBy = 'concrete_desc';
if (empty($statusFilter)) $statusFilter = 'completed'; // <-- Default status filter (e.g., completed)

// --- Fetch Panel Data ---
$panels = [];
$error = null;
$availablePrioritizations = [];

try {
    $pdo = connectDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- Fetch Available Prioritizations ---
    $stmtPrio = $pdo->query("SELECT DISTINCT Proritization FROM hpc_panels WHERE Proritization IS NOT NULL AND Proritization != '' ORDER BY Proritization ASC");
    $availablePrioritizations = $stmtPrio->fetchAll(PDO::FETCH_COLUMN);

    // --- Build Main Query ---
    $conditions = [];
    $params = [];
    $orderBy = "";

    // Prioritization Filter
    if ($prioritizationFilter !== 'all' && !empty($prioritizationFilter)) {
        $conditions[] = "hp.Proritization = ?";
        $params[] = $prioritizationFilter;
    }
//'pending','Formwork','Assembly','Mesh','Concreting','completed','polystyrene'
    // --- Status Filter (Based on Timestamps) ---
    // IMPORTANT: Adjust column names (polystyrene_end_time, mesh_end_time, etc.) if they differ in your DB
    switch ($statusFilter) {
        case 'completed':

            $conditions[] = "hp.status = 'completed'";
            break;
        case 'assembly':

            $conditions[] = "hp.status = 'Assembly'"; 
            break;
        case 'concreting': // Assumes means "ready for concrete" or "in concreting"
            $conditions[] = "hp.status = 'Concreting'";
            break;
        case 'mesh':

            $conditions[] = "hp.status = 'Mesh'";
            break;
        case 'polystyrene':
            $conditions[] = "hp.polystyrene = 1";
            $conditions[] = "hp.status = 'polystyrene'";
            break;
        case 'pending': // Before formwork or polystyrene is done
            $conditions[] = "hp.status = 'pending'";
            // Or perhaps check the *very first* step's timestamp column if it's not formwork
            // $conditions[] = "hp.polystyrene IS NULL"; // Alternative if formwork isn't tracked first
            break;
        case 'all':
        default:
            // No status-specific time conditions applied when 'all' is selected
            break;
    }


    // Sorting
    switch ($sortBy) {
        case 'concrete_asc':    $orderBy = "ORDER BY hp.concrete_end_time ASC, hp.id ASC"; break;
        case 'assembly_desc':   $orderBy = "ORDER BY hp.assembly_end_time DESC, hp.id DESC"; break;
        case 'assembly_asc':    $orderBy = "ORDER BY hp.assembly_end_time ASC, hp.id ASC"; break;
        case 'concrete_desc':
        default:                $orderBy = "ORDER BY hp.concrete_end_time DESC, hp.id DESC"; break;
    }

    // Construct the final SQL - Added potential new date columns needed for status filter
    $sql = "SELECT
                hp.id,
                hp.address,
                hp.assigned_date,
                hp.status,
                hp.Proritization,
                hp.planned_finish_date,
                hp.formwork_end_time,    -- Needed for polystyrene/pending status
                hp.polystyrene, -- Needed for mesh/polystyrene status (assuming it exists)
                hp.mesh_end_time,        -- Needed for assembly/mesh status (assuming it exists)
                hp.assembly_end_time,
                hp.concrete_start_time, -- Needed for concreting status (assuming it exists)
                hp.concrete_end_time,
                ct.sample_code as batch_number
            FROM hpc_panels hp
            LEFT JOIN concrete_tests ct ON hp.assigned_date = ct.production_date";
            

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    $sql .= " " . $orderBy;

    // Execute Query
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = "خطا در بارگذاری اطلاعات: " . $e->getMessage();
    error_log("Error on print_multi_label.php: " . $e->getMessage());
}

// Flush buffer if started - moved before DOCTYPE
if (ob_get_level() > 0) {
    ob_end_flush();
}

/**
 * Helper function to get the latest Shamsi date from a list of potential date strings.
 * (No changes needed here)
 */
function getLatestShamsiDate(array $dateStrings): string {
    $latestTimestamp = 0;
    foreach ($dateStrings as $dateStr) {
        if (!empty($dateStr) && $dateStr !== '0000-00-00' && $dateStr !== '0000-00-00 00:00:00') {
            $timestamp = strtotime($dateStr);
            if ($timestamp !== false && $timestamp > $latestTimestamp) {
                $latestTimestamp = $timestamp;
            }
        }
    }

    if ($latestTimestamp > 0) {
        return jdate('Y/m/d', $latestTimestamp);
    } else {
        return '-'; // Return hyphen if no valid date was found
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/assets/images/favicon-96x96.png">
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
    <title>چاپ گروهی برچسب پنل‌ها</title>
    <style>
        /* --- Base Styles --- */
        @font-face { font-family: 'Vazir'; src: url('/assets/fonts/Vazir-Regular.woff2') format('woff2'); }
        body { font-family: 'Vazir', sans-serif; margin: 0; padding: 0; background-color: #eee; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        /* --- Label Container & Wrapper --- */
        .panel-wrapper { /* Added wrapper for checkbox + label */
            page-break-inside: avoid;
            margin-bottom: 15mm; /* Spacing between items */
        }
        .label-container {
            width: 200mm;
            border: 1px dashed #ccc;
            margin: 5mm auto 0 auto; /* Margin top only */
            padding: 0;
            box-sizing: border-box;
            overflow: hidden;
            background-color: #fff;
            display: block;
        }
        .panel-selection-wrapper { /* Styles for the checkbox area */
             text-align: center;
             padding: 5px 0;
             margin: 0 auto; /* Center it */
             width: 200mm; /* Match label width */
         }
         .panel-selection-wrapper label {
             margin-right: 5px;
             font-size: 10pt;
             cursor: pointer;
         }

        /* --- Label Table --- */
        table.label-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .label-table th, .label-table td {
            border: 1px solid #333;
            padding: 1.5mm 2.5mm;
            vertical-align: middle;
            text-align: center;
            font-size: 9pt;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        .label-table th { font-weight: bold; background-color: #f0f0f0 !important; }

        /* --- Table Rows and Cells --- */
        .header-row { height: 16mm; }
        .middle-row { height: 7mm; }
        .bottom-row { height: 7mm; }
        .qr-code-cell { width: 22mm; padding: 1mm; }
        .qr-code-cell img { max-width: 100%; height: auto; display: block; } /* Adjusted width */
        .logo-cell img {
            max-height: 10mm;
            max-width: 45%;
            vertical-align: middle;
            margin: 0 0.5mm;
        }
        .logo-wrapper-dark {
            /* Removed background for simplicity with logo */
            display: inline-block; /* Or block if needed */
            vertical-align: middle;
            margin-left: 5mm; /* Space between logos */
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .logo-wrapper-dark img {
            max-width: 80%; /* Adjust as needed */
            max-height: 12mm; /* Adjust as needed */
        }
        .title-cell { font-size: 10pt; font-weight: bold; vertical-align: bottom; padding-bottom: 1mm; }

        /* --- QC Cell Styling (Pattern) --- */
        .qc-pass-cell {
            /* background-color: #008000 !important; */ /* Removed solid green */
            background-image: repeating-linear-gradient(
                45deg,
                #a8dda8, /* Lighter green */
                #a8dda8 1px,
                #ffffff 1px,
                #ffffff 2px
            );
            background-size: 2px 2px; /* Pixel pattern size */
            color: #000000 !important; /* Black text for contrast */
            font-weight: bold;
            font-size: 10pt;
            width: 40mm;
            line-height: 2;
            -webkit-print-color-adjust: exact !important; /* Ensure pattern prints */
            print-color-adjust: exact !important; /* Ensure pattern prints */
        }

        .label-text { font-weight: bold; font-size: 8pt; color: #444; padding-left: 1mm; text-align: left; }
        .value-text { font-weight: bold; font-size: 9pt; text-align: right; padding-right: 1mm; }
        .project-name { font-size: 10pt; font-weight: bold; }
        .date-value { font-size: 10pt; font-weight: bold; direction: rtl; unicode-bidi: embed; text-align: right; padding-right: 2mm; }
        .date-label { font-size: 8pt; font-weight: normal; margin-left: 1mm; }


        /* --- Controls --- */
        .controls-container { background-color: #fff; padding: 15px; margin-bottom: 20px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .controls-container label { margin-left: 5px; font-weight: bold; margin-right: 15px; /* Add spacing */ }
        .controls-container select, .controls-container button { padding: 8px 12px; margin-right: 5px; margin-bottom: 5px; /* Spacing for smaller screens */ border-radius: 4px; border: 1px solid #ccc; font-family: 'Vazir', sans-serif; font-size: 14px; vertical-align: middle; }
        .controls-container button { background-color: #007bff; color: white; cursor: pointer; border: none; }
        .controls-container button:hover { background-color: #0056b3; }
        .controls-container span.panel-count { margin-left: 15px; font-size: 13px; color: #555; }
        .controls-container a { color: white; text-decoration: none; }
        #printSelectedBtn { background-color: #28a745; } /* Green for selected print */
        #printSelectedBtn:hover { background-color: #218838; }
        #backButton { background-color: #f44336; }
        #backButton:hover { background-color: #d32f2f; }


        /* --- Print Styles --- */
        @media print {
            body { margin: 0; padding: 0; background-color: #fff; }
            .no-print, /* General class to hide elements */
            .controls-container, /* Hide the filter/button controls */
            .panel-selection-wrapper /* Hide the checkbox wrapper */
            {
                display: none !important;
            }

            .panel-wrapper {
                margin-bottom: 0; /* Remove screen margin */
                page-break-inside: avoid !important;
            }

            .label-container {
                width: 180mm; /* Adjust width slightly if needed for A4 */
                border: 1px solid #000;
                margin: 1mm 0; /* Minimal margin for print */
                display: block;
                page-break-inside: avoid !important;
            }
            .label-table th, .label-table td { padding: 1mm 2mm; font-size: 8.5pt; } /* Slightly smaller font for print */
            .label-table th { background-color: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

            /* QC Cell Print Style (ensure pattern prints) */
            .qc-pass-cell {
                background-image: repeating-linear-gradient( 45deg, #a8dda8, #a8dda8 1px, #ffffff 1px, #ffffff 2px );
                background-size: 2px 2px;
                color: #000000 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .logo-wrapper-dark img { max-height: 10mm; max-width: 70%; } /* Adjust print logo size if needed */

            /* Styles for selective printing */
            body.print-selection-active .panel-wrapper {
                display: none !important; /* Hide all wrappers by default when selecting */
            }
            body.print-selection-active .panel-wrapper.selected-for-print {
                display: block !important; /* Show only selected wrappers */
                page-break-inside: avoid !important;
            }

            /* @page { size: A4; margin: 10mm; } */ /* Uncomment if specific page size/margins needed */
        }
    </style>
</head>
<body>

    <div class="controls-container no-print">
        <form method="GET" action="print_multi_label.php" id="filterForm">
             <label for="statusFilterSelect">وضعیت:</label>
             <select name="status" id="statusFilterSelect" onchange="this.form.submit()">
                  <option value="all" <?= ($statusFilter == 'all') ? 'selected' : '' ?>>همه وضعیت‌ها</option>
                  <option value="completed" <?= ($statusFilter == 'completed') ? 'selected' : '' ?>>تکمیل شده</option>
                  <option value="assembly" <?= ($statusFilter == 'assembly') ? 'selected' : '' ?>>فیس کوت</option>
                  <option value="concreting" <?= ($statusFilter == 'concreting') ? 'selected' : '' ?>>بتن ریزی</option>
                  <option value="mesh" <?= ($statusFilter == 'mesh') ? 'selected' : '' ?>>مش گذاری</option>
                  <option value="polystyrene" <?= ($statusFilter == 'polystyrene') ? 'selected' : '' ?>>فوم گذاری</option>
                  <option value="pending" <?= ($statusFilter == 'pending') ? 'selected' : '' ?>>در انتظار</option>
             </select>

             <label for="prioritizationFilterSelect">اولویت:</label>
             <select name="prioritization" id="prioritizationFilterSelect" onchange="this.form.submit()">
                  <option value="all" <?= ($prioritizationFilter == 'all') ? 'selected' : '' ?>>همه اولویت‌ها</option>
                  <?php foreach ($availablePrioritizations as $prio): ?>
                  <option value="<?= escapeHtml($prio) ?>" <?= ($prioritizationFilter == $prio) ? 'selected' : '' ?>><?= escapeHtml($prio) ?></option>
                  <?php endforeach; ?>
              </select>

              <label for="sortSelect">مرتب سازی:</label>
              <select name="sort" id="sortSelect" onchange="this.form.submit()">
                 <option value="concrete_desc" <?= ($sortBy == 'concrete_desc') ? 'selected' : '' ?>>پایان بتن (جدیدترین)</option>
                 <option value="concrete_asc" <?= ($sortBy == 'concrete_asc') ? 'selected' : '' ?>>پایان بتن (قدیمی ترین)</option>
                 <option value="assembly_desc" <?= ($sortBy == 'assembly_desc') ? 'selected' : '' ?>>پایان فیس کوت (جدیدترین)</option> <!-- Changed label to فیس کوت -->
                 <option value="assembly_asc" <?= ($sortBy == 'assembly_asc') ? 'selected' : '' ?>>پایان فیس کوت (قدیمی ترین)</option> <!-- Changed label to فیس کوت -->
                 <!-- Add other sort options if needed, e.g., by mesh_end_time -->
              </select>

             <button type="button" onclick="window.print();">چاپ همه</button>
             <button type="button" id="printSelectedBtn">چاپ منتخب</button> <!-- New Button -->
             <button type="button" id="backButton"><a href="new_panels.php">بازگشت</a></button>
             <span class="panel-count">(<?= count($panels) ?> برچسب یافت شد)</span>
             
         </form>
    </div>

    <?php if ($error): ?>
        <div style="padding: 20px; color: red; text-align: center; border: 1px solid red; margin: 20px; background: #ffeeee;">
            <strong>خطا:</strong> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php elseif (empty($panels)): ?>
         <div style="padding: 20px; text-align: center; margin: 20px;">
             هیچ پنلی با فیلترهای انتخاب شده یافت نشد.
         </div>
    <?php else: ?>
        <div class="labels-grid">
            <?php foreach ($panels as $panelData): ?>
                <?php
                    // --- Get Path to QR Code Image ---
                    $currentPanelId = $panelData['id'];
                    $qrCodeFilename = "panel_qr_" . $currentPanelId . ".png";
                    $qrCodeWebUrl = QRCODE_IMAGE_WEB_PATH . $qrCodeFilename;
                    $qrCodeServerPath = QRCODE_SERVER_BASE_PATH . QRCODE_IMAGE_WEB_PATH . $qrCodeFilename;
                    $currentQrCodeImageUrl = null;

                    if (file_exists($qrCodeServerPath)) {
                        $mtime = @filemtime($qrCodeServerPath);
                        $currentQrCodeImageUrl = $qrCodeWebUrl . ($mtime ? '?v=' . $mtime : '');
                    } else {
                        error_log("Missing QR Code Image: Panel ID $currentPanelId, Expected Path: $qrCodeServerPath");
                    }

                    // --- Calculate Latest Date (using the function) ---
                    $dateColumnsToCheck = [

                        $panelData['assigned_date'] ?? null // Keep as fallback if desired
                    ];
                    $latestShamsiDate = getLatestShamsiDate(array_filter($dateColumnsToCheck)); // Use array_filter to remove nulls


                    // --- Prepare other data ---
                    $currentPanelCode = $panelData['address'] ?? 'N/A';
                    $currentProject = $panelData['project_name'] ?? 'پروژه فرشته'; // Use DB or default
                    //$currentBatch = $panelData['batch_number'] ?? '-'; // Use DB or default
                    $currentPanelNumber = '-'; // Default
                    $addrParts = explode('-', $currentPanelCode);
                    if (count($addrParts) > 1 && is_numeric(end($addrParts))) {
                        $currentPanelNumber = end($addrParts);
                    } else {
                         $currentPanelNumber = $panelData['id']; // Fallback to ID
                    }
                ?>
                 <!-- Wrapper for Checkbox and Label -->
                 <div class="panel-wrapper" data-panel-id="<?= $currentPanelId ?>">
                      <div class="panel-selection-wrapper no-print">
                          <input type="checkbox" class="panel-select-checkbox" value="<?= $currentPanelId ?>" id="select-panel-<?= $currentPanelId ?>">
                          <label for="select-panel-<?= $currentPanelId ?>">انتخاب برای چاپ</label>
                      </div>

                      <!-- Generate Label HTML for the Current Panel -->
                      <div class="label-container">
                          <table class="label-table">
                              <tbody>
                                  <tr class="header-row">
                                      <td class="qr-code-cell" rowspan="2">
                                          <?php if ($currentQrCodeImageUrl): ?>
                                              <img src="<?= htmlspecialchars($currentQrCodeImageUrl) ?>" alt="QR Code Panel <?= htmlspecialchars($currentPanelId) ?>">
                                          <?php else: ?>
                                              <span style="font-size: 7pt; color: red; display:inline-block; padding-top:5mm;">QR یافت نشد</span>
                                          <?php endif; ?>
                                      </td>
                                      <td class="logo-cell" colspan="3">
                                           <img src="/assets/images/alumglass-farsi-logo-H40.png" alt="AlumGlass Logo">
                                           <span class="logo-wrapper-dark">
                                               <img src="/assets/images/hotelfereshteh1.png" alt="hotelfereshteh Logo">
                                           </span>
                                       </td>
                                       <td class="qc-pass-cell" rowspan="3"> QC<br>کنترل شد </td>
                                  </tr>
                                  <tr class="header-row">
                                      <td class="title-cell" colspan="3">برچسب کنترل کیفی محصول</td>
                                  </tr>
                                  <tr class="middle-row">
                                      <td colspan="1" class="project-name"><?= htmlspecialchars($currentProject) ?></td>
                                      <td colspan="1" class="date-value">
                                         <span class="date-label">تاریخ تولید :</span>
                                          <?= htmlspecialchars($latestShamsiDate) ?>
                                          <span class="label-text">آدرس پنل : </span>
                                          <?= htmlspecialchars($currentPanelCode) ?>
                                          
                                      </td>
                                      <td colspan="2" class="value-text">

                                          <span class="label-text">آدرس پنل : </span>
                                          <?= htmlspecialchars($currentPanelCode) ?>
                                          
                                      </td>
                                  </tr>
                                  
                              </tbody>
                          </table>
                      </div>
                      <!-- End Label HTML -->
                 </div>
                 <!-- End Panel Wrapper -->

            <?php endforeach; ?>
        </div> <!-- End Labels Grid -->
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const printSelectedBtn = document.getElementById('printSelectedBtn');
            const checkboxes = document.querySelectorAll('.panel-select-checkbox');
            const panelWrappers = document.querySelectorAll('.panel-wrapper'); // Select the wrapper

            if (printSelectedBtn) {
                printSelectedBtn.addEventListener('click', function() {
                    const selectedPanelIds = [];
                    checkboxes.forEach(checkbox => {
                        if (checkbox.checked) {
                            selectedPanelIds.push(checkbox.value);
                        }
                    });

                    if (selectedPanelIds.length === 0) {
                        alert('لطفاً حداقل یک پنل را برای چاپ انتخاب کنید.');
                        return;
                    }

                    // Add selection classes
                    panelWrappers.forEach(wrapper => {
                        const panelId = wrapper.getAttribute('data-panel-id');
                        if (selectedPanelIds.includes(panelId)) {
                            wrapper.classList.add('selected-for-print');
                        } else {
                            wrapper.classList.remove('selected-for-print');
                        }
                    });

                    // Add body class to activate print selection styles
                    document.body.classList.add('print-selection-active');

                    // Trigger print dialog
                    window.print();

                    // --- Cleanup after print ---
                    // Option 1: Use setTimeout (might run before print finishes in some browsers)
                    // setTimeout(() => {
                    //    document.body.classList.remove('print-selection-active');
                    //    panelWrappers.forEach(wrapper => wrapper.classList.remove('selected-for-print'));
                    // }, 1000); // Delay 1 second

                    // Option 2: Use onafterprint (better, but check browser compatibility)
                    if (window.onafterprint !== undefined) {
                        window.onafterprint = () => {
                            document.body.classList.remove('print-selection-active');
                            panelWrappers.forEach(wrapper => wrapper.classList.remove('selected-for-print'));
                            // Reset the event handler to avoid multiple triggers if the user prints again
                            window.onafterprint = null;
                        };
                    } else {
                        // Fallback if onafterprint is not supported - user might need to refresh
                        console.warn("Browser does not fully support onafterprint. View might need manual refresh after printing selected items.");
                        // Consider leaving the classes and maybe adding a "Reset View" button
                        // Or just remove them after a short delay anyway
                         setTimeout(() => {
                            document.body.classList.remove('print-selection-active');
                            panelWrappers.forEach(wrapper => wrapper.classList.remove('selected-for-print'));
                        }, 1500);
                    }

                });
            }

            // Optional: Add Select All / Deselect All functionality
            // (Add a master checkbox with id="selectAllCheckbox" in the controls)
            // const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            // if (selectAllCheckbox) {
            //     selectAllCheckbox.addEventListener('change', function() {
            //         checkboxes.forEach(cb => {
            //             cb.checked = selectAllCheckbox.checked;
            //         });
            //     });
            // }
        });
    </script>

</body>
</html>