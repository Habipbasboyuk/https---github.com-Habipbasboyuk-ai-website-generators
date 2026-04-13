<?php
namespace AM\Infrastructure\Http\Controllers;

use AM\Domain\Activity\Activity_Service;
use AM\Support\Permissions;
use AM\Support\Validator;

if (!defined('ABSPATH')) { exit; }

final class Activity_Controller {
    private Activity_Service $activity;

    public function __construct(?Activity_Service $activity = null) {
        $this->activity = $activity ?: new Activity_Service();
    }

    public function register(): void {
        register_rest_route('am/v1', '/projects/(?P<id>\d+)/activity', [
            'methods' => 'GET',
            'callback' => [$this, 'list'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);
    }

    public function is_logged_in(): bool { return is_user_logged_in(); }

    public function list(\WP_REST_Request $req) {
        try {
            $project_id = (int) $req['id'];
            $uid = get_current_user_id();
            if (!Permissions::user_can_read_project($project_id, $uid) || !Permissions::can_view_activity($uid)) {
                throw new \Exception('Forbidden', 403);
            }
            return rest_ensure_response($this->activity->list_by_project($project_id));
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }
}
