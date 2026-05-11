<?php
declare(strict_types=1);
/**
 * ReviewerAnalytics Service
 * SPEC-RPT-001: Reporting and Analytics System
 *
 * Calculates comprehensive reviewer performance metrics:
 * - Completion metrics (total reviews, completion time, on-time rate)
 * - Scoring metrics (average scores, distribution, consistency)
 * - Quality metrics (inter-rater reliability, comment quality)
 * - Comparative metrics (peer comparison, trends, rankings)
 *
 * @author SPEC-RPT-001 Implementation
 * @version 1.0.0
 * @created 2025-01-04
 */

class ReviewerAnalytics
{
    private PDO $db;
    private AggregationEngine $aggregation;
    // CR6-02: Cache for getAllReviewerScores() to avoid redundant queries per instance
    private ?array $cachedScores = null;

    public function __construct(PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->aggregation = new AggregationEngine();
    }

    /**
     * Calculate complete performance metrics for a reviewer
     *
     * @param int $reviewerId Reviewer ID to analyze
     * @return array Complete performance metrics
     * @throws InvalidArgumentException If reviewer not found
     */
    public function calculateMetrics(int $reviewerId): array
    {
        $metrics = [
            'reviewer_id' => $reviewerId,
            'completion_metrics' => $this->calculateCompletionMetrics($reviewerId),
            'scoring_metrics' => $this->calculateScoringMetrics($reviewerId),
            'quality_metrics' => $this->calculateQualityMetrics($reviewerId),
            'comparative_metrics' => $this->calculateComparativeMetrics($reviewerId),
        ];

        // Add metadata
        $metrics['has_sufficient_data'] = $metrics['completion_metrics']['total_reviews'] >= 5;
        $metrics['last_updated'] = date('Y-m-d H:i:s');

        return $metrics;
    }

    /**
     * Calculate completion metrics
     *
     * @param int $reviewerId Reviewer ID
     * @return array Completion metrics
     */
    private function calculateCompletionMetrics(int $reviewerId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_reviews,
                SUM(CASE WHEN is_final = TRUE THEN 1 ELSE 0 END) as completed_reviews,
                AVG(CASE
                    WHEN is_final = TRUE
                    THEN TIMESTAMPDIFF(HOUR, a.created_at, r.created_at)
                    ELSE NULL
                END) as avg_completion_hours,
                SUM(CASE
                    WHEN r.is_final = TRUE
                     AND r.created_at <= COALESCE(a.deadline, DATE_ADD(a.created_at, INTERVAL 7 DAY))
                    THEN 1 ELSE 0
                END) as on_time_reviews
            FROM reviews r
            JOIN assignments a ON r.reviewer_id = a.reviewer_id AND r.application_id = a.application_id
            WHERE r.reviewer_id = ?
        ");
        $stmt->execute([$reviewerId]);
        $data = $stmt->fetch();

        $completedReviews = (int) ($data['completed_reviews'] ?? 0);
        $onTimeRate = 0.0;
        if ($completedReviews > 0) {
            $onTimeRate = round(((int) ($data['on_time_reviews'] ?? 0) / $completedReviews) * 100, 2);
        }

        return [
            'total_reviews' => (int) ($data['total_reviews'] ?? 0),
            'completed_reviews' => $completedReviews,
            'pending_reviews' => (int) (($data['total_reviews'] ?? 0) - $completedReviews),
            'avg_completion_hours' => $data['avg_completion_hours']
                ? round($data['avg_completion_hours'], 2)
                : null,
            'on_time_completion_rate' => $onTimeRate,
        ];
    }

