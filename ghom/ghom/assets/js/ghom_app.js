///ghom/assets/js/ghom_app.js
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

let currentSvgHeight = 2200,
  currentSvgWidth = 3000;
let visibleStatuses = {
  OK: true,
  "Not OK": true,
  "Ready for Inspection": true,
  Pending: true,
};
let currentlyActiveSvgElement = null;
const SVG_BASE_PATH = "/ghom/"; // Use root-relative path
const STATUS_COLORS = {
  OK: "rgba(40, 167, 69, 0.7)", // Bright Green
  "Not OK": "rgba(220, 53, 69, 0.7)", // Strong Red
  "Ready for Inspection": "rgba(255, 140, 0, 0.8)", // Distinct Orange
  Pending: "rgba(108, 117, 125, 0.4)", // Neutral Grey (unchanged)
};
let currentPlanDbData = {};
const svgGroupConfig = {
  GFRC: {
    label: "GFRC",
    colors: {
      v: "rgba(13, 110, 253, 0.7)", // A clear, standard Blue
      h: "rgba(0, 150, 136, 0.75)", // A contrasting Teal/Cyan
    },
    defaultVisible: true,
    interactive: true,
    elementType: "GFRC",
  },
  Box_40x80x4: {
    label: "Box_40x80x4",
    defaultVisible: true,
    interactive: true,
    elementType: "Zirsazi",
  },
  Box_40x20: {
    label: "Box_40x20",
    defaultVisible: true,
    interactive: true,
    elementType: "Zirsazi",
  },
  tasme: {
    label: "تسمه",
    defaultVisible: true,
    interactive: true,
    elementType: "Zirsazi",
  },
  nabshi_tooli: {
    label: "نبشی طولی",
    defaultVisible: true,
    interactive: true,
    elementType: "Zirsazi",
  },
  Gasket: {
    label: "Gasket",
    defaultVisible: true,
    interactive: true,
    elementType: "Zirsazi",
  },
  SPACER: {
    label: "فاصله گذار",
    defaultVisible: true,
    interactive: true,
    elementType: "Zirsazi",
  },
  Smoke_Barrier: {
    label: "دودبند",
    defaultVisible: true,
    interactive: true,
    elementType: "Zirsazi",
  },
  uchanel: {
    label: "یو چنل",
    defaultVisible: true,
    interactive: true,
    elementType: "Zirsazi",
  },
  unolite: {
    label: "یونولیت",
    defaultVisible: true,
    interactive: true,
    elementType: "Zirsazi",
  },
  "GFRC-Part6": {
    label: "GFRC - قسمت 6",
    defaultVisible: true,
    interactive: true,
    elementType: "GFRC",
  },
  "GFRC-Part_4": {
    label: "GFRC - قسمت 4",
    defaultVisible: true,
    interactive: true,
    elementType: "GFRC",
  },
  Atieh: {
    label: "بلوک A- آتیه نما",
    color: "#0de16d",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت آتیه نما",
    block: "A",
    elementType: "Region",
    contractor_id: "cat",
  },
  org: {
    label: "بلوک - اورژانس A- آتیه نما",
    color: "#ebb00d",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت آتیه نما",
    block: "A - اورژانس",
    elementType: "Region",
    contractor_id: "cat",
  },
  AranB: {
    label: "بلوک B-آرانسج",
    color: "#38abee",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت آرانسج",
    block: "B",
    elementType: "Region",
    contractor_id: "car",
  },
  AranC: {
    label: "بلوک C-آرانسج",
    color: "#ee3838",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت آرانسج",
    block: "C",
    elementType: "Region",
    contractor_id: "car",
  },
  hayatOmran: {
    label: " حیاط عمران آذرستان",
    color: "#eef595da",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت عمران آذرستان",
    block: "حیاط",
    elementType: "Region",
    contractor_id: "coa",
  },
  hayatRos: {
    label: " حیاط رس",
    color: "#eb0de7da",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت ساختمانی رس",
    block: "حیاط",
    elementType: "Region",
    contractor_id: "crs",
  },
  handrail: {
    label: "نقشه ندارد",
    color: "rgba(238, 56, 56, 0.3)",
    defaultVisible: true,
    interactive: true,
  },
  "glass_40%": {
    label: "شیشه 40%",
    color: "rgba(173, 216, 230, 0.7)",
    defaultVisible: true,
    interactive: true,
    elementType: "Glass",
  },
  "glass_30%": {
    label: "شیشه 30%",
    color: "rgba(173, 216, 230, 0.7)",
    defaultVisible: true,
    interactive: true,
    elementType: "Glass",
  },
  "glass_50%": {
    label: "شیشه 50%",
    color: "rgba(173, 216, 230, 0.7)",
    defaultVisible: true,
    interactive: true,
    elementType: "Glass",
  },
  glass_opaque: {
    label: "شیشه مات",
    color: "rgba(144, 238, 144, 0.7)",
    defaultVisible: true,
    interactive: true,
    elementType: "Glass",
  },
  "glass_80%": {
    label: "شیشه 80%",
    color: "rgba(255, 255, 102, 0.7)",
    defaultVisible: true,
    interactive: true,
    elementType: "Glass",
  },
  Mullion: {
    label: "مولیون",
    color: "rgba(128, 128, 128, 0.9)",
    defaultVisible: true,
    interactive: true,
    elementType: "Mullion",
  },
  Transom: {
    label: "ترنزوم",
    color: "rgba(169, 169, 169, 0.9)",
    defaultVisible: true,
    interactive: true,
    elementType: "Transom",
  },
  Bazshow: {
    label: "بازشو",
    color: "rgba(169, 169, 169, 0.9)",
    defaultVisible: true,
    interactive: true,
    elementType: "Bazshow",
  },
  GLASS: {
    label: "شیشه",
    color: "#eef595da",
    defaultVisible: true,
    interactive: true,
    elementType: "Glass",
  },
  STONE: {
    label: "سنگ",
    color: "#4c28a1",
    defaultVisible: true,
    interactive: true,
    elementType: "STONE",
  },
  Zirsazi: {
    label: "زیرسازی",
    color: "#2464ee",
    defaultVisible: true,
    interactive: true,
    elementType: "Zirsazi",
  },
  Curtainwall: {
    label: "کرتین وال",
    color: "#4c28a1",
    defaultVisible: true,
    interactive: true,
    elementType: "Curtainwall",
  },
};

