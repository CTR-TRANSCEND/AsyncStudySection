/**
 * Dashboard Enhancement JavaScript (SPEC-UIX-002 Milestone 6)
 * TAG: SPEC-UIX-002-M6-JS
 *
 * Design-TAG: Enhanced dashboard with progress bars, filters, and statistics
 * Function-TAG: Improves dashboard usability and information density
 * Test-TAG: WCAG 2.1 AA compliant, tested via Playwright
 */

var DashboardEnhancement = (function() {
    'use strict';

    var state = {
        filters: {
            status: 'all',
            section: 'all',
            grantType: 'all'
        },
        applications: []
    };

    /**
     * Initialize dashboard enhancements
     */
    function init() {
        initStatisticsCards();
        initFilters();
        initProgressBars();
        initDeadlineChips();
        loadFilterState();
        applyFilters();
    }

    /**
     * STATISTICS CARDS
     */
    function initStatisticsCards() {
        var applications = document.querySelectorAll('.application-card');
        var stats = {
            total: applications.length,
            pending: 0,
            inProgress: 0,
            completed: 0,
            overdue: 0
        };

        applications.forEach(function(card) {
            var status = card.getAttribute('data-status') || 'pending';
            var isOverdue = card.getAttribute('data-overdue') === 'true';

            if (status === 'pending') stats.pending++;
            else if (status === 'in-progress') stats.inProgress++;
            else if (status === 'completed') stats.completed++;

            if (isOverdue) stats.overdue++;
        });

        // Create stats cards HTML
        var statsHTML = '<div class="stats-grid">' +
            '<div class="stats-card" data-stat="total">' +
                '<div class="stats-card-icon icon-primary">📋</div>' +
                '<div class="stats-card-content">' +
                    '<div class="stats-card-value">' + stats.total + '</div>' +
                    '<div class="stats-card-label">Total Applications</div>' +
                '</div>' +
            '</div>' +
            '<div class="stats-card" data-stat="pending">' +
                '<div class="stats-card-icon icon-warning">⏳</div>' +
                '<div class="stats-card-content">' +
                    '<div class="stats-card-value">' + stats.pending + '</div>' +
                    '<div class="stats-card-label">Pending</div>' +
                '</div>' +
            '</div>' +
            '<div class="stats-card" data-stat="in-progress">' +
                '<div class="stats-card-icon icon-primary">🔄</div>' +
                '<div class="stats-card-content">' +
                    '<div class="stats-card-value">' + stats.inProgress + '</div>' +
                    '<div class="stats-card-label">In Progress</div>' +
                '</div>' +
            '</div>' +
            '<div class="stats-card" data-stat="completed">' +
                '<div class="stats-card-icon icon-success">✓</div>' +
                '<div class="stats-card-content">' +
                    '<div class="stats-card-value">' + stats.completed + '</div>' +
                    '<div class="stats-card-label">Completed</div>' +
                '</div>' +
            '</div>';

        if (stats.overdue > 0) {
            statsHTML += '<div class="stats-card" data-stat="overdue">' +
                '<div class="stats-card-icon icon-danger">⚠</div>' +
                '<div class="stats-card-content">' +
                    '<div class="stats-card-value">' + stats.overdue + '</div>' +
                    '<div class="stats-card-label">Overdue</div>' +
                '</div>' +
            '</div>';
        }

        statsHTML += '</div>';

        // Insert stats grid at the top of the page
        var container = document.querySelector('.dashboard-container') || document.querySelector('main');
        if (container) {
            container.insertAdjacentHTML('afterbegin', statsHTML);
        }
    }

    /**
     * QUICK FILTERS
     */
    function initFilters() {
        // Create filter HTML
        var filterHTML = '<div class="dashboard-filters">' +
            '<div class="filter-group">' +
                '<label class="filter-label" for="filter-status">Status</label>' +
                '<select id="filter-status" class="filter-select" aria-label="Filter by status">' +
                    '<option value="all">All Statuses</option>' +
                    '<option value="pending">Pending</option>' +
                    '<option value="in-progress">In Progress</option>' +
                    '<option value="completed">Completed</option>' +
                '</select>' +
            '</div>' +
            '<div class="filter-group">' +
                '<label class="filter-label" for="filter-section">Study Section</label>' +
                '<select id="filter-section" class="filter-select" aria-label="Filter by study section">' +
                    '<option value="all">All Sections</option>' +
                '</select>' +
            '</div>' +
            '<div class="filter-group">' +
                '<label class="filter-label" for="filter-grant-type">Grant Type</label>' +
                '<select id="filter-grant-type" class="filter-select" aria-label="Filter by grant type">' +
                    '<option value="all">All Types</option>' +
                '</select>' +
            '</div>' +
            '<button class="reset-filters" type="button">Reset Filters</button>' +
            '<div class="filter-announcements" aria-live="polite" aria-atomic="true"></div>' +
        '</div>';

        // Insert filters
        var container = document.querySelector('.dashboard-container') || document.querySelector('main');
        if (container) {
            var statsGrid = container.querySelector('.stats-grid');
            if (statsGrid && statsGrid.nextSibling) {
                statsGrid.nextSibling.insertAdjacentHTML('beforebegin', filterHTML);
            } else {
                container.insertAdjacentHTML('afterbegin', filterHTML);
            }
        }

        // Populate dynamic filter options
        populateFilterOptions();

        // Attach event listeners
        var statusFilter = document.getElementById('filter-status');
        var sectionFilter = document.getElementById('filter-section');
        var grantTypeFilter = document.getElementById('filter-grant-type');
        var resetButton = document.querySelector('.reset-filters');

        if (statusFilter) {
            statusFilter.addEventListener('change', function() {
                state.filters.status = this.value;
                saveFilterState();
                applyFilters();
            });
        }

        if (sectionFilter) {
            sectionFilter.addEventListener('change', function() {
                state.filters.section = this.value;
                saveFilterState();
                applyFilters();
            });
        }

        if (grantTypeFilter) {
            grantTypeFilter.addEventListener('change', function() {
                state.filters.grantType = this.value;
                saveFilterState();
                applyFilters();
            });
        }

        if (resetButton) {
            resetButton.addEventListener('click', resetFilters);
        }
    }

    function populateFilterOptions() {
        var applications = document.querySelectorAll('.application-card');
        var sections = new Set();
        var grantTypes = new Set();

        applications.forEach(function(card) {
            var section = card.getAttribute('data-section');
            var grantType = card.getAttribute('data-grant-type');

            if (section) sections.add(section);
            if (grantType) grantTypes.add(grantType);
        });

        var sectionFilter = document.getElementById('filter-section');
        if (sectionFilter) {
            sections.forEach(function(section) {
                var option = document.createElement('option');
                option.value = section;
                option.textContent = section;
                sectionFilter.appendChild(option);
            });
        }

        var grantTypeFilter = document.getElementById('filter-grant-type');
        if (grantTypeFilter) {
            grantTypes.forEach(function(type) {
                var option = document.createElement('option');
                option.value = type;
                option.textContent = type;
                grantTypeFilter.appendChild(option);
            });
        }
    }

    function applyFilters() {
        var applications = document.querySelectorAll('.application-card');
        var visibleCount = 0;

        applications.forEach(function(card) {
            var status = card.getAttribute('data-status') || 'pending';
            var section = card.getAttribute('data-section') || 'all';
            var grantType = card.getAttribute('data-grant-type') || 'all';

            var matchesStatus = state.filters.status === 'all' || status === state.filters.status;
            var matchesSection = state.filters.section === 'all' || section === state.filters.section;
            var matchesGrantType = state.filters.grantType === 'all' || grantType === state.filters.grantType;

            if (matchesStatus && matchesSection && matchesGrantType) {
                card.classList.remove('hidden', 'fade-out');
                card.classList.add('fade-in');
                visibleCount++;
            } else {
                card.classList.add('fade-out');
                setTimeout(function() {
                    card.classList.add('hidden');
                    card.classList.remove('fade-in');
                }, 300);
            }
        });

        // Show empty state if no applications match
        showEmptyState(visibleCount === 0);

        // Announce to screen readers
        announceFilterResults(visibleCount);
    }

    function showEmptyState(show) {
        var existingEmptyState = document.querySelector('.empty-state');
        if (existingEmptyState) {
            existingEmptyState.remove();
        }

        if (show) {
            var emptyStateHTML = '<div class="empty-state">' +
                '<svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
                    '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>' +
                '</svg>' +
                '<h2 class="empty-state-title">No applications found</h2>' +
                '<p class="empty-state-description">Try adjusting your filters to see more applications.</p>' +
                '<button class="reset-filters" type="button">Reset Filters</button>' +
            '</div>';

            var container = document.querySelector('.dashboard-container') || document.querySelector('main');
            if (container) {
                container.insertAdjacentHTML('beforeend', emptyStateHTML);

                // Attach event listener to new reset button
                var newResetButton = container.querySelector('.empty-state .reset-filters');
                if (newResetButton) {
                    newResetButton.addEventListener('click', resetFilters);
                }
            }
        }
    }

    function announceFilterResults(count) {
        var announcement = document.querySelector('.filter-announcements');
        if (announcement) {
            var message = count === 1 ? 'Showing 1 application' : 'Showing ' + count + ' applications';
            announcement.textContent = message;
        }
    }

    function resetFilters() {
        state.filters = {
            status: 'all',
            section: 'all',
            grantType: 'all'
        };

        var statusFilter = document.getElementById('filter-status');
        var sectionFilter = document.getElementById('filter-section');
        var grantTypeFilter = document.getElementById('filter-grant-type');

        if (statusFilter) statusFilter.value = 'all';
        if (sectionFilter) sectionFilter.value = 'all';
        if (grantTypeFilter) grantTypeFilter.value = 'all';

        saveFilterState();
        applyFilters();
    }

    /**
     * PROGRESS BARS
     */
    function initProgressBars() {
        var applications = document.querySelectorAll('.application-card');

        applications.forEach(function(card) {
            var progress = parseInt(card.getAttribute('data-progress') || '0');
            var titleElement = card.querySelector('.application-card-title');

            if (titleElement && progress >= 0) {
                var progressLevel = progress < 33 ? 'low' : progress < 66 ? 'medium' : 'high';
                var progressBarHTML = '<div class="application-progress-bar" role="progressbar" aria-label="Review progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + progress + '">' +
                    '<div class="application-progress-fill" style="width: ' + progress + '%;" data-progress="' + progressLevel + '"></div>' +
                '</div>';

                titleElement.insertAdjacentHTML('afterend', progressBarHTML);
            }
        });
    }

    /**
     * DEADLINE CHIPS
     */
    function initDeadlineChips() {
        var applications = document.querySelectorAll('.application-card');

        applications.forEach(function(card) {
            var deadline = card.getAttribute('data-deadline');
            var isOverdue = card.getAttribute('data-overdue') === 'true';
            var metaElement = card.querySelector('.application-card-meta');

            if (deadline && metaElement) {
                var daysUntil = calculateDaysUntil(deadline);
                var chipClass = 'status-on-track';
                var chipText = 'On track';
                var chipIcon = '✓';

                if (isOverdue) {
                    chipClass = 'status-overdue';
                    chipText = 'Overdue';
                    chipIcon = '⚠';
                } else if (daysUntil <= 3) {
                    chipClass = 'status-due-soon';
                    chipText = daysUntil === 0 ? 'Due today' : daysUntil === 1 ? 'Due tomorrow' : 'Due in ' + daysUntil + ' days';
                    chipIcon = '⏰';
                }

                var chipHTML = '<span class="deadline-chip ' + chipClass + '">' +
                    '<span class="deadline-chip-icon">' + chipIcon + '</span>' +
                    chipText +
                '</span>';

                metaElement.insertAdjacentHTML('beforeend', chipHTML);
            }
        });
    }

    function calculateDaysUntil(deadline) {
        var deadlineDate = new Date(deadline);
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        deadlineDate.setHours(0, 0, 0, 0);

        var diffTime = deadlineDate - today;
        var diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

        return diffDays;
    }

    /**
     * FILTER PERSISTENCE
     */
    function saveFilterState() {
        localStorage.setItem('dashboard_filter_status', state.filters.status);
        localStorage.setItem('dashboard_filter_section', state.filters.section);
        localStorage.setItem('dashboard_filter_grant_type', state.filters.grantType);
    }

    function loadFilterState() {
        var savedStatus = localStorage.getItem('dashboard_filter_status');
        var savedSection = localStorage.getItem('dashboard_filter_section');
        var savedGrantType = localStorage.getItem('dashboard_filter_grant_type');

        if (savedStatus) {
            state.filters.status = savedStatus;
            var statusFilter = document.getElementById('filter-status');
            if (statusFilter) statusFilter.value = savedStatus;
        }

        if (savedSection) {
            state.filters.section = savedSection;
            var sectionFilter = document.getElementById('filter-section');
            if (sectionFilter) sectionFilter.value = savedSection;
        }

        if (savedGrantType) {
            state.filters.grantType = savedGrantType;
            var grantTypeFilter = document.getElementById('filter-grant-type');
            if (grantTypeFilter) grantTypeFilter.value = savedGrantType;
        }
    }

    /**
     * PUBLIC API
     */
    return {
        init: init,
        applyFilters: applyFilters,
        resetFilters: resetFilters
    };
})();

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', DashboardEnhancement.init);
} else {
    DashboardEnhancement.init();
}
