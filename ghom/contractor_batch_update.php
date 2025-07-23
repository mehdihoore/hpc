<?php
// ghom/contractor_batch_update.php
require_once __DIR__ . '/../../sercon/bootstrap.php';
secureSession();
if (!isLoggedIn()) {
    header('Location: /login.php?msg=login_required');
    exit();
}
if (!in_array($_SESSION['role'], ['admin', 'superuser', 'user', 'cat', 'car', 'coa', 'crs'])) {
    http_response_code(403);
    require 'Access_Denied.php';
    exit;
}
$pageTitle = "اعلام وضعیت گروهی";
require_once __DIR__ . '/header_ghom.php';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title><?php echo escapeHtml($pageTitle); ?></title>
    <link rel="stylesheet" href="/ghom/assets/css/jalalidatepicker.min.css" />
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

        /* SVG Container Optimizations */
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

        #svgContainer.loading {
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="20" fill="none" stroke="%23007bff" stroke-width="4"><animate attributeName="r" values="20;25;20" dur="1s" repeatCount="indefinite"/></circle></svg>');
            background-repeat: no-repeat;
            background-position: center;
            background-size: 50px 50px;
        }

        #svgContainer.loading::before {
            content: "در حال بارگذاری...";
            position: absolute;
            top: 60%;
            left: 50%;
            transform: translateX(-50%);
            color: #007bff;
            font-weight: bold;
        }

        #svgContainer.dragging {
            cursor: grabbing;
        }

        /* Optimize SVG rendering */
        #svgContainer svg {
            display: block;
            width: 100%;
            height: 100%;
            /* Critical for performance */
            shape-rendering: optimizeSpeed;
            text-rendering: optimizeSpeed;
            image-rendering: optimizeSpeed;
        }

        /* Lightweight interactive elements */
        .interactive-element {
            cursor: pointer;
            /* Simplified transitions for better performance */
            transition: stroke 0.1s ease-out;
        }

        .element-selected {
            stroke: #ffc107 !important;
            stroke-width: 3px !important;
            stroke-opacity: 1 !important;
            fill-opacity: 0.3 !important;
        }

        /* Rest of your styles... */
        .navigation-controls,
        .layer-controls {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .navigation-controls button,
        .layer-controls button,
        .header-action-btn {
            padding: 8px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-family: inherit;
            border: 1px solid transparent;
        }

        .navigation-controls button {
            border-color: #007bff;
            background-color: #007bff;
            color: white;
        }

        .layer-controls button {
            border-color: #ccc;
            background-color: #f8f9fa;
        }

        .layer-controls button.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }

        .header-action-btn {
            background-color: #28a745;
            color: white;
            font-size: 0.9em;
        }

        p.description {
            text-align: center;
            margin-bottom: 10px;
        }

        #batch-update-panel {
            display: none;
            /* Hidden by default, toggled by your JS */
            position: fixed;
            /* Makes it float over the page */
            top: 150px;
            /* Position from the top */
            right: 20px;
            /* Position from the right */
            z-index: 1000;
            background: #ffffff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
            width: 320px;
            /* A reasonable, fixed width */
            border-top: 5px solid #007bff;
            max-height: calc(100vh - 170px);
            /* Prevents it from going off-screen */
            overflow-y: auto;
            /* Adds scroll if content is too tall */
        }

        #batch-update-panel .form-group {
            margin-bottom: 15px;
        }

        #batch-update-panel label {
            display: block;
            margin-bottom: 5px;
        }

        #batch-update-panel input,
        #batch-update-panel select,
        #batch-update-panel textarea {
            width: 100%;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }

        #submitBatchUpdate {
            width: 100%;
            padding: 10px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }


        /* Loading states */
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Performance indicator */
        #performance-info {
            position: fixed;
            bottom: 10px;
            left: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8em;
            display: none;
        }

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


        .interactive-element {
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .interactive-element:hover {
            filter: brightness(1.1);
        }

        /* FORM POPUP */
        .form-popup {
            display: none;
            position: fixed;
            bottom: 10px;
            left: 10px;
            z-index: 20;
            background-color: #f9f9f9;
            padding: 15px;
            border: 2px solid #555;
            border-radius: 5px;
            max-width: 800px;
            width: 95vw;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.3);
        }

        .form-popup h3 {
            margin-top: 0;
            text-align: center;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
        }

        .form-popup table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 0.9em;
        }

        .form-popup th,
        .form-popup td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: right;
        }

        .form-popup th {
            background-color: #f2f2f2;
            font-weight: bold;
        }

        .form-popup .checklist-input,
        .notes-container textarea,
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            font-family: inherit;
            border-radius: 3px;
        }

        .notes-container textarea {
            min-height: 60px;
            padding: 5px;
            border: 1px solid #ccc;
        }

        .form-popup .btn-container {
            text-align: left;
            margin-top: 15px;
        }

        .form-popup .btn {
            padding: 8px 12px;
            color: white;
            border: none;
            cursor: pointer;
            margin-right: 5px;
            border-radius: 3px;
        }

        .form-popup .btn.save {
            background-color: #28a745;
        }

        .form-popup .btn.cancel {
            background-color: #dc3545;
        }

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
            border: 2px solid #3498db;
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

        .gfrc-tab-buttons {
            display: flex;
            border-bottom: 2px solid #ccc;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .gfrc-tab-button {
            padding: 10px 20px;
            cursor: pointer;
            background: #eee;
            border: 1px solid #ccc;
            border-bottom: none;
            margin-left: 5px;
            border-radius: 5px 5px 0 0;
            font-size: 0.9em;
        }

        .gfrc-tab-button.active {
            background: #fff;
            border-bottom: 2px solid #fff;
            font-weight: bold;
            color: #000;
        }

        .gfrc-tab-content {
            display: none;
            padding: 15px;
            max-height: 40vh;
            overflow-y: auto;
        }

        .gfrc-tab-content.active {
            display: block;
        }

        .checklist-item-row {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }

        .checklist-item-row:last-child {
            border-bottom: none;
        }

        .checklist-item-row label.item-text {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .checklist-item-row .status-selector {
            display: flex;
            gap: 15px;
            margin-bottom: 8px;
        }

        .controls-toolbar {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: center;
            margin: 20px 0;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.95em;
            border: none;
            color: white;
        }

        .action-btn.primary {
            background-color: #007bff;
        }

        .action-btn.secondary {
            background-color: #6c757d;
        }

        #instructions-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 2000;
            display: flex;
            justify-content: center;
            align-items: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
        }

        #instructions-overlay.visible {
            opacity: 1;
            visibility: visible;
        }

        #instructions-content {
            background: white;
            padding: 25px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            transform: scale(0.9);
            transition: transform 0.3s;
        }

        #instructions-overlay.visible #instructions-content {
            transform: scale(1);
        }

        #close-instructions-btn {
            position: absolute;
            top: 10px;
            left: 10px;
            background: transparent;
            border: none;
            font-size: 24px;
            cursor: pointer;
            line-height: 1;
        }

        #instructions-content h3 {
            text-align: center;
            margin-top: 0;
            margin-bottom: 20px;
            color: #0056b3;
        }

        #instructions-content h4 {
            margin-top: 20px;
            margin-bottom: 10px;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }

        #instructions-content ul {
            list-style: none;
            padding-right: 0;
        }

        #instructions-content li {
            margin-bottom: 8px;
        }

        #instructions-content kbd {
            background-color: #eee;
            border-radius: 3px;
            border: 1px solid #b4b4b4;
            box-shadow: 0 1px 1px rgba(0, 0, 0, .2), 0 2px 0 0 rgba(255, 255, 255, .7) inset;
            color: #333;
            display: inline-block;
            font-size: .85em;
            font-weight: 700;
            line-height: 1;
            padding: 2px 4px;
            white-space: nowrap;
        }

        #zoneSelectionMenu {
            position: absolute;
            z-index: 1005;
            background-color: #f9f9f9;
            border: 1px solid #ccc;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            padding: 15px;
            border-radius: 8px;
            width: 600px;
            max-width: 90vw;
            display: flex;
            flex-direction: column;
        }

        .zone-menu-title {
            margin: -5px -5px 15px -5px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
            text-align: right;
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }

        .zone-menu-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .zone-menu-grid button {
            padding: 12px;
            border: 1px solid #ddd;
            background-color: #fff;
            cursor: pointer;
            text-align: right;
            font-size: 14px;
            border-radius: 5px;
            transition: all 0.2s;
        }

        .zone-menu-grid button:hover {
            background-color: #e9e9e9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        #zoneSelectionMenu .close-menu-btn {
            margin-top: 15px;
            padding: 10px;
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        #zoneSelectionMenu .close-menu-btn:hover {
            background-color: #5a6268;
        }

        /* --- Mobile Responsive Styles for Menu --- */
        @media (max-width: 640px) {
            #zoneSelectionMenu {
                width: 90vw;
            }

            .zone-menu-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 420px) {
            .zone-menu-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body data-user-role="<?php echo escapeHtml($_SESSION['role']); ?>">

    <!-- ORIGINAL HTML STRUCTURE RESTORED -->
    <div class="controls-toolbar">
        <button id="toggle-batch-panel-btn" class="action-btn primary">اعلام وضعیت گروهی</button>
        <!-- NEW HELP BUTTON -->
        <button id="show-instructions-btn" class="action-btn secondary">راهنما</button>
    </div>
    <div id="view-filter-panel" style="border: 1px solid #007bff; padding: 15px; margin-bottom: 20px; background-color: #f8f9fa; width: 90%; max-width: 800px; display: none;">
        <h4 style="margin-top: 0; margin-bottom: 10px; color: #0056b3;">فیلتر نمایش وضعیت</h4>
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <div class="form-group" style="flex: 1; min-width: 200px;">
                <label for="view-element-type-select">۱. نوع المان:</label>
                <select id="view-element-type-select" class="form-control"></select>
            </div>
            <div class="form-group" style="flex: 1; min-width: 200px;">
                <label for="view-stage-select">۲. مرحله:</label>
                <select id="view-stage-select" class="form-control" disabled>
                    <option value="">-- ابتدا نوع المان را انتخاب کنید --</option>
                </select>
            </div>
            <div class="form-group" style="align-self: flex-end;">
                <button id="reset-view-filter-btn" class="action-btn secondary" style="background-color: #6c757d;">پاک کردن فیلتر</button>
            </div>
        </div>
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
            <select id="regionSelect" class="region-zone-nav-select"></select>
        </div>
        <div id="zoneButtonsContainer" class="region-zone-nav-zone-buttons"></div>
    </div>
    <script>
        async function initializeApp() {
            try {
                const [groupRes, regionRes, planRes] = await Promise.all([
                    fetch('/ghom/assets/js/svgGroupConfig.json'),
                    fetch('/ghom/assets/js/regionToZoneMap.json'),
                    fetch('/ghom/assets/js/planNavigationMappings.json')
                ]);
                if (!groupRes.ok || !regionRes.ok || !planRes.ok) {
                    throw new Error('Failed to load configuration files.');
                }
                // Assign the data to the global window object so other scripts can see it
                window.svgGroupConfig = await groupRes.json();
                window.regionToZoneMap = await regionRes.json();
                window.planNavigationMappings = await planRes.json();
                console.log("Configuration loaded successfully on index.php.");
            } catch (error) {
                console.error("CRITICAL ERROR:", error);
                document.getElementById('svgContainer').innerHTML = `<p style="color:red; font-weight:bold;">خطا در بارگذاری فایل‌های پیکربندی.</p>`;
            }
        }

        // This listener ensures the config is loaded before any other script runs
        document.addEventListener('DOMContentLoaded', () => {
            initializeApp().then(() => {
                console.log("App initialized, ghom_app.js can now execute safely.");
            });
        });
    </script>
    <div id="svgContainer"></div>

    <div id="batch-update-panel">
        <h3>اعلام وضعیت گروهی</h3>
        <p><strong><span id="selectionCount">0</span> المان انتخاب شده</strong></p>
        <hr>
        <div class="form-group">
            <label for="batch_stage">برای مرحله:</label>
            <select id="batch_stage" name="stage_id" required disabled>
                <option value="">ابتدا المان(های) هم‌نوع را انتخاب کنید</option>
            </select>
        </div>

        <div id="update-existing-container" class="form-group">
            <label>
                <input type="checkbox" id="update_existing_checkbox">
                <div>
                    <strong><span id="existingCount">0</span> المان قبلا ثبت شده.</strong>
                    <br>
                    <span>با انتخاب این گزینه، تاریخ و توضیحات آنها بروز خواهد شد.</span>
                </div>
            </label>
        </div>

        <div class="form-group"><label for="batch_date">تاریخ اعلام وضعیت:</label><input type="text" id="batch_date" data-jdp readonly></div>
        <div class="form-group"><label for="batch_notes">توضیحات مشترک (اختیاری):</label><textarea id="batch_notes" rows="3"></textarea></div>
        <button id="submitBatchUpdate">ثبت برای بازرسی</button>
    </div>

    <!-- NEW INSTRUCTIONS MODAL HTML -->
    <div id="instructions-overlay">
        <div id="instructions-content">
            <button id="close-instructions-btn">&times;</button>
            <h3>راهنمای استفاده</h3>
            <h4>کامپیوتر (Desktop)</h4>
            <ul>
                <li><b>انتخاب تکی:</b> روی المان کلیک کنید.</li>
                <li><b>انتخاب چندتایی:</b> کلید <kbd>Ctrl</kbd> را نگه دارید و روی المان‌ها کلیک کنید.</li>
                <li><b>انتخاب بازه‌ای:</b> روی یک المان کلیک کنید، کلید <kbd>Shift</kbd> را نگه دارید و روی المان دیگر کلیک کنید.</li>
                <li><b>انتخاب با کادر (Marquee):</b> در فضای خالی کلیک کرده و موس را بکشید.</li>
                <li><b>جا‌به‌جایی نقشه (Pan):</b> کلیک راست موس را نگه دارید و بکشید، یا کلید <kbd>Space</kbd> را نگه دارید و با کلیک چپ بکشید.</li>
                <li><b>بزرگ‌نمایی (Zoom):</b> از چرخ موس (Scroll Wheel) استفاده کنید.</li>
            </ul>
            <h4>موبایل و تبلت (Mobile/Tablet)</h4>
            <ul>
                <li><b>انتخاب تکی:</b> روی المان ضربه (Tap) بزنید.</li>
                <li><b>انتخاب چندتایی / با کادر:</b> در یک فضای خالی یا روی یک المان <strong>لمس طولانی (Long Press)</strong> کنید و انگشت خود را بکشید.</li>
                <li><b>جا‌به‌جایی نقشه (Pan):</b> با یک انگشت بکشید.</li>
                <li><b>بزرگ‌نمایی (Zoom):</b> با دو انگشت (Pinch) زوم کنید یا برای بزرگ‌نمایی سریع، دو بار ضربه (Double Tap) بزنید.</li>
            </ul>
        </div>
    </div>


    <script src="/ghom/assets/js/jalalidatepicker.min.js"></script>


    <!-- Script 2: The script for the instructions modal -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const instructionsOverlay = document.getElementById('instructions-overlay');
            const showInstructionsBtn = document.getElementById('show-instructions-btn');
            const closeInstructionsBtn = document.getElementById('close-instructions-btn');

            if (showInstructionsBtn && instructionsOverlay) {
                showInstructionsBtn.addEventListener('click', () => {
                    instructionsOverlay.classList.add('visible');
                });
            }

            if (closeInstructionsBtn && instructionsOverlay) {
                closeInstructionsBtn.addEventListener('click', () => {
                    instructionsOverlay.classList.remove('visible');
                });
            }

            // Close the modal if the user clicks on the dark background
            if (instructionsOverlay) {
                instructionsOverlay.addEventListener('click', (event) => {
                    if (event.target === instructionsOverlay) {
                        instructionsOverlay.classList.remove('visible');
                    }
                });
            }

            // --- FIX: Disable past dates in the date picker ---
            if (typeof jalaliDatepicker !== 'undefined') {
                jalaliDatepicker.startWatch({
                    minDate: "today"
                });
            }

            // --- NEW, SMARTER LOGIC FOR THE STAGE DROPDOWN ---
            document.addEventListener('selectionChanged', async (e) => {
                const selectedElementsMap = e.detail.selectedElements;
                const batchStageSelect = document.getElementById('batch_stage');
                const updateContainer = document.getElementById('update-existing-container');

                // Hide and reset the update option on every change
                updateContainer.style.display = 'none';
                document.getElementById('update_existing_checkbox').checked = false;

                if (!batchStageSelect) return;
                const elements = [...selectedElementsMap.values()];

                if (elements.length === 0) {
                    batchStageSelect.innerHTML = '<option value="">ابتدا المان انتخاب کنید</option>';
                    batchStageSelect.disabled = true;
                    return;
                }

                const firstElementType = elements[0].element_type;
                const allSameType = elements.every(el => el.element_type === firstElementType);

                if (!allSameType) {
                    batchStageSelect.innerHTML = '<option value="">المان‌ها باید از یک نوع باشند</option>';
                    batchStageSelect.disabled = true;
                    return;
                }

                try {
                    batchStageSelect.disabled = true;
                    batchStageSelect.innerHTML = '<option value="">در حال بررسی وضعیت...</option>';

                    const [allStages, elementStatuses] = await Promise.all([
                        fetch(`/ghom/api/get_stages.php?type=${firstElementType}`).then(res => res.json()),
                        fetch('/ghom/api/get_selection_status.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                element_ids: elements.map(el => el.element_id)
                            })
                        }).then(res => res.json())
                    ]);

                    if (!allStages || allStages.length === 0) {
                        batchStageSelect.innerHTML = '<option value="">مرحله‌ای تعریف نشده</option>';
                        return;
                    }

                    let maxLastPassedOrder = -1;
                    elements.forEach(el => {
                        const lastPassed = elementStatuses[el.element_id];
                        maxLastPassedOrder = Math.max(maxLastPassedOrder, lastPassed !== undefined ? parseInt(lastPassed, 10) : -1);
                    });

                    const nextStageOrder = maxLastPassedOrder + 1;
                    const availableStages = allStages.filter((stage, index) => index === nextStageOrder);

                    if (availableStages.length > 0) {
                        batchStageSelect.innerHTML = availableStages.map(s => `<option value="${s.stage_id}">${s.stage}</option>`).join('');
                        batchStageSelect.disabled = false;

                        // **NEW**: Check if any selected elements are already submitted for this specific stage
                        const nextStageId = availableStages[0].stage_id;
                        const checkResponse = await fetch(`/ghom/api/check_existing_for_stage.php`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                element_ids: elements.map(el => el.element_id),
                                stage_id: nextStageId
                            })
                        });
                        const existingData = await checkResponse.json();

                        if (existingData.count > 0) {
                            document.getElementById('existingCount').textContent = existingData.count;
                            updateContainer.style.display = 'block';
                        }

                    } else {
                        batchStageSelect.innerHTML = '<option value="">مرحله بعدی یافت نشد</option>';
                        batchStageSelect.disabled = true;
                    }
                } catch (error) {
                    console.error("Error updating stages:", error);
                    batchStageSelect.innerHTML = '<option value="">خطا در بارگذاری مراحل</option>';
                    batchStageSelect.disabled = true;
                }
            });
        });
    </script>

    <!-- Script 3: The main application logic, loaded correctly as a module -->
    <script type="module" src="/ghom/assets/js/shared_svg_logic.js"></script>
    <?php require_once 'footer.php'; ?>

</body>

</html>
<?php
// /public_html/ghom/api/get_selection_status.php (NEW FILE)
header('Content-Type: application/json');
require_once __DIR__ . '/../../../sercon/bootstrap.php';
secureSession();

$element_ids = json_decode(file_get_contents('php://input'), true)['element_ids'] ?? [];

if (empty($element_ids)) {
    exit(json_encode([]));
}

try {
    $pdo = getProjectDBConnection('ghom');
    $placeholders = implode(',', array_fill(0, count($element_ids), '?'));

    // This query finds the highest-ordered stage that has been marked "OK" for each element
    $sql = "
        SELECT 
            i.element_id, 
            MAX(ws.display_order) as last_passed_order
        FROM inspections i
        JOIN inspection_stages ws ON i.stage_id = ws.stage_id
        WHERE i.element_id IN ($placeholders) AND i.status = 'OK'
        GROUP BY i.element_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($element_ids);
    $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode($results);
} catch (Exception $e) {
    http_response_code(500);
    error_log("API Error in get_selection_status.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database error.']);
}
