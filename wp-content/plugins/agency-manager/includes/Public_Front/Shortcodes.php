<?php
namespace AM\Public_Front;

use AM\Domain\Projects\Project_Service;
use AM\Domain\Tasks\Task_Service;
use AM\Domain\Comments\Comment_Service;
use AM\Domain\Workflow\Workflow_Service;
use AM\Support\Permissions;

if (!defined('ABSPATH')) { exit; }

final class Shortcodes {
    public static function register(): void {
        add_shortcode('am_portal', [self::class, 'render_portal']);
        add_shortcode('am_project', [self::class, 'render_project']);
        add_shortcode('am_task', [self::class, 'render_task']);
    }

    public static function enqueue_assets(): void {
        wp_register_style('am_portal', AM_PLUGIN_URL . 'public/portal.css', [], AM_VERSION);
        if (is_singular()) {
            // Always allow, minimal.
            wp_enqueue_style('am_portal');
        }
    }

    public static function render_portal($atts): string {
        if (!is_user_logged_in()) {
            return '<p>Please log in to access the client portal.</p>';
        }
        $svc = new Project_Service();
        $projects = $svc->list_for_current_user();

        $out = '<div class="am-portal">';
        $out .= '<h2>Projects</h2>';
        if (empty($projects)) {
            $out .= '<p>No projects found.</p></div>';
            return $out;
        }
        $out .= '<ul class="am-list">';
        foreach ($projects as $p) {
            $url = add_query_arg(['am_project' => $p['id']]);
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($p['title']) . '</a></li>';
        }
        $out .= '</ul>';

        // Optional: inline project view when query param present.
        if (!empty($_GET['am_project'])) {
            $out .= self::render_project(['id' => (int) $_GET['am_project']]);
        }
        if (!empty($_GET['am_task'])) {
            $out .= self::render_task(['id' => (int) $_GET['am_task']]);
        }
        $out .= '</div>';
        return $out;
    }