    /**
     * Calculate scoring metrics
     *
     * @param int $reviewerId Reviewer ID
     * @return array Scoring metrics
     */
    private function calculateScoringMetrics(int $reviewerId): array
    {
        // Get scores from legacy and new systems
        $scores = $this->getAllReviewerScores($reviewerId);

        if (empty($scores)) {
            return [
                'average_score_given' => null,
                'score_distribution' => [],
                'score_stddev' => null,
                'scores_by_criterion' => [],
            ];
        }

        // Calculate overall statistics
        $avgScore = $this->aggregation->mean($scores);
        $stddev = $this->aggregation->stddev($scores);

        // Build distribution histogram
        // CR6-24: Guard against out-of-range keys to prevent undefined offset warnings
        $distribution = array_fill(1, 9, 0);
        foreach ($scores as $score) {
            $intScore = (int) $score;
            if ($intScore >= 1 && $intScore <= 9) {
                $distribution[$intScore]++;
            }
        }

        // Get scores by criterion
        $scoresByCriterion = $this->getScoresByCriterion($reviewerId);

        return [
            'average_score_given' => $avgScore,
            'score_distribution' => $distribution,
            'score_stddev' => $stddev,
            'score_min' => min($scores),
            'score_max' => max($scores),
            'scores_by_criterion' => $scoresByCriterion,
        ];
    }

    /**
     * Calculate quality metrics
     *
     * @param int $reviewerId Reviewer ID
     * @return array Quality metrics
     */
    private function calculateQualityMetrics(int $reviewerId): array
    {
        // Calculate inter-rater reliability
        $reliability = $this->calculateInterRaterReliability($reviewerId);

        // Get comment quality metrics
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_reviews,
                AVG(LENGTH(COALESCE(r.overall_impact_explanation, '')) +
                    LENGTH(COALESCE(r.relevance_explanation, ''))) as avg_comment_length
            FROM reviews r
            WHERE r.reviewer_id = ? AND r.is_final = TRUE
        ");
        $stmt->execute([$reviewerId]);
        $commentData = $stmt->fetch();

        // Count discussion participation
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as message_count
            FROM discussion_messages dm
            WHERE dm.user_id = ?
        ");
        $stmt->execute([$reviewerId]);
        $discussionData = $stmt->fetch();

        return [
            'inter_rater_reliability' => $reliability,
            'avg_comment_length' => $commentData['avg_comment_length']
                ? round($commentData['avg_comment_length'], 2)
                : 0,
            'discussion_messages_posted' => (int) ($discussionData['message_count'] ?? 0),
        ];
    }

