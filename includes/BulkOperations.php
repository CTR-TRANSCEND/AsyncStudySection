<?php
declare(strict_types=1);
/**
 * BulkOperations Class
 * SPEC: SPEC-ADM-001 Admin Panel Enhancements
 * Feature: Bulk User Operations
 * Description: Handles bulk administrative operations with audit logging and rollback support
 * Created: 2025-01-04
 * TAG: Design-TAG -> Function-TAG -> Test-TAG
 */

class BulkOperations
{
    private $db;
    private $createdBy;
    private $allowedOperations = ['activate', 'deactivate', 'role_change', 'assign_section', 'password_reset'];
    private $allowedTables = ['users', 'applications', 'assignments'];

    /**
     * Constructor
     * @param PDO $db Database connection
     * @param int $createdBy User ID performing the operation
     */
    public function __construct(PDO $db, $createdBy)
    {
        $this->db = $db;
        $this->createdBy = (int)$createdBy;
    }

    /**
     * Get created_by user ID
     * @return int
     */
    public function getCreatedBy()
    {
        return $this->createdBy;
    }

    /**
     * Validate bulk operation parameters
     * @param string $operationType Operation type
     * @param string $targetTable Target table name
     * @param array $targetIds Array of record IDs
     * @param bool $confirmLargeOp Confirmation for operations >100 records
     * @return bool True if valid
     */
    public function validate($operationType, $targetTable, $targetIds, $confirmLargeOp = false)
    {
        // Validate operation type
        if (!in_array($operationType, $this->allowedOperations)) {
            return false;
        }

        // Validate target table
        if (!in_array($targetTable, $this->allowedTables)) {
            return false;
        }

        // Validate target IDs
        if (!is_array($targetIds) || empty($targetIds)) {
            return false;
        }

        // Check for large operations requiring confirmation
        if (count($targetIds) > 100 && !$confirmLargeOp) {
            return false;
        }

        return true;
    }