const regionToZoneMap = {
  Atieh: [
    {
      label: "زون 1 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone01.svg",
    },
    {
      label: "زون 2 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone02.svg",
    },
    {
      label: "زون 3 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone03.svg",
    },
    {
      label: "زون 4 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone04.svg",
    },
    {
      label: "زون 5 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone05.svg",
    },
    {
      label: "زون 6 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone06.svg",
    },
    {
      label: "زون 7 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone07.svg",
    },
    {
      label: "زون 8 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone08.svg",
    },
    {
      label: "زون 9 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone09.svg",
    },
    {
      label: "زون 10 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone10.svg",
    },
    {
      label: "زون 15 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone15.svg",
    },
    {
      label: "زون 16 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone16.svg",
    },
    {
      label: "زون 17 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone17.svg",
    },
    {
      label: "زون 18 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone18.svg",
    },
    {
      label: "زون 19 (آتیه نما)",
      svgFile: SVG_BASE_PATH + "Zone19.svg",
    },
  ],
  org: [
    {
      label: "زون اورژانس ",
      svgFile: SVG_BASE_PATH + "ZoneEmergency.svg",
    },
  ],
  AranB: [
    {
      label: "زون 1 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone01.svg",
    },
    {
      label: "زون 2 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone02.svg",
    },
    {
      label: "زون 3 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone03.svg",
    },
    {
      label: "زون 11 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone11.svg",
    },
    {
      label: "زون 12 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone12.svg",
    },
    {
      label: "زون 13 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone13.svg",
    },
    {
      label: "زون 14 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone14.svg",
    },
    {
      label: "زون 16 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone16.svg",
    },
    {
      label: "زون 19 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone19.svg",
    },
    {
      label: "زون 20 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone20.svg",
    },
    {
      label: "زون 21 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone21.svg",
    },
    {
      label: "زون 26 (آرانسج B)",
      svgFile: SVG_BASE_PATH + "Zone26.svg",
    },
  ],
  AranC: [
    {
      label: "زون 4 (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone04.svg",
    },
    {
      label: "زون 5 (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone05.svg",
    },
    {
      label: "زون 6 (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone06.svg",
    },
    {
      label: "زون 7E (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone07E.svg",
    },
    {
      label: "زون 7S (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone07S.svg",
    },
    {
      label: "زون 7N (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone07N.svg",
    },
    {
      label: "زون 8 (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone08.svg",
    },
    {
      label: "زون 9 (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone09.svg",
    },
    {
      label: "زون 10 (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone10.svg",
    },
    {
      label: "زون 22 (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone22.svg",
    },
    {
      label: "زون 23 (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone23.svg",
    },
    {
      label: "زون 24 (آرانسج C)",
      svgFile: SVG_BASE_PATH + "Zone24.svg",
    },
  ],
  hayatOmran: [
    {
      label: "زون 15 حیاط عمران آذرستان",
      svgFile: SVG_BASE_PATH + "Zone15.svg",
    },
    {
      label: "زون 16 حیاط عمران آذرستان",
      svgFile: SVG_BASE_PATH + "Zone16.svg",
    },
    {
      label: "زون 17 حیاط عمران آذرستان",
      svgFile: SVG_BASE_PATH + "Zone17.svg",
    },
    {
      label: "زون 18 حیاط عمران آذرستان",
      svgFile: SVG_BASE_PATH + "Zone18.svg",
    },
  ],
  hayatRos: [
    {
      label: "زون 11 حیاط رس ",
      svgFile: SVG_BASE_PATH + "Zone11.svg",
    },
    {
      label: "زون 12 حیاط رس",
      svgFile: SVG_BASE_PATH + "Zone12.svg",
    },
    {
      label: "زون 13 حیاط رس",
      svgFile: SVG_BASE_PATH + "Zone13.svg",
    },
    {
      label: "زون 14 حیاط رس",
      svgFile: SVG_BASE_PATH + "Zone14.svg",
    },
  ],
};

const planNavigationMappings = [
  {
    type: "textAndCircle",
    regex: /^(\d+|[A-Za-z]+[\d-]*)\s+Zone$/i,
    numberGroupIndex: 1,
    svgFilePattern: SVG_BASE_PATH + "Zone{NUMBER}.svg",
    labelPattern: "Zone {NUMBER}",
    defaultContractor: "پیمانکار پیش‌فرض زون عمومی",
    defaultBlock: "بلوک پیش‌فرض زون عمومی",
  },
  {
    svgFile: SVG_BASE_PATH + "Zone09.svg",
    label: "Zone 09",
    defaultContractor: "شرکت آتیه نما زون 09 ",
    defaultBlock: "بلوکA  زون 9 ",
  },
  {
    svgFile: SVG_BASE_PATH + "Plan.svg",
    label: "Plan اصلی",
    defaultContractor: "مدیر پیمان ",
    defaultBlock: "پروژه بیمارستان قم ",
  },
];
// Add this new constant to ghom_app.js
const planroles = {
  Atieh: {
    label: "بلوک A- آتیه نما",
    color: "#0de16d",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت آتیه نما",
    block: "A",
    elementType: "Region",
    contractor_id: "cat",
  },
  org: {
    label: "بلوک - اورژانس A- آتیه نما",
    color: "#ebb00d",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت آتیه نما",
    block: "A - اورژانس",
    elementType: "Region",
    contractor_id: "cat",
  },
  AranB: {
    label: "بلوک B-آرانسج",
    color: "#38abee",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت آرانسج",
    block: "B",
    elementType: "Region",
    contractor_id: "car",
  },
  AranC: {
    label: "بلوک C-آرانسج",
    color: "#ee3838",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت آرانسج",
    block: "C",
    elementType: "Region",
    contractor_id: "car",
  },
  hayatOmran: {
    label: " حیاط عمران آذرستان",
    color: "#eef595da",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت عمران آذرستان",
    block: "حیاط",
    elementType: "Region",
    contractor_id: "coa",
  },
  hayatRos: {
    label: " حیاط رس",
    color: "#eb0de7da",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت ساختمانی رس",
    block: "حیاط",
    elementType: "Region",
    contractor_id: "crs",
  },
};
//</editor-fold>
function showZoneSelectionMenu(regionKey, event) {
  closeAllForms();
  const zones = regionToZoneMap[regionKey];

  if (!zones || zones.length === 0) {
    console.warn(`No zones found for region key: ${regionKey}`);
    return;
  }

  const menu = document.createElement("div");
  menu.id = "zoneSelectionMenu";

  const regionConfig = svgGroupConfig[regionKey];
  const regionLabel = regionConfig ? regionConfig.label : "انتخاب زون";
  const title = document.createElement("h4");
  title.className = "zone-menu-title";
  title.textContent = `زون‌های موجود برای: ${regionLabel}`;
  menu.appendChild(title);

  const buttonGrid = document.createElement("div");
  buttonGrid.className = "zone-menu-grid";

  zones.forEach((zone) => {
    const menuItem = document.createElement("button");
    menuItem.textContent = zone.label;
    menuItem.onclick = (e) => {
      e.stopPropagation();
      loadAndDisplaySVG(zone.svgFile);
      closeZoneSelectionMenu();
    };
    buttonGrid.appendChild(menuItem);
  });
  menu.appendChild(buttonGrid);

  const closeButton = document.createElement("button");
  closeButton.textContent = "بستن منو";
  closeButton.className = "close-menu-btn";
  closeButton.onclick = (e) => {
    e.stopPropagation();
    closeZoneSelectionMenu();
  };
  menu.appendChild(closeButton);

  document.body.appendChild(menu);

  // --- NEW POSITIONING LOGIC ---
  // Position the menu based on the actual mouse click coordinates.
  let menuLeft = event.pageX;
  let menuTop = event.pageY + 10; // 10px offset from cursor

  menu.style.top = `${menuTop}px`;
  menu.style.left = `${menuLeft}px`;

  // This ensures the menu doesn't render off-screen.
  const menuRect = menu.getBoundingClientRect();
  const viewportWidth = window.innerWidth;

  if (menuLeft + menuRect.width > viewportWidth) {
    menu.style.left = `${viewportWidth - menuRect.width - 20}px`; // Move it left
  }
  // --- END NEW LOGIC ---

  setTimeout(
    () =>
      document.addEventListener("click", closeZoneMenuOnClickOutside, {
        once: true,
      }),
    0
  );
}
/**
 * Updates the information bar at the top with the current plan's details.
 * @param {string} zoneName - The name of the current zone/plan.
 * @param {string} contractor - The contractor for the zone.
 * @param {string} block - The block for the zone.
 */
