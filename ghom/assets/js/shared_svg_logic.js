// ===================================================================================
//
//            public_html\ghom\assets\js\shared_svg_logic.js - MOBILE BATCH SELECTION & ZOOM FIX
//
// Fixed mobile batch selection with long press and improved zoom controls
//
// ===================================================================================

document.addEventListener("DOMContentLoaded", () => {
  /**
   * Main entry point. Waits for config files to be loaded, then initializes the application.
   */
  const waitForConfigAndStart = () => {
    if (window.svgGroupConfig && window.regionToZoneMap) {
      console.log("Configuration is ready. Starting main application logic.");
      initializeApp();
    } else {
      setTimeout(waitForConfigAndStart, 100);
    }
  };

  /**
   * Sets up all event listeners and loads the initial view from session storage or defaults.
   */
  const initializeApp = () => {
    // 1. Setup Static Event Listeners
    document.getElementById("backToPlanBtn")?.addEventListener("click", () => {
      clearSessionState();
      loadAndDisplaySVG("/ghom/Plan.svg");
    });

    document
      .getElementById("toggle-batch-panel-btn")
      ?.addEventListener("click", () => {
        const batchPanel = document.getElementById("batch-update-panel");
        if (batchPanel) {
          batchPanel.style.display =
            batchPanel.style.display === "block" ? "none" : "block";
        }
      });

    document
      .getElementById("submitBatchUpdate")
      ?.addEventListener("click", submitBatchUpdate);

    if (typeof jalaliDatepicker !== "undefined") {
      jalaliDatepicker.startWatch();
    }
    document
      .getElementById("batch_stage")
      ?.addEventListener("change", handleStageChange);
    document
      .getElementById("view-element-type-select")
      ?.addEventListener("change", handleViewTypeChange);
    document
      .getElementById("view-stage-select")
      ?.addEventListener("change", handleViewStageChange);
    document
      .getElementById("reset-view-filter-btn")
      ?.addEventListener("click", resetViewFilter);
    // 2. Load the Initial View
    loadInitialView();
  };

  /**
   * Reads from session storage to restore the last view or loads the default plan.
   */
  const loadInitialView = () => {
    // Restore layer visibility state
    const savedLayerState = loadStateFromSession("layerVisibility");
    if (savedLayerState) {
      layerVisibility = savedLayerState;
    } else {
      // If no state is saved, create it from the default config
      for (const groupId in svgGroupConfig) {
        if (svgGroupConfig.hasOwnProperty(groupId)) {
          layerVisibility[groupId] = svgGroupConfig[groupId].defaultVisible;
        }
      }
    }

    // Restore the last viewed plan
    const lastPlan = loadStateFromSession("lastViewedPlan");
    if (lastPlan && lastPlan !== "null") {
      loadAndDisplaySVG(lastPlan);
    } else {
      loadAndDisplaySVG("/ghom/Plan.svg");
    }
  };

  // Start the entire process
  waitForConfigAndStart();
});

// SECTION 1: CORE CONFIGURATION & GLOBAL STATE
// ===================================================================================

const SVG_BASE_PATH = "/ghom/";
const USER_ROLE = document.body.dataset.userRole;
let selectedElements = new Map();
let lastClickedElementId = null;

// Selection state
let isMarqueeSelecting = false;
let selectionBoxDiv = null;
let marqueeStartPoint = { x: 0, y: 0 };
let selectionStartTime = 0;
const SELECTION_DELAY = 150; // ms delay before starting selection

// Pan & zoom state
let isPanning = false;
let isSpacebarDown = false;
let panStart = { x: 0, y: 0 };
let panX = 0,
  panY = 0,
  zoom = 1;
let panStartTime = 0;
const PAN_DELAY = 100; // ms delay before starting pan

// Mobile touch state - ENHANCED
let touchStartTime = 0;
let initialTouchDistance = 0;
let lastTouchCenter = { x: 0, y: 0 };
let touchMoveThreshold = 10; // pixels
let touchStartPos = { x: 0, y: 0 };
let hasTouchMoved = false;

let isLongPressing = false;
let longPressTriggered = false;

const TOUCH_MOVE_THRESHOLD = 15; // pixels before canceling long press
let isLongPress = false;
let longPressTimer = null;
const LONG_PRESS_DURATION = 800; // ms for long press
let touchCount = 0;
let doubleTapTimer = null;
const DOUBLE_TAP_DELAY = 300; // ms

// Mobile selection mode
let isMobileSelectionMode = false;
let mobileSelectionModeBtn = null;

// SVG context state
let currentSvgElement = null;
let currentPlanFileName = "Plan.svg";
let currentPlanZoneName = "نامشخص";
let currentPlanDefaultContractor = "پیمانکار عمومی";
let currentPlanDefaultBlock = "بلوک عمومی";
let currentSvgAxisMarkersX = [],
  currentSvgAxisMarkersY = [];

// Container reference
let currentContainer = null;

