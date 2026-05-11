<?php
declare(strict_types=1);
/**
 * ReportSecurity Middleware
 * SPEC-RPT-001: Reporting and Analytics System
 *
 * Provides security controls for reporting module:
 * - Role-based access control (RBAC)
 * - Rate limiting for report generation
 * - Audit logging for all report operations
 * - Input validation and sanitization
 *
 * @author SPEC-RPT-001 Implementation
 * @version 1.0.0
 * @created 2025-01-04
 */

class ReportSecurity
{
    private const RATE_LIMIT_KEY_PREFIX = 'report_rate_limit_';
    private const MAX_REPORTS_PER_PERIOD = 10;
    private const RATE_LIMIT_PERIOD = 300; // 5 minutes in seconds

    private PDO $db;

    public function __construct(PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * Verify admin access for reporting features
     *
     * @param int $userId User ID to check
     * @return bool True if user has admin access
     * @throws RuntimeException If user lacks permission
     */
    public function requireAdminAccess(int $userId): bool
    {
        // Use session role to avoid redundant DB query — session is authoritative after login
        $role = $_SESSION['role'] ?? null;

        if ($role !== 'admin') {
            $this->logAudit('unauthorized_report_access', $userId, [
                'action' => 'access_denied',
                'reason' => 'non_admin_user'
            ]);
            throw new RuntimeException('Access denied: Administrator privileges required');
        }

        return true;
    }

    /**
     * Check rate limit for report generation
     *
     * @param int $userId User ID to check
     * @return bool True if within rate limit
     * @throws RuntimeException If rate limit exceeded
     */
    public function checkRateLimit(int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM report_generation_log
            WHERE generated_by = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
              AND status IN ('pending', 'completed')
        ");
        $stmt->execute([$userId, self::RATE_LIMIT_PERIOD]);
        $result = $stmt->fetch();

        $count = (int) $result['count'];

        if ($count >= self::MAX_REPORTS_PER_PERIOD) {
            $this->logAudit('rate_limit_exceeded', $userId, [
                'count' => $count,
                'limit' => self::MAX_REPORTS_PER_PERIOD,
                'period' => self::RATE_LIMIT_PERIOD
            ]);
            throw new RuntimeException(
                'Rate limit exceeded: Maximum ' . self::MAX_REPORTS_PER_PERIOD .
                ' reports per ' . (self::RATE_LIMIT_PERIOD / 60) . ' minutes'
            );
        }

        return true;
    }

    /**
     * Validate and sanitize report parameters
     *
     * @param array $params Raw user input parameters
     * @return array Validated and sanitized parameters
     * @throws InvalidArgumentException If validation fails
     */
    public function validateReportParams(array $params): array
    {
        $validated = [];

        // Validate date range if provided
        if (isset($params['date_from'])) {
            $validated['date_from'] = $this->validateDate($params['date_from']);
        }
        if (isset($params['date_to'])) {
            $validated['date_to'] = $this->validateDate($params['date_to']);
        }

        // Validate date range logic
        if (isset($validated['date_from']) && isset($validated['date_to'])) {
            if ($validated['date_from'] > $validated['date_to']) {
                throw new InvalidArgumentException('Date from must be before date to');
            }
        }

        // Validate application IDs if provided
        if (isset($params['application_ids'])) {
            $validated['application_ids'] = $this->validateIntArray($params['application_ids']);
        }

        // Validate study section ID if provided
        if (isset($params['study_section_id'])) {
            $validated['study_section_id'] = (int) $params['study_section_id'];
        }

        // Validate grant type ID if provided
        if (isset($params['grant_type_id'])) {
            $validated['grant_type_id'] = (int) $params['grant_type_id'];
        }

        // Validate score range if provided
        if (isset($params['score_min'])) {
            $validated['score_min'] = $this->validateScore($params['score_min']);
        }
        if (isset($params['score_max'])) {
            $validated['score_max'] = $this->validateScore($params['score_max']);
        }

        // Validate score range logic
        if (isset($validated['score_min']) && isset($validated['score_max'])) {
            if ($validated['score_min'] > $validated['score_max']) {
                throw new InvalidArgumentException('Minimum score cannot exceed maximum score');
            }
        }

        // Validate export format if provided
        if (isset($params['export_format'])) {
            $validated['export_format'] = $this->validateExportFormat($params['export_format']);
        }

        // Validate pagination parameters
        if (isset($params['page'])) {
            $validated['page'] = max(1, (int) $params['page']);
        }
        if (isset($params['page_size'])) {
            $allowedSizes = [25, 50, 100];
            $pageSize = (int) $params['page_size'];
            $validated['page_size'] = in_array($pageSize, $allowedSizes) ? $pageSize : 25;
        }

        return $validated;
    }

    /**
     * Validate date string and convert to DateTime object
     *
     * @param string $dateString Date string to validate
     * @return string Validated ISO date string
     * @throws InvalidArgumentException If date is invalid
     */
    private function validateDate(string $dateString): string
    {
        $date = DateTime::createFromFormat('Y-m-d', $dateString);
        if (!$date || $date->format('Y-m-d') !== $dateString) {
            throw new InvalidArgumentException('Invalid date format: ' . $dateString);
        }
        return $dateString;
    }

    /**
     * Validate array of integers
     *
     * @param mixed $input Input to validate
     * @return array Array of integers
     * @throws InvalidArgumentException If input is not array of integers
     */
    private function validateIntArray($input): array
    {
        if (!is_array($input)) {
            throw new InvalidArgumentException('Expected array of integers');
        }

        $result = [];
        foreach ($input as $value) {
            if (!is_numeric($value)) {
                throw new InvalidArgumentException('All values must be integers');
            }
            $result[] = (int) $value;
        }

        return $result;
    }

    /**
     * Validate score is within valid range (1-9)
     *
     * @param mixed $score Score to validate
     * @return int Validated score
     * @throws InvalidArgumentException If score is invalid
     */
    private function validateScore($score): int
    {
        $score = (int) $score;
        if ($score < 1 || $score > 9) {
            throw new InvalidArgumentException('Score must be between 1 and 9');
        }
        return $score;
    }

    /**
     * Validate export format
     *
     * @param string $format Format to validate
     * @return string Validated format
     * @throws InvalidArgumentException If format is invalid
     */
    private function validateExportFormat(string $format): string
    {
        $allowedFormats = ['pdf', 'xlsx', 'csv', 'docx'];
        $format = strtolower(trim($format));

        if (!in_array($format, $allowedFormats)) {
            throw new InvalidArgumentException('Invalid export format: ' . $format);
        }

        return $format;
    }

    /**
     * Log audit trail for report operations
     *
     * @param string $action Action performed
     * @param int $userId User ID who performed action
     * @param array $metadata Additional metadata to log
     * @return bool True if log successful
     */
    public function logAudit(string $action, int $userId, array $metadata = []): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_log (table_name, record_id, field_name, old_value, new_value, changed_by, action_type)
                VALUES ('report_generation', 0, ?, NULL, ?, ?, ?)
            ");

            $metadataJson = json_encode($metadata);

            $stmt->execute([
                $action,
                $metadataJson,
                $userId,
                'report_' . $action
            ]);

            return true;
        } catch (Exception $e) {
            // Log errors but don't throw to avoid disrupting main flow
            error_log('ReportSecurity audit log failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sanitize report output to prevent XSS
     *
     * @param array $data Data to sanitize
     * @return array Sanitized data
     */
    public function sanitizeOutput(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeOutput($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Check if report contains sensitive information
     *
     * @param array $reportConfig Report configuration
     * @return bool True if report contains sensitive data
     */
    public function containsSensitiveData(array $reportConfig): bool
    {
        $sensitiveFields = [
            'reviewer_identities',
            'reviewer_names',
            'discussion_messages',
            'internal_notes'
        ];

        foreach ($sensitiveFields as $field) {
            if (isset($reportConfig[$field]) && $reportConfig[$field] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate SQL injection prevention
     *
     * @param string $input Input to check
     * @return bool True if input is safe
     * @throws InvalidArgumentException If SQL injection detected
     */
    public function validateSqlSafe(string $input): bool
    {
        $dangerousPatterns = [
            '/\b(union\s+select)\b/i',
            '/\b(or\s+1\s*=\s*1)\b/i',
            '/\b(and\s+1\s*=\s*1)\b/i',
            '/\b(drop\s+table)\b/i',
            '/\b(delete\s+from)\b/i',
            '/\b(insert\s+into)\b/i',
            '/\b(update\s+\w+\s+set)\b/i',
            '/--/',
            '/\/\*/',
            '/;\s*$/',
            '/\b(exec\b|execute\b)/i'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                throw new InvalidArgumentException('Input contains potentially dangerous SQL patterns');
            }
        }

        return true;
    }
}