function updateCurrentZoneInfo(zoneName, contractor, block) {
  const infoContainer = document.getElementById("currentZoneInfo");
  const zoneNameDisplay = document.getElementById("zoneNameDisplay");
  const contractorDisplay = document.getElementById("zoneContractorDisplay");
  const blockDisplay = document.getElementById("zoneBlockDisplay");

  // If we don't have a zone name (i.e., we are on the main plan), hide the bar.
  if (!zoneName) {
    infoContainer.style.display = "none";
    return;
  }

  // Otherwise, populate the fields and show the bar.
  zoneNameDisplay.textContent = zoneName || "نامشخص";
  contractorDisplay.textContent = contractor || "نامشخص";
  blockDisplay.textContent = block || "نامشخص";
  infoContainer.style.display = "block"; // Make it visible
}
// NEW FUNCTION: Removes the zone selection menu from the page.
function closeZoneSelectionMenu() {
  const menu = document.getElementById("zoneSelectionMenu");
  if (menu) menu.remove();
  document.removeEventListener("click", closeZoneMenuOnClickOutside);
}

// NEW FUNCTION: Handles clicks outside the zone menu to close it.
function closeZoneMenuOnClickOutside(event) {
  const menu = document.getElementById("zoneSelectionMenu");
  if (menu && !menu.contains(event.target)) {
    closeZoneSelectionMenu();
  }
}

function clearActiveSvgElementHighlight() {
  if (currentlyActiveSvgElement) {
    currentlyActiveSvgElement.classList.remove("svg-element-active");
    currentlyActiveSvgElement = null;
  }
}

function closeAllForms() {
  document
    .querySelectorAll(".form-popup")
    .forEach((form) => (form.style.display = "none"));
  closeGfrcSubPanelMenu();
  closeZoneSelectionMenu(); // ADD THIS LINE
  clearActiveSvgElementHighlight();
}

function closeForm(formId) {
  const formPopup = document.getElementById(formId);
  if (formPopup) {
    formPopup.classList.remove("show");
    formPopup.style.display = "none";

    // Reset any transform applied by interact.js
    formPopup.style.transform = "";
    formPopup.removeAttribute("data-x");
    formPopup.removeAttribute("data-y");

    // Reset size if it was resized
    formPopup.style.width = "";
    formPopup.style.height = "";
  }
}

function showGfrcSubPanelMenu(clickedElement, dynamicContext) {
  closeGfrcSubPanelMenu();
  const orientation = clickedElement.dataset.panelOrientation;
  let subPanelIds = [];

  // Determine which set of sub-panels to show based on orientation
  if (orientation === "عمودی") {
    subPanelIds = ["face", "left", "right"];
  } else if (orientation === "افقی") {
    subPanelIds = ["face", "up", "down"];
  } else {
    // Fallback for unknown orientation - opens the form for the "Default" part.
    openChecklistForm(
      `${clickedElement.dataset.uniquePanelId}-Default`,
      dynamicContext.elementType,
      dynamicContext
    );
    return;
  }

  const menu = document.createElement("div");
  menu.id = "gfrcSubPanelMenu";

  subPanelIds.forEach((partName) => {
    const menuItem = document.createElement("button");
    // Create the full ID, e.g., "FF-01-face"
    const fullElementId = `${clickedElement.dataset.uniquePanelId}-${partName}`;
    menuItem.textContent = `چک لیست: ${partName}`;

    // --- THIS IS THE CRITICAL CHANGE ---
    // The onclick handler now calls your new, powerful form function.
    menuItem.onclick = (e) => {
      e.stopPropagation();
      openChecklistForm(
        fullElementId,
        dynamicContext.elementType,
        dynamicContext
      );
      closeGfrcSubPanelMenu();
    };
    // --- END OF CHANGE ---

    menu.appendChild(menuItem);
  });

  // Add a close button to the menu
  const closeButton = document.createElement("button");
  closeButton.textContent = "بستن منو";
  closeButton.className = "close-menu-btn";
  closeButton.onclick = (e) => {
    e.stopPropagation();
    closeGfrcSubPanelMenu();
  };
  menu.appendChild(closeButton);

  document.body.appendChild(menu);
  const rect = clickedElement.getBoundingClientRect();
  menu.style.top = `${rect.bottom + window.scrollY}px`;
  menu.style.left = `${rect.left + window.scrollX}px`;

  setTimeout(
    () =>
      document.addEventListener("click", closeGfrcMenuOnClickOutside, {
        once: true,
      }),
    0
  );
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
  if (typeof unsafe !== "string") return "";
  return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}
/**
 * Saves a key-value pair to sessionStorage, converting value to JSON string.
 * @param {string} key The key to save under.
 * @param {*} value The value to save (will be stringified).
 */
function saveStateToSession(key, value) {
  try {
    sessionStorage.setItem(key, JSON.stringify(value));
  } catch (e) {
    console.error("Failed to save state to session storage:", e);
  }
}

/**
 * Loads and parses a JSON value from sessionStorage.
 * @param {string} key The key to load.
 * @returns {*} The parsed value or null if not found or invalid.
 */
function loadStateFromSession(key) {
  try {
    const value = sessionStorage.getItem(key);
    return value ? JSON.parse(value) : null;
  } catch (e) {
    console.error("Failed to load state from session storage:", e);
    return null;
  }
}

/**
 * Clears all relevant application state from sessionStorage.
 */
function clearSessionState() {
  sessionStorage.removeItem("lastViewedPlan");
  sessionStorage.removeItem("layerVisibility");
  sessionStorage.removeItem("statusVisibility");
}

/**
 * This is the NEW, database-driven function to open the GFRC checklist.
 * It completely replaces the old one.
 */
function setText(id, text) {
  const el = document.getElementById(id);
  if (el) {
    el.textContent = text || ""; // Use empty string as a fallback for null/undefined
  } else {
    console.error(
      `JavaScript Error: HTML element with ID '${id}' was not found.`
    );
  }
}

/**
 * Opens a dynamic, stage-based checklist form with a professional tabbed UI.
 * This is the definitive version for creating the inspection form.
 */
