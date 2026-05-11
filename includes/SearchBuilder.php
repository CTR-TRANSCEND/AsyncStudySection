<?php
declare(strict_types=1);
/**
 * SearchBuilder Class
 * SPEC: SPEC-ADM-001 Admin Panel Enhancements
 * Feature: Advanced Search and Filtering
 * Description: Builds secure search queries with parameter binding to prevent SQL injection
 * Created: 2025-01-04
 * TAG: Design-TAG -> Function-TAG -> Test-TAG
 */

class SearchBuilder
{
    private $table;
    private $db;
    private $filters = [];
    private $sortField = null;
    private $sortOrder = 'ASC';
    private $page = 1;
    private $pageSize = 20;
    private $offset = 0;

    /**
     * Whitelist of allowed field names per table.
     * Keys are table names; values are arrays of permitted column names.
     * Add entries here when creating a SearchBuilder for a new table.
     */
    private static $fieldWhitelists = [
        'applications' => [
            'id', 'grant_id', 'applicant_name', 'application_title',
            'grant_type', 'grant_type_id', 'status', 'study_section_id', 'is_complete',
            'created_at', 'updated_at',
        ],
        'reviews' => [
            'id', 'application_id', 'reviewer_id', 'overall_impact_score',
            'relevance_score', 'budget_acceptable', 'review_date',
            'created_at', 'updated_at',
        ],
        'users' => [
            'id', 'name', 'email', 'role', 'is_active',
            'created_at', 'updated_at',
        ],
        'study_sections' => [
            'id', 'name', 'description', 'created_at',
        ],
        'grant_types' => [
            'id', 'name', 'description', 'created_at',
        ],
        'assignments' => [
            'id', 'application_id', 'reviewer_id', 'created_at',
        ],
    ];

    /**
     * Constructor
     * @param string $table Table name to search
     * @param PDO $db Database connection
     */
    public function __construct($table, PDO $db)
    {
        $this->table = $table;
        $this->db = $db;
    }

    /**
     * Validate that a field name is on the whitelist for the current table.
     * Throws InvalidArgumentException if the field is not permitted.
     *
     * @param string $field Field name to validate
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateFieldName($field)
    {
        // Allow qualified field names like "table.column"
        if (strpos($field, '.') !== false) {
            list($tbl, $col) = explode('.', $field, 2);
            $allowed = self::$fieldWhitelists[$tbl] ?? null;
            if ($allowed === null || !in_array($col, $allowed, true)) {
                throw new InvalidArgumentException("Field name not allowed: " . $field);
            }
            return;
        }

        $allowed = self::$fieldWhitelists[$this->table] ?? null;
        if ($allowed !== null && !in_array($field, $allowed, true)) {
            throw new InvalidArgumentException("Field name not allowed: " . $field);
        }

        // If no whitelist is configured for this table, fall back to a
        // strict pattern check: only alphanumeric characters and underscores.
        if ($allowed === null && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            throw new InvalidArgumentException("Field name not allowed: " . $field);
        }
    }

    /**
     * Get table name
     * @return string
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * Get all filters
     * @return array
     */
    public function getFilters()
    {
        return $this->filters;
    }

    /**
     * Add a filter to the search query
     * @param string $field Field name
     * @param mixed $value Filter value
     * @param string $type Filter type (text, select, date_range, multi_select, numeric_range)
     * @return self
     */
    public function addFilter($field, $value, $type = 'text')
    {
        $this->validateFieldName($field);
        $this->filters[] = [
            'field' => $field,
            'value' => $value,
            'type' => $type
        ];
        return $this;
    }

    /**
     * Clear all filters
     * @return self
     */
    public function clearFilters()
    {
        $this->filters = [];
        return $this;
    }

    /**
     * Set pagination parameters
     * @param int $page Page number (1-based)
     * @param int $pageSize Records per page
     * @return self
     */
    public function setPagination($page, $pageSize)
    {
        $this->page = max(1, (int)$page);
        $this->pageSize = max(1, min(1000, (int)$pageSize));
        $this->offset = ($this->page - 1) * $this->pageSize;
        return $this;
    }

    /**
     * Set sort parameters
     * @param string $field Field to sort by
     * @param string $order Sort direction (ASC or DESC)
     * @return self
     */
    public function setSort($field, $order = 'ASC')
    {
        $this->validateFieldName($field);
        $allowedOrders = ['ASC', 'DESC'];
        $this->sortField = $field;
        $this->sortOrder = in_array(strtoupper($order), $allowedOrders) ? strtoupper($order) : 'ASC';
        return $this;
    }

