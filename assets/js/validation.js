/**
 * AGR.UI Form Validation System
 * TAG: SPEC-UI-001-VALIDATION
 *
 * Design-TAG: Real-time form validation with accessible feedback
 * Function-TAG: Validate form inputs and display inline errors
 * Test-TAG: Tested for various input types and error scenarios
 *
 * WCAG 2.1 AA Compliance:
 * - ARIA attributes for invalid fields (aria-invalid, aria-describedby)
 * - Error messages associated with inputs
 * - Error summary for screen readers
 * - Focus moves to first invalid field on submit
 * - Clear visual feedback (colors, icons)
 */

var AGR = AGR || {};

/**
 * Form validation manager
 */
AGR.Validation = (function() {
    'use strict';

    const VALID_CLASS = 'is-valid';
    const INVALID_CLASS = 'is-invalid';
    const PENDING_CLASS = 'is-pending';

    /**
     * Validate single field
     * @param {HTMLInputElement} input - Input element
     * @returns {boolean} True if valid
     */
    function validateField(input) {
        // Skip if field is disabled, readonly, or hidden
        if (input.disabled || input.readOnly || input.type === 'hidden') {
            return true;
        }

        // Check validity
        const isValid = input.checkValidity();

        // Update classes
        input.classList.remove(VALID_CLASS, INVALID_CLASS, PENDING_CLASS);
        if (input.value.length > 0) {
            input.classList.add(isValid ? VALID_CLASS : INVALID_CLASS);
        }

        // Update error message
        updateFieldError(input, isValid);

        // Announce to screen readers if invalid
        if (!isValid && input.value.length > 0) {
            const errorMessage = input.validationMessage || 'Invalid input';
            AGR.Utils.announceToScreenReader(`Validation error: ${errorMessage}`, 'assertive');
        }

        return isValid;
    }

    /**
     * Update field error message
     * @param {HTMLInputElement} input - Input element
     * @param {boolean} isValid - Whether field is valid
     */
    function updateFieldError(input, isValid) {
        // Find or create error element
        let errorElement = input.parentNode.querySelector('.invalid-feedback');
        let successElement = input.parentNode.querySelector('.valid-feedback');

        if (!isValid) {
            // Create error element if needed
            if (!errorElement) {
                errorElement = document.createElement('div');
                errorElement.className = 'invalid-feedback';
                errorElement.setAttribute('role', 'alert');
                errorElement.setAttribute('aria-live', 'polite');
                input.parentNode.appendChild(errorElement);
            }

            // Set error message
            const customMessage = input.getAttribute('data-error-message');
            errorElement.textContent = customMessage || input.validationMessage || 'This field is invalid';

            // Set ARIA attributes
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', errorElement.id || AGR.Utils.generateId());
        } else {
            // Remove error element
            if (errorElement) {
                errorElement.remove();
            }

            // Create success indicator if field has value
            if (input.value.length > 0 && input.type !== 'password') {
                if (!successElement) {
                    successElement = document.createElement('div');
                    successElement.className = 'valid-feedback';
                    successElement.innerHTML = '<svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg> Valid';
                    input.parentNode.appendChild(successElement);
                }
                successElement.style.display = 'block';
            }

            // Clear ARIA attributes
            input.removeAttribute('aria-invalid');
            input.removeAttribute('aria-describedby');
        }
    }

    /**
     * Validate entire form
     * @param {HTMLFormElement} form - Form element
     * @returns {boolean} True if form is valid
     */
    function validateForm(form) {
        const inputs = form.querySelectorAll('input, textarea, select');
        let isValid = true;
        let firstInvalid = null;

        inputs.forEach(input => {
            if (!validateField(input)) {
                isValid = false;
                if (!firstInvalid) {
                    firstInvalid = input;
                }
            }
        });

        // Focus first invalid field
        if (firstInvalid) {
            firstInvalid.focus();
            AGR.Utils.scrollToElement(firstInvalid, { block: 'center' });
        }

        // Show/hide error summary
        updateErrorSummary(form, isValid);

        return isValid;
    }

    /**
     * Update error summary
     * @param {HTMLFormElement} form - Form element
     * @param {boolean} isValid - Whether form is valid
     */
    function updateErrorSummary(form, isValid) {
        let summary = form.querySelector('.validation-error-summary');
        const invalidFields = form.querySelectorAll('.is-invalid');

        if (invalidFields.length > 0) {
            // Create summary if needed
            if (!summary) {
                summary = document.createElement('div');
                summary.className = 'alert alert-error validation-error-summary';
                summary.setAttribute('role', 'alert');

                const title = document.createElement('h3');
                title.textContent = 'Please fix the following errors:';
                title.style.fontSize = '1rem';
                title.style.marginBottom = '0.5rem';
                summary.appendChild(title);

                const list = document.createElement('ul');
                list.className = 'validation-error-list';
                summary.appendChild(list);

                form.insertBefore(summary, form.firstChild);
            }

            // Update error list
            const list = summary.querySelector('.validation-error-list');
            list.innerHTML = '';

            invalidFields.forEach(field => {
                const label = form.querySelector(`label[for="${field.id}"]`);
                const labelText = label ? label.textContent.replace('*', '').trim() : field.name || 'Field';

                const li = document.createElement('li');
                const link = document.createElement('a');
                link.href = '#' + (field.id || '');
                link.textContent = labelText + ': ' + (field.validationMessage || 'Invalid');
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    field.focus();
                });
                li.appendChild(link);
                list.appendChild(li);
            });

            summary.style.display = 'block';

            // Announce to screen readers
            AGR.Utils.announceToScreenReader(
                `Form has ${invalidFields.length} validation errors. Please review and fix.`,
                'assertive'
            );
        } else {
            // Hide summary
            if (summary) {
                summary.style.display = 'none';
            }
        }
    }

    /**
     * Initialize form validation
     * @param {HTMLFormElement} form - Form element
     * @param {Object} options - Validation options
     */
    function initForm(form, options = {}) {
        const inputs = form.querySelectorAll('input, textarea, select');

        inputs.forEach(input => {
            // Validate on blur
            input.addEventListener('blur', () => {
                validateField(input);
            });

            // Validate on input (if already showing error)
            input.addEventListener('input', () => {
                if (input.classList.contains(INVALID_CLASS)) {
                    validateField(input);
                }
            });

            // Clear error on input start
            input.addEventListener('input', () => {
                if (input.classList.contains(PENDING_CLASS)) {
                    input.classList.remove(PENDING_CLASS);
                }
            });

            // Mark as pending on focus (if empty required field)
            input.addEventListener('focus', () => {
                if (input.required && input.value.length === 0) {
                    input.classList.add(PENDING_CLASS);
                }
            });
        });

        // Prevent form submission if invalid
        form.addEventListener('submit', (e) => {
            if (!validateForm(form)) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }

            // Call custom submit handler if provided
            if (options.onValidSubmit) {
                e.preventDefault();
                options.onValidSubmit(form);
            }
        });

        // Store validation state on form element
        form.setAttribute('data-validation-initialized', 'true');
    }

    /**
     * Initialize all forms on page
     */
    function initAllForms() {
        const forms = document.querySelectorAll('form:not([data-validation-initialized])');
        forms.forEach(form => initForm(form));
    }

    /**
     * Remove validation from field
     * @param {HTMLInputElement} input - Input element
     */
    function clearFieldValidation(input) {
        input.classList.remove(VALID_CLASS, INVALID_CLASS, PENDING_CLASS);
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');

        const errorElement = input.parentNode.querySelector('.invalid-feedback');
        if (errorElement) {
            errorElement.remove();
        }

        const successElement = input.parentNode.querySelector('.valid-feedback');
        if (successElement) {
            successElement.remove();
        }
    }

    /**
     * Reset form validation
     * @param {HTMLFormElement} form - Form element
     */
    function resetForm(form) {
        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(input => clearFieldValidation(input));

        const summary = form.querySelector('.validation-error-summary');
        if (summary) {
            summary.remove();
        }
    }

    /**
     * Add custom validator to field
     * @param {HTMLInputElement} input - Input element
     * @param {Function} validator - Validator function
     * @param {string} errorMessage - Custom error message
     */
    function addCustomValidator(input, validator, errorMessage) {
        input.addEventListener('input', () => {
            const isValid = validator(input.value);
            if (!isValid && input.value.length > 0) {
                input.setCustomValidity(errorMessage || 'Validation failed');
                validateField(input);
            } else {
                input.setCustomValidity('');
                validateField(input);
            }
        });
    }

    // Auto-initialize forms on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllForms);
    } else {
        initAllForms();
    }

    // Public API
    return {
        initForm,
        initAllForms,
        validateField,
        validateForm,
        clearFieldValidation,
        resetForm,
        addCustomValidator
    };
})();
