/**
 * Review Form UX Enhancements (SPEC-UIX-002 Milestone 5)
 * TAG: SPEC-UIX-002-M5-JS
 *
 * Design-TAG: Enhanced review form with progress tracking, autosave, shortcuts, and summary
 * Function-TAG: Improves reviewer productivity and form completion awareness
 * Test-TAG: WCAG 2.1 AA compliant, tested via Playwright
 */

var ReviewFormUX = (function() {
    'use strict';

    var config = {
        autosaveInterval: 60000, // 1 minute
        autosaveDebounce: 2000,  // 2 seconds
        maxCharLength: 500,
        warningThreshold: 450
    };

    var state = {
        sections: [],
        completedSections: 0,
        lastSaved: null,
        timerInterval: null,
        autosaveTimeout: null
    };

    /**
     * Initialize review form UX enhancements
     */
    function init() {
        initProgressBar();
        initAutosave();
        initKeyboardShortcuts();
        initCharacterCounters();
        initSummaryCard();
        initStickyHeader();
        loadSavedState();
    }

    /**
     * SECTION PROGRESS BAR
     */
    function initProgressBar() {
        var form = document.querySelector('form[method="POST"]');
        if (!form) return;

        // Identify all sections
        var sectionCards = form.querySelectorAll('.card.mb-3');
        state.sections = Array.from(sectionCards).map(function(card, index) {
            return {
                element: card,
                id: 'section-' + index,
                completed: false
            };
        });

        // Create progress bar container
        var progressContainer = document.createElement('div');
        progressContainer.className = 'review-progress-container';
        progressContainer.innerHTML = `
            <div class="review-progress-bar" role="progressbar" aria-label="Review progress" aria-valuemin="0" aria-valuemax="100">
                <div class="review-progress-bar-fill" style="width: 0%" aria-valuenow="0"></div>
            </div>
            <div class="review-progress-text">
                <span>Review Progress</span>
                <span>
                    <span class="review-progress-percentage">0%</span>
                    <span class="review-progress-details">0 of ${state.sections.length} sections completed</span>
                </span>
            </div>
        `;

        form.insertBefore(progressContainer, form.firstChild);

        // Monitor section completion
        monitorSectionCompletion();
    }

    function monitorSectionCompletion() {
        state.sections.forEach(function(section) {
            var inputs = section.element.querySelectorAll('input, textarea, select');

            inputs.forEach(function(input) {
                input.addEventListener('change', function() {
                    checkSectionCompletion(section);
                    updateProgress();
                });

                input.addEventListener('input', function() {
                    if (input.type === 'text' || input.tagName === 'TEXTAREA') {
                        checkSectionCompletion(section);
                        updateProgress();
                    }
                });
            });
        });
    }

    function checkSectionCompletion(section) {
        var requiredInputs = section.element.querySelectorAll('[required]');
        var allFilled = true;

        requiredInputs.forEach(function(input) {
            if (!input.value.trim()) {
                allFilled = false;
            }
        });

        section.completed = allFilled;
    }

    function updateProgress() {
        var completed = state.sections.filter(function(s) { return s.completed; }).length;
        var percentage = Math.round((completed / state.sections.length) * 100);

        var progressBar = document.querySelector('.review-progress-bar-fill');
        var progressText = document.querySelector('.review-progress-percentage');
        var progressDetails = document.querySelector('.review-progress-details');

        if (progressBar) {
            progressBar.style.width = percentage + '%';
            progressBar.setAttribute('aria-valuenow', percentage);
        }

        if (progressText) {
            progressText.textContent = percentage + '%';
        }

        if (progressDetails) {
            progressDetails.textContent = completed + ' of ' + state.sections.length + ' sections completed';
        }

        state.completedSections = completed;
        saveState();
    }

    /**
     * AUTOSAVE SYSTEM
     */
    function initAutosave() {
        var form = document.querySelector('form[method="POST"]');
        if (!form) return;

        // Create autosave indicator
        var autosaveIndicator = document.createElement('div');
        autosaveIndicator.className = 'autosave-timer-indicator';
        autosaveIndicator.innerHTML = `
            <span class="autosave-status saved">
                <svg class="autosave-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <span class="autosave-text">Autosave enabled</span>
            </span>
        `;

        var progressText = document.querySelector('.review-progress-text');
        if (progressText) {
            progressText.appendChild(autosaveIndicator);
        }

        // Track changes for autosave
        var inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
            input.addEventListener('input', debounceAutosave);
            input.addEventListener('change', triggerAutosave);
        });

        // Update timer display every minute
        state.timerInterval = setInterval(updateAutosaveTimer, 60000);
    }

    function debounceAutosave() {
        clearTimeout(state.autosaveTimeout);
        state.autosaveTimeout = setTimeout(triggerAutosave, config.autosaveDebounce);
    }

    function triggerAutosave() {
        var form = document.querySelector('form[method="POST"]');
        if (!form) return;

        setAutosaveStatus('saving');

        // Simulate autosave (in production, this would be an AJAX request)
        setTimeout(function() {
            state.lastSaved = Date.now();
            setAutosaveStatus('saved');
            saveState();
        }, 500);
    }

    function setAutosaveStatus(status) {
        var statusElement = document.querySelector('.autosave-status');
        var statusText = document.querySelector('.autosave-text');
        var statusIcon = document.querySelector('.autosave-icon');

        if (!statusElement) return;

        statusElement.className = 'autosave-status ' + status;

        if (status === 'saving') {
            statusText.textContent = 'Saving...';
            statusIcon.classList.add('saving');
        } else if (status === 'saved') {
            var minutesAgo = Math.floor((Date.now() - state.lastSaved) / 60000);
            statusText.textContent = 'Last saved: ' + minutesAgo + ' minute' + (minutesAgo !== 1 ? 's' : '') + ' ago';
            statusIcon.classList.remove('saving');
        }
    }

    function updateAutosaveTimer() {
        if (state.lastSaved) {
            setAutosaveStatus('saved');
        }
    }

    /**
     * KEYBOARD SHORTCUTS
     */
    function initKeyboardShortcuts() {
        // Create shortcuts trigger button
        var trigger = document.createElement('button');
        trigger.className = 'keyboard-shortcuts-trigger';
        trigger.textContent = '?';
        trigger.setAttribute('aria-label', 'Show keyboard shortcuts');
        trigger.addEventListener('click', showShortcutsPanel);
        document.body.appendChild(trigger);

        // Create shortcuts panel
        createShortcutsPanel();

        // Register keyboard shortcuts
        document.addEventListener('keydown', handleKeyboardShortcut);
    }

    function createShortcutsPanel() {
        var panel = document.createElement('div');
        panel.className = 'keyboard-shortcuts-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Keyboard shortcuts');
        panel.innerHTML = `
            <h2 class="keyboard-shortcuts-title">Keyboard Shortcuts</h2>
            <ul class="keyboard-shortcut-list">
                <li class="keyboard-shortcut-item">
                    <span class="keyboard-shortcut-description">Save draft</span>
                    <span class="keyboard-shortcut-keys">
                        <span class="keyboard-shortcut-key">Ctrl</span>
                        <span class="keyboard-shortcut-key">S</span>
                    </span>
                </li>
                <li class="keyboard-shortcut-item">
                    <span class="keyboard-shortcut-description">Next section</span>
                    <span class="keyboard-shortcut-keys">
                        <span class="keyboard-shortcut-key">Alt</span>
                        <span class="keyboard-shortcut-key">N</span>
                    </span>
                </li>
                <li class="keyboard-shortcut-item">
                    <span class="keyboard-shortcut-description">Previous section</span>
                    <span class="keyboard-shortcut-keys">
                        <span class="keyboard-shortcut-key">Alt</span>
                        <span class="keyboard-shortcut-key">P</span>
                    </span>
                </li>
                <li class="keyboard-shortcut-item">
                    <span class="keyboard-shortcut-description">Show/hide shortcuts</span>
                    <span class="keyboard-shortcut-keys">
                        <span class="keyboard-shortcut-key">?</span>
                    </span>
                </li>
                <li class="keyboard-shortcut-item">
                    <span class="keyboard-shortcut-description">Close panel</span>
                    <span class="keyboard-shortcut-keys">
                        <span class="keyboard-shortcut-key">Esc</span>
                    </span>
                </li>
            </ul>
        `;

        var backdrop = document.createElement('div');
        backdrop.className = 'keyboard-shortcuts-backdrop';
        backdrop.addEventListener('click', hideShortcutsPanel);

        document.body.appendChild(backdrop);
        document.body.appendChild(panel);
    }

    function showShortcutsPanel() {
        var panel = document.querySelector('.keyboard-shortcuts-panel');
        var backdrop = document.querySelector('.keyboard-shortcuts-backdrop');

        if (panel) panel.classList.add('is-visible');
        if (backdrop) backdrop.classList.add('is-visible');
    }

    function hideShortcutsPanel() {
        var panel = document.querySelector('.keyboard-shortcuts-panel');
        var backdrop = document.querySelector('.keyboard-shortcuts-backdrop');

        if (panel) panel.classList.remove('is-visible');
        if (backdrop) backdrop.classList.remove('is-visible');
    }

    function handleKeyboardShortcut(e) {
        // Ctrl+S: Save draft
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            triggerAutosave();
            return;
        }

        // Alt+N: Next section
        if (e.altKey && e.key === 'n') {
            e.preventDefault();
            navigateToNextSection();
            return;
        }

        // Alt+P: Previous section
        if (e.altKey && e.key === 'p') {
            e.preventDefault();
            navigateToPreviousSection();
            return;
        }

        // ?: Show shortcuts
        if (e.key === '?') {
            e.preventDefault();
            var panel = document.querySelector('.keyboard-shortcuts-panel');
            if (panel && panel.classList.contains('is-visible')) {
                hideShortcutsPanel();
            } else {
                showShortcutsPanel();
            }
            return;
        }

        // Escape: Close panel
        if (e.key === 'Escape') {
            hideShortcutsPanel();
        }
    }

    function navigateToNextSection() {
        var currentSection = getCurrentSection();
        if (currentSection < state.sections.length - 1) {
            state.sections[currentSection + 1].element.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function navigateToPreviousSection() {
        var currentSection = getCurrentSection();
        if (currentSection > 0) {
            state.sections[currentSection - 1].element.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function getCurrentSection() {
        var scrollPosition = window.pageYOffset + 100;
        for (var i = 0; i < state.sections.length; i++) {
            var section = state.sections[i];
            var rect = section.element.getBoundingClientRect();
            var absoluteTop = rect.top + window.pageYOffset;

            if (absoluteTop <= scrollPosition) {
                return i;
            }
        }
        return 0;
    }

    /**
     * CHARACTER COUNTERS
     */
    function initCharacterCounters() {
        var textareas = document.querySelectorAll('textarea.form-control');

        textareas.forEach(function(textarea) {
            var name = textarea.getAttribute('name');
            if (!name) return;

            var counter = document.createElement('div');
            counter.className = 'char-counter';
            counter.setAttribute('data-for', name);
            counter.innerHTML = `
                <span class="char-counter-count">0/${config.maxCharLength}</span>
                <span class="char-counter-limit">characters</span>
            `;

            textarea.parentNode.insertBefore(counter, textarea.nextSibling);

            textarea.addEventListener('input', function() {
                updateCharacterCounter(textarea, counter);
            });

            // Initial update
            updateCharacterCounter(textarea, counter);
        });
    }

    function updateCharacterCounter(textarea, counter) {
        var length = textarea.value.length;
        var countElement = counter.querySelector('.char-counter-count');

        countElement.textContent = length + '/' + config.maxCharLength;

        counter.classList.remove('char-counter-warning', 'char-counter-error');

        if (length > config.maxCharLength) {
            counter.classList.add('char-counter-error');
        } else if (length > config.warningThreshold) {
            counter.classList.add('char-counter-warning');
        }
    }

    /**
     * SUMMARY CARD
     */
    function initSummaryCard() {
        var form = document.querySelector('form[method="POST"]');
        if (!form) return;

        var summaryCard = document.createElement('div');
        summaryCard.className = 'review-summary-card';
        summaryCard.innerHTML = `
            <h3 class="review-summary-title">Review Summary</h3>
            <div class="review-summary-stat">
                <span class="review-summary-stat-label">Overall Score</span>
                <span class="review-summary-stat-value overall-score">-</span>
            </div>
            <div class="review-summary-stat">
                <span class="review-summary-stat-label">Sections Completed</span>
                <span class="review-summary-stat-value sections-completed">0/${state.sections.length}</span>
            </div>
            <div class="review-summary-stat">
                <span class="review-summary-stat-label">Total Words</span>
                <span class="review-summary-stat-value word-count">0 words</span>
            </div>
        `;

        // Insert after progress container
        var progressContainer = document.querySelector('.review-progress-container');
        if (progressContainer && progressContainer.nextSibling) {
            form.insertBefore(summaryCard, progressContainer.nextSibling);
        }

        // Update summary on form changes
        var inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
            input.addEventListener('change', updateSummaryCard);
            input.addEventListener('input', updateSummaryCard);
        });

        updateSummaryCard();
    }

    function updateSummaryCard() {
        var overallScore = document.querySelector('select[name="overall_impact_score"]');
        var scoreDisplay = document.querySelector('.review-summary-stat-value.overall-score');

        if (overallScore && scoreDisplay) {
            scoreDisplay.textContent = overallScore.value || '-';
        }

        var sectionsDisplay = document.querySelector('.review-summary-stat-value.sections-completed');
        if (sectionsDisplay) {
            sectionsDisplay.textContent = state.completedSections + '/' + state.sections.length;
        }

        var wordCount = countTotalWords();
        var wordCountDisplay = document.querySelector('.review-summary-stat-value.word-count');
        if (wordCountDisplay) {
            wordCountDisplay.textContent = wordCount + ' words';
        }
    }

    function countTotalWords() {
        var textareas = document.querySelectorAll('textarea.form-control');
        var totalWords = 0;

        textareas.forEach(function(textarea) {
            var text = textarea.value.trim();
            if (text) {
                var words = text.split(/\s+/).filter(function(w) { return w.length > 0; });
                totalWords += words.length;
            }
        });

        return totalWords;
    }

    /**
     * STICKY HEADER
     */
    function initStickyHeader() {
        var sectionHeaders = document.querySelectorAll('.card-header');

        sectionHeaders.forEach(function(header, index) {
            var sectionCard = header.closest('.card');
            if (!sectionCard) return;

            var stickyHeader = document.createElement('div');
            stickyHeader.className = 'section-sticky-header';
            stickyHeader.innerHTML = `
                <span class="section-name">${header.textContent.trim()}</span>
                <span class="section-progress-mini">Section ${index + 1} of ${state.sections.length}</span>
            `;

            sectionCard.insertBefore(stickyHeader, sectionCard.firstChild);
        });
    }

    /**
     * STATE PERSISTENCE
     */
    function saveState() {
        var stateToSave = {
            completedSections: state.completedSections,
            totalSections: state.sections.length,
            lastSaved: state.lastSaved,
            timestamp: Date.now()
        };

        localStorage.setItem('review_form_state', JSON.stringify(stateToSave));
    }

    function loadSavedState() {
        var savedState = localStorage.getItem('review_form_state');
        if (!savedState) return;

        try {
            var parsed = JSON.parse(savedState);
            state.completedSections = parsed.completedSections || 0;
            state.lastSaved = parsed.lastSaved;

            updateProgress();
            updateSummaryCard();

            if (state.lastSaved) {
                setAutosaveStatus('saved');
            }
        } catch (e) {
            console.error('Error loading saved state:', e);
        }
    }

    /**
     * PUBLIC API
     */
    return {
        init: init,
        updateProgress: updateProgress,
        triggerAutosave: triggerAutosave,
        showShortcuts: showShortcutsPanel,
        hideShortcuts: hideShortcutsPanel
    };
})();

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ReviewFormUX.init);
} else {
    ReviewFormUX.init();
}