function openChecklistForm(fullElementId, elementType, dynamicContext) {
  const formPopup = document.getElementById("universalChecklistForm");
  const formContainer = document.getElementById("universal-form-element");
  if (!formPopup || !formContainer) return;

  // Show loading state
  formContainer.innerHTML = `<div class="form-loader"><h3>در حال بارگذاری فرم...</h3></div>`;

  // Use proper display method
  formPopup.classList.add("show");
  formPopup.style.display = "block";

  const apiParams = new URLSearchParams({
    element_id: fullElementId,
    element_type: elementType,
  });

  fetch(`/ghom/api/get_element_data.php?${apiParams.toString()}`)
    .then((res) => res.json())
    .then((data) => {
      if (data.error) throw new Error(data.error);

      // --- 1. BUILD THE NEW HEADER ---
      const headerHTML = `
        <div class="form-header-new">
            <h3>چک لیست: ${escapeHtml(elementType)}</h3>
            <p>${escapeHtml(fullElementId)}</p>
            <div class="form-meta">
                <span><strong>موقعیت:</strong> ${escapeHtml(
                  dynamicContext.block
                )}, ${escapeHtml(dynamicContext.zoneName)}</span>
                <span><strong>طبقه:</strong> ${escapeHtml(
                  dynamicContext.floorLevel
                )}</span>
                <span><strong>محور:</strong> ${escapeHtml(
                  dynamicContext.axisSpan
                )}</span>
            </div>
            <div class="form-meta" style="margin-top: 5px;">
              <span><strong>ابعاد:</strong> <span class="ltr-text">${
                dynamicContext.widthCm
              } x ${dynamicContext.heightCm} cm</span></span>
              <span><strong>مساحت:</strong> <span class="ltr-text">${
                dynamicContext.areaSqm
              } m²</span></span>
            </div>
        </div>`;

      let formBodyHTML =
        '<div class="no-stages-message">مراحل بازرسی برای این المان تعریف نشده است.</div>';

      // --- 2. BUILD THE TABS AND CONTENT ---
      if (data.template && data.template.length > 0) {
        const tabButtonsHTML = data.template
          .map(
            (stageData, index) =>
              `<button type="button" class="stage-tab-button ${
                index === 0 ? "active" : ""
              }" data-tab="stage-content-${stageData.stage_id}">
                  ${escapeHtml(stageData.stage_name)}
              </button>`
          )
          .join("");

        const tabContentsHTML = data.template
          .map((stageData, index) => {
            // Find the most recent history record for this specific stage
            const stageHistory =
              data.history.find((h) => h.stage_id === stageData.stage_id) || {};

            const itemsHTML = stageData.items
              .map((item) => {
                const itemHistory =
                  stageHistory.items?.find((i) => i.item_id === item.item_id) ||
                  {};
                return `
                   <div class="item-row">
                       <label class="item-text">${escapeHtml(
                         item.item_text
                       )}</label>
                       <div class="item-controls">
                           <div class="status-selector-new">
                               <input type="radio" id="status_ok_${
                                 stageData.stage_id
                               }_${item.item_id}" name="status_${
                  stageData.stage_id
                }_${item.item_id}" value="OK" ${
                  itemHistory.item_status === "OK" ? "checked" : ""
                }>
                               <label for="status_ok_${stageData.stage_id}_${
                  item.item_id
                }" class="status-icon ok" title="OK">✓</label>
                               
                               <input type="radio" id="status_nok_${
                                 stageData.stage_id
                               }_${item.item_id}" name="status_${
                  stageData.stage_id
                }_${item.item_id}" value="Not OK" ${
                  itemHistory.item_status === "Not OK" ? "checked" : ""
                }>
                               <label for="status_nok_${stageData.stage_id}_${
                  item.item_id
                }" class="status-icon nok" title="Not OK">✗</label>
                           </div>
                           <input type="text" class="checklist-input" data-item-id="${
                             item.item_id
                           }" value="${escapeHtml(
                  itemHistory.item_value || ""
                )}" placeholder="توضیحات...">
                       </div>
                   </div>`;
              })
              .join("");

            return `
              <div id="stage-content-${
                stageData.stage_id
              }" class="stage-tab-content ${index === 0 ? "active" : ""}">
                  <fieldset class="stage-container" data-stage-id="${
                    stageData.stage_id
                  }">
                      <div class="stage-body">
                          <div class="checklist-items">${itemsHTML}</div>
                          <div class="stage-sections">
                              <fieldset class="consultant-section">
                                  <legend>بخش مشاور</legend>
                                  <div class="form-group">
                                      <label>وضعیت کلی:</label>
                                      <select name="overall_status">
                                          <option value="Pending" ${
                                            !stageHistory.overall_status ||
                                            stageHistory.overall_status ===
                                              "Pending"
                                              ? "selected"
                                              : ""
                                          }>در انتظار</option>
                                          <option value="OK" ${
                                            stageHistory.overall_status === "OK"
                                              ? "selected"
                                              : ""
                                          }>تایید</option>
                                          <option value="Not OK" ${
                                            stageHistory.overall_status ===
                                            "Not OK"
                                              ? "selected"
                                              : ""
                                          }>رد</option>
                                      </select>
                                  </div>
                                  <div class="form-group">
                                      <label>تاریخ بازرسی:</label>
                                      <input type="text" name="inspection_date" value="${
                                        stageHistory.inspection_date_jalali ||
                                        ""
                                      }" data-jdp readonly>
                                  </div>
                              </fieldset>
                              <fieldset class="contractor-section">
                                  <legend>بخش پیمانکار</legend>
                                  <div class="form-group">
                                      <label>وضعیت:</label>
                                      <select name="contractor_status">
                                          <option value="Pending" ${
                                            !stageHistory.contractor_status ||
                                            stageHistory.contractor_status ===
                                              "Pending"
                                              ? "selected"
                                              : ""
                                          }>در حال اجرا</option>
                                          <option value="Ready for Inspection" ${
                                            stageHistory.contractor_status ===
                                            "Ready for Inspection"
                                              ? "selected"
                                              : ""
                                          }>آماده</option>
                                      </select>
                                  </div>
                                  <div class="form-group">
                                      <label>تاریخ اعلام:</label>
                                      <input type="text" name="contractor_date" value="${
                                        stageHistory.contractor_date_jalali ||
                                        ""
                                      }" data-jdp readonly>
                                  </div>
                              </fieldset>
                          </div>
                      </div>
                  </fieldset>
              </div>`;
          })
          .join("");

        formBodyHTML = `
            <div class="stage-tabs-container">${tabButtonsHTML}</div>
            <div class="stage-content-container">${tabContentsHTML}</div>
        `;
      }

      // --- 3. ASSEMBLE THE FINAL FORM ---
      formContainer.innerHTML = `
          ${headerHTML}
          <form id="checklist-form" class="form-body-new" novalidate>
              <input type="hidden" name="elementId" value="${fullElementId}">
              ${formBodyHTML}
          </form>
          <div class="form-footer-new">
              <button type="button" class="btn cancel" onclick="closeForm('universalChecklistForm')">بستن</button>
              <button type="submit" form="checklist-form" class="btn save">ذخیره تغییرات</button>
              <div class="resize-handle"></div>
          </div>
      `;

      // --- 4. ADD EVENT LISTENERS AND INITIALIZE ---
      setFormState(formContainer, USER_ROLE, data.history);

      // Initialize date picker
      if (typeof jalaliDatepicker !== "undefined") {
        jalaliDatepicker.startWatch({
          selector: "#universalChecklistForm [data-jdp]",
        });
      }

      // Add tab switching functionality
      formContainer.querySelectorAll(".stage-tab-button").forEach((button) => {
        button.addEventListener("click", () => {
          formContainer
            .querySelectorAll(".stage-tab-button, .stage-tab-content")
            .forEach((el) => el.classList.remove("active"));
          button.classList.add("active");
          formContainer
            .querySelector(`#${button.dataset.tab}`)
            .classList.add("active");
        });
      });
    })
    .catch((err) => {
      formContainer.innerHTML = `
        <div class="form-loader">
          <h3>خطا</h3>
          <p>${escapeHtml(err.message)}</p>
          <button type="button" class="btn cancel" onclick="closeForm('universalChecklistForm')">بستن</button>
        </div>`;
    });
}
/**
 * Enforces the inspection workflow by enabling/disabling form sections
 * based on the element's current status and the user's role.
 * @param {HTMLElement} formElement The form container element.
 * @param {string} role The role of the current user.
 */