// Transform update function
let updateTransformFunction = null;
const svgGroupConfig = {
  GFRC: {
    label: "GFRC",
    // ADD THIS 'colors' OBJECT
    colors: {
      v: "rgba(13, 110, 253, 0.7)", // Vertical color (Blue)
      h: "rgba(0, 150, 136, 0.75)", // Horizontal color (Teal)
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
let currentPlanDbData = {}; // This will hold all data from the database
const STATUS_COLORS = {
  OK: "rgba(40, 167, 69, 0.7)", // Green
  "Not OK": "rgba(220, 53, 69, 0.7)", // Red
  "Ready for Inspection": "rgba(255, 193, 7, 0.7)", // Yellow
  Pending: "rgba(211, 211, 211, 0.6)", // A lighter Grey for default
};
// Device detection
const isMobile =
  /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(
    navigator.userAgent
  ) ||
  "ontouchstart" in window ||
  navigator.maxTouchPoints > 0;
let layerVisibility = {};

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
}
// Keyboard state
document.addEventListener("keydown", (e) => {
  if (e.code === "Space") {
    isSpacebarDown = true;
    e.preventDefault();
  }
});

document.addEventListener("keyup", (e) => {
  if (e.code === "Space") {
    isSpacebarDown = false;
  }
});

const mobileInstructionsCSS = `
  /* Add this CSS to your stylesheet */
  .mobile-instructions {
    position: fixed;
    bottom: 10px;
    left: 10px;
    right: 10px;
    background: rgba(0,0,0,0.8);
    color: white;
    padding: 10px;
    border-radius: 6px;
    font-size: 12px;
    z-index: 1001;
    display: none;
  }

  @media (max-width: 768px) {
    .mobile-instructions {
      display: block;
    }
  }

  .mobile-instructions.hidden {
    display: none !important;
  }
`;
function getRegionAndZoneInfoForFile(svgFullFilename) {
  if (typeof regionToZoneMap === "undefined") return null;

  for (const regionKey in regionToZoneMap) {
    const zonesInRegion = regionToZoneMap[regionKey];
    // Ensure we are comparing the full path correctly
    const foundZone = zonesInRegion.find(
      (zone) =>
        (SVG_BASE_PATH + zone.svgFile).toLowerCase() ===
        svgFullFilename.toLowerCase()
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
  return null; // Return null if no match is found
}
function loadAndDisplaySVG(svgPath) {
  const svgContainer = document.getElementById("svgContainer");
  if (!svgContainer) return;
  saveStateToSession("lastViewedPlan", svgPath);
  // Your existing logic is preserved
  selectedElements.clear();
  updateSelectionCount();
  svgContainer.innerHTML = "";
  svgContainer.classList.add("loading");
  currentPlanFileName = svgPath;

  const baseFilename = svgPath.substring(svgPath.lastIndexOf("/") + 1);
  const isPlanFile = baseFilename.toLowerCase() === "plan.svg";

  // Your existing UI setup logic is preserved
  const regionZoneNav = document.getElementById("regionZoneNavContainer");
  const backBtn = document.getElementById("backToPlanBtn");
  if (regionZoneNav) regionZoneNav.style.display = isPlanFile ? "flex" : "none";
  if (backBtn) backBtn.style.display = isPlanFile ? "none" : "block";

  // Your original logic to find zone info
  let zoneInfo = isPlanFile
    ? window.planNavigationMappings.find(
        (m) =>
          m.svgFile && m.svgFile.toLowerCase() === baseFilename.toLowerCase()
      )
    : getRegionAndZoneInfoForFile(svgPath);

  // Your original logic to populate the info bar
  currentPlanZoneName = zoneInfo?.label || baseFilename.replace(/\.svg$/i, "");
  currentPlanDefaultContractor =
    zoneInfo?.contractor || zoneInfo?.defaultContractor || "پیمانکار عمومی";
  currentPlanDefaultBlock =
    zoneInfo?.block || zoneInfo?.defaultBlock || "بلوک عمومی";

  const zoneInfoContainer = document.getElementById("currentZoneInfo");
  if (zoneInfoContainer) {
    document.getElementById("zoneNameDisplay").textContent =
      currentPlanZoneName;
    document.getElementById("zoneContractorDisplay").textContent =
      currentPlanDefaultContractor;
    document.getElementById("zoneBlockDisplay").textContent =
      currentPlanDefaultBlock;
    zoneInfoContainer.style.display = "block";
  }

  // --- THIS IS THE UPDATED LOGIC ---
  // It fetches the SVG and the Database data at the same time for efficiency.
  Promise.all([
    fetch(svgPath).then((res) => {
      if (!res.ok) throw new Error(`Network error loading ${baseFilename}`);
      return res.text();
    }),
    // Only fetch element data if it's a zone file
    isPlanFile
      ? Promise.resolve({})
      : fetch(`/ghom/api/get_plan_elements.php?plan=${baseFilename}`).then(
          (res) => {
            if (!res.ok) return {}; // On error, return empty data to prevent a crash
            return res.json();
          }
        ),
  ])
    .then(([svgData, dbData]) => {
      svgContainer.classList.remove("loading");
      currentPlanDbData = dbData; // Store the database data globally

      svgContainer.innerHTML = svgData;
      currentSvgElement = svgContainer.querySelector("svg");
      if (!currentSvgElement)
        throw new Error("SVG element not found in fetched data.");

      // The rest of your original function flow is preserved
      applyGroupStylesAndControls(currentSvgElement);
      setupZoomAndPan(svgContainer, currentSvgElement);
      setupInteractionHandlers(svgContainer);

      if (isPlanFile) {
        setupPlanNavigation();
      } else {
        // We no longer need extractAllAxisMarkers(), because this data now comes from the database.
      }

      showMobileInstructions();
    })
    .catch((error) => {
      svgContainer.classList.remove("loading");
      svgContainer.innerHTML = `<p style="color:red; font-weight:bold;">خطا در بارگزاری نقشه: ${error.message}</p>`;
      if (zoneInfoContainer) zoneInfoContainer.style.display = "none";
      console.error("Error in loadAndDisplaySVG:", error);
    });
}
/**
 * NEW: Reusable function to check for existing submissions and update the UI.
 * @param {string} stageId The stage ID to check against.
 */
async function checkAndUpdateOption(stageId) {
  const updateContainer = document.getElementById("update-existing-container");
  const updateCheckbox = document.getElementById("update_existing_checkbox");

  // Always hide and reset first
  if (updateContainer) updateContainer.style.display = "none";
  if (updateCheckbox) updateCheckbox.checked = false;

  if (!stageId || selectedElements.size === 0) return;

  try {
    const selectedIds = Array.from(selectedElements.keys());
    const checkResponse = await fetch(
      `/ghom/api/check_existing_for_stage.php`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          element_ids: selectedIds,
          stage_id: stageId,
        }),
      }
    );
    const existingData = await checkResponse.json();

    if (existingData.count > 0 && updateContainer) {
      document.getElementById("existingCount").textContent = existingData.count;
      updateContainer.style.display = "block";
    }
  } catch (error) {
    console.error("Error checking existing submissions:", error);
  }
}

function showMobileInstructions() {
  // Only show on mobile devices
  if (window.innerWidth <= 768) {
    let instructionsDiv = document.getElementById("mobile-instructions");

    if (!instructionsDiv) {
      instructionsDiv = document.createElement("div");
      instructionsDiv.id = "mobile-instructions";
      instructionsDiv.className = "mobile-instructions";
      instructionsDiv.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <div>
            📱 <strong>Mobile Guide:</strong><br>
            • <strong>Long press</strong> element for batch select<br>
            • <strong>Long press</strong> empty area for marquee select<br>
            • <strong>Double tap</strong> to zoom<br>
            • <strong>Pinch</strong> to zoom, <strong>drag</strong> to pan
          </div>
          <button onclick="this.parentElement.parentElement.classList.add('hidden')" 
                  style="background:white;color:black;border:none;padding:5px 10px;border-radius:3px;font-size:12px;">
            ✕
          </button>
        </div>
      `;
      document.body.appendChild(instructionsDiv);
    }

    // Auto hide after 8 seconds
    setTimeout(() => {
      if (instructionsDiv && !instructionsDiv.classList.contains("hidden")) {
        instructionsDiv.classList.add("hidden");
      }
    }, 8000);
  }
}

function setupPlanNavigation() {
  const regionSelect = document.getElementById("regionSelect");
  const zoneButtonsContainer = document.getElementById("zoneButtonsContainer");
  if (!regionSelect || !zoneButtonsContainer) {
    console.error("Navigation elements not found!");
    return;
  }

  // This check prevents re-adding options if the function is ever called twice.
  if (regionSelect.dataset.initialized) {
    return;
  }

  regionSelect.innerHTML =
    '<option value="">-- ابتدا یک محدوده انتخاب کنید --</option>';

  // --- ACCESS CONTROL LOGIC ---
  // Get the user's role (e.g., 'cat', 'car', 'admin') from the body tag
  const userRole = document.body.dataset.userRole;
  const isAdmin = userRole === "admin" || userRole === "superuser";

  // Use the config objects that are already in this file
  for (const regionKey in regionToZoneMap) {
    const regionConfig = svgGroupConfig[regionKey];
    if (!regionConfig) continue; // Skip if no config exists for this region

    // Filter the dropdown: only show the region if the user is an admin
    // OR if the region's assigned contractor_id matches the user's role.
    if (isAdmin || regionConfig.contractor_id === userRole) {
      const option = document.createElement("option");
      option.value = regionKey;
      option.textContent = regionConfig.label || regionKey;
      regionSelect.appendChild(option);
    }
  }
  // --- END OF ACCESS CONTROL LOGIC ---

  // The rest of the function remains the same
  regionSelect.addEventListener("change", function () {
    zoneButtonsContainer.innerHTML = "";
    const selectedRegionKey = this.value;

    if (!selectedRegionKey || typeof regionToZoneMap === "undefined") {
      return;
    }

    const zonesArray = regionToZoneMap[selectedRegionKey];

    if (Array.isArray(zonesArray)) {
      zonesArray.forEach((zone) => {
        const button = document.createElement("button");
        button.textContent = zone.label;
        button.addEventListener("click", () =>
          loadAndDisplaySVG(SVG_BASE_PATH + zone.svgFile)
        );
        zoneButtonsContainer.appendChild(button);
      });
    }
  });

  regionSelect.dataset.initialized = "true";
}
/**
 * Applies status-based colors to SVG elements in a zone view.
 * This is now the primary coloring function for zone plans.
 * @param {SVGElement} svgElement The parent SVG element.
 * @param {Object} dbData An object mapping element_id to its database record.
 */
/**
 * Applies status-based colors to SVG elements. Correctly handles all pending states.
 */
/**
 * MODIFIED: This function now dynamically colors elements based on a specific stage.
 * @param {SVGElement} svgElement The parent SVG element.
 * @param {Object} dbData The complete database data object.
 * @param {string|null} targetStageId The specific stage_id to color for. If null, it shows the latest status.
 */
/**
 * MODIFIED: This function now dynamically colors elements based on element type and a specific stage.
 * @param {SVGElement} svgElement The parent SVG element.
 * @param {Object} dbData The complete database data object.
 * @param {string|null} targetStageId The specific stage_id to color for. If null, it shows the latest status.
 * @param {string|null} targetElementType The type of element to highlight.
 */
function applyStatusColors(
  svgElement,
  dbData,
  targetStageId = null,
  targetElementType = null
) {
  if (!svgElement || !dbData) return;

  svgElement.querySelectorAll(".interactive-element").forEach((el) => {
    const elementId = el.id;
    const elementData = dbData[elementId];
    const elementType = el.dataset.elementType;

    // If filtering by type, fade out non-matching elements
    if (targetElementType && elementType !== targetElementType) {
      el.style.fill = "#e0e0e0";
      el.style.fillOpacity = "0.5";
      el.style.strokeOpacity = "0.5";
      return; // Skip to next element
    }

    // Restore normal opacity for matching or unfiltered elements
    el.style.fillOpacity = "1";
    el.style.strokeOpacity = "1";

    // Determine the status
    let status = "Pending";
    if (elementData && elementData.stages) {
      if (targetStageId) {
        // Use status for the specific stage, or 'Pending' if not found
        status = elementData.stages[targetStageId] || "Pending";
      } else {
        // Default: Find the latest available status for the element
        const stageIds = Object.keys(elementData.stages);
        if (stageIds.length > 0) {
          const latestStageId = Math.max(...stageIds.map(Number));
          status = elementData.stages[latestStageId];
        }
      }
    }

    const statusColor = STATUS_COLORS[status];

    if (statusColor) {
      el.style.fill = statusColor;
    } else {
      // Fallback for elements with no status
      if (elementType === "GFRC") {
        const orientation = el.dataset.panelOrientation;
        const colors = window.svgGroupConfig.GFRC?.colors;
        el.style.fill =
          colors && orientation === "Horizontal"
            ? colors.h
            : colors?.v || STATUS_COLORS.Pending;
      } else {
        el.style.fill = STATUS_COLORS.Pending;
      }
    }
  });
}

/**
 * MODIFIED: This function is now the heart of the dynamic coloring.
 * @param {string|null} targetElementType The type of element to highlight.
 * @param {string|null} targetStageId The stage ID to check status for.
 */
function updateSvgColors(targetElementType = null, targetStageId = null) {
  const svgElement = document.querySelector("#svgContainer svg");
  if (!svgElement || !currentPlanDbData) return;

  svgElement.querySelectorAll(".interactive-element").forEach((el) => {
    const elementId = el.id;
    const elementData = currentPlanDbData[elementId];
    const elementType = el.dataset.elementType;

    // If filtering by type, fade out non-matching elements
    if (targetElementType && elementType !== targetElementType) {
      el.style.fill = "#e0e0e0";
      el.style.fillOpacity = "0.5";
      el.style.strokeOpacity = "0.5";
      return; // Skip to next element
    }

    // Restore normal opacity for matching or unfiltered elements
    el.style.fillOpacity = "1";
    el.style.strokeOpacity = "1";

    // Determine the status
    let status = "Pending";
    if (elementData && elementData.stages) {
      if (targetStageId) {
        // Use status for the specific stage, or 'Pending' if not found
        status = elementData.stages[targetStageId] || "Pending";
      } else {
        // Default: Find the latest available status for the element
        const stageIds = Object.keys(elementData.stages);
        if (stageIds.length > 0) {
          const latestStageId = Math.max(...stageIds.map(Number));
          status = elementData.stages[latestStageId];
        }
      }
    }

    const statusColor = STATUS_COLORS[status];

    if (statusColor) {
      el.style.fill = statusColor;
    } else {
      // Fallback for elements with no status (e.g., initial state)
      if (elementType === "GFRC") {
        const orientation = el.dataset.panelOrientation;
        const colors = window.svgGroupConfig.GFRC?.colors;
        el.style.fill =
          colors && orientation === "Horizontal"
            ? colors.h
            : colors?.v || STATUS_COLORS.Pending;
      } else {
        el.style.fill = STATUS_COLORS.Pending;
      }
    }
  });
}
/**
 * Sets up SVG layers, controls, and initializes interactive elements ONCE.
 * On subsequent loads, it just applies visibility.
 * @param {SVGElement} svgElement The root <svg> element.
 */
/**
 * Sets up SVG layers and controls. This now correctly rebuilds controls for each plan.
 */
function applyGroupStylesAndControls(svgElement) {
  const layerControlsContainer = document.getElementById(
    "layerControlsContainer"
  );
  if (!layerControlsContainer) return;

  layerControlsContainer.innerHTML = ""; // ALWAYS clear old buttons

  const isPlan = currentPlanFileName.endsWith("Plan.svg");

  for (const groupId in svgGroupConfig) {
    const config = svgGroupConfig[groupId];
    const groupElement = svgElement.getElementById(groupId);

    // Only proceed if the group actually exists in the current SVG file
    if (groupElement) {
      // Determine if this layer button should be shown
      const isRegionLayer = config.elementType === "Region";
      // Show region buttons on the plan, and non-region buttons on zones
      if ((isPlan && isRegionLayer) || (!isPlan && !isRegionLayer)) {
        const isVisible = layerVisibility[groupId] ?? config.defaultVisible;
        groupElement.style.display = isVisible ? "" : "none";

        const button = document.createElement("button");
        button.textContent = config.label;
        button.className = isVisible ? "active" : "";
        button.addEventListener("click", () => {
          const isNowVisible = groupElement.style.display === "none";
          groupElement.style.display = isNowVisible ? "" : "none";
          button.classList.toggle("active", isNowVisible);
          layerVisibility[groupId] = isNowVisible;
          saveStateToSession("layerVisibility", layerVisibility);
        });
        layerControlsContainer.appendChild(button);
      }

      // Color the region blocks on the main plan
      if (isPlan && isRegionLayer && config.color) {
        groupElement.querySelectorAll("path, rect, polygon").forEach((el) => {
          el.style.fill = config.color;
          el.style.fillOpacity = "0.8";
        });
      }

      // Initialize the elements within the group
      if (config.interactive) {
        initializeElementsByType(groupElement, config.elementType, groupId);
      }
    }
  }

  // After setting all defaults, apply specific status colors on zone plans
  if (!isPlan) {
    updateSvgColors(null, null);
  }
}

function populateViewFilterDropdowns() {
  const typeSelect = document.getElementById("view-element-type-select");
  const stageSelect = document.getElementById("view-stage-select");
  if (!typeSelect || !stageSelect) return;

  // Get unique element types present in the current view
  const uniqueTypes = [
    ...new Set(Object.values(currentPlanDbData).map((el) => el.element_type)),
  ];

  typeSelect.innerHTML = '<option value="">-- همه انواع --</option>';
  uniqueTypes.forEach((type) => {
    if (type) {
      // Ensure type is not null/empty
      typeSelect.innerHTML += `<option value="${type}">${type}</option>`;
    }
  });

  // Reset stage dropdown
  stageSelect.innerHTML =
    '<option value="">-- ابتدا نوع المان را انتخاب کنید --</option>';
  stageSelect.disabled = true;
}

async function handleViewTypeChange() {
  const type = this.value;
  const stageSelect = document.getElementById("view-stage-select");
  const svgElement = document.querySelector("#svgContainer svg");

  applyStatusColors(svgElement, currentPlanDbData, null, type);

  if (!type) {
    stageSelect.innerHTML =
      '<option value="">-- ابتدا نوع المان را انتخاب کنید --</option>';
    stageSelect.disabled = true;
    return;
  }

  stageSelect.disabled = true;
  stageSelect.innerHTML = '<option value="">در حال بارگذاری...</option>';

  try {
    const response = await fetch(`/ghom/api/get_stages.php?type=${type}`);
    const stages = await response.json();

    if (stages && stages.length > 0) {
      stageSelect.innerHTML = '<option value="">-- همه مراحل --</option>';
      stages.forEach((s) => {
        stageSelect.innerHTML += `<option value="${s.stage_id}">${s.stage}</option>`;
      });
      stageSelect.disabled = false;
    } else {
      stageSelect.innerHTML = '<option value="">مرحله‌ای یافت نشد</option>';
    }
  } catch (error) {
    console.error("Failed to load stages for view filter:", error);
    stageSelect.innerHTML = '<option value="">خطا در بارگذاری</option>';
  }
}

/**
 * NEW: Handles change event for the stage filter dropdown.
 */
function handleViewStageChange() {
  const stageId = this.value;
  const type = document.getElementById("view-element-type-select").value;
  const svgElement = document.querySelector("#svgContainer svg");
  applyStatusColors(svgElement, currentPlanDbData, stageId, type);
}

/**
 * NEW: Resets the view filter and restores the default SVG coloring.
 */
function resetViewFilter() {
  const typeSelect = document.getElementById("view-element-type-select");
  const stageSelect = document.getElementById("view-stage-select");

  typeSelect.value = "";
  stageSelect.innerHTML =
    '<option value="">-- ابتدا نوع المان را انتخاب کنید --</option>';
  stageSelect.disabled = true;

  applyStatusColors(
    document.querySelector("#svgContainer svg"),
    currentPlanDbData,
    null,
    null
  );
}

function handleBatchStageChange() {
  const stageId = this.value;

  // When user changes the stage in the batch panel, recolor SVG and re-check for conflicts
  updateSvgColors(null, stageId);
  checkAndUpdateOption(stageId);
}
function initializeElementsByType(groupElement, elementType, groupId) {
  const isPlan = currentPlanFileName.endsWith("Plan.svg");
  const elements = groupElement.querySelectorAll("path, rect, circle, polygon");

  elements.forEach((el) => {
    // A. Handle the main plan: make all shapes in region groups interactive
    if (isPlan) {
      // Only make regions interactive on the main plan
      if (svgGroupConfig[groupId]?.elementType === "Region") {
        makeElementInteractive(el, groupId, true);
      }
      return; // Skip other logic for main plan elements
    }

    // B. Handle Zone Plans: only elements with a specific ID are interactive
    if (el.id) {
      el.dataset.uniqueId = el.id;
      el.dataset.elementType = elementType;

      const dbData = currentPlanDbData[el.id] || {};
      el.dataset.axisSpan = dbData.axis || "N/A";
      el.dataset.floorLevel = dbData.floor || "N/A";
      el.dataset.contractor = dbData.contractor || currentPlanDefaultContractor;
      el.dataset.block = dbData.block || currentPlanDefaultBlock;

      if (elementType === "GFRC") {
        const width = parseFloat(dbData.width);
        const height = parseFloat(dbData.height);
        if (width && height) {
          el.dataset.panelOrientation =
            width > height * 1.5 ? "Horizontal" : "Vertical";
        }
      }

      // Make the element interactive for selection
      makeElementInteractive(el, groupId, false);
    }
  });
}
/**
 * Makes an SVG element interactive, adding the appropriate click handler.
 * This version uses the dedicated 'planroles' constant for permission checks.
 * @param {SVGElement} element The SVG element to make interactive.
 * @param {string} groupId The ID of the parent group (e.g., 'Atieh').
 * @param {boolean} isPlan A flag indicating if the current view is the main plan.
 */
function makeElementInteractive(element, groupId, isPlan) {
  element.classList.add("interactive-element");

  const clickHandler = (event) => {
    event.stopPropagation();

    if (isPlan) {
      // --- THIS IS THE CORRECTED LOGIC ---

      const userRole = document.body.dataset.userRole;
      const isAdmin = userRole === "admin" || userRole === "superuser";

      // 1. Look for the region in your new 'planroles' constant.
      const regionRoleConfig = planroles[groupId];

      // 2. If the region doesn't exist in the roles config, do nothing.
      if (!regionRoleConfig) {
        console.warn(
          `Region "${groupId}" not found in 'planroles' config. Click ignored.`
        );
        return;
      }

      // 3. Perform the permission check using the contractor_id from 'planroles'.
      // The .trim() is kept as a best practice.
      const hasPermission =
        isAdmin ||
        (regionRoleConfig.contractor_id &&
          userRole &&
          regionRoleConfig.contractor_id.trim() === userRole.trim());

      if (hasPermission) {
        // If permission is granted, show the menu.
        showZoneSelectionMenu(groupId, event);
      } else {
        // If permission is denied, log it and do nothing.
        console.log(
          `Access Denied: User role '[${userRole}]' cannot access region '[${groupId}]' which requires '[${regionRoleConfig.contractor_id}]'.`
        );
      }
    } else {
      // This part is for zone plans and remains unchanged.
      handleElementClick(event);
    }
  };

  element.addEventListener("click", clickHandler);
}

// SECTION 2: UNIFIED INTERACTION HANDLERS (Desktop + Mobile)
// ===================================================================================

function setupInteractionHandlers(container) {
  // Clean up existing listeners by cloning
  removeExistingListeners();

  // Desktop mouse events
  container.addEventListener("mousedown", handlePointerStart);
  document.addEventListener("mousemove", handlePointerMove);
  document.addEventListener("mouseup", handlePointerEnd);

  // Mobile touch events with enhanced handling
  container.addEventListener("touchstart", handleTouchStart, {
    passive: false,
  });
  document.addEventListener("touchmove", handleTouchMove, { passive: false });
  document.addEventListener("touchend", handleTouchEnd, { passive: false });

  // Element clicks
  container.addEventListener("click", handleElementClick);

  // Enable right-click context menu prevention and pan
  container.addEventListener("contextmenu", (e) => {
    e.preventDefault();
    return false;
  });
}

function removeExistingListeners() {
  // Remove document-level listeners
  document.removeEventListener("mousemove", handlePointerMove);
  document.removeEventListener("mouseup", handlePointerEnd);
  document.removeEventListener("touchmove", handleTouchMove);
  document.removeEventListener("touchend", handleTouchEnd);
}

// Desktop pointer handlers
function handlePointerStart(e) {
  const targetElement = e.target.closest(".interactive-element");
  const containerRect = e.currentTarget.getBoundingClientRect();
  const pointerPos = {
    x: e.clientX - containerRect.left,
    y: e.clientY - containerRect.top,
  };

  // Right click or spacebar for panning
  if (e.button === 2 || isSpacebarDown || e.altKey) {
    e.preventDefault();
    startPanning(pointerPos, e.currentTarget, e.clientX, e.clientY);
    return;
  }

  // Only handle left mouse button for selection
  if (e.button !== 0) return;

  // If clicking on an interactive element, don't start selection
  if (targetElement) {
    return;
  }

  e.preventDefault();
  startMarqueeSelection(pointerPos, e.currentTarget, e);
}

function handlePointerMove(e) {
  if (isPanning) {
    continuePanning(e.clientX, e.clientY);
  } else if (isMarqueeSelecting) {
    continueMarqueeSelection(e);
  }
}

function handlePointerEnd(e) {
  if (isPanning) {
    stopPanning();
  } else if (isMarqueeSelecting) {
    finishMarqueeSelection(e);
  }
}

// ENHANCED Touch handlers for mobile with selection support
function handleTouchStart(e) {
  const touches = e.touches;
  const targetElement = e.target.closest(".interactive-element");

  touchStartTime = Date.now();
  hasTouchMoved = false;
  touchCount++;

  // Clear any existing long press timer
  if (longPressTimer) {
    clearTimeout(longPressTimer);
    longPressTimer = null;
  }

  // Single touch
  if (touches.length === 1) {
    const touch = touches[0];
    const containerRect = e.currentTarget.getBoundingClientRect();
    touchStartPos = {
      x: touch.clientX - containerRect.left,
      y: touch.clientY - containerRect.top,
    };

    // If touching an interactive element, handle selection modes
    if (targetElement) {
      // Start long press timer for batch selection mode
      longPressTimer = setTimeout(() => {
        if (!hasTouchMoved) {
          isLongPress = true;
          // Vibrate if available
          if (navigator.vibrate) {
            navigator.vibrate(50);
          }
          // Visual feedback for long press
          targetElement.style.transform = "scale(1.1)";
          setTimeout(() => {
            if (targetElement.style) {
              targetElement.style.transform = "";
            }
          }, 200);
        }
      }, LONG_PRESS_DURATION);
      return;
    }

    // If not on element, check for marquee selection (long press on empty area)
    longPressTimer = setTimeout(() => {
      if (!hasTouchMoved) {
        isLongPress = true;
        if (navigator.vibrate) {
          navigator.vibrate(100);
        }
        // Start marquee selection
        startMarqueeSelection(touchStartPos, e.currentTarget, e);
      }
    }, LONG_PRESS_DURATION);

    // For pan, wait a bit to see if it's a long press
    setTimeout(() => {
      if (!isLongPress && !hasTouchMoved && !targetElement) {
        startPanning(
          touchStartPos,
          e.currentTarget,
          touch.clientX,
          touch.clientY
        );
      }
    }, 100);
  } else if (touches.length === 2) {
    // Two finger pinch/zoom - cancel any long press
    if (longPressTimer) {
      clearTimeout(longPressTimer);
      longPressTimer = null;
    }

    e.preventDefault();
    const touch1 = touches[0];
    const touch2 = touches[1];

    initialTouchDistance = Math.hypot(
      touch2.clientX - touch1.clientX,
      touch2.clientY - touch1.clientY
    );

    lastTouchCenter = {
      x: (touch1.clientX + touch2.clientX) / 2,
      y: (touch1.clientY + touch2.clientY) / 2,
    };
  }

  // Handle double tap for zoom
  if (doubleTapTimer && touchCount === 2) {
    clearTimeout(doubleTapTimer);
    doubleTapTimer = null;
    touchCount = 0;
    // Double tap detected - zoom in
    if (!targetElement) {
      const touch = touches[0];
      const containerRect = e.currentTarget.getBoundingClientRect();
      const tapPoint = {
        x: touch.clientX - containerRect.left,
        y: touch.clientY - containerRect.top,
      };
      zoomToPoint(1.5, tapPoint);
    }
  } else if (touchCount === 1) {
    doubleTapTimer = setTimeout(() => {
      touchCount = 0;
    }, DOUBLE_TAP_DELAY);
  }
}

function handleTouchMove(e) {
  const touches = e.touches;

  if (
    Math.abs(touches[0].clientX - touchStartPos.x) > touchMoveThreshold ||
    Math.abs(touches[0].clientY - touchStartPos.y) > touchMoveThreshold
  ) {
    hasTouchMoved = true;

    // Cancel long press if moved too much
    if (longPressTimer) {
      clearTimeout(longPressTimer);
      longPressTimer = null;
    }
  }

  if (touches.length === 1) {
    if (isPanning) {
      const touch = touches[0];
      continuePanning(touch.clientX, touch.clientY);
      e.preventDefault();
    } else if (isMarqueeSelecting) {
      continueMarqueeSelection(e);
      e.preventDefault();
    }
  } else if (touches.length === 2) {
    // Handle pinch zoom
    e.preventDefault();
    const touch1 = touches[0];
    const touch2 = touches[1];

    const currentDistance = Math.hypot(
      touch2.clientX - touch1.clientX,
      touch2.clientY - touch1.clientY
    );

    const currentCenter = {
      x: (touch1.clientX + touch2.clientX) / 2,
      y: (touch1.clientY + touch2.clientY) / 2,
    };

    if (initialTouchDistance > 0) {
      const scale = currentDistance / initialTouchDistance;
      handlePinchZoom(scale, currentCenter);
    }

    lastTouchCenter = currentCenter;
    initialTouchDistance = currentDistance;
  }
}

function handleTouchEnd(e) {
  const touchDuration = Date.now() - touchStartTime;
  const targetElement = e.target.closest(".interactive-element");

  // Clear long press timer
  if (longPressTimer) {
    clearTimeout(longPressTimer);
    longPressTimer = null;
  }

  if (isPanning) {
    stopPanning();
  }

  if (isMarqueeSelecting) {
    finishMarqueeSelection(e);
  }

  // Handle element selection on touch end
  if (targetElement && !hasTouchMoved && touchDuration < LONG_PRESS_DURATION) {
    // Short tap on element
    if (isLongPress) {
      // This was a long press, toggle selection
      toggleSelection(targetElement);
      updateSelectionCount();
    } else {
      // Regular tap - simulate click
      const clickEvent = new MouseEvent("click", {
        bubbles: true,
        cancelable: true,
        clientX: e.changedTouches[0].clientX,
        clientY: e.changedTouches[0].clientY,
      });
      targetElement.dispatchEvent(clickEvent);
    }
  }

  // Reset states
  if (e.touches.length === 0) {
    initialTouchDistance = 0;
    hasTouchMoved = false;
    isLongPress = false;
  }
}

// SECTION 3: MOBILE SELECTION MODE
// ===================================================================================

function showSelectionModeIndicator() {
  // Create floating indicator
  const indicator = document.createElement("div");
  indicator.id = "selection-mode-indicator";
  indicator.innerHTML = "📱 Selection Mode - Drag to select elements";
  indicator.style.cssText = `
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: #007cba;
    color: white;
    padding: 10px 20px;
    border-radius: 25px;
    z-index: 10000;
    font-size: 14px;
    font-weight: bold;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    animation: slideDown 0.3s ease-out;
  `;

  // Add CSS animation
  if (!document.getElementById("selection-mode-styles")) {
    const style = document.createElement("style");
    style.id = "selection-mode-styles";
    style.textContent = `
      @keyframes slideDown {
        from { transform: translateX(-50%) translateY(-100%); opacity: 0; }
        to { transform: translateX(-50%) translateY(0); opacity: 1; }
      }
    `;
    document.head.appendChild(style);
  }

  document.body.appendChild(indicator);

  // Auto-hide after 3 seconds
  setTimeout(() => {
    if (indicator.parentNode) {
      indicator.style.animation = "slideDown 0.3s ease-out reverse";
      setTimeout(() => indicator.remove(), 300);
    }
  }, 3000);
}

function createMobileSelectionToggle() {
  if (!isMobile) return;

  // Remove existing button if any
  const existingBtn = document.getElementById("mobile-selection-toggle");
  if (existingBtn) {
    existingBtn.remove();
  }

  const toggleBtn = document.createElement("button");
  toggleBtn.id = "mobile-selection-toggle";
  toggleBtn.innerHTML = "🎯";
  toggleBtn.title = "Toggle Selection Mode";
  toggleBtn.style.cssText = `
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: ${isMobileSelectionMode ? "#007cba" : "#fff"};
    color: ${isMobileSelectionMode ? "#fff" : "#007cba"};
    border: 2px solid #007cba;
    font-size: 24px;
    cursor: pointer;
    z-index: 10000;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
  `;

  toggleBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    isMobileSelectionMode = !isMobileSelectionMode;

    // Update button appearance
    toggleBtn.style.background = isMobileSelectionMode ? "#007cba" : "#fff";
    toggleBtn.style.color = isMobileSelectionMode ? "#fff" : "#007cba";

    // Show feedback
    const feedback = document.createElement("div");
    feedback.textContent = isMobileSelectionMode
      ? "Selection Mode ON"
      : "Selection Mode OFF";
    feedback.style.cssText = `
      position: fixed;
      bottom: 90px;
      right: 20px;
      background: ${isMobileSelectionMode ? "#4CAF50" : "#FF5722"};
      color: white;
      padding: 8px 16px;
      border-radius: 20px;
      font-size: 12px;
      z-index: 10001;
      animation: fadeInOut 2s ease-in-out;
    `;

    document.body.appendChild(feedback);
    setTimeout(() => feedback.remove(), 2000);
  });

  document.body.appendChild(toggleBtn);
  mobileSelectionModeBtn = toggleBtn;
}

// SECTION 4: PANNING FUNCTIONS
// ===================================================================================

function startPanning(startPos, container, clientX, clientY) {
  if (!container) {
    console.error("Container is null in startPanning");
    return;
  }

  isPanning = true;
  panStartTime = Date.now();

  // Store the actual mouse/touch coordinates for proper delta calculation
  panStart.x = clientX;
  panStart.y = clientY;

  container.style.cursor = "grabbing";
}

function continuePanning(clientX, clientY) {
  if (!isPanning) return;

  // Calculate the delta from the start position
  const deltaX = clientX - panStart.x;
  const deltaY = clientY - panStart.y;

  // Update pan position by adding the delta to the current position
  panX += deltaX;
  panY += deltaY;

  // Update the start position for the next move event
  panStart.x = clientX;
  panStart.y = clientY;

  if (updateTransformFunction) {
    updateTransformFunction();
  }
}

function stopPanning() {
  isPanning = false;
  const container = currentContainer || document.getElementById("svgContainer");
  if (container) {
    container.style.cursor = "grab";
  }
}

function handlePinchZoom(scale, center) {
  const container = currentContainer || document.getElementById("svgContainer");
  if (!container) return;

  const rect = container.getBoundingClientRect();
  const newZoom = Math.max(0.1, Math.min(30, zoom * scale));

  // Adjust pan to zoom towards the pinch center
  panX = center.x - rect.left - (center.x - rect.left - panX) * scale;
  panY = center.y - rect.top - (center.y - rect.top - panY) * scale;
  zoom = newZoom;

  if (updateTransformFunction) {
    updateTransformFunction();
  }
}

// SECTION 5: MARQUEE SELECTION FUNCTIONS
// ===================================================================================

function startMarqueeSelection(startPos, container, event) {
  // Don't start selection if we're about to pan (desktop only)
  if (!isMobile && (isSpacebarDown || event.altKey)) return;

  isMarqueeSelecting = true;
  selectionStartTime = Date.now();
  marqueeStartPoint = startPos;

  selectionBoxDiv = document.createElement("div");
  selectionBoxDiv.id = "selection-box";
  selectionBoxDiv.style.cssText = `
    position: absolute;
    border: 2px dashed #007cba;
    background: rgba(0, 124, 186, 0.1);
    pointer-events: none;
    z-index: 1000;
  `;

  container.appendChild(selectionBoxDiv);
  selectionBoxDiv.style.left = `${startPos.x}px`;
  selectionBoxDiv.style.top = `${startPos.y}px`;
}

function continueMarqueeSelection(event) {
  if (!isMarqueeSelecting || !selectionBoxDiv) return;

  const container = currentContainer || document.getElementById("svgContainer");
  if (!container) return;

  const containerRect = container.getBoundingClientRect();
  let currentX, currentY;

  if (event.touches && event.touches.length > 0) {
    // Touch event
    currentX = event.touches[0].clientX - containerRect.left;
    currentY = event.touches[0].clientY - containerRect.top;
  } else {
    // Mouse event
    currentX = event.clientX - containerRect.left;
    currentY = event.clientY - containerRect.top;
  }

  selectionBoxDiv.style.left = `${Math.min(currentX, marqueeStartPoint.x)}px`;
  selectionBoxDiv.style.top = `${Math.min(currentY, marqueeStartPoint.y)}px`;
  selectionBoxDiv.style.width = `${Math.abs(currentX - marqueeStartPoint.x)}px`;
  selectionBoxDiv.style.height = `${Math.abs(
    currentY - marqueeStartPoint.y
  )}px`;
}

function finishMarqueeSelection(event) {
  if (!isMarqueeSelecting || !selectionBoxDiv) return;

  isMarqueeSelecting = false;

  const boxRect = selectionBoxDiv.getBoundingClientRect();

  // Only clear existing selections if not holding Ctrl/Cmd (desktop) or in mobile mode
  const shouldClearExisting = isMobile
    ? !isMobileSelectionMode
    : !(event.ctrlKey || event.metaKey);

  if (shouldClearExisting) {
    clearAllSelections();
  }

  // Select elements that intersect with the selection box
  let selectedCount = 0;
  document.querySelectorAll(".interactive-element").forEach((el) => {
    const elRect = el.getBoundingClientRect();
    if (
      boxRect.right > elRect.left &&
      boxRect.left < elRect.right &&
      boxRect.bottom > elRect.top &&
      boxRect.top < elRect.bottom
    ) {
      toggleSelection(el, true);
      selectedCount++;
    }
  });

  // Show selection feedback on mobile
  if (isMobile && selectedCount > 0) {
    showSelectionFeedback(selectedCount);
  }

  updateSelectionCount();
  selectionBoxDiv.remove();
  selectionBoxDiv = null;
}

function showSelectionFeedback(count) {
  const feedback = document.createElement("div");
  feedback.textContent = `Selected ${count} element${count > 1 ? "s" : ""}`;
  feedback.style.cssText = `
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #4CAF50;
    color: white;
    padding: 12px 24px;
    border-radius: 25px;
    font-size: 16px;
    font-weight: bold;
    z-index: 10001;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    animation: scaleIn 0.3s ease-out;
  `;

  // Add scale animation
  if (!document.getElementById("feedback-styles")) {
    const style = document.createElement("style");
    style.id = "feedback-styles";
    style.textContent = `
      @keyframes scaleIn {
        from { transform: translate(-50%, -50%) scale(0.5); opacity: 0; }
        to { transform: translate(-50%, -50%) scale(1); opacity: 1; }
      }
      @keyframes fadeInOut {
        0% { opacity: 0; transform: translateY(20px); }
        50% { opacity: 1; transform: translateY(0); }
        100% { opacity: 0; transform: translateY(-20px); }
      }
    `;
    document.head.appendChild(style);
  }

  document.body.appendChild(feedback);
  setTimeout(() => feedback.remove(), 1500);
}

// SECTION 6: ELEMENT SELECTION LOGIC
// ===================================================================================

function handleElementClick(event) {
  const targetElement = event.target.closest(".interactive-element");
  if (!targetElement) return;

  event.stopPropagation();
  const uniqueId = targetElement.dataset.uniqueId;
  if (!uniqueId) return;

  // Handle multi-selection differently on mobile
  if (isMobile) {
    if (isMobileSelectionMode) {
      // In mobile selection mode, always toggle
      toggleSelection(targetElement);
    } else {
      // Normal mobile tap - single selection
      clearAllSelections();
      toggleSelection(targetElement, true);
    }
  } else {
    // Desktop behavior
    if (event.ctrlKey || event.metaKey) {
      toggleSelection(targetElement);
    } else if (event.shiftKey && lastClickedElementId) {
      selectRange(uniqueId);
    } else {
      clearAllSelections();
      toggleSelection(targetElement, true);
    }
  }

  lastClickedElementId = uniqueId;
  updateSelectionCount();
}

function toggleSelection(element, forceSelect = false) {
  const uniqueId = element.dataset.uniqueId;
  if (forceSelect || !selectedElements.has(uniqueId)) {
    element.classList.add("element-selected");
    selectedElements.set(uniqueId, getElementData(element));
  } else {
    element.classList.remove("element-selected");
    selectedElements.delete(uniqueId);
  }
  document.dispatchEvent(
    new CustomEvent("selectionChanged", { detail: { selectedElements } })
  );
  updateSelectionCount();
}

function selectRange(endId) {
  const allElements = Array.from(
    document.querySelectorAll(".interactive-element")
  );
  const lastIndex = allElements.findIndex(
    (el) => el.dataset.uniqueId === lastClickedElementId
  );
  const currentIndex = allElements.findIndex(
    (el) => el.dataset.uniqueId === endId
  );
  if (lastIndex === -1 || currentIndex === -1) return;

  const [start, end] = [
    Math.min(lastIndex, currentIndex),
    Math.max(lastIndex, currentIndex),
  ];
  for (let i = start; i <= end; i++) {
    toggleSelection(allElements[i], true);
  }
}
/**
 * NEW: Handles the change event of the stage dropdown.
 */
function handleStageChange() {
  const stageId = this.value;
  const svgElement = document
    .getElementById("svgContainer")
    ?.querySelector("svg");

  // 1. Recolor the SVG based on the newly selected stage
  if (svgElement && currentPlanDbData) {
    applyStatusColors(svgElement, currentPlanDbData, stageId);
  }

  // 2. Re-check if the "update" option should be shown for this stage
  checkAndUpdateOption(stageId);
}

document.addEventListener("selectionChanged", async (e) => {
  const selectedElementsMap = e.detail.selectedElements;
  const batchStageSelect = document.getElementById("batch_stage");
  const updateContainer = document.getElementById("update-existing-container");

  // Always hide the "update" option when selection changes
  if (updateContainer) {
    updateContainer.style.display = "none";
    document.getElementById("update_existing_checkbox").checked = false;
  }

  if (!batchStageSelect) return;

  const elements = [...selectedElementsMap.values()];

  // 1. Handle cases where the selection is empty or invalid
  if (elements.length === 0) {
    batchStageSelect.innerHTML =
      '<option value="">ابتدا المان انتخاب کنید</option>';
    batchStageSelect.disabled = true;
    return;
  }

  const firstElementType = elements[0].element_type;
  const allSameType = elements.every(
    (el) => el.element_type === firstElementType
  );

  if (!allSameType) {
    batchStageSelect.innerHTML =
      '<option value="">المان‌ها باید از یک نوع باشند</option>';
    batchStageSelect.disabled = true;
    return;
  }

  // 2. Fetch all necessary data from the server
  try {
    batchStageSelect.disabled = true;
    batchStageSelect.innerHTML =
      '<option value="">در حال بررسی وضعیت...</option>';

    const [allStages, elementStatuses] = await Promise.all([
      // Get all possible stages for this element type
      fetch(`/ghom/api/get_stages.php?type=${firstElementType}`).then((res) =>
        res.json()
      ),
      // Get the last PASSED stage for each selected element
      fetch("/ghom/api/get_selection_status.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          element_ids: elements.map((el) => el.element_id),
        }),
      }).then((res) => res.json()),
    ]);

    if (!allStages || allStages.length === 0) {
      batchStageSelect.innerHTML =
        '<option value="">مرحله‌ای تعریف نشده</option>';
      return;
    }

    // 3. Determine the next logical stage for the entire group
    let maxLastPassedOrder = -1;
    elements.forEach((el) => {
      const lastPassedOrder = elementStatuses[el.element_id];
      // Find the highest stage order that has been passed among all selected elements
      maxLastPassedOrder = Math.max(
        maxLastPassedOrder,
        lastPassedOrder !== undefined ? parseInt(lastPassedOrder, 10) : -1
      );
    });

    // The next stage is one after the highest last passed stage
    const nextStageOrder = maxLastPassedOrder + 1;

    // Find the stage object that corresponds to this order
    const availableStages = allStages.filter(
      (stage, index) => index === nextStageOrder
    );

    // 4. Update the UI with the next stage and check for conflicts
    if (availableStages.length > 0) {
      const nextStage = availableStages[0];

      // Populate the dropdown with the correct next stage
      batchStageSelect.innerHTML = `<option value="${nextStage.stage_id}">${nextStage.stage}</option>`;
      batchStageSelect.disabled = false;

      // **TRIGGER DYNAMIC UPDATES**
      // A. Color the SVG to reflect statuses for this specific next stage
      applyStatusColors(
        document.querySelector("#svgContainer svg"),
        currentPlanDbData,
        nextStage.stage_id
      );

      // B. Check if any elements have already been submitted for this stage and show the update option if needed
      await checkAndUpdateOption(nextStage.stage_id);
    } else {
      // This happens if all stages are complete
      batchStageSelect.innerHTML =
        '<option value="">مرحله بعدی یافت نشد</option>';
      batchStageSelect.disabled = true;
    }
  } catch (error) {
    console.error("Error handling selection change:", error);
    batchStageSelect.innerHTML =
      '<option value="">خطا در بارگذاری مراحل</option>';
    batchStageSelect.disabled = true;
  }
});

function submitBatchUpdate() {
  const submitBtn = document.getElementById("submitBatchUpdate");
  const stageEl = document.getElementById("batch_stage");
  const dateEl = document.getElementById("batch_date");

  // --- Frontend Validation ---
  if (selectedElements.size === 0) {
    return alert("لطفا ابتدا المان‌ها را انتخاب کنید.");
  }
  if (!stageEl || !stageEl.value) {
    return alert("لطفا یک مرحله بازرسی را انتخاب کنید.");
  }
  if (!dateEl || !dateEl.value) {
    return alert("لطفا تاریخ اعلام وضعیت را مشخص کنید.");
  }

  // --- CORRECTED PAYLOAD ---
  const payload = {
    // Key changed from 'elements_data' to 'element_ids'
    element_ids: Array.from(selectedElements.keys()), // Sending just the IDs
    stage_id: stageEl.value,
    notes: document.getElementById("batch_notes").value,
    date: dateEl.value,
    // The key for this was 'update_mode' but the PHP expects 'update_existing' (boolean)
    update_existing: document.getElementById("update_existing_checkbox")
      .checked,
  };

  // Disable button to prevent multiple submissions
  submitBtn.disabled = true;
  submitBtn.textContent = "در حال ثبت...";

  fetch("/ghom/api/batch_update_status.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  })
    .then((res) => {
      if (!res.ok) {
        // Try to get a specific error message from the server
        return res.json().then((err) => {
          throw new Error(err.message || "خطای سرور");
        });
      }
      return res.json();
    })
    .then((data) => {
      alert(data.message);
      if (data.status === "success") {
        // Reload the current view to show updated statuses
        loadAndDisplaySVG(currentPlanFileName);
      }
    })
    .catch((error) => {
      console.error("Submission Error:", error);
      alert(`خطای ارتباطی: ${error.message}`);
    })
    .finally(() => {
      submitBtn.disabled = false;
      submitBtn.textContent = "ثبت برای بازرسی";
    });
}

// SECTION 7: ZOOM AND TRANSFORM FUNCTIONS
// ===================================================================================

function setupZoomAndPan(container, svg) {
  // Reset transform state
  zoom = 1;
  panX = 0;
  panY = 0;

  updateTransformFunction = () => {
    if (svg) {
      svg.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom})`;
    }
  };

  // Mouse wheel zoom
  container.addEventListener("wheel", (e) => {
    e.preventDefault();
    const rect = container.getBoundingClientRect();
    const scale = e.deltaY > 0 ? 0.9 : 1.1;
    const newZoom = Math.max(0.1, Math.min(30, zoom * scale));

    // Zoom towards mouse position
    const mouseX = e.clientX - rect.left;
    const mouseY = e.clientY - rect.top;

    panX = mouseX - (mouseX - panX) * scale;
    panY = mouseY - (mouseY - panY) * scale;
    zoom = newZoom;

    updateTransformFunction();
  });

  // Create zoom control buttons
  createZoomControls(container);

  // Set initial cursor
  container.style.cursor = "grab";

  // Apply initial transform
  updateTransformFunction();
}

// Create zoom control buttons
function createZoomControls(container) {
  // Remove existing controls if any
  const existingControls = container.querySelector(".zoom-controls");
  if (existingControls) {
    existingControls.remove();
  }

  const controlsDiv = document.createElement("div");
  controlsDiv.className = "zoom-controls";
  controlsDiv.style.cssText = `
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    gap: 8px;
    background: rgba(255, 255, 255, 0.95);
    padding: 12px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    touch-action: none;
  `;

  // Button base styles - larger for mobile
  const buttonBaseStyle = `
    width: 44px;
    height: 44px;
    border: 2px solid #007cba;
    background: white;
    cursor: pointer;
    border-radius: 6px;
    font-size: 20px;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
    touch-action: none;
    user-select: none;
    -webkit-user-select: none;
    -webkit-touch-callout: none;
    transition: all 0.2s ease;
  `;

  // Zoom In button
  const zoomInBtn = document.createElement("button");
  zoomInBtn.textContent = "+";
  zoomInBtn.title = "Zoom In";
  zoomInBtn.style.cssText = buttonBaseStyle;

  // Zoom Out button
  const zoomOutBtn = document.createElement("button");
  zoomOutBtn.textContent = "−";
  zoomOutBtn.title = "Zoom Out";
  zoomOutBtn.style.cssText = buttonBaseStyle;

  // Reset Zoom button
  const resetBtn = document.createElement("button");
  resetBtn.textContent = "⌂";
  resetBtn.title = "Reset Zoom";
  resetBtn.style.cssText = buttonBaseStyle;

  // Add touch events for better mobile responsiveness
  [zoomInBtn, zoomOutBtn, resetBtn].forEach((btn, index) => {
    // Prevent context menu
    btn.addEventListener("contextmenu", (e) => {
      e.preventDefault();
      return false;
    });

    // Touch start - visual feedback
    btn.addEventListener(
      "touchstart",
      (e) => {
        e.preventDefault();
        e.stopPropagation();
        btn.style.background = "#007cba";
        btn.style.color = "white";
        btn.style.transform = "scale(0.95)";
      },
      { passive: false }
    );

    // Touch end - action
    btn.addEventListener(
      "touchend",
      (e) => {
        e.preventDefault();
        e.stopPropagation();

        // Reset visual state
        btn.style.background = "white";
        btn.style.color = "#007cba";
        btn.style.transform = "scale(1)";

        // Perform action
        if (index === 0) {
          // Zoom in
          zoomToCenter(1.3);
        } else if (index === 1) {
          // Zoom out
          zoomToCenter(0.7);
        } else {
          // Reset
          resetZoom();
        }
      },
      { passive: false }
    );

    // Mouse events for desktop
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();

      if (index === 0) {
        zoomToCenter(1.2);
      } else if (index === 1) {
        zoomToCenter(0.8);
      } else {
        resetZoom();
      }
    });

    // Hover effects for desktop
    btn.addEventListener("mouseenter", () => {
      btn.style.background = "#f0f8ff";
    });
    btn.addEventListener("mouseleave", () => {
      btn.style.background = "white";
    });
  });

  controlsDiv.appendChild(zoomInBtn);
  controlsDiv.appendChild(zoomOutBtn);
  controlsDiv.appendChild(resetBtn);
  container.appendChild(controlsDiv);
}

