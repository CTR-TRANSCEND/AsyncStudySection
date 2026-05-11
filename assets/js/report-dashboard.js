/**
 * ReportDashboard - Analytics Dashboard Module
 * SPEC-RPT-001: Reporting and Analytics System
 *
 * Provides interactive dashboard functionality:
 * - Summary cards with key metrics
 * - Chart rendering and management
 * - Data table with pagination
 * - Filter application
 * - Real-time data refresh
 *
 * @author SPEC-RPT-001 Implementation
 * @version 1.0.0
 * @created 2025-01-04
 */

class ReportDashboard {
    /**
     * Initialize dashboard
     * @param {Object} config - Dashboard configuration
     */
    constructor(config = {}) {
        this.config = {
            containerId: config.containerId || 'dashboard-container',
            apiEndpoint: config.apiEndpoint || '/api/reports',
            refreshInterval: config.refreshInterval || 300000, // 5 minutes
            autoRefresh: config.autoRefresh !== false,
            ...config
        };

        this.container = document.getElementById(this.config.containerId);
        this.charts = new Map();
        this.filters = new Map();
        this.refreshTimer = null;

        if (!this.container) {
            throw new Error(`Dashboard container with ID "${this.config.containerId}" not found`);
        }

        this._initialize();
    }

    /**
     * Initialize dashboard components
     * @private
     */
    _initialize() {
        this._loadDashboard();

        if (this.config.autoRefresh) {
            this._startAutoRefresh();
        }
    }

    /**
     * Load dashboard data and render
     */
    async _loadDashboard() {
        try {
            this._showLoading();

            const data = await this._fetchDashboardData();
            this._renderDashboard(data);

            this._hideLoading();
        } catch (error) {
            console.error('Error loading dashboard:', error);
            this._showError(error.message);
        }
    }

