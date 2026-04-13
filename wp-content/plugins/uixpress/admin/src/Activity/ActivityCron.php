<?php

namespace UiXpress\Activity;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class ActivityCron
 * 
 * Handles scheduled cleanup tasks for activity logs
 * 
 * @since 1.0.0
 */
class ActivityCron
{
    /**
     * ActivityCron constructor.
     */
    public function __construct()
    {
        // Schedule cleanup if not already scheduled
        if (!wp_next_scheduled('uixpress_activity_log_cleanup')) {
            wp_schedule_event(time(), 'daily', 'uixpress_activity_log_cleanup');
        }
        
        add_action('uixpress_activity_log_cleanup', [$this, 'cleanup_old_logs']);
    }

    /**
     * Cleans up old activity logs based on retention policy
     * 
     * @return void
     * @since 1.0.0
     */
    public function cleanup_old_logs()
    {
        // Check if activity logger is enabled
        if (!ActivityDatabase::is_activity_logger_enabled()) {
            return;
        }

        // Check if auto cleanup is enabled
        $settings = get_option('uixpress_settings', []);
        if (!isset($settings['activity_log_auto_cleanup']) || !$settings['activity_log_auto_cleanup']) {
            return;
        }

        // Get retention period (default 90 days)
        $retention_days = isset($settings['activity_log_retention_days']) ? absint($settings['activity_log_retention_days']) : 90;

        global $wpdb;
        $table_name = $wpdb->prefix . 'uixpress_activity_log';

        // Check if table exists
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name))) {
            return;
        }

        // Calculate cutoff date
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        // Delete old logs
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE created_at < %s",
                $cutoff_date
            )
        );
    }

    /**
     * Manually triggers cleanup of old logs
     * 
     * @return int Number of deleted rows
     * @since 1.0.0
     */
    public static function manual_cleanup()
    {
        $settings = get_option('uixpress_settings', []);
        $retention_days = isset($settings['activity_log_retention_days']) ? absint($settings['activity_log_retention_days']) : 90;

        global $wpdb;
        $table_name = $wpdb->prefix . 'uixpress_activity_log';

        // Check if table exists
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name))) {
            return 0;
        }

        // Calculate cutoff date
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        // Delete old logs
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE created_at < %s",
                $cutoff_date
            )
        );

        return $deleted;
    }

    /**
     * Unschedules cron jobs
     * 
     * @return void
     * @since 1.0.0
     */
    public static function unschedule_cron_jobs()
    {
        $timestamp = wp_next_scheduled('uixpress_activity_log_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'uixpress_activity_log_cleanup');
        }

        $timestamp = wp_next_scheduled('uixpress_activity_log_flush');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'uixpress_activity_log_flush');
        }
    }
}