function zoomToPoint(scale, point) {
  const container = currentContainer || document.getElementById("svgContainer");
  if (!container) return;

  const newZoom = Math.max(0.1, Math.min(30, zoom * scale));

  // Zoom towards the specified point
  panX = point.x - (point.x - panX) * scale;
  panY = point.y - (point.y - panY) * scale;
  zoom = newZoom;

  if (updateTransformFunction) {
    updateTransformFunction();
  }
}
// Zoom to center of viewport
function zoomToCenter(scale) {
  const container = currentContainer || document.getElementById("svgContainer");
  if (!container) return;

  const rect = container.getBoundingClientRect();
  const centerX = rect.width / 2;
  const centerY = rect.height / 2;

  const newZoom = Math.max(0.1, Math.min(30, zoom * scale));

  // Zoom towards center
  panX = centerX - (centerX - panX) * scale;
  panY = centerY - (centerY - panY) * scale;
  zoom = newZoom;

  if (updateTransformFunction) {
    updateTransformFunction();
  }
}

// Reset zoom and pan
function resetZoom() {
  zoom = 1;
  panX = 0;
  panY = 0;

  if (updateTransformFunction) {
    updateTransformFunction();
  }
}

// SECTION 8: UTILITY AND HELPER FUNCTIONS
// ===================================================================================
/**
 * Displays a popup menu with zone links for a clicked region, arranged in a grid.
 * @param {string} regionKey The key for the clicked region (e.g., 'Atieh').
 * @param {MouseEvent} event The click event object.
 */
