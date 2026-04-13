<?php
namespace AM\Infrastructure\DB;

if (!defined('ABSPATH')) { exit; }

final class Migrator {
    public static function activate(): void {
        self::migrate();
        // Flush rewrite rules for CPTs.
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules();
        }
    }

    public static function deactivate(): void {
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules();
        }
    }

    public static function migrate(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $dep = Schema::table_dependencies();
        $act = Schema::table_activity();

        $sql1 = "CREATE TABLE {$dep} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT(20) UNSIGNED NOT NULL,
            task_id BIGINT(20) UNSIGNED NOT NULL,
            depends_on_task_id BIGINT(20) UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'fs',
            created_by BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY project_task (project_id, task_id),
            KEY task_id (task_id),
            KEY depends_on_task_id (depends_on_task_id)
        ) {$charset_collate};";

        $sql2 = "CREATE TABLE {$act} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(30) NOT NULL,
            object_id BIGINT(20) UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            actor_user_id BIGINT(20) UNSIGNED NULL,
            project_id BIGINT(20) UNSIGNED NULL,
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY project_created (project_id, created_at),
            KEY object_lookup (object_type, object_id),
            KEY actor_created (actor_user_id, created_at)
        ) {$charset_collate};";

        dbDelta($sql1);
        dbDelta($sql2);

        update_option('am_db_version', Schema::target_db_version());
    }
}
