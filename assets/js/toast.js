/**
 * AGR.UI Toast Notification System
 * TAG: SPEC-UI-001-TOAST
 *
 * Design-TAG: Non-intrusive notification system replacing showNotification()
 * Function-TAG: Display success, error, warning, and info messages
 * Test-TAG: Tested via manual interaction and screen reader compatibility
 *
 * WCAG 2.1 AA Compliance:
 * - ARIA live region for screen reader announcements
 * - Keyboard dismissible (Escape key)
 * - Sufficient color contrast (WCAG AA)
 * - Auto-dismiss with configurable duration
 * - Maximum 5 toasts visible at once
 */

var AGR = AGR || {};

/**
 * Toast notification manager
 */
AGR.Toast = (function() {
    'use strict';

    const MAX_TOASTS = 5;
    const DEFAULT_DURATION = 3000;
    const ANIMATION_DURATION = 200;

    let container = null;
    let activeToasts = [];

    /**
     * Initialize toast container
     * Creates DOM element for toast notifications
     */
    function initContainer() {
        if (container) return;

        container = document.createElement('div');
        container.id = 'agr-toast-container';
        container.className = 'agr-toast-container';
        container.setAttribute('role', 'region');
        container.setAttribute('aria-live', 'polite');
        container.setAttribute('aria-label', 'Notification messages');
        document.body.appendChild(container);
    }

    /**
     * Create toast element
     * @param {string} message - Toast message
     * @param {string} type - Toast type (success, error, warning, info)
     * @returns {HTMLElement} Toast element
     */
    function createToast(message, type) {
        const toast = document.createElement('div');
        toast.className = `agr-toast agr-toast-${type}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
        toast.textContent = message;

        // Add icon based on type
        const icon = getIconForType(type);
        if (icon) {
            const iconSpan = document.createElement('span');
            iconSpan.className = 'agr-toast-icon';
            iconSpan.setAttribute('aria-hidden', 'true');
            iconSpan.innerHTML = icon;
            toast.insertBefore(iconSpan, toast.firstChild);
        }

        // Add close button
        const closeButton = document.createElement('button');
        closeButton.className = 'agr-toast-close';
        closeButton.setAttribute('type', 'button');
        closeButton.setAttribute('aria-label', 'Close notification');
        closeButton.innerHTML = '&times;';
        closeButton.addEventListener('click', () => dismissToast(toast));
        toast.appendChild(closeButton);

        return toast;
    }

    /**
     * Get icon HTML for toast type
     * @param {string} type - Toast type
     * @returns {string} Icon HTML
     */
    function getIconForType(type) {
        const icons = {
            success: '<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>',
            error: '<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>',
            warning: '<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>',
            info: '<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>'
        };
        return icons[type] || icons.info;
    }

    /**
     * Show toast notification
     * @param {string} message - Toast message
     * @param {string} type - Toast type (success, error, warning, info)
     * @param {number} duration - Auto-dismiss duration in ms (0 for no auto-dismiss)
     */
    function show(message, type = 'info', duration = DEFAULT_DURATION) {
        initContainer();

        // Remove oldest toast if at max capacity
        if (activeToasts.length >= MAX_TOASTS) {
            dismissToast(activeToasts[0]);
        }

        const toast = createToast(message, type);
        container.appendChild(toast);
        activeToasts.push(toast);

        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.add('agr-toast-show');
        });

        // Announce to screen readers
        AGR.Utils.announceToScreenReader(`${type}: ${message}`, type === 'error' ? 'assertive' : 'polite');

        // Auto-dismiss if duration > 0
        if (duration > 0) {
            setTimeout(() => {
                dismissToast(toast);
            }, duration);
        }

        return toast;
    }

    /**
     * Dismiss toast notification
     * @param {HTMLElement} toast - Toast element to dismiss
     */
    function dismissToast(toast) {
        if (!toast || !toast.parentNode) return;

        toast.classList.remove('agr-toast-show');
        toast.classList.add('agr-toast-hide');

        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
            activeToasts = activeToasts.filter(t => t !== toast);
        }, ANIMATION_DURATION);
    }

    /**
     * Dismiss all toasts
     */
    function dismissAll() {
        activeToasts.forEach(toast => dismissToast(toast));
    }

    /**
     * Dismiss most recent toast
     */
    function dismissMostRecent() {
        if (activeToasts.length > 0) {
            dismissToast(activeToasts[activeToasts.length - 1]);
        }
    }

    /**
     * Handle keyboard events
     * Escape key dismisses most recent toast
     */
    function handleKeyDown(e) {
        if (e.key === 'Escape') {
            dismissMostRecent();
        }
    }

    // Initialize keyboard handler
    document.addEventListener('keydown', handleKeyDown);

    // Convenience methods
    function success(message, duration) {
        return show(message, 'success', duration);
    }

    function error(message, duration = 0) {
        // Error toasts don't auto-dismiss by default
        return show(message, 'error', duration);
    }

    function warning(message, duration) {
        return show(message, 'warning', duration);
    }

    function info(message, duration) {
        return show(message, 'info', duration);
    }

    // Public API
    return {
        show,
        success,
        error,
        warning,
        info,
        dismiss: dismissToast,
        dismissAll,
        dismissMostRecent
    };
})();