function setFormState(formContainer, userRole, history) {
  const isSuperuser = userRole === "superuser";
  const isConsultant = userRole === "admin";
  const isContractor = ["cat", "car", "coa", "crs"].includes(userRole);

  formContainer.querySelectorAll(".stage-container").forEach((stageEl) => {
    const stageId = stageEl.dataset.stageId;
    const stageHistory = history.find((h) => h.stage_id == stageId) || {};

    const consultantSection = stageEl.querySelector(".consultant-section");
    const contractorSection = stageEl.querySelector(".contractor-section");

    if (!consultantSection || !contractorSection) return;

    // Default to both sections being disabled
    consultantSection.disabled = true;
    contractorSection.disabled = true;

    // Apply permissions based on role and workflow rules
    if (isSuperuser) {
      consultantSection.disabled = false;
      contractorSection.disabled = false;
    } else if (isConsultant) {
      consultantSection.disabled = false;
    } else if (isContractor) {
      if (
        stageHistory.overall_status !== "OK" &&
        stageHistory.contractor_status !== "Ready for Inspection"
      ) {
        const rejectionCount = history.filter(
          (h) => h.stage_id == stageId && h.overall_status === "Not OK"
        ).length;
        if (rejectionCount < 3) {
          contractorSection.disabled = false;
        } else {
          const legend = contractorSection.querySelector("legend");
          if (legend) legend.textContent += " (3 بار رد شده - قفل)";
        }
      }
    }
  });

  // Control the main save button visibility
  const saveButton = formContainer.querySelector(".btn.save");
  if (saveButton) {
    saveButton.style.display =
      isSuperuser || isConsultant || isContractor ? "block" : "none";
  }
}

//<editor-fold desc="SVG Initialization and Interaction">
/**
 * Makes an SVG element interactive by adding the correct click handler.
 * This version uses the dedicated 'planroles' constant for permission checks.
 * @param {SVGElement} element The SVG element to make interactive.
 * @param {string} groupId The ID of the parent group (e.g., 'Atieh', 'GFRC').
 * @param {string} elementId The specific ID of the element.
 * @param {boolean} isPlan A flag indicating if the current view is the main plan.
 */
function makeElementInteractive(element, groupId, elementId, isPlan) {
  element.classList.add("interactive-element");

  const clickHandler = (event) => {
    event.stopPropagation();

    if (isPlan) {
      // --- THIS IS THE UPDATED ACCESS CONTROL LOGIC ---
      const userRole = document.body.dataset.userRole;
      const isAdmin = userRole === "admin" || userRole === "superuser";

      // 1. Look for the region in your new 'planroles' constant.
      const regionRoleConfig = planroles[element.dataset.regionKey];

      // 2. If there is no config for this region, do nothing.
      if (!regionRoleConfig) {
        console.warn(
          `Region "${element.dataset.regionKey}" not found in 'planroles' config. Click ignored.`
        );
        return;
      }

      // 3. Perform the permission check using the contractor_id from 'planroles'.
      const hasPermission =
        isAdmin ||
        (regionRoleConfig.contractor_id &&
          userRole &&
          regionRoleConfig.contractor_id.trim() === userRole.trim());

      if (hasPermission) {
        // If they have permission, show the menu.
        showZoneSelectionMenu(element.dataset.regionKey, element);
      } else {
        // If not, do nothing.
        console.log(
          `Access Denied: User role '[${userRole}]' cannot access region '[${element.dataset.regionKey}]' which requires '[${regionRoleConfig.contractor_id}]'.`
        );
      }
    } else {
      // Logic for zone plans (opening forms) remains unchanged.
      closeAllForms();
      currentlyActiveSvgElement = element;
      element.classList.add("svg-element-active");

      const dynamicContext = {
        elementType: element.dataset.elementType,
        planFile: currentPlanFileName,
        block: element.dataset.block,
        zoneName: element.dataset.zoneName,
        floorLevel: element.dataset.floorLevel,
        axisSpan: element.dataset.axisSpan,
        widthCm: element.dataset.widthCm,
        heightCm: element.dataset.heightCm,
        areaSqm: element.dataset.areaSqm,
      };

      if (element.dataset.elementType === "GFRC") {
        // If the element is GFRC, show the specific part-selection menu first.
        showGfrcSubPanelMenu(element, dynamicContext);
      } else {
        // For all other types (Mullion, Glass, etc.), open the form directly.
        openChecklistForm(
          elementId,
          element.dataset.elementType,
          dynamicContext
        );
      }
    }
  };

  element.addEventListener("click", clickHandler);
}

function initializeElementsByType(groupElement, elementType, groupId) {
  const isPlan = currentPlanFileName.toLowerCase() === "plan.svg";
  const elements = groupElement.querySelectorAll("path, rect, circle, polygon");

  elements.forEach((el, index) => {
    if (isPlan) {
      el.dataset.regionKey = groupId;
      makeElementInteractive(el, groupId, el.id || `${groupId}_${index}`, true);
    } else {
      if (!el.id) return;
      const dbData = currentPlanDbData[el.id];
      if (dbData) {
        // --- THIS IS THE CRITICAL FIX ---
        // All data from the database is now correctly attached to the SVG element.
        el.dataset.uniquePanelId = el.id;
        el.dataset.elementType = dbData.type;
        el.dataset.axisSpan = dbData.axis;
        el.dataset.floorLevel = dbData.floor;
        el.dataset.widthCm = dbData.width;
        el.dataset.heightCm = dbData.height;
        el.dataset.areaSqm = dbData.area;
        el.dataset.status = dbData.status;
        el.dataset.block = dbData.block; // Fixed
        el.dataset.contractor = dbData.contractor; // Fixed
        el.dataset.zoneName = dbData.zoneName; // Fixed

        if (elementType === "GFRC" && dbData.width && dbData.height) {
          el.dataset.panelOrientation =
            parseFloat(dbData.width) > parseFloat(dbData.height) * 1.5
              ? "افقی"
              : "عمودی";
        }
        makeElementInteractive(el, groupId, el.id, false);
      }
    }
  });
}

function applyGroupStylesAndControls(svgElement) {
  const isPlan = currentPlanFileName.toLowerCase() === "plan.svg";
  const layerControlsContainer = document.getElementById(
    "layerControlsContainer"
  );
  layerControlsContainer.innerHTML = "";

  for (const groupId in svgGroupConfig) {
    const config = svgGroupConfig[groupId];
    const groupElement = svgElement.getElementById(groupId);
    if (groupElement) {
      groupElement.style.display = config.defaultVisible ? "" : "none";

      if (isPlan && config.color) {
        groupElement.querySelectorAll("path, rect, polygon").forEach((el) => {
          el.style.fill = config.color;
          el.style.fillOpacity = "0.7";
        });
      }

      if (config.interactive && config.elementType) {
        initializeElementsByType(groupElement, config.elementType, groupId);
      }

      // Your original layer toggle button logic is restored
      const button = document.createElement("button");
      button.textContent = config.label;
      button.className = config.defaultVisible ? "active" : "";
      button.addEventListener("click", () => {
        const isVisible = groupElement.style.display !== "none";
        groupElement.style.display = isVisible ? "none" : "";
        button.classList.toggle("active", !isVisible);
      });
      layerControlsContainer.appendChild(button);
    }
  }

  if (!isPlan) {
    applyElementVisibilityAndColor(svgElement, currentPlanDbData);
  }
}

