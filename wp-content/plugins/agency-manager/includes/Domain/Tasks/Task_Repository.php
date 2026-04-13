<?php
namespace AM\Domain\Tasks;

if (!defined('ABSPATH')) { exit; }

final class Task_Repository {
    public function get(int $task_id): ?\WP_Post {
        $t = get_post($task_id);
        if (!$t || $t->post_type !== 'agency_task') return null;
        return $t;
    }

    /** @return \WP_Post[] */
    public function list_by_project(int $project_id, int $per_page = 500): array {
        $q = new \WP_Query([
            'post_type' => 'agency_task',
            'post_status' => ['publish','private','draft'],
            'posts_per_page' => $per_page,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => '_am_project_id',
                    'value' => (int)$project_id,
                    'compare' => '=',
                ]
            ],
        ]);
        return $q->posts;
    }

    public function update_meta(int $task_id, array $meta): void {
        foreach ($meta as $k => $v) {
            update_post_meta($task_id, $k, $v);
        }
    }
}