    public static function render_project($atts): string {
        if (!is_user_logged_in()) {
            return '';
        }
        $project_id = isset($atts['id']) ? (int) $atts['id'] : (int) ($_GET['am_project'] ?? 0);
        if ($project_id <= 0) return '';

        $uid = get_current_user_id();
        if (!Permissions::user_can_read_project($project_id, $uid)) {
            return '<div class="am-box"><p>Forbidden.</p></div>';
        }

        $pSvc = new Project_Service();
        $tSvc = new Task_Service();
        try {
            $project = $pSvc->get($project_id);
            $tasks = $tSvc->list_for_project($project_id);
        } catch (\Throwable $e) {
            return '<div class="am-box"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }

        $out = '<div class="am-box">';
        $out .= '<h3>' . esc_html($project['title']) . '</h3>';
        if (!empty($project['raw_description'])) {
            $out .= '<div class="am-content">' . $project['description'] . '</div>';
        }
        $out .= '<h4>Tasks</h4>';
        if (empty($tasks)) {
            $out .= '<p>No tasks.</p>';
        } else {
            $out .= '<table class="am-table"><thead><tr><th>Task</th><th>Status</th><th>Due</th></tr></thead><tbody>';
            foreach ($tasks as $t) {
                $url = add_query_arg(['am_task' => $t['id'], 'am_project' => $project_id]);
                $out .= '<tr>';
                $out .= '<td><a href="' . esc_url($url) . '">' . esc_html($t['title']) . '</a></td>';
                $out .= '<td>' . esc_html($t['status']) . '</td>';
                $out .= '<td>' . esc_html($t['due_date']) . '</td>';
                $out .= '</tr>';
            }
            $out .= '</tbody></table>';
        }
        $out .= '</div>';
        return $out;
    }

    public static function render_task($atts): string {
        if (!is_user_logged_in()) {
            return '';
        }
        $task_id = isset($atts['id']) ? (int) $atts['id'] : (int) ($_GET['am_task'] ?? 0);
        if ($task_id <= 0) return '';

        $uid = get_current_user_id();
        if (!Permissions::user_can_read_task($task_id, $uid)) {
            return '<div class="am-box"><p>Forbidden.</p></div>';
        }

        $tSvc = new Task_Service();
        $cSvc = new Comment_Service();
        $wSvc = new Workflow_Service();

        // Handle comment submission.
        if (!empty($_POST['am_action']) && $_POST['am_action'] === 'add_comment') {
            check_admin_referer('am_task_action_' . $task_id);
            $content = (string) ($_POST['comment'] ?? '');
            try { $cSvc->add($task_id, $content); } catch (\Throwable $e) { /* ignore */ }
        }

        // Handle status change.
        if (!empty($_POST['am_action']) && $_POST['am_action'] === 'change_status') {
            check_admin_referer('am_task_action_' . $task_id);
            $status = (string) ($_POST['status'] ?? '');
            try { $tSvc->change_status($task_id, $status); } catch (\Throwable $e) { /* ignore */ }
        }

        try {
            $task = $tSvc->get($task_id);
            $comments = $cSvc->list_for_task($task_id);
        } catch (\Throwable $e) {
            return '<div class="am-box"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }

        $project_id = (int) $task['project_id'];
        $workflow = $wSvc->get_project_workflow($project_id);

        // Determine allowed statuses for current user.
        $allowed = [];
        foreach ($workflow['statuses'] as $st) {
            if (!is_array($st)) continue;
            $key = (string) ($st['key'] ?? '');
            if ($key === '') continue;
            if (Permissions::can_manage_tasks($uid) || Permissions::can_manage_projects($uid)) {
                $allowed[] = $st;
            } else {
                if (Permissions::is_client($uid) && !empty($st['client_can_set'])) {
                    $allowed[] = $st;
                }
            }
        }

        $out = '<div class="am-box">';
        $out .= '<h3>' . esc_html($task['title']) . '</h3>';
        $out .= '<p><strong>Status:</strong> ' . esc_html($task['status']) . '</p>';
        if (!empty($task['raw_description'])) {
            $out .= '<div class="am-content">' . $task['description'] . '</div>';
        }

        // Status change form (only if there are allowed statuses).
        if (!empty($allowed)) {
            $out .= '<form method="post" class="am-form">';
            $out .= '<input type="hidden" name="am_action" value="change_status" />';
            $out .= '<label>Change status</label>';
            $out .= '<select name="status">';
            foreach ($allowed as $st) {
                $key = (string) $st['key'];
                $sel = selected($key, $task['status'], false);
                $out .= '<option value="' . esc_attr($key) . '" ' . $sel . '>' . esc_html((string) $st['name']) . '</option>';
            }
            $out .= '</select>';
            wp_nonce_field('am_task_action_' . $task_id);
            $out .= '<button type="submit">Update</button>';
            $out .= '</form>';
        }

        // Attachments: show links.
        if (!empty($task['attachments'])) {
            $out .= '<h4>Attachments</h4><ul class="am-list">';
            foreach ($task['attachments'] as $aid) {
                $url = wp_get_attachment_url($aid);
                if ($url) {
                    $out .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html(basename($url)) . '</a></li>';
                }
            }
            $out .= '</ul>';
        }

        // Upload form (simple).
        if (Permissions::user_can_upload_attachment($task_id, $uid)) {
            $out .= '<h4>Upload attachment</h4>';
            $out .= '<form method="post" enctype="multipart/form-data" class="am-form" action="' . esc_url(rest_url('am/v1/tasks/' . $task_id . '/attachments')) . '">';
            $out .= '<input type="file" name="file" required />';
            $out .= '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('wp_rest')) . '" />';
            $out .= '<button type="submit">Upload</button>';
            $out .= '<p class="am-note">Uploads go through a secure internal endpoint.</p>';
            $out .= '</form>';
        }

        // Comments.
        $out .= '<h4>Comments</h4>';
        if (empty($comments)) {
            $out .= '<p>No comments yet.</p>';
        } else {
            $out .= '<ul class="am-comments">';
            foreach ($comments as $c) {
                $out .= '<li><div class="am-comment-meta">' . esc_html($c['author']) . ' — ' . esc_html($c['created_at']) . '</div>';
                $out .= '<div class="am-comment-body">' . wp_kses_post($c['content']) . '</div></li>';
            }
            $out .= '</ul>';
        }

        if (Permissions::user_can_comment_task($task_id, $uid)) {
            $out .= '<form method="post" class="am-form">';
            $out .= '<input type="hidden" name="am_action" value="add_comment" />';
            $out .= '<textarea name="comment" rows="3" required></textarea>';
            wp_nonce_field('am_task_action_' . $task_id);
            $out .= '<button type="submit">Add comment</button>';
            $out .= '</form>';
        }

        $out .= '</div>';
        return $out;
    }
}
