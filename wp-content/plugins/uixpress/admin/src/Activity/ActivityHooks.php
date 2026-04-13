<?php

namespace UiXpress\Activity;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class ActivityHooks
 * 
 * Hooks into WordPress actions to log activities
 * 
 * @since 1.0.0
 */
class ActivityHooks
{
    /**
     * ActivityHooks constructor.
     */
    public function __construct()
    {
        // Only hook if activity logger is enabled
        if (!ActivityDatabase::is_activity_logger_enabled()) {
            return;
        }

        // Post actions
        add_action('wp_insert_post', [$this, 'log_post_created'], 10, 3);
        add_action('post_updated', [$this, 'log_post_updated'], 10, 3);
        add_action('before_delete_post', [$this, 'log_post_deleted'], 10, 1);
        add_action('trashed_post', [$this, 'log_post_trashed'], 10, 1);
        add_action('untrashed_post', [$this, 'log_post_restored'], 10, 1);

        // User actions
        add_action('user_register', [$this, 'log_user_created'], 10, 1);
        add_action('profile_update', [$this, 'log_user_updated'], 10, 2);
        add_action('delete_user', [$this, 'log_user_deleted'], 10, 1);
        add_action('set_user_role', [$this, 'log_user_role_changed'], 10, 3);

        // Comment actions
        add_action('wp_insert_comment', [$this, 'log_comment_created'], 10, 2);
        add_action('edit_comment', [$this, 'log_comment_updated'], 10, 2);
        add_action('delete_comment', [$this, 'log_comment_deleted'], 10, 2);
        add_action('wp_set_comment_status', [$this, 'log_comment_status_changed'], 10, 2);

        // Plugin actions
        add_action('activated_plugin', [$this, 'log_plugin_activated'], 10, 2);
        add_action('deactivated_plugin', [$this, 'log_plugin_deactivated'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'log_plugin_installed'], 10, 2);
        add_action('delete_plugin', [$this, 'log_plugin_deleted'], 10, 1);

        // Settings actions
        add_action('updated_option', [$this, 'log_option_updated'], 10, 3);

        // Media actions
        add_action('add_attachment', [$this, 'log_media_uploaded'], 10, 1);
        add_action('delete_attachment', [$this, 'log_media_deleted'], 10, 1);

        // Login/logout
        add_action('wp_login', [$this, 'log_user_login'], 10, 2);
        add_action('wp_logout', [$this, 'log_user_logout'], 10, 1);
    }

    /**
     * Logs post creation
     * 
     * @param int $post_id Post ID
     * @param \WP_Post $post Post object
     * @param bool $update Whether this is an update
     * @return void
     * @since 1.0.0
     */
    public function log_post_created($post_id, $post, $update)
    {
        if ($update || $post->post_status === 'auto-draft' || $post->post_type === 'revision') {
            return;
        }

        ActivityLogger::log(
            'created',
            $post->post_type,
            $post_id,
            null,
            [
                'title' => $post->post_title,
                'status' => $post->post_status,
            ],
            [
                'post_type' => $post->post_type,
            ]
        );
    }

    /**
     * Logs post update
     * 
     * @param int $post_id Post ID
     * @param \WP_Post $post_after Post object after update
     * @param \WP_Post $post_before Post object before update
     * @return void
     * @since 1.0.0
     */
    public function log_post_updated($post_id, $post_after, $post_before)
    {
        if ($post_after->post_status === 'auto-draft' || $post_after->post_type === 'revision') {
            return;
        }

        $old_value = [];
        $new_value = [];

        // Track changed fields
        if ($post_before->post_title !== $post_after->post_title) {
            $old_value['title'] = $post_before->post_title;
            $new_value['title'] = $post_after->post_title;
        }

        if ($post_before->post_status !== $post_after->post_status) {
            $old_value['status'] = $post_before->post_status;
            $new_value['status'] = $post_after->post_status;
        }

        if ($post_before->post_content !== $post_after->post_content) {
            $old_value['content'] = substr($post_before->post_content, 0, 100) . '...';
            $new_value['content'] = substr($post_after->post_content, 0, 100) . '...';
        }

        if (empty($old_value) && empty($new_value)) {
            return; // No changes
        }

        ActivityLogger::log(
            'updated',
            $post_after->post_type,
            $post_id,
            $old_value,
            $new_value,
            [
                'post_type' => $post_after->post_type,
            ]
        );
    }

    /**
     * Logs post deletion
     * 
     * @param int $post_id Post ID
     * @return void
     * @since 1.0.0
     */
    public function log_post_deleted($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        ActivityLogger::log(
            'deleted',
            $post->post_type,
            $post_id,
            [
                'title' => $post->post_title,
                'status' => $post->post_status,
            ],
            null,
            [
                'post_type' => $post->post_type,
            ]
        );
    }

