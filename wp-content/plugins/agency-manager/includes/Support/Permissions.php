<?php
namespace AM\Support;

if (!defined('ABSPATH')) { exit; }

final class Permissions {
    public static function user_id(): int {
        return get_current_user_id();
    }

    public static function is_client(int $user_id = 0): bool {
        $user_id = $user_id ?: self::user_id();
        $user = get_user_by('id', $user_id);
        if (!$user) return false;
        return in_array('client', (array) $user->roles, true);
    }

    public static function can_manage_projects(int $user_id = 0): bool {
        $user_id = $user_id ?: self::user_id();
        return user_can($user_id, 'am_manage_projects');
    }

    public static function can_manage_tasks(int $user_id = 0): bool {
        $user_id = $user_id ?: self::user_id();
        return user_can($user_id, 'am_manage_tasks');
    }

    public static function can_view_activity(int $user_id = 0): bool {
        $user_id = $user_id ?: self::user_id();
        return user_can($user_id, 'am_view_activity_log');
    }

    /**
     * A client can see a project if their user ID is linked to the project.
     * Admin/PM can see everything.
     */
    public static function user_can_read_project(int $project_id, int $user_id = 0): bool {
        $user_id = $user_id ?: self::user_id();
        if (user_can($user_id, 'am_read_all_projects') || self::can_manage_projects($user_id)) {
            return true;
        }
        if (!user_can($user_id, 'am_read_project')) {
            return false;
        }
        $clients = (array) get_post_meta($project_id, '_am_clients', true);
        $clients = array_map('intval', $clients);
        return in_array((int)$user_id, $clients, true);
    }

    public static function user_can_read_task(int $task_id, int $user_id = 0): bool {
        $user_id = $user_id ?: self::user_id();
        $task = get_post($task_id);
        if (!$task || $task->post_type !== 'agency_task') {
            return false;
        }
        $project_id = (int) get_post_meta($task_id, '_am_project_id', true);
        if (!$project_id || !self::user_can_read_project($project_id, $user_id)) {
            return false;
        }
        // Client visibility.
        if (self::is_client($user_id)) {
            $client_visible = (bool) get_post_meta($task_id, '_am_client_visible', true);
            if (!$client_visible) {
                return false;
            }
        }
        return user_can($user_id, 'am_read_task') || self::can_manage_tasks($user_id) || self::can_manage_projects($user_id);
    }

    public static function user_can_comment_task(int $task_id, int $user_id = 0): bool {
        $user_id = $user_id ?: self::user_id();
        if (!self::user_can_read_task($task_id, $user_id)) return false;
        return user_can($user_id, 'am_comment_task') || self::can_manage_tasks($user_id) || self::can_manage_projects($user_id);
    }

    public static function user_can_upload_attachment(int $task_id, int $user_id = 0): bool {
        $user_id = $user_id ?: self::user_id();
        if (!self::user_can_read_task($task_id, $user_id)) return false;
        return user_can($user_id, 'am_upload_task_attachments') || self::can_manage_tasks($user_id) || self::can_manage_projects($user_id);
    }

    public static function user_can_change_task_status(int $task_id, string $new_status_key, int $user_id = 0): bool {
        $user_id = $user_id ?: self::user_id();
        if (!self::user_can_read_task($task_id, $user_id)) return false;

        // Admin/PM/Team can always set any valid status (service validates status exists).
        if (self::can_manage_tasks($user_id) || self::can_manage_projects($user_id)) {
            return true;
        }

        // Client can only set statuses allowed by project workflow.
        if (!user_can($user_id, 'am_change_task_status_client')) {
            return false;
        }

        if (!self::is_client($user_id)) {
            return false;
        }

        $project_id = (int) get_post_meta($task_id, '_am_project_id', true);
        $workflow = get_post_meta($project_id, '_am_workflow', true);
        $statuses = is_array($workflow) && isset($workflow['statuses']) ? (array) $workflow['statuses'] : [];
        foreach ($statuses as $status) {
            if (!is_array($status)) continue;
            if (($status['key'] ?? '') === $new_status_key) {
                return (bool) ($status['client_can_set'] ?? false);
            }
        }
        return false;
    }
}
