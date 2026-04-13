<?php
namespace AM\Infrastructure\DB;

if (!defined('ABSPATH')) { exit; }

final class Schema {
    public static function table_dependencies(): string {
        global $wpdb;
        return $wpdb->prefix . 'am_task_dependencies';
    }

    public static function table_activity(): string {
        global $wpdb;
        return $wpdb->prefix . 'am_activity_log';
    }

    public static function current_db_version(): string {
        return (string) get_option('am_db_version', '0');
    }

    public static function target_db_version(): string {
        return '1';
    }
}
