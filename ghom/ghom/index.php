<?php
// public_html/ghom/index.php

// Include the central bootstrap file
require_once __DIR__ . '/../sercon/bootstrap.php';
// require_once __DIR__ . '/includes/jdf.php';
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

<main style="flex: 1; padding: var(--md-spacing-lg);">
    <div class="content-container" style="max-width: 1200px; margin: 0 auto;">
        
        <!-- Welcome Card -->
        <div class="md-card md-fade-in">
            <div class="md-card-header">
                <h2 class="md-card-title">خوش آمدید به سامانه بازرسی نما</h2>
                <p class="md-card-subtitle">پروژه بیمارستان هزار تخت خوابی قم</p>
            </div>
            <div class="md-card-content">
                <p>این سامانه برای مدیریت و کنترل کیفیت نماهای ساختمان طراحی شده است. شما می‌توانید از طریق منوهای بالا به بخش‌های مختلف دسترسی داشته باشید.</p>
            </div>
        </div>

        <!-- Quick Stats Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: var(--md-spacing-lg); margin: var(--md-spacing-xl) 0;">
            
            <!-- Zone Navigation Card -->
            <div class="md-card md-slide-in-right">
                <div class="md-card-header">
                    <h3 class="md-card-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="margin-left: 0.5rem;">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                        </svg>
                        ناوبری نقشه
                    </h3>
                </div>
                <div class="md-card-content">
                    <p>مشاهده نقشه‌های مختلف پروژه و انتخاب نواحی مورد نظر</p>
                    
                    <!-- Zone Selection -->
                    <div class="md-text-field">
                        <select class="md-text-field__input" id="zoneSelect" onchange="loadZone()">
                            <option value="">انتخاب ناحیه</option>
                            <option value="Zone01">ناحیه ۱</option>
                            <option value="Zone02">ناحیه ۲</option>
                            <option value="Zone03">ناحیه ۳</option>
                            <option value="Zone04">ناحیه ۴</option>
                            <option value="Zone05">ناحیه ۵</option>
                            <option value="Zone06">ناحیه ۶</option>
                            <option value="Zone07">ناحیه ۷</option>
                            <option value="Zone08">ناحیه ۸</option>
                            <option value="Zone09">ناحیه ۹</option>
                            <option value="Zone10">ناحیه ۱۰</option>
                            <option value="Zone11">ناحیه ۱۱</option>
                        </select>
                        <label class="md-text-field__label">انتخاب ناحیه</label>
                    </div>
                </div>
                <div class="md-card-actions">
                    <button class="md-button md-button--contained" onclick="showPlan()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                        مشاهده نقشه کلی
                    </button>
                </div>
            </div>

            <!-- Current Zone Info Card -->
            <div class="md-card md-slide-in-right" id="currentZoneCard" style="display: none;">
                <div class="md-card-header">
                    <h3 class="md-card-title">اطلاعات ناحیه فعلی</h3>
                </div>
                <div class="md-card-content">
                    <div style="display: flex; flex-direction: column; gap: var(--md-spacing-sm);">
                        <div class="md-status-chip md-status-chip--info">
                            <strong>ناحیه:</strong> <span id="zoneNameDisplay">-</span>
                        </div>
                        <div class="md-status-chip md-status-chip--info">
                            <strong>پیمانکار:</strong> <span id="zoneContractorDisplay">-</span>
                        </div>
                        <div class="md-status-chip md-status-chip--info">
                            <strong>بلوک:</strong> <span id="zoneBlockDisplay">-</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Layer Controls Card -->
            <div class="md-card md-slide-in-right">
                <div class="md-card-header">
                    <h3 class="md-card-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="margin-left: 0.5rem;">
                            <path d="M12 16l-5-5h3V4h4v7h3l-5 5z"/>
                            <path d="M5 20v-2h14v2H5z"/>
                        </svg>
                        کنترل لایه‌ها
                    </h3>
                </div>
                <div class="md-card-content">
                    <div style="display: flex; flex-wrap: wrap; gap: var(--md-spacing-xs);">
                        <button class="md-button md-button--outlined layer-btn active" data-layer="all" onclick="toggleLayer('all', this)">
                            همه
                        </button>
                        <button class="md-button md-button--outlined layer-btn" data-layer="gfrc" onclick="toggleLayer('gfrc', this)">
                            GFRC
                        </button>
                        <button class="md-button md-button--outlined layer-btn" data-layer="curtain_wall" onclick="toggleLayer('curtain_wall', this)">
                            کرتین وال
                        </button>
                        <button class="md-button md-button--outlined layer-btn" data-layer="window_wall" onclick="toggleLayer('window_wall', this)">
                            ویندو وال
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- SVG Container Card -->
        <div class="md-card" id="svgCard" style="display: none;">
            <div class="md-card-header">
                <h3 class="md-card-title">نقشه پروژه</h3>
                <div class="md-card-actions" style="margin: 0;">
                    <button class="md-button md-button--text" onclick="zoomIn()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                            <path d="M12 10h-2v2H9v-2H7V9h2V7h1v2h2v1z"/>
                        </svg>
                        بزرگ‌نمایی
                    </button>
                    <button class="md-button md-button--text" onclick="zoomOut()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                            <path d="M7 9v1h5V9H7z"/>
                        </svg>
                        کوچک‌نمایی
                    </button>
                    <button class="md-button md-button--text" onclick="resetZoom()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/>
                            <path d="M8 12h8v-1H8v1z"/>
                        </svg>
                        بازنشانی
                    </button>
                </div>
            </div>
            <div class="md-card-content" style="padding: 0;">
                <div id="svgContainer" style="width: 100%; height: 60vh; background: var(--md-background); border: 1px solid rgba(0,0,0,0.12); overflow: hidden; position: relative; cursor: grab;">
                    <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: rgba(0,0,0,0.6);">
                        <div style="text-align: center;">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor" style="opacity: 0.5; margin-bottom: 1rem;">
                                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                            </svg>
                            <p>برای مشاهده نقشه، ابتدا یک ناحیه انتخاب کنید</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="md-card">
            <div class="md-card-header">
                <h3 class="md-card-title">دسترسی سریع</h3>
            </div>
            <div class="md-card-content">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--md-spacing-md);">
                    
                    <?php if (hasAccess(['admin', 'superuser', 'user', 'cat', 'car', 'coa', 'crs'])): ?>
                    <a href="contractor_batch_update.php" class="md-button md-button--outlined" style="height: auto; padding: var(--md-spacing-md); text-align: center; text-decoration: none;">
                        <div>
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" style="margin-bottom: 0.5rem;">
                                <polygon points="3 7 12 2 21 7 12 12 3 7" />
                                <polyline points="3 12 12 17 21 12" />
                                <polyline points="3 17 12 22 21 17" />
                            </svg>
                            <div>به‌روزرسانی پیمانکار</div>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if (hasAccess(['admin', 'superuser', 'user', 'cat', 'car', 'coa', 'crs'])): ?>
                    <a href="inspection_dashboard.php" class="md-button md-button--outlined" style="height: auto; padding: var(--md-spacing-md); text-align: center; text-decoration: none;">
                        <div>
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" style="margin-bottom: 0.5rem;">
                                <rect x="3" y="3" width="7" height="7" rx="1" ry="1" />
                                <rect x="14" y="3" width="7" height="7" rx="1" ry="1" />
                                <rect x="3" y="14" width="7" height="7" rx="1" ry="1" />
                                <rect x="14" y="14" width="7" height="7" rx="1" ry="1" />
                            </svg>
                            <div>داشبورد بازرسی</div>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if (hasAccess(['admin', 'superuser', 'user', 'cat', 'car', 'coa', 'crs'])): ?>
                    <a href="reports.php" class="md-button md-button--outlined" style="height: auto; padding: var(--md-spacing-md); text-align: center; text-decoration: none;">
                        <div>
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" style="margin-bottom: 0.5rem;">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14,2 14,8 20,8" />
                                <line x1="16" y1="13" x2="8" y2="13" />
                                <line x1="16" y1="17" x2="8" y2="17" />
                                <polyline points="10,9 9,9 8,9" />
                            </svg>
                            <div>گزارشات</div>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if (hasAccess(['admin', 'superuser'])): ?>
                    <a href="checklist_manager.php" class="md-button md-button--outlined" style="height: auto; padding: var(--md-spacing-md); text-align: center; text-decoration: none;">
                        <div>
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" style="margin-bottom: 0.5rem;">
                                <path d="M9 6h11" />
                                <path d="M9 12h11" />
                                <path d="M9 18h11" />
                                <path d="M4 6l1.5 1.5L8 5" />
                                <path d="M4 12l1.5 1.5L8 11" />
                                <path d="M4 18l1.5 1.5L8 17" />
                            </svg>
                            <div>مدیریت چک‌لیست‌ها</div>
                        </div>
                    </a>
                    <?php endif; ?>

                </div>
            </div>
        </div>

    </div>
