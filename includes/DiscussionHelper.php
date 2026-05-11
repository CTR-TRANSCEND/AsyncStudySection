<?php
declare(strict_types=1);
/**
 * Discussion Helper Class
 * SPEC: SPEC-DISC-001 (Discussion System Improvements)
 * Description: Business logic for discussion features including search, read/unread, and exports
 * Version: 1.0.0
 * Date: 2025-01-04
 */

require_once __DIR__ . '/functions.php';

class DiscussionHelper
{
    /**
     * Search messages in a discussion
     *
     * @param PDO $db Database connection
     * @param int $applicationId Application ID
     * @param string $searchTerm Search term
     * @param array $filters Optional filters (date_range, author, has_attachments, is_edited)
     * @param int $limit Result limit (default 20)
     * @param int $offset Result offset (default 0)
     * @return array Array of messages with highlighted search terms
     */
    public static function searchMessages($db, $applicationId, $searchTerm, $filters = [], $limit = 20, $offset = 0)
    {
        $params = [$applicationId];
        $whereClauses = ['dm.application_id = ?'];

        // Add search term condition
        if (!empty($searchTerm)) {
            // Try FULLTEXT search first, fallback to LIKE
            $whereClauses[] = '(MATCH(dm.message) AGAINST(? IN NATURAL LANGUAGE MODE) OR dm.message LIKE ?)';
            $params[] = $searchTerm;
            $params[] = "%$searchTerm%";
        }

        // Add filters
        if (!empty($filters['date_from'])) {
            $whereClauses[] = 'dm.created_at >= ?';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $whereClauses[] = 'dm.created_at <= ?';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['author_id'])) {
            $whereClauses[] = 'dm.user_id = ?';
            $params[] = $filters['author_id'];
        }

        if (isset($filters['has_attachments'])) {
            if ($filters['has_attachments']) {
                $whereClauses[] = 'EXISTS (SELECT 1 FROM uploaded_files uf WHERE uf.message_id = dm.id)';
            } else {
                $whereClauses[] = 'NOT EXISTS (SELECT 1 FROM uploaded_files uf WHERE uf.message_id = dm.id)';
            }
        }

        if (isset($filters['is_edited'])) {
            $whereClauses[] = 'dm.is_edited = ?';
            $params[] = $filters['is_edited'] ? 1 : 0;
        }

        // Exclude deleted messages
        $whereClauses[] = 'dm.is_deleted = FALSE';

        // Build query
        $sql = "
            SELECT
                dm.*,
                ass.anonymous_label,
                u.full_name,
                (SELECT COUNT(*) FROM uploaded_files uf WHERE uf.message_id = dm.id) as attachment_count
            FROM discussion_messages dm
            JOIN users u ON dm.user_id = u.id
            LEFT JOIN assignments ass ON dm.application_id = ass.application_id AND dm.user_id = ass.reviewer_id
            WHERE " . implode(' AND ', $whereClauses) . "
            ORDER BY dm.created_at DESC
            LIMIT " . (int) $limit . " OFFSET " . (int) $offset . "
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        // Highlight search terms in results
        if (!empty($searchTerm)) {
            foreach ($messages as &$msg) {
                $msg['message_html'] = self::highlightSearchTerm($msg['message'], $searchTerm);
            }
        }

