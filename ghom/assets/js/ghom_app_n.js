///public_html/ghom/assets/js/ghom_app_n.js
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
const STATUS_COLORS = {
  OK: "rgba(12, 135, 8, 0.7)", // Green
  "Not OK": "rgba(220, 53, 69, 0.7)", // Red
  "Ready for Inspection": "rgba(167, 29, 135, 0.7)", // Yellow
  Pending: "rgba(108, 117, 125, 0.4)", // Grey, slightly transparent
};
let currentPlanData = {};
const svgGroupConfig = {
  GFRC: {
    label: "GFRC",
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
  },
  org: {
    label: "بلوک - اورژانس A- آتیه نما",
    color: "#ebb00d",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت آتیه نما",
    block: "A - اورژانس",
    elementType: "Region",
  },
  AranB: {
    label: "بلوک B-آرانسج",
    color: "#38abee",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت آرانسج",
    block: "B",
    elementType: "Region",
  },
  AranC: {
    label: "بلوک C-آرانسج",
    color: "#ee3838",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت آرانسج",
    block: "C",
    elementType: "Region",
  },
  hayatOmran: {
    label: " حیاط عمران آذرستان",
    color: "#eef595da",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت عمران آذرستان",
    block: "حیاط",
    elementType: "Region",
  },
  hayatRos: {
    label: " حیاط رس",
    color: "#eb0de7da",
    defaultVisible: true,
    interactive: true,
    contractor: "شرکت ساختمانی رس",
    block: "حیاط",
    elementType: "Region",
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
//</editor-fold>

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
  clearActiveSvgElementHighlight();
}

function closeForm(formId) {
  document.getElementById(formId).style.display = "none";
  clearActiveSvgElementHighlight();
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
    // Fallback for unknown orientation
    openGfrcChecklistForm(
      clickedElement.dataset.uniquePanelId,
      "Default",
      dynamicContext
    );
    return;
  }

  const menu = document.createElement("div");
  menu.id = "gfrcSubPanelMenu";
  subPanelIds.forEach((partName) => {
    const menuItem = document.createElement("button");
    // Create a more specific ID, e.g., FF-01(AT)-Face
    const subPanelId = `${clickedElement.dataset.uniquePanelId}-${partName}`;
    menuItem.textContent = `چک لیست: ${partName}`;
    menuItem.onclick = (e) => {
      e.stopPropagation();
      openGfrcChecklistForm(
        clickedElement.dataset.uniquePanelId,
        partName,
        dynamicContext
      );

      closeGfrcSubPanelMenu();
    };
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

  // Add a listener to close the menu when clicking elsewhere
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
function openGfrcChecklistForm(elementId, partName, dynamicContext) {
  const formPopup = document.getElementById("gfrcChecklistForm");
  const formContentContainer = document.getElementById("gfrc-form-content");
  if (!formPopup || !formContentContainer) return;

  const fullElementId = `${elementId}-${partName}`;
  formContentContainer.innerHTML = "<p>در حال بارگذاری...</p>";
  formPopup.style.display = "block";

  const queryParams = new URLSearchParams({
    element_id: fullElementId,
    element_type: dynamicContext.elementType,
  });

  fetch(`${SVG_BASE_PATH}api/get_element_data.php?${queryParams.toString()}`)
    .then((res) => res.json())
    .then((data) => {
      if (data.error) throw new Error(data.error);
      if (!data.items || data.items.length === 0) {
        throw new Error("قالب چک لیست GFRC یافت نشد.");
      }

      const stages = data.items.reduce((acc, item) => {
        const stageName = item.stage || "موارد کلی";
        if (!acc[stageName]) acc[stageName] = [];
        acc[stageName].push(item);
        return acc;
      }, {});
      const stageNames = Object.keys(stages);

      const tabButtonsHTML = stageNames
        .map(
          (stageName, index) =>
            `<button type="button" class="gfrc-tab-button" data-tab="gfrc-stage-${index}">${escapeHtml(
              stageName
            )}</button>`
        )
        .join("");
      const tabContentsHTML = stageNames
        .map((stageName, index) => {
          const itemsHTML = stages[stageName]
            .map(
              (item) => `
                  <div class="checklist-item-row">
                      <label class="item-text">${escapeHtml(
                        item.item_text
                      )}</label>
                      <div class="status-selector">
                          <label><input type="radio" name="status_${
                            item.item_id
                          }" value="OK" ${
                item.item_status === "OK" ? "checked" : ""
              }> OK</label>
                          <label><input type="radio" name="status_${
                            item.item_id
                          }" value="Not OK" ${
                item.item_status === "Not OK" ? "checked" : ""
              }> NOK</label>
                          <label><input type="radio" name="status_${
                            item.item_id
                          }" value="N/A" ${
                !item.item_status || item.item_status === "N/A" ? "checked" : ""
              }> N/A</label>
                      </div>
                      <input type="text" class="checklist-input" data-item-id="${
                        item.item_id
                      }" value="${escapeHtml(item.item_value || "")}">
                  </div>`
            )
            .join("");
          return `<div id="gfrc-stage-${index}" class="gfrc-tab-content">${itemsHTML}</div>`;
        })
        .join("");

      const createLinks = (jsonString) => {
        if (!jsonString) return "<li>هیچ فایلی پیوست نشده است.</li>";
        try {
          const paths = JSON.parse(jsonString);
          if (!Array.isArray(paths) || paths.length === 0)
            return "<li>هیچ فایلی پیوست نشده است.</li>";
          return paths
            .map(
              (p) =>
                `<li><a href="${escapeHtml(p)}" target="_blank">${escapeHtml(
                  p.split("/").pop()
                )}</a></li>`
            )
            .join("");
        } catch (e) {
          return "<li>خطا در نمایش فایل‌ها.</li>";
        }
      };

      formContentContainer.innerHTML = `
              <input type="hidden" name="elementId" value="${fullElementId}">
              <input type="hidden" name="elementType" value="${
                dynamicContext.elementType
              }">
              <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ccc; padding-bottom: 10px; margin-bottom: 15px;">
                  <h3>چک لیست GFRC: ${escapeHtml(elementId)} (${escapeHtml(
        partName
      )})</h3>
                  <div style="text-align: left; font-size: 0.9em;"><p style="margin: 0;"><strong>موقعیت:</strong> بلوک ${escapeHtml(
                    dynamicContext.block
                  )}, زون ${escapeHtml(dynamicContext.zoneName)}</p></div>
              </div>
              <div class="gfrc-tab-buttons">${tabButtonsHTML}</div>
              <div class="gfrc-tabs-content-container">${tabContentsHTML}</div>
              
              <!-- CORRECTED UI: These sections are now outside the tab container -->
              <div class="form-sections-grid">
                  <fieldset id="consultant-section">
                      <legend>بخش مشاور</legend>
                      <div class="form-group"><label>تاریخ بازرسی:</label><input type="text" name="inspection_date" data-jdp readonly></div>
                      <div class="form-group"><label>یادداشت:</label><textarea name="notes"></textarea></div>
                      <div class="attachments-display-container"><strong>پیوست‌ها:</strong><ul class="consultant-attachments-list"></ul></div>
                      <div class="file-upload-container"><label>آپلود فایل جدید:</label><input type="file" name="attachments[]" multiple></div>
                  </fieldset>
                  <fieldset id="contractor-section">
                      <legend>بخش پیمانکار</legend>
                      <div class="form-group"><label>وضعیت:</label><select name="contractor_status"><option value="Pending">در حال اجرا</option><option value="Ready for Inspection">آماده برای بازرسی</option></select></div>
                      <div class="form-group"><label>تاریخ اعلام:</label><input type="text" name="contractor_date" data-jdp readonly></div>
                      <div class="form-group"><label>توضیحات:</label><textarea name="contractor_notes"></textarea></div>
                      <div class="attachments-display-container"><strong>پیوست‌ها:</strong><ul class="contractor-attachments-list"></ul></div>
                      <div class="file-upload-container"><label>آپلود فایل جدید:</label><input type="file" name="contractor_attachments[]" multiple></div>
                  </fieldset>
              </div>
              <div class="btn-container">
                  <button type="submit" class="btn save">ذخیره</button>
                  <button type="button" class="btn cancel" onclick="closeForm('gfrcChecklistForm')">بستن</button>
              </div>`;

      if (data.inspectionData) {
        const insp = data.inspectionData;
        formContentContainer.querySelector('[name="inspection_date"]').value =
          insp.inspection_date_jalali || "";
        formContentContainer.querySelector('[name="notes"]').value =
          insp.notes || "";
        formContentContainer.querySelector(
          ".consultant-attachments-list"
        ).innerHTML = createLinks(insp.attachments);
        formContentContainer.querySelector('[name="contractor_status"]').value =
          insp.contractor_status || "Pending";
        formContentContainer.querySelector('[name="contractor_date"]').value =
          insp.contractor_date_jalali || "";
        formContentContainer.querySelector('[name="contractor_notes"]').value =
          insp.contractor_notes || "";
        formContentContainer.querySelector(
          ".contractor-attachments-list"
        ).innerHTML = createLinks(insp.contractor_attachments);
      }

      const tabButtons =
        formContentContainer.querySelectorAll(".gfrc-tab-button");
      const tabContents =
        formContentContainer.querySelectorAll(".gfrc-tab-content");
      tabButtons.forEach((button) => {
        button.addEventListener("click", () => {
          tabButtons.forEach((btn) => btn.classList.remove("active"));
          tabContents.forEach((content) => content.classList.remove("active"));
          button.classList.add("active");
          formContentContainer
            .querySelector(`#${button.dataset.tab}`)
            .classList.add("active");
        });
      });
      if (tabButtons.length > 0) tabButtons[0].click();

      setFormState(formContentContainer, USER_ROLE);
      jalaliDatepicker.startWatch({
        selector: "#gfrcChecklistForm [data-jdp]",
      });
    })
    .catch((err) => {
      formContentContainer.innerHTML = `<h3>خطا</h3><p>${escapeHtml(
        err.message
      )}</p><button type="button" class="btn cancel" onclick="closeForm('gfrcChecklistForm')">بستن</button>`;
    });
}

function openUniversalChecklistForm(elementId, elementType, dynamicContext) {
  const formPopup = document.getElementById("universalChecklistForm");
  const formElement = document.getElementById("universal-form-element");
  if (!formPopup || !formElement) return;

  formElement.innerHTML = "<h3>در حال بارگذاری فرم...</h3>";
  formPopup.style.display = "block";

  const queryParams = new URLSearchParams({
    element_id: elementId,
    element_type: elementType,
  });

  fetch(`${SVG_BASE_PATH}api/get_element_data.php?${queryParams.toString()}`)
    .then((res) =>
      res.ok ? res.json() : Promise.reject("پاسخ شبکه ناموفق بود.")
    )
    .then((data) => {
      if (data.error) throw new Error(data.error);
      if (!data.items || data.items.length === 0) {
        throw new Error(
          `قالب چک لیست برای "${elementType}" یافت نشد یا خالی است.`
        );
      }

      const externalCheckItems = [
        "لهیدگی و خط وخش",
        "عدم کیفیت رنگ",
        "جانمایی لامل در موقعیت",
        "عدم وجود درز بین ترنزوم و مولیون",
        "لاستیک کشی",
        "کنترل جزئیات دودبندی",
        "کیفیت اجرای چسب یا کاورکپ",
      ];
      const remarksItem = data.items.find(
        (item) => item.item_text === "ملاحظات"
      );
      const glassItem = data.items.find((item) =>
        item.item_text.includes("شیشه")
      );
      const stagedItems = data.items
        .filter(
          (item) =>
            item.item_id !== remarksItem?.item_id &&
            item.item_id !== glassItem?.item_id
        )
        .reduce((acc, item) => {
          const stageName = item.stage || "کنترل های عمومی";
          if (!acc[stageName]) acc[stageName] = [];
          acc[stageName].push(item);
          return acc;
        }, {});

      const stagesHtml = Object.keys(stagedItems)
        .map((stageName) => {
          const itemsHtml = stagedItems[stageName]
            .map((item) => {
              const isExternal = externalCheckItems.includes(item.item_text);
              return `<div class="checklist-item-row ${
                isExternal ? "highlight-external" : ""
              }">
                              <label class="item-text">${escapeHtml(
                                item.item_text
                              )}</label>
                              <div class="status-selector">
                                  <label><input type="radio" name="status_${
                                    item.item_id
                                  }" value="OK" ${
                item.item_status === "OK" ? "checked" : ""
              }> OK</label>
                                  <label><input type="radio" name="status_${
                                    item.item_id
                                  }" value="Not OK" ${
                item.item_status === "Not OK" ? "checked" : ""
              }> NOK</label>
                                  <label><input type="radio" name="status_${
                                    item.item_id
                                  }" value="N/A" ${
                !item.item_status || item.item_status === "N/A" ? "checked" : ""
              }> N/A</label>
                              </div>
                              <input type="text" class="checklist-input" data-item-id="${
                                item.item_id
                              }" value="${escapeHtml(item.item_value || "")}">
                          </div>`;
            })
            .join("");
          return `<fieldset class="stage-fieldset"><legend>${escapeHtml(
            stageName
          )}</legend>${itemsHtml}</fieldset>`;
        })
        .join("");

      const glassHtml = glassItem
        ? `<fieldset class="stage-fieldset"><legend>کنترل شیشه</legend><div class="checklist-item-row"><label class="item-text">${escapeHtml(
            glassItem.item_text
          )}</label><input type="text" class="checklist-input" placeholder="A / B / C" data-item-id="${
            glassItem.item_id
          }" value="${escapeHtml(
            glassItem.item_value || ""
          )}"></div></fieldset>`
        : "";
      const remarksHtml = remarksItem
        ? `<fieldset class="stage-fieldset"><legend>ملاحظات</legend><div class="checklist-item-row"><textarea class="checklist-input" data-item-id="${
            remarksItem.item_id
          }" style="min-height: 80px;">${escapeHtml(
            remarksItem.item_value || ""
          )}</textarea></div></fieldset>`
        : "";

      const createLinks = (jsonString) => {
        if (!jsonString) return "<li>هیچ فایلی پیوست نشده است.</li>";
        try {
          const paths = JSON.parse(jsonString);
          if (!Array.isArray(paths) || paths.length === 0)
            return "<li>هیچ فایلی پیوست نشده است.</li>";
          return paths
            .map(
              (p) =>
                `<li><a href="${escapeHtml(p)}" target="_blank">${escapeHtml(
                  p.split("/").pop()
                )}</a></li>`
            )
            .join("");
        } catch (e) {
          return "<li>خطا در نمایش فایل‌ها.</li>";
        }
      };

      formElement.innerHTML = `
              <input type="hidden" name="elementId" value="${elementId}"><input type="hidden" name="elementType" value="${elementType}">
              <h3>چک لیست: ${escapeHtml(elementId)}</h3>
              <p><strong>موقعیت:</strong> بلوک ${escapeHtml(
                dynamicContext.block
              )}, زون ${escapeHtml(dynamicContext.zoneName)}, طبقه ${escapeHtml(
        dynamicContext.floorLevel || "N/A"
      )}</p>
              <div class="form-vertical-layout">${stagesHtml}${glassHtml}${remarksHtml}</div>
              <p class="highlight-note">توجه: قسمت های زرد رنگ از بیرون نما می بایست چک گردند</p>
              <div class="form-sections-grid">
                  <fieldset id="consultant-section">
                      <legend>بخش مشاور</legend>
                      <div class="form-group"><label>تاریخ بازرسی:</label><input type="text" name="inspection_date" data-jdp readonly></div>
                      <div class="form-group"><label>یادداشت:</label><textarea name="notes"></textarea></div>
                      <div class="attachments-display-container"><strong>پیوست‌ها:</strong><ul class="consultant-attachments-list"></ul></div>
                      <div class="file-upload-container"><label>آپلود فایل جدید:</label><input type="file" name="attachments[]" multiple></div>
                  </fieldset>
                  <fieldset id="contractor-section">
                      <legend>بخش پیمانکار</legend>
                      <div class="form-group"><label>وضعیت:</label><select name="contractor_status"><option value="Pending">در حال اجرا</option><option value="Ready for Inspection">آماده برای بازرسی</option></select></div>
                      <div class="form-group"><label>تاریخ اعلام:</label><input type="text" name="contractor_date" data-jdp readonly></div>
                      <div class="form-group"><label>توضیحات:</label><textarea name="contractor_notes"></textarea></div>
                      <div class="attachments-display-container"><strong>پیوست‌ها:</strong><ul class="contractor-attachments-list"></ul></div>
                      <div class="file-upload-container"><label>آپلود فایل جدید:</label><input type="file" name="contractor_attachments[]" multiple></div>
                  </fieldset>
              </div>
              <div class="btn-container"><button type="submit" class="btn save">ذخیره</button><button type="button" class="btn cancel" onclick="closeForm('universalChecklistForm')">بستن</button></div>`;

      if (data.inspectionData) {
        const insp = data.inspectionData;
        formElement.querySelector('[name="inspection_date"]').value =
          insp.inspection_date_jalali || "";
        formElement.querySelector('[name="notes"]').value = insp.notes || "";
        formElement.querySelector(".consultant-attachments-list").innerHTML =
          createLinks(insp.attachments);
        formElement.querySelector('[name="contractor_status"]').value =
          insp.contractor_status || "Pending";
        formElement.querySelector('[name="contractor_date"]').value =
          insp.contractor_date_jalali || "";
        formElement.querySelector('[name="contractor_notes"]').value =
          insp.contractor_notes || "";
        formElement.querySelector(".contractor-attachments-list").innerHTML =
          createLinks(insp.contractor_attachments);
      }

      setFormState(formElement, USER_ROLE);
      jalaliDatepicker.startWatch({
        selector: "#universalChecklistForm [data-jdp]",
      });
    })
    .catch((error) => {
      formElement.innerHTML = `<p style="color:red;"><b>خطا:</b> ${escapeHtml(
        error.message
      )}</p><button type="button" class="btn cancel" onclick="closeForm('universalChecklistForm')">بستن</button>`;
    });
}

function setFormState(formElement, role) {
  const consultant_section = formElement.querySelector("#consultant-section");
  const contractor_section = formElement.querySelector("#contractor-section");
  const saveButton = formElement.querySelector(".btn.save");

  // Disable both sections by default
  if (consultant_section) consultant_section.disabled = true;
  if (contractor_section) contractor_section.disabled = true;
  if (saveButton) saveButton.style.display = "none";

  // Enable sections based on role
  switch (role) {
    case "admin": // Admin can edit the consultant part
    case "supervisor": // Supervisor can also edit the consultant part
      if (consultant_section) consultant_section.disabled = false;
      if (saveButton) saveButton.style.display = "inline-block";
      break;
    case "contractor": // Contractor can ONLY edit their own part
      if (contractor_section) contractor_section.disabled = false;
      if (saveButton) saveButton.style.display = "inline-block";
      break;
    case "guest": // Guest can view but not edit
      // Everything remains disabled.
      break;
  }
}
//<editor-fold desc="SVG Initialization and Interaction">
function makeElementInteractive(element, groupId, elementId) {
  const config = svgGroupConfig[groupId];
  if (!config || !config.elementType) return;
  const elementType = config.elementType;

  element.classList.add("interactive-element");

  const clickHandler = (event) => {
    event.stopPropagation();
    closeAllForms();
    element.classList.add("svg-element-active");
    currentlyActiveSvgElement = element;

    let coords = { x: 0, y: 0 };
    try {
      const bbox = element.getBBox();
      coords.x = bbox.x + bbox.width / 2;
      coords.y = bbox.y + bbox.height / 2;
    } catch (e) {
      console.error("Could not calculate BBox for element:", element, e);
    }

    // --- THE CRITICAL FIX IS HERE ---
    // We now correctly separate the data for saving from the string for display.
    const dynamicContext = {
      contractor: element.dataset.contractor,
      block: element.dataset.block,
      axisSpan: element.dataset.axisSpan,
      floorLevel: element.dataset.floorLevel,
      zoneName: currentPlanZoneName,
      planFile: currentPlanFileName.split("/").pop(),
      panelOrientation: element.dataset.panelOrientation,
      elementType: elementType,
      coords: coords,
      areaString: `زون: ${currentPlanZoneName}, محور: ${element.dataset.axisSpan}, طبقه: ${element.dataset.floorLevel}`,
    };
    const panelIdForChecklist = element.dataset.uniquePanelId || elementId;

    if (elementType === "GFRC") {
      showGfrcSubPanelMenu(element, dynamicContext);
    } else {
      openUniversalChecklistForm(
        panelIdForChecklist,
        elementType,
        dynamicContext
      );
    }
  };
  element.addEventListener("click", clickHandler);
  addTouchClickSupport(element, clickHandler); // Ensures it works on mobile
}

function initializeElementsByType(groupElement, elementType, groupId) {
  const elements = groupElement.querySelectorAll(
    "path, rect, circle, polygon, polyline, line, ellipse"
  );
  elements.forEach((el, index) => {
    const elementId = el.id || `${groupId}_${index}`;
    el.dataset.generatedId = elementId;
    const spatialCtx = getElementSpatialContext(el);
    el.dataset.axisSpan = spatialCtx.axisSpan;
    el.dataset.floorLevel = spatialCtx.floorLevel;
    el.dataset.uniquePanelId =
      el.id || spatialCtx.derivedId || `${groupId}_elem_${index}`;
    el.dataset.contractor =
      groupElement.dataset.contractor || currentPlanDefaultContractor;
    el.dataset.block = groupElement.dataset.block || currentPlanDefaultBlock;

    if (elementType === "GFRC") {
      const dims = el.getBBox();
      el.dataset.panelOrientation = dims.width > dims.height ? "افقی" : "عمودی";
      el.style.fill =
        dims.width > dims.height
          ? "rgba(255, 160, 122, 0.7)"
          : "rgba(32, 178, 170, 0.7)";
    }
    makeElementInteractive(el, groupId, el.dataset.uniquePanelId);
  });
}

function applyGroupStylesAndControls(svgElement) {
  const layerControlsContainer = document.getElementById(
    "layerControlsContainer"
  );
  layerControlsContainer.innerHTML = "";
  for (const groupId in svgGroupConfig) {
    const config = svgGroupConfig[groupId];
    const groupElement = svgElement.getElementById(groupId);
    if (groupElement) {
      if (config.color && groupId !== "GFRC") {
        const elementsToColor = groupElement.querySelectorAll(
          "path, rect, circle, polygon, polyline, line, ellipse"
        );
        elementsToColor.forEach((el) => {
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
  regionSelect.addEventListener("change", function () {
    zoneButtonsContainer.innerHTML = "";
    const selectedRegionKey = this.value;
    if (selectedRegionKey && regionToZoneMap[selectedRegionKey]) {
      regionToZoneMap[selectedRegionKey].forEach((zone) => {
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
  document
    .getElementById("backToPlanBtn")
    .addEventListener("click", () =>
      loadAndDisplaySVG(SVG_BASE_PATH + "Plan.svg")
    );
  loadAndDisplaySVG(SVG_BASE_PATH + "Plan.svg");

  const gfrcForm = document.getElementById("gfrc-form-element");
  if (gfrcForm) {
    gfrcForm.addEventListener("submit", function (event) {
      event.preventDefault();
      const form = event.target;
      const formData = new FormData(form);
      const saveBtn = form.querySelector(".btn.save");

      // --- Correctly gather data from the complex form ---
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
      formData.delete("items[]"); // Ensure old format is removed
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
  } //end
  const universalForm = document.getElementById("universal-form-element");
  if (universalForm) {
    universalForm.addEventListener("submit", function (event) {
      event.preventDefault();
      const form = event.target;
      const formData = new FormData(form);
      const saveBtn = form.querySelector(".btn.save");

      // --- Correctly gather data, including the status buttons ---
      const itemsPayload = [];
      form.querySelectorAll(".checklist-input").forEach((input) => {
        const itemId = input.dataset.itemId;
        if (itemId) {
          const statusRadio = form.querySelector(
            `input[name="status_${itemId}"]:checked`
          );
          itemsPayload.push({
            itemId: itemId,
            status: statusRadio ? statusRadio.value : "N/A", // Get status from radio button
            value: input.value,
          });
        }
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
            closeForm("universalChecklistForm");
          } else {
            alert("خطا: " + data.message);
          }
        })
        .catch((err) => alert("خطای ارتباطی: " + err.message))
        .finally(() => {
          saveBtn.textContent = "ذخیره";
          saveBtn.disabled = false;
        });
    });
  }
  document
    .getElementById("backToPlanBtn")
    .addEventListener("click", () =>
      loadAndDisplaySVG(SVG_BASE_PATH + "Plan.svg")
    );
  loadAndDisplaySVG(SVG_BASE_PATH + "Plan.svg");
});

function loadAndDisplaySVG(svgFullFilename) {
  const svgContainer = document.getElementById("svgContainer");
  if (!svgContainer) {
    console.error("SVG container not found!");
    return;
  }
  closeAllForms();
  svgContainer.innerHTML = "";
  svgContainer.classList.add("loading");

  const baseFilename = svgFullFilename.substring(
    svgFullFilename.lastIndexOf("/") + 1
  );
  const isPlan = baseFilename.toLowerCase() === "plan.svg";
  document.getElementById("regionZoneNavContainer").style.display = isPlan
    ? "flex"
    : "none";
  if (isPlan) setupRegionZoneNavigationIfNeeded();

  currentPlanFileName = svgFullFilename;
  let zoneInfo = isPlan
    ? planNavigationMappings.find(
        (m) =>
          m.svgFile && m.svgFile.toLowerCase() === svgFullFilename.toLowerCase()
      )
    : getRegionAndZoneInfoForFile(svgFullFilename);

  currentPlanZoneName = zoneInfo?.label || baseFilename.replace(/\.svg$/i, "");
  currentPlanDefaultContractor =
    zoneInfo?.defaultContractor || zoneInfo?.contractor || "پیمانکار عمومی";
  currentPlanDefaultBlock =
    zoneInfo?.defaultBlock || zoneInfo?.block || "بلوک عمومی";

  const zoneInfoContainer = document.getElementById("currentZoneInfo");
  document.getElementById("zoneNameDisplay").textContent = currentPlanZoneName;
  document.getElementById("zoneContractorDisplay").textContent =
    currentPlanDefaultContractor;
  document.getElementById("zoneBlockDisplay").textContent =
    currentPlanDefaultBlock;
  zoneInfoContainer.style.display = "block";

  fetch(svgFullFilename)
    .then((response) => {
      svgContainer.classList.remove("loading");
      if (!response.ok) {
        throw new Error(`Failed to load SVG file: ${response.statusText}`);
      }
      return response.text();
    })
    .then((svgData) => {
      svgContainer.innerHTML = svgData;
      const zoomControlsHtml = `<div class="zoom-controls"><button id="zoomInBtn">+</button><button id="zoomOutBtn">-</button><button id="zoomResetBtn">⌂</button></div>`;
      svgContainer.insertAdjacentHTML("afterbegin", zoomControlsHtml);
      currentSvgElement = svgContainer.querySelector("svg");
      if (!currentSvgElement) {
        throw new Error("SVG element not found in the fetched data.");
      }

      // 3. Initialize the SVG and apply default styles
      resetZoomAndPan();
      setupZoomControls();
      extractAllAxisMarkers(currentSvgElement);
      applyGroupStylesAndControls(currentSvgElement);

      // 4. After setting defaults, fetch the dynamic statuses for this plan
      return fetch(`/ghom/api/get_plan_statuses.php?plan=${baseFilename}`);
    })
    .then((response) => {
      if (!response.ok) {
        console.warn(
          `Could not fetch statuses for ${baseFilename}. It might not have inspections yet.`
        );
        return {}; // Return an empty object to prevent errors
      }
      return response.json();
    })
    .then((statuses) => {
      // 5. Apply the status colors on top of the default styles
      if (currentSvgElement) {
        applyStatusColors(currentSvgElement, statuses);
      }
    })
    .catch((error) => {
      svgContainer.classList.remove("loading");
      svgContainer.innerHTML = `<p style="color:red; font-weight:bold;">خطا در بارگزاری نقشه: ${error.message}</p>`;
      console.error("Error in loadAndDisplaySVG:", error);
    });
}
//</editor-fold>
/**
 * Applies status-based colors to SVG elements.
 * @param {SVGElement} svgElement The parent SVG element.
 * @param {Object} statuses An object mapping element_id to its status string.
 */
function applyStatusColors(svgElement, statuses) {
  if (!statuses || Object.keys(statuses).length === 0) return;

  for (const elementId in statuses) {
    const status = statuses[elementId];
    const color = STATUS_COLORS[status];

    if (color) {
      // Find the element by its ID. Works for both <path> and the <g> from your python script
      const el = svgElement.getElementById(elementId);
      if (el) {
        // To color the main panel shape, we target the path inside it if it's a group
        const shape =
          el.tagName.toLowerCase() === "g"
            ? el.querySelector("path, rect, circle, polygon")
            : el;
        if (shape) {
          shape.style.fill = color;
          shape.style.fillOpacity = "0.8"; // Make status colors prominent
        }
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