</main>

<!-- Floating Action Button for Quick Access -->
<button class="md-fab" onclick="showQuickActions()" data-tooltip="دسترسی سریع">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
    </svg>
</button>

<script src="/ghom/assets/js/shared_svg_logic.js"></script>
<script src="/ghom/assets/js/interact.min.js"></script>
<script src="/ghom/assets/js/jalalidatepicker.min.js"></script>

<script>
// Global variables
let currentZoom = 1;
let currentPanX = 0;
let currentPanY = 0;
let isDragging = false;
let dragStartX = 0;
let dragStartY = 0;

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Show welcome animation
    setTimeout(() => {
        document.querySelector('.md-card').classList.add('md-fade-in');
    }, 100);
});

// Zone loading function
function loadZone() {
    const zoneSelect = document.getElementById('zoneSelect');
    const selectedZone = zoneSelect.value;
    
    if (!selectedZone) {
        document.getElementById('svgCard').style.display = 'none';
        document.getElementById('currentZoneCard').style.display = 'none';
        return;
    }

    // Show loading indicator
    showCircularProgress('#svgContainer');
    
    // Load SVG
    const svgContainer = document.getElementById('svgContainer');
    const svgPath = `/${selectedZone}.svg`;
    
    fetch(svgPath)
        .then(response => response.text())
        .then(svgContent => {
            svgContainer.innerHTML = svgContent;
            document.getElementById('svgCard').style.display = 'block';
            
            // Update zone info
            updateZoneInfo(selectedZone);
            
            // Initialize SVG interactions
            initializeSVGInteractions();
            
            // Show success message
            showSnackbar(`ناحیه ${selectedZone} با موفقیت بارگذاری شد`);
        })
        .catch(error => {
            console.error('Error loading zone:', error);
            showSnackbar('خطا در بارگذاری ناحیه', 3000, {
                text: 'تلاش مجدد',
                handler: () => loadZone()
            });
        })
        .finally(() => {
            hideCircularProgress('#svgContainer');
        });
}

