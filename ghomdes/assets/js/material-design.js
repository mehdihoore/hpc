/**
 * Material Design JavaScript Components
 * For Ghom Hospital Project
 * RTL Support
 */

class MaterialDesign {
  constructor() {
    this.init();
  }

  init() {
    this.initRippleEffect();
    this.initNavigationDrawer();
    this.initSnackbar();
    this.initTextFields();
    this.initTabs();
    this.initTooltips();
    this.initProgressIndicators();
    this.initScrollEffects();
  }

  // Ripple Effect for Buttons
  initRippleEffect() {
    const buttons = document.querySelectorAll('.md-button, .md-card, .md-navigation-drawer__item');
    
    buttons.forEach(button => {
      button.addEventListener('click', (e) => {
        const ripple = document.createElement('span');
        const rect = button.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height);
        const x = e.clientX - rect.left - size / 2;
        const y = e.clientY - rect.top - size / 2;
        
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = x + 'px';
        ripple.style.top = y + 'px';
        ripple.classList.add('md-ripple');
        
        button.appendChild(ripple);
        
        setTimeout(() => {
          ripple.remove();
        }, 600);
      });
    });

    // Add ripple CSS
    if (!document.querySelector('#md-ripple-styles')) {
      const style = document.createElement('style');
      style.id = 'md-ripple-styles';
      style.textContent = `
        .md-ripple {
          position: absolute;
          border-radius: 50%;
          background: rgba(255, 255, 255, 0.6);
          transform: scale(0);
          animation: md-ripple-animation 0.6s linear;
          pointer-events: none;
        }
        
        @keyframes md-ripple-animation {
          to {
            transform: scale(4);
            opacity: 0;
          }
        }
        
        .md-button, .md-card, .md-navigation-drawer__item {
          position: relative;
          overflow: hidden;
        }
      `;
      document.head.appendChild(style);
    }
  }

  // Navigation Drawer
  initNavigationDrawer() {
    const drawer = document.querySelector('.md-navigation-drawer');
    const overlay = document.querySelector('.md-drawer-overlay');
    const toggleButton = document.querySelector('.md-drawer-toggle');
    const closeButton = document.querySelector('.md-drawer-close');

    if (!drawer) return;

    // Create overlay if it doesn't exist
    if (!overlay) {
      const newOverlay = document.createElement('div');
      newOverlay.className = 'md-drawer-overlay';
      newOverlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1100;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
      `;
      document.body.appendChild(newOverlay);
    }

    const actualOverlay = document.querySelector('.md-drawer-overlay');

    const openDrawer = () => {
      drawer.classList.add('open');
      actualOverlay.style.opacity = '1';
      actualOverlay.style.visibility = 'visible';
      document.body.style.overflow = 'hidden';
    };

    const closeDrawer = () => {
      drawer.classList.remove('open');
      actualOverlay.style.opacity = '0';
      actualOverlay.style.visibility = 'hidden';
      document.body.style.overflow = '';
    };

    if (toggleButton) {
      toggleButton.addEventListener('click', openDrawer);
    }

    if (closeButton) {
      closeButton.addEventListener('click', closeDrawer);
    }

    if (actualOverlay) {
      actualOverlay.addEventListener('click', closeDrawer);
    }

    // Close on escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && drawer.classList.contains('open')) {
        closeDrawer();
      }
    });
  }

  // Snackbar
  initSnackbar() {
    window.showSnackbar = (message, duration = 3000, action = null) => {
      const snackbar = document.createElement('div');
      snackbar.className = 'md-snackbar';
      
      const messageSpan = document.createElement('span');
      messageSpan.textContent = message;
      snackbar.appendChild(messageSpan);

      if (action) {
        const actionButton = document.createElement('button');
        actionButton.className = 'md-button md-button--text';
        actionButton.textContent = action.text;
        actionButton.style.color = 'var(--md-secondary)';
        actionButton.style.marginRight = '16px';
        actionButton.addEventListener('click', () => {
          action.handler();
          hideSnackbar();
        });
        snackbar.appendChild(actionButton);
      }

      document.body.appendChild(snackbar);

      // Show snackbar
      setTimeout(() => {
        snackbar.classList.add('show');
      }, 100);

      const hideSnackbar = () => {
        snackbar.classList.remove('show');
        setTimeout(() => {
          if (snackbar.parentNode) {
            snackbar.parentNode.removeChild(snackbar);
          }
        }, 300);
      };

      // Auto hide
      setTimeout(hideSnackbar, duration);

      return hideSnackbar;
    };
  }

  // Text Fields
  initTextFields() {
    const textFields = document.querySelectorAll('.md-text-field__input');
    
    textFields.forEach(input => {
      // Handle focus and blur for floating labels
      const handleFocus = () => {
        input.parentElement.classList.add('focused');
      };
      
      const handleBlur = () => {
        if (!input.value) {
          input.parentElement.classList.remove('focused');
        }
      };

      input.addEventListener('focus', handleFocus);
      input.addEventListener('blur', handleBlur);

      // Check if field has initial value
      if (input.value) {
        input.parentElement.classList.add('focused');
      }
    });
  }

  // Tabs
  initTabs() {
    const tabGroups = document.querySelectorAll('.md-tabs');
    
    tabGroups.forEach(tabGroup => {
      const tabs = tabGroup.querySelectorAll('.md-tab');
      const panels = document.querySelectorAll('.md-tab-panel');
      
      tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
          // Remove active class from all tabs and panels
          tabs.forEach(t => t.classList.remove('active'));
          panels.forEach(p => p.classList.remove('active'));
          
          // Add active class to clicked tab and corresponding panel
          tab.classList.add('active');
          if (panels[index]) {
            panels[index].classList.add('active');
          }
        });
      });
    });
  }

  // Tooltips
  initTooltips() {
    const tooltipElements = document.querySelectorAll('[data-tooltip]');
    
    tooltipElements.forEach(element => {
      const tooltipText = element.getAttribute('data-tooltip');
      
      element.addEventListener('mouseenter', (e) => {
        const tooltip = document.createElement('div');
        tooltip.className = 'md-tooltip';
        tooltip.textContent = tooltipText;
        tooltip.style.cssText = `
          position: absolute;
          background: #616161;
          color: white;
          padding: 8px 12px;
          border-radius: 4px;
          font-size: 12px;
          z-index: 1400;
          pointer-events: none;
          opacity: 0;
          transition: opacity 0.15s ease;
        `;
        
        document.body.appendChild(tooltip);
        
        const rect = element.getBoundingClientRect();
        tooltip.style.top = (rect.top - tooltip.offsetHeight - 8) + 'px';
        tooltip.style.left = (rect.left + rect.width / 2 - tooltip.offsetWidth / 2) + 'px';
        
        setTimeout(() => {
          tooltip.style.opacity = '1';
        }, 10);
        
        element._tooltip = tooltip;
      });
      
      element.addEventListener('mouseleave', () => {
        if (element._tooltip) {
          element._tooltip.style.opacity = '0';
          setTimeout(() => {
            if (element._tooltip && element._tooltip.parentNode) {
              element._tooltip.parentNode.removeChild(element._tooltip);
            }
            element._tooltip = null;
          }, 150);
        }
      });
    });
  }

  // Progress Indicators
  initProgressIndicators() {
    // Linear progress
    window.setLinearProgress = (selector, value) => {
      const progressBar = document.querySelector(selector + ' .md-progress-linear__bar');
      if (progressBar) {
        progressBar.style.width = value + '%';
      }
    };

    // Circular progress
    window.showCircularProgress = (selector) => {
      const container = document.querySelector(selector);
      if (container) {
        const progress = document.createElement('div');
        progress.className = 'md-progress-circular';
        container.appendChild(progress);
      }
    };

    window.hideCircularProgress = (selector) => {
      const container = document.querySelector(selector);
      if (container) {
        const progress = container.querySelector('.md-progress-circular');
        if (progress) {
          progress.remove();
        }
      }
    };
  }

  // Scroll Effects
  initScrollEffects() {
    const appBar = document.querySelector('.md-app-bar');
    let lastScrollTop = 0;
    
    if (appBar) {
      window.addEventListener('scroll', () => {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        
        // Add elevation on scroll
        if (scrollTop > 0) {
          appBar.classList.add('elevated');
        } else {
          appBar.classList.remove('elevated');
        }
        
        // Hide/show app bar on scroll
        if (scrollTop > lastScrollTop && scrollTop > 100) {
          appBar.style.transform = 'translateY(-100%)';
        } else {
          appBar.style.transform = 'translateY(0)';
        }
        
        lastScrollTop = scrollTop;
      });
    }
  }

  // Utility Functions
  static showDialog(title, content, actions = []) {
    const dialog = document.createElement('div');
    dialog.className = 'md-dialog-overlay';
    dialog.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1500;
      opacity: 0;
      transition: opacity 0.3s ease;
    `;

    const dialogContent = document.createElement('div');
    dialogContent.className = 'md-dialog';
    dialogContent.style.cssText = `
      background: white;
      border-radius: 8px;
      min-width: 320px;
      max-width: 500px;
      box-shadow: 0 24px 38px rgba(0,0,0,0.14);
      transform: scale(0.8);
      transition: transform 0.3s ease;
    `;

    const dialogHeader = document.createElement('div');
    dialogHeader.style.cssText = 'padding: 24px 24px 16px; border-bottom: 1px solid rgba(0,0,0,0.12);';
    dialogHeader.innerHTML = `<h6 style="margin: 0; font-size: 1.25rem; font-weight: 500;">${title}</h6>`;

    const dialogBody = document.createElement('div');
    dialogBody.style.cssText = 'padding: 16px 24px;';
    dialogBody.innerHTML = content;

    const dialogActions = document.createElement('div');
    dialogActions.style.cssText = 'padding: 8px 16px 16px; display: flex; justify-content: flex-end; gap: 8px;';

    actions.forEach(action => {
      const button = document.createElement('button');
      button.className = `md-button ${action.primary ? 'md-button--contained' : 'md-button--text'}`;
      button.textContent = action.text;
      button.addEventListener('click', () => {
        action.handler();
        closeDialog();
      });
      dialogActions.appendChild(button);
    });

    dialogContent.appendChild(dialogHeader);
    dialogContent.appendChild(dialogBody);
    dialogContent.appendChild(dialogActions);
    dialog.appendChild(dialogContent);
    document.body.appendChild(dialog);

    const closeDialog = () => {
      dialog.style.opacity = '0';
      dialogContent.style.transform = 'scale(0.8)';
      setTimeout(() => {
        if (dialog.parentNode) {
          dialog.parentNode.removeChild(dialog);
        }
      }, 300);
    };

    // Show dialog
    setTimeout(() => {
      dialog.style.opacity = '1';
      dialogContent.style.transform = 'scale(1)';
    }, 10);

    // Close on overlay click
    dialog.addEventListener('click', (e) => {
      if (e.target === dialog) {
        closeDialog();
      }
    });

    // Close on escape key
    const escapeHandler = (e) => {
      if (e.key === 'Escape') {
        closeDialog();
        document.removeEventListener('keydown', escapeHandler);
      }
    };
    document.addEventListener('keydown', escapeHandler);

    return closeDialog;
  }

  static showConfirm(title, message, onConfirm, onCancel) {
    return MaterialDesign.showDialog(title, message, [
      {
        text: 'لغو',
        handler: onCancel || (() => {})
      },
      {
        text: 'تأیید',
        primary: true,
        handler: onConfirm
      }
    ]);
  }

  // Form Validation
  static validateForm(formSelector) {
    const form = document.querySelector(formSelector);
    if (!form) return false;

    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;

    requiredFields.forEach(field => {
      const fieldContainer = field.closest('.md-text-field') || field.parentElement;
      
      if (!field.value.trim()) {
        fieldContainer.classList.add('md-error');
        isValid = false;
      } else {
        fieldContainer.classList.remove('md-error');
      }
    });

    // Add error styles if not exist
    if (!document.querySelector('#md-form-validation-styles')) {
      const style = document.createElement('style');
      style.id = 'md-form-validation-styles';
      style.textContent = `
        .md-error .md-text-field__input {
          border-color: var(--md-error) !important;
        }
        .md-error .md-text-field__label {
          color: var(--md-error) !important;
        }
      `;
      document.head.appendChild(style);
    }

    return isValid;
  }

  // Data Table Enhancements
  static enhanceDataTable(tableSelector) {
    const table = document.querySelector(tableSelector);
    if (!table) return;

    // Add sorting functionality
    const headers = table.querySelectorAll('th[data-sortable]');
    headers.forEach(header => {
      header.style.cursor = 'pointer';
      header.addEventListener('click', () => {
        const column = header.dataset.sortable;
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        
        const isAscending = header.classList.contains('sort-asc');
        
        // Remove sort classes from all headers
        headers.forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
        
        // Add appropriate sort class
        header.classList.add(isAscending ? 'sort-desc' : 'sort-asc');
        
        // Sort rows
        rows.sort((a, b) => {
          const aVal = a.children[header.cellIndex].textContent.trim();
          const bVal = b.children[header.cellIndex].textContent.trim();
          
          if (isAscending) {
            return bVal.localeCompare(aVal, 'fa');
          } else {
            return aVal.localeCompare(bVal, 'fa');
          }
        });
        
        // Reorder rows in DOM
        rows.forEach(row => tbody.appendChild(row));
      });
    });

    // Add row selection
    const checkboxes = table.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(checkbox => {
      checkbox.addEventListener('change', () => {
        const row = checkbox.closest('tr');
        if (checkbox.checked) {
          row.classList.add('selected');
        } else {
          row.classList.remove('selected');
        }
      });
    });
  }
}

// Initialize Material Design when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
  new MaterialDesign();
});

// Export for use in other scripts
window.MaterialDesign = MaterialDesign;

