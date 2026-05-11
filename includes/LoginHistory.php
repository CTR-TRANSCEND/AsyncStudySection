<?php
/**
 * Login History Management Class
 * SPEC-AUTH-001.5: Login History and Audit
 *
 * Features:
 * - Comprehensive login event recording
 * - User login history viewing
 * - Admin login history with filters
 * - CSV export functionality
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Records and retrieves login attempt history for audit and security reporting.
 */
class LoginHistory {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Record a login attempt
     * @param int|null $userId User ID (null for failed attempts with unknown user)
     * @param string $username Username attempted
     * @param string $ipAddress IP address
     * @param string|null $userAgent User agent string
     * @param bool $success Whether login was successful
     * @param string|null $failureReason Reason for failure (if applicable)
     * @return bool Success status
     */
    public function recordLogin(?int $userId, string $username, string $ipAddress, ?string $userAgent, bool $success, ?string $failureReason = null): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO login_history (user_id, username, ip_address, user_agent, success, failure_reason)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $username,
                $ipAddress,
                $userAgent,
                $success ? 1 : 0,
                $success ? null : $failureReason
            ]);

            return true;
        } catch (PDOException $e) {
            error_log("Login history recording failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get login history for a specific user
     * @param int $userId User ID
     * @param int $limit Maximum number of records
     * @param int $offset Offset for pagination
     * @return array Login history records
     */
    public function getUserHistory(int $userId, int $limit = 50, int $offset = 0): array {
        try {
            $stmt = $this->db->prepare("
                SELECT id, username, ip_address, user_agent, success,
                       failure_reason, country_code, city, login_time
                FROM login_history
                WHERE user_id = ?
                ORDER BY login_time DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $limit, $offset]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get all login history with filtering (admin)
     * @param array $filters Filter criteria
     * @param int $limit Maximum number of records
     * @param int $offset Offset for pagination
     * @return array Login history records
     */
    public function getFilteredHistory(array $filters = [], int $limit = 100, int $offset = 0): array {
        try {
            $where = ['1=1'];
            $params = [];

            if (!empty($filters['username'])) {
                $where[] = 'username LIKE ?';
                $params[] = '%' . $filters['username'] . '%';
            }

            if (!empty($filters['ip_address'])) {
                $where[] = 'ip_address = ?';
                $params[] = $filters['ip_address'];
            }

            if (isset($filters['success'])) {
                $where[] = 'success = ?';
                $params[] = $filters['success'] ? 1 : 0;
            }

            if (!empty($filters['date_from'])) {
                $where[] = 'login_time >= ?';
                $params[] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $where[] = 'login_time <= ?';
                $params[] = $filters['date_to'];
            }

            $whereClause = implode(' AND ', $where);

            $stmt = $this->db->prepare("
                SELECT lh.id, lh.user_id, lh.username, lh.ip_address,
                       lh.user_agent, lh.success, lh.failure_reason,
                       lh.country_code, lh.city, lh.login_time,
                       u.full_name, u.role
                FROM login_history lh
                LEFT JOIN users u ON lh.user_id = u.id
                WHERE {$whereClause}
                ORDER BY lh.login_time DESC
                LIMIT ? OFFSET ?
            ");

            $stmt->execute([...$params, $limit, $offset]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get failed login attempts (for security monitoring)
     * @param int $hours Number of hours to look back
     * @return array Failed login attempts
     */
    public function getRecentFailures(int $hours = 24): array {
        try {
            $stmt = $this->db->prepare("
                SELECT username, ip_address, failure_reason, login_time,
                       COUNT(*) as attempt_count
                FROM login_history
                WHERE success = 0 AND login_time > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY username, ip_address
                ORDER BY attempt_count DESC, login_time DESC
                LIMIT 50
            ");
            $stmt->execute([$hours]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get suspicious activity patterns
     * @return array Suspicious activity indicators
     */
    public function getSuspiciousActivity(): array {
        try {
            $suspicious = [];

            // Multiple failed logins from same IP
            $stmt = $this->db->prepare("
                SELECT ip_address, COUNT(*) as fail_count
                FROM login_history
                WHERE success = 0 AND login_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                GROUP BY ip_address
                HAVING fail_count >= 5
            ");
            $stmt->execute();
            $suspicious['multiple_failures_by_ip'] = $stmt->fetchAll();

            // Failed logins for multiple usernames from same IP
            $stmt = $this->db->prepare("
                SELECT ip_address, COUNT(DISTINCT username) as user_count
                FROM login_history
                WHERE success = 0 AND login_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                GROUP BY ip_address
                HAVING user_count >= 3
            ");
            $stmt->execute();
            $suspicious['multiple_usernames_by_ip'] = $stmt->fetchAll();

            // Unusual time access (outside business hours)
            $stmt = $this->db->prepare("
                SELECT user_id, username, COUNT(*) as login_count
                FROM login_history
                WHERE success = 1
                  AND HOUR(login_time) NOT BETWEEN 8 AND 18
                  AND login_time > DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY user_id, username
                HAVING login_count >= 3
            ");
            $stmt->execute();
            $suspicious['unusual_time_access'] = $stmt->fetchAll();

            return $suspicious;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Export login history to CSV
     * @param array $filters Filter criteria
     * @return string CSV content
     */
    public function exportToCsv(array $filters = []): string {
        $records = $this->getFilteredHistory($filters, 10000);

        $output = fopen('php://temp', 'r+');

        // Header
        fputcsv($output, [
            'Date/Time', 'Username', 'Full Name', 'IP Address',
            'Location', 'Device', 'Status', 'Failure Reason'
        ]);

        // Data rows
        foreach ($records as $record) {
            $location = $record['city'] ?? '';
            if ($record['country_code'] ?? '') {
                $location .= ($location ? ', ' : '') . $record['country_code'];
            }

            fputcsv($output, [
                $record['login_time'],
                $record['username'],
                $record['full_name'] ?? 'Unknown',
                $record['ip_address'],
                $location ?: 'Unknown',
                $this->detectDeviceType($record['user_agent'] ?? null),
                $record['success'] ? 'Success' : 'Failed',
                $record['failure_reason'] ?? ''
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Detect device type from user agent
     * @param string|null $userAgent User agent string
     * @return string Device type
     */
    private function detectDeviceType(?string $userAgent): string {
        if ($userAgent === null) {
            return 'Unknown';
        }

        if (preg_match('/(ipad|tablet)/i', $userAgent)) {
            return 'Tablet';
        } elseif (preg_match('/(android|iphone|mobile)/i', $userAgent)) {
            return 'Mobile';
        } else {
            return 'Desktop';
        }
    }

    /**
     * Cleanup old login history records
     * @param int $daysToKeep Number of days to keep
     * @return int Number of records deleted
     */
    public function cleanupOldRecords(int $daysToKeep = 90): int {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM login_history
                WHERE success = 1 AND login_time < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);
            $deleted = $stmt->rowCount();

            // Keep failed attempts longer (365 days)
            $stmt = $this->db->prepare("
                DELETE FROM login_history
                WHERE success = 0 AND login_time < DATE_SUB(NOW(), INTERVAL 365 DAY)
            ");
            $stmt->execute();
            $deleted += $stmt->rowCount();

            return $deleted;
        } catch (PDOException $e) {
            error_log("Login history cleanup failed: " . $e->getMessage());
            return 0;
        }
    }
}