// Update zone information
function updateZoneInfo(zoneName) {
    document.getElementById('zoneNameDisplay').textContent = zoneName;
    document.getElementById('zoneContractorDisplay').textContent = 'در حال بارگذاری...';
    document.getElementById('zoneBlockDisplay').textContent = 'در حال بارگذاری...';
    document.getElementById('currentZoneCard').style.display = 'block';
    
    // Fetch zone details (you can implement this based on your data structure)
    // For now, using placeholder data
    setTimeout(() => {
        document.getElementById('zoneContractorDisplay').textContent = 'پیمانکار نمونه';
        document.getElementById('zoneBlockDisplay').textContent = 'بلوک A';
    }, 500);
}

// Show plan function
function showPlan() {
    const svgContainer = document.getElementById('svgContainer');
    
    fetch('/Plan.svg')
        .then(response => response.text())
        .then(svgContent => {
            svgContainer.innerHTML = svgContent;
            document.getElementById('svgCard').style.display = 'block';
            
            // Reset zone selection
            document.getElementById('zoneSelect').value = '';
            document.getElementById('currentZoneCard').style.display = 'none';
            
            initializeSVGInteractions();
            showSnackbar('نقشه کلی پروژه نمایش داده شد');
        })
        .catch(error => {
            console.error('Error loading plan:', error);
            showSnackbar('خطا در بارگذاری نقشه کلی');
        });
}

// Layer control functions
function toggleLayer(layerType, button) {
    const layerButtons = document.querySelectorAll('.layer-btn');
    
    if (layerType === 'all') {
        layerButtons.forEach(btn => {
            btn.classList.remove('md-button--contained');
            btn.classList.add('md-button--outlined');
        });
        button.classList.remove('md-button--outlined');
        button.classList.add('md-button--contained');
        
        // Show all elements
        const svgElements = document.querySelectorAll('#svgContainer svg *');
        svgElements.forEach(el => el.style.display = '');
    } else {
        // Toggle individual layer
        const isActive = button.classList.contains('md-button--contained');
        
        if (isActive) {
            button.classList.remove('md-button--contained');
            button.classList.add('md-button--outlined');
        } else {
            button.classList.remove('md-button--outlined');
            button.classList.add('md-button--contained');
        }
        
        // Update 'all' button
        const allButton = document.querySelector('.layer-btn[data-layer="all"]');
        allButton.classList.remove('md-button--contained');
        allButton.classList.add('md-button--outlined');
        
        // Filter elements based on active layers
        filterSVGElements();
    }
}