function showZoneSelectionMenu(regionKey, event) {
  closeAllForms(); // Assumes this function exists to close other popups
  const zones = window.regionToZoneMap[regionKey];

  if (!zones || zones.length === 0) {
    console.warn(`No zones found in regionToZoneMap for key: ${regionKey}`);
    return;
  }

  const menu = document.createElement("div");
  menu.id = "zoneSelectionMenu";

  const regionConfig = window.svgGroupConfig[regionKey];
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
      loadAndDisplaySVG(zone.svgFile); // The path is already correct
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

  // Position the menu based on the actual mouse click coordinates.
  menu.style.top = `${event.pageY + 10}px`;
  menu.style.left = `${event.pageX}px`;

  // Ensure it doesn't render off-screen
  const menuRect = menu.getBoundingClientRect();
  if (menuRect.right > window.innerWidth) {
    menu.style.left = `${window.innerWidth - menuRect.width - 20}px`;
  }

  setTimeout(
    () =>
      document.addEventListener("click", closeZoneMenuOnClickOutside, {
        once: true,
      }),
    0
  );
}

/**
 * Removes the zone selection menu from the page.
 */
function closeZoneSelectionMenu() {
  const menu = document.getElementById("zoneSelectionMenu");
  if (menu) menu.remove();
  document.removeEventListener("click", closeZoneMenuOnClickOutside);
}

