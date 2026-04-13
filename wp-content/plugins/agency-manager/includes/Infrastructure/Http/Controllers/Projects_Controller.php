<?php
namespace AM\Infrastructure\Http\Controllers;

use AM\Domain\Projects\Project_Service;
use AM\Support\Validator;

if (!defined('ABSPATH')) { exit; }

final class Projects_Controller {
    private Project_Service $service;

    public function __construct(?Project_Service $service = null) {
        $this->service = $service ?: new Project_Service();
    }

    public function register(): void {
        register_rest_route('am/v1', '/projects', [
            'methods' => 'GET',
            'callback' => [$this, 'list_projects'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);

        register_rest_route('am/v1', '/projects/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_project'],
            'permission_callback' => [$this, 'is_logged_in'],
            'args' => ['id' => ['required' => true]],
        ]);

        register_rest_route('am/v1', '/projects/(?P<id>\d+)/workflow', [
            'methods' => 'POST',
            'callback' => [$this, 'update_workflow'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);

        register_rest_route('am/v1', '/projects/(?P<id>\d+)/clients', [
            'methods' => 'POST',
            'callback' => [$this, 'update_clients'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);
    }

    public function is_logged_in(): bool {
        return is_user_logged_in();
    }

    public function list_projects(\WP_REST_Request $req) {
        try {
            return rest_ensure_response($this->service->list_for_current_user());
        } catch (\Throwable $e) {
            return Validator::error('am_error', $e->getMessage(), $e->getCode() ?: 500);
        }
    }

    public function get_project(\WP_REST_Request $req) {
        try {
            $id = (int) $req['id'];
            return rest_ensure_response($this->service->get($id));
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }

    public function update_workflow(\WP_REST_Request $req) {
        try {
            $id = (int) $req['id'];
            $workflow = (array) $req->get_json_params();
            return rest_ensure_response($this->service->update_workflow($id, $workflow));
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }

    public function update_clients(\WP_REST_Request $req) {
        try {
            $id = (int) $req['id'];
            $params = (array) $req->get_json_params();
            $clients = (array) ($params['clients'] ?? []);
            return rest_ensure_response($this->service->update_clients($id, $clients));
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }
}
