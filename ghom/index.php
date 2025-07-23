<?php
// public_html/ghom/index.php

// Include the central bootstrap file
require_once __DIR__ . '/../../sercon/bootstrap.php';
require_once __DIR__ . '/includes/jdf.php';
// Secure the session (starts or resumes and applies security checks)
secureSession();

// --- Authorization & Project Context Check ---

// 1. Check if user is logged in at all
if (!isLoggedIn()) {
    header('Location: /login.php?msg=login_required');
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'superuser', 'user', 'cat', 'car', 'coa', 'crs'])) {
    http_response_code(403);
    require 'Access_Denied.php';
    exit;
}
// 2. Define the expected project for this page
$expected_project_key = 'ghom';

// 3. Check if the user has selected a project and if it's the correct one
if (!isset($_SESSION['current_project_config_key']) || $_SESSION['current_project_config_key'] !== $expected_project_key) {
    // Log the attempt for security auditing
    logError("User ID " . ($_SESSION['user_id'] ?? 'N/A') . " tried to access Ghom project page without correct session context.");
    // Redirect them to the project selection page with an error
    header('Location: /select_project.php?msg=context_mismatch');
    exit();
}
$pageTitle = "پروژه بیمارستان هزار تخت خوابی قم";
require_once __DIR__ . '/header_ghom.php';
// If all checks pass, the script continues and will render the HTML below.
$user_role = $_SESSION['role'] ?? 'guest';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo escapeHtml($pageTitle); ?></title>
    <link rel="icon" type="image/x-icon" href="/ghom/assets/images/favicon.ico" />
    <link rel="stylesheet" href="/ghom/assets/css/jalalidatepicker.min.css" />
    <link rel="stylesheet" href="/ghom/assets/css/formopen.css" />
    <link rel="stylesheet" href="/ghom/assets/css/crack.css" />
    <script src="/ghom/assets/js/interact.min.js"></script>
    <script src="/ghom/assets/js/fabric.min.js"></script>

    <style>
        @font-face {
            font-family: "Samim";
            src: url("/ghom/assets/fonts/Samim-FD.woff2") format("woff2"),
                url("/ghom/assets/fonts/Samim-FD.woff") format("woff"),
                url("/ghom/assets/fonts/Samim-FD.ttf") format("truetype");
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Samim", "Tahoma", sans-serif;
            background-color: #f4f7f6;
            direction: rtl;
            margin: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0;
            text-align: right;
            min-height: 100vh;
        }

        .stage-fieldset {
            border: 1px solid #007bff;
            border-radius: 5px;
            padding: 10px;
            margin-top: 20px;
            background-color: #f8f9fa;
        }

        .stage-fieldset legend {
            font-weight: bold;
            color: #0056b3;
            background-color: #f8f9fa;
            padding: 0 10px;
        }

        /* This is the class for the yellow items */
        .highlight-external {
            background-color: #fff3cd !important;
            /* Light yellow background */
            border-radius: 4px;
            padding: 10px;
            border: 1px solid #ffeeba;
        }

        .ltr-text {
            direction: ltr;
            unicode-bidi: embed;
            display: inline-block;
        }

        .highlight-note {
            color: red;
            text-align: center;
            font-weight: bold;
            margin-top: 15px;
        }

        /* HEADER */
        header {
            background-color: #0056b3;
            color: white;
            padding: 15px 20px;
            width: 100%;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .header-content {
            display: grid;
            grid-template-columns: 1fr 2fr 1fr;
            align-items: center;
            max-width: 1200px;
            width: 100%;
            gap: 20px;
        }

        .header-content .logo-left,
        .header-content .logo-right {
            display: flex;
            align-items: center;
            height: 50px;
        }

        .header-content .logo-left img {
            height: 50px;
            width: auto;
        }

        .header-content .logo-left {
            justify-content: flex-start;
        }

        .header-content .logo-right {
            justify-content: flex-end;
        }

        .header-content h1 {
            margin: 0;
            font-size: 1.6em;
            font-weight: 600;
            text-align: center;
        }

        /* FOOTER */
        footer {
            background-color: #343a40;
            color: #f8f9fa;
            text-align: center;
            padding: 20px;
            width: 100%;
            margin-top: auto;
            font-size: 0.9em;
        }

        footer p {
            margin: 0;
        }

        /* ZONE INFO */
        #currentZoneInfo {
            margin-top: 20px;
            text-align: center;
            padding: 10px;
            background-color: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 5px;
            display: none;
            font-size: 0.9em;
        }

        #zoneNameDisplay,
        #zoneContractorDisplay,
        #zoneBlockDisplay {
            margin-left: 15px;
            font-weight: bold;
        }

        #regionZoneNavContainer {
            align-self: center;
            margin-bottom: 15px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            display: none;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            width: 80%;
            max-width: 600px;
            background-color: #f8f9fa;
        }

        /* CONTROLS */
        .navigation-controls,
        .layer-controls {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .navigation-controls button,
        .layer-controls button {
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-family: inherit;
        }

        .navigation-controls button {
            border: 1px solid #007bff;
            background-color: #007bff;
            color: white;
        }

        .layer-controls button {
            border: 1px solid #ccc;
            background-color: #f8f9fa;
        }

        .layer-controls button.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }

        p.description {
            text-align: center;
            margin-bottom: 10px;
        }

        /* SVG CONTAINER */
        #svgContainer {
            width: 90vw;
            height: 65vh;
            max-width: 1200px;
            border: 1px solid #007bff;
            background-color: #e9ecef;
            overflow: hidden;
            margin: 10px auto;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: grab;
            position: relative;
        }

        #svgContainer.dragging {
            cursor: grabbing;
        }

        #svgContainer.loading::before {
            content: "در حال بارگذاری نقشه...";
        }

        #svgContainer svg {
            display: block;
            width: 100%;
            height: 100%;
        }

        .interactive-element {
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .interactive-element:hover {
            filter: brightness(1.1);
        }

        /* START: === IMPROVED FORM POPUP CSS === */




        .highlight-issue {
            background-color: #fff3cd !important;
        }

        .zoom-controls {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 5;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .svg-element-active {
            stroke: #ff3333 !important;
            stroke-width: 3px !important;
        }

        #gfrcSubPanelMenu {
            position: absolute;
            background: white;
            border: 1px solid #ccc;
            padding: 5px;
            z-index: 1001;
            box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.2);
            min-width: 150px;
        }

        #gfrcSubPanelMenu button {
            display: block;
            width: 100%;
            margin-bottom: 3px;
            text-align: right;
            padding: 5px;
            background-color: #007bff;
            color: white;
            border: none;
            cursor: pointer;
        }

        #gfrcSubPanelMenu button:hover {
            background-color: #0056b3;
        }

        #gfrcSubPanelMenu .close-menu-btn {
            background-color: #f0f0f0;
            color: black;
            margin-top: 5px;
        }

        /* JDP Date Picker Overrides */
        .jdp-container {
            position: absolute;
            /* Position it relative to the input field */
            border: 2px solid #3498db;

            /* Give it a z-index higher than other form content */
            z-index: 10;
        }

        .jdp-header {
            background-color: #1a1b1b !important;
            color: #ecf0f1 !important;
        }

        .jdp-day.jdp-selected {
            background-color: #0b3653 !important;
            color: white !important;
            border-radius: 50% !important;
        }

        .jdp-day.jdp-today {
            border: 1px solid #3498db !important;
        }

        .region-zone-nav-title {
            margin-top: 0;
            margin-bottom: 10px;
            text-align: center;
        }

        .region-zone-nav-select-row {
            margin-bottom: 10px;
            width: 100%;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .region-zone-nav-label {
            margin-left: 5px;
            font-weight: bold;
        }

        .region-zone-nav-select {
            padding: 5px;
            border-radius: 3px;
            border: 1px solid #ccc;
            min-width: 200px;
            font-family: inherit;
        }

        #regionZoneNavContainer .region-zone-nav-zone-buttons,
        .region-zone-nav-zone-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
            margin-top: 5px;
        }

        .region-zone-nav-zone-buttons button {
            padding: 8px 12px;
            border-radius: 4px;
            border: 1px solid #007bff;
            background-color: #007bff;
            color: white;
            cursor: pointer;
            font-family: inherit;
            margin: 4px;
            transition: background 0.2s;
        }

        .region-zone-nav-zone-buttons button:hover {
            background-color: #0056b3;
        }

        #contractor-section {
            border: 1px solid #007bff;
            border-radius: 5px;
            padding: 10px;
            margin-top: 20px;
            background-color: #f0f8ff;
        }

        #contractor-section legend {
            font-weight: bold;
            color: #007bff;
        }


        #status-legend {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            /* Space between items */
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            margin: 0 auto 15px auto;
            /* Center it and add space below */
            max-width: 800px;
            font-size: 14px;
        }

        #status-legend span {
            display: inline-flex;
            align-items: center;
        }

        .legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            margin-left: 6px;
            border: 1px solid rgba(0, 0, 0, 0.2);
        }

        g[id$="_circle"] {
            pointer-events: none;
        }

        #zoneSelectionMenu {
            position: absolute;
            /* Crucial for positioning with pageX/pageY */
            z-index: 1005;
            background-color: #f9f9f9;
            border: 1px solid #ccc;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            padding: 15px;
            border-radius: 8px;
            width: 600px;
            max-width: 90vw;
            /* Ensure it's not wider than the screen */
            display: flex;
            flex-direction: column;
        }

        /* Style for the menu title */
        .zone-menu-title {
            margin: -5px -5px 15px -5px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
            text-align: right;
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }

        /* Grid container for the buttons */
        .zone-menu-grid {
            display: grid;
            /* This creates a 3-column grid for desktops. */
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            /* The space between the buttons */
        }

        /* Styling for the individual zone buttons inside the grid */
        .zone-menu-grid button {
            padding: 12px;
            border: 1px solid #ddd;
            background-color: #fff;
            cursor: pointer;
            text-align: right;
            font-size: 14px;
            border-radius: 5px;
            transition: background-color 0.2s, transform 0.2s;
        }

        .zone-menu-grid button:hover {
            background-color: #e9e9e9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        /* Style for the main close button at the bottom */
        #zoneSelectionMenu .close-menu-btn {
            margin-top: 15px;
            padding: 10px;
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.2s;
        }

        #zoneSelectionMenu .close-menu-btn:hover {
            background-color: #5a6268;
        }


        /* --- MOBILE RESPONSIVE STYLES --- */

        /* For tablets and larger phones (screens smaller than 640px) */
        @media (max-width: 640px) {
            #zoneSelectionMenu {
                /* Make the menu take up more screen width on mobile */
                width: 90vw;
            }

            .zone-menu-grid {
                /* Switch to a 2-column grid, which is better for this screen size */
                grid-template-columns: repeat(2, 1fr);
            }

            .zone-menu-title {
                font-size: 15px;
                /* Slightly smaller title is fine */
            }

            .zone-menu-grid button {
                font-size: 13px;
                /* Adjust font to fit */
                padding: 10px;
            }
        }

        /* For smaller phones (screens smaller than 420px) */
        @media (max-width: 420px) {
            .zone-menu-grid {
                /* Switch to a single column for the best readability and touch experience */
                grid-template-columns: 1fr;
            }
        }

        .legend-item {
            cursor: pointer;
            transition: opacity 0.3s ease;
            padding: 5px;
            border-radius: 4px;
        }

        .legend-item:not(.active) {
            opacity: 0.4;
            text-decoration: line-through;
            background-color: #e9ecef;
        }

        .history-container {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px dashed #ccc;
        }

        .history-list {
            list-style: none;
            padding: 8px;
            margin: 0;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            max-height: 120px;
            overflow-y: auto;
            font-size: 13px;
        }

        .history-list li {
            padding: 4px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .history-list li:last-child {
            border-bottom: none;
        }

        .history-meta {
            font-weight: bold;
            color: #0056b3;
            margin-left: 5px;
        }

        textarea {
            resize: both;
        }

        .history-log-container {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 2px solid #007bff;
        }

        .inspection-cycle-container {
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            background-color: #f8f9fa;
        }

        .inspection-cycle-container h3 {
            margin: 0;
            padding: 10px 15px;
            background-color: #e9ecef;
            border-bottom: 1px solid #dee2e6;
            font-size: 1.1em;
        }

        .history-event {
            padding: 10px 15px;
            border-bottom: 1px solid #e9ecef;
        }

        .history-event:last-child {
            border-bottom: none;
        }

        .history-event-header {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .history-note {
            margin-bottom: 8px;
        }

        .history-file-list {
            margin: 5px 0 0;
            padding-right: 20px;
        }

        .history-checklist {
            margin-top: 10px;
        }

        .history-checklist h4 {
            margin: 0 0 5px;
            font-size: 13px;
            color: #333;
        }

        .history-checklist-item {
            font-size: 13px;
            padding: 3px 0;
            display: flex;
            align-items: center;
        }

        .history-item-status {
            display: inline-block;
            width: 60px;
            text-align: center;
            padding: 2px 5px;
            border-radius: 4px;
            color: white;
            font-weight: bold;
            margin-left: 10px;
            flex-shrink: 0;
        }

        .history-item-status.status-ok {
            background-color: #28a745;
        }

        .history-item-status.status-not-ok {
            background-color: #dc3545;
        }

        .history-item-value {
            color: #6c757d;
            margin-right: 5px;
        }

        .history-log-container {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f1f3f5;
            border: 1px solid #dee2e6;
            border-radius: 6px;
        }

        .history-log-container h4 {
            margin-top: 0;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ced4da;
            font-size: 16px;
            color: #343a40;
        }

        .history-event {
            padding: 12px;
            background-color: #fff;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            margin-bottom: 10px;
        }

        .history-event:last-child {
            margin-bottom: 0;
        }

        .history-event-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: #495057;
            margin-bottom: 10px;
        }

        .history-event-header strong {
            color: #0056b3;
        }

        .history-note {
            font-size: 14px;
            margin-bottom: 8px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 3px;
        }

        .history-checklist {
            margin-top: 12px;
        }

        .history-checklist h4 {
            margin: 0 0 8px 0;
            font-size: 13px;
            color: #495057;
        }

        .history-checklist-item {
            display: flex;
            align-items: center;
            font-size: 13px;
            padding: 4px 0;
            border-top: 1px solid #f1f3f5;
        }

        .history-item-status {
            flex-shrink: 0;
            font-weight: bold;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            min-width: 60px;
            text-align: center;
            margin-left: 10px;
        }

        .history-item-status.status-ok {
            background-color: #28a745;
        }

        .history-item-status.status-not-ok,
        .history-item-status.status-reject {
            background-color: #dc3545;
        }

        .history-item-status.status-repair {
            background-color: #9c27b0;
        }

        .history-item-status.status-pending {
            background-color: #6c757d;
        }

        .history-item-value {
            color: #6c757d;
            margin-right: 5px;
            font-style: italic;
        }
    </style>

</head>



<body data-user-role="<?php echo escapeHtml($user_role); ?>">
    <div id="status-legend">
        <strong>راهنمای وضعیت:</strong>
        <span class="legend-item active" data-status="OK"><span class="legend-dot" style="background-color:rgba(40, 167, 69, 0.7);"></span>تایید شده</span>

        <span class="legend-item active" data-status="Reject"><span class="legend-dot" style="background-color: rgba(220, 53, 69, 0.7);"></span>رد شده</span>

        <span class="legend-item active" data-status="Repair"><span class="legend-dot" style="background-color: rgba(156, 39, 176, 0.7);"></span>نیاز به تعمیر</span>

        <span class="legend-item active" data-status="Ready for Inspection"><span class="legend-dot" style="background-color: rgba(255, 140, 0, 0.8);"></span>آماده بازرسی</span>
        <span class="legend-item active" data-status="Pending"><span class="legend-dot" style="background-color: rgba(108, 117, 125, 0.4);"></span>در انتظار</span>
    </div>
    <div id="currentZoneInfo">
        <strong>نقشه فعلی:</strong> <span id="zoneNameDisplay"></span>
        <strong>پیمانکار:</strong> <span id="zoneContractorDisplay"></span>
        <strong>بلوک:</strong> <span id="zoneBlockDisplay"></span>
    </div>

    <div class="layer-controls" id="layerControlsContainer"></div>
    <div class="navigation-controls"><button id="backToPlanBtn">بازگشت به پلن اصلی</button></div>
    <div id="regionZoneNavContainer">
        <h3 class="region-zone-nav-title">ناوبری سریع به زون‌ها</h3>
        <div class="region-zone-nav-select-row">
            <label for="regionSelect" class="region-zone-nav-label">انتخاب محدوده:</label>
            <select id="regionSelect" class="region-zone-nav-select">
                <option value="">-- ابتدا یک محدوده انتخاب کنید --</option>
            </select>
        </div>
        <div id="zoneButtonsContainer" class="region-zone-nav-zone-buttons"></div>
    </div>
    <p class="description">برای مشاهده چک لیست، روی المان مربوطه در نقشه کلیک کنید.</p>
    <div id="svgContainer"></div>
    <div id="crack-drawer-modal" class="crack-drawer-modal">
        <div class="drawer-header">
            <h3 id="drawer-title">ترسیم ترک برای المان</h3>
            <button id="drawer-close-btn" class="drawer-close-btn">×</button>
        </div>
        <div class="drawer-body">
            <div class="drawer-tools">
                <h4>ابزارها</h4>
                <div class="tool-item">
                    <label>ترک ریز ( 0.2mm بزرگتر از)</label>
                    <div class="tool-controls">
                        <input type="color" class="tool-color" value="#FFEB3B">
                        <input type="number" class="tool-thickness" value="1" step="0.5">
                        <button class="tool-btn active">انتخاب</button>
                    </div>
                </div>
                <div class="tool-item">
                    <label>ترک متوسط (0.2-0.5mm)</label>
                    <div class="tool-controls">
                        <input type="color" class="tool-color" value="#FF9800">
                        <input type="number" class="tool-thickness" value="2.5" step="0.5">
                        <button class="tool-btn">انتخاب</button>
                    </div>
                </div>
                <div class="tool-item">
                    <label>ترک عمیق (>0.5mm)</label>
                    <div class="tool-controls">
                        <input type="color" class="tool-color" value="#F44336">
                        <input type="number" class="tool-thickness" value="4" step="0.5">
                        <button class="tool-btn">انتخاب</button>
                    </div>
                </div>
                <hr>
                <button id="drawer-undo-btn">Undo</button>
            </div>
            <div class="drawer-canvas-container">
                <div id="ruler-top" class="ruler horizontal"></div>
                <div id="ruler-left" class="ruler vertical"></div>
                <canvas id="crack-canvas"></canvas>
            </div>
        </div>
        <div class="drawer-footer">
            <button id="drawer-save-btn" class="btn save">ذخیره و بستن</button>
        </div>
    </div>
    <div class="form-popup" id="universalChecklistForm">
        <!-- This container will be completely filled by JavaScript -->
        <div id="universal-form-element">

        </div>
    </div>


    <?php require_once 'footer.php'; ?>


    <script src="/ghom/assets/js/crypto-js.min.js"></script>

    <script type="text/javascript" src="/ghom/assets/js/jalalidatepicker.min.js"></script>


    <!-- STEP 2: LOAD THE MAIN APPLICATION LOGIC -->
    <script src="/ghom/assets/js/ghom_app.js"></script>



</body>

</html>