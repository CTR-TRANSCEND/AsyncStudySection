/**
 * AGR.UI Modal Dialog System
 * TAG: SPEC-UI-001-MODAL
 *
 * Design-TAG: Accessible modal dialogs with focus management
 * Function-TAG: Create and manage modal overlays
 * Test-TAG: Tested for focus trapping, keyboard navigation, ARIA compliance
 *
 * WCAG 2.1 AA Compliance:
 * - Focus trap within modal
 * - Return focus to trigger element on close
 * - Escape key closes modal
 * - Click outside closes modal (configurable)
 * - ARIA attributes (role, aria-modal, aria-labelledby)
 * - Background scroll disabled when open
 */

var AGR = AGR || {};

/**
 * Modal dialog manager
 */
AGR.Modal = (function() {
    'use strict';

    let activeModal = null;
    let triggerElement = null;
    let focusTrapCleanup = null;
    let backdrop = null;

    /**
     * Create modal element
     * @param {Object} options - Modal options
     * @returns {HTMLElement} Modal element
     */
    function createModal(options) {
        const modal = document.createElement('div');
        modal.className = 'agr-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', options.titleId || 'agr-modal-title');
        modal.setAttribute('aria-describedby', options.descriptionId || 'agr-modal-description');

        if (options.id) {
            modal.id = options.id;
        }

        // Modal content
        const content = document.createElement('div');
        content.className = 'agr-modal-content';

        // Header
        if (options.title) {
            const header = document.createElement('div');
            header.className = 'agr-modal-header';

            const title = document.createElement('h2');
            title.id = 'agr-modal-title';
            title.className = 'agr-modal-title';
            title.textContent = options.title;

            header.appendChild(title);
            content.appendChild(header);
        }

        // Body
        const body = document.createElement('div');
        body.className = 'agr-modal-body';
        if (typeof options.content === 'string') {
            body.innerHTML = options.content;
        } else if (options.content instanceof HTMLElement) {
            body.appendChild(options.content);
        }
        content.appendChild(body);

        // Footer
        if (options.buttons && options.buttons.length > 0) {
            const footer = document.createElement('div');
            footer.className = 'agr-modal-footer';

            options.buttons.forEach(button => {
                const btn = document.createElement('button');
                btn.className = `btn btn-${button.type || 'secondary'}`;
                btn.textContent = button.label;
                btn.setAttribute('type', 'button');

                if (button.onClick) {
                    btn.addEventListener('click', () => {
                        button.onClick(modal);
                        if (button.closeOnClick !== false) {
                            close();
                        }
                    });
                } else if (button.closeOnClick !== false) {
                    btn.addEventListener('click', close);
                }

                footer.appendChild(btn);
            });

            content.appendChild(footer);
        }

        // Close button
        const closeButton = document.createElement('button');
        closeButton.className = 'agr-modal-close';
        closeButton.setAttribute('type', 'button');
        closeButton.setAttribute('aria-label', 'Close modal');
        closeButton.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>';
        closeButton.addEventListener('click', close);
        content.appendChild(closeButton);

        modal.appendChild(content);

        return modal;
    }

    /**
     * Create backdrop overlay
     * @returns {HTMLElement} Backdrop element
     */
    function createBackdrop() {
        const backdrop = document.createElement('div');
        backdrop.className = 'agr-modal-backdrop';
        backdrop.setAttribute('aria-hidden', 'true');
        return backdrop;
    }

    /**
     * Open modal dialog
     * @param {Object} options - Modal options
     * @returns {HTMLElement} Modal element
     */
    function open(options) {
        // Store trigger element for focus return
        triggerElement = document.activeElement;

        // Create backdrop
        backdrop = createBackdrop();
        document.body.appendChild(backdrop);

        // Create modal
        const modal = createModal(options);
        document.body.appendChild(modal);
        activeModal = modal;

        // Disable body scroll
        AGR.Utils.disableBodyScroll(true);

        // Trigger animation
        requestAnimationFrame(() => {
            backdrop.classList.add('agr-modal-backdrop-show');
            modal.classList.add('agr-modal-show');
        });

        // Set focus to first focusable element after animation
        setTimeout(() => {
            AGR.Utils.setFocusToFirst(modal);
        }, 100);

        // Setup focus trap
        focusTrapCleanup = AGR.Utils.trapFocus(modal);

        // Setup click outside to close
        if (options.clickOutsideToClose !== false) {
            backdrop.addEventListener('click', handleBackdropClick);
            modal.addEventListener('click', handleModalClick);
        }

        // Announce to screen readers
        AGR.Utils.announceToScreenReader('Dialog opened: ' + (options.title || 'Modal'), 'polite');

        return modal;
    }

    /**
     * Close active modal
     */
    function close() {
        if (!activeModal) return;

        // Remove focus trap
        if (focusTrapCleanup) {
            focusTrapCleanup();
            focusTrapCleanup = null;
        }

        // Remove event listeners
        if (backdrop) {
            backdrop.removeEventListener('click', handleBackdropClick);
        }
        if (activeModal) {
            activeModal.removeEventListener('click', handleModalClick);
        }

        // Animate out
        activeModal.classList.remove('agr-modal-show');
        activeModal.classList.add('agr-modal-hide');
        if (backdrop) {
            backdrop.classList.remove('agr-modal-backdrop-show');
        }

        // Remove from DOM after animation
        setTimeout(() => {
            if (activeModal && activeModal.parentNode) {
                activeModal.parentNode.removeChild(activeModal);
            }
            if (backdrop && backdrop.parentNode) {
                backdrop.parentNode.removeChild(backdrop);
            }
            activeModal = null;
            backdrop = null;

            // Re-enable body scroll
            AGR.Utils.disableBodyScroll(false);

            // Return focus to trigger element
            if (triggerElement && triggerElement.focus) {
                triggerElement.focus();
            }
        }, 200);
    }

    /**
     * Handle backdrop click (close modal)
     * @param {Event} e - Click event
     */
    function handleBackdropClick(e) {
        if (e.target === backdrop) {
            close();
        }
    }

    /**
     * Handle modal click (check if clicked outside content)
     * @param {Event} e - Click event
     */
    function handleModalClick(e) {
        if (e.target === activeModal) {
            close();
        }
    }

    /**
     * Handle keyboard events
     * @param {KeyboardEvent} e - Keyboard event
     */
    function handleKeyDown(e) {
        if (e.key === 'Escape' && activeModal) {
            close();
        }
    }

    // Initialize keyboard handler
    document.addEventListener('keydown', handleKeyDown);

    /**
     * Confirm dialog
     * @param {string} message - Confirmation message
     * @param {string} title - Dialog title
     * @returns {Promise<boolean>} True if confirmed
     */
    function confirm(message, title = 'Confirm') {
        return new Promise((resolve) => {
            open({
                title: title,
                content: `<p>${AGR.Utils.escapeHtml(message)}</p>`,
                buttons: [
                    {
                        label: 'Cancel',
                        type: 'secondary',
                        onClick: () => resolve(false)
                    },
                    {
                        label: 'Confirm',
                        type: 'primary',
                        onClick: () => resolve(true)
                    }
                ]
            });
        });
    }

    /**
     * Alert dialog
     * @param {string} message - Alert message
     * @param {string} title - Dialog title
     * @returns {Promise<void>}
     */
    function alert(message, title = 'Notice') {
        return new Promise((resolve) => {
            open({
                title: title,
                content: `<p>${AGR.Utils.escapeHtml(message)}</p>`,
                buttons: [
                    {
                        label: 'OK',
                        type: 'primary',
                        onClick: () => resolve()
                    }
                ]
            });
        });
    }

    // Public API
    return {
        open,
        close,
        confirm,
        alert,
        isOpen: () => activeModal !== null
    };
})();