    /**
     * Build the WHERE clause based on filters
     * @return array Array with 'clause' string and 'params' array
     */
    private function buildWhereClause()
    {
        $conditions = [];
        $params = [];

        $paramCounter = 0;

        foreach ($this->filters as $index => $filter) {
            $field = $filter['field'];
            $value = $filter['value'];
            $type = $filter['type'];

            switch ($type) {
                case 'text':
                    // Use LIKE with wildcards for text search
                    $paramName = ":{$field}_" . $paramCounter++;
                    $conditions[] = "{$field} LIKE {$paramName}";
                    $params[$paramName] = "%{$value}%";
                    break;

                case 'select':
                    // Exact match
                    $paramName = ":{$field}_" . $paramCounter++;
                    $conditions[] = "{$field} = {$paramName}";
                    $params[$paramName] = $value;
                    break;

                case 'multi_select':
                    // IN clause for multiple values
                    if (is_array($value) && !empty($value)) {
                        $placeholders = [];
                        foreach ($value as $i => $v) {
                            $paramName = ":{$field}_{$paramCounter}";
                            $paramCounter++;
                            $placeholders[] = $paramName;
                            $params[$paramName] = $v;
                        }
                        $inClause = implode(', ', $placeholders);
                        $conditions[] = "{$field} IN ({$inClause})";
                    }
                    break;

                case 'date_range':
                    // BETWEEN clause for date ranges
                    if (is_array($value) && count($value) === 2) {
                        $startParam = ":{$field}_start_" . $paramCounter++;
                        $endParam = ":{$field}_end_" . $paramCounter++;
                        $conditions[] = "{$field} BETWEEN {$startParam} AND {$endParam}";
                        $params[$startParam] = $value[0];
                        $params[$endParam] = $value[1];
                    }
                    break;

                case 'numeric_range':
                    // Range check for numeric values
                    if (is_array($value) && count($value) === 2) {
                        $minParam = ":{$field}_min_" . $paramCounter++;
                        $maxParam = ":{$field}_max_" . $paramCounter++;
                        $conditions[] = "{$field} >= {$minParam} AND {$field} <= {$maxParam}";
                        $params[$minParam] = $value[0];
                        $params[$maxParam] = $value[1];
                    }
                    break;

                default:
                    // Default to exact match
                    $paramName = ":{$field}_" . $paramCounter++;
                    $conditions[] = "{$field} = {$paramName}";
                    $params[$paramName] = $value;
                    break;
            }
        }

        $whereClause = '';
        if (!empty($conditions)) {
            $whereClause = ' WHERE ' . implode(' AND ', $conditions);
        }

        return [
            'clause' => $whereClause,
            'params' => $params
        ];
    }

    /**
     * Build the complete search query
     * @return string SQL query
     */
    public function buildQuery()
    {
        $where = $this->buildWhereClause();

        $query = "SELECT * FROM {$this->table}";
        $query .= $where['clause'];

        if ($this->sortField) {
            $query .= " ORDER BY {$this->sortField} {$this->sortOrder}";
        }

        $query .= " LIMIT " . (int) $this->pageSize . " OFFSET " . (int) $this->offset;

        return $query;
    }

    /**
     * Build count query for pagination
     * @return string SQL count query
     */
    public function buildCountQuery()
    {
        $where = $this->buildWhereClause();

        $query = "SELECT COUNT(*) as total FROM {$this->table}";
        $query .= $where['clause'];

        return $query;
    }

    /**
     * Get parameters for prepared statement
     * @return array Parameters array
     */
    public function getParameters()
    {
        $where = $this->buildWhereClause();
        return $where['params'];
    }

    /**
     * Execute the search query
     * @return array Query results
     * @throws PDOException On database error
     */
    public function execute()
    {
        $query = $this->buildQuery();
        $params = $this->getParameters();

        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("SearchBuilder query error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get total count of matching records
     * @return int Total count
     * @throws PDOException On database error
     */
    public function getCount()
    {
        $query = $this->buildCountQuery();
        $params = $this->getParameters();

        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['total'];
        } catch (PDOException $e) {
            error_log("SearchBuilder count error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get pagination info
     * @return array Pagination info with total, page, pageSize, totalPages
     */
    public function getPaginationInfo()
    {
        $total = $this->getCount();
        $totalPages = ceil($total / $this->pageSize);

        return [
            'total' => $total,
            'page' => $this->page,
            'pageSize' => $this->pageSize,
            'totalPages' => $totalPages,
            'offset' => $this->offset
        ];
    }
}
