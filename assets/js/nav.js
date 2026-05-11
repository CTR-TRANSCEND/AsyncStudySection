/**
 * Navigation System Module (SPEC-UIX-002 Milestone 2)
 *
 * Provides centralized navigation functionality with:
 * - Mobile menu toggle
 * - Dropdown menu management
 * - User menu interactions
 * - Accessibility support (ARIA attributes, keyboard navigation)
 * - Header scroll effects
 *
 * @author MoAI TDD Implementation
 * @version 1.0.0
 */

;(function() {
    'use strict';

    // Namespace for navigation functionality
    window.AGR = window.AGR || {};
    window.AGR.Navigation = Navigation;

    /**
     * Navigation Module
     */
    function Navigation() {
        this.mobileMenuToggle = null;
        this.mainNav = null;
        this.userMenuButton = null;
        this.userMenuDropdown = null;
        this.header = null;
        this.dropdowns = [];

        this.init();
    }

    /**
     * Initialize navigation components
     */
    Navigation.prototype.init = function() {
        // Cache DOM elements
        this.mobileMenuToggle = document.getElementById('mobile-menu-toggle');
        this.mainNav = document.getElementById('main-nav');
        this.userMenuButton = document.getElementById('user-menu-button');
        this.userMenuDropdown = document.getElementById('user-menu-dropdown');
        this.header = document.getElementById('main-header');

        // Initialize components
        this.initMobileMenu();
        this.initUserMenu();
        this.initDropdowns();
        this.initHeaderScroll();

        // Close dropdowns on escape key
        document.addEventListener('keydown', this.handleEscapeKey.bind(this));

        // Close dropdowns when clicking outside
        document.addEventListener('click', this.handleOutsideClick.bind(this));
    };

    /**
     * Initialize mobile menu toggle
     */
    Navigation.prototype.initMobileMenu = function() {
        if (!this.mobileMenuToggle || !this.mainNav) {
            return;
        }

        this.mobileMenuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const isExpanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', !isExpanded);
            this.mainNav.classList.toggle('show');
        }.bind(this));
    };

    /**
     * Initialize user menu dropdown
     */
    Navigation.prototype.initUserMenu = function() {
        if (!this.userMenuButton || !this.userMenuDropdown) {
            return;
        }

        this.userMenuButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const isExpanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', !isExpanded);
            this.userMenuDropdown.classList.toggle('show');
        }.bind(this));

        // Store reference for global handlers
        this.dropdowns.push({
            button: this.userMenuButton,
            menu: this.userMenuDropdown
        });
    };

    /**
     * Initialize all dropdown menus
     */
    Navigation.prototype.initDropdowns = function() {
        var self = this;
        var dropdownToggles = document.querySelectorAll('.nav-dropdown-toggle');

        dropdownToggles.forEach(function(toggle) {
            // Find the dropdown menu - it's the next sibling with .nav-dropdown-menu class
            var dropdownWrapper = toggle.closest('.nav-dropdown');
            var dropdown = dropdownWrapper ? dropdownWrapper.querySelector('.nav-dropdown-menu') : null;

            if (!dropdown) {
                return;
            }

            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', !isExpanded);
                dropdown.classList.toggle('show');
            });

            // Store reference for global handlers
            self.dropdowns.push({
                button: toggle,
                menu: dropdown
            });
        });
    };

    /**
     * Initialize header scroll effect
     */
    Navigation.prototype.initHeaderScroll = function() {
        if (!this.header) {
            return;
        }

        var scrollTimeout;
        window.addEventListener('scroll', function() {
            if (scrollTimeout) {
                window.cancelAnimationFrame(scrollTimeout);
            }

            scrollTimeout = window.requestAnimationFrame(function() {
                if (window.scrollY > 10) {
                    this.header.classList.add('scrolled');
                } else {
                    this.header.classList.remove('scrolled');
                }
            }.bind(this));
        }.bind(this));
    };

    /**
     * Handle escape key to close dropdowns
     */
    Navigation.prototype.handleEscapeKey = function(e) {
        if (e.key !== 'Escape') {
            return;
        }

        this.dropdowns.forEach(function(dropdown) {
            if (dropdown.menu && dropdown.menu.classList.contains('show')) {
                dropdown.button.setAttribute('aria-expanded', 'false');
                dropdown.menu.classList.remove('show');
                dropdown.button.focus();
            }
        });
    };

    /**
     * Handle clicks outside dropdowns to close them
     */
    Navigation.prototype.handleOutsideClick = function(e) {
        this.dropdowns.forEach(function(dropdown) {
            if (!dropdown.menu || !dropdown.menu.classList.contains('show')) {
                return;
            }

            // Check if click is outside dropdown
            if (!dropdown.button.contains(e.target) &&
                !dropdown.menu.contains(e.target)) {
                dropdown.button.setAttribute('aria-expanded', 'false');
                dropdown.menu.classList.remove('show');
            }
        });
    };

    /**
     * Close all dropdowns programmatically
     */
    Navigation.prototype.closeAllDropdowns = function() {
        this.dropdowns.forEach(function(dropdown) {
            if (dropdown.menu && dropdown.menu.classList.contains('show')) {
                dropdown.button.setAttribute('aria-expanded', 'false');
                dropdown.menu.classList.remove('show');
            }
        });
    };

    /**
     * Auto-initialize when DOM is ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            new Navigation();
        });
    } else {
        // DOM is already ready
        new Navigation();
    }

    /**
     * Export create function for manual initialization
     */
    Navigation.create = function() {
        return new Navigation();
    };

})();
