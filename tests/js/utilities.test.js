/**
 * Tests for AGR.Utils utility functions (assets/js/utilities.js)
 *
 * The source file uses a global IIFE pattern (var AGR = AGR || {}).
 * We load it by reading and evaluating the file content, injecting it
 * into jsdom's global scope so AGR.Utils becomes available.
 */

import { readFileSync } from 'fs';
import { resolve } from 'path';

// Load utilities.js into the jsdom global scope before tests run
beforeAll(() => {
    const src = readFileSync(
        resolve(process.cwd(), 'assets/js/utilities.js'),
        'utf8'
    );
    // Execute in global scope — indirect eval ensures global-scope var binding
    (0, eval)(src);
});

// ─── debounce ──────────────────────────────────────────────────────────────

describe('AGR.Utils.debounce', () => {
    it('delays function execution by the specified wait time', async () => {
        vi.useFakeTimers();
        const mock = vi.fn();
        const debounced = globalThis.AGR.Utils.debounce(mock, 100);

        debounced();
        expect(mock).not.toHaveBeenCalled();

        vi.advanceTimersByTime(100);
        expect(mock).toHaveBeenCalledTimes(1);

        vi.useRealTimers();
    });

    it('resets the timer when called again before the wait expires', async () => {
        vi.useFakeTimers();
        const mock = vi.fn();
        const debounced = globalThis.AGR.Utils.debounce(mock, 100);

        debounced();
        vi.advanceTimersByTime(50);
        debounced(); // reset timer
        vi.advanceTimersByTime(50);
        // 100ms has not elapsed since the second call
        expect(mock).not.toHaveBeenCalled();

        vi.advanceTimersByTime(50);
        expect(mock).toHaveBeenCalledTimes(1);

        vi.useRealTimers();
    });

    it('passes arguments to the debounced function', () => {
        vi.useFakeTimers();
        const mock = vi.fn();
        const debounced = globalThis.AGR.Utils.debounce(mock, 50);

        debounced('hello', 42);
        vi.advanceTimersByTime(50);
        expect(mock).toHaveBeenCalledWith('hello', 42);

        vi.useRealTimers();
    });
});

// ─── throttle ──────────────────────────────────────────────────────────────

describe('AGR.Utils.throttle', () => {
    it('executes the function immediately on the first call', () => {
        vi.useFakeTimers();
        const mock = vi.fn();
        const throttled = globalThis.AGR.Utils.throttle(mock, 100);

        throttled();
        expect(mock).toHaveBeenCalledTimes(1);

        vi.useRealTimers();
    });

    it('ignores additional calls within the limit window', () => {
        vi.useFakeTimers();
        const mock = vi.fn();
        const throttled = globalThis.AGR.Utils.throttle(mock, 100);

        throttled();
        throttled();
        throttled();
        expect(mock).toHaveBeenCalledTimes(1);

        vi.useRealTimers();
    });

    it('allows execution again after the limit window expires', () => {
        vi.useFakeTimers();
        const mock = vi.fn();
        const throttled = globalThis.AGR.Utils.throttle(mock, 100);

        throttled();
        vi.advanceTimersByTime(100);
        throttled();
        expect(mock).toHaveBeenCalledTimes(2);

        vi.useRealTimers();
    });
});

// ─── generateId ────────────────────────────────────────────────────────────

describe('AGR.Utils.generateId', () => {
    it('returns a string starting with "agr-"', () => {
        const id = globalThis.AGR.Utils.generateId();
        expect(id).toMatch(/^agr-/);
    });

    it('returns a unique value on each call', () => {
        const id1 = globalThis.AGR.Utils.generateId();
        const id2 = globalThis.AGR.Utils.generateId();
        expect(id1).not.toBe(id2);
    });
});

// ─── getBreakpoint ─────────────────────────────────────────────────────────

describe('AGR.Utils.getBreakpoint', () => {
    const setWidth = (w) => Object.defineProperty(window, 'innerWidth', { writable: true, configurable: true, value: w });

    it.each([
        [320,  'xs'],
        [480,  'sm'],
        [768,  'md'],
        [1024, 'lg'],
        [1200, 'xl'],
    ])('returns "%s" for width %i', (width, expected) => {
        setWidth(width);
        expect(globalThis.AGR.Utils.getBreakpoint()).toBe(expected);
    });
});

// ─── escapeHtml ────────────────────────────────────────────────────────────

describe('AGR.Utils.escapeHtml', () => {
    it('escapes < and > characters', () => {
        const result = globalThis.AGR.Utils.escapeHtml('<script>');
        expect(result).toBe('&lt;script&gt;');
    });

    it('escapes & character', () => {
        const result = globalThis.AGR.Utils.escapeHtml('a & b');
        expect(result).toBe('a &amp; b');
    });

    it('returns plain strings unchanged', () => {
        expect(globalThis.AGR.Utils.escapeHtml('hello world')).toBe('hello world');
    });
});
