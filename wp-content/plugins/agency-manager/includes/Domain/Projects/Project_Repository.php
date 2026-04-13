<?php
namespace AM\Domain\Projects;

if (!defined('ABSPATH')) { exit; }

final class Project_Repository {
    public function get(int $project_id): ?\WP_Post {
        $p = get_post($project_id);
        if (!$p || $p->post_type !== 'agency_project') return null;
        return $p;
    }

    /** @return \WP_Post[] */
    public function list_all(int $per_page = 100): array {
        $q = new \WP_Query([
            'post_type' => 'agency_project',
            'post_status' => ['publish','private','draft'],
            'posts_per_page' => $per_page,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);
        return $q->posts;
    }

    /** @return \WP_Post[] */
    public function list_for_client(int $client_user_id, int $per_page = 100): array {
        // Projects store linked client IDs in _am_clients.
        $q = new \WP_Query([
            'post_type' => 'agency_project',
            'post_status' => ['publish','private','draft'],
            'posts_per_page' => $per_page,
            'meta_query' => [
                [
                    'key' => '_am_clients',
                    'value' => '"' . (int)$client_user_id . '"',
                    'compare' => 'LIKE',
                ]
            ],
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);
        return $q->posts;
    }

    public function save_meta(int $project_id, array $meta): void {
        foreach ($meta as $k => $v) {
            update_post_meta($project_id, $k, $v);
        }
    }
}
