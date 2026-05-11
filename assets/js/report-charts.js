/**
 * ReportCharts - Chart.js Integration Module
 * SPEC-RPT-001: Reporting and Analytics System
 *
 * Provides chart rendering functionality using Chart.js:
 * - Bar charts for categorical comparisons
 * - Line charts for trend analysis
 * - Pie charts for distribution analysis
 * - Scatter plots for correlation analysis
 * - Radar charts for multi-criteria assessment
 *
 * @author SPEC-RPT-001 Implementation
 * @version 1.0.0
 * @created 2025-01-04
 */

class ReportCharts {
    /**
     * Initialize chart instance
     * @param {string} canvasId - Canvas element ID
     */
    constructor(canvasId) {
        this.canvas = document.getElementById(canvasId);
        if (!this.canvas) {
            throw new Error(`Canvas element with ID "${canvasId}" not found`);
        }
        this.chart = null;
        this.chartType = null;
    }

    /**
     * Create or update bar chart
     * @param {Object} data - Chart data
     * @param {Object} options - Chart options
     */
    renderBarChart(data, options = {}) {
        this._ensureChartJs();
        this._destroyChart();

        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                },
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            return `${context.dataset.label}: ${context.raw.toFixed(2)}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 2
                    }
                }
            }
        };

        this.chart = new Chart(this.canvas, {
            type: 'bar',
            data: data,
            options: { ...defaultOptions, ...options }
        });

        this.chartType = 'bar';
        return this.chart;
    }

    /**
     * Create or update line chart
     * @param {Object} data - Chart data
     * @param {Object} options - Chart options
     */
    renderLineChart(data, options = {}) {
        this._ensureChartJs();
        this._destroyChart();

        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    ticks: {
                        precision: 2
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        };

        this.chart = new Chart(this.canvas, {
            type: 'line',
            data: data,
            options: { ...defaultOptions, ...options }
        });

        this.chartType = 'line';
        return this.chart;
    }

    /**
     * Create or update pie chart
     * @param {Object} data - Chart data
     * @param {Object} options - Chart options
     */
    renderPieChart(data, options = {}) {
        this._ensureChartJs();
        this._destroyChart();

        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'right',
                },
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        };

        this.chart = new Chart(this.canvas, {
            type: 'pie',
            data: data,
            options: { ...defaultOptions, ...options }
        });

        this.chartType = 'pie';
        return this.chart;
    }

    /**
     * Create or update scatter plot
     * @param {Object} data - Chart data
     * @param {Object} options - Chart options
     */
    renderScatterPlot(data, options = {}) {
        this._ensureChartJs();
        this._destroyChart();

        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                },
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            return `(${context.parsed.x.toFixed(2)}, ${context.parsed.y.toFixed(2)})`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'linear',
                    position: 'bottom',
                }
            }
        };

        this.chart = new Chart(this.canvas, {
            type: 'scatter',
            data: data,
            options: { ...defaultOptions, ...options }
        });

        this.chartType = 'scatter';
        return this.chart;
    }

    /**
     * Create or update radar chart
     * @param {Object} data - Chart data
     * @param {Object} options - Chart options
     */
    renderRadarChart(data, options = {}) {
        this._ensureChartJs();
        this._destroyChart();

        const defaultOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                }
            },
            scales: {
                r: {
                    beginAtZero: true,
                    suggestedMin: 0,
                    suggestedMax: 100,
                    ticks: {
                        stepSize: 20
                    }
                }
            }
        };

        this.chart = new Chart(this.canvas, {
            type: 'radar',
            data: data,
            options: { ...defaultOptions, ...options }
        });

        this.chartType = 'radar';
        return this.chart;
    }

    /**
     * Update chart data
     * @param {Object} newData - New chart data
     */
    updateData(newData) {
        if (!this.chart) {
            throw new Error('No chart instance exists. Call render method first.');
        }

        this.chart.data = newData;
        this.chart.update();
    }

    /**
     * Export chart as image
     * @param {string} format - Image format ('png' or 'svg')
     * @returns {string} Image data URL
     */
    exportAsImage(format = 'png') {
        if (!this.chart) {
            throw new Error('No chart instance exists to export');
        }

        const canvas = this.chart.canvas;
        const mimeType = format === 'svg' ? 'image/svg+xml' : 'image/png';
        return canvas.toDataURL(mimeType, 1.0);
    }

    /**
     * Download chart as image file
     * @param {string} filename - Filename for download
     * @param {string} format - Image format
     */
    downloadChart(filename = 'chart.png', format = 'png') {
        const imageData = this.exportAsImage(format);
        const link = document.createElement('a');
        link.download = filename;
        link.href = imageData;
        link.click();
    }

    /**
     * Destroy current chart instance
     */
    destroy() {
        this._destroyChart();
    }

    /**
     * Get current chart instance
     * @returns {Chart|null} Chart instance
     */
    getChart() {
        return this.chart;
    }

    /**
     * Check if Chart.js is loaded, throw error if not
     * @private
     */
    _ensureChartJs() {
        if (typeof Chart === 'undefined') {
            throw new Error('Chart.js is not loaded. Include Chart.js library first.');
        }
    }

    /**
     * Destroy existing chart instance if it exists
     * @private
     */
    _destroyChart() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
            this.chartType = null;
        }
    }

    /**
     * Create accessible color palette (colorblind-friendly)
     * @param {number} count - Number of colors needed
     * @returns {Array<string>} Array of hex color codes
     */
    static createColorPalette(count) {
        // Colorblind-friendly palette from Wong (2011)
        const baseColors = [
            '#E69F00', // Orange
            '#56B4E9', // Sky blue
            '#009E73', // Bluish green
            '#F0E442', // Yellow
            '#0072B2', // Blue
            '#D55E00', // Vermilion
            '#CC79A7', // Reddish purple
            '#999999', // Grey
        ];

        const palette = [];
        for (let i = 0; i < count; i++) {
            palette.push(baseColors[i % baseColors.length]);
        }

        return palette;
    }

    /**
     * Create dataset with color
     * @param {string} label - Dataset label
     * @param {Array} data - Data values
     * @param {string} color - Color code
     * @returns {Object} Chart.js dataset object
     */
    static createDataset(label, data, color) {
        return {
            label: label,
            data: data,
            backgroundColor: color + '80', // Add transparency
            borderColor: color,
            borderWidth: 2,
        };
    }

    /**
     * Format number with appropriate precision
     * @param {number} value - Value to format
     * @param {number} decimals - Number of decimal places
     * @returns {string} Formatted number
     */
    static formatNumber(value, decimals = 2) {
        return Number(value).toFixed(decimals);
    }
}

/**
 * Chart data builder helper class
 */
class ChartDataBuilder {
    constructor() {
        this.labels = [];
        this.datasets = [];
    }

    /**
     * Set chart labels
     * @param {Array<string>} labels - X-axis labels
     */
    setLabels(labels) {
        this.labels = labels;
        return this;
    }

    /**
     * Add dataset
     * @param {string} label - Dataset label
     * @param {Array<number>} data - Data values
     * @param {string} color - Color code
     */
    addDataset(label, data, color) {
        this.datasets.push({
            label: label,
            data: data,
            backgroundColor: color + '80',
            borderColor: color,
            borderWidth: 2,
        });
        return this;
    }

    /**
     * Build final chart data object
     * @returns {Object} Chart.js data object
     */
    build() {
        return {
            labels: this.labels,
            datasets: this.datasets
        };
    }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ReportCharts, ChartDataBuilder };
}
