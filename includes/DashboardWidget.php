<?php
declare(strict_types=1);
/**
 * DashboardWidget Class
 * SPEC: SPEC-ADM-001 Admin Panel Enhancements
 * Feature: Dashboard Customization
 * Description: Manages dashboard widgets with drag-and-drop customization
 * Created: 2025-01-04
 * TAG: Design-TAG -> Function-TAG -> Test-TAG
 */

class DashboardWidget
{
    private $db;
    private $userId;

    /**
     * Default widget configuration
     */
    private $defaultWidgets = [
        [
            'id' => 'stats',
            'title' => 'Statistics',
            'type' => 'stat_cards',
            'visible' => true,
            'position' => 0
        ],
        [
            'id' => 'recent_apps',
            'title' => 'Recent Applications',
            'type' => 'list',
            'visible' => true,
            'position' => 1
        ],
        [
            'id' => 'activity',
            'title' => 'Recent Activity',
            'type' => 'timeline',
            'visible' => true,
            'position' => 2
        ],
        [
            'id' => 'study_sections',
            'title' => 'Study Sections Overview',
            'type' => 'table',
            'visible' => true,
            'position' => 3
        ]
    ];

    /**
     * Constructor
     * @param PDO $db Database connection
     * @param int $userId User ID
     */
    public function __construct(PDO $db, $userId)
    {
        $this->db = $db;
        $this->userId = (int)$userId;
    }

