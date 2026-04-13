<?php
namespace AM\Domain\Dependencies;

use AM\Domain\Activity\Activity_Service;
use AM\Support\Permissions;

if (!defined('ABSPATH')) { exit; }

final class Dependency_Service {
    private Dependency_Repository $repo;
    private Activity_Service $activity;

    public function __construct(?Dependency_Repository $repo = null, ?Activity_Service $activity = null) {
        $this->repo = $repo ?: new Dependency_Repository();
        $this->activity = $activity ?: new Activity_Service();
    }

    public function list_by_project(int $project_id): array {
        $uid = Permissions::user_id();
        if (!Permissions::user_can_read_project($project_id, $uid)) {
            throw new \Exception('Forbidden', 403);
        }
        return $this->repo->list_by_project($project_id);
    }

    public function add(int $project_id, int $task_id, int $depends_on_task_id, string $type = 'fs'): int {
        $uid = Permissions::user_id();
        if (!Permissions::can_manage_tasks($uid) && !Permissions::can_manage_projects($uid)) {
            throw new \Exception('Forbidden', 403);
        }
        // Basic integrity checks.
        if ($task_id === $depends_on_task_id) {
            throw new \Exception('Task cannot depend on itself', 400);
        }
        if ((int)get_post_meta($task_id, '_am_project_id', true) !== $project_id) {
            throw new \Exception('Task not in project', 400);
        }
        if ((int)get_post_meta($depends_on_task_id, '_am_project_id', true) !== $project_id) {
            throw new \Exception('Dependency task not in project', 400);
        }
        $id = $this->repo->insert($project_id, $task_id, $depends_on_task_id, $type, $uid);
        $this->activity->log('dependency', $id, 'create', $uid, $project_id, [
            'task_id' => $task_id,
            'depends_on_task_id' => $depends_on_task_id,
            'type' => $type,
        ]);
        return $id;
    }

    public function remove(int $dependency_id, int $project_id): void {
        $uid = Permissions::user_id();
        if (!Permissions::can_manage_tasks($uid) && !Permissions::can_manage_projects($uid)) {
            throw new \Exception('Forbidden', 403);
        }
        $this->repo->delete($dependency_id);
        $this->activity->log('dependency', $dependency_id, 'delete', $uid, $project_id, []);
    }
}
