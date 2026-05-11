<?php
declare(strict_types=1);
/**
 * AggregationEngine Service
 * SPEC-RPT-001: Reporting and Analytics System
 *
 * Provides statistical aggregation functions for report data analysis:
 * - Mean (arithmetic average)
 * - Median (middle value)
 * - Standard Deviation (measure of variability)
 * - Percentiles (quartiles and custom percentiles)
 * - Min, Max, Range
 *
 * @author SPEC-RPT-001 Implementation
 * @version 1.0.0
 * @created 2025-01-04
 */

class AggregationEngine
{
    /**
     * Calculate arithmetic mean (average) of values
     *
     * @param array $data Array of numeric values
     * @return float The arithmetic mean
     * @throws InvalidArgumentException If array is empty
     */
    public function mean(array $data): float
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Cannot calculate mean of empty array');
        }

        $sum = array_sum($data);
        $count = count($data);

        return round($sum / $count, 2);
    }

    /**
     * Calculate median (middle value) of dataset
     *
     * @param array $data Array of numeric values
     * @return float The median value
     * @throws InvalidArgumentException If array is empty
     */
    public function median(array $data): float
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Cannot calculate median of empty array');
        }

        // Sort data
        $sorted = $data;
        sort($sorted);

        $count = count($sorted);
        $middle = (int) floor($count / 2);

        // Odd count: return middle element
        if ($count % 2 === 1) {
            return (float) $sorted[$middle];
        }

        // Even count: return average of two middle elements
        return ($sorted[$middle - 1] + $sorted[$middle]) / 2.0;
    }

    /**
     * Calculate population standard deviation
     *
     * @param array $data Array of numeric values
     * @return float The standard deviation
     * @throws InvalidArgumentException If array is empty
     */
    public function stddev(array $data): float
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Cannot calculate standard deviation of empty array');
        }

        $count = count($data);

        // Single value: no variance
        if ($count === 1) {
            return 0.0;
        }

        $mean = $this->mean($data);

        // Calculate sum of squared differences
        $sumSquaredDiff = 0.0;
        foreach ($data as $value) {
            $diff = $value - $mean;
            $sumSquaredDiff += ($diff * $diff);
        }

        // Population standard deviation (divide by N)
        $variance = $sumSquaredDiff / $count;

        return round(sqrt($variance), 2);
    }

    /**
     * Calculate percentile using linear interpolation method
     *
     * @param array $data Array of numeric values
     * @param int $percentile Percentile to calculate (0-100)
     * @return float The percentile value
     * @throws InvalidArgumentException If array empty or percentile invalid
     */
    public function percentile(array $data, int $percentile): float
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Cannot calculate percentile of empty array');
        }

        if ($percentile < 0 || $percentile > 100) {
            throw new InvalidArgumentException('Percentile must be between 0 and 100');
        }

        // Sort data
        $sorted = $data;
        sort($sorted);

        $count = count($sorted);

        // Edge cases
        if ($percentile === 0) {
            return (float) $sorted[0];
        }
        if ($percentile === 100) {
            return (float) $sorted[$count - 1];
        }

        // Calculate rank using linear interpolation method
        $rank = ($percentile / 100) * ($count - 1);

        $lowerIndex = (int) floor($rank);
        $upperIndex = (int) ceil($rank);

        // Exact match
        if ($lowerIndex === $upperIndex) {
            return (float) $sorted[$lowerIndex];
        }

        // Linear interpolation
        $fraction = $rank - $lowerIndex;
        $lowerValue = $sorted[$lowerIndex];
        $upperValue = $sorted[$upperIndex];

        return round($lowerValue + ($fraction * ($upperValue - $lowerValue)), 2);
    }

    /**
     * Find minimum value in dataset
     *
     * @param array $data Array of numeric values
     * @return float The minimum value
     * @throws InvalidArgumentException If array is empty
     */
    public function min(array $data): float
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Cannot calculate minimum of empty array');
        }

        return (float) min($data);
    }

    /**
     * Find maximum value in dataset
     *
     * @param array $data Array of numeric values
     * @return float The maximum value
     * @throws InvalidArgumentException If array is empty
     */
    public function max(array $data): float
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Cannot calculate maximum of empty array');
        }

        return (float) max($data);
    }

    /**
     * Calculate range (max - min) of dataset
     *
     * @param array $data Array of numeric values
     * @return float The range
     * @throws InvalidArgumentException If array is empty
     */
    public function range(array $data): float
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Cannot calculate range of empty array');
        }

        return $this->max($data) - $this->min($data);
    }

    /**
     * Calculate all statistics for a dataset
     *
     * @param array $data Array of numeric values
     * @return array Associative array with all statistics
     * @throws InvalidArgumentException If array is empty
     */
    public function calculateAll(array $data): array
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Cannot calculate statistics of empty array');
        }

        return [
            'count' => count($data),
            'mean' => $this->mean($data),
            'median' => $this->median($data),
            'stddev' => $this->stddev($data),
            'min' => $this->min($data),
            'max' => $this->max($data),
            'range' => $this->range($data),
            'p25' => $this->percentile($data, 25),
            'p50' => $this->percentile($data, 50),
            'p75' => $this->percentile($data, 75),
        ];
    }
}
