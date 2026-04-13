<?php
namespace AM\Domain\Activity;

use AM\Infrastructure\DB\Schema;

if (!defined('ABSPATH')) { exit; }

final class Activity_Repository {
    public function insert(array $row): void {
        global $wpdb;
        $table = Schema::table_activity();
        $wpdb->insert($table, [
            'object_type' => $row['object_type'],
            'object_id' => (int)$row['object_id'],
            'action' => $row['action'],
            'actor_user_id' => $row['actor_user_id'] ? (int)$row['actor_user_id'] : null,
            'project_id' => $row['project_id'] ? (int)$row['project_id'] : null,
            'meta' => $row['meta'] ?? null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], [
            '%s','%d','%s','%d','%d','%s','%s'
        ]);
    }

    public function list_by_project(int $project_id, int $limit = 200): array {
        global $wpdb;
        $table = Schema::table_activity();
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE project_id = %d ORDER BY created_at DESC LIMIT %d",
            $project_id,
            $limit
        );
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }
}
