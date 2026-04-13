<?php
namespace AM\Domain\Tasks;

use AM\Domain\Activity\Activity_Service;
use AM\Domain\Workflow\Workflow_Service;
use AM\Support\Permissions;
use AM\Support\Validator;

if (!defined('ABSPATH')) { exit; }

final class Task_Service {
    private Task_Repository $repo;
    private Activity_Service $activity;
    private Workflow_Service $workflow;

    public function __construct(?Task_Repository $repo = null, ?Activity_Service $activity = null, ?Workflow_Service $workflow = null) {
        $this->repo = $repo ?: new Task_Repository();
        $this->activity = $activity ?: new Activity_Service();
        $this->workflow = $workflow ?: new Workflow_Service();
    }

    public function list_for_project(int $project_id): array {
        $uid = Permissions::user_id();
        if (!Permissions::user_can_read_project($project_id, $uid)) {
            throw new \Exception('Forbidden', 403);
        }
        $tasks = $this->repo->list_by_project($project_id);
        $out = [];
        foreach ($tasks as $t) {
            if (Permissions::is_client($uid)) {
                $client_visible = (bool) get_post_meta($t->ID, '_am_client_visible', true);
                if (!$client_visible) {
                    continue;
                }
            }
            $out[] = $this->serialize($t);
        }
        return $out;
    }

    public function get(int $task_id): array {
        $uid = Permissions::user_id();
        if (!Permissions::user_can_read_task($task_id, $uid)) {
            throw new \Exception('Forbidden', 403);
        }
        $t = $this->repo->get($task_id);
        if (!$t) {
            throw new \Exception('Not Found', 404);
        }
        return $this->serialize($t);
    }

    public function change_status(int $task_id, string $new_status_key): array {
        $uid = Permissions::user_id();
        $new_status_key = Validator::sanitize_status_key($new_status_key);

        if (!Permissions::user_can_change_task_status($task_id, $new_status_key, $uid)) {
            throw new \Exception('Forbidden', 403);
        }

        $project_id = (int) get_post_meta($task_id, '_am_project_id', true);
        if (!$this->workflow->status_exists($project_id, $new_status_key)) {
            throw new \Exception('Invalid status', 400);
        }

        $old = (string) get_post_meta($task_id, '_am_status_key', true);
        $this->repo->update_meta($task_id, ['_am_status_key' => $new_status_key]);

        $this->activity->log('task', $task_id, 'status_change', $uid, $project_id, [
            'old' => $old,
            'new' => $new_status_key,
            'by_client' => Permissions::is_client($uid),
        ]);

        return $this->get($task_id);
    }

    public function add_attachment(int $task_id, int $attachment_id): array {
        $uid = Permissions::user_id();
        if (!Permissions::user_can_upload_attachment($task_id, $uid)) {
            throw new \Exception('Forbidden', 403);
        }
        $project_id = (int) get_post_meta($task_id, '_am_project_id', true);
        $ids = (array) get_post_meta($task_id, '_am_attachment_ids', true);
        $ids = array_map('intval', $ids);
        if (!in_array($attachment_id, $ids, true)) {
            $ids[] = $attachment_id;
        }
        update_post_meta($task_id, '_am_attachment_ids', array_values(array_unique($ids)));

        $this->activity->log('task', $task_id, 'attachment_add', $uid, $project_id, [
            'attachment_id' => $attachment_id,
        ]);

        return $this->get($task_id);
    }

    private function serialize(\WP_Post $t): array {
        $task_id = (int) $t->ID;
        $project_id = (int) get_post_meta($task_id, '_am_project_id', true);
        $labels = wp_get_post_terms($task_id, 'agency_label', ['fields' => 'names']);
        $attachment_ids = array_map('intval', (array) get_post_meta($task_id, '_am_attachment_ids', true));
        $status_key = (string) get_post_meta($task_id, '_am_status_key', true);

        return [
            'id' => $task_id,
            'project_id' => $project_id,
            'title' => get_the_title($t),
            'description' => apply_filters('the_content', $t->post_content),
            'raw_description' => $t->post_content,
            'assignee' => (int) get_post_meta($task_id, '_am_assignee', true),
            'start_date' => (string) get_post_meta($task_id, '_am_start_date', true),
            'due_date' => (string) get_post_meta($task_id, '_am_due_date', true),
            'status' => $status_key,
            'priority' => (string) get_post_meta($task_id, '_am_priority_key', true),
            'labels' => is_array($labels) ? $labels : [],
            'estimate_hours' => (float) get_post_meta($task_id, '_am_estimate_hours', true),
            'time_logged' => 0.0, // future-proof
            'client_visible' => (bool) get_post_meta($task_id, '_am_client_visible', true),
            'attachments' => $attachment_ids,
            'comment_count' => (int) get_comments_number($task_id),
        ];
    }
}
