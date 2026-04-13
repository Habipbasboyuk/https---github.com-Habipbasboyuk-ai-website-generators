<?php
namespace AM\Domain\Workflow;

use AM\Support\Validator;

if (!defined('ABSPATH')) { exit; }

final class Workflow_Service {
    public function get_project_workflow(int $project_id): array {
        $workflow = get_post_meta($project_id, '_am_workflow', true);
        if (is_array($workflow) && Validator::workflow_is_valid($workflow)) {
            return $workflow;
        }
        // Default workflow if none set.
        return [
            'version' => 1,
            'statuses' => [
                ['key' => 'todo', 'name' => 'To do', 'color' => '#999999', 'order' => 10, 'client_can_set' => false],
                ['key' => 'doing', 'name' => 'Doing', 'color' => '#22cc88', 'order' => 20, 'client_can_set' => false],
                ['key' => 'review', 'name' => 'Review', 'color' => '#ff9900', 'order' => 30, 'client_can_set' => true],
                ['key' => 'done', 'name' => 'Done', 'color' => '#0099ff', 'order' => 40, 'client_can_set' => true],
            ],
        ];
    }

    public function status_exists(int $project_id, string $status_key): bool {
        $workflow = $this->get_project_workflow($project_id);
        foreach ($workflow['statuses'] as $st) {
            if (is_array($st) && ($st['key'] ?? '') === $status_key) return true;
        }
        return false;
    }
}