    /**
     * Create bulk operation record in database
     * @param string $operationType Operation type
     * @param string $targetTable Target table
     * @param array $targetIds Target record IDs
     * @return int|false Operation ID or false on failure
     */
    public function create($operationType, $targetTable, $targetIds)
    {
        try {
            $query = "INSERT INTO bulk_operations
                      (operation_type, target_table, target_ids, status, created_by)
                      VALUES (:op_type, :target_table, :target_ids, 'pending', :created_by)";

            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([
                ':op_type' => $operationType,
                ':target_table' => $targetTable,
                ':target_ids' => json_encode($targetIds),
                ':created_by' => $this->createdBy
            ]);

            if ($result) {
                $operationId = (int)$this->db->lastInsertId();

                // Log to audit_log
                $this->logAuditEvent($operationType, $targetTable, $targetIds);

                return $operationId;
            }

            return false;
        } catch (PDOException $e) {
            error_log("BulkOperations create error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Bulk activate users
     * @param array $targetIds User IDs to activate
     * @return array Result with status, success_count, error_count
     */
    public function activate($targetIds)
    {
        return $this->executeBulkOperation('activate', 'users', $targetIds, function() use ($targetIds) {
            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $query = "UPDATE users SET is_active = TRUE WHERE id IN ({$placeholders})";
            $stmt = $this->db->prepare($query);
            $stmt->execute($targetIds);
            return $stmt->rowCount();
        });
    }

    /**
     * Bulk deactivate users
     * @param array $targetIds User IDs to deactivate
     * @return array Result with status, success_count, error_count
     */
    public function deactivate($targetIds)
    {
        return $this->executeBulkOperation('deactivate', 'users', $targetIds, function() use ($targetIds) {
            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $query = "UPDATE users SET is_active = FALSE WHERE id IN ({$placeholders})";
            $stmt = $this->db->prepare($query);
            $stmt->execute($targetIds);
            return $stmt->rowCount();
        });
    }

    /**
     * Bulk role change
     * @param array $targetIds User IDs
     * @param string $newRole New role to assign
     * @return array Result with status, success_count, error_count
     */
    public function changeRole($targetIds, $newRole)
    {
        return $this->executeBulkOperation('role_change', 'users', $targetIds, function() use ($targetIds, $newRole) {
            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $query = "UPDATE users SET role = ? WHERE id IN ({$placeholders})";
            $params = array_merge([$newRole], $targetIds);
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            return $stmt->rowCount();
        }, ['new_role' => $newRole]);
    }

    /**
     * Check if applications have reviews
     * @param array $targetIds Application IDs
     * @return array Array with application_id as key and boolean as value
     */
    public function checkApplicationsHaveReviews($targetIds)
    {
        try {
            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $query = "SELECT application_id, COUNT(*) as review_count
                      FROM reviews
                      WHERE application_id IN ({$placeholders})
                      GROUP BY application_id";

            $stmt = $this->db->prepare($query);
            $stmt->execute($targetIds);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $hasReviews = [];
            foreach ($targetIds as $id) {
                $hasReviews[$id] = false;
            }

            foreach ($results as $row) {
                $hasReviews[$row['application_id']] = ($row['review_count'] > 0);
            }

            return $hasReviews;
        } catch (PDOException $e) {
            error_log("BulkOperations checkApplicationsHaveReviews error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Bulk delete applications (with review check)
     * @param array $targetIds Application IDs to delete
     * @return array Result with status, success_count, blocked array
     */
    public function deleteApplications($targetIds)
    {
        // Check for reviews first
        $hasReviews = $this->checkApplicationsHaveReviews($targetIds);
        $blocked = array_keys(array_filter($hasReviews));

        if (!empty($blocked)) {
            return [
                'status' => false,
                'error' => 'Cannot delete applications with existing reviews',
                'blocked' => $blocked,
                'success_count' => 0,
                'error_count' => count($blocked)
            ];
        }

        // Proceed with deletion
        return $this->executeBulkOperation('delete', 'applications', $targetIds, function() use ($targetIds) {
            $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
            $query = "DELETE FROM applications WHERE id IN ({$placeholders})";
            $stmt = $this->db->prepare($query);
            $stmt->execute($targetIds);
            return $stmt->rowCount();
        });
    }

    /**
     * Execute bulk operation with transaction support
     * @param string $operationType Operation type
     * @param string $targetTable Target table
     * @param array $targetIds Target IDs
     * @param callable $operation Operation function to execute
     * @param array $additionalData Additional data for results
     * @return array Result array
     */
    private function executeBulkOperation($operationType, $targetTable, $targetIds, callable $operation, $additionalData = [])
    {
        try {
            $this->db->beginTransaction();

            // Create bulk operation record
            $operationId = $this->create($operationType, $targetTable, $targetIds);

            if ($operationId === false || $operationId === null) {
                $this->db->rollBack();
                error_log("BulkOperations: Failed to create operation record for $operationType on $targetTable");
                return [
                    'status' => false,
                    'error' => 'Failed to create operation audit record',
                    'success_count' => 0,
                    'error_count' => count($targetIds)
                ];
            }

            // Update status to in_progress
            $this->updateOperationStatus($operationId, 'in_progress');

            // Execute the operation
            $successCount = $operation();

            // Update status to completed
            $this->updateOperationStatus($operationId, 'completed', $successCount);

            $this->db->commit();

            $result = [
                'status' => true,
                'success_count' => $successCount,
                'error_count' => 0,
                'operation_id' => $operationId
            ];

            return array_merge($result, $additionalData);
        } catch (Exception $e) {
            $this->db->rollBack();

            // Update status to failed
            if (isset($operationId)) {
                $this->updateOperationStatus($operationId, 'failed', 0, $e->getMessage());
            }

            error_log("BulkOperations execute error: " . $e->getMessage());

            return [
                'status' => false,
                'error' => $e->getMessage(),
                'success_count' => 0,
                'error_count' => count($targetIds)
            ];
        }
    }

    /**
     * Update bulk operation status
     * @param int $operationId Operation ID
     * @param string $status New status
     * @param int $successCount Success count
     * @param string $errorMessage Error message if any
     */
    private function updateOperationStatus($operationId, $status, $successCount = 0, $errorMessage = null)
    {
        try {
            $query = "UPDATE bulk_operations
                      SET status = :status, completed_at = NOW(), error_message = :error_message
                      WHERE id = :operation_id";

            $results = null;
            if ($status === 'completed') {
                $results = json_encode(['success_count' => $successCount]);
            }

            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':status' => $status,
                ':error_message' => $errorMessage,
                ':operation_id' => $operationId
            ]);

            // Update results field if completed
            if ($results) {
                $query = "UPDATE bulk_operations SET results = :results WHERE id = :operation_id";
                $stmt = $this->db->prepare($query);
                $stmt->execute([
                    ':results' => $results,
                    ':operation_id' => $operationId
                ]);
            }
        } catch (PDOException $e) {
            error_log("BulkOperations updateStatus error: " . $e->getMessage());
        }
    }

    /**
     * Rollback a bulk operation
     * @param int $operationId Operation ID to rollback
     * @return array Result with status and restored_count
     */
    public function rollback($operationId)
    {
        try {
            // Get operation details
            $operation = $this->getOperationDetails($operationId);

            if (!$operation) {
                return ['status' => false, 'error' => 'Operation not found'];
            }

            if ($operation['status'] !== 'completed') {
                return ['status' => false, 'error' => 'Can only rollback completed operations'];
            }

            $this->db->beginTransaction();

            $restoredCount = 0;

            // Reverse based on operation type
            $targetIds = json_decode($operation['target_ids'], true);
            if (!is_array($targetIds) || empty($targetIds)) {
                throw new Exception("Invalid or empty target_ids for operation: " . $operationId);
            }

            switch ($operation['operation_type']) {
                case 'activate':
                    // Reverse by deactivating
                    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                    $query = "UPDATE users SET is_active = FALSE WHERE id IN ({$placeholders})";
                    $stmt = $this->db->prepare($query);
                    $stmt->execute($targetIds);
                    $restoredCount = $stmt->rowCount();
                    break;

                case 'deactivate':
                    // Reverse by activating
                    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
                    $query = "UPDATE users SET is_active = TRUE WHERE id IN ({$placeholders})";
                    $stmt = $this->db->prepare($query);
                    $stmt->execute($targetIds);
                    $restoredCount = $stmt->rowCount();
                    break;

                default:
                    throw new Exception("Rollback not supported for operation type: " . $operation['operation_type']);
            }

            // Log rollback
            $this->logAuditEvent('rollback', $operation['target_table'], $targetIds);

            $this->db->commit();

            return [
                'status' => true,
                'restored_count' => $restoredCount,
                'operation_id' => $operationId
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("BulkOperations rollback error: " . $e->getMessage());

            return [
                'status' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get bulk operation status
     * @param int $operationId Operation ID
     * @return array|false Operation details or false
     */
    public function getStatus($operationId)
    {
        return $this->getOperationDetails($operationId);
    }

    /**
     * Get operation details
     * @param int $operationId Operation ID
     * @return array|false Operation details or false
     */
    private function getOperationDetails($operationId)
    {
        try {
            $query = "SELECT * FROM bulk_operations WHERE id = :operation_id";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':operation_id' => $operationId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("BulkOperations getOperationDetails error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log audit event for bulk operation
     * @param string $operationType Operation type
     * @param string $targetTable Target table
     * @param array $targetIds Target IDs
     */
    private function logAuditEvent($operationType, $targetTable, $targetIds)
    {
        try {
            $query = "INSERT INTO audit_log
                      (table_name, record_id, field_name, old_value, new_value, changed_by, action_type)
                      VALUES (:table_name, 0, 'bulk_operation', NULL, :new_value, :changed_by, :action_type)";

            $details = [
                'operation' => $operationType,
                'target_count' => count($targetIds),
                'target_ids' => $targetIds
            ];

            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':table_name' => $targetTable,
                ':new_value' => json_encode($details),
                ':changed_by' => $this->createdBy,
                ':action_type' => 'bulk_' . $operationType
            ]);
        } catch (PDOException $e) {
            error_log("BulkOperations logAuditEvent error: " . $e->getMessage());
        }
    }

    /**
     * Get recent bulk operations
     * @param int $limit Number of operations to return
     * @return array Recent operations
     */
    public function getRecentOperations($limit = 10)
    {
        try {
            $query = "SELECT bo.*, u.full_name as created_by_name
                      FROM bulk_operations bo
                      JOIN users u ON bo.created_by = u.id
                      ORDER BY bo.created_at DESC
                      LIMIT :limit";

            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("BulkOperations getRecentOperations error: " . $e->getMessage());
            return [];
        }
    }
}
