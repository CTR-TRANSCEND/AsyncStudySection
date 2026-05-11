/**
 * AGR.UI Utilities - Core utility functions for Grant Review System UI
 * TAG: SPEC-UI-001-UTILITIES
 *
 * Design-TAG: Utility functions for DOM manipulation, events, and accessibility
 * Function-TAG: Helper functions that support other UI components
 * Test-TAG: Functions are tested through their usage in other components
 *
 * WCAG 2.1 AA Compliance:
 * - Focus management functions preserve logical tab order
 * - ARIA attributes are set correctly for screen readers
 * - Keyboard event handlers follow accessibility patterns
 */

var AGR = AGR || {};

/**
 * Utility namespace for common functions
 */
AGR.Utils = (function() {
    'use strict';

    /**
     * Debounce function execution
     * Prevents excessive function calls during rapid events (scroll, resize, input)
     * @param {Function} func - Function to debounce
     * @param {number} wait - Milliseconds to wait (default: 250ms)
     * @returns {Function} Debounced function
     */
    function debounce(func, wait = 250) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Throttle function execution
     * Limits function execution rate (useful for scroll/resize)
     * @param {Function} func - Function to throttle
     * @param {number} limit - Milliseconds between executions (default: 100ms)
     * @returns {Function} Throttled function
     */
    function throttle(func, limit = 100) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }

    /**
     * Get all focusable elements within a container
     * Used for focus trapping in modals and dropdowns
     * @param {HTMLElement} container - Container element
     * @returns {NodeList} List of focusable elements
     */
    function getFocusableElements(container) {
        const focusableSelectors = [
            'a[href]',
            'button:not([disabled])',
            'textarea:not([disabled])',
            'input:not([disabled])',
            'select:not([disabled])',
            '[tabindex]:not([tabindex="-1"])',
            '[contenteditable="true"]'
        ].join(', ');

        return container.querySelectorAll(focusableSelectors);
    }

    /**
     * Trap focus within a container (for modals, dropdowns)
     * Cycles focus within container when Tab/Shift+Tab is pressed
     * @param {HTMLElement} container - Container to trap focus in
     * @returns {Function} Cleanup function to remove focus trap
     */
    function trapFocus(container) {
        const focusableElements = getFocusableElements(container);
        if (focusableElements.length === 0) return function() {};

        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        function handleKeyDown(e) {
            if (e.key !== 'Tab') return;

            // Shift+Tab on first element -> move to last
            if (e.shiftKey && document.activeElement === firstElement) {
                e.preventDefault();
                lastElement.focus();
            }
            // Tab on last element -> move to first
            else if (!e.shiftKey && document.activeElement === lastElement) {
                e.preventDefault();
                firstElement.focus();
            }
        }

        container.addEventListener('keydown', handleKeyDown);

        // Return cleanup function
        return function() {
            container.removeEventListener('keydown', handleKeyDown);
        };
    }

    /**
     * Set focus to first focusable element in container
     * @param {HTMLElement} container - Container element
     * @returns {boolean} True if focus was set successfully
     */
    function setFocusToFirst(container) {
        const focusableElements = getFocusableElements(container);
        if (focusableElements.length > 0) {
            focusableElements[0].focus();
            return true;
        }
        return false;
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} str - String to escape
     * @returns {string} Escaped string
     */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Check if element is in viewport
     * @param {HTMLElement} element - Element to check
     * @returns {boolean} True if element is in viewport
     */
    function isInViewport(element) {
        const rect = element.getBoundingClientRect();
        return (
            rect.top >= 0 &&
            rect.left >= 0 &&
            rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
            rect.right <= (window.innerWidth || document.documentElement.clientWidth)
        );
    }

    /**
     * Smooth scroll to element
     * @param {HTMLElement} element - Target element
     * @param {Object} options - Scroll options
     */
    function scrollToElement(element, options = {}) {
        const defaultOptions = {
            behavior: 'smooth',
            block: 'start',
            ...options
        };
        element.scrollIntoView(defaultOptions);
    }

    /**
     * Add event delegate to parent element
     * Improves performance for dynamic content
     * @param {HTMLElement} parent - Parent element
     * @param {string} eventType - Event type (click, input, etc.)
     * @param {string} selector - CSS selector for target elements
     * @param {Function} handler - Event handler function
     */
    function delegate(parent, eventType, selector, handler) {
        parent.addEventListener(eventType, function(e) {
            const target = e.target.closest(selector);
            if (target && parent.contains(target)) {
                handler.call(target, e);
            }
        });
    }

    /**
     * Check if user prefers reduced motion
     * @returns {boolean} True if prefers reduced motion
     */
    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    /**
     * Check if user prefers dark mode
     * @returns {boolean} True if prefers dark mode
     */
    function prefersDarkMode() {
        return window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    /**
     * Get current viewport breakpoint
     * @returns {string} Breakpoint name (xs, sm, md, lg, xl)
     */
    function getBreakpoint() {
        const width = window.innerWidth;
        if (width < 480) return 'xs';
        if (width < 768) return 'sm';
        if (width < 1024) return 'md';
        if (width < 1200) return 'lg';
        return 'xl';
    }

    /**
     * Check if device is mobile
     * @returns {boolean} True if mobile device
     */
    function isMobile() {
        return getBreakpoint() === 'xs' || getBreakpoint() === 'sm';
    }

    /**
     * Generate unique ID
     * @returns {string} Unique identifier
     */
    function generateId() {
        return 'agr-' + Math.random().toString(36).substr(2, 9);
    }

    /**
     * Announce message to screen readers
     * Uses ARIA live region for accessibility
     * @param {string} message - Message to announce
     * @param {string} priority - Priority (polite or assertive)
     */
    function announceToScreenReader(message, priority = 'polite') {
        let liveRegion = document.getElementById('agr-live-region');
        if (!liveRegion) {
            liveRegion = document.createElement('div');
            liveRegion.id = 'agr-live-region';
            liveRegion.setAttribute('role', 'status');
            liveRegion.setAttribute('aria-live', priority);
            liveRegion.className = 'sr-only';
            liveRegion.style.position = 'absolute';
            liveRegion.style.left = '-10000px';
            liveRegion.style.width = '1px';
            liveRegion.style.height = '1px';
            document.body.appendChild(liveRegion);
        }
        liveRegion.textContent = message;
        setTimeout(() => { liveRegion.textContent = ''; }, 1000);
    }

    /**
     * Disable body scroll (for modals, mobile menus)
     * @param {boolean} disable - True to disable, false to enable
     */
    function disableBodyScroll(disable = true) {
        if (disable) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }

    // Public API
    return {
        debounce,
        throttle,
        getFocusableElements,
        trapFocus,
        setFocusToFirst,
        escapeHtml,
        isInViewport,
        scrollToElement,
        delegate,
        prefersReducedMotion,
        prefersDarkMode,
        getBreakpoint,
        isMobile,
        generateId,
        announceToScreenReader,
        disableBodyScroll
    };
})();
