<?php
namespace AM\Domain\Activity;

if (!defined('ABSPATH')) { exit; }

final class Activity_Service {
    private Activity_Repository $repo;

    public function __construct(?Activity_Repository $repo = null) {
        $this->repo = $repo ?: new Activity_Repository();
    }

    public function log(string $object_type, int $object_id, string $action, ?int $actor_user_id, ?int $project_id, array $meta = []): void {
        $this->repo->insert([
            'object_type' => $object_type,
            'object_id' => $object_id,
            'action' => $action,
            'actor_user_id' => $actor_user_id,
            'project_id' => $project_id,
            'meta' => $meta ? wp_json_encode($meta) : null,
        ]);
    }

    public function list_by_project(int $project_id, int $limit = 200): array {
        $rows = $this->repo->list_by_project($project_id, $limit);
        foreach ($rows as &$r) {
            $r['meta'] = $r['meta'] ? json_decode($r['meta'], true) : null;
        }
        return $rows;
    }
}
