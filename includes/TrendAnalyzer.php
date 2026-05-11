<?php
declare(strict_types=1);
/**
 * TrendAnalyzer Service
 * SPEC-RPT-001: Reporting and Analytics System
 *
 * Provides time-series analytics and trend analysis:
 * - Moving averages (3, 7, 30 period)
 * - Anomaly detection (z-score, IQR methods)
 * - Seasonal pattern decomposition
 * - Year-over-year comparisons
 * - Growth rate calculations
 *
 * @author SPEC-RPT-001 Implementation
 * @version 1.0.0
 * @created 2025-01-04
 */

class TrendAnalyzer
{
    private PDO $db;
    private AggregationEngine $aggregation;

    public function __construct(PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->aggregation = new AggregationEngine();
    }

    /**
     * Calculate simple moving average
     *
     * @param array $data Time-series data (indexed by date/time)
     * @param int $period Number of periods for moving average
     * @return array Moving average values
     * @throws InvalidArgumentException If period is invalid
     */
    public function movingAverage(array $data, int $period): array
    {
        if ($period < 2) {
            throw new InvalidArgumentException('Moving average period must be at least 2');
        }

        if (count($data) < $period) {
            throw new InvalidArgumentException('Data array must contain at least ' . $period . ' elements');
        }

        $values = array_values($data);
        $movingAverages = [];

        for ($i = $period - 1; $i < count($values); $i++) {
            $slice = array_slice($values, $i - $period + 1, $period);
            $movingAverages[$i] = round($this->aggregation->mean($slice), 2);
        }

        return $movingAverages;
    }

    /**
     * Calculate exponential moving average
     *
     * @param array $data Time-series data
     * @param int $period Smoothing period
     * @return array EMA values
     */
    public function exponentialMovingAverage(array $data, int $period): array
    {
        if ($period < 2) {
            throw new InvalidArgumentException('EMA period must be at least 2');
        }

        $values = array_values($data);
        $multiplier = 2 / ($period + 1);

        $ema = [];
        $ema[0] = $values[0]; // Start with first value

        for ($i = 1; $i < count($values); $i++) {
            $ema[$i] = round(($values[$i] - $ema[$i - 1]) * $multiplier + $ema[$i - 1], 2);
        }

        return $ema;
    }