    /**
     * Fetch dashboard data from API
     * @returns {Promise<Object>} Dashboard data
     * @private
     */
    async _fetchDashboardData() {
        const params = new URLSearchParams(this._getFilterParams());

        const response = await fetch(`${this.config.apiEndpoint}?${params}`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        return await response.json();
    }

    /**
     * Render dashboard components
     * @param {Object} data - Dashboard data
     * @private
     */
    _renderDashboard(data) {
        this._renderSummaryCards(data.summary);
        this._renderCharts(data.charts);
        this._renderDataTable(data.table);
    }

    /**
     * Render summary cards
     * @param {Object} summary - Summary data
     * @private
     */
    _renderSummaryCards(summary) {
        const container = this.container.querySelector('.summary-cards');
        if (!container) return;

        container.innerHTML = '';

        for (const [key, metric] of Object.entries(summary)) {
            const card = this._createSummaryCard(key, metric);
            container.appendChild(card);
        }
    }

    /**
     * Create summary card element
     * @param {string} key - Metric key
     * @param {Object} metric - Metric data
     * @returns {HTMLElement} Card element
     * @private
     */
    _createSummaryCard(key, metric) {
        const card = document.createElement('div');
        card.className = 'summary-card';
        card.innerHTML = `
            <div class="card-title">${metric.label || this._formatLabel(key)}</div>
            <div class="card-value">${metric.value}</div>
            ${metric.change !== undefined ? `
                <div class="card-change ${metric.change >= 0 ? 'positive' : 'negative'}">
                    ${metric.change >= 0 ? '↑' : '↓'} ${Math.abs(metric.change)}%
                </div>
            ` : ''}
            ${metric.description ? `<div class="card-description">${metric.description}</div>` : ''}
        `;

        return card;
    }

    /**
     * Render charts
     * @param {Object} chartsData - Charts configuration
     * @private
     */
    _renderCharts(chartsData) {
        for (const chartConfig of chartsData) {
            try {
                const chartId = chartConfig.canvasId;
                const chartInstance = new ReportCharts(chartId);

                switch (chartConfig.type) {
                    case 'bar':
                        chartInstance.renderBarChart(chartConfig.data, chartConfig.options);
                        break;
                    case 'line':
                        chartInstance.renderLineChart(chartConfig.data, chartConfig.options);
                        break;
                    case 'pie':
                        chartInstance.renderPieChart(chartConfig.data, chartConfig.options);
                        break;
                    case 'scatter':
                        chartInstance.renderScatterPlot(chartConfig.data, chartConfig.options);
                        break;
                    case 'radar':
                        chartInstance.renderRadarChart(chartConfig.data, chartConfig.options);
                        break;
                    default:
                        console.warn(`Unknown chart type: ${chartConfig.type}`);
                }

                this.charts.set(chartId, chartInstance);
            } catch (error) {
                console.error(`Error rendering chart ${chartConfig.canvasId}:`, error);
            }
        }
    }

    /**
     * Render data table
     * @param {Object} tableData - Table data
     * @private
     */
    _renderDataTable(tableData) {
        const container = this.container.querySelector('.data-table-container');
        if (!container || !tableData) return;

        const table = document.createElement('table');
        table.className = 'data-table';

        // Render header
        if (tableData.columns) {
            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');
            tableData.columns.forEach(col => {
                const th = document.createElement('th');
                th.textContent = col.label;
                headerRow.appendChild(th);
            });
            thead.appendChild(headerRow);
            table.appendChild(thead);
        }

        // Render body
        if (tableData.rows) {
            const tbody = document.createElement('tbody');
            tableData.rows.forEach(row => {
                const tr = document.createElement('tr');
                tableData.columns.forEach(col => {
                    const td = document.createElement('td');
                    td.textContent = row[col.key] || '';
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
        }

        container.innerHTML = '';
        container.appendChild(table);

        // Add pagination if needed
        if (tableData.pagination) {
            this._renderPagination(container, tableData.pagination);
        }
    }

    /**
     * Render pagination controls
     * @param {HTMLElement} container - Container element
     * @param {Object} pagination - Pagination data
     * @private
     */
    _renderPagination(container, pagination) {
        const paginationDiv = document.createElement('div');
        paginationDiv.className = 'pagination';

        const { currentPage, totalPages, pageSize } = pagination;

        // Previous button
        const prevBtn = document.createElement('button');
        prevBtn.textContent = 'Previous';
        prevBtn.disabled = currentPage === 1;
        prevBtn.addEventListener('click', () => this._goToPage(currentPage - 1));
        paginationDiv.appendChild(prevBtn);

        // Page info
        const pageInfo = document.createElement('span');
        pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        paginationDiv.appendChild(pageInfo);

        // Next button
        const nextBtn = document.createElement('button');
        nextBtn.textContent = 'Next';
        nextBtn.disabled = currentPage === totalPages;
        nextBtn.addEventListener('click', () => this._goToPage(currentPage + 1));
        paginationDiv.appendChild(nextBtn);

        container.appendChild(paginationDiv);
    }

    /**
     * Navigate to specific page
     * @param {number} page - Page number
     * @private
     */
    async _goToPage(page) {
        this.filters.set('page', page);
        await this._loadDashboard();
    }

    /**
     * Apply filters
     * @param {Object} filters - Filter parameters
     */
    applyFilters(filters) {
        for (const [key, value] of Object.entries(filters)) {
            if (value !== null && value !== undefined && value !== '') {
                this.filters.set(key, value);
            } else {
                this.filters.delete(key);
            }
        }

        this._loadDashboard();
    }

    /**
     * Get current filter parameters
     * @returns {Object} Filter parameters
     * @private
     */
    _getFilterParams() {
        const params = {};
        for (const [key, value] of this.filters.entries()) {
            params[key] = value;
        }
        return params;
    }

    /**
     * Refresh dashboard data
     */
    refresh() {
        this._loadDashboard();
    }

    /**
     * Start auto-refresh timer
     * @private
     */
    _startAutoRefresh() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
        }

        this.refreshTimer = setInterval(() => {
            this.refresh();
        }, this.config.refreshInterval);
    }

    /**
     * Stop auto-refresh timer
     */
    stopAutoRefresh() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
            this.refreshTimer = null;
        }
    }

    /**
     * Destroy dashboard and cleanup
     */
    destroy() {
        this.stopAutoRefresh();

        // Destroy all charts
        for (const [chartId, chartInstance] of this.charts.entries()) {
            chartInstance.destroy();
        }
        this.charts.clear();

        // Clear container
        if (this.container) {
            this.container.innerHTML = '';
        }
    }

    /**
     * Show loading indicator
     * @private
     */
    _showLoading() {
        const loader = this.container.querySelector('.dashboard-loader');
        if (loader) {
            loader.style.display = 'flex';
        }
    }

    /**
     * Hide loading indicator
     * @private
     */
    _hideLoading() {
        const loader = this.container.querySelector('.dashboard-loader');
        if (loader) {
            loader.style.display = 'none';
        }
    }

    /**
     * Show error message
     * @param {string} message - Error message
     * @private
     */
    _showError(message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'dashboard-error';
        errorDiv.textContent = `Error: ${message}`;
        this.container.appendChild(errorDiv);

        setTimeout(() => errorDiv.remove(), 5000);
    }

    /**
     * Format label for display
     * @param {string} key - Key to format
     * @returns {string} Formatted label
     * @private
     */
    _formatLabel(key) {
        return key
            .replace(/_/g, ' ')
            .replace(/([A-Z])/g, ' $1')
            .trim()
            .split(' ')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ReportDashboard;
}
