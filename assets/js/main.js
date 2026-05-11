/**
 * Main JavaScript for Grant Review System
 * TAG: SPEC-UI-001-MAIN
 *
 * Design-TAG: Integration point for all UI components
 * Function-TAG: Initialize and coordinate UI systems
 * Test-TAG: Manual testing of all integrated features
 */

/**
 * Form Leave Guard System (SPEC-UIX-002 Milestone 4)
 *
 * Features:
 * - Opt-in via data-track-dirty attribute
 * - Visual "unsaved changes" indicator
 * - Smart exemptions for filters and successful saves
 * - Per-form dirty state tracking
 */
var FormLeaveGuard = (function() {
    'use strict';

    var trackedForms = {};

    function init() {
        var forms = document.querySelectorAll('form[data-track-dirty]');
        forms.forEach(setupForm);
    }

    function setupForm(form) {
        var formId = form.id || 'form-' + Math.random().toString(36).substr(2, 9);
        form.id = formId;

        trackedForms[formId] = {
            element: form,
            isDirty: false,
            exempt: false
        };

        // Track changes on inputs
        var inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
            // Skip filter/search inputs
            if (input.hasAttribute('data-no-track')) {
                return;
            }

            input.addEventListener('change', function() {
                markDirty(formId);
            });

            input.addEventListener('input', function() {
                if (input.type === 'text' || input.type === 'textarea' || input.type === 'search') {
                    markDirty(formId);
                }
            });
        });

        // Clear dirty state on successful submit
        form.addEventListener('submit', function(e) {
            // Check if form has validation errors
            if (!form.checkValidity()) {
                return;
            }

            // Mark as clean - will be re-marked if submit fails
            markClean(formId);
            trackedForms[formId].exempt = true;
        });

        // Add visual indicator
        addDirtyIndicator(form);
    }

    function markDirty(formId) {
        if (!trackedForms[formId] || trackedForms[formId].exempt) {
            return;
        }

        trackedForms[formId].isDirty = true;
        updateDirtyIndicator(formId, true);
    }

    function markClean(formId) {
        if (!trackedForms[formId]) {
            return;
        }

        trackedForms[formId].isDirty = false;
        trackedForms[formId].exempt = false;
        updateDirtyIndicator(formId, false);
    }

    function addDirtyIndicator(form) {
        var indicator = document.createElement('div');
        indicator.className = 'form-dirty-indicator';
        indicator.innerHTML = '<span class="badge badge-warning">You have unsaved changes</span>';
        indicator.style.display = 'none';
        indicator.setAttribute('aria-live', 'polite');
        form.insertBefore(indicator, form.firstChild);
    }

    function updateDirtyIndicator(formId, isDirty) {
        var form = trackedForms[formId].element;
        var indicator = form.querySelector('.form-dirty-indicator');

        if (indicator) {
            indicator.style.display = isDirty ? 'block' : 'none';
        }

        document.body.classList.toggle('has-unsaved-changes', isDirty);
    }

    function hasDirtyForms() {
        return Object.keys(trackedForms).some(function(formId) {
            return trackedForms[formId].isDirty;
        });
    }

    function handleBeforeUnload(e) {
        if (hasDirtyForms()) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    }

    // Public API
    return {
        init: init,
        markClean: markClean,
        markDirty: markDirty,
        hasDirtyForms: hasDirtyForms,
        handleBeforeUnload: handleBeforeUnload
    };
})();

// Auto-scroll chat to bottom
document.addEventListener('DOMContentLoaded', function() {
    // Initialize form leave guard
    FormLeaveGuard.init();

    // Setup beforeunload handler
    window.addEventListener('beforeunload', FormLeaveGuard.handleBeforeUnload);

    // Auto-scroll chat containers
    const chatContainers = document.querySelectorAll('.chat-container');
    chatContainers.forEach(function(container) {
        container.scrollTop = container.scrollHeight;
    });

    // Auto-refresh discussion every 30 seconds (if on review page)
    if (window.location.pathname.includes('review_application.php')) {
        // Optional: implement AJAX refresh for discussions
    }
});

/**
 * Confirm action using new modal system
 * @param {string} message - Confirmation message
 * @returns {boolean} True if confirmed
 */
function confirmAction(message) {
    // Use new AGR.Modal if available
    if (typeof AGR !== 'undefined' && AGR.Modal) {
        // For async confirmation, use: AGR.Modal.confirm(message)
        // For legacy sync code, fall back to native confirm
        return confirm(message || 'Are you sure?');
    }
    return confirm(message || 'Are you sure?');
}