//</editor-fold>

//<editor-fold desc="SVG Loading and Navigation">
function getRegionAndZoneInfoForFile(svgFullFilename) {
  for (const regionKey in regionToZoneMap) {
    const zonesInRegion = regionToZoneMap[regionKey];
    const foundZone = zonesInRegion.find(
      (zone) => zone.svgFile.toLowerCase() === svgFullFilename.toLowerCase()
    );
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

// In ghom_app.js, REPLACE this function

function setupRegionZoneNavigationIfNeeded() {
  const regionSelect = document.getElementById("regionSelect");
  const zoneButtonsContainer = document.getElementById("zoneButtonsContainer");
  if (!regionSelect || !zoneButtonsContainer) return;

  if (regionSelect.dataset.initialized) return;

  regionSelect.innerHTML =
    '<option value="">-- ابتدا یک محدوده انتخاب کنید --</option>';

  // Get user role from the body tag
  const userRole = document.body.dataset.userRole;
  const isAdmin = userRole === "admin" || userRole === "superuser";

  // Use the svgGroupConfig and regionToZoneMap objects that are already in your file.
  for (const regionKey in regionToZoneMap) {
    const regionConfig = svgGroupConfig[regionKey];
    if (!regionConfig) continue; // Skip if no config exists for this region

    // --- THIS IS THE ONLY CHANGE IN LOGIC ---
    // It checks the new contractor_id property you just added.
    if (isAdmin || regionConfig.contractor_id === userRole) {
      const option = document.createElement("option");
      option.value = regionKey;
      option.textContent = regionConfig.label || regionKey;
      regionSelect.appendChild(option);
    }
  }

  // The rest of your original function logic stays the same
  regionSelect.addEventListener("change", function () {
    zoneButtonsContainer.innerHTML = "";
    const selectedRegionKey = this.value;
    if (!selectedRegionKey) return;

    const zones = regionToZoneMap[selectedRegionKey] || [];

    zones.forEach((zone) => {
      const button = document.createElement("button");
      button.textContent = zone.label;
      button.addEventListener("click", () => loadAndDisplaySVG(zone.svgFile));
      zoneButtonsContainer.appendChild(button);
    });
  });

  regionSelect.dataset.initialized = "true";
}
function initializeStatusLegend() {
  const legendItems = document.querySelectorAll(".legend-item");
  legendItems.forEach((item) => {
    item.addEventListener("click", () => {
      const status = item.dataset.status;
      // Toggle the status in our global tracker
      visibleStatuses[status] = !visibleStatuses[status];
      // Toggle the active class for visual feedback
      item.classList.toggle("active", visibleStatuses[status]);
      saveStateToSession("statusVisibility", visibleStatuses);
      // Re-apply styles to the current SVG
      if (currentSvgElement) {
        applyElementVisibilityAndColor(currentSvgElement, currentPlanDbData);
      }
    });
  });
}
//<editor-fold desc="DOM Ready and Event Listeners">
document.addEventListener("DOMContentLoaded", () => {
  // --- 1. SETUP EVENT LISTENERS FIRST ---

  // Correctly set up the "Back to Plan" button listener.
  // It now performs both actions on a single click.
  document.getElementById("backToPlanBtn").addEventListener("click", () => {
    clearSessionState();
    loadAndDisplaySVG(SVG_BASE_PATH + "Plan.svg");
  });

  // Initialize the legend so it's ready for clicks.
  initializeStatusLegend();

  // Setup form handlers (your existing code for this is fine).
  const gfrcForm = document.getElementById("gfrc-form-element");
  if (gfrcForm) {
    gfrcForm.addEventListener("submit", function (event) {
      event.preventDefault();
      const form = event.target;
      const formData = new FormData(form);
      const saveBtn = form.querySelector(".btn.save");
      const itemsPayload = [];
      form.querySelectorAll(".checklist-input").forEach((input) => {
        const itemId = input.dataset.itemId;
        const statusRadio = form.querySelector(
          `input[name="status_${itemId}"]:checked`
        );
        itemsPayload.push({
          itemId: itemId,
          status: statusRadio ? statusRadio.value : "N/A",
          value: input.value,
        });
      });
      formData.append("items", JSON.stringify(itemsPayload));
      saveBtn.textContent = "در حال ذخیره...";
      saveBtn.disabled = true;
      fetch(`${SVG_BASE_PATH}api/save_inspection.php`, {
        method: "POST",
        body: formData,
      })
        .then((res) => res.json())
        .then((data) => {
          if (data.status === "success") {
            alert(data.message);
            closeForm("gfrcChecklistForm");
          } else {
            alert("خطا در ذخیره‌سازی: " + data.message);
          }
        })
        .catch((err) => alert("خطای ارتباطی: " + err))
        .finally(() => {
          saveBtn.textContent = "ذخیره";
          saveBtn.disabled = false;
        });
    });
  }
  const universalFormPopup = document.getElementById("universalChecklistForm");
  if (universalFormPopup) {
    // Listen for the submit event on the entire popup panel.
    universalFormPopup.addEventListener("submit", function (event) {
      event.preventDefault(); // Prevent default browser submission

      const form = document.getElementById("checklist-form"); // Target the <form> inside
      if (!form) {
        alert("خطای داخلی: فرم یافت نشد.");
        return;
      }

      const saveBtn = universalFormPopup.querySelector(".btn.save");
      const formData = new FormData(); // We will build this manually

      // Append the main element ID
      formData.append(
        "elementId",
        form.querySelector('[name="elementId"]').value
      );

      // --- NEW: CORRECTLY GATHER DATA BY STAGE ---
      const stagesData = {};
      form.querySelectorAll(".stage-container").forEach((stageEl) => {
        const stageId = stageEl.dataset.stageId;
        const stageItems = [];

        // Gather checklist item data for this stage
        stageEl.querySelectorAll(".item-row").forEach((itemEl) => {
          const input = itemEl.querySelector(".checklist-input");
          const radio = itemEl.querySelector('input[type="radio"]:checked');
          stageItems.push({
            itemId: input.dataset.itemId,
            status: radio ? radio.value : "Pending", // Default to Pending if nothing is selected
            value: input.value,
          });
        });

        // Put all data for this stage into a single object
        stagesData[stageId] = {
          overall_status: stageEl.querySelector('[name="overall_status"]')
            .value,
          inspection_date: stageEl.querySelector('[name="inspection_date"]')
            .value,
          contractor_status: stageEl.querySelector('[name="contractor_status"]')
            .value,
          contractor_date: stageEl.querySelector('[name="contractor_date"]')
            .value,
          items: stageItems,
        };
      });

      // Append the structured stage data as a single JSON string
      formData.append("stages", JSON.stringify(stagesData));

      saveBtn.textContent = "در حال ذخیره...";
      saveBtn.disabled = true;

      fetch(`${SVG_BASE_PATH}api/save_inspection.php`, {
        method: "POST",
        body: formData,
      })
        .then((res) => res.json())
        .then((data) => {
          if (data.status === "success") {
            alert(data.message);
            closeForm("universalChecklistForm");
            // Reload the current SVG to show color changes immediately
            loadAndDisplaySVG(SVG_BASE_PATH + currentPlanFileName);
          } else {
            throw new Error(data.message || "An unknown error occurred.");
          }
        })
        .catch((err) => {
          console.error("Save Error:", err);
          alert("خطای ارتباطی: " + err.message);
        })
        .finally(() => {
          saveBtn.textContent = "ذخیره تغییرات";
          saveBtn.disabled = false;
        });
    });
  }

  // --- 2. DEFINE THE INITIAL VIEW LOADER ---
  function loadInitialView() {
    // Load Status Filters from session
    const savedStatuses = loadStateFromSession("statusVisibility");
    if (savedStatuses) {
      visibleStatuses = savedStatuses;
      // Update the legend UI to match the loaded state
      document.querySelectorAll(".legend-item").forEach((item) => {
        const status = item.dataset.status;
        const isActive = visibleStatuses[status] ?? false;
        item.classList.toggle("active", isActive);
      });
    }

    // Load the Last Viewed Plan from session
    const lastPlan = loadStateFromSession("lastViewedPlan");
    if (lastPlan && lastPlan !== "null") {
      // Safety check for the string "null"
      loadAndDisplaySVG(SVG_BASE_PATH + lastPlan);
    } else {
      // Default to the main plan if nothing is saved
      loadAndDisplaySVG(SVG_BASE_PATH + "Plan.svg");
    }
  }

  // --- 3. EXECUTE THE INITIAL LOAD ---
  // This is now the ONLY place where the initial view is triggered.
  loadInitialView();

  // Optional: Handle Deep Linking from URL (this can stay as-is)
  const urlParams = new URLSearchParams(window.location.search);
  const elementIdToOpen = urlParams.get("element_id");
  if (elementIdToOpen) {
    console.log("Deep link detected, session state might be overridden.");
  }
  const formPopupElement = document.getElementById("universalChecklistForm");
  if (formPopupElement && typeof interact !== "undefined") {
    // Only enable dragging/resizing on desktop
    if (window.innerWidth >= 992) {
      interact(formPopupElement)
        .resizable({
          edges: { left: true, right: true, bottom: true, top: true },
          listeners: {
            move(event) {
              let target = event.target;
              let x = parseFloat(target.getAttribute("data-x")) || 0;
              let y = parseFloat(target.getAttribute("data-y")) || 0;

              // Update size
              target.style.width = event.rect.width + "px";
              target.style.height = event.rect.height + "px";

              // Adjust position if resizing from top or left
              x += event.deltaRect.left;
              y += event.deltaRect.top;

              target.style.transform = "translate(" + x + "px," + y + "px)";

              target.setAttribute("data-x", x);
              target.setAttribute("data-y", y);
            },
          },
          modifiers: [
            interact.modifiers.restrictEdges({
              outer: "parent",
            }),
            interact.modifiers.restrictSize({
              min: { width: 450, height: 500 },
              max: {
                width: window.innerWidth * 0.95,
                height: window.innerHeight * 0.9,
              },
            }),
          ],
          inertia: true,
        })
        .draggable({
          allowFrom: ".form-header-new", // Only allow dragging from header
          listeners: {
            move(event) {
              let target = event.target;
              let x =
                (parseFloat(target.getAttribute("data-x")) || 0) + event.dx;
              let y =
                (parseFloat(target.getAttribute("data-y")) || 0) + event.dy;

              target.style.transform = "translate(" + x + "px, " + y + "px)";

              target.setAttribute("data-x", x);
              target.setAttribute("data-y", y);
            },
          },
          inertia: true,
          modifiers: [
            interact.modifiers.restrictRect({
              restriction: "parent",
              endOnly: true,
            }),
          ],
        });
    }
  }
});

function loadAndDisplaySVG(svgFullFilename) {
  const svgContainer = document.getElementById("svgContainer");
  if (!svgContainer) return;

  closeAllForms();
  svgContainer.innerHTML = "";
  svgContainer.classList.add("loading");

  const baseFilename = svgFullFilename.substring(
    svgFullFilename.lastIndexOf("/") + 1
  );
  currentPlanFileName = baseFilename;
  saveStateToSession("lastViewedPlan", baseFilename);
  const isPlan = baseFilename.toLowerCase() === "plan.svg";

  document.getElementById("regionZoneNavContainer").style.display = isPlan
    ? "flex"
    : "none";
  document.getElementById("backToPlanBtn").style.display = isPlan
    ? "none"
    : "block";
  if (isPlan) {
    setupRegionZoneNavigationIfNeeded();
    // NEW: Hide the info bar when on the main plan
    updateCurrentZoneInfo(null);
  } else {
    // NEW: Get zone info and update the display bar
    const info = getRegionAndZoneInfoForFile(svgFullFilename);
    if (info) {
      updateCurrentZoneInfo(info.zoneLabel, info.contractor, info.block);
    }
  }

  Promise.all([
    fetch(SVG_BASE_PATH + baseFilename).then((res) => {
      if (!res.ok) throw new Error(`SVG file not found: ${res.statusText}`);
      return res.text();
    }),
    isPlan
      ? Promise.resolve({})
      : fetch(`/ghom/api/get_plan_elements.php?plan=${baseFilename}`).then(
          (res) => {
            if (!res.ok) return {}; // Return empty object on API failure to prevent crash
            return res.json();
          }
        ),
  ])
    .then(([svgData, dbData]) => {
      svgContainer.classList.remove("loading");
      currentPlanDbData = dbData;

      svgContainer.innerHTML = svgData;
      const zoomControlsHtml = `<div class="zoom-controls"><button id="zoomInBtn">+</button><button id="zoomOutBtn">-</button><button id="zoomResetBtn">⌂</button></div>`;
      svgContainer.insertAdjacentHTML("afterbegin", zoomControlsHtml);

      currentSvgElement = svgContainer.querySelector("svg");
      if (!currentSvgElement) throw new Error("SVG element not found in data.");

      applyGroupStylesAndControls(currentSvgElement);
      setupZoomControls();

      // NEW: Apply visibility filters from the legend after loading everything
      applyElementVisibilityAndColor(currentSvgElement, currentPlanDbData);
    })
    .catch((error) => {
      svgContainer.classList.remove("loading");
      console.error("Error during plan loading:", error);
      svgContainer.innerHTML = `<p style="color:red; font-weight:bold;">خطا در بارگزاری نقشه: ${error.message}</p>`;
    });
}
//</editor-fold>
function applyDataAndStyles(svgElement, dbData) {
  // Loop through every known element from the database
  for (const elementId in dbData) {
    const data = dbData[elementId];
    const el = svgElement.getElementById(elementId);

    if (el) {
      // Apply the status color
      el.style.fill = STATUS_COLORS[data.status] || STATUS_COLORS["Pending"];

      // Attach all database info to the element's dataset
      el.dataset.elementType = data.type;
      el.dataset.floorLevel = data.floor;
      el.dataset.axisSpan = data.axis;
      el.dataset.widthCm = data.width;
      el.dataset.heightCm = data.height;
      el.dataset.areaSqm = data.area;
      el.dataset.status = data.status;
      // Mark as interactive so the click listener can find it
      el.classList.add("interactive-element");
    }
  }
}

/**
 * Applies both visibility and color styles to all elements based on the legend filters and their status.
 * @param {SVGElement} svgElement The parent SVG element.
 * @param {Object} dbData An object mapping element_id to its database record.
 */
function applyElementVisibilityAndColor(svgElement, dbData) {
  for (const elementId in dbData) {
    const el = svgElement.getElementById(elementId);
    if (el) {
      const data = dbData[elementId];
      const status = data.status;
      const elementType = data.type;

      // 1. SET VISIBILITY based on the legend filter
      if (visibleStatuses[status]) {
        el.style.display = ""; // Show element
      } else {
        el.style.display = "none"; // Hide element
        continue; // No need to color a hidden element
      }

      // 2. SET COLOR (this is your existing logic from applyStatusColors)
      if (status === "Pending") {
        if (elementType === "GFRC") {
          const orientation = el.dataset.panelOrientation;
          const gfrcConfig = svgGroupConfig["GFRC"];
          if (gfrcConfig && gfrcConfig.colors) {
            if (orientation === "افقی" && gfrcConfig.colors.h) {
              el.style.fill = gfrcConfig.colors.h;
            } else if (orientation === "عمودی" && gfrcConfig.colors.v) {
              el.style.fill = gfrcConfig.colors.v;
            } else {
              el.style.fill = STATUS_COLORS["Pending"];
            }
          } else {
            el.style.fill = STATUS_COLORS["Pending"];
          }
        } else {
          const group = el.closest("g");
          if (
            group &&
            group.id &&
            svgGroupConfig[group.id] &&
            svgGroupConfig[group.id].color
          ) {
            el.style.fill = svgGroupConfig[group.id].color;
          } else {
            el.style.fill = STATUS_COLORS["Pending"];
          }
        }
      } else {
        el.style.fill = STATUS_COLORS[status] || STATUS_COLORS["Pending"];
      }
    }
  }
}
//<editor-fold desc="Utility and Math Functions">
function getRectangleDimensions(dAttribute) {
  if (!dAttribute) return null;
  const commands = dAttribute
    .trim()
    .toUpperCase()
    .split(/(?=[LMCZHV])/);
  let points = [],
    currentX = 0,
    currentY = 0;
  commands.forEach((commandStr) => {
    const type = commandStr.charAt(0);
    const args =
      commandStr
        .substring(1)
        .trim()
        .split(/[\s,]+/)
        .map(Number) || [];
    let i = 0;
    switch (type) {
      case "M":
      case "L":
        while (i < args.length) {
          currentX = args[i++];
          currentY = args[i++];
          points.push({
            x: currentX,
            y: currentY,
          });
        }
        break;
      case "H":
        while (i < args.length) {
          currentX = args[i++];
          points.push({
            x: currentX,
            y: currentY,
          });
        }
        break;
      case "V":
        while (i < args.length) {
          currentY = args[i++];
          points.push({
            x: currentX,
            y: currentY,
          });
        }
        break;
    }
  });
  if (points.length < 3) return null;
  const xValues = points.map((p) => p.x),
    yValues = points.map((p) => p.y);
  const minX = Math.min(...xValues),
    maxX = Math.max(...xValues);
  const minY = Math.min(...yValues),
    maxY = Math.max(...yValues);
  return {
    x: minX,
    y: minY,
    width: maxX - minX,
    height: maxY - minY,
  };
}

function extractAllAxisMarkers(svgElement) {
  currentSvgAxisMarkersX = [];
  currentSvgAxisMarkersY = [];
  const allTexts = Array.from(svgElement.querySelectorAll("text"));
  const viewBoxHeight = svgElement.viewBox.baseVal.height;
  const viewBoxWidth = svgElement.viewBox.baseVal.width;
  // More generous thresholds
  const Y_EDGE_THRESHOLD = viewBoxHeight * 0.2;
  const X_EDGE_THRESHOLD = viewBoxWidth * 0.2;

  allTexts.forEach((textEl) => {
    try {
      const content = textEl.textContent.trim();
      if (!content) return;
      const bbox = textEl.getBBox();
      if (bbox.width === 0 || bbox.height === 0) return; // Skip invisible elements

      // Y-Axis (Floors): Must be text like "+12.25", "1st FLOOR", etc., AND be near the left/right edges.
      if (
        content.toLowerCase().includes("floor") ||
        content.match(/^\s*\+\d+\.\d+\s*$/)
      ) {
        if (
          bbox.x < X_EDGE_THRESHOLD ||
          bbox.x + bbox.width > viewBoxWidth - X_EDGE_THRESHOLD
        ) {
          currentSvgAxisMarkersY.push({
            text: content,
            x: bbox.x,
            y: bbox.y + bbox.height / 2,
          });
        }
      }
      // X-Axis (Grid Letters/Numbers): Must be simple letters or numbers, AND be near the top/bottom edges.
      else if (content.match(/^[A-Z0-9]{1,3}$/)) {
        if (
          bbox.y < Y_EDGE_THRESHOLD ||
          bbox.y + bbox.height > viewBoxHeight - Y_EDGE_THRESHOLD
        ) {
          currentSvgAxisMarkersX.push({
            text: content,
            x: bbox.x + bbox.width / 2,
            y: bbox.y,
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
  console.log(
    "Found X-Axis Markers:",
    currentSvgAxisMarkersX.map((m) => m.text)
  );
  console.log(
    "Found Y-Axis (Floor) Markers:",
    currentSvgAxisMarkersY.map((m) => m.text)
  );
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
    currentSvgAxisMarkersX.forEach((marker) => {
      if (marker.x <= elCenterX) {
        leftMarker = marker;
      }
      if (marker.x >= elCenterX && !rightMarker) {
        rightMarker = marker;
      }
    });
    if (leftMarker && rightMarker) {
      axisSpan =
        leftMarker.text === rightMarker.text
          ? leftMarker.text
          : `${leftMarker.text}-${rightMarker.text}`;
    } else if (leftMarker) {
      axisSpan = `> ${leftMarker.text}`;
    } else if (rightMarker) {
      axisSpan = `< ${rightMarker.text}`;
    }

    // Find nearest Y-axis (floor) marker that is BELOW the element's center
    let belowMarker = null;
    currentSvgAxisMarkersY.forEach((marker) => {
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
    derivedId: null,
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
    passive: false,
  });
  svgContainer.addEventListener("mousedown", handleMouseDown);
  document.addEventListener("mousemove", handleMouseMove);
  document.addEventListener("mouseup", handleMouseUp);
  svgContainer.addEventListener("mouseleave", handleMouseUp);
  svgContainer.addEventListener("touchstart", handleTouchStart, {
    passive: false,
  });
  svgContainer.addEventListener("touchmove", handleTouchMove, {
    passive: false,
  });
  document.addEventListener("touchend", handleTouchEnd, {
    passive: false,
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
    lastTouchDistance = Math.hypot(
      t2.clientX - t1.clientX,
      t2.clientY - t1.clientY
    );
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
      zoomSvg(
        currentZoom * scale,
        (midX - rect.left - panX) / currentZoom,
        (midY - rect.top - panY) / currentZoom
      );
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
  element.addEventListener(
    "touchstart",
    (e) => {
      e.stopPropagation();
      touchMoved = false;
      touchStartTime = Date.now();
    },
    {
      passive: true,
    }
  );
  element.addEventListener(
    "touchmove",
    () => {
      touchMoved = true;
    },
    {
      passive: true,
    }
  );
  element.addEventListener(
    "touchend",
    (e) => {
      if (!touchMoved && Date.now() - touchStartTime < 300) {
        e.preventDefault();
        clickHandler(e);
      }
    },
    {
      passive: false,
    }
  );
}

function zoomSvg(newZoomFactor, pivotX, pivotY) {
  if (!currentSvgElement) return;
  const svgContainerRect = document
    .getElementById("svgContainer")
    .getBoundingClientRect();
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