// Filter SVG elements based on active layers
function filterSVGElements() {
    const activeLayerButtons = document.querySelectorAll('.layer-btn.md-button--contained:not([data-layer="all"])');
    const activeLayers = Array.from(activeLayerButtons).map(btn => btn.dataset.layer);
    
    const svgElements = document.querySelectorAll('#svgContainer svg *[data-type]');
    svgElements.forEach(el => {
        const elementType = el.dataset.type;
        if (activeLayers.length === 0 || activeLayers.includes(elementType)) {
            el.style.display = '';
        } else {
            el.style.display = 'none';
        }
    });
}

// Zoom functions
function zoomIn() {
    currentZoom = Math.min(currentZoom * 1.2, 5);
    updateSVGTransform();
}

function zoomOut() {
    currentZoom = Math.max(currentZoom / 1.2, 0.1);
    updateSVGTransform();
}

function resetZoom() {
    currentZoom = 1;
    currentPanX = 0;
    currentPanY = 0;
    updateSVGTransform();
}

function updateSVGTransform() {
    const svg = document.querySelector('#svgContainer svg');
    if (svg) {
        svg.style.transform = `translate(${currentPanX}px, ${currentPanY}px) scale(${currentZoom})`;
    }
}

// Initialize SVG interactions
function initializeSVGInteractions() {
    const svgContainer = document.getElementById('svgContainer');
    const svg = svgContainer.querySelector('svg');
    
    if (!svg) return;
    
    // Add pan functionality
    svgContainer.addEventListener('mousedown', startDrag);
    svgContainer.addEventListener('mousemove', drag);
    svgContainer.addEventListener('mouseup', endDrag);
    svgContainer.addEventListener('mouseleave', endDrag);
    
    // Add wheel zoom
    svgContainer.addEventListener('wheel', (e) => {
        e.preventDefault();
        if (e.deltaY < 0) {
            zoomIn();
        } else {
            zoomOut();
        }
    });
    
    // Add click handlers for interactive elements
    const interactiveElements = svg.querySelectorAll('[data-element-id]');
    interactiveElements.forEach(element => {
        element.style.cursor = 'pointer';
        element.addEventListener('click', (e) => {
            e.stopPropagation();
            const elementId = element.dataset.elementId;
            showElementDetails(elementId);
        });
        
        // Add hover effects
        element.addEventListener('mouseenter', () => {
            element.style.filter = 'brightness(1.2)';
        });
        
        element.addEventListener('mouseleave', () => {
            element.style.filter = '';
        });
    });
}

// Drag functions
function startDrag(e) {
    isDragging = true;
    dragStartX = e.clientX - currentPanX;
    dragStartY = e.clientY - currentPanY;
    document.getElementById('svgContainer').style.cursor = 'grabbing';
}

function drag(e) {
    if (!isDragging) return;
    
    currentPanX = e.clientX - dragStartX;
    currentPanY = e.clientY - dragStartY;
    updateSVGTransform();
}

function endDrag() {
    isDragging = false;
    document.getElementById('svgContainer').style.cursor = 'grab';
}

// Show element details
function showElementDetails(elementId) {
    MaterialDesign.showDialog(
        'جزئیات المان',
        `<p>شناسه المان: <strong>${elementId}</strong></p>
         <p>در حال بارگذاری اطلاعات...</p>`,
        [
            {
                text: 'بستن',
                handler: () => {}
            },
            {
                text: 'مشاهده جزئیات',
                primary: true,
                handler: () => {
                    window.location.href = `view_element_history.php?element_id=${elementId}`;
                }
            }
        ]
    );
}

// Quick actions
function showQuickActions() {
    const actions = [
        { text: 'به‌روزرسانی پیمانکار', url: 'contractor_batch_update.php' },
        { text: 'داشبورد بازرسی', url: 'inspection_dashboard.php' },
        { text: 'گزارشات', url: 'reports.php' }
    ];
    
    let actionsHtml = '<div style="display: flex; flex-direction: column; gap: 1rem;">';
    actions.forEach(action => {
        actionsHtml += `<a href="${action.url}" class="md-button md-button--outlined" style="text-decoration: none;">${action.text}</a>`;
    });
    actionsHtml += '</div>';
    
    MaterialDesign.showDialog(
        'دسترسی سریع',
        actionsHtml,
        [
            {
                text: 'بستن',
                handler: () => {}
            }
        ]
    );
}

// Touch support for mobile
document.addEventListener('touchstart', function(e) {
    if (e.target.closest('#svgContainer')) {
        e.preventDefault();
    }
});

document.addEventListener('touchmove', function(e) {
    if (e.target.closest('#svgContainer')) {
        e.preventDefault();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
</body>
</html>

