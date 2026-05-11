<?php
declare(strict_types=1);
/**
 * Discussion Moderator Class
 * SPEC: SPEC-DISC-001 (Moderation Tools)
 * Description: Administrative moderation tools for discussions
 * Version: 1.0.0
 * Date: 2025-01-04
 */

require_once __DIR__ . '/auth.php';

class DiscussionModerator
{
    /**
     * Soft delete a message
     *
     * @param PDO $db Database connection
     * @param int $messageId Message ID
     * @param int $adminId Admin user ID performing the action
     * @return bool True on success, false on failure
     * @throws InvalidArgumentException If not admin
     */
    public static function softDeleteMessage($db, $messageId, $adminId = null)
    {
        if ($adminId === null) {
            $adminId = Auth::getUserId();
        }

        // Verify admin权限
        if (!Auth::isAdmin()) {
            throw new InvalidArgumentException('Only administrators can delete messages.');
        }

        // Get message for audit log
        $stmt = $db->prepare("SELECT * FROM discussion_messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();

        if (!$message) {
            return false;
        }

        // Soft delete the message
        $sql = "
            UPDATE discussion_messages
            SET is_deleted = TRUE,
                deleted_at = NOW(),
                deleted_by = ?
            WHERE id = ?
        ";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([$adminId, $messageId]);

        if ($result) {
            // Log to audit trail
            logAudit(
                'discussion_messages',
                $messageId,
                'is_deleted',
                'FALSE',
                'TRUE',
                'update'
            );

            logAudit(
                'discussion_messages',
                $messageId,
                'deleted_by',
                null,
                $adminId,
                'update'
            );
        }

        return $result;
    }

    /**
     * Restore a deleted message
     *
     * @param PDO $db Database connection
     * @param int $messageId Message ID
     * @param int $adminId Admin user ID
     * @return bool True on success, false on failure
     */
    public static function restoreMessage($db, $messageId, $adminId = null)
    {
        if ($adminId === null) {
            $adminId = Auth::getUserId();
        }

        if (!Auth::isAdmin()) {
            throw new InvalidArgumentException('Only administrators can restore messages.');
        }

        $sql = "
            UPDATE discussion_messages
            SET is_deleted = FALSE,
                deleted_at = NULL,
                deleted_by = NULL
            WHERE id = ?
        ";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([$messageId]);

        if ($result) {
            logAudit(
                'discussion_messages',
                $messageId,
                'is_deleted',
                'TRUE',
                'FALSE',
                'update'
            );
        }

        return $result;
    }

    /**
     * Pin a message
     *
     * @param PDO $db Database connection
     * @param int $messageId Message ID
     * @param int $adminId Admin user ID
     * @return bool True on success, false on failure
     */
    public static function pinMessage($db, $messageId, $adminId = null)
    {
        if ($adminId === null) {
            $adminId = Auth::getUserId();
        }

        if (!Auth::isAdmin()) {
            throw new InvalidArgumentException('Only administrators can pin messages.');
        }

        $sql = "
            UPDATE discussion_messages
            SET is_pinned = TRUE,
                pinned_at = NOW()
            WHERE id = ?
        ";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([$messageId]);

        if ($result) {
            logAudit(
                'discussion_messages',
                $messageId,
                'is_pinned',
                'FALSE',
                'TRUE',
                'update'
            );
        }

        return $result;
    }

    /**
     * Unpin a message
     *
     * @param PDO $db Database connection
     * @param int $messageId Message ID
     * @param int $adminId Admin user ID
     * @return bool True on success, false on failure
     */
    public static function unpinMessage($db, $messageId, $adminId = null)
    {
        if ($adminId === null) {
            $adminId = Auth::getUserId();
        }

        if (!Auth::isAdmin()) {
            throw new InvalidArgumentException('Only administrators can unpin messages.');
        }

        $sql = "
            UPDATE discussion_messages
            SET is_pinned = FALSE,
                pinned_at = NULL
            WHERE id = ?
        ";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([$messageId]);

        if ($result) {
            logAudit(
                'discussion_messages',
                $messageId,
                'is_pinned',
                'TRUE',
                'FALSE',
                'update'
            );
        }

        return $result;
    }

    /**
     * Flag a message
     *
     * @param PDO $db Database connection
     * @param int $messageId Message ID
     * @param string $reason Flag reason
     * @param int $adminId Admin user ID
     * @return bool True on success, false on failure
     */
    public static function flagMessage($db, $messageId, $reason, $adminId = null)
    {
        if ($adminId === null) {
            $adminId = Auth::getUserId();
        }

        if (!Auth::isAdmin()) {
            throw new InvalidArgumentException('Only administrators can flag messages.');
        }

        $validReasons = ['spam', 'harassment', 'off-topic', 'inappropriate', 'other'];
        if (!in_array($reason, $validReasons, true)) {
            throw new InvalidArgumentException('Invalid flag reason.');
        }

        $sql = "
            UPDATE discussion_messages
            SET flag_reason = ?
            WHERE id = ?
        ";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([$reason, $messageId]);

        if ($result) {
            logAudit(
                'discussion_messages',
                $messageId,
                'flag_reason',
                null,
                $reason,
                'update'
            );
        }

        return $result;
    }

    /**
     * Lock a discussion
     *
     * @param PDO $db Database connection
     * @param int $applicationId Application ID
     * @param string $reason Lock reason
     * @param int $adminId Admin user ID
     * @return bool True on success, false on failure
     */
    public static function lockDiscussion($db, $applicationId, $reason = '', $adminId = null)
    {
        if ($adminId === null) {
            $adminId = Auth::getUserId();
        }

        if (!Auth::isAdmin()) {
            throw new InvalidArgumentException('Only administrators can lock discussions.');
        }

        $sql = "
            UPDATE applications
            SET discussion_locked = TRUE,
                discussion_locked_at = NOW(),
                discussion_locked_by = ?,
                discussion_locked_reason = ?
            WHERE id = ?
        ";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([$adminId, $reason, $applicationId]);

        if ($result) {
            logAudit(
                'applications',
                $applicationId,
                'discussion_locked',
                'FALSE',
                'TRUE',
                'update'
            );
        }

        return $result;
    }

    /**
     * Unlock a discussion
     *
     * @param PDO $db Database connection
     * @param int $applicationId Application ID
     * @param int $adminId Admin user ID
     * @return bool True on success, false on failure
     */
    public static function unlockDiscussion($db, $applicationId, $adminId = null)
    {
        if ($adminId === null) {
            $adminId = Auth::getUserId();
        }

        if (!Auth::isAdmin()) {
            throw new InvalidArgumentException('Only administrators can unlock discussions.');
        }

        $sql = "
            UPDATE applications
            SET discussion_locked = FALSE,
                discussion_locked_at = NULL,
                discussion_locked_by = NULL,
                discussion_locked_reason = NULL
            WHERE id = ?
        ";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([$applicationId]);

        if ($result) {
            logAudit(
                'applications',
                $applicationId,
                'discussion_locked',
                'TRUE',
                'FALSE',
                'update'
            );
        }

        return $result;
    }

    /**
     * Bulk delete messages
     *
     * @param PDO $db Database connection
     * @param array $messageIds Array of message IDs
     * @param int $adminId Admin user ID
     * @return int Number of messages deleted
     */
    public static function bulkDeleteMessages($db, $messageIds, $adminId = null)
    {
        if ($adminId === null) {
            $adminId = Auth::getUserId();
        }

        if (!Auth::isAdmin()) {
            throw new InvalidArgumentException('Only administrators can delete messages.');
        }

        if (empty($messageIds)) {
            return 0;
        }

        $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';

        $sql = "
            UPDATE discussion_messages
            SET is_deleted = TRUE,
                deleted_at = NOW(),
                deleted_by = ?
            WHERE id IN ($placeholders)
        ";

        $params = array_merge([$adminId], $messageIds);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $deletedCount = $stmt->rowCount();

        // Batch log deletions
        if (!empty($messageIds)) {
            $changedBy = Auth::getUserId() ?? 0;
            $auditValues = [];
            $auditParams = [];
            foreach ($messageIds as $messageId) {
                $auditValues[] = "('discussion_messages', ?, 'is_deleted', 'FALSE', 'TRUE', ?, 'update')";
                $auditParams[] = $messageId;
                $auditParams[] = $changedBy;
            }
            try {
                $auditSql = "INSERT INTO audit_log (table_name, record_id, field_name, old_value, new_value, changed_by, action_type) VALUES " . implode(', ', $auditValues);
                $auditStmt = $db->prepare($auditSql);
                $auditStmt->execute($auditParams);
            } catch (PDOException $e) {
                // Fall back to individual logging if batch fails
                foreach ($messageIds as $messageId) {
                    logAudit('discussion_messages', $messageId, 'is_deleted', 'FALSE', 'TRUE', 'update');
                }
            }
        }

        return $deletedCount;
    }

    /**
     * Get moderation log for an application
     *
     * @param PDO $db Database connection
     * @param int $applicationId Application ID
     * @return array Moderation actions
     */
    public static function getModerationLog($db, $applicationId)
    {
        if (!Auth::isAdmin()) {
            throw new InvalidArgumentException('Only administrators can view moderation logs.');
        }

        $sql = "
            SELECT
                al.*,
                u.full_name as moderator_name,
                dm.message as message_content
            FROM audit_log al
            JOIN users u ON al.changed_by = u.id
            LEFT JOIN discussion_messages dm ON al.record_id = dm.id
            WHERE al.table_name IN ('discussion_messages', 'applications')
              AND al.record_id IN (
                  SELECT id FROM discussion_messages WHERE application_id = ?
                  UNION
                  SELECT ? as id
              )
            ORDER BY al.changed_at DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$applicationId, $applicationId]);
        return $stmt->fetchAll();
    }

    /**
     * Get messages with moderation status
     *
     * @param PDO $db Database connection
     * @param int $applicationId Application ID
     * @param bool $includeDeleted Whether to include deleted messages
     * @return array Messages
     */
    public static function getMessagesForModeration($db, $applicationId, $includeDeleted = false)
    {
        if (!Auth::isAdmin()) {
            throw new InvalidArgumentException('Only administrators can view moderation panel.');
        }

        $whereClause = 'dm.application_id = ?';
        $params = [$applicationId];

        if (!$includeDeleted) {
            $whereClause .= ' AND dm.is_deleted = FALSE';
        }

        $sql = "
            SELECT
                dm.*,
                ass.anonymous_label,
                u.full_name,
                u.email,
                (SELECT COUNT(*) FROM uploaded_files uf WHERE uf.message_id = dm.id) as attachment_count,
                moderator.full_name as deleted_by_name
            FROM discussion_messages dm
            JOIN users u ON dm.user_id = u.id
            LEFT JOIN assignments ass ON dm.application_id = ass.application_id AND dm.user_id = ass.reviewer_id
            LEFT JOIN users moderator ON dm.deleted_by = moderator.id
            WHERE $whereClause
            ORDER BY dm.is_pinned DESC, dm.created_at ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
