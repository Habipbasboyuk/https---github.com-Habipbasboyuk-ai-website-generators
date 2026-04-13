<?php
namespace AM\Infrastructure\Capabilities;

if (!defined('ABSPATH')) { exit; }

final class Roles {
    public static function ensure_roles(): void {
        // Create roles if missing, add capabilities.
        self::ensure_role('project_manager', __('Project Manager', 'agency-manager'), [
            'read' => true,
            'am_manage_projects' => true,
            'am_manage_tasks' => true,
            'am_read_all_projects' => true,
            'am_view_activity_log' => true,
        ]);

        self::ensure_role('team_member', __('Team Member', 'agency-manager'), [
            'read' => true,
            'am_manage_tasks' => true,
        ]);

        self::ensure_role('client', __('Client', 'agency-manager'), [
            'read' => true,
            'am_read_project' => true,
            'am_read_task' => true,
            'am_comment_task' => true,
            'am_upload_task_attachments' => true,
            'am_change_task_status_client' => true,
        ]);

        // Administrator capabilities.
        $admin = get_role('administrator');
        if ($admin) {
            $caps = [
                'am_manage_settings',
                'am_manage_projects',
                'am_manage_tasks',
                'am_read_all_projects',
                'am_read_project',
                'am_read_task',
                'am_comment_task',
                'am_upload_task_attachments',
                'am_change_task_status_client',
                'am_view_activity_log',
            ];
            foreach ($caps as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    private static function ensure_role(string $key, string $label, array $caps): void {
        $role = get_role($key);
        if (!$role) {
            add_role($key, $label, $caps);
            return;
        }
        foreach ($caps as $cap => $grant) {
            if ($grant) {
                $role->add_cap($cap);
            } else {
                $role->remove_cap($cap);
            }
        }
    }
}
