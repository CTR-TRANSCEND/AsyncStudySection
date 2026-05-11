// @MX:NOTE: Vitest config for vanilla JS unit tests (no build system)
// @MX:NOTE: Uses jsdom environment to simulate browser globals (window, document)
// @MX:REASON: assets/js files rely on browser APIs (document.createElement, window.matchMedia)

/** @type {import('vitest').UserConfig} */
export default {
    test: {
        // jsdom provides window, document, and other browser APIs
        environment: 'jsdom',
        // Test files are in tests/js/
        include: ['tests/js/**/*.test.js'],
        // Global test APIs (describe, it, expect) available without imports
        globals: true,
        // Clear mock state between tests
        clearMocks: true,
    },
};
