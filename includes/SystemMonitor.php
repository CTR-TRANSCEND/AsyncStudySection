<?php
declare(strict_types=1);
/**
 * SystemMonitor Class
 * SPEC: SPEC-ADM-001 Admin Panel Enhancements
 * Feature: System Health Monitoring
 * Description: Collects and monitors system health metrics with alerting
 * Created: 2025-01-04
 * TAG: Design-TAG -> Function-TAG -> Test-TAG
 */

class SystemMonitor
{
    private $db;
    private $thresholds = [
        'disk_usage_warning' => 80,
        'disk_usage_critical' => 90,
        'db_connection_warning' => 80,
        'error_log_warning' => 100,
        'failed_login_warning' => 10
    ];

    /**
     * Constructor
     * @param PDO $db Database connection
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Collect all system health metrics
     * @return array Array of all metrics
     */
    public function collectAllMetrics()
    {
        return [
            'disk_usage_uploads' => $this->getDiskUsage('../uploads'),
            'disk_usage_backups' => $this->getDiskUsage('../backups'),
            'database_size' => $this->getDatabaseSize(),
            'db_connection_count' => $this->getDbConnectionCount(),
            'db_max_connections' => $this->getDbMaxConnections(),
            'error_log_24h' => $this->getErrorLogCount(24),
            'login_success_24h' => $this->getLoginCount('success', 24),
            'login_failed_24h' => $this->getLoginCount('failed', 24),
            'total_applications' => $this->getTotalApplications(),
            'total_reviews' => $this->getTotalReviews(),
            'review_completion_rate' => $this->getReviewCompletionRate()
        ];
    }

    /**
     * Get disk usage for a directory
     * @param string $directory Directory path
     * @return array Array with usage percentage, used, total
     */
    public function getDiskUsage($directory)
    {
        $path = realpath($directory);
        if (!$path || !is_dir($path)) {
            return [
                'percentage' => 0,
                'used' => 0,
                'total' => 0,
                'unit' => 'MB'
            ];
        }

        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;

        if ($total <= 0) {
            return ['total' => 0, 'free' => 0, 'used' => 0, 'percentage' => 0];
        }

        $percentage = ($used / $total) * 100;

        return [
            'percentage' => round($percentage, 2),
            'used' => round($used / 1024 / 1024, 2), // Convert to MB
            'total' => round($total / 1024 / 1024, 2), // Convert to MB
            'unit' => 'MB'
        ];
    }

