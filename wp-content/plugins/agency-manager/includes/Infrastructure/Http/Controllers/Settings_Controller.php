<?php
namespace AM\Infrastructure\Http\Controllers;

use AM\Support\Validator;

if (!defined('ABSPATH')) { exit; }

final class Settings_Controller {
    public function register(): void {
        register_rest_route('am/v1', '/settings', [
            'methods' => 'GET',
            'callback' => [$this, 'get_settings'],
            'permission_callback' => [$this, 'can_manage'],
        ]);

        register_rest_route('am/v1', '/settings', [
            'methods' => 'POST',
            'callback' => [$this, 'update_settings'],
            'permission_callback' => [$this, 'can_manage'],
        ]);
    }

    public function can_manage(): bool {
        return is_user_logged_in() && current_user_can('am_manage_settings');
    }

    public function get_settings() {
        $defaults = self::defaults();
        $settings = get_option('am_settings', []);
        if (!is_array($settings)) $settings = [];
        $settings = array_merge($defaults, $settings);
        return rest_ensure_response($settings);
    }

    public function update_settings(\WP_REST_Request $req) {
        try {
            $params = (array) $req->get_json_params();
            $allowed = ['priorities', 'task_types', 'status_templates', 'project_templates'];
            $settings = get_option('am_settings', []);
            if (!is_array($settings)) $settings = [];
            foreach ($allowed as $k) {
                if (array_key_exists($k, $params)) {
                    $settings[$k] = $params[$k];
                }
            }
            update_option('am_settings', $settings);
            return $this->get_settings();
        } catch (\Throwable $e) {
            return Validator::error('am_error', $e->getMessage(), 500);
        }
    }

    public static function defaults(): array {
        return [
            'priorities' => [
                ['key' => 'low', 'name' => 'Low', 'order' => 10],
                ['key' => 'normal', 'name' => 'Normal', 'order' => 20],
                ['key' => 'high', 'name' => 'High', 'order' => 30],
                ['key' => 'urgent', 'name' => 'Urgent', 'order' => 40],
            ],
            'task_types' => [
                ['key' => 'task', 'name' => 'Task', 'order' => 10],
                ['key' => 'bug', 'name' => 'Bug', 'order' => 20],
                ['key' => 'feature', 'name' => 'Feature', 'order' => 30],
            ],
            'status_templates' => [],
            'project_templates' => [],
        ];
    }
}