    /**
     * Logs post trashed
     * 
     * @param int $post_id Post ID
     * @return void
     * @since 1.0.0
     */
    public function log_post_trashed($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        ActivityLogger::log(
            'trashed',
            $post->post_type,
            $post_id,
            ['status' => $post->post_status],
            ['status' => 'trash'],
            [
                'post_type' => $post->post_type,
            ]
        );
    }

    /**
     * Logs post restored
     * 
     * @param int $post_id Post ID
     * @return void
     * @since 1.0.0
     */
    public function log_post_restored($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        ActivityLogger::log(
            'restored',
            $post->post_type,
            $post_id,
            ['status' => 'trash'],
            ['status' => $post->post_status],
            [
                'post_type' => $post->post_type,
            ]
        );
    }

    /**
     * Logs user creation
     * 
     * @param int $user_id User ID
     * @return void
     * @since 1.0.0
     */
    public function log_user_created($user_id)
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        ActivityLogger::log(
            'created',
            'user',
            $user_id,
            null,
            [
                'username' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
            ]
        );
    }

    /**
     * Logs user update
     * 
     * @param int $user_id User ID
     * @param \WP_User $old_user_data User object before update
     * @return void
     * @since 1.0.0
     */
    public function log_user_updated($user_id, $old_user_data)
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $old_value = [];
        $new_value = [];

        if ($old_user_data->user_email !== $user->user_email) {
            $old_value['email'] = $old_user_data->user_email;
            $new_value['email'] = $user->user_email;
        }

        if ($old_user_data->display_name !== $user->display_name) {
            $old_value['display_name'] = $old_user_data->display_name;
            $new_value['display_name'] = $user->display_name;
        }

        if (empty($old_value) && empty($new_value)) {
            return; // No changes
        }

        ActivityLogger::log(
            'updated',
            'user',
            $user_id,
            $old_value,
            $new_value
        );
    }

    /**
     * Logs user deletion
     * 
     * @param int $user_id User ID
     * @return void
     * @since 1.0.0
     */
    public function log_user_deleted($user_id)
    {
        ActivityLogger::log(
            'deleted',
            'user',
            $user_id,
            null,
            null
        );
    }

    /**
     * Logs user role change
     * 
     * @param int $user_id User ID
     * @param string $role New role
     * @param array $old_roles Old roles
     * @return void
     * @since 1.0.0
     */
    public function log_user_role_changed($user_id, $role, $old_roles)
    {
        ActivityLogger::log(
            'role_changed',
            'user',
            $user_id,
            ['roles' => $old_roles],
            ['role' => $role],
            [
                'action_type' => 'role_change',
            ]
        );
    }

    /**
     * Logs comment creation
     * 
     * @param int $comment_id Comment ID
     * @param \WP_Comment $comment Comment object
     * @return void
     * @since 1.0.0
     */
    public function log_comment_created($comment_id, $comment)
    {
        ActivityLogger::log(
            'created',
            'comment',
            $comment_id,
            null,
            [
                'author' => $comment->comment_author,
                'status' => $comment->comment_approved,
                'post_id' => $comment->comment_post_ID,
            ],
            [
                'post_id' => $comment->comment_post_ID,
            ]
        );
    }

    /**
     * Logs comment update
     * 
     * @param int $comment_id Comment ID
     * @param array $data Comment data
     * @return void
     * @since 1.0.0
     */
    public function log_comment_updated($comment_id, $data)
    {
        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }

        $old_value = [];
        $new_value = [];

        if (isset($data['comment_content']) && $data['comment_content'] !== $comment->comment_content) {
            $old_value['content'] = substr($comment->comment_content, 0, 100) . '...';
            $new_value['content'] = substr($data['comment_content'], 0, 100) . '...';
        }

        if (empty($old_value) && empty($new_value)) {
            return;
        }

        ActivityLogger::log(
            'updated',
            'comment',
            $comment_id,
            $old_value,
            $new_value,
            [
                'post_id' => $comment->comment_post_ID,
            ]
        );
    }

    /**
     * Logs comment deletion
     * 
     * @param int $comment_id Comment ID
     * @param \WP_Comment $comment Comment object
     * @return void
     * @since 1.0.0
     */
    public function log_comment_deleted($comment_id, $comment)
    {
        ActivityLogger::log(
            'deleted',
            'comment',
            $comment_id,
            [
                'author' => $comment->comment_author,
                'post_id' => $comment->comment_post_ID,
            ],
            null,
            [
                'post_id' => $comment->comment_post_ID,
            ]
        );
    }

    /**
     * Logs comment status change
     * 
     * @param int $comment_id Comment ID
     * @param string|int $status New status
     * @return void
     * @since 1.0.0
     */
    public function log_comment_status_changed($comment_id, $status)
    {
        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }

        $old_status = $comment->comment_approved;
        $new_status = $status;

        ActivityLogger::log(
            'status_changed',
            'comment',
            $comment_id,
            ['status' => $old_status],
            ['status' => $new_status],
            [
                'post_id' => $comment->comment_post_ID,
            ]
        );
    }

    /**
     * Logs plugin activation
     * 
     * @param string $plugin Plugin file
     * @param bool $network_wide Whether network-wide activation
     * @return void
     * @since 1.0.0
     */
    public function log_plugin_activated($plugin, $network_wide)
    {
        ActivityLogger::log(
            'activated',
            'plugin',
            null,
            null,
            ['plugin' => $plugin],
            [
                'network_wide' => $network_wide,
            ]
        );
    }

    /**
     * Logs plugin deactivation
     * 
     * @param string $plugin Plugin file
     * @param bool $network_wide Whether network-wide deactivation
     * @return void
     * @since 1.0.0
     */
    public function log_plugin_deactivated($plugin, $network_wide)
    {
        ActivityLogger::log(
            'deactivated',
            'plugin',
            null,
            null,
            ['plugin' => $plugin],
            [
                'network_wide' => $network_wide,
            ]
        );
    }

    /**
     * Logs plugin installation
     * 
     * @param \WP_Upgrader $upgrader Upgrader instance
     * @param array $hook_extra Extra arguments
     * @return void
     * @since 1.0.0
     */
    public function log_plugin_installed($upgrader, $hook_extra)
    {
        if (!isset($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
            return;
        }

        if (isset($hook_extra['action']) && $hook_extra['action'] === 'install') {
            $plugin = isset($hook_extra['plugin']) ? $hook_extra['plugin'] : 'unknown';
            ActivityLogger::log(
                'installed',
                'plugin',
                null,
                null,
                ['plugin' => $plugin]
            );
        }
    }

    /**
     * Logs plugin deletion
     * 
     * Note: WordPress's delete_plugin hook only passes the plugin file path.
     * The second parameter is optional for compatibility with other potential callers.
     * 
     * @param string $plugin_file Plugin file
     * @param bool $deleted Whether deletion was successful (defaults to true)
     * @return void
     * @since 1.0.0
     */
    public function log_plugin_deleted($plugin_file, $deleted = true)
    {
        if ($deleted) {
            ActivityLogger::log(
                'deleted',
                'plugin',
                null,
                null,
                ['plugin' => $plugin_file]
            );
        }
    }

    /**
     * Logs option update (settings changes)
     * 
     * @param string $option_name Option name
     * @param mixed $old_value Old value
     * @param mixed $value New value
     * @return void
     * @since 1.0.0
     */
    public function log_option_updated($option_name, $old_value, $value)
    {
        // Skip certain options to avoid log spam
        $skip_options = [
            'cron',
            'transient',
            '_transient',
            '_site_transient',
            'rewrite_rules',
            'active_plugins',
        ];

        foreach ($skip_options as $skip) {
            if (strpos($option_name, $skip) !== false) {
                return;
            }
        }

        // Only log uixpress settings and important options
        if (strpos($option_name, 'uixpress') === false && strpos($option_name, 'theme_mods') === false) {
            return;
        }

        ActivityLogger::log(
            'updated',
            'option',
            null,
            is_array($old_value) ? $old_value : ['value' => $old_value],
            is_array($value) ? $value : ['value' => $value],
            [
                'option_name' => $option_name,
            ]
        );
    }

    /**
     * Logs media upload
     * 
     * @param int $attachment_id Attachment ID
     * @return void
     * @since 1.0.0
     */
    public function log_media_uploaded($attachment_id)
    {
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return;
        }

        ActivityLogger::log(
            'uploaded',
            'media',
            $attachment_id,
            null,
            [
                'title' => $attachment->post_title,
                'filename' => basename(get_attached_file($attachment_id)),
                'mime_type' => get_post_mime_type($attachment_id),
            ]
        );
    }

    /**
     * Logs media deletion
     * 
     * @param int $attachment_id Attachment ID
     * @return void
     * @since 1.0.0
     */
    public function log_media_deleted($attachment_id)
    {
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return;
        }

        ActivityLogger::log(
            'deleted',
            'media',
            $attachment_id,
            [
                'title' => $attachment->post_title,
                'filename' => basename(get_attached_file($attachment_id)),
            ],
            null
        );
    }

    /**
     * Logs user login
     * 
     * @param string $user_login User login
     * @param \WP_User $user User object
     * @return void
     * @since 1.0.0
     */
    public function log_user_login($user_login, $user)
    {
        ActivityLogger::log(
            'login',
            'user',
            $user->ID,
            null,
            null,
            [
                'username' => $user_login,
            ]
        );
    }

    /**
     * Logs user logout
     * 
     * @param int $user_id User ID
     * @return void
     * @since 1.0.0
     */
    public function log_user_logout($user_id)
    {
        ActivityLogger::log(
            'logout',
            'user',
            $user_id,
            null,
            null
        );
    }
}

