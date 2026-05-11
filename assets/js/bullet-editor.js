/**
 * AGR.UI Bullet Editor - Advanced bullet point editor module
 * TAG: SPEC-UI-001-BULLET-EDITOR
 *
 * Design-TAG: ContentEditable-based bullet point editor with rich text support
 * Function-TAG: Create, edit, and manage bullet lists with auto-save and validation
 * Test-TAG: Manual testing + unit tests for bullet operations
 *
 * WCAG 2.1 AA Compliance:
 * - Full keyboard navigation (Enter, Backspace, Tab, Shift+Tab, Arrows)
 * - ARIA labels and live regions for screen readers
 * - Focus management and visible focus indicators
 * - Semantic HTML structure
 * - Screen reader announcements for actions
 *
 * @requires AGR.Utils (utilities.js)
 * @requires AGR.Toast (toast.js)
 */

var AGR = AGR || {};

/**
 * Bullet Editor Module
 * Provides advanced bullet point editing with auto-save, validation, and accessibility
 */
AGR.BulletEditor = (function() {
    'use strict';

    // Default configuration
    const DEFAULTS = {
        maxLength: 2000,
        showCounter: true,
        enablePreview: true,
        autoSaveDelay: 5000,
        placeholder: 'Type bullet point... Press Enter for new bullet',
        bullets: ['•', '◦', '▪'], // Nested bullet styles
        maxIndent: 3
    };

    // Store active editors
    const editors = new Map();

    /**
     * Generate a unique editor ID
     * @private
     * @returns {string} Unique editor ID
     */
    function generateEditorId() {
        return 'bullet-editor-' + AGR.Utils.generateId();
    }

    /**
     * Create bullet item element
     * @private
     * @param {string} text - Initial text content
     * @param {number} level - Indentation level (0-3)
     * @param {string} bulletChar - Bullet character
     * @returns {HTMLElement} Bullet item div element
     */
    function createBulletItem(text = '', level = 0, bulletChar = DEFAULTS.bullets[0]) {
        const item = document.createElement('div');
        item.className = 'agr-bullet-item';
        item.setAttribute('data-indent', level);
        item.setAttribute('role', 'listitem');
        item.style.cssText = `
            position: relative;
            padding-left: ${1 + (level * 1.5)}rem;
            margin-bottom: 0.25rem;
            min-height: 1.5em;
            word-wrap: break-word;
        `;

        const bullet = document.createElement('span');
        bullet.className = 'agr-bullet-marker';
        bullet.textContent = bulletChar + ' ';
        bullet.setAttribute('aria-hidden', 'true');
        bullet.style.cssText = `
            position: absolute;
            left: ${level * 1.5}rem;
            color: #333;
            font-weight: bold;
            user-select: none;
        `;

        const textNode = document.createTextNode(text);

        item.appendChild(bullet);
        item.appendChild(textNode);

        return item;
    }

    /**
     * Get current bullet character for indent level
     * @private
     * @param {number} level - Indentation level
     * @returns {string} Bullet character
     */
    function getBulletForLevel(level) {
        return DEFAULTS.bullets[level % DEFAULTS.bullets.length];
    }

    /**
     * Create character/word counter
     * @private
     * @param {string} editorId - Editor ID
     * @param {number} maxLength - Maximum character count
     * @returns {HTMLElement} Counter element
     */
    function createCounter(editorId, maxLength) {
        const counter = document.createElement('div');
        counter.className = 'agr-bullet-counter';
        counter.id = `${editorId}-counter`;
        counter.setAttribute('aria-live', 'polite');
        counter.setAttribute('aria-atomic', 'true');
        counter.style.cssText = `
            font-size: 0.75rem;
            color: #6c757d;
            text-align: right;
            margin-top: 0.25rem;
            transition: color 0.2s ease;
        `;
        counter.textContent = `0 / ${maxLength} characters`;

        return counter;
    }

    /**
     * Update character counter
     * @private
     * @param {HTMLElement} counter - Counter element
     * @param {number} current - Current character count
     * @param {number} max - Maximum character count
     */
    function updateCounter(counter, current, max) {
        counter.textContent = `${current} / ${max} characters`;

        if (current > max) {
            counter.style.color = '#dc3545';
            counter.style.fontWeight = 'bold';
        } else if (current > max * 0.9) {
            counter.style.color = '#ffc107';
            counter.style.fontWeight = 'bold';
        } else {
            counter.style.color = '#6c757d';
            counter.style.fontWeight = 'normal';
        }
    }

    /**
     * Create preview button
     * @private
     * @param {string} editorId - Editor ID
     * @returns {HTMLElement} Preview button element
     */
    function createPreviewButton(editorId) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'agr-bullet-preview-btn';
        button.id = `${editorId}-preview-btn`;
        button.setAttribute('aria-label', 'Toggle preview mode');
        button.setAttribute('aria-pressed', 'false');
        button.textContent = 'Preview';
        button.style.cssText = `
            padding: 0.25rem 0.75rem;
            margin-top: 0.5rem;
            font-size: 0.875rem;
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: background-color 0.2s ease;
        `;

        button.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#5a6268';
        });

        button.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '#6c757d';
        });

        return button;
    }

    /**
     * Create preview display
     * @private
     * @param {string} editorId - Editor ID
     * @returns {HTMLElement} Preview display element
     */
    function createPreviewDisplay(editorId) {
        const preview = document.createElement('div');
        preview.className = 'agr-bullet-preview';
        preview.id = `${editorId}-preview`;
        preview.setAttribute('aria-live', 'polite');
        preview.setAttribute('role', 'region');
        preview.setAttribute('aria-label', 'Bullet point preview');
        preview.style.cssText = `
            display: none;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            padding: 1rem;
            background-color: #f8f9fa;
            min-height: 80px;
            max-height: 300px;
            overflow-y: auto;
        `;

        return preview;
    }

    /**
     * Create toolbar
     * @private
     * @param {string} editorId - Editor ID
     * @returns {HTMLElement} Toolbar element
     */
    function createToolbar(editorId) {
        const toolbar = document.createElement('div');
        toolbar.className = 'agr-bullet-toolbar';
        toolbar.id = `${editorId}-toolbar`;
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', 'Bullet editor toolbar');
        toolbar.style.cssText = `
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        `;

        // Help tooltip
        const help = document.createElement('button');
        help.type = 'button';
        help.className = 'agr-bullet-help';
        help.setAttribute('aria-label', 'Keyboard shortcuts');
        help.textContent = '?';
        help.style.cssText = `
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 1px solid #6c757d;
            background: white;
            cursor: pointer;
            font-weight: bold;
        `;

        help.addEventListener('click', function() {
            showHelpTooltip(editorId);
        });

        toolbar.appendChild(help);

        return toolbar;
    }

    /**
     * Show help tooltip with keyboard shortcuts
     * @private
     * @param {string} editorId - Editor ID
     */
    function showHelpTooltip(editorId) {
        const shortcuts = [
            'Enter - New bullet point',
            'Backspace - Delete empty bullet or merge',
            'Tab - Indent bullet',
            'Shift+Tab - Outdent bullet',
            'Arrow Up/Down - Navigate bullets'
        ];

        AGR.Toast.info('Keyboard Shortcuts:\n' + shortcuts.join('\n'), 0);
    }

    /**
     * Get plain text content from editor
     * @private
     * @param {HTMLElement} editor - Editor element
     * @returns {Array<string>} Array of bullet text lines
     */
    function getEditorContent(editor) {
        const bullets = editor.querySelectorAll('.agr-bullet-item');
        return Array.from(bullets).map(bullet => {
            const text = bullet.textContent.replace(/^[\u2022\u25E6\u25AA]\s*/, '').trim();
            return text;
        }).filter(text => text.length > 0);
    }

    /**
     * Calculate character count
     * @private
     * @param {Array<string>} content - Array of bullet lines
     * @returns {number} Total character count
     */
    function calculateCharCount(content) {
        return content.join('\n').length;
    }

    /**
     * Calculate word count
     * @private
     * @param {Array<string>} content - Array of bullet lines
     * @returns {number} Total word count
     */
    function calculateWordCount(content) {
        return content.join(' ').split(/\s+/).filter(word => word.length > 0).length;
    }

    /**
     * Update preview display
     * @private
     * @param {HTMLElement} preview - Preview element
     * @param {Array<string>} content - Bullet content
     */
    function updatePreview(preview, content) {
        preview.innerHTML = '';

        if (content.length === 0) {
            preview.innerHTML = '<p style="color: #6c757d; font-style: italic;">No bullets yet</p>';
            return;
        }

        const ul = document.createElement('ul');
        ul.style.cssText = 'margin: 0; padding-left: 1.5rem;';

        content.forEach(line => {
            const li = document.createElement('li');
            li.textContent = line;
            li.style.marginBottom = '0.25rem';
            ul.appendChild(li);
        });

        preview.appendChild(ul);
    }

    /**
     * Handle keyboard events
     * @private
     * @param {KeyboardEvent} e - Keyboard event
     * @param {HTMLElement} editor - Editor element
     * @param {Object} options - Editor options
     */
    function handleKeyDown(e, editor, options) {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleEnterKey(e, editor, options);
        } else if (e.key === 'Backspace') {
            handleBackspaceKey(e, editor, options);
        } else if (e.key === 'Tab') {
            e.preventDefault();
            handleTabKey(e, editor, options);
        } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            handleArrowKeys(e, editor);
        }
    }

    /**
     * Handle Enter key - create new bullet
     * @private
     * @param {KeyboardEvent} e - Keyboard event
     * @param {HTMLElement} editor - Editor element
     * @param {Object} options - Editor options
     */
    function handleEnterKey(e, editor, options) {
        const sel = window.getSelection();
        if (!sel.rangeCount) return;

        const range = sel.getRangeAt(0);
        let container = range.startContainer;
        if (container.nodeType === Node.TEXT_NODE) {
            container = container.parentElement;
        }

        const currentBullet = container.closest('.agr-bullet-item') || editor;

        // Get current indent level
        const currentLevel = parseInt(currentBullet.getAttribute('data-indent') || '0');

        const newBullet = createBulletItem('\u00A0', currentLevel);

        if (currentBullet === editor) {
            editor.appendChild(newBullet);
        } else {
            currentBullet.parentNode.insertBefore(newBullet, currentBullet.nextSibling);
        }

        // Focus new bullet
        const textNode = newBullet.childNodes[1];
        const newRange = document.createRange();
        newRange.setStart(textNode, 1);
        newRange.collapse(true);
        sel.removeAllRanges();
        sel.addRange(newRange);

        syncToTextarea(editor, options);
        AGR.Utils.announceToScreenReader('New bullet created');
    }

    /**
     * Handle Backspace key - delete or merge bullets
     * @private
     * @param {KeyboardEvent} e - Keyboard event
     * @param {HTMLElement} editor - Editor element
     * @param {Object} options - Editor options
     */
    function handleBackspaceKey(e, editor, options) {
        const sel = window.getSelection();
        if (!sel.rangeCount) return;

        const range = sel.getRangeAt(0);
        let container = range.startContainer;
        if (container.nodeType === Node.TEXT_NODE) {
            container = container.parentElement;
        }

        const currentBullet = container.closest('.agr-bullet-item');

        if (currentBullet && range.collapsed && range.startOffset === 0) {
            const textNode = currentBullet.childNodes[1];

            if (textNode && (textNode.textContent === '' || textNode.textContent === '\u00A0')) {
                e.preventDefault();
                const prevBullet = currentBullet.previousElementSibling;

                if (prevBullet && prevBullet.classList.contains('agr-bullet-item')) {
                    // Merge with previous bullet
                    currentBullet.remove();
                    const prevText = prevBullet.childNodes[1];
                    if (prevText) {
                        const newRange = document.createRange();
                        newRange.setStart(prevText, prevText.textContent.length);
                        newRange.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(newRange);
                    }
                    AGR.Utils.announceToScreenReader('Bullet merged with previous');
                } else if (!prevBullet) {
                    // First bullet, just clear text
                    textNode.textContent = '\u00A0';
                }

                syncToTextarea(editor, options);
            }
        }
    }

    /**
     * Handle Tab key - indent or outdent
     * @private
     * @param {KeyboardEvent} e - Keyboard event
     * @param {HTMLElement} editor - Editor element
     * @param {Object} options - Editor options
     */
    function handleTabKey(e, editor, options) {
        const sel = window.getSelection();
        if (!sel.rangeCount) return;

        const range = sel.getRangeAt(0);
        let container = range.startContainer;
        if (container.nodeType === Node.TEXT_NODE) {
            container = container.parentElement;
        }

        const currentBullet = container.closest('.agr-bullet-item');
        if (!currentBullet) return;

        const currentLevel = parseInt(currentBullet.getAttribute('data-indent') || '0');
        let newLevel = currentLevel;

        if (e.shiftKey) {
            // Outdent
            newLevel = Math.max(0, currentLevel - 1);
        } else {
            // Indent
            newLevel = Math.min(options.maxIndent, currentLevel + 1);
        }

        if (newLevel !== currentLevel) {
            currentBullet.setAttribute('data-indent', newLevel);
            const bulletMarker = currentBullet.querySelector('.agr-bullet-marker');
            bulletMarker.textContent = getBulletForLevel(newLevel) + ' ';

            // Update padding
            currentBullet.style.paddingLeft = `${1 + (newLevel * 1.5)}rem`;
            bulletMarker.style.left = `${newLevel * 1.5}rem`;

            syncToTextarea(editor, options);
            AGR.Utils.announceToScreenReader(e.shiftKey ? 'Bullet outdented' : 'Bullet indented');
        }
    }

    /**
     * Handle Arrow keys - navigate between bullets
     * @private
     * @param {KeyboardEvent} e - Keyboard event
     * @param {HTMLElement} editor - Editor element
     */
    function handleArrowKeys(e, editor) {
        const sel = window.getSelection();
        if (!sel.rangeCount) return;

        const range = sel.getRangeAt(0);
        let container = range.startContainer;
        if (container.nodeType === Node.TEXT_NODE) {
            container = container.parentElement;
        }

        const currentBullet = container.closest('.agr-bullet-item');
        if (!currentBullet) return;

        const textNode = currentBullet.childNodes[1];
        if (!textNode) return;

        if (e.key === 'ArrowUp' && range.startOffset === 0) {
            const prevBullet = currentBullet.previousElementSibling;
            if (prevBullet && prevBullet.classList.contains('agr-bullet-item')) {
                e.preventDefault();
                const prevText = prevBullet.childNodes[1];
                if (prevText) {
                    const newRange = document.createRange();
                    newRange.setStart(prevText, prevText.textContent.length);
                    newRange.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(newRange);
                }
            }
        } else if (e.key === 'ArrowDown') {
            if (range.startOffset >= textNode.textContent.length) {
                const nextBullet = currentBullet.nextElementSibling;
                if (nextBullet && nextBullet.classList.contains('agr-bullet-item')) {
                    e.preventDefault();
                    const nextText = nextBullet.childNodes[1];
                    if (nextText) {
                        const newRange = document.createRange();
                        newRange.setStart(nextText, 0);
                        newRange.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(newRange);
                    }
                }
            }
        }
    }

    /**
     * Handle input events
     * @private
     * @param {InputEvent} e - Input event
     * @param {HTMLElement} editor - Editor element
     * @param {Object} options - Editor options
     */
    function handleInput(e, editor, options) {
        // Replace non-breaking space
        const sel = window.getSelection();
        if (sel.rangeCount) {
            const range = sel.getRangeAt(0);
            if (range.startContainer.nodeType === Node.TEXT_NODE &&
                range.startContainer.textContent === '\u00A0') {
                range.startContainer.textContent = ' ';
                const newRange = document.createRange();
                newRange.setStart(range.startContainer, 0);
                newRange.collapse(true);
                sel.removeAllRanges();
                sel.addRange(newRange);
            }
        }

        syncToTextarea(editor, options);
    }

    /**
     * Handle paste events
     * @private
     * @param {ClipboardEvent} e - Clipboard event
     * @param {HTMLElement} editor - Editor element
     * @param {Object} options - Editor options
     */
    function handlePaste(e, editor, options) {
        e.preventDefault();

        const text = e.clipboardData.getData('text/plain');
        const lines = text.split(/\n+/).filter(line => line.trim());

        if (lines.length === 0) return;

        const sel = window.getSelection();
        if (!sel.rangeCount) return;

        const range = sel.getRangeAt(0);
        let container = range.startContainer;
        if (container.nodeType === Node.TEXT_NODE) {
            container = container.parentElement;
        }

        const currentBullet = container.closest('.agr-bullet-item') || editor;

        // Insert first line into current bullet
        if (currentBullet.classList.contains('agr-bullet-item')) {
            const textNode = currentBullet.childNodes[1];
            if (textNode) {
                textNode.textContent = lines[0];
            }
        }

        // Create bullets for remaining lines
        if (lines.length > 1) {
            const currentLevel = parseInt(currentBullet.getAttribute('data-indent') || '0');
            let insertAfter = currentBullet;

            for (let i = 1; i < lines.length; i++) {
                const newBullet = createBulletItem(lines[i], currentLevel);
                if (insertAfter === editor) {
                    editor.appendChild(newBullet);
                } else {
                    insertAfter.parentNode.insertBefore(newBullet, insertAfter.nextSibling);
                }
                insertAfter = newBullet;
            }
        }

        syncToTextarea(editor, options);
        AGR.Toast.success(`Pasted ${lines.length} bullet(s)`);
    }

    /**
     * Sync editor content to textarea
     * @private
     * @param {HTMLElement} editor - Editor element
     * @param {Object} options - Editor options
     */
    function syncToTextarea(editor, options) {
        const textarea = editor.previousElementSibling;
        if (!textarea || textarea.tagName !== 'TEXTAREA') return;

        const content = getEditorContent(editor);
        textarea.value = content.join('\n');

        // Update counter if enabled
        if (options.showCounter) {
            const wrapper = editor.closest('.agr-bullet-editor-wrapper');
            if (wrapper) {
                const counter = wrapper.querySelector('.agr-bullet-counter');
                if (counter) {
                    const charCount = calculateCharCount(content);
                    updateCounter(counter, charCount, options.maxLength);
                }
            }
        }

        // Trigger custom event for external listeners
        textarea.dispatchEvent(new CustomEvent('bulletChange', {
            detail: { content, charCount: calculateCharCount(content) }
        }));
    }

    /**
     * Auto-save draft to localStorage
     * @private
     * @param {string} editorId - Editor ID
     * @param {HTMLElement} editor - Editor element
     */
    function autoSave(editorId, editor) {
        const content = getEditorContent(editor);
        const key = `agr-bullet-draft-${editorId}`;
        localStorage.setItem(key, JSON.stringify({
            content,
            timestamp: Date.now()
        }));
    }

    /**
     * Load draft from localStorage
     * @private
     * @param {string} editorId - Editor ID
     * @returns {Array<string>|null} Draft content or null
     */
    function loadDraft(editorId) {
        const key = `agr-bullet-draft-${editorId}`;
        const draft = localStorage.getItem(key);
        if (draft) {
            try {
                const data = JSON.parse(draft);
                // Restore if less than 24 hours old
                if (Date.now() - data.timestamp < 24 * 60 * 60 * 1000) {
                    return data.content;
                }
            } catch (e) {
                console.warn('Failed to load draft:', e);
            }
        }
        return null;
    }

    /**
     * Clear draft from localStorage
     * @private
     * @param {string} editorId - Editor ID
     */
    function clearDraft(editorId) {
        const key = `agr-bullet-draft-${editorId}`;
        localStorage.removeItem(key);
    }

    /**
     * Initialize bullet editor for a textarea
     * @public
     * @param {string|HTMLElement} selector - CSS selector or textarea element
     * @param {Object} options - Configuration options
     * @returns {string|null} Editor ID or null on failure
     */
    function init(selector, options = {}) {
        const textarea = typeof selector === 'string'
            ? document.querySelector(selector)
            : selector;

        if (!textarea || textarea.tagName !== 'TEXTAREA') {
            console.error('BulletEditor: Textarea not found');
            return null;
        }

        // Merge options with defaults
        const opts = { ...DEFAULTS, ...options };

        // Generate unique editor ID
        const editorId = generateEditorId();

        // Create wrapper
        const wrapper = document.createElement('div');
        wrapper.className = 'agr-bullet-editor-wrapper';
        wrapper.id = `${editorId}-wrapper`;

        // Create toolbar
        if (opts.enablePreview) {
            const toolbar = createToolbar(editorId);
            wrapper.appendChild(toolbar);
        }

        // Create editor
        const editor = document.createElement('div');
        editor.id = editorId;
        editor.className = 'agr-bullet-editor';
        editor.contentEditable = true;
        editor.setAttribute('role', 'textbox');
        editor.setAttribute('aria-multiline', 'true');
        editor.setAttribute('aria-label', opts.placeholder || 'Bullet point editor');
        editor.style.cssText = `
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            padding: 0.5rem 0.5rem 0.5rem 2rem;
            min-height: 80px;
            max-height: 300px;
            overflow-y: auto;
            overflow-x: hidden;
            font-size: 0.9rem;
            line-height: 1.5;
            background: white;
        `;

        // Load initial content
        let initialValue = textarea.value.trim();

        // Check for draft
        const draft = loadDraft(editorId);
        if (draft && draft.length > 0) {
            initialValue = draft.join('\n');
            // Toast is optional - only show if AGR.Toast exists
            if (typeof AGR !== 'undefined' && AGR.Toast && AGR.Toast.info) {
                AGR.Toast.info('Draft restored from auto-save');
            }
        }

        if (initialValue) {
            initialValue.split(/\n/).forEach(line => {
                if (line.trim()) {
                    const bullet = createBulletItem(line.trim());
                    editor.appendChild(bullet);
                }
            });
        }

        // Create counter if enabled
        if (opts.showCounter) {
            const counter = createCounter(editorId, opts.maxLength);
            wrapper.appendChild(editor);
            wrapper.appendChild(counter);

            // Initial counter update
            const content = getEditorContent(editor);
            updateCounter(counter, calculateCharCount(content), opts.maxLength);
        } else {
            wrapper.appendChild(editor);
        }

        // Create preview if enabled
        let preview = null;
        let previewBtn = null;
        if (opts.enablePreview) {
            preview = createPreviewDisplay(editorId);
            previewBtn = createPreviewButton(editorId);
            wrapper.appendChild(previewBtn);
            wrapper.appendChild(preview);

            // Preview toggle
            previewBtn.addEventListener('click', function() {
                const isPressed = this.getAttribute('aria-pressed') === 'true';
                if (isPressed) {
                    preview.style.display = 'none';
                    editor.style.display = 'block';
                    this.textContent = 'Preview';
                    this.setAttribute('aria-pressed', 'false');
                } else {
                    const content = getEditorContent(editor);
                    updatePreview(preview, content);
                    preview.style.display = 'block';
                    editor.style.display = 'none';
                    this.textContent = 'Edit';
                    this.setAttribute('aria-pressed', 'true');
                }
            });
        }

        // Event listeners
        editor.addEventListener('keydown', function(e) {
            handleKeyDown(e, editor, opts);
        });

        editor.addEventListener('input', function(e) {
            handleInput(e, editor, opts);
        });

        editor.addEventListener('paste', function(e) {
            handlePaste(e, editor, opts);
        });

        editor.addEventListener('focus', function() {
            this.style.borderColor = '#80bdff';
            this.style.outline = '0';
            this.style.boxShadow = '0 0 0 0.2rem rgba(0, 123, 255, 0.25)';
        });

        editor.addEventListener('blur', function() {
            this.style.borderColor = '#ced4da';
            this.style.boxShadow = 'none';
        });

        // Auto-save
        let autoSaveTimer = null;
        if (opts.autoSaveDelay > 0) {
            editor.addEventListener('input', AGR.Utils.debounce(function() {
                autoSave(editorId, editor);
            }, opts.autoSaveDelay));
        }

        // Insert editor before textarea and hide textarea
        textarea.parentNode.insertBefore(wrapper, textarea);
        textarea.style.display = 'none';

        // Store editor reference
        textarea._bulletEditorId = editorId;
        editors.set(editorId, {
            wrapper,
            editor,
            textarea,
            preview,
            previewBtn,
            options: opts
        });

        AGR.Utils.announceToScreenReader('Bullet editor initialized');

        return editorId;
    }

    /**
     * Get content from bullet editor
     * @public
     * @param {string} editorId - Editor ID
     * @returns {Array<string>} Array of bullet lines
     */
    function getContent(editorId) {
        const instance = editors.get(editorId);
        if (!instance) {
            console.error('BulletEditor: Editor not found', editorId);
            return [];
        }
        return getEditorContent(instance.editor);
    }

    /**
     * Set content of bullet editor
     * @public
     * @param {string} editorId - Editor ID
     * @param {string|Array<string>} content - Content to set
     */
    function setContent(editorId, content) {
        const instance = editors.get(editorId);
        if (!instance) {
            console.error('BulletEditor: Editor not found', editorId);
            return;
        }

        const lines = typeof content === 'string'
            ? content.split(/\n+/).filter(line => line.trim())
            : content.filter(line => line.trim());

        instance.editor.innerHTML = '';

        lines.forEach(line => {
            const bullet = createBulletItem(line);
            instance.editor.appendChild(bullet);
        });

        syncToTextarea(instance.editor, instance.options);
    }

    /**
     * Destroy bullet editor
     * @public
     * @param {string} editorId - Editor ID
     */
    function destroy(editorId) {
        const instance = editors.get(editorId);
        if (!instance) {
            console.error('BulletEditor: Editor not found', editorId);
            return;
        }

        // Sync final content
        syncToTextarea(instance.editor, instance.options);

        // Show textarea
        instance.textarea.style.display = '';
        instance.textarea._bulletEditorId = null;

        // Remove wrapper
        instance.wrapper.remove();

        // Clear draft
        clearDraft(editorId);

        // Remove from editors map
        editors.delete(editorId);

        AGR.Utils.announceToScreenReader('Bullet editor destroyed');
    }

    /**
     * Initialize all matching textareas
     * @public
     * @param {string} selector - CSS selector for textareas
     * @param {Object} options - Configuration options
     * @returns {Array<string>} Array of editor IDs
     */
    function initAll(selector, options = {}) {
        const textareas = document.querySelectorAll(selector);
        const editorIds = [];

        textareas.forEach(textarea => {
            const editorId = init(textarea, options);
            if (editorId) {
                editorIds.push(editorId);
            }
        });

        return editorIds;
    }

    /**
     * Sync all editors to their textareas
     * Call this before form submission
     * @public
     */
    function syncAll() {
        editors.forEach((instance, editorId) => {
            syncToTextarea(instance.editor, instance.options);
        });
    }

    /**
     * Clear all drafts from localStorage
     * @public
     */
    function clearAllDrafts() {
        Object.keys(localStorage)
            .filter(key => key.startsWith('agr-bullet-draft-'))
            .forEach(key => localStorage.removeItem(key));
    }

    // Public API
    return {
        init,
        initAll,
        getContent,
        setContent,
        destroy,
        syncAll,
        clearAllDrafts,
        // Version info
        version: '1.0.0'
    };
})();

// Auto-initialize if data attribute present
document.addEventListener('DOMContentLoaded', function() {
    const autoTextareas = document.querySelectorAll('textarea[data-bullet-editor="auto"]');
    if (autoTextareas.length > 0) {
        AGR.BulletEditor.initAll('textarea[data-bullet-editor="auto"]');
    }
});

// Sync all editors on form submit
document.addEventListener('submit', function(e) {
    if (e.target && e.target.tagName === 'FORM') {
        AGR.BulletEditor.syncAll();
    }
}, true);
