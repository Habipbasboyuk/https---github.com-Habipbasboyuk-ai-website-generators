<?php

namespace UiXpress\Activity;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class ActivityDatabase
 * 
 * Handles database schema creation and management for UiXpress Activity Logger
 * Only initializes when activity logger is enabled in settings
 * 
 * @since 1.0.0
 */
class ActivityDatabase
{
    /**
     * ActivityDatabase constructor.
     */
    public function __construct()
    {
        add_action('admin_init', [$this, 'maybe_initialize_activity_tables']);
    }

    /**
     * Checks if activity logger is enabled and initializes tables if needed
     * 
     * @return void
     * @since 1.0.0
     */
    public function maybe_initialize_activity_tables()
    {
        $settings = get_option('uixpress_settings', []);
        
        if (!isset($settings['enable_activity_logger']) || !$settings['enable_activity_logger']) {
            return;
        }

        if (!$this->table_exists()) {
            $this->create_activity_table();
        }
    }

    /**
     * Checks if activity log table exists
     * 
     * @return bool True if table exists, false otherwise
     * @since 1.0.0
     */
    private function table_exists()
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'uixpress_activity_log';
        // Use prepared statement to prevent SQL injection
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
        
        return $table_exists;
    }

    /**
     * Creates the activity log table with the specified schema
     * 
     * @return bool True on success, false on failure
     * @since 1.0.0
     */
    private function create_activity_table()
    {
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create activity log table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}uixpress_activity_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            object_type VARCHAR(50) NOT NULL,
            object_id BIGINT UNSIGNED DEFAULT NULL,
            old_value LONGTEXT DEFAULT NULL,
            new_value LONGTEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            metadata LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_object_type (object_type),
            INDEX idx_object_id (object_id),
            INDEX idx_created_at (created_at),
            INDEX idx_user_action (user_id, action),
            INDEX idx_object (object_type, object_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // Execute table creation
        dbDelta($sql);

        return true;
    }

    /**
     * Checks if activity logger is enabled in plugin settings
     * 
     * @return bool True if activity logger is enabled, false otherwise
     * @since 1.0.0
     */
    public static function is_activity_logger_enabled()
    {
        $settings = get_option('uixpress_settings', []);
        return isset($settings['enable_activity_logger']) && $settings['enable_activity_logger'];
    }

    /**
     * Handle activity logger enable/disable
     * 
     * @param bool $enabled Whether activity logger should be enabled
     * @return void
     * @since 1.0.0
     */
    public static function toggle_activity_logger($enabled)
    {
        if ($enabled) {
            // Activity logger is being enabled - create table if needed
            $instance = new self();
            $instance->maybe_initialize_activity_tables();
        } else {
            // Activity logger is being disabled - unschedule cron jobs
            ActivityCron::unschedule_cron_jobs();
        }
    }
}

