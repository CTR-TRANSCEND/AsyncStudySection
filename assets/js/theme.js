/**
 * AGR.UI Theme Management System
 * TAG: SPEC-UI-001-THEME
 *
 * Design-TAG: Dark mode theme with system preference detection
 * Function-TAG: Toggle between light and dark themes
 * Test-TAG: Tested for persistence, contrast ratios, and smooth transitions
 *
 * WCAG 2.1 AA Compliance:
 * - WCAG AAA contrast ratios in both themes (7:1 for normal text)
 * - Respects prefers-reduced-motion for transitions
 * - Theme toggle is accessible via keyboard and screen reader
 * - Theme preference persists across sessions
 */

var AGR = AGR || {};

/**
 * Theme manager
 */
AGR.Theme = (function() {
    'use strict';

    const STORAGE_KEY = 'agr-theme-preference';
    const THEMES = {
        LIGHT: 'light',
        DARK: 'dark'
    };

    let currentTheme = null;
    let toggleButton = null;

    /**
     * Get saved theme preference
     * @returns {string|null} Saved theme or null
     */
    function getSavedTheme() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            console.warn('Cannot access localStorage:', e);
            return null;
        }
    }

    /**
     * Save theme preference
     * @param {string} theme - Theme to save
     */
    function saveTheme(theme) {
        try {
            localStorage.setItem(STORAGE_KEY, theme);
        } catch (e) {
            console.warn('Cannot save to localStorage:', e);
        }
    }

    /**
     * Apply theme to document
     * @param {string} theme - Theme to apply
     */
    function applyTheme(theme) {
        const html = document.documentElement;
        const body = document.body;

        if (theme === THEMES.DARK) {
            html.setAttribute('data-theme', THEMES.DARK);
            body.classList.add('agr-theme-dark');
            body.classList.remove('agr-theme-light');
        } else {
            html.setAttribute('data-theme', THEMES.LIGHT);
            body.classList.add('agr-theme-light');
            body.classList.remove('agr-theme-dark');
        }

        currentTheme = theme;
        updateToggleButton();
    }

    /**
     * Detect system theme preference
     * @returns {string} System theme preference
     */
    function detectSystemTheme() {
        return AGR.Utils.prefersDarkMode() ? THEMES.DARK : THEMES.LIGHT;
    }

    /**
     * Initialize theme
     * Priority: Saved preference > System preference > Light (default)
     */
    function init() {
        const savedTheme = getSavedTheme();
        const systemTheme = detectSystemTheme();
        const theme = savedTheme || systemTheme;

        applyTheme(theme);

        // Listen for system theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            // Only auto-switch if user hasn't set explicit preference
            if (!getSavedTheme()) {
                applyTheme(e.matches ? THEMES.DARK : THEMES.LIGHT);
            }
        });

        // Announce theme to screen readers
        AGR.Utils.announceToScreenReader(`Theme set to ${theme}`, 'polite');
    }

    /**
     * Toggle theme
     */
    function toggle() {
        const newTheme = currentTheme === THEMES.DARK ? THEMES.LIGHT : THEMES.DARK;
        setTheme(newTheme);
    }

    /**
     * Set theme explicitly
     * @param {string} theme - Theme to set
     */
    function setTheme(theme) {
        if (!THEMES[theme.toUpperCase()]) {
            console.warn(`Invalid theme: ${theme}`);
            return;
        }

        applyTheme(theme);
        saveTheme(theme);

        // Announce theme change
        AGR.Utils.announceToScreenReader(`Theme changed to ${theme}`, 'polite');

        // Trigger custom event
        const event = new CustomEvent('agrthemechange', {
            detail: { theme }
        });
        document.dispatchEvent(event);
    }

    /**
     * Get current theme
     * @returns {string} Current theme
     */
    function getTheme() {
        return currentTheme;
    }

    /**
     * Check if dark mode is active
     * @returns {boolean} True if dark mode
     */
    function isDark() {
        return currentTheme === THEMES.DARK;
    }

    /**
     * Update toggle button state
     */
    function updateToggleButton() {
        if (!toggleButton) return;

        const icon = toggleButton.querySelector('.agr-theme-toggle-icon');
        const label = toggleButton.querySelector('.agr-theme-toggle-label');

        if (currentTheme === THEMES.DARK) {
            if (icon) {
                icon.innerHTML = '<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/></svg>';
            }
            if (label) {
                label.textContent = 'Dark mode';
            }
            toggleButton.setAttribute('aria-label', 'Switch to light mode');
            toggleButton.setAttribute('title', 'Switch to light mode');
        } else {
            if (icon) {
                icon.innerHTML = '<svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd"/></svg>';
            }
            if (label) {
                label.textContent = 'Light mode';
            }
            toggleButton.setAttribute('aria-label', 'Switch to dark mode');
            toggleButton.setAttribute('title', 'Switch to dark mode');
        }
    }

    /**
     * Create theme toggle button
     * @param {Object} options - Button options
     * @returns {HTMLElement} Toggle button element
     */
    function createToggleButton(options = {}) {
        const button = document.createElement('button');
        button.className = 'agr-theme-toggle btn btn-outline btn-sm';
        button.setAttribute('type', 'button');
        button.setAttribute('aria-label', 'Toggle theme');
        button.setAttribute('title', 'Toggle theme');

        const icon = document.createElement('span');
        icon.className = 'agr-theme-toggle-icon';
        icon.setAttribute('aria-hidden', 'true');

        button.appendChild(icon);

        if (options.showLabel) {
            const label = document.createElement('span');
            label.className = 'agr-theme-toggle-label';
            label.style.marginLeft = '0.5rem';
            button.appendChild(label);
        }

        button.addEventListener('click', toggle);

        toggleButton = button;
        updateToggleButton();

        return button;
    }

    /**
     * Insert toggle button into container
     * @param {HTMLElement|string} container - Container element or selector
     */
    function insertToggleButton(container) {
        const target = typeof container === 'string'
            ? document.querySelector(container)
            : container;

        if (!target) {
            console.warn('Theme toggle container not found');
            return;
        }

        if (!toggleButton) {
            toggleButton = createToggleButton();
        }

        target.appendChild(toggleButton);
    }

    // Public API
    return {
        init,
        toggle,
        setTheme,
        getTheme,
        isDark,
        createToggleButton,
        insertToggleButton,
        THEMES
    };
})();

// Auto-initialize theme on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => AGR.Theme.init());
} else {
    AGR.Theme.init();
}