/**
 * Handles clicks outside the zone menu to close it.
 */
function closeZoneMenuOnClickOutside(event) {
  const menu = document.getElementById("zoneSelectionMenu");
  if (menu && !menu.contains(event.target)) {
    closeZoneSelectionMenu();
  }
}

function updateSelectionCount() {
  const countElement = document.getElementById("selectionCount");
  if (countElement) {
    countElement.textContent = selectedElements.size;
  }

  const batchStageSelect = document.getElementById("batch_stage");
  if (!batchStageSelect) return;

  if (selectedElements.size === 0) {
    batchStageSelect.innerHTML =
      '<option value="">ابتدا المان انتخاب کنید</option>';
    batchStageSelect.disabled = true;
    return;
  }

  const elements = [...selectedElements.values()];
  const firstElementType = elements[0].element_type;
  const allSameType = elements.every(
    (el) => el.element_type === firstElementType
  );

  if (!allSameType) {
    batchStageSelect.innerHTML =
      '<option value="">المان‌ها باید از یک نوع باشند</option>';
    batchStageSelect.disabled = true;
    return;
  }

  batchStageSelect.disabled = false;
  batchStageSelect.innerHTML = '<option value="">در حال بارگذاری...</option>';

  fetch(`/ghom/api/get_stages.php?type=${firstElementType}`)
    .then((res) => res.json())
    .then((stages) => {
      if (stages && stages.length > 0) {
        batchStageSelect.innerHTML = stages
          .map((s) => `<option value="${s.stage_id}">${s.stage}</option>`)
          .join("");
      } else {
        batchStageSelect.innerHTML =
          '<option value="">مرحله‌ای تعریف نشده</option>';
        batchStageSelect.disabled = true;
      }
    });
}

