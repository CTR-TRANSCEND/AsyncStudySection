<?php
/**
 * TDD Test: AggregationEngine Service
 * SPEC-RPT-001: Reporting and Analytics System
 *
 * Tests aggregation functions: mean, median, stddev, percentile
 * Following RED-GREEN-REFACTOR cycle
 */

use PHPUnit\Framework\TestCase;

class AggregationEngineTest extends TestCase
{
    private AggregationEngine $aggregation;

    protected function setUp(): void
    {
        $this->aggregation = new AggregationEngine();
    }

    // ========== MEAN TESTS ==========

    public function testMeanWithPositiveNumbers()
    {
        // Given: array of positive numbers
        $data = [1, 2, 3, 4, 5];

        // When: calculate mean
        $result = $this->aggregation->mean($data);

        // Then: should return arithmetic mean
        $this->assertEquals(3.0, $result);
        $this->assertIsFloat($result);
    }

    public function testMeanWithDecimals()
    {
        // Given: array with decimal values
        $data = [1.5, 2.5, 3.5];

        // When: calculate mean
        $result = $this->aggregation->mean($data);

        // Then: should return correct mean
        $this->assertEquals(2.5, $result);
    }

    public function testMeanWithSingleValue()
    {
        // Given: single element array
        $data = [42];

        // When: calculate mean
        $result = $this->aggregation->mean($data);

        // Then: should return the value itself
        $this->assertEquals(42.0, $result);
    }

    public function testMeanWithEmptyArray()
    {
        // Given: empty array
        $data = [];

        // When: calculate mean
        // Then: should throw InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot calculate mean of empty array');
        $this->aggregation->mean($data);
    }

    public function testMeanWithNegativeNumbers()
    {
        // Given: array with negative values
        $data = [-5, 0, 5];

        // When: calculate mean
        $result = $this->aggregation->mean($data);

        // Then: should return correct mean
        $this->assertEquals(0.0, $result);
    }

    // ========== MEDIAN TESTS ==========

    public function testMedianWithOddCount()
    {
        // Given: array with odd number of elements
        $data = [1, 3, 5];

        // When: calculate median
        $result = $this->aggregation->median($data);

        // Then: should return middle value
        $this->assertEquals(3.0, $result);
    }

    public function testMedianWithEvenCount()
    {
        // Given: array with even number of elements
        $data = [1, 2, 3, 4];

        // When: calculate median
        $result = $this->aggregation->median($data);

        // Then: should return average of middle values
        $this->assertEquals(2.5, $result);
    }

    public function testMedianWithUnsortedData()
    {
        // Given: unsorted array
        $data = [5, 1, 3, 2, 4];

        // When: calculate median
        $result = $this->aggregation->median($data);

        // Then: should handle unsorted data correctly
        $this->assertEquals(3.0, $result);
    }

    public function testMedianWithEmptyArray()
    {
        // Given: empty array
        $data = [];

        // When: calculate median
        // Then: should throw exception
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot calculate median of empty array');
        $this->aggregation->median($data);
    }

    // ========== STANDARD DEVIATION TESTS ==========

    public function testStdDevWithNormalDistribution()
    {
        // Given: dataset with known std dev
        $data = [2, 4, 4, 4, 5, 5, 7, 9];

        // When: calculate standard deviation
        $result = $this->aggregation->stddev($data);

        // Then: should return population std dev
        // Expected: ~2.0 for population std dev
        $this->assertEqualsWithDelta(2.0, $result, 0.01);
    }

    public function testStdDevWithZeroVariance()
    {
        // Given: all values identical
        $data = [5, 5, 5, 5];

        // When: calculate standard deviation
        $result = $this->aggregation->stddev($data);

        // Then: should return zero
        $this->assertEquals(0.0, $result);
    }

    public function testStdDevWithSingleValue()
    {
        // Given: single value
        $data = [42];

        // When: calculate standard deviation
        $result = $this->aggregation->stddev($data);

        // Then: should return zero (no variance)
        $this->assertEquals(0.0, $result);
    }

