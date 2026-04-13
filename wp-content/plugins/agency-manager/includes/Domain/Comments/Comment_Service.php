<?php
namespace AM\Domain\Comments;

use AM\Domain\Activity\Activity_Service;
use AM\Support\Permissions;

if (!defined('ABSPATH')) { exit; }

final class Comment_Service {
    private Activity_Service $activity;

    public function __construct(?Activity_Service $activity = null) {
        $this->activity = $activity ?: new Activity_Service();
    }

    public function list_for_task(int $task_id): array {
        $uid = Permissions::user_id();
        if (!Permissions::user_can_read_task($task_id, $uid)) {
            throw new \Exception('Forbidden', 403);
        }
        $comments = get_comments([
            'post_id' => $task_id,
            'status' => 'approve',
            'type' => 'am_task_comment',
            'orderby' => 'comment_date_gmt',
            'order' => 'ASC',
            'number' => 500,
        ]);
        $out = [];
        foreach ($comments as $c) {
            $out[] = [
                'id' => (int) $c->comment_ID,
                'user_id' => (int) $c->user_id,
                'author' => $c->comment_author,
                'content' => apply_filters('comment_text', $c->comment_content, $c),
                'raw_content' => $c->comment_content,
                'created_at' => $c->comment_date_gmt,
            ];
        }
        return $out;
    }

    public function add(int $task_id, string $content): array {
        $uid = Permissions::user_id();
        if (!Permissions::user_can_comment_task($task_id, $uid)) {
            throw new \Exception('Forbidden', 403);
        }
        $content = trim(wp_strip_all_tags($content, false));
        if ($content === '') {
            throw new \Exception('Empty comment', 400);
        }
        $comment_id = wp_insert_comment([
            'comment_post_ID' => $task_id,
            'comment_content' => $content,
            'user_id' => $uid,
            'comment_author' => wp_get_current_user()->display_name,
            'comment_author_email' => wp_get_current_user()->user_email,
            'comment_approved' => 1,
            'comment_type' => 'am_task_comment',
        ]);
        if (!$comment_id || is_wp_error($comment_id)) {
            throw new \Exception('Failed to add comment', 500);
        }
        $project_id = (int) get_post_meta($task_id, '_am_project_id', true);
        $this->activity->log('task', $task_id, 'comment_add', $uid, $project_id, [
            'comment_id' => (int) $comment_id,
        ]);
        return $this->list_for_task($task_id);
    }
}