function clearAllSelections() {
  document
    .querySelectorAll(".element-selected")
    .forEach((el) => el.classList.remove("element-selected"));
  selectedElements.clear();
}

function getElementData(element) {
  let coords = { x: 0, y: 0 };
  try {
    const bbox = element.getBBox();
    coords.x = bbox.x + bbox.width / 2;
    coords.y = bbox.y + bbox.height / 2;
  } catch (e) {
    // Ignore errors for hidden or complex elements
  }

  // This now returns ALL relevant data for the element
  return {
    element_id: element.dataset.uniqueId,
    element_type: element.dataset.elementType,
    zone_name: currentPlanZoneName,
    axis_span: element.dataset.axisSpan,
    floor_level: element.dataset.floorLevel,
    contractor: element.dataset.contractor,
    block: element.dataset.block,
    plan_file: currentPlanFileName,
    x_coord: coords.x,
    y_coord: coords.y,
    panel_orientation: element.dataset.panelOrientation,
  };
}

function getElementSpatialContext(element) {
  let axisSpan = "N/A",
    floorLevel = "N/A";
  try {
    const elBBox = element.getBBox();
    const elCenterX = elBBox.x + elBBox.width / 2;
    const elCenterY = elBBox.y + elBBox.height / 2;
    let leftMarker = null,
      rightMarker = null;
    currentSvgAxisMarkersX.forEach((marker) => {
      if (marker.x <= elCenterX) leftMarker = marker;
      if (marker.x >= elCenterX && !rightMarker) rightMarker = marker;
    });
    if (leftMarker && rightMarker) {
      axisSpan =
        leftMarker.text === rightMarker.text
          ? leftMarker.text
          : `${leftMarker.text}-${rightMarker.text}`;
    }
    let belowMarker = null;
    currentSvgAxisMarkersY.forEach((marker) => {
      if (marker.y >= elCenterY && (!belowMarker || marker.y < belowMarker.y))
        belowMarker = marker;
    });
    if (belowMarker) floorLevel = belowMarker.text;
  } catch (e) {}
  return { axisSpan, floorLevel, derivedId: null };
}

