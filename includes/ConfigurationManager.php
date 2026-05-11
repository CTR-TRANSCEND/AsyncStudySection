<?php
declare(strict_types=1);
/**
 * ConfigurationManager Class
 * SPEC: SPEC-ADM-001 Admin Panel Enhancements
 * Feature: Configuration Management UI
 * Description: Manages dynamic system configuration with validation and audit logging
 * Created: 2025-01-04
 * TAG: Design-TAG -> Function-TAG -> Test-TAG
 */

class ConfigurationManager
{
    private $db;
    private $updatedBy;

    /**
     * Constructor
     * @param PDO $db Database connection
     * @param int $updatedBy User ID making changes
     */
    public function __construct(PDO $db, $updatedBy)
    {
        $this->db = $db;
        $this->updatedBy = (int)$updatedBy;
    }

    /**
     * Get configuration value
     * @param string $key Configuration key
     * @param mixed $default Default value if not found
     * @return mixed Configuration value
     */
    public function get($key, $default = null)
    {
        try {
            $query = "SELECT config_value, config_type FROM system_configuration WHERE config_key = :config_key";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':config_key' => $key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return $default;
            }

            return $this->castValue($result['config_value'], $result['config_type']);
        } catch (PDOException $e) {
            error_log("ConfigurationManager get error: " . $e->getMessage());
            return $default;
        }
    }

    /**
     * Set configuration value
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @param string $type Value type (string, integer, boolean, json)
     * @param bool $isSensitive Whether value is sensitive
     * @param string $description Configuration description
     * @param string $category Configuration category
     * @return bool True if successful
     */
    public function set($key, $value, $type = 'string', $isSensitive = false, $description = null, $category = 'general')
    {
        try {
            // Validate value
            if (!$this->validateValue($value, $type)) {
                return false;
            }

            // Get old value for audit log
            $oldValue = $this->get($key);

            // Convert value to string for storage
            $stringValue = $this->valueToString($value, $type);

            // Check if config exists
            $query = "SELECT id FROM system_configuration WHERE config_key = :config_key";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':config_key' => $key]);
            $exists = $stmt->fetch();

            if ($exists) {
                // Update existing
                $query = "UPDATE system_configuration
                          SET config_value = :config_value,
                              config_type = :config_type,
                              is_sensitive = :is_sensitive,
                              description = :description,
                              category = :category,
                              updated_by = :updated_by,
                              updated_at = NOW()
                          WHERE config_key = :config_key";

                $stmt = $this->db->prepare($query);
                $result = $stmt->execute([
                    ':config_key' => $key,
                    ':config_value' => $stringValue,
                    ':config_type' => $type,
                    ':is_sensitive' => $isSensitive ? 1 : 0,
                    ':description' => $description,
                    ':category' => $category,
                    ':updated_by' => $this->updatedBy
                ]);
            } else {
                // Insert new
                $query = "INSERT INTO system_configuration
                          (config_key, config_value, config_type, is_sensitive, description, category, updated_by)
                          VALUES (:config_key, :config_value, :config_type, :is_sensitive, :description, :category, :updated_by)";

                $stmt = $this->db->prepare($query);
                $result = $stmt->execute([
                    ':config_key' => $key,
                    ':config_value' => $stringValue,
                    ':config_type' => $type,
                    ':is_sensitive' => $isSensitive ? 1 : 0,
                    ':description' => $description,
                    ':category' => $category,
                    ':updated_by' => $this->updatedBy
                ]);
            }

            if ($result) {
                // Log to audit_log
                $this->logAuditEvent($key, $oldValue, $value);
            }

            return $result;
        } catch (PDOException $e) {
            error_log("ConfigurationManager set error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete configuration
     * @param string $key Configuration key
     * @return bool True if deleted
     */
    public function delete($key)
    {
        try {
            // Get old value for audit log
            $oldValue = $this->get($key);

            $query = "DELETE FROM system_configuration WHERE config_key = :config_key";
            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([':config_key' => $key]);

            if ($result) {
                // Log to audit_log
                $this->logAuditEvent($key, $oldValue, null);
            }

            return $result;
        } catch (PDOException $e) {
            error_log("ConfigurationManager delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all configurations
     * @param string $category Filter by category
     * @return array Array of configurations
     */
    public function getAll($category = null)
    {
        try {
            if ($category) {
                $query = "SELECT * FROM system_configuration WHERE category = :category ORDER BY category, config_key";
                $stmt = $this->db->prepare($query);
                $stmt->execute([':category' => $category]);
            } else {
                $query = "SELECT * FROM system_configuration ORDER BY category, config_key";
                $stmt = $this->db->query($query);
            }

            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Cast values and mask sensitive
            foreach ($results as &$config) {
                $config['typed_value'] = $this->castValue($config['config_value'], $config['config_type']);
                if ($config['is_sensitive']) {
                    $config['config_value'] = '****HIDDEN****';
                }
            }

            return $results;
        } catch (PDOException $e) {
            error_log("ConfigurationManager getAll error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get configuration categories
     * @return array Array of categories
     */
    public function getCategories()
    {
        try {
            $query = "SELECT DISTINCT category FROM system_configuration ORDER BY category";
            $stmt = $this->db->query($query);
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

            return $results;
        } catch (PDOException $e) {
            error_log("ConfigurationManager getCategories error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Validate configuration value
     * @param mixed $value Value to validate
     * @param string $type Expected type
     * @return bool True if valid
     */
    private function validateValue($value, $type)
    {
        switch ($type) {
            case 'string':
                return is_string($value);

            case 'integer':
                return is_numeric($value) && (int)$value == $value; // loose comparison intentional: checks that value has no fractional part

            case 'boolean':
                return is_bool($value) || in_array(strtolower($value), ['true', 'false', '1', '0']);

            case 'json':
                json_decode($value);
                return json_last_error() === JSON_ERROR_NONE;

            default:
                return false;
        }
    }

    /**
     * Cast value from string to proper type
     * @param string $value String value
     * @param string $type Value type
     * @return mixed Casted value
     */
    private function castValue($value, $type)
    {
        switch ($type) {
            case 'string':
                return (string)$value;

            case 'integer':
                return (int)$value;

            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'json':
                $decoded = json_decode($value, true);
                if ($decoded === null && $value !== 'null') {
                    throw new \InvalidArgumentException('Invalid JSON in configuration value');
                }
                return $decoded;

            default:
                return $value;
        }
    }

    /**
     * Convert value to string for storage
     * @param mixed $value Value to convert
     * @param string $type Value type
     * @return string String representation
     */
    private function valueToString($value, $type)
    {
        switch ($type) {
            case 'boolean':
                return $value ? 'true' : 'false';

            case 'json':
                return is_string($value) ? $value : json_encode($value);

            case 'integer':
                return (string)(int)$value;

            case 'string':
            default:
                return (string)$value;
        }
    }

    /**
     * Log configuration change to audit log
     * @param string $key Configuration key
     * @param mixed $oldValue Old value
     * @param mixed $newValue New value
     */
    private function logAuditEvent($key, $oldValue, $newValue)
    {
        try {
            $query = "INSERT INTO audit_log
                      (table_name, record_id, field_name, old_value, new_value, changed_by, action_type, changed_at)
                      VALUES ('system_configuration', 0, :key, :old_value, :new_value, :changed_by, 'update', NOW())";

            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':key' => $key,
                ':old_value' => is_null($oldValue) ? null : json_encode($oldValue),
                ':new_value' => is_null($newValue) ? null : json_encode($newValue),
                ':changed_by' => $this->updatedBy
            ]);
        } catch (PDOException $e) {
            error_log("ConfigurationManager logAuditEvent error: " . $e->getMessage());
        }
    }

    /**
     * Export configuration to JSON
     * @param bool $includeSensitive Include sensitive values
     * @return string JSON export
     */
    public function exportToJson($includeSensitive = false)
    {
        $configs = $this->getAll();

        $export = [];
        foreach ($configs as $config) {
            if ($config['is_sensitive'] && !$includeSensitive) {
                continue;
            }

            $export[] = [
                'key' => $config['config_key'],
                'value' => $config['typed_value'],
                'type' => $config['config_type'],
                'category' => $config['category']
            ];
        }

        return json_encode($export, JSON_PRETTY_PRINT);
    }

    /**
     * Import configuration from JSON
     * @param string $json JSON string
     * @return array Result with success_count and error_count
     */
    public function importFromJson($json)
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON: ' . json_last_error_msg()
            ];
        }

        $successCount = 0;
        $errorCount = 0;

        foreach ($data as $item) {
            if (!isset($item['key']) || !isset($item['value'])) {
                $errorCount++;
                continue;
            }

            $type = $item['type'] ?? 'string';
            $category = $item['category'] ?? 'general';

            if ($this->set($item['key'], $item['value'], $type, false, null, $category)) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        return [
            'success' => true,
            'success_count' => $successCount,
            'error_count' => $errorCount
        ];
    }
}
