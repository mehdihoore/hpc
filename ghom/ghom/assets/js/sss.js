// ===================================================================================
//
//            public_html\ghom\assets\js\shared_svg_logic.js - MOBILE BATCH SELECTION & ZOOM FIX
//
// Fixed mobile batch selection with long press and improved zoom controls
//
// ===================================================================================

document.addEventListener("DOMContentLoaded", () => {
  // This listener will run AFTER the inline script has loaded the config.

  // Create a new function to wait for the global config to be ready.
  const waitForConfigAndStart = () => {
    // Check if the global variables set by the loader exist
    if (window.svgGroupConfig && window.regionToZoneMap) {
      console.log("Configuration is ready. Starting main application logic.");

      // Attach all your static event listeners here
      document
        .getElementById("backToPlanBtn")
        ?.addEventListener("click", () => loadAndDisplaySVG("/ghom/Plan.svg"));

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

      // Initialize date pickers
      if (typeof jalaliDatepicker !== "undefined") {
        jalaliDatepicker.startWatch();
      }

      // Load the initial SVG, which will now have access to the config
      loadAndDisplaySVG("/ghom/Plan.svg");
    } else {
      // If the config isn't ready, wait a moment and check again.
      setTimeout(waitForConfigAndStart, 100);
    }
  };

  // Start the process
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

// Device detection
const isMobile =
  /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(
    navigator.userAgent
  ) ||
  "ontouchstart" in window ||
  navigator.maxTouchPoints > 0;

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

  selectedElements.clear();
  updateSelectionCount();

  svgContainer.innerHTML = "";
  svgContainer.classList.add("loading");
  currentPlanFileName = svgPath; // Store the full path for later use

  // --- FIX #1: Extract the base filename for reliable lookups ---
  const baseFilename = svgPath.substring(svgPath.lastIndexOf("/") + 1);
  const isPlanFile = baseFilename.toLowerCase() === "plan.svg";

  // --- Set up the main page UI ---
  const regionZoneNav = document.getElementById("regionZoneNavContainer");
  const backBtn = document.getElementById("backToPlanBtn");
  if (regionZoneNav) regionZoneNav.style.display = isPlanFile ? "flex" : "none";
  if (backBtn) backBtn.style.display = isPlanFile ? "none" : "block";

  // --- Correctly find the info for the current view ---
  let zoneInfo = isPlanFile
    ? planNavigationMappings.find(
        (m) =>
          m.svgFile && m.svgFile.toLowerCase() === baseFilename.toLowerCase()
      )
    : getRegionAndZoneInfoForFile(svgPath);

  // --- Populate the info bar with the found data ---
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
    zoneInfoContainer.style.display = "block"; // Make it visible
  }

  fetch(svgPath)
    .then((response) => {
      svgContainer.classList.remove("loading");
      if (!response.ok)
        throw new Error(`Network error loading ${baseFilename}`);
      return response.text();
    })
    .then((svgData) => {
      svgContainer.innerHTML = svgData;
      currentSvgElement = svgContainer.querySelector("svg");
      if (!currentSvgElement)
        throw new Error("SVG element not found in fetched data.");

      // --- FIX #2: Apply styles and interactivity to ALL SVGs ---
      // This function must run for the Plan.svg to make regions clickable and colored.
      applyGroupStylesAndControls(currentSvgElement);

      // Run other setup tasks
      setupZoomAndPan(svgContainer, currentSvgElement);
      setupInteractionHandlers(svgContainer);

      // Run tasks specific to the type of SVG
      if (isPlanFile) {
        setupPlanNavigation(); // This sets up the dropdowns
      } else {
        extractAllAxisMarkers(currentSvgElement); // Only needed for zones
      }

      showMobileInstructions();
    })
    .catch((error) => {
      svgContainer.innerHTML = `<p style="color:red; font-weight:bold;">خطا در بارگزاری نقشه: ${error.message}</p>`;
      if (zoneInfoContainer) zoneInfoContainer.style.display = "none";
      console.error("Error in loadAndDisplaySVG:", error);
    });
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
  if (!regionSelect.dataset.initialized) {
    regionSelect.innerHTML =
      '<option value="">-- ابتدا یک محدوده انتخاب کنید --</option>';

    if (
      typeof regionToZoneMap !== "undefined" &&
      typeof svgGroupConfig !== "undefined"
    ) {
      // Iterate through the keys of the region map ('Atieh', 'AranB', etc.)
      for (const regionKey in regionToZoneMap) {
        const option = document.createElement("option");
        option.value = regionKey;

        // --- THIS IS THE CRITICAL FIX ---
        // We get the readable Persian label from svgGroupConfig using the regionKey.
        // If it doesn't exist, we fall back to the key itself.
        option.textContent = svgGroupConfig[regionKey]?.label || regionKey;

        regionSelect.appendChild(option);
      }
    }
    regionSelect.dataset.initialized = "true";
  }

  // The event listener logic for when a region is selected is correct and remains.
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
        // Ensure the path is correctly constructed
        button.addEventListener("click", () =>
          loadAndDisplaySVG(SVG_BASE_PATH + zone.svgFile)
        );
        zoneButtonsContainer.appendChild(button);
      });
    }
  });
}