    /**
     * Get user dashboard layout
     * @return array Dashboard layout
     */
    public function getLayout()
    {
        try {
            $query = "SELECT preference_value FROM user_preferences
                      WHERE user_id = :user_id AND preference_key = 'dashboard_layout'";

            $stmt = $this->db->prepare($query);
            $stmt->execute([':user_id' => $this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $layout = json_decode($result['preference_value'], true);
                if ($layout && isset($layout['widgets'])) {
                    return $layout;
                }
            }

            // Return default layout
            return $this->getDefaultLayout();
        } catch (PDOException $e) {
            error_log("DashboardWidget getLayout error: " . $e->getMessage());
            return $this->getDefaultLayout();
        }
    }

    /**
     * Get default layout
     * @return array Default layout
     */
    private function getDefaultLayout()
    {
        $widgetIds = array_map(function($w) { return $w['id']; }, $this->defaultWidgets);
        return [
            'widgets' => $widgetIds,
            'order' => array_keys($widgetIds)
        ];
    }

    /**
     * Save dashboard layout
     * @param array $widgetIds Array of widget IDs in order
     * @return bool True if saved
     */
    public function saveLayout($widgetIds)
    {
        try {
            $layout = [
                'widgets' => $widgetIds,
                'order' => array_keys($widgetIds)
            ];

            $value = json_encode($layout);

            // Atomic upsert — avoids race condition in check-then-act pattern
            $query = "INSERT INTO user_preferences (user_id, preference_key, preference_value)
                      VALUES (:user_id, 'dashboard_layout', :value)
                      ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_at = NOW()";

            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([
                ':value' => $value,
                ':user_id' => $this->userId
            ]);

            if ($result) {
                // Log to audit_log
                $this->logAuditEvent('dashboard_layout', null, $layout);
            }

            return $result;
        } catch (PDOException $e) {
            error_log("DashboardWidget saveLayout error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get widget visibility settings
     * @return array Widget visibility settings
     */
    public function getVisibility()
    {
        try {
            $query = "SELECT preference_value FROM user_preferences
                      WHERE user_id = :user_id AND preference_key = 'widget_visibility'";

            $stmt = $this->db->prepare($query);
            $stmt->execute([':user_id' => $this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $visibility = json_decode($result['preference_value'], true);
                if ($visibility) {
                    return $visibility;
                }
            }

            // Return default visibility
            return $this->getDefaultVisibility();
        } catch (PDOException $e) {
            error_log("DashboardWidget getVisibility error: " . $e->getMessage());
            return $this->getDefaultVisibility();
        }
    }

    /**
     * Get default visibility
     * @return array Default visibility
     */
    private function getDefaultVisibility()
    {
        $visibility = [];
        foreach ($this->defaultWidgets as $widget) {
            $visibility[$widget['id']] = $widget['visible'];
        }
        return $visibility;
    }

    /**
     * Save widget visibility
     * @param array $visibility Array of widget_id => visible boolean
     * @return bool True if saved
     */
    public function saveVisibility($visibility)
    {
        try {
            $value = json_encode($visibility);

            // Atomic upsert — avoids race condition in check-then-act pattern
            $query = "INSERT INTO user_preferences (user_id, preference_key, preference_value)
                      VALUES (:user_id, 'widget_visibility', :value)
                      ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_at = NOW()";

            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([
                ':value' => $value,
                ':user_id' => $this->userId
            ]);

            if ($result) {
                // Log to audit_log
                $this->logAuditEvent('widget_visibility', null, $visibility);
            }

            return $result;
        } catch (PDOException $e) {
            error_log("DashboardWidget saveVisibility error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all available widgets
     * @return array All widgets
     */
    public function getAllWidgets()
    {
        return $this->defaultWidgets;
    }

    /**
     * Get widgets for rendering (filtered by visibility and ordered)
     * @return array Widgets for rendering
     */
    public function getWidgetsForRendering()
    {
        $layout = $this->getLayout();
        $visibility = $this->getVisibility();
        $allWidgets = $this->getAllWidgets();

        $widgets = [];

        // Create widget map
        $widgetMap = [];
        foreach ($allWidgets as $widget) {
            $widgetMap[$widget['id']] = $widget;
        }

        // Apply layout order and visibility
        if (isset($layout['widgets'])) {
            foreach ($layout['widgets'] as $index => $widgetId) {
                if (isset($widgetMap[$widgetId])) {
                    $widget = $widgetMap[$widgetId];
                    $widget['position'] = $index;
                    // Check visibility
                    $isVisible = isset($visibility[$widgetId]) ? $visibility[$widgetId] : true;
                    $widget['visible'] = $isVisible;
                    if ($isVisible) {
                        $widgets[] = $widget;
                    }
                }
            }
        }

        return $widgets;
    }

    /**
     * Reset to default layout
     * @return bool True if reset
     */
    public function resetToDefault()
    {
        try {
            $query = "DELETE FROM user_preferences
                      WHERE user_id = :user_id AND preference_key IN ('dashboard_layout', 'widget_visibility')";

            $stmt = $this->db->prepare($query);
            $result = $stmt->execute([':user_id' => $this->userId]);

            if ($result) {
                // Log to audit_log
                $this->logAuditEvent('dashboard_reset', null, 'reset_to_default');
            }

            return $result;
        } catch (PDOException $e) {
            error_log("DashboardWidget resetToDefault error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add custom widget
     * @param string $id Widget ID
     * @param string $title Widget title
     * @param string $type Widget type
     * @param array $data Widget data
     * @return bool True if added
     */
    public function addCustomWidget($id, $title, $type, $data = [])
    {
        try {
            $widget = [
                'id' => $id,
                'title' => $title,
                'type' => $type,
                'visible' => true,
                'position' => count($this->defaultWidgets),
                'data' => $data,
                'custom' => true
            ];

            // Add to user preferences
            $query = "SELECT preference_value FROM user_preferences
                      WHERE user_id = :user_id AND preference_key = 'custom_widgets'";

            $stmt = $this->db->prepare($query);
            $stmt->execute([':user_id' => $this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $customWidgets = [];
            if ($result) {
                $customWidgets = json_decode($result['preference_value'], true) ?: [];
            }

            $customWidgets[$id] = $widget;

            $value = json_encode($customWidgets);

            // Save
            if ($result) {
                $query = "UPDATE user_preferences
                          SET preference_value = :value, updated_at = NOW()
                          WHERE user_id = :user_id AND preference_key = 'custom_widgets'";
            } else {
                $query = "INSERT INTO user_preferences (user_id, preference_key, preference_value)
                          VALUES (:user_id, 'custom_widgets', :value)";
            }

            $stmt = $this->db->prepare($query);
            return $stmt->execute([
                ':value' => $value,
                ':user_id' => $this->userId
            ]);
        } catch (PDOException $e) {
            error_log("DashboardWidget addCustomWidget error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get custom widgets
     * @return array Custom widgets
     */
    public function getCustomWidgets()
    {
        try {
            $query = "SELECT preference_value FROM user_preferences
                      WHERE user_id = :user_id AND preference_key = 'custom_widgets'";

            $stmt = $this->db->prepare($query);
            $stmt->execute([':user_id' => $this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $widgets = json_decode($result['preference_value'], true);
                return $widgets ?: [];
            }

            return [];
        } catch (PDOException $e) {
            error_log("DashboardWidget getCustomWidgets error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Log dashboard change to audit log
     * @param string $key Preference key
     * @param mixed $oldValue Old value
     * @param mixed $newValue New value
     */
    private function logAuditEvent($key, $oldValue, $newValue)
    {
        try {
            $query = "INSERT INTO audit_log
                      (table_name, record_id, field_name, old_value, new_value, changed_by, action_type, changed_at)
                      VALUES ('user_preferences', 0, :key, :old_value, :new_value, :changed_by, 'update', NOW())";

            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':key' => $key,
                ':old_value' => is_null($oldValue) ? null : json_encode($oldValue),
                ':new_value' => is_null($newValue) ? null : json_encode($newValue),
                ':changed_by' => $this->userId
            ]);
        } catch (PDOException $e) {
            error_log("DashboardWidget logAuditEvent error: " . $e->getMessage());
        }
    }
}
