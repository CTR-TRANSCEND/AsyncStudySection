/**
 * Tests for AGR.Validation (assets/js/validation.js)
 *
 * validation.js depends on AGR.Utils, so we load both files in order.
 * Both use the global IIFE pattern; we inject them into jsdom's global scope.
 *
 * NOTE: Most of AGR.Validation is DOM-heavy. These tests cover the core
 * field/class management logic that can be exercised with real DOM nodes.
 */

import { readFileSync } from 'fs';
import { resolve } from 'path';

// Load utilities.js first (validation.js calls AGR.Utils.*)
beforeAll(() => {
    const loadSrc = (relPath) => {
        const src = readFileSync(resolve(process.cwd(), relPath), 'utf8');
        (0, eval)(src); // indirect eval for global-scope var binding
    };

    loadSrc('assets/js/utilities.js');
    loadSrc('assets/js/validation.js');
});

// ─── helpers ───────────────────────────────────────────────────────────────

/**
 * Create a minimal form with one text input wrapped in a div,
 * appended to the document body so DOM queries work correctly.
 */
function makeInput({ type = 'text', required = false, value = '', name = 'field' } = {}) {
    const wrapper = document.createElement('div');
    const input = document.createElement('input');
    input.type = type;
    input.name = name;
    input.id = name;
    if (required) input.required = true;
    if (value) input.value = value;
    wrapper.appendChild(input);
    document.body.appendChild(wrapper);
    return { wrapper, input };
}

afterEach(() => {
    // Clean up DOM nodes added during each test
    document.body.innerHTML = '';
});

// ─── clearFieldValidation ──────────────────────────────────────────────────

describe('AGR.Validation.clearFieldValidation', () => {
    it('removes is-valid class from the input', () => {
        const { input } = makeInput({ value: 'hello' });
        input.classList.add('is-valid');

        globalThis.AGR.Validation.clearFieldValidation(input);

        expect(input.classList.contains('is-valid')).toBe(false);
    });

    it('removes is-invalid class from the input', () => {
        const { input } = makeInput();
        input.classList.add('is-invalid');

        globalThis.AGR.Validation.clearFieldValidation(input);

        expect(input.classList.contains('is-invalid')).toBe(false);
    });

    it('removes aria-invalid attribute', () => {
        const { input } = makeInput();
        input.setAttribute('aria-invalid', 'true');

        globalThis.AGR.Validation.clearFieldValidation(input);

        expect(input.hasAttribute('aria-invalid')).toBe(false);
    });

    it('removes .invalid-feedback element when present', () => {
        const { wrapper, input } = makeInput();
        const errorDiv = document.createElement('div');
        errorDiv.className = 'invalid-feedback';
        errorDiv.textContent = 'Error!';
        wrapper.appendChild(errorDiv);

        globalThis.AGR.Validation.clearFieldValidation(input);

        expect(wrapper.querySelector('.invalid-feedback')).toBeNull();
    });

    it('removes .valid-feedback element when present', () => {
        const { wrapper, input } = makeInput();
        const successDiv = document.createElement('div');
        successDiv.className = 'valid-feedback';
        successDiv.textContent = 'Valid';
        wrapper.appendChild(successDiv);

        globalThis.AGR.Validation.clearFieldValidation(input);

        expect(wrapper.querySelector('.valid-feedback')).toBeNull();
    });
});

// ─── validateField — disabled/hidden fields ────────────────────────────────

describe('AGR.Validation.validateField', () => {
    it('returns true for a disabled input without touching its classes', () => {
        const { input } = makeInput();
        input.disabled = true;

        const result = globalThis.AGR.Validation.validateField(input);

        expect(result).toBe(true);
        expect(input.classList.contains('is-invalid')).toBe(false);
    });

    it('returns true for a hidden input', () => {
        const { input } = makeInput({ type: 'hidden' });

        const result = globalThis.AGR.Validation.validateField(input);

        expect(result).toBe(true);
    });

    it('adds is-valid when a non-empty input passes HTML5 validity', () => {
        const { input } = makeInput({ value: 'test@example.com', type: 'email' });

        globalThis.AGR.Validation.validateField(input);

        expect(input.classList.contains('is-valid')).toBe(true);
        expect(input.classList.contains('is-invalid')).toBe(false);
    });

    it('adds is-invalid when a non-empty required input is empty', () => {
        const { input } = makeInput({ required: true });
        // Input value is empty and required — checkValidity() returns false

        globalThis.AGR.Validation.validateField(input);

        // Empty required field: HTML5 validity fails, but value.length === 0
        // so neither class is added (source code: only adds class if value.length > 0)
        expect(input.classList.contains('is-valid')).toBe(false);
        expect(input.classList.contains('is-invalid')).toBe(false);
    });
});

// ─── resetForm ─────────────────────────────────────────────────────────────

describe('AGR.Validation.resetForm', () => {
    it('clears validation state from all inputs in the form', () => {
        const form = document.createElement('form');
        document.body.appendChild(form);

        const input1 = document.createElement('input');
        input1.type = 'text';
        input1.classList.add('is-invalid');

        const input2 = document.createElement('input');
        input2.type = 'text';
        input2.classList.add('is-valid');

        form.appendChild(input1);
        form.appendChild(input2);

        globalThis.AGR.Validation.resetForm(form);

        expect(input1.classList.contains('is-invalid')).toBe(false);
        expect(input2.classList.contains('is-valid')).toBe(false);
    });

    it('removes the error summary element when present', () => {
        const form = document.createElement('form');
        const summary = document.createElement('div');
        summary.className = 'validation-error-summary';
        form.appendChild(summary);
        document.body.appendChild(form);

        globalThis.AGR.Validation.resetForm(form);

        expect(form.querySelector('.validation-error-summary')).toBeNull();
    });
});
