<?php
namespace AM\Domain\Projects;

use AM\Domain\Activity\Activity_Service;
use AM\Support\Permissions;
use AM\Support\Validator;

if (!defined('ABSPATH')) { exit; }

final class Project_Service {
    private Project_Repository $repo;
    private Activity_Service $activity;

    public function __construct(?Project_Repository $repo = null, ?Activity_Service $activity = null) {
        $this->repo = $repo ?: new Project_Repository();
        $this->activity = $activity ?: new Activity_Service();
    }

    public function list_for_current_user(): array {
        $uid = Permissions::user_id();
        if (user_can($uid, 'am_read_all_projects') || Permissions::can_manage_projects($uid)) {
            $projects = $this->repo->list_all();
        } else {
            $projects = $this->repo->list_for_client($uid);
        }
        return array_map([$this, 'serialize'], $projects);
    }

    public function get(int $project_id): array {
        $uid = Permissions::user_id();
        if (!Permissions::user_can_read_project($project_id, $uid)) {
            throw new \Exception('Forbidden', 403);
        }
        $p = $this->repo->get($project_id);
        if (!$p) {
            throw new \Exception('Not Found', 404);
        }
        return $this->serialize($p);
    }

    public function update_workflow(int $project_id, array $workflow): array {
        $uid = Permissions::user_id();
        if (!Permissions::can_manage_projects($uid)) {
            throw new \Exception('Forbidden', 403);
        }
        if (!Validator::workflow_is_valid($workflow)) {
            throw new \Exception('Invalid workflow', 400);
        }
        $old = get_post_meta($project_id, '_am_workflow', true);
        update_post_meta($project_id, '_am_workflow', $workflow);
        $this->activity->log('project', $project_id, 'workflow_update', $uid, $project_id, [
            'old' => $old,
            'new' => $workflow,
        ]);
        return $this->get($project_id);
    }

    public function update_clients(int $project_id, array $client_user_ids): array {
        $uid = Permissions::user_id();
        if (!Permissions::can_manage_projects($uid)) {
            throw new \Exception('Forbidden', 403);
        }
        $client_user_ids = array_values(array_unique(array_filter(array_map('intval', $client_user_ids))));
        $old = (array) get_post_meta($project_id, '_am_clients', true);
        update_post_meta($project_id, '_am_clients', $client_user_ids);
        $this->activity->log('project', $project_id, 'clients_update', $uid, $project_id, [
            'old' => $old,
            'new' => $client_user_ids,
        ]);
        return $this->get($project_id);
    }

    private function serialize(\WP_Post $p): array {
        $workflow = get_post_meta($p->ID, '_am_workflow', true);
        $clients = (array) get_post_meta($p->ID, '_am_clients', true);
        return [
            'id' => (int) $p->ID,
            'title' => get_the_title($p),
            'description' => apply_filters('the_content', $p->post_content),
            'raw_description' => $p->post_content,
            'status' => $p->post_status,
            'created_at' => $p->post_date_gmt,
            'clients' => array_map('intval', $clients),
            'workflow' => is_array($workflow) ? $workflow : null,
        ];
    }
}