    /**
     * Get database size
     * @return float Database size in MB
     */
    public function getDatabaseSize()
    {
        try {
            $dbName = $this->getCurrentDatabaseName();
            $query = "SELECT
                        ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size
                      FROM information_schema.tables
                      WHERE table_schema = :db_name";

            $stmt = $this->db->prepare($query);
            $stmt->execute([':db_name' => $dbName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (float)$result['size'] : 0;
        } catch (PDOException $e) {
            error_log("SystemMonitor getDatabaseSize error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get current database connection count
     * @return int Connection count
     */
    public function getDbConnectionCount()
    {
        try {
            $query = "SHOW STATUS LIKE 'Threads_connected'";
            $stmt = $this->db->query($query);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int)$result['Value'] : 0;
        } catch (PDOException $e) {
            error_log("SystemMonitor getDbConnectionCount error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get maximum database connections
     * @return int Max connections
     */
    public function getDbMaxConnections()
    {
        try {
            $query = "SHOW VARIABLES LIKE 'max_connections'";
            $stmt = $this->db->query($query);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int)$result['Value'] : 100;
        } catch (PDOException $e) {
            error_log("SystemMonitor getDbMaxConnections error: " . $e->getMessage());
            return 100;
        }
    }

    /**
     * Get error log count in last N hours
     * @param int $hours Number of hours
     * @return int Error count
     */
    public function getErrorLogCount($hours = 24)
    {
        // This is a placeholder - actual implementation would check error logs
        // For now, return 0 as we don't have a structured error logging table
        return 0;
    }

    /**
     * Get login count (success or failed) in last N hours
     * @param string $type 'success' or 'failed'
     * @param int $hours Number of hours
     * @return int Login count
     */
    public function getLoginCount($type = 'success', $hours = 24)
    {
        try {
            // This would require a login_attempts table
            // For now, return 0 as placeholder
            return 0;
        } catch (PDOException $e) {
            error_log("SystemMonitor getLoginCount error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get total applications count
     * @return int Total applications
     */
    public function getTotalApplications()
    {
        try {
            $query = "SELECT COUNT(*) as count FROM applications";
            $stmt = $this->db->query($query);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int)$result['count'] : 0;
        } catch (PDOException $e) {
            error_log("SystemMonitor getTotalApplications error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get total reviews count
     * @return int Total reviews
     */
    public function getTotalReviews()
    {
        try {
            $query = "SELECT COUNT(*) as count FROM reviews";
            $stmt = $this->db->query($query);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? (int)$result['count'] : 0;
        } catch (PDOException $e) {
            error_log("SystemMonitor getTotalReviews error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get review completion rate
     * @return float Completion rate percentage
     */
    public function getReviewCompletionRate()
    {
        try {
            $query = "SELECT
                        COUNT(CASE WHEN a.is_complete = TRUE THEN 1 END) * 100.0 / COUNT(*) as rate
                      FROM assignments a";

            $stmt = $this->db->query($query);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? round((float)$result['rate'], 2) : 0;
        } catch (PDOException $e) {
            error_log("SystemMonitor getReviewCompletionRate error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Store metric in database
     * @param string $metricName Metric name
     * @param float $value Metric value
     * @param string $unit Metric unit
     */
    public function storeMetric($metricName, $value, $unit = null)
    {
        try {
            $query = "INSERT INTO system_health_metrics
                      (metric_name, metric_value, metric_unit, measured_at)
                      VALUES (:metric_name, :metric_value, :metric_unit, NOW())";

            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':metric_name' => $metricName,
                ':metric_value' => $value,
                ':metric_unit' => $unit
            ]);
        } catch (PDOException $e) {
            error_log("SystemMonitor storeMetric error: " . $e->getMessage());
        }
    }

    /**
     * Store all metrics in database
     */
    public function storeAllMetrics()
    {
        $metrics = $this->collectAllMetrics();

        foreach ($metrics as $name => $data) {
            if (is_array($data)) {
                if (isset($data['percentage'])) {
                    $this->storeMetric($name, $data['percentage'], '%');
                }
            } else {
                $this->storeMetric($name, $data);
            }
        }
    }

    /**
     * Evaluate thresholds and create alerts
     * @return array Array of alerts generated
     */
    public function evaluateAlerts()
    {
        $metrics = $this->collectAllMetrics();
        $alerts = [];

        // Check disk usage
        if (isset($metrics['disk_usage_uploads']['percentage'])) {
            $usage = $metrics['disk_usage_uploads']['percentage'];
            if ($usage >= $this->thresholds['disk_usage_critical']) {
                $alerts[] = $this->createAlert(
                    'critical',
                    'Disk usage for uploads directory is critical',
                    'disk_usage_uploads',
                    $this->thresholds['disk_usage_critical'],
                    $usage
                );
            } elseif ($usage >= $this->thresholds['disk_usage_warning']) {
                $alerts[] = $this->createAlert(
                    'warning',
                    'Disk usage for uploads directory is high',
                    'disk_usage_uploads',
                    $this->thresholds['disk_usage_warning'],
                    $usage
                );
            }
        }

        // Check database connections
        if ($metrics['db_max_connections'] > 0) {
            $connectionUsage = ($metrics['db_connection_count'] / $metrics['db_max_connections']) * 100;
            if ($connectionUsage >= $this->thresholds['db_connection_warning']) {
                $alerts[] = $this->createAlert(
                    'warning',
                    'Database connection pool usage is high',
                    'db_connection_count',
                    $this->thresholds['db_connection_warning'],
                    $connectionUsage
                );
            }
        }

        // Check error logs
        if ($metrics['error_log_24h'] >= $this->thresholds['error_log_warning']) {
            $alerts[] = $this->createAlert(
                'warning',
                'High number of error log entries in last 24 hours',
                'error_log_24h',
                $this->thresholds['error_log_warning'],
                $metrics['error_log_24h']
            );
        }

        return $alerts;
    }

    /**
     * Create alert in database
     * @param string $type Alert type (warning, critical, security)
     * @param string $message Alert message
     * @param string $metricName Metric name
     * @param float $threshold Threshold value
     * @param float $actualValue Actual value
     * @return int|false Alert ID or false
     */
    private function createAlert($type, $message, $metricName, $threshold, $actualValue)
    {
        try {
            $query = "INSERT INTO system_alerts
                      (alert_type, alert_message, metric_name, threshold_value, actual_value, created_at)
                      VALUES (:alert_type, :alert_message, :metric_name, :threshold_value, :actual_value, NOW())";

            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':alert_type' => $type,
                ':alert_message' => $message,
                ':metric_name' => $metricName,
                ':threshold_value' => $threshold,
                ':actual_value' => $actualValue
            ]);

            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("SystemMonitor createAlert error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get active alerts
     * @return array Array of active alerts
     */
    public function getActiveAlerts()
    {
        try {
            $query = "SELECT * FROM system_alerts
                      WHERE is_resolved = FALSE
                      ORDER BY
                        CASE alert_type
                          WHEN 'critical' THEN 1
                          WHEN 'security' THEN 2
                          WHEN 'warning' THEN 3
                        END,
                        created_at DESC";

            $stmt = $this->db->query($query);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("SystemMonitor getActiveAlerts error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Resolve alert
     * @param int $alertId Alert ID
     * @param int $resolvedBy User ID resolving the alert
     * @param string $note Resolution note
     * @return bool True if resolved
     */
    public function resolveAlert($alertId, $resolvedBy, $note = null)
    {
        try {
            $query = "UPDATE system_alerts
                      SET is_resolved = TRUE, resolved_at = NOW(), resolved_by = :resolved_by, resolution_note = :note
                      WHERE id = :alert_id";

            $stmt = $this->db->prepare($query);
            return $stmt->execute([
                ':alert_id' => $alertId,
                ':resolved_by' => $resolvedBy,
                ':note' => $note
            ]);
        } catch (PDOException $e) {
            error_log("SystemMonitor resolveAlert error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get system health status
     * @return string 'healthy', 'warning', or 'critical'
     */
    public function getHealthStatus()
    {
        $alerts = $this->getActiveAlerts();

        foreach ($alerts as $alert) {
            if ($alert['alert_type'] === 'critical' || $alert['alert_type'] === 'security') {
                return 'critical';
            }
        }

        if (!empty($alerts)) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Get metric history
     * @param string $metricName Metric name
     * @param int $hours Number of hours of history
     * @return array Metric history data
     */
    public function getMetricHistory($metricName, $hours = 24)
    {
        try {
            $query = "SELECT metric_value, metric_unit, measured_at
                      FROM system_health_metrics
                      WHERE metric_name = :metric_name
                      AND measured_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                      ORDER BY measured_at ASC";

            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':metric_name' => $metricName,
                ':hours' => $hours
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("SystemMonitor getMetricHistory error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get current database name
     * @return string Database name
     */
    private function getCurrentDatabaseName()
    {
        try {
            $stmt = $this->db->query('SELECT DATABASE() as db_name');
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['db_name'];
        } catch (PDOException $e) {
            return '';
        }
    }
}
