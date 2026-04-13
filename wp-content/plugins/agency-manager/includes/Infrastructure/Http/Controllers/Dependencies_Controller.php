<?php
namespace AM\Infrastructure\Http\Controllers;

use AM\Domain\Dependencies\Dependency_Service;
use AM\Support\Validator;

if (!defined('ABSPATH')) { exit; }

final class Dependencies_Controller {
    private Dependency_Service $deps;

    public function __construct(?Dependency_Service $deps = null) {
        $this->deps = $deps ?: new Dependency_Service();
    }

    public function register(): void {
        register_rest_route('am/v1', '/projects/(?P<id>\d+)/dependencies', [
            'methods' => 'GET',
            'callback' => [$this, 'list'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);

        register_rest_route('am/v1', '/projects/(?P<id>\d+)/dependencies', [
            'methods' => 'POST',
            'callback' => [$this, 'add'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);

        register_rest_route('am/v1', '/projects/(?P<id>\d+)/dependencies/(?P<dep_id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'remove'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);
    }

    public function is_logged_in(): bool { return is_user_logged_in(); }

    public function list(\WP_REST_Request $req) {
        try {
            $project_id = (int) $req['id'];
            return rest_ensure_response($this->deps->list_by_project($project_id));
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }

    public function add(\WP_REST_Request $req) {
        try {
            $project_id = (int) $req['id'];
            $params = (array) $req->get_json_params();
            $task_id = (int) ($params['task_id'] ?? 0);
            $depends_on_task_id = (int) ($params['depends_on_task_id'] ?? 0);
            $type = (string) ($params['type'] ?? 'fs');
            $dep_id = $this->deps->add($project_id, $task_id, $depends_on_task_id, $type);
            return rest_ensure_response(['id' => $dep_id]);
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }

    public function remove(\WP_REST_Request $req) {
        try {
            $project_id = (int) $req['id'];
            $dep_id = (int) $req['dep_id'];
            $this->deps->remove($dep_id, $project_id);
            return rest_ensure_response(['deleted' => true]);
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }
}