        return $messages;
    }

    /**
     * Highlight search terms in text
     *
     * @param string $text Text to highlight
     * @param string $searchTerm Search term
     * @return string Text with highlighted terms
     */
    private static function highlightSearchTerm($text, $searchTerm)
    {
        // Escape HTML first
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Create case-insensitive pattern
        $pattern = preg_quote($searchTerm, '/');
        $pattern = "/($pattern)/i";

        // Replace with highlighted version
        $replacement = '<mark class="search-highlight">$1</mark>';

        return preg_replace($pattern, $replacement, $text);
    }

    /**
     * Get unread count for user across applications
     *
     * @param PDO $db Database connection
     * @param int $userId User ID
     * @return array Array of application_id => unread_count
     */
    public static function getUnreadCounts($db, $userId)
    {
        $sql = "
            SELECT
                dm.application_id,
                COUNT(DISTINCT dm.id) as unread_count
            FROM discussion_messages dm
            JOIN assignments ass ON dm.application_id = ass.application_id AND ass.reviewer_id = ?
            LEFT JOIN discussion_message_reads dmr ON dmr.user_id = ? AND dmr.message_id = dm.id
            WHERE dm.user_id != ?
              AND dm.is_deleted = FALSE
              AND dmr.id IS NULL
            GROUP BY dm.application_id
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $userId, $userId]);
        $results = $stmt->fetchAll();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['application_id']] = (int) $row['unread_count'];
        }

        return $counts;
    }

    /**
     * Mark messages as read
     *
     * @param PDO $db Database connection
     * @param int $userId User ID
     * @param int $applicationId Application ID
     * @param array $messageIds Array of message IDs to mark as read
     * @return int Number of messages marked
     */
    public static function markAsRead($db, $userId, $applicationId, $messageIds)
    {
        if (empty($messageIds)) {
            return 0;
        }

        $marked = 0;

        foreach ($messageIds as $messageId) {
            try {
                $sql = "
                    INSERT INTO discussion_message_reads (user_id, application_id, message_id)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE read_at = NOW()
                ";
                $stmt = $db->prepare($sql);
                $stmt->execute([$userId, $applicationId, $messageId]);
                $marked++;
            } catch (PDOException $e) {
                // Ignore duplicates or foreign key errors
                error_log("Error marking message as read: " . $e->getMessage());
            }
        }

        return $marked;
    }

    /**
     * Mark all messages in application as read
     *
     * @param PDO $db Database connection
     * @param int $userId User ID
     * @param int $applicationId Application ID
     * @return int Number of messages marked
     */
    public static function markAllAsRead($db, $userId, $applicationId)
    {
        $sql = "
            INSERT IGNORE INTO discussion_message_reads (user_id, application_id, message_id)
            SELECT ?, ?, dm.id
            FROM discussion_messages dm
            WHERE dm.application_id = ?
              AND dm.is_deleted = FALSE
              AND dm.user_id != ?
              AND NOT EXISTS (
                  SELECT 1 FROM discussion_message_reads dmr
                  WHERE dmr.user_id = ? AND dmr.message_id = dm.id
              )
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $applicationId, $applicationId, $userId, $userId]);

        return $stmt->rowCount();
    }

    /**
     * Export discussion messages
     *
     * @param PDO $db Database connection
     * @param int $applicationId Application ID
     * @param string $format Export format (pdf, csv, json, html)
     * @param array $options Export options (date_start, date_end, include_attachments)
     * @return array File information (path, size, filename)
     */
    public static function exportDiscussion($db, $applicationId, $format, $options = [])
    {
        // Get messages
        $params = [$applicationId];
        $whereClauses = ['dm.application_id = ?', 'dm.is_deleted = FALSE'];

        if (!empty($options['date_start'])) {
            $whereClauses[] = 'dm.created_at >= ?';
            $params[] = $options['date_start'];
        }

        if (!empty($options['date_end'])) {
            $whereClauses[] = 'dm.created_at <= ?';
            $params[] = $options['date_end'];
        }

        $sql = "
            SELECT
                dm.*,
                ass.anonymous_label,
                u.full_name,
                (SELECT COUNT(*) FROM uploaded_files uf WHERE uf.message_id = dm.id) as attachment_count
            FROM discussion_messages dm
            JOIN users u ON dm.user_id = u.id
            LEFT JOIN assignments ass ON dm.application_id = ass.application_id AND dm.user_id = ass.reviewer_id
            WHERE " . implode(' AND ', $whereClauses) . "
            ORDER BY dm.created_at ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        // Get application details
        $stmt = $db->prepare("SELECT * FROM applications WHERE id = ?");
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch();

        if (!$application) {
            throw new \InvalidArgumentException("Application not found: $applicationId");
        }

        // Generate export based on format
        $exportDir = dirname(__DIR__) . '/exports';
        if (!is_dir($exportDir)) {
            if (!mkdir($exportDir, 0755, true)) {
                throw new RuntimeException("Failed to create export directory: $exportDir");
            }
        }

        $timestamp = date('Y-m-d_H-i-s');
        $filename = "discussion_{$applicationId}_{$timestamp}.{$format}";
        $filepath = "$exportDir/$filename";

        try {
            switch ($format) {
                case 'json':
                    self::exportJson($messages, $application, $filepath);
                    break;
                case 'csv':
                    self::exportCsv($messages, $application, $filepath);
                    break;
                case 'html':
                    self::exportHtml($messages, $application, $filepath);
                    break;
                case 'pdf':
                    // PDF export requires external library (TCPDF)
                    // For now, create HTML and note PDF conversion needed
                    self::exportHtml($messages, $application, $filepath);
                    break;
                default:
                    throw new InvalidArgumentException("Unsupported export format: $format");
            }

            if (!file_exists($filepath)) {
                throw new RuntimeException("Export file was not created: $filepath");
            }

            $fileSize = filesize($filepath);

            // Log export to database
            $userId = Auth::getUserId();
            if ($userId === null) {
                throw new \RuntimeException('Authentication required for discussion export');
            }
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiration

            $sql = "
                INSERT INTO discussion_exports
                (application_id, user_id, export_format, date_range_start, date_range_end, file_path, file_size, include_attachments, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $applicationId,
                $userId,
                $format,
                $options['date_start'] ?? null,
                $options['date_end'] ?? null,
                $filepath,
                $fileSize,
                (int)($options['include_attachments'] ?? false),
                $expiresAt
            ]);
        } catch (\Exception $e) {
            // Clean up orphaned export file on any failure
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            throw $e;
        }

        // Log to audit trail
        logAudit('discussion_exports', $db->lastInsertId(), 'export', null, $filename, 'insert');

        return [
            'filepath' => $filepath,
            'filename' => $filename,
            'filesize' => $fileSize,
            'format' => $format,
            'expires_at' => $expiresAt
        ];
    }

    /**
     * Export as JSON
     */
    private static function exportJson($messages, $application, $filepath)
    {
        $data = [
            'application' => [
                'id' => $application['id'],
                'grant_id' => $application['grant_id'],
                'title' => $application['application_title'],
                'applicant' => $application['applicant_name']
            ],
            'exported_at' => date('c'),
            'message_count' => count($messages),
            'messages' => array_map(function($msg) {
                return [
                    'id' => $msg['id'],
                    'author' => $msg['anonymous_label'] ?? $msg['full_name'],
                    'timestamp' => $msg['created_at'],
                    'content' => $msg['message'],
                    'is_edited' => (bool) $msg['is_edited'],
                    'attachment_count' => (int) $msg['attachment_count']
                ];
            }, $messages)
        ];

        if (file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new \RuntimeException("Failed to write export file: $filepath");
        }
    }

    /**
     * Export as CSV
     */
    private static function exportCsv($messages, $application, $filepath)
    {
        $fp = fopen($filepath, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open file for CSV export: $filepath");
        }

        // Write header
        fputcsv($fp, ['Timestamp', 'Author', 'Message', 'Edited', 'Attachments']);

        // Write rows
        foreach ($messages as $msg) {
            fputcsv($fp, [
                $msg['created_at'],
                $msg['anonymous_label'] ?? $msg['full_name'],
                $msg['message'],
                $msg['is_edited'] ? 'Yes' : 'No',
                $msg['attachment_count']
            ]);
        }

        fclose($fp);
    }

    /**
     * Export as HTML
     */
    private static function exportHtml($messages, $application, $filepath)
    {
        $html = "
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Discussion Export - {$application['grant_id']}</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .app-info { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .message { border-left: 3px solid #007bff; padding: 15px; margin-bottom: 15px; background: white; }
        .message-header { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .author { font-weight: bold; color: #007bff; }
        .timestamp { color: #6c757d; font-size: 0.9em; }
        .content { line-height: 1.6; }
        .edited { color: #ffc107; font-size: 0.85em; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #dee2e6; text-align: center; color: #6c757d; }
    </style>
</head>
<body>
    <h1>Discussion Export</h1>
    <div class='app-info'>
        <p><strong>Grant ID:</strong> " . htmlspecialchars($application['grant_id']) . "</p>
        <p><strong>Title:</strong> " . htmlspecialchars($application['application_title']) . "</p>
        <p><strong>Applicant:</strong> " . htmlspecialchars($application['applicant_name']) . "</p>
        <p><strong>Exported:</strong> " . date('Y-m-d H:i:s') . "</p>
        <p><strong>Messages:</strong> " . count($messages) . "</p>
    </div>
";

        foreach ($messages as $msg) {
            $author = htmlspecialchars($msg['anonymous_label'] ?? $msg['full_name']);
            $timestamp = htmlspecialchars($msg['created_at']);
            $content = nl2br(htmlspecialchars($msg['message']));
            $edited = $msg['is_edited'] ? "<span class='edited'>(edited)</span>" : '';

            $html .= "
    <div class='message'>
        <div class='message-header'>
            <span class='author'>$author</span>
            <span class='timestamp'>$timestamp $edited</span>
        </div>
        <div class='content'>$content</div>
    </div>";
        }

        $html .= "
    <div class='footer'>
        <p>Generated by Asynchronous Grant Review System</p>
    </div>
</body>
</html>";

        if (file_put_contents($filepath, $html) === false) {
            throw new \RuntimeException("Failed to write export file: $filepath");
        }
    }
}