function extractAllAxisMarkers(svgElement) {
  currentSvgAxisMarkersX = [];
  currentSvgAxisMarkersY = [];
  const allTexts = Array.from(svgElement.querySelectorAll("text"));
  const viewBoxHeight = svgElement.viewBox.baseVal?.height || 2200;
  const Y_EDGE_THRESHOLD = viewBoxHeight * 0.2;
  allTexts.forEach((textEl) => {
    try {
      const content = textEl.textContent.trim();
      if (!content) return;
      const bbox = textEl.getBBox();
      if (
        bbox.y < Y_EDGE_THRESHOLD ||
        bbox.y + bbox.height > viewBoxHeight - Y_EDGE_THRESHOLD
      ) {
        currentSvgAxisMarkersX.push({
          text: content,
          x: bbox.x + bbox.width / 2,
          y: bbox.y,
        });
      } else {
        currentSvgAxisMarkersY.push({
          text: content,
          x: bbox.x,
          y: bbox.y + bbox.height / 2,
        });
      }
    } catch (e) {}
  });
  currentSvgAxisMarkersX.sort((a, b) => a.x - b.x);
  currentSvgAxisMarkersY.sort((a, b) => a.y - b.y);
}

function closeAllForms() {
  closeZoneSelectionMenu();
  // Add any other form/popup closing logic for this page here
}