    public function testStdDevWithEmptyArray()
    {
        // Given: empty array
        $data = [];

        // When: calculate std dev
        // Then: should throw exception
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot calculate standard deviation of empty array');
        $this->aggregation->stddev($data);
    }

    // ========== PERCENTILE TESTS ==========

    public function testPercentile25()
    {
        // Given: ordered dataset
        $data = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

        // When: calculate 25th percentile
        $result = $this->aggregation->percentile($data, 25);

        // Then: should return 3.25 (first quartile)
        $this->assertEqualsWithDelta(3.25, $result, 0.01);
    }

    public function testPercentile50Median()
    {
        // Given: dataset
        $data = [1, 2, 3, 4, 5];

        // When: calculate 50th percentile
        $result = $this->aggregation->percentile($data, 50);

        // Then: should equal median
        $this->assertEquals(3.0, $result);
    }

    public function testPercentile75()
    {
        // Given: ordered dataset
        $data = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

        // When: calculate 75th percentile
        $result = $this->aggregation->percentile($data, 75);

        // Then: should return 7.75 (third quartile)
        $this->assertEqualsWithDelta(7.75, $result, 0.01);
    }

    public function testPercentile100()
    {
        // Given: dataset
        $data = [1, 2, 3, 4, 5];

        // When: calculate 100th percentile
        $result = $this->aggregation->percentile($data, 100);

        // Then: should return maximum value
        $this->assertEquals(5.0, $result);
    }

    public function testPercentile0()
    {
        // Given: dataset
        $data = [1, 2, 3, 4, 5];

        // When: calculate 0th percentile
        $result = $this->aggregation->percentile($data, 0);

        // Then: should return minimum value
        $this->assertEquals(1.0, $result);
    }

    public function testPercentileWithInvalidPercentile()
    {
        // Given: dataset
        $data = [1, 2, 3];

        // When: calculate invalid percentile > 100
        // Then: should throw exception
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Percentile must be between 0 and 100');
        $this->aggregation->percentile($data, 101);
    }

    public function testPercentileWithEmptyArray()
    {
        // Given: empty array
        $data = [];

        // When: calculate percentile
        // Then: should throw exception
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot calculate percentile of empty array');
        $this->aggregation->percentile($data, 50);
    }

    // ========== MIN AND MAX TESTS ==========

    public function testMin()
    {
        // Given: dataset
        $data = [5, 2, 8, 1, 9];

        // When: find minimum
        $result = $this->aggregation->min($data);

        // Then: should return smallest value
        $this->assertEquals(1, $result);
    }

    public function testMax()
    {
        // Given: dataset
        $data = [5, 2, 8, 1, 9];

        // When: find maximum
        $result = $this->aggregation->max($data);

        // Then: should return largest value
        $this->assertEquals(9, $result);
    }

    public function testRange()
    {
        // Given: dataset
        $data = [5, 2, 8, 1, 9];

        // When: calculate range
        $result = $this->aggregation->range($data);

        // Then: should return max - min
        $this->assertEquals(8, $result); // 9 - 1
    }

    // ========== COMBINED STATISTICS TESTS ==========

    public function testCalculateAllStatistics()
    {
        // Given: sample dataset
        $data = [2, 4, 4, 4, 5, 5, 7, 9];

        // When: calculate all statistics
        $stats = $this->aggregation->calculateAll($data);

        // Then: should return complete statistics object
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('mean', $stats);
        $this->assertArrayHasKey('median', $stats);
        $this->assertArrayHasKey('stddev', $stats);
        $this->assertArrayHasKey('min', $stats);
        $this->assertArrayHasKey('max', $stats);
        $this->assertArrayHasKey('count', $stats);
        $this->assertArrayHasKey('range', $stats);

        $this->assertEqualsWithDelta(5.0, $stats['mean'], 0.01);
        $this->assertEquals(4.5, $stats['median']);
        $this->assertEqualsWithDelta(2.0, $stats['stddev'], 0.01);
        $this->assertEquals(2, $stats['min']);
        $this->assertEquals(9, $stats['max']);
        $this->assertEquals(8, $stats['count']);
        $this->assertEquals(7, $stats['range']);
    }
}