function applyGroupStylesAndControls(svgElement) {
  // Find the container we added in the HTML
  const layerControlsContainer = document.getElementById(
    "layerControlsContainer"
  );
  if (!layerControlsContainer) return; // Exit if the container isn't on the page

  layerControlsContainer.innerHTML = ""; // Clear any old buttons

  if (typeof svgGroupConfig === "undefined") {
    console.error(
      "svgGroupConfig is not defined. Make sure it's loaded before the script."
    );
    return;
  }

  for (const groupId in svgGroupConfig) {
    const config = svgGroupConfig[groupId];
    const groupElement = svgElement.getElementById(groupId);

    if (groupElement) {
      // Apply initial visibility
      groupElement.style.display = config.defaultVisible ? "" : "none";

      // This is the new part that was missing: creating the buttons
      const button = document.createElement("button");
      button.textContent = config.label;
      button.className = config.defaultVisible ? "active" : "inactive";

      button.addEventListener("click", () => {
        const isVisible = groupElement.style.display !== "none";
        groupElement.style.display = isVisible ? "none" : "";
        button.className = !isVisible ? "active" : "inactive";
      });

      layerControlsContainer.appendChild(button);

      // The original logic for making elements interactive is still here
      if (config.interactive && config.elementType) {
        initializeElementsByType(groupElement, config.elementType, groupId);
      }
    }
  }
}

function initializeElementsByType(groupElement, elementType, groupId) {
  // Select all potential shapes within the group
  const elements = groupElement.querySelectorAll("path, rect, circle, polygon");

  elements.forEach((el) => {
    // --- THIS IS THE CRITICAL FIX ---
    // Only process elements that have a non-empty ID attribute from the SVG file.
    // This stops the script from creating fallback IDs like "GFRC_123".
    if (el.id) {
      // If it has a real ID, make it interactive.
      el.classList.add("interactive-element");

      // Use its existing ID as the unique identifier for data submission.
      el.dataset.uniqueId = el.id;
      el.dataset.elementType = elementType;

      // The rest of your logic to get context is correct.
      const spatialCtx = getElementSpatialContext(el);
      el.dataset.axisSpan = spatialCtx.axisSpan;
      el.dataset.floorLevel = spatialCtx.floorLevel;
      el.dataset.contractor = currentPlanDefaultContractor;
      el.dataset.block = currentPlanDefaultBlock;

      if (elementType === "GFRC") {
        try {
          const dims = el.getBBox();
          el.dataset.panelOrientation =
            dims.width > dims.height ? "Horizontal" : "Vertical";
        } catch (e) {
          // Ignore BBox errors for hidden elements
        }
      }
    }
    // If the element has no ID, we do nothing and it remains non-interactive.
  });
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

function submitBatchUpdate() {
  if (selectedElements.size === 0) {
    alert("لطفا ابتدا یک یا چند المان را انتخاب کنید.");
    return;
  }

  const statusEl = document.getElementById("batch_status");
  const notesEl = document.getElementById("batch_notes");
  const dateEl = document.getElementById("batch_date");

  const payload = {
    elements_data: Array.from(selectedElements.values()),
    status: statusEl ? statusEl.value : "",
    notes: notesEl ? notesEl.value : "",
    date: dateEl ? dateEl.value : "",
  };

  const submitBtn = document.getElementById("submitBatchUpdate");
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.textContent = "در حال ثبت...";
  }

  fetch("/ghom/api/batch_update_status.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  })
    .then((res) => res.json())
    .then((data) => {
      alert(data.message);
      if (data.status === "success") {
        clearAllSelections();
        updateSelectionCount();
      }
    })
    .catch((error) => alert(`خطای ارتباطی: ${error.message}`))
    .finally(() => {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = "ثبت برای موارد انتخابی";
      }
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

function updateSelectionCount() {
  const countElement = document.getElementById("selectionCount");
  if (countElement) {
    countElement.textContent = selectedElements.size;
  }
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

function closeAllForms() {} // Empty placeholder