    /**
     * Detect anomalies using z-score method
     *
     * @param array $data Time-series data
     * @param float $threshold Standard deviation threshold (default: 2)
     * @return array Detected anomalies with metadata
     */
    public function detectAnomaliesZScore(array $data, float $threshold = 2.0): array
    {
        if (count($data) < 3) {
            return [];
        }

        $values = array_values($data);
        $mean = $this->aggregation->mean($values);
        $stddev = $this->aggregation->stddev($values);

        if ($stddev === 0.0) {
            return [];
        }

        $anomalies = [];
        $keys = array_keys($data);

        foreach ($values as $index => $value) {
            $zScore = abs(($value - $mean) / $stddev);

            if ($zScore > $threshold) {
                $anomalies[] = [
                    'index' => $index,
                    'key' => $keys[$index],
                    'value' => $value,
                    'z_score' => round($zScore, 2),
                    'deviation_type' => $value > $mean ? 'above' : 'below',
                    'severity' => $this->interpretZScoreSeverity($zScore),
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Detect anomalies using Interquartile Range (IQR) method
     *
     * @param array $data Time-series data
     * @param float $multiplier IQR multiplier (default: 1.5)
     * @return array Detected anomalies
     */
    public function detectAnomaliesIQR(array $data, float $multiplier = 1.5): array
    {
        if (count($data) < 4) {
            return [];
        }

        $values = array_values($data);
        sort($values);

        $q1 = $this->aggregation->percentile($values, 25);
        $q3 = $this->aggregation->percentile($values, 75);
        $iqr = $q3 - $q1;

        $lowerBound = $q1 - ($multiplier * $iqr);
        $upperBound = $q3 + ($multiplier * $iqr);

        $anomalies = [];
        $keys = array_keys($data);

        foreach ($data as $key => $value) {
            if ($value < $lowerBound || $value > $upperBound) {
                $anomalies[] = [
                    'key' => $key,
                    'value' => $value,
                    'lower_bound' => $lowerBound,
                    'upper_bound' => $upperBound,
                    'outlier_type' => $value < $lowerBound ? 'low' : 'high',
                    'distance_from_bound' => $value < $lowerBound
                        ? round($lowerBound - $value, 2)
                        : round($value - $upperBound, 2),
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Calculate period-over-period growth rate
     *
     * @param array $data Time-series data
     * @param int $periods Periods to compare (default: 1 for consecutive)
     * @return array Growth rates
     */
    public function calculateGrowthRate(array $data, int $periods = 1): array
    {
        if (count($data) <= $periods) {
            return [];
        }

        $values = array_values($data);
        $keys = array_keys($data);

        $growthRates = [];

        for ($i = $periods; $i < count($values); $i++) {
            $previousValue = $values[$i - $periods];
            $currentValue = $values[$i];

            if ($previousValue == 0) { // loose comparison intentional: data values may be int or float
                $growthRate = $currentValue > 0 ? 100.0 : 0.0;
            } else {
                $growthRate = (($currentValue - $previousValue) / abs($previousValue)) * 100;
            }

            $growthRates[] = [
                'period' => $keys[$i],
                'previous_period' => $keys[$i - $periods],
                'growth_rate' => round($growthRate, 2),
                'previous_value' => $previousValue,
                'current_value' => $currentValue,
            ];
        }

        return $growthRates;
    }

    /**
     * Decompose time series into trend, seasonal, and residual components
     *
     * @param array $data Time-series data with date keys
     * @param string $seasonality Seasonality type ('weekly', 'monthly', 'quarterly')
     * @return array Decomposed components
     */
    public function decomposeTimeSeries(array $data, string $seasonality = 'monthly'): array
    {
        // Resolve period first so the guard threshold matches movingAverage requirements
        $period = $this->getSeasonalityPeriod($seasonality);
        if (count($data) < $period) {
            throw new InvalidArgumentException(
                "Insufficient data for {$seasonality} decomposition (need at least {$period} data points, got " . count($data) . ')'
            );
        }

        // Sort by date
        ksort($data);
        $values = array_values($data);
        $dates = array_keys($data);
        $trend = $this->movingAverage($data, $period);

        // Align trend array with data (pad with null for initial values)
        $padding = array_fill(0, $period - 1, null);
        $trend = array_merge($padding, $trend);

        // Calculate detrended data
        $detrended = [];
        for ($i = 0; $i < count($values); $i++) {
            if ($trend[$i] !== null) {
                $detrended[] = $values[$i] - $trend[$i];
            } else {
                $detrended[] = null;
            }
        }

        // Extract seasonal component
        $seasonal = $this->extractSeasonalComponent($dates, $detrended, $seasonality);

        // Calculate residual (random) component
        $residual = [];
        for ($i = 0; $i < count($values); $i++) {
            if ($trend[$i] !== null && isset($seasonal[$i])) {
                $residual[] = $values[$i] - $trend[$i] - $seasonal[$i];
            } else {
                $residual[] = null;
            }
        }

        return [
            'original' => $values,
            'dates' => $dates,
            'trend' => $trend,
            'seasonal' => $seasonal,
            'residual' => $residual,
            'seasonality_type' => $seasonality,
        ];
    }

    /**
     * Get application submission trends over time
     *
     * @param string $granularity Time granularity ('daily', 'weekly', 'monthly')
     * @param int $days Number of days to analyze
     * @return array Time-series data
     */
    public function getApplicationSubmissionTrends(string $granularity = 'daily', int $days = 90): array
    {
        $dateFormat = $this->getDateFormat($granularity);
        $groupBy = $this->getGroupByClause($granularity);

        $stmt = $this->db->prepare("
            SELECT
                DATE_FORMAT(created_at, ?) as period,
                COUNT(*) as count
            FROM applications
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY period
            ORDER BY period ASC
        ");
        $stmt->execute([$dateFormat, $days]);
        $results = $stmt->fetchAll();

        $timeSeries = [];
        foreach ($results as $row) {
            $timeSeries[$row['period']] = (int) $row['count'];
        }

        // Fill in missing periods with zero
        $timeSeries = $this->fillMissingPeriods($timeSeries, $granularity, $days);

        return $timeSeries;
    }

    /**
     * Get review completion trends
     *
     * @param string $granularity Time granularity
     * @param int $days Number of days
     * @return array Time-series data
     */
    public function getReviewCompletionTrends(string $granularity = 'daily', int $days = 90): array
    {
        $dateFormat = $this->getDateFormat($granularity);

        $stmt = $this->db->prepare("
            SELECT
                DATE_FORMAT(r.created_at, ?) as period,
                COUNT(*) as count
            FROM reviews r
            WHERE r.is_final = TRUE
              AND r.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY period
            ORDER BY period ASC
        ");
        $stmt->execute([$dateFormat, $days]);
        $results = $stmt->fetchAll();

        $timeSeries = [];
        foreach ($results as $row) {
            $timeSeries[$row['period']] = (int) $row['count'];
        }

        return $this->fillMissingPeriods($timeSeries, $granularity, $days);
    }

    /**
     * Get average score trends
     *
     * @param string $granularity Time granularity
     * @param int $days Number of days
     * @return array Time-series data
     */
    public function getAverageScoreTrends(string $granularity = 'daily', int $days = 90): array
    {
        $dateFormat = $this->getDateFormat($granularity);

        $stmt = $this->db->prepare("
            SELECT
                DATE_FORMAT(r.created_at, ?) as period,
                AVG(COALESCE(r.overall_impact_score, r.relevance_score)) as avg_score
            FROM reviews r
            WHERE r.is_final = TRUE
              AND r.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND (r.overall_impact_score IS NOT NULL OR r.relevance_score IS NOT NULL)
            GROUP BY period
            ORDER BY period ASC
        ");
        $stmt->execute([$dateFormat, $days]);
        $results = $stmt->fetchAll();

        $timeSeries = [];
        foreach ($results as $row) {
            $timeSeries[$row['period']] = round((float) $row['avg_score'], 2);
        }

        return $this->fillMissingPeriods($timeSeries, $granularity, $days);
    }

    /**
     * Compare two time periods
     *
     * @param array $currentPeriod Current period data
     * @param array $previousPeriod Previous period data
     * @return array Comparison metrics
     */
    public function comparePeriods(array $currentPeriod, array $previousPeriod): array
    {
        $currentSum = array_sum($currentPeriod);
        $previousSum = array_sum($previousPeriod);
        $currentAvg = !empty($currentPeriod) ? $this->aggregation->mean($currentPeriod) : 0.0;
        $previousAvg = !empty($previousPeriod) ? $this->aggregation->mean($previousPeriod) : 0.0;

        $change = $currentSum - $previousSum;
        $percentChange = $previousSum > 0
            ? round(($change / $previousSum) * 100, 2)
            : ($currentSum > 0 ? 100.0 : 0.0);

        return [
            'current_total' => $currentSum,
            'previous_total' => $previousSum,
            'current_average' => $currentAvg,
            'previous_average' => $previousAvg,
            'absolute_change' => $change,
            'percent_change' => $percentChange,
            'direction' => $change > 0 ? 'increase' : ($change < 0 ? 'decrease' : 'no_change'),
        ];
    }

    /**
     * Get date format string for MySQL
     *
     * @param string $granularity Time granularity
     * @return string Date format
     */
    private function getDateFormat(string $granularity): string
    {
        $formats = [
            'daily' => '%Y-%m-%d',
            'weekly' => '%Y-%u',
            'monthly' => '%Y-%m',
            'quarterly' => '%Y-Q',
        ];

        return $formats[$granularity] ?? '%Y-%m-%d';
    }

    /**
     * Get seasonality period for moving average
     *
     * @param string $seasonality Seasonality type
     * @return int Period length
     */
    private function getSeasonalityPeriod(string $seasonality): int
    {
        $periods = [
            'weekly' => 7,
            'monthly' => 30,
            'quarterly' => 90,
        ];

        return $periods[$seasonality] ?? 30;
    }

    /**
     * Extract seasonal component from detrended data
     *
     * @param array $dates Date keys
     * @param array $detrended Detrended values
     * @param string $seasonality Seasonality type
     * @return array Seasonal component values
     */
    private function extractSeasonalComponent(array $dates, array $detrended, string $seasonality): array
    {
        $seasonal = [];

        // Simple averaging by period type
        $periodValues = [];

        foreach ($dates as $index => $date) {
            $periodKey = $this->getPeriodKey($date, $seasonality);

            if ($detrended[$index] !== null) {
                if (!isset($periodValues[$periodKey])) {
                    $periodValues[$periodKey] = [];
                }
                $periodValues[$periodKey][] = $detrended[$index];
            }
        }

        // Calculate average for each period
        $periodAverages = [];
        foreach ($periodValues as $period => $values) {
            $periodAverages[$period] = $this->aggregation->mean($values);
        }

        // Apply seasonal averages to original data
        foreach ($dates as $index => $date) {
            $periodKey = $this->getPeriodKey($date, $seasonality);
            $seasonal[$index] = $periodAverages[$periodKey] ?? 0;
        }

        return $seasonal;
    }

    /**
     * Get period key for seasonal extraction
     *
     * @param string $date Date string
     * @param string $seasonality Seasonality type
     * @return string Period key
     */
    private function getPeriodKey(string $date, string $seasonality): string
    {
        $timestamp = strtotime($date);

        switch ($seasonality) {
            case 'weekly':
                return date('w', $timestamp); // Day of week
            case 'monthly':
                return date('j', $timestamp); // Day of month
            case 'quarterly':
                $dayOfYear = date('z', $timestamp);
                return 'q' . ceil(($dayOfYear + 1) / 91.25);
            default:
                return 'all';
        }
    }

    /**
     * Fill missing periods in time series
     *
     * @param array $data Existing data
     * @param string $granularity Time granularity
     * @param int $days Number of days
     * @return array Filled time series
     */
    private function fillMissingPeriods(array $data, string $granularity, int $days): array
    {
        $filled = [];

        $now = time();
        $interval = $this->getIntervalSeconds($granularity);

        for ($i = $days - 1; $i >= 0; $i--) {
            $timestamp = $now - ($i * $interval);
            $key = $this->formatPeriodKey($timestamp, $granularity);

            $filled[$key] = $data[$key] ?? 0;
        }

        return $filled;
    }

    /**
     * Format a timestamp into a period key matching the DB DATE_FORMAT output.
     *
     * Mapping mirrors getDateFormat():
     *   daily     -> %Y-%m-%d  => Y-m-d
     *   weekly    -> %Y-%u     => Y-W  (zero-padded week number)
     *   monthly   -> %Y-%m     => Y-m
     *   quarterly -> %Y-Q      => Y-Q  (matches the literal MySQL format string)
     *
     * @param int    $timestamp Unix timestamp
     * @param string $granularity Time granularity
     * @return string Formatted period key
     */
    private function formatPeriodKey(int $timestamp, string $granularity): string
    {
        switch ($granularity) {
            case 'daily':
                return date('Y-m-d', $timestamp);
            case 'weekly':
                // MySQL %u = week number (Sunday start, 00-53); PHP date('W') = ISO week (Monday
                // start). Use date('W') as the closest portable match for zero-padded week keys.
                return date('Y', $timestamp) . '-' . str_pad((string)(int)date('W', $timestamp), 2, '0', STR_PAD_LEFT);
            case 'monthly':
                return date('Y-m', $timestamp);
            case 'quarterly':
                // Matches MySQL DATE_FORMAT(col, '%Y-Q') literal output (no quarter digit appended)
                return date('Y', $timestamp) . '-Q';
            default:
                return date('Y-m-d', $timestamp);
        }
    }

    /**
     * Get interval in seconds for granularity
     *
     * @param string $granularity Time granularity
     * @return int Seconds
     */
    private function getIntervalSeconds(string $granularity): int
    {
        $intervals = [
            'daily' => 86400,     // 24 hours
            'weekly' => 604800,   // 7 days
            'monthly' => 2592000, // 30 days
            'quarterly' => 7776000, // 90 days
        ];

        return $intervals[$granularity] ?? 86400;
    }

    /**
     * Interpret z-score severity
     *
     * @param float $zScore Z-score value
     * @return string Severity level
     */
    private function interpretZScoreSeverity(float $zScore): string
    {
        if ($zScore >= 4.0) {
            return 'extreme';
        } elseif ($zScore >= 3.0) {
            return 'very_high';
        } elseif ($zScore >= 2.0) {
            return 'high';
        } elseif ($zScore >= 1.5) {
            return 'moderate';
        } else {
            return 'low';
        }
    }
}
