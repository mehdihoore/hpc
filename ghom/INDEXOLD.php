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
            content: "در حال بارگذاری SVG...";
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
            max-width: 450px;
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
    </style>

</head>



<body data-user-role="<?php echo escapeHtml($user_role); ?>">

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

    <!-- GFRC Form Popup -->
    <div class="form-popup" id="gfrcChecklistForm">
        <form id="gfrc-form-element">
            <fieldset id="consultant-section">
                <legend>بخش مشاور</legend>
                <input type="hidden" id="gfrcElementId" name="elementId">
                <h3 id="gfrcFormTitle">چک لیست کنترل کیفی - GFRC</h3>
                <div id="gfrcStaticData">
                    <p><strong>پیمانکار:</strong> <span id="gfrcContractor"></span></p>
                    <p><strong>محدوده:</strong> <span id="gfrcArea"></span></p>
                    <p><strong>شماره پنل:</strong> <span id="gfrcPanelNumber"></span></p>
                </div>
                <div class="form-group">
                    <label for="gfrcInspectionDate">تاریخ بازرسی مشاور</label>
                    <input type="text" id="gfrcInspectionDate" name="inspection_date" data-jdp readonly>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>شرح بررسی</th>
                            <th class="status-cell">وضعیت</th>
                            <th>مقدار / توضیحات</th>
                        </tr>
                    </thead>
                    <tbody id="gfrcChecklistBody"></tbody>
                </table>
                <div class="notes-container">
                    <label for="gfrcNotesTextarea">ملاحظات:</label>
                    <textarea id="gfrcNotesTextarea" name="notes"></textarea>
                </div>

                <!-- **NEW SECTION FOR DISPLAYING FILES** -->
                <div id="gfrc-attachments-container" class="attachments-display-container" style="margin-top: 15px;">
                    <strong>پیوست‌های موجود:</strong>
                    <ul id="gfrc-attachments-list" style="list-style-type: disc; padding-right: 20px;">
                        <!-- Links will be added here by JS -->
                    </ul>
                </div>

                <div class="file-upload-container">
                    <label for="gfrcAttachments">افزودن پیوست جدید:</label>
                    <input type="file" id="gfrcAttachments" name="attachments[]" multiple>
                </div>
            </fieldset>
            <fieldset id="contractor-section">
                <legend>بخش پیمانکار</legend>
                <div class="form-group">
                    <label for="contractor_status">وضعیت اعلامی پیمانکار</label>
                    <select id="contractor_status" name="contractor_status">
                        <option value="Pending">در حال اجرا</option>
                        <option value="Ready for Inspection">آماده برای بازرسی</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="contractor_date">تاریخ اعلام وضعیت</label>
                    <input type="text" id="contractor_date" name="contractor_date" data-jdp readonly>
                </div>
                <div class="form-group">
                    <label for="contractor_notes">توضیحات پیمانکار</label>
                    <textarea id="contractor_notes" name="contractor_notes"></textarea>
                </div>
            </fieldset>
            <div class="btn-container">
                <button type="submit" class="btn save">ذخیره</button>
                <button type="button" class="btn cancel" onclick="closeForm('gfrcChecklistForm')">بستن</button>
            </div>
        </form>
    </div>


    <footer>
        <p>@1404-1405 شرکت آلومنیوم شیشه تهران. تمامی حقوق محفوظ است.</p>
    </footer>

    <!-- ADDED: Script for the date picker -->
    <script type="text/javascript" src="/ghom/assets/js/jalalidatepicker.min.js"></script>
    <script>
        const USER_ROLE = document.body.dataset.userRole;
        //<editor-fold desc="Config and Global Variables">
        let currentZoom = 1;
        const zoomStep = 0.2;
        const minZoom = 0.5;
        const maxZoom = 40;
        let currentSvgElement = null;
        let isPanning = false;
        let panStartX = 0,
            panStartY = 0,
            panX = 0,
            panY = 0;
        let lastTouchDistance = 0;
        let currentPlanFileName = "Plan.svg";
        let currentPlanZoneName = "نامشخص";
        let currentPlanDefaultContractor = "پیمانکار عمومی";
        let currentPlanDefaultBlock = "بلوک عمومی";
        let currentSvgAxisMarkersX = [],
            currentSvgAxisMarkersY = [];
        let currentSvgHeight = 2200,
            currentSvgWidth = 3000;
        let currentlyActiveSvgElement = null;
        const SVG_BASE_PATH = "/ghom/"; // Use root-relative path

        const svgGroupConfig = {
            GFRC: {
                label: "GFRC",
                defaultVisible: true,
                interactive: true,
                elementType: "GFRC"
            },
            Box_40x80x4: {
                label: "Box_40x80x4",
                defaultVisible: true,
                interactive: true,
                elementType: "Zirsazi"
            },
            Box_40x20: {
                label: "Box_40x20",
                defaultVisible: true,
                interactive: true,
                elementType: "Zirsazi"
            },
            tasme: {
                label: "تسمه",
                defaultVisible: true,
                interactive: true,
                elementType: "Zirsazi"
            },
            nabshi_tooli: {
                label: "نبشی طولی",
                defaultVisible: true,
                interactive: true,
                elementType: "Zirsazi"
            },
            Gasket: {
                label: "Gasket",
                defaultVisible: true,
                interactive: true,
                elementType: "Zirsazi"
            },
            SPACER: {
                label: "فاصله گذار",
                defaultVisible: true,
                interactive: true,
                elementType: "Zirsazi"
            },
            Smoke_Barrier: {
                label: "دودبند",
                defaultVisible: true,
                interactive: true,
                elementType: "Zirsazi"
            },
            uchanel: {
                label: "یو چنل",
                defaultVisible: true,
                interactive: true,
                elementType: "Zirsazi"
            },
            unolite: {
                label: "یونولیت",
                defaultVisible: true,
                interactive: true,
                elementType: "Zirsazi"
            },
            "GFRC-Part6": {
                label: "GFRC - قسمت 6",
                defaultVisible: true,
                interactive: true,
                elementType: "GFRC"
            },
            "GFRC-Part_4": {
                label: "GFRC - قسمت 4",
                defaultVisible: true,
                interactive: true,
                elementType: "GFRC"
            },
            Atieh: {
                label: "بلوک A- آتیه نما",
                color: "#0de16d",
                defaultVisible: true,
                interactive: true,
                contractor: "شرکت آتیه نما",
                block: "A",
                elementType: "Region"
            },
            org: {
                label: "بلوک - اورژانس A- آتیه نما",
                color: "#ebb00d",
                defaultVisible: true,
                interactive: true,
                contractor: "شرکت آتیه نما",
                block: "A - اورژانس",
                elementType: "Region"
            },
            AranB: {
                label: "بلوک B-آرانسج",
                color: "#38abee",
                defaultVisible: true,
                interactive: true,
                contractor: "شرکت آرانسج",
                block: "B",
                elementType: "Region"
            },
            AranC: {
                label: "بلوک C-آرانسج",
                color: "#ee3838",
                defaultVisible: true,
                interactive: true,
                contractor: "شرکت آرانسج",
                block: "C",
                elementType: "Region"
            },
            hayatOmran: {
                label: " حیاط عمران آذرستان",
                color: "#eef595da",
                defaultVisible: true,
                interactive: true,
                contractor: "شرکت عمران آذرستان",
                block: "حیاط",
                elementType: "Region"
            },
            hayatRos: {
                label: " حیاط رس",
                color: "#eb0de7da",
                defaultVisible: true,
                interactive: true,
                contractor: "شرکت ساختمانی رس",
                block: "حیاط",
                elementType: "Region"
            },
            handrail: {
                label: "نقشه ندارد",
                color: "rgba(238, 56, 56, 0.3)",
                defaultVisible: true,
                interactive: true
            },
            "glass_40%": {
                label: "شیشه 40%",
                color: "rgba(173, 216, 230, 0.7)",
                defaultVisible: true,
                interactive: true,
                elementType: "Glass"
            },
            "glass_30%": {
                label: "شیشه 30%",
                color: "rgba(173, 216, 230, 0.7)",
                defaultVisible: true,
                interactive: true,
                elementType: "Glass"
            },
            "glass_50%": {
                label: "شیشه 50%",
                color: "rgba(173, 216, 230, 0.7)",
                defaultVisible: true,
                interactive: true,
                elementType: "Glass"
            },
            glass_opaque: {
                label: "شیشه مات",
                color: "rgba(144, 238, 144, 0.7)",
                defaultVisible: true,
                interactive: true,
                elementType: "Glass"
            },
            "glass_80%": {
                label: "شیشه 80%",
                color: "rgba(255, 255, 102, 0.7)",
                defaultVisible: true,
                interactive: true,
                elementType: "Glass"
            },
            Mullion: {
                label: "مولیون",
                color: "rgba(128, 128, 128, 0.9)",
                defaultVisible: true,
                interactive: true,
                elementType: "Mullion"
            },
            Transom: {
                label: "ترنزوم",
                color: "rgba(169, 169, 169, 0.9)",
                defaultVisible: true,
                interactive: true,
                elementType: "Transom"
            },
            Bazshow: {
                label: "بازشو",
                color: "rgba(169, 169, 169, 0.9)",
                defaultVisible: true,
                interactive: true,
                elementType: "Bazshow"
            },
            GLASS: {
                label: "شیشه",
                color: "#eef595da",
                defaultVisible: true,
                interactive: true,
                elementType: "Glass"
            },
            STONE: {
                label: "سنگ",
                color: "#4c28a1",
                defaultVisible: true,
                interactive: true,
                elementType: "STONE"
            },
            Zirsazi: {
                label: "زیرسازی",
                color: "#2464ee",
                defaultVisible: true,
                interactive: true,
                elementType: "Zirsazi"
            },
        };

        const regionToZoneMap = {
            Atieh: [{
                label: "زون 1 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone01.svg"
            }, {
                label: "زون 2 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone02.svg"
            }, {
                label: "زون 3 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone03.svg"
            }, {
                label: "زون 4 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone04.svg"
            }, {
                label: "زون 5 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone05.svg"
            }, {
                label: "زون 6 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone06.svg"
            }, {
                label: "زون 7 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone07.svg"
            }, {
                label: "زون 8 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone08.svg"
            }, {
                label: "زون 9 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone09.svg"
            }, {
                label: "زون 10 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone10.svg"
            }, {
                label: "زون 15 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone15.svg"
            }, {
                label: "زون 16 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone16.svg"
            }, {
                label: "زون 17 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone17.svg"
            }, {
                label: "زون 18 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone18.svg"
            }, {
                label: "زون 19 (آتیه نما)",
                svgFile: SVG_BASE_PATH + "Zone19.svg"
            }],
            org: [{
                label: "زون اورژانس ",
                svgFile: SVG_BASE_PATH + "ZoneEmergency.svg"
            }],
            AranB: [{
                label: "زون 1 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone01.svg"
            }, {
                label: "زون 2 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone02.svg"
            }, {
                label: "زون 3 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone03.svg"
            }, {
                label: "زون 11 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone11.svg"
            }, {
                label: "زون 12 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone12.svg"
            }, {
                label: "زون 13 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone13.svg"
            }, {
                label: "زون 14 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone14.svg"
            }, {
                label: "زون 16 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone16.svg"
            }, {
                label: "زون 19 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone19.svg"
            }, {
                label: "زون 20 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone20.svg"
            }, {
                label: "زون 21 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone21.svg"
            }, {
                label: "زون 26 (آرانسج B)",
                svgFile: SVG_BASE_PATH + "Zone26.svg"
            }],
            AranC: [{
                label: "زون 4 (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone04.svg"
            }, {
                label: "زون 5 (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone05.svg"
            }, {
                label: "زون 6 (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone06.svg"
            }, {
                label: "زون 7E (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone07E.svg"
            }, {
                label: "زون 7S (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone07S.svg"
            }, {
                label: "زون 7N (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone07N.svg"
            }, {
                label: "زون 8 (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone08.svg"
            }, {
                label: "زون 9 (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone09.svg"
            }, {
                label: "زون 10 (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone10.svg"
            }, {
                label: "زون 22 (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone22.svg"
            }, {
                label: "زون 23 (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone23.svg"
            }, {
                label: "زون 24 (آرانسج C)",
                svgFile: SVG_BASE_PATH + "Zone24.svg"
            }],
            hayatOmran: [{
                label: "زون 15 حیاط عمران آذرستان",
                svgFile: SVG_BASE_PATH + "Zone15.svg"
            }, {
                label: "زون 16 حیاط عمران آذرستان",
                svgFile: SVG_BASE_PATH + "Zone16.svg"
            }, {
                label: "زون 17 حیاط عمران آذرستان",
                svgFile: SVG_BASE_PATH + "Zone17.svg"
            }, {
                label: "زون 18 حیاط عمران آذرستان",
                svgFile: SVG_BASE_PATH + "Zone18.svg"
            }],
            hayatRos: [{
                label: "زون 11 حیاط رس ",
                svgFile: SVG_BASE_PATH + "Zone11.svg"
            }, {
                label: "زون 12 حیاط رس",
                svgFile: SVG_BASE_PATH + "Zone12.svg"
            }, {
                label: "زون 13 حیاط رس",
                svgFile: SVG_BASE_PATH + "Zone13.svg"
            }, {
                label: "زون 14 حیاط رس",
                svgFile: SVG_BASE_PATH + "Zone14.svg"
            }],
        };

        const planNavigationMappings = [{
                type: "textAndCircle",
                regex: /^(\d+|[A-Za-z]+[\d-]*)\s+Zone$/i,
                numberGroupIndex: 1,
                svgFilePattern: SVG_BASE_PATH + "Zone{NUMBER}.svg",
                labelPattern: "Zone {NUMBER}",
                defaultContractor: "پیمانکار پیش‌فرض زون عمومی",
                defaultBlock: "بلوک پیش‌فرض زون عمومی"
            },
            {
                svgFile: SVG_BASE_PATH + "Zone09.svg",
                label: "Zone 09",
                defaultContractor: "شرکت آتیه نما زون 09 ",
                defaultBlock: "بلوکA  زون 9 "
            },
            {
                svgFile: SVG_BASE_PATH + "Plan.svg",
                label: "Plan اصلی",
                defaultContractor: "مدیر پیمان ",
                defaultBlock: "پروژه بیمارستان قم "
            },
        ];
        //</editor-fold>

        function clearActiveSvgElementHighlight() {
            if (currentlyActiveSvgElement) {
                currentlyActiveSvgElement.classList.remove('svg-element-active');
                currentlyActiveSvgElement = null;
            }
        }

        function closeAllForms() {
            document.querySelectorAll('.form-popup').forEach(form => form.style.display = 'none');
            closeGfrcSubPanelMenu();
            clearActiveSvgElementHighlight();
        }

        function closeForm(formId) {
            document.getElementById(formId).style.display = 'none';
            clearActiveSvgElementHighlight();
        }

        function showGfrcSubPanelMenu(clickedElement, dynamicContext) {
            closeGfrcSubPanelMenu();
            const orientation = clickedElement.dataset.panelOrientation;
            let subPanelIds = [];

            // Determine which set of sub-panels to show based on orientation
            if (orientation === 'عمودی') {
                subPanelIds = ['Face', 'Left Face', 'Right Face'];
            } else if (orientation === 'افقی') {
                subPanelIds = ['Face', 'Top Face', 'Bottom Face'];
            } else {
                // Fallback for unknown orientation
                openGfrcChecklistForm(clickedElement.dataset.uniquePanelId, 'Default', dynamicContext);
                return;
            }

            const menu = document.createElement("div");
            menu.id = "gfrcSubPanelMenu";
            subPanelIds.forEach(partName => {
                const menuItem = document.createElement("button");
                // Create a more specific ID, e.g., FF-01(AT)-Face
                const subPanelId = `${clickedElement.dataset.uniquePanelId}-${partName}`;
                menuItem.textContent = `چک لیست: ${partName}`;
                menuItem.onclick = (e) => {
                    e.stopPropagation();
                    openGfrcChecklistForm(clickedElement.dataset.uniquePanelId, partName, dynamicContext);
                    closeGfrcSubPanelMenu();
                };
                menu.appendChild(menuItem);
            });

            // Add a close button to the menu
            const closeButton = document.createElement("button");
            closeButton.textContent = "بستن منو";
            closeButton.className = 'close-menu-btn';
            closeButton.onclick = (e) => {
                e.stopPropagation();
                closeGfrcSubPanelMenu();
            };
            menu.appendChild(closeButton);

            document.body.appendChild(menu);
            const rect = clickedElement.getBoundingClientRect();
            menu.style.top = `${rect.bottom + window.scrollY}px`;
            menu.style.left = `${rect.left + window.scrollX}px`;

            // Add a listener to close the menu when clicking elsewhere
            setTimeout(() => document.addEventListener("click", closeGfrcMenuOnClickOutside, {
                once: true
            }), 0);
        }

        function closeGfrcSubPanelMenu() {
            const menu = document.getElementById("gfrcSubPanelMenu");
            if (menu) menu.remove();
            document.removeEventListener("click", closeGfrcMenuOnClickOutside);
        }

        function closeGfrcMenuOnClickOutside(event) {
            const menu = document.getElementById("gfrcSubPanelMenu");
            if (menu && !menu.contains(event.target)) {
                closeGfrcSubPanelMenu();
            }
        }
        // A helper function to escape HTML characters for security
        function escapeHtml(unsafe) {
            if (typeof unsafe !== 'string') return '';
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        /**
         * This is the NEW, database-driven function to open the GFRC checklist.
         * It completely replaces the old one.
         */
        function openGfrcChecklistForm(elementId, partName, dynamicContext) {
            const form = document.getElementById('gfrcChecklistForm');
            const checklistBody = document.getElementById('gfrcChecklistBody');
            const fullElementId = `${elementId}-${partName}`;

            // --- Reset the form fields ---
            document.getElementById('gfrcElementId').value = fullElementId;
            document.getElementById('gfrcFormTitle').textContent = `در حال بارگذاری چک لیست: ${elementId} (${partName})`;
            document.getElementById('gfrcContractor').textContent = dynamicContext.contractor;
            document.getElementById('gfrcArea').textContent = dynamicContext.areaString;
            document.getElementById('gfrcPanelNumber').textContent = elementId;
            document.getElementById('gfrcInspectionDate').value = '';
            document.getElementById('gfrcNotesTextarea').value = '';
            document.getElementById('gfrcAttachments').value = ''; // Clear file input
            document.getElementById('gfrc-attachments-list').innerHTML = '<li>در حال بررسی...</li>';

            checklistBody.innerHTML = '<tr><td colspan="3">در حال بارگذاری...</td></tr>';
            form.style.display = 'block';

            const queryParams = new URLSearchParams({
                element_id: fullElementId,
                element_type: 'GFRC',
                ...dynamicContext
            });
            fetch(`${SVG_BASE_PATH}api/get_element_data.php?${queryParams.toString()}`)
                .then(res => res.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);

                    // --- Populate Checklist Items (same as before) ---
                    checklistBody.innerHTML = "";
                    if (data.items.length > 0) {
                        data.items.forEach(item => {
                            const row = checklistBody.insertRow();
                            row.insertCell().textContent = item.item_text;
                            const statusCell = row.insertCell();
                            statusCell.className = 'status-cell';
                            const ok_checked = item.item_status === 'OK' ? 'checked' : '';
                            const nok_checked = item.item_status === 'Not OK' ? 'checked' : '';
                            const na_checked = !item.item_status || item.item_status === 'N/A' ? 'checked' : '';
                            statusCell.innerHTML = `<input type="radio" name="status_${item.item_id}" value="OK" ${ok_checked}><label>OK</label><input type="radio" name="status_${item.item_id}" value="Not OK" ${nok_checked}><label>NOK</label><input type="radio" name="status_${item.item_id}" value="N/A" ${na_checked}><label>N/A</label>`;
                            row.insertCell().innerHTML = `<input type="text" class="checklist-input" data-item-id="${item.item_id}" value="${item.item_value || ''}">`;
                        });
                    } else {
                        checklistBody.innerHTML = '<tr><td colspan="3">چک لیستی برای این نوع المان یافت نشده است.</td></tr>';
                    }

                    // --- **NEW LOGIC to populate Date and Attachments** ---
                    const attachmentsList = document.getElementById('gfrc-attachments-list');
                    attachmentsList.innerHTML = ''; // Clear "loading" message

                    if (data.inspectionData) {
                        // Populate Date
                        document.getElementById('contractor_status').value = data.inspectionData.contractor_status || 'Pending';
                        document.getElementById('contractor_notes').value = data.inspectionData.contractor_notes || '';
                        document.getElementById('contractor_date').value = data.inspectionData.contractor_date_jalali || '';

                        // Populate Attachments List
                        let attachments = [];
                        if (data.inspectionData.attachments) {
                            try {
                                attachments = JSON.parse(data.inspectionData.attachments);
                            } catch (e) {}
                        }

                        if (attachments && attachments.length > 0) {
                            attachments.forEach(path => {
                                const li = document.createElement('li');
                                const a = document.createElement('a');
                                a.href = path; // The public path from the DB
                                a.textContent = path.split('/').pop(); // Show just the filename
                                a.target = '_blank'; // Open in new tab
                                li.appendChild(a);
                                attachmentsList.appendChild(li);
                            });
                        } else {
                            attachmentsList.innerHTML = '<li>هیچ فایلی پیوست نشده است.</li>';
                        }
                    } else {
                        attachmentsList.innerHTML = '<li>هیچ فایلی پیوست نشده است.</li>';
                    }
                    setFormState(form, USER_ROLE);
                    // Finally, initialize the date picker on the now-visible input
                    jalaliDatepicker.startWatch({
                        selector: '[data-jdp]'
                    });
                })
                .catch(err => {
                    document.getElementById('gfrcFormTitle').textContent = 'خطا';
                    checklistBody.innerHTML = `<tr><td colspan="3">خطا در بارگذاری اطلاعات: ${err.message}</td></tr>`;
                });
        }

        function setFormState(form, role) {
            const consultant_section = form.querySelector('#consultant-section');
            const contractor_section = form.querySelector('#contractor-section');
            const saveButton = form.querySelector('.btn.save');

            // Disable everything by default
            consultant_section.disabled = true;
            contractor_section.disabled = true;
            saveButton.style.display = 'none';

            switch (role) {
                case 'admin': // مشاور
                    consultant_section.disabled = false;
                    saveButton.style.display = 'inline-block';
                    break;
                case 'supervisor': // پیمانکار
                    contractor_section.disabled = false;
                    saveButton.style.display = 'inline-block';
                    break;
                case 'guest': // کارفرما
                    // Everything remains disabled, save button hidden
                    break;
            }
        }
        //<editor-fold desc="SVG Initialization and Interaction">
        function makeElementInteractive(element, groupId, elementId) {
            element.classList.add("interactive-element");
            const elementType = svgGroupConfig[groupId]?.elementType;
            const dynamicContext = {
                contractor: element.dataset.contractor,
                block: element.dataset.block,
                axisSpan: element.dataset.axisSpan,
                floorLevel: element.dataset.floorLevel,
                areaString: `زون: ${currentPlanZoneName}, محور: ${element.dataset.axisSpan}, طبقه: ${element.dataset.floorLevel}`,
                panelOrientation: element.dataset.panelOrientation
            };

            const clickHandler = (event) => {
                event.stopPropagation();
                closeAllForms();
                element.classList.add('svg-element-active');
                currentlyActiveSvgElement = element;

                if (elementType === "GFRC") {
                    // IMPORTANT: This now calls the menu function
                    showGfrcSubPanelMenu(element, dynamicContext);
                } else {
                    alert(`چک لیست برای ${elementType} هنوز پیاده‌سازی نشده.`);
                }
            };
            element.addEventListener("click", clickHandler);
        }

        function initializeElementsByType(groupElement, elementType, groupId) {
            const elements = groupElement.querySelectorAll("path, rect, circle, polygon, polyline, line, ellipse");
            elements.forEach((el, index) => {
                const elementId = el.id || `${groupId}_${index}`;
                el.dataset.generatedId = elementId;
                const spatialCtx = getElementSpatialContext(el);
                el.dataset.axisSpan = spatialCtx.axisSpan;
                el.dataset.floorLevel = spatialCtx.floorLevel;
                el.dataset.uniquePanelId = el.id || spatialCtx.derivedId || `${groupId}_elem_${index}`;
                el.dataset.contractor = groupElement.dataset.contractor || currentPlanDefaultContractor;
                el.dataset.block = groupElement.dataset.block || currentPlanDefaultBlock;

                if (elementType === "GFRC") {
                    const dims = el.getBBox();
                    el.dataset.panelOrientation = dims.width > dims.height ? "افقی" : "عمودی";
                    el.style.fill = dims.width > dims.height ? "rgba(255, 160, 122, 0.7)" : "rgba(32, 178, 170, 0.7)";
                }
                makeElementInteractive(el, groupId, el.dataset.uniquePanelId);
            });
        }

        function applyGroupStylesAndControls(svgElement) {
            const layerControlsContainer = document.getElementById("layerControlsContainer");
            layerControlsContainer.innerHTML = "";
            for (const groupId in svgGroupConfig) {
                const config = svgGroupConfig[groupId];
                const groupElement = svgElement.getElementById(groupId);
                if (groupElement) {
                    if (config.color && groupId !== "GFRC") {
                        const elementsToColor = groupElement.querySelectorAll("path, rect, circle, polygon, polyline, line, ellipse");
                        elementsToColor.forEach(el => {
                            el.style.fill = config.color;
                        });
                    }
                    groupElement.style.display = config.defaultVisible ? "" : "none";
                    if (config.interactive && config.elementType) {
                        initializeElementsByType(groupElement, config.elementType, groupId);
                    }
                    const button = document.createElement("button");
                    button.textContent = config.label;
                    button.className = config.defaultVisible ? "active" : "inactive";
                    button.addEventListener("click", () => {
                        const isVisible = groupElement.style.display !== "none";
                        groupElement.style.display = isVisible ? "none" : "";
                        button.className = !isVisible ? "active" : "inactive";
                    });
                    layerControlsContainer.appendChild(button);
                }
            }
        }
        //</editor-fold>

        //<editor-fold desc="SVG Loading and Navigation">
        function getRegionAndZoneInfoForFile(svgFullFilename) {
            for (const regionKey in regionToZoneMap) {
                const zonesInRegion = regionToZoneMap[regionKey];
                const foundZone = zonesInRegion.find(zone => zone.svgFile.toLowerCase() === svgFullFilename.toLowerCase());
                if (foundZone) {
                    const regionConfig = svgGroupConfig[regionKey];
                    return {
                        regionKey: regionKey,
                        zoneLabel: foundZone.label,
                        contractor: regionConfig?.contractor,
                        block: regionConfig?.block,
                    };
                }
            }
            return null;
        }

        function setupRegionZoneNavigationIfNeeded() {
            const regionSelect = document.getElementById("regionSelect");
            const zoneButtonsContainer = document.getElementById("zoneButtonsContainer");
            if (regionSelect.dataset.initialized) return;

            for (const regionKey in regionToZoneMap) {
                const option = document.createElement("option");
                option.value = regionKey;
                option.textContent = svgGroupConfig[regionKey]?.label || regionKey;
                regionSelect.appendChild(option);
            }
            regionSelect.addEventListener("change", function() {
                zoneButtonsContainer.innerHTML = "";
                const selectedRegionKey = this.value;
                if (selectedRegionKey && regionToZoneMap[selectedRegionKey]) {
                    regionToZoneMap[selectedRegionKey].forEach(zone => {
                        const button = document.createElement("button");
                        button.textContent = zone.label;
                        button.addEventListener("click", () => loadAndDisplaySVG(zone.svgFile));
                        zoneButtonsContainer.appendChild(button);
                    });
                }
            });
            regionSelect.dataset.initialized = "true";
        }



        //<editor-fold desc="DOM Ready and Event Listeners">
        document.addEventListener("DOMContentLoaded", () => {
            document.getElementById("backToPlanBtn").addEventListener("click", () => loadAndDisplaySVG(SVG_BASE_PATH + "Plan.svg"));
            loadAndDisplaySVG(SVG_BASE_PATH + "Plan.svg");

            document.getElementById('gfrc-form-element').addEventListener('submit', function(event) {
                event.preventDefault();
                const form = event.target;
                const formData = new FormData(form);
                const saveBtn = form.querySelector('.btn.save');

                // Collect checklist data manually
                const itemsPayload = [];
                form.querySelectorAll('.checklist-input').forEach(input => {
                    const itemId = input.dataset.itemId;
                    const statusRadio = form.querySelector(`input[name="status_${itemId}"]:checked`);
                    itemsPayload.push({
                        itemId: itemId,
                        status: statusRadio ? statusRadio.value : 'N/A',
                        value: input.value
                    });
                });
                formData.append('items', JSON.stringify(itemsPayload));

                saveBtn.textContent = 'در حال ذخیره...';
                saveBtn.disabled = true;

                fetch(`${SVG_BASE_PATH}api/save_inspection.php`, {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            alert(data.message);
                            closeForm('gfrcChecklistForm');
                        } else {
                            alert('خطا در ذخیره‌سازی: ' + data.message);
                        }
                    })
                    .catch(err => alert('خطای ارتباطی: ' + err))
                    .finally(() => {
                        saveBtn.textContent = 'ذخیره';
                        saveBtn.disabled = false;
                    });
            });

        });

        function loadAndDisplaySVG(svgFullFilename) {
            const svgContainer = document.getElementById("svgContainer");
            closeAllForms();
            svgContainer.innerHTML = "";
            svgContainer.classList.add("loading");

            const baseFilename = svgFullFilename.substring(svgFullFilename.lastIndexOf("/") + 1);
            const isPlan = baseFilename.toLowerCase() === "plan.svg";
            document.getElementById("regionZoneNavContainer").style.display = isPlan ? "flex" : "none";
            if (isPlan) setupRegionZoneNavigationIfNeeded();

            currentPlanFileName = svgFullFilename;
            let zoneInfo = isPlan ? planNavigationMappings.find(m => m.svgFile && m.svgFile.toLowerCase() === svgFullFilename.toLowerCase()) :
                getRegionAndZoneInfoForFile(svgFullFilename);

            currentPlanZoneName = zoneInfo?.label || baseFilename.replace(/\.svg$/i, "");
            currentPlanDefaultContractor = zoneInfo?.defaultContractor || zoneInfo?.contractor || "پیمانکار عمومی";
            currentPlanDefaultBlock = zoneInfo?.defaultBlock || zoneInfo?.block || "بلوک عمومی";

            const zoneInfoContainer = document.getElementById("currentZoneInfo");
            document.getElementById("zoneNameDisplay").textContent = currentPlanZoneName;
            document.getElementById("zoneContractorDisplay").textContent = currentPlanDefaultContractor;
            document.getElementById("zoneBlockDisplay").textContent = currentPlanDefaultBlock;
            zoneInfoContainer.style.display = "block";

            fetch(svgFullFilename)
                .then(response => {
                    svgContainer.classList.remove("loading");
                    if (!response.ok) throw new Error(`Failed to load ${svgFullFilename}: ${response.status}`);
                    return response.text();
                })
                .then(svgData => {
                    svgContainer.innerHTML = svgData;
                    const zoomControlsHtml = `<div class="zoom-controls"><button id="zoomInBtn">+</button><button id="zoomOutBtn">-</button><button id="zoomResetBtn">⌂</button></div>`;
                    svgContainer.insertAdjacentHTML("afterbegin", zoomControlsHtml);

                    setTimeout(() => {
                        const svgElement = svgContainer.querySelector("svg");
                        if (svgElement) {
                            currentSvgElement = svgElement;
                            resetZoomAndPan();
                            setupZoomControls();
                            currentSvgHeight = svgElement.viewBox.baseVal?.height || svgElement.height.baseVal?.value || 2200;
                            currentSvgWidth = svgElement.viewBox.baseVal?.width || svgElement.width.baseVal?.value || 3000;
                            extractAllAxisMarkers(svgElement);
                            applyGroupStylesAndControls(svgElement);
                            if (isPlan) {
                                /* setupPlanNavigationLinks(svgElement); // This complex logic can be simplified or removed if not essential */
                            }
                        }
                    }, 0);
                })
                .catch(error => {
                    svgContainer.classList.remove("loading");
                    svgContainer.textContent = `خطا در بارگزاری نقشه.`;
                    zoneInfoContainer.style.display = "none";
                    console.error(`Error loading SVG:`, error);
                });
        }
        //</editor-fold>

        //<editor-fold desc="Utility and Math Functions">
        function getRectangleDimensions(dAttribute) {
            if (!dAttribute) return null;
            const commands = dAttribute.trim().toUpperCase().split(/(?=[LMCZHV])/);
            let points = [],
                currentX = 0,
                currentY = 0;
            commands.forEach(commandStr => {
                const type = commandStr.charAt(0);
                const args = (commandStr.substring(1).trim().split(/[\s,]+/).map(Number)) || [];
                let i = 0;
                switch (type) {
                    case "M":
                    case "L":
                        while (i < args.length) {
                            currentX = args[i++];
                            currentY = args[i++];
                            points.push({
                                x: currentX,
                                y: currentY
                            });
                        }
                        break;
                    case "H":
                        while (i < args.length) {
                            currentX = args[i++];
                            points.push({
                                x: currentX,
                                y: currentY
                            });
                        }
                        break;
                    case "V":
                        while (i < args.length) {
                            currentY = args[i++];
                            points.push({
                                x: currentX,
                                y: currentY
                            });
                        }
                        break;
                }
            });
            if (points.length < 3) return null;
            const xValues = points.map(p => p.x),
                yValues = points.map(p => p.y);
            const minX = Math.min(...xValues),
                maxX = Math.max(...xValues);
            const minY = Math.min(...yValues),
                maxY = Math.max(...yValues);
            return {
                x: minX,
                y: minY,
                width: maxX - minX,
                height: maxY - minY
            };
        }

        function extractAllAxisMarkers(svgElement) {
            currentSvgAxisMarkersX = [];
            currentSvgAxisMarkersY = [];
            const allTexts = Array.from(svgElement.querySelectorAll("text"));
            const viewBoxHeight = svgElement.viewBox.baseVal.height;
            const viewBoxWidth = svgElement.viewBox.baseVal.width;
            // More generous thresholds
            const Y_EDGE_THRESHOLD = viewBoxHeight * 0.20;
            const X_EDGE_THRESHOLD = viewBoxWidth * 0.20;

            allTexts.forEach(textEl => {
                try {
                    const content = textEl.textContent.trim();
                    if (!content) return;
                    const bbox = textEl.getBBox();
                    if (bbox.width === 0 || bbox.height === 0) return; // Skip invisible elements

                    // Y-Axis (Floors): Must be text like "+12.25", "1st FLOOR", etc., AND be near the left/right edges.
                    if (content.toLowerCase().includes('floor') || content.match(/^\s*\+\d+\.\d+\s*$/)) {
                        if (bbox.x < X_EDGE_THRESHOLD || (bbox.x + bbox.width) > (viewBoxWidth - X_EDGE_THRESHOLD)) {
                            currentSvgAxisMarkersY.push({
                                text: content,
                                x: bbox.x,
                                y: bbox.y + bbox.height / 2
                            });
                        }
                    }
                    // X-Axis (Grid Letters/Numbers): Must be simple letters or numbers, AND be near the top/bottom edges.
                    else if (content.match(/^[A-Z0-9]{1,3}$/)) {
                        if (bbox.y < Y_EDGE_THRESHOLD || (bbox.y + bbox.height) > (viewBoxHeight - Y_EDGE_THRESHOLD)) {
                            currentSvgAxisMarkersX.push({
                                text: content,
                                x: bbox.x + bbox.width / 2,
                                y: bbox.y
                            });
                        }
                    }
                } catch (e) {
                    /* Ignore errors */
                }
            });

            currentSvgAxisMarkersX.sort((a, b) => a.x - b.x);
            currentSvgAxisMarkersY.sort((a, b) => a.y - b.y);

            // --- DEBUGGING: Uncomment to see what markers are being found ---
            console.log("Found X-Axis Markers:", currentSvgAxisMarkersX.map(m => m.text));
            console.log("Found Y-Axis (Floor) Markers:", currentSvgAxisMarkersY.map(m => m.text));
        }

        function getElementSpatialContext(element) {
            let axisSpan = "N/A",
                floorLevel = "N/A";
            try {
                const elBBox = element.getBBox();
                const elCenterX = elBBox.x + elBBox.width / 2;
                const elCenterY = elBBox.y + elBBox.height / 2;

                // Find nearest X-axis marker to the left and right
                let leftMarker = null,
                    rightMarker = null;
                currentSvgAxisMarkersX.forEach(marker => {
                    if (marker.x <= elCenterX) {
                        leftMarker = marker;
                    }
                    if (marker.x >= elCenterX && !rightMarker) {
                        rightMarker = marker;
                    }
                });
                if (leftMarker && rightMarker) {
                    axisSpan = (leftMarker.text === rightMarker.text) ? leftMarker.text : `${leftMarker.text}-${rightMarker.text}`;
                } else if (leftMarker) {
                    axisSpan = `> ${leftMarker.text}`;
                } else if (rightMarker) {
                    axisSpan = `< ${rightMarker.text}`;
                }

                // Find nearest Y-axis (floor) marker that is BELOW the element's center
                let belowMarker = null;
                currentSvgAxisMarkersY.forEach(marker => {
                    if (marker.y >= elCenterY && (!belowMarker || marker.y < belowMarker.y)) {
                        belowMarker = marker;
                    }
                });
                if (belowMarker) {
                    floorLevel = belowMarker.text;
                }

            } catch (e) {
                /* ignore */
            }
            return {
                axisSpan,
                floorLevel,
                derivedId: null
            };
        }
        //</editor-fold>

        //<editor-fold desc="Zoom and Pan Functions">
        function setupZoomControls() {
            const zoomInBtn = document.getElementById("zoomInBtn");
            const zoomOutBtn = document.getElementById("zoomOutBtn");
            const zoomResetBtn = document.getElementById("zoomResetBtn");
            const svgContainer = document.getElementById("svgContainer");
            zoomInBtn.addEventListener("click", () => zoomSvg(currentZoom + zoomStep));
            zoomOutBtn.addEventListener("click", () => zoomSvg(currentZoom - zoomStep));
            zoomResetBtn.addEventListener("click", resetZoomAndPan);
            svgContainer.addEventListener("wheel", handleWheelZoom, {
                passive: false
            });
            svgContainer.addEventListener("mousedown", handleMouseDown);
            document.addEventListener("mousemove", handleMouseMove);
            document.addEventListener("mouseup", handleMouseUp);
            svgContainer.addEventListener("mouseleave", handleMouseUp);
            svgContainer.addEventListener("touchstart", handleTouchStart, {
                passive: false
            });
            svgContainer.addEventListener("touchmove", handleTouchMove, {
                passive: false
            });
            document.addEventListener("touchend", handleTouchEnd, {
                passive: false
            });
        }

        function handleWheelZoom(e) {
            e.preventDefault();
            const svgContainerRect = e.currentTarget.getBoundingClientRect();
            const svgX = (e.clientX - svgContainerRect.left - panX) / currentZoom;
            const svgY = (e.clientY - svgContainerRect.top - panY) / currentZoom;
            const delta = e.deltaY < 0 ? zoomStep : -zoomStep;
            zoomSvg(currentZoom * (1 + delta), svgX, svgY);
        }

        function handleMouseDown(e) {
            if (e.target.closest(".zoom-controls, .interactive-element")) return;
            isPanning = true;
            panStartX = e.clientX - panX;
            panStartY = e.clientY - panY;
            e.currentTarget.classList.add("dragging");
        }

        function handleMouseMove(e) {
            if (!isPanning) return;
            e.preventDefault();
            panX = e.clientX - panStartX;
            panY = e.clientY - panStartY;
            updateTransform();
        }

        function handleMouseUp(e) {
            if (isPanning) {
                isPanning = false;
                document.getElementById("svgContainer").classList.remove("dragging");
            }
        }

        function handleTouchStart(e) {
            if (e.target.closest(".zoom-controls")) return;
            if (e.touches.length === 1) {
                isPanning = true;
                const touch = e.touches[0];
                panStartX = touch.clientX - panX;
                panStartY = touch.clientY - panY;
            } else if (e.touches.length === 2) {
                isPanning = false;
                const t1 = e.touches[0],
                    t2 = e.touches[1];
                lastTouchDistance = Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY);
            }
        }

        function handleTouchMove(e) {
            e.preventDefault();
            if (e.touches.length === 1 && isPanning) {
                const touch = e.touches[0];
                panX = touch.clientX - panStartX;
                panY = touch.clientY - panStartY;
                updateTransform();
            } else if (e.touches.length === 2) {
                const t1 = e.touches[0],
                    t2 = e.touches[1];
                const dist = Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY);
                if (lastTouchDistance > 0) {
                    const scale = dist / lastTouchDistance;
                    const midX = (t1.clientX + t2.clientX) / 2;
                    const midY = (t1.clientY + t2.clientY) / 2;
                    const rect = e.currentTarget.getBoundingClientRect();
                    zoomSvg(currentZoom * scale, (midX - rect.left - panX) / currentZoom, (midY - rect.top - panY) / currentZoom);
                }
                lastTouchDistance = dist;
            }
        }

        function handleTouchEnd(e) {
            isPanning = false;
            if (e.touches.length < 2) lastTouchDistance = 0;
        }

        function addTouchClickSupport(element, clickHandler) {
            let touchStartTime, touchMoved;
            element.addEventListener("touchstart", e => {
                e.stopPropagation();
                touchMoved = false;
                touchStartTime = Date.now();
            }, {
                passive: true
            });
            element.addEventListener("touchmove", () => {
                touchMoved = true;
            }, {
                passive: true
            });
            element.addEventListener("touchend", e => {
                if (!touchMoved && (Date.now() - touchStartTime < 300)) {
                    e.preventDefault();
                    clickHandler(e);
                }
            }, {
                passive: false
            });
        }

        function zoomSvg(newZoomFactor, pivotX, pivotY) {
            if (!currentSvgElement) return;
            const svgContainerRect = document.getElementById("svgContainer").getBoundingClientRect();
            pivotX = pivotX ?? svgContainerRect.width / 2;
            pivotY = pivotY ?? svgContainerRect.height / 2;
            const newZoom = Math.max(minZoom, Math.min(maxZoom, newZoomFactor));
            panX -= pivotX * (newZoom - currentZoom);
            panY -= pivotY * (newZoom - currentZoom);
            currentZoom = newZoom;
            updateTransform();
            updateZoomButtonStates();
        }

        function resetZoomAndPan() {
            currentZoom = 1;
            panX = 0;
            panY = 0;
            updateTransform();
            updateZoomButtonStates();
        }

        function updateTransform() {
            if (currentSvgElement) {
                currentSvgElement.style.transform = `translate(${panX}px, ${panY}px) scale(${currentZoom})`;
                currentSvgElement.style.transformOrigin = `0 0`;
            }
        }

        function updateZoomButtonStates() {
            const zoomInBtn = document.getElementById("zoomInBtn");
            const zoomOutBtn = document.getElementById("zoomOutBtn");
            if (zoomInBtn && zoomOutBtn) {
                zoomInBtn.disabled = currentZoom >= maxZoom;
                zoomOutBtn.disabled = currentZoom <= minZoom;
            }
        }
        //</editor-fold>
    </script>
</body>

</html>