    /**
     * Calculate comparative metrics against peers
     *
     * @param int $reviewerId Reviewer ID
     * @return array Comparative metrics
     */
    private function calculateComparativeMetrics(int $reviewerId): array
    {
        $reviewerScores = $this->getAllReviewerScores($reviewerId);

        if (empty($reviewerScores)) {
            return [
                'peer_z_score' => null,
                'percentile_ranking' => null,
                'trend_direction' => 'insufficient_data',
            ];
        }

        $reviewerAvg = $this->aggregation->mean($reviewerScores);

        // CR6-02: Merge two peer-stats queries into one to avoid duplicate UNION ALL subqueries
        $stmt = $this->db->query("
            SELECT AVG(score) as peer_avg, STDDEV(score) as peer_stddev
            FROM (
                SELECT overall_impact_score as score
                FROM reviews WHERE overall_impact_score IS NOT NULL
                UNION ALL
                SELECT relevance_score as score
                FROM reviews WHERE relevance_score IS NOT NULL
                UNION ALL
                SELECT score as score
                FROM review_criteria_scores WHERE score IS NOT NULL
            ) all_scores
        ");
        $peerData = $stmt->fetch();
        $peerAvg = $peerData['peer_avg'] ?? 0;
        $peerStddev = $peerData['peer_stddev'] ?? 1;

        $zScore = 0.0;
        if ($peerStddev > 0) {
            $zScore = round(($reviewerAvg - $peerAvg) / $peerStddev, 2);
        }

        // Determine trend direction
        $trendDirection = $this->calculateTrendDirection($reviewerId);

        return [
            'peer_average' => round($peerAvg, 2),
            'peer_z_score' => $zScore,
            'interpretation' => $this->interpretZScore($zScore),
            'trend_direction' => $trendDirection,
        ];
    }

    /**
     * Calculate inter-rater reliability using Pearson correlation
     *
     * @param int $reviewerId Reviewer ID
     * @return array Reliability metrics
     */
    private function calculateInterRaterReliability(int $reviewerId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                r.application_id,
                r.overall_impact_score as reviewer_score,
                AVG(r2.overall_impact_score) as peer_avg_score
            FROM reviews r
            JOIN reviews r2 ON r.application_id = r2.application_id AND r.id != r2.id
            WHERE r.reviewer_id = ?
              AND r.overall_impact_score IS NOT NULL
              AND r2.overall_impact_score IS NOT NULL
            GROUP BY r.application_id, r.overall_impact_score
            LIMIT 500
        ");
        $stmt->execute([$reviewerId]);
        $comparisons = $stmt->fetchAll();

        if (count($comparisons) < 3) {
            return [
                'correlation_coefficient' => null,
                'interpretation' => 'insufficient_data',
                'sample_size' => count($comparisons),
            ];
        }

        // Calculate Pearson correlation coefficient
        $reviewerScores = array_column($comparisons, 'reviewer_score');
        $peerScores = array_column($comparisons, 'peer_avg_score');

        $correlation = $this->calculatePearsonCorrelation($reviewerScores, $peerScores);

        return [
            'correlation_coefficient' => round($correlation, 3),
            'interpretation' => $this->interpretCorrelation($correlation),
            'sample_size' => count($comparisons),
        ];
    }

    /**
     * Calculate Pearson correlation coefficient
     *
     * @param array $x First array of values
     * @param array $y Second array of values
     * @return float Correlation coefficient (-1 to 1)
     */
    private function calculatePearsonCorrelation(array $x, array $y): float
    {
        $n = count($x);
        if ($n !== count($y) || $n === 0) {
            return 0.0;
        }

        $meanX = $this->aggregation->mean($x);
        $meanY = $this->aggregation->mean($y);

        $numerator = 0.0;
        $sumX2 = 0.0;
        $sumY2 = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $numerator += ($dx * $dy);
            $sumX2 += ($dx * $dx);
            $sumY2 += ($dy * $dy);
        }

        $denominator = sqrt($sumX2) * sqrt($sumY2);

        if ($denominator === 0.0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    /**
     * Get all scores for a reviewer from both legacy and new systems
     *
     * @param int $reviewerId Reviewer ID
     * @return array Array of all scores
     */
    private function getAllReviewerScores(int $reviewerId): array
    {
        // CR6-02: Return cached result on repeated calls within same request
        if ($this->cachedScores !== null) {
            return $this->cachedScores;
        }

        $scores = [];

        // Legacy scores
        $stmt = $this->db->prepare("
            SELECT overall_impact_score, relevance_score
            FROM reviews
            WHERE reviewer_id = ? AND is_final = TRUE
        ");
        $stmt->execute([$reviewerId]);
        $legacyScores = $stmt->fetchAll();

        foreach ($legacyScores as $row) {
            if ($row['overall_impact_score'] !== null) {
                $scores[] = (int) $row['overall_impact_score'];
            }
            if ($row['relevance_score'] !== null) {
                $scores[] = (int) $row['relevance_score'];
            }
        }

        // Legacy criteria scores
        $stmt = $this->db->prepare("
            SELECT rcs.score
            FROM review_criteria_scores rcs
            JOIN reviews r ON rcs.review_id = r.id
            WHERE r.reviewer_id = ? AND rcs.score IS NOT NULL
        ");
        $stmt->execute([$reviewerId]);
        $criteriaScores = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($criteriaScores as $score) {
            $scores[] = (int) $score;
        }

        // New system section scores
        $stmt = $this->db->prepare("
            SELECT rss.score
            FROM review_section_scores rss
            JOIN reviews r ON rss.review_id = r.id
            JOIN grant_sections gs ON rss.grant_section_id = gs.id
            WHERE r.reviewer_id = ? AND rss.score IS NOT NULL AND gs.is_scored = TRUE
        ");
        $stmt->execute([$reviewerId]);
        $sectionScores = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($sectionScores as $score) {
            $scores[] = (int) $score;
        }

        $this->cachedScores = $scores;
        return $scores;
    }

    /**
     * Get scores grouped by criterion/section
     *
     * @param int $reviewerId Reviewer ID
     * @return array Scores by criterion
     */
    private function getScoresByCriterion(int $reviewerId): array
    {
        $scoresByCriterion = [];

        // Legacy criteria
        $stmt = $this->db->prepare("
            SELECT criterion_name, AVG(score) as avg_score, COUNT(*) as count
            FROM review_criteria_scores rcs
            JOIN reviews r ON rcs.review_id = r.id
            WHERE r.reviewer_id = ? AND rcs.score IS NOT NULL
            GROUP BY criterion_name
        ");
        $stmt->execute([$reviewerId]);
        $legacyCriteria = $stmt->fetchAll();

        foreach ($legacyCriteria as $row) {
            $scoresByCriterion[$row['criterion_name']] = [
                'average' => round($row['avg_score'], 2),
                'count' => (int) $row['count'],
            ];
        }

        // New system sections
        $stmt = $this->db->prepare("
            SELECT gs.name, AVG(rss.score) as avg_score, COUNT(*) as count
            FROM review_section_scores rss
            JOIN reviews r ON rss.review_id = r.id
            JOIN grant_sections gs ON rss.grant_section_id = gs.id
            WHERE r.reviewer_id = ? AND rss.score IS NOT NULL AND gs.is_scored = TRUE
            GROUP BY gs.name
        ");
        $stmt->execute([$reviewerId]);
        $sections = $stmt->fetchAll();

        foreach ($sections as $row) {
            $scoresByCriterion[$row['name']] = [
                'average' => round($row['avg_score'], 2),
                'count' => (int) $row['count'],
            ];
        }

        return $scoresByCriterion;
    }

    /**
     * Calculate trend direction for reviewer performance
     *
     * @param int $reviewerId Reviewer ID
     * @return string Trend direction
     */
    private function calculateTrendDirection(int $reviewerId): string
    {
        $stmt = $this->db->prepare("
            SELECT
                r.created_at,
                COALESCE(r.overall_impact_score, r.relevance_score) as score
            FROM reviews r
            WHERE r.reviewer_id = ? AND r.is_final = TRUE
              AND (r.overall_impact_score IS NOT NULL OR r.relevance_score IS NOT NULL)
            ORDER BY r.created_at ASC
            LIMIT 20
        ");
        $stmt->execute([$reviewerId]);
        $scores = $stmt->fetchAll();

        if (count($scores) < 5) {
            return 'insufficient_data';
        }

        // Split into first and second half
        $midPoint = (int) (count($scores) / 2);
        $firstHalf = array_slice(array_column($scores, 'score'), 0, $midPoint);
        $secondHalf = array_slice(array_column($scores, 'score'), $midPoint);

        $firstAvg = $this->aggregation->mean($firstHalf);
        $secondAvg = $this->aggregation->mean($secondHalf);

        $diff = $secondAvg - $firstAvg;

        if ($diff > 0.3) {
            return 'improving';
        } elseif ($diff < -0.3) {
            return 'declining';
        } else {
            return 'stable';
        }
    }

    /**
     * Interpret z-score
     *
     * @param float $zScore Z-score to interpret
     * @return string Interpretation
     */
    private function interpretZScore(float $zScore): string
    {
        if ($zScore >= 1.5) {
            return 'well_above_average';
        } elseif ($zScore >= 0.5) {
            return 'above_average';
        } elseif ($zScore >= -0.5) {
            return 'average';
        } elseif ($zScore >= -1.5) {
            return 'below_average';
        } else {
            return 'well_below_average';
        }
    }

    /**
     * Interpret correlation coefficient
     *
     * @param float $correlation Correlation coefficient
     * @return string Interpretation
     */
    private function interpretCorrelation(float $correlation): string
    {
        $abs = abs($correlation);

        if ($abs >= 0.8) {
            return 'excellent';
        } elseif ($abs >= 0.6) {
            return 'good';
        } elseif ($abs >= 0.4) {
            return 'fair';
        } else {
            return 'poor';
        }
    }
}
