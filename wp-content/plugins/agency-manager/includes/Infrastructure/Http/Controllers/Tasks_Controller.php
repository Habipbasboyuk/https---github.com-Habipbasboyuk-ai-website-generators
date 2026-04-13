<?php
namespace AM\Infrastructure\Http\Controllers;

use AM\Domain\Tasks\Task_Service;
use AM\Domain\Comments\Comment_Service;
use AM\Support\Validator;

if (!defined('ABSPATH')) { exit; }

final class Tasks_Controller {
    private Task_Service $tasks;
    private Comment_Service $comments;

    public function __construct(?Task_Service $tasks = null, ?Comment_Service $comments = null) {
        $this->tasks = $tasks ?: new Task_Service();
        $this->comments = $comments ?: new Comment_Service();
    }

    public function register(): void {
        register_rest_route('am/v1', '/projects/(?P<id>\d+)/tasks', [
            'methods' => 'GET',
            'callback' => [$this, 'list_project_tasks'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);

        register_rest_route('am/v1', '/tasks/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_task'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);

        register_rest_route('am/v1', '/tasks/(?P<id>\d+)/status', [
            'methods' => 'POST',
            'callback' => [$this, 'change_status'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);

        register_rest_route('am/v1', '/tasks/(?P<id>\d+)/comments', [
            'methods' => 'GET',
            'callback' => [$this, 'list_comments'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);

        register_rest_route('am/v1', '/tasks/(?P<id>\d+)/comments', [
            'methods' => 'POST',
            'callback' => [$this, 'add_comment'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);

        register_rest_route('am/v1', '/tasks/(?P<id>\d+)/attachments', [
            'methods' => 'POST',
            'callback' => [$this, 'upload_attachment'],
            'permission_callback' => [$this, 'is_logged_in'],
        ]);
    }

    public function is_logged_in(): bool {
        return is_user_logged_in();
    }

    public function list_project_tasks(\WP_REST_Request $req) {
        try {
            $project_id = (int) $req['id'];
            return rest_ensure_response($this->tasks->list_for_project($project_id));
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }

    public function get_task(\WP_REST_Request $req) {
        try {
            $id = (int) $req['id'];
            return rest_ensure_response($this->tasks->get($id));
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }

    public function change_status(\WP_REST_Request $req) {
        try {
            $id = (int) $req['id'];
            $params = (array) $req->get_json_params();
            $status = (string) ($params['status'] ?? '');
            return rest_ensure_response($this->tasks->change_status($id, $status));
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }

    public function list_comments(\WP_REST_Request $req) {
        try {
            $id = (int) $req['id'];
            return rest_ensure_response($this->comments->list_for_task($id));
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }

    public function add_comment(\WP_REST_Request $req) {
        try {
            $id = (int) $req['id'];
            $params = (array) $req->get_json_params();
            $content = (string) ($params['content'] ?? '');
            return rest_ensure_response($this->comments->add($id, $content));
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }

    public function upload_attachment(\WP_REST_Request $req) {
        try {
            $task_id = (int) $req['id'];
            // Expect multipart/form-data with file field "file".
            if (empty($_FILES['file'])) {
                throw new \Exception('No file uploaded (expected field: file)', 400);
            }

            // Validate permissions at service level by calling add_attachment after upload.
            // We need to ensure the user can upload attachments to this task before we store the file.
            // So we call Task_Service::get (will enforce read permission) and then validate upload permission.
            // If permission fails, Task_Service methods will throw.
            // We do not write anything to disk until after the permission check.
            // Unfortunately PHP already buffered the upload, but we can still stop before inserting attachment.
            // We'll do an explicit check.
            $uid = get_current_user_id();
            if (!\AM\Support\Permissions::user_can_upload_attachment($task_id, $uid)) {
                throw new \Exception('Forbidden', 403);
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $file = $_FILES['file'];
            $overrides = ['test_form' => false];
            $movefile = wp_handle_upload($file, $overrides);
            if (!is_array($movefile) || isset($movefile['error'])) {
                throw new \Exception($movefile['error'] ?? 'Upload failed', 500);
            }

            $filename = $movefile['file'];
            $filetype = wp_check_filetype(basename($filename), null);

            $attachment = [
                'post_mime_type' => $filetype['type'],
                'post_title' => sanitize_file_name(basename($filename)),
                'post_content' => '',
                'post_status' => 'inherit',
            ];

            $attach_id = wp_insert_attachment($attachment, $filename, $task_id);
            if (is_wp_error($attach_id) || !$attach_id) {
                throw new \Exception('Failed to create attachment', 500);
            }

            $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
            wp_update_attachment_metadata($attach_id, $attach_data);

            return rest_ensure_response($this->tasks->add_attachment($task_id, (int)$attach_id));
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if ($status < 400 || $status > 599) $status = 500;
            return Validator::error('am_error', $e->getMessage(), $status);
        }
    }
}