/**
 * Show notification using new toast system
 * @param {string} message - Notification message
 * @param {string} type - Notification type (success, error, warning, info)
 */
function showNotification(message, type) {
    // Use new AGR.Toast if available
    if (typeof AGR !== 'undefined' && AGR.Toast) {
        type = type || 'info';
        if (AGR.Toast[type]) {
            AGR.Toast[type](message);
        } else {
            AGR.Toast.show(message, type);
        }
    } else {
        // Fallback to old implementation
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-' + (type || 'info');
        alertDiv.textContent = message;
        alertDiv.style.position = 'fixed';
        alertDiv.style.top = '20px';
        alertDiv.style.right = '20px';
        alertDiv.style.zIndex = '9999';
        alertDiv.style.minWidth = '300px';

        document.body.appendChild(alertDiv);

        setTimeout(function() {
            alertDiv.style.opacity = '0';
            alertDiv.style.transition = 'opacity 0.5s';
            setTimeout(function() {
                if (alertDiv.parentNode) {
                    document.body.removeChild(alertDiv);
                }
            }, 500);
        }, 3000);
    }
}

/**
 * Show loading state on button
 * @param {HTMLButtonElement} button - Button element
 * @param {string} originalText - Original button text (optional)
 */
function setButtonLoading(button, originalText) {
    if (!button) return;

    // Store original text if not already stored
    if (!button.hasAttribute('data-original-text')) {
        button.setAttribute('data-original-text', originalText || button.textContent);
    }

    // Add loading class
    button.classList.add('is-loading');
    button.disabled = true;

    // Update aria attributes
    button.setAttribute('aria-busy', 'true');
    button.setAttribute('aria-live', 'polite');
}

/**
 * Remove loading state from button
 * @param {HTMLButtonElement} button - Button element
 */
function removeButtonLoading(button) {
    if (!button) return;

    // Remove loading class
    button.classList.remove('is-loading');
    button.disabled = false;

    // Restore original text
    if (button.hasAttribute('data-original-text')) {
        button.textContent = button.getAttribute('data-original-text');
        button.removeAttribute('data-original-text');
    }

    // Update aria attributes
    button.removeAttribute('aria-busy');
    button.removeAttribute('aria-live');
}

/**
 * Create loading overlay for element
 * @param {HTMLElement} element - Element to overlay
 * @returns {HTMLElement} Overlay element
 */
function createLoadingOverlay(element) {
    const overlay = document.createElement('div');
    overlay.className = 'agr-loading-overlay';

    const spinner = document.createElement('div');
    spinner.className = 'agr-spinner';
    spinner.setAttribute('role', 'status');
    spinner.setAttribute('aria-label', 'Loading...');

    overlay.appendChild(spinner);
    element.appendChild(overlay);

    return overlay;
}

/**
 * Remove loading overlay from element
 * @param {HTMLElement} element - Element with overlay
 */
function removeLoadingOverlay(element) {
    const overlay = element.querySelector('.agr-loading-overlay');
    if (overlay) {
        overlay.remove();
    }
}

/**
 * Show success message with toast
 * @param {string} message - Success message
 */
function showSuccess(message) {
    if (typeof AGR !== 'undefined' && AGR.Toast) {
        AGR.Toast.success(message);
    } else {
        showNotification(message, 'success');
    }
}

/**
 * Show error message with toast
 * @param {string} message - Error message
 */
function showError(message) {
    if (typeof AGR !== 'undefined' && AGR.Toast) {
        AGR.Toast.error(message);
    } else {
        showNotification(message, 'error');
    }
}

/**
 * Show warning message with toast
 * @param {string} message - Warning message
 */
function showWarning(message) {
    if (typeof AGR !== 'undefined' && AGR.Toast) {
        AGR.Toast.warning(message);
    } else {
        showNotification(message, 'warning');
    }
}

/**
 * Show info message with toast
 * @param {string} message - Info message
 */
function showInfo(message) {
    if (typeof AGR !== 'undefined' && AGR.Toast) {
        AGR.Toast.info(message);
    } else {
        showNotification(message, 'info');
    }
}

// Export functions for global use
window.AGRHelpers = {
    confirmAction,
    showNotification,
    showSuccess,
    showError,
    showWarning,
    showInfo,
    setButtonLoading,
    removeButtonLoading,
    createLoadingOverlay,
    removeLoadingOverlay
};
