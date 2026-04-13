<?php
namespace AM\Domain\Dependencies;

use AM\Infrastructure\DB\Schema;

if (!defined('ABSPATH')) { exit; }

final class Dependency_Repository {
    public function list_by_project(int $project_id): array {
        global $wpdb;
        $table = Schema::table_dependencies();
        $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE project_id = %d", $project_id);
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    public function insert(int $project_id, int $task_id, int $depends_on_task_id, string $type, int $created_by): int {
        global $wpdb;
        $table = Schema::table_dependencies();
        $wpdb->insert($table, [
            'project_id' => $project_id,
            'task_id' => $task_id,
            'depends_on_task_id' => $depends_on_task_id,
            'type' => $type,
            'created_by' => $created_by,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], ['%d','%d','%d','%s','%d','%s']);
        return (int) $wpdb->insert_id;
    }

    public function delete(int $id): void {
        global $wpdb;
        $table = Schema::table_dependencies();
        $wpdb->delete($table, ['id' => $id], ['%d']);
    }
}
