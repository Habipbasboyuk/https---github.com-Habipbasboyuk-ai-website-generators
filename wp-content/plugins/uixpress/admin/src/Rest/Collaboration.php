<?php

namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class Collaboration
 *
 * REST API endpoints for real-time collaboration
 * Provides authentication and document persistence for Hocuspocus server
 * 
 * @since 1.2.16
 */
class Collaboration
{
    /**
     * Collaboration constructor.
     */
    public function __construct()
    {
        add_action("rest_api_init", [$this, "register_custom_endpoints"]);
        add_action("wp_enqueue_scripts", [$this, "enqueue_collaboration_config"]);
        add_action("admin_enqueue_scripts", [$this, "enqueue_collaboration_config"]);
    }

    /**
     * Registers custom REST API endpoints
     * 
     * @return void
     * @since 1.2.16
     */
    public function register_custom_endpoints()
    {
        // Verify user can edit a post (called by Hocuspocus server)
        register_rest_route('uixpress/v1', '/collab/verify', [
            'methods' => 'POST',
            'callback' => [$this, 'verify_user'],
            'permission_callback' => '__return_true', // Public endpoint, authentication is done inside
            'args' => [
                'post_id' => [
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
                'token' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Get collaboration document state
        register_rest_route('uixpress/v1', '/collab/document/(?P<post_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_document'],
            'permission_callback' => [$this, 'check_edit_permissions'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    },
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Save collaboration document state
        register_rest_route('uixpress/v1', '/collab/document/(?P<post_id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'save_document'],
            'permission_callback' => [$this, 'check_edit_permissions'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    },
                    'sanitize_callback' => 'absint',
                ],
                'document' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Get collaboration settings
        register_rest_route('uixpress/v1', '/collab/settings', [
            'methods' => 'GET',
            'callback' => [$this, 'get_settings'],
            'permission_callback' => [$this, 'check_edit_permissions'],
        ]);

        // Get active collaborators for a post
        register_rest_route('uixpress/v1', '/collab/users/(?P<post_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_active_users'],
            'permission_callback' => [$this, 'check_edit_permissions'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    },
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Enqueues collaboration configuration for the frontend
     *
     * @return void
     * @since 1.2.16
     */
    public function enqueue_collaboration_config()
    {
        if (!is_user_logged_in()) {
            return;
        }

        $user = wp_get_current_user();
        $collab_enabled = $this->is_collaboration_enabled();

        // Collaboration configuration
        $collab_config = [
            'enabled' => $collab_enabled,
            'serverUrl' => $this->get_server_url(),
            'partyKitHost' => $this->get_partykit_host(),
            'mode' => $this->get_provider_mode(),
            'siteId' => $this->get_site_id(),
            'siteUrl' => get_site_url(),
            'userId' => $user->ID,
            'userName' => $user->display_name,
            'userColor' => $this->generate_user_color($user->ID),
            'userAvatar' => get_avatar_url($user->ID),
        ];

        // Output as global JavaScript variable
        wp_add_inline_script(
            'wp-api-fetch',
            'window.uixpressCollab = ' . wp_json_encode($collab_config) . ';',
            'before'
        );
    }

    /**
     * Gets the provider mode
     *
     * @return string 'hosted', 'custom', or 'cloud'
     * @since 1.2.16
     */
    private function get_provider_mode()
    {
        $custom_url = get_option('uix_collaboration_custom_server_url', '');
        if (!empty($custom_url)) {
            return 'custom';
        }
        return get_option('uix_collaboration_mode', 'hosted');
    }

    /**
     * Gets or generates a unique site identifier
     * This ensures documents are isolated per WordPress installation
     *
     * @return string Unique site identifier
     * @since 1.2.16
     */
    private function get_site_id()
    {
        $site_id = get_option('uix_collaboration_site_id', '');
        
        if (empty($site_id)) {
            // Generate a unique site ID based on site URL
            $site_url = get_site_url();
            $site_id = substr(md5($site_url), 0, 12);
            update_option('uix_collaboration_site_id', $site_id);
        }
        
        return $site_id;
    }

    /**
     * Checks if the user has permission to edit posts
     *
     * @param \WP_REST_Request $request The request object
     * @return bool|\WP_Error True if the user has permission, WP_Error object otherwise
     * @since 1.2.16
     */
    public function check_edit_permissions($request)
    {
        $post_id = $request->get_param('post_id');
        
        if ($post_id) {
            return current_user_can('edit_post', $post_id);
        }
        
        return current_user_can('edit_posts');
    }

    /**
     * Verifies if a user can edit a specific post
     * Called by Hocuspocus server for authentication
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response The response object
     * @since 1.2.16
     */
    public function verify_user($request)
    {
        $post_id = $request->get_param('post_id');
        $token = $request->get_param('token');

        // Verify nonce/token
        if (!wp_verify_nonce($token, 'wp_rest')) {
            // Try to authenticate via cookie
            $user_id = wp_validate_auth_cookie('', 'logged_in');
            
            if (!$user_id) {
                return new \WP_REST_Response([
                    'can_edit' => false,
                    'error' => 'Invalid authentication',
                ], 401);
            }
        }

        $user = wp_get_current_user();
        
        if (!$user || !$user->exists()) {
            return new \WP_REST_Response([
                'can_edit' => false,
                'error' => 'User not found',
            ], 401);
        }

        // Check if user can edit this specific post
        $can_edit = current_user_can('edit_post', $post_id);

        if (!$can_edit) {
            return new \WP_REST_Response([
                'can_edit' => false,
                'error' => 'User cannot edit this post',
            ], 403);
        }

        return new \WP_REST_Response([
            'can_edit' => true,
            'user' => [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'avatar' => get_avatar_url($user->ID),
                'color' => $this->generate_user_color($user->ID),
            ],
        ]);
    }

    /**
     * Gets the collaboration document state for a post
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response The response object
     * @since 1.2.16
     */
    public function get_document($request)
    {
        $post_id = $request->get_param('post_id');
        
        // Get stored Y.js document state
        $document = get_post_meta($post_id, '_uix_collab_document', true);

        return new \WP_REST_Response([
            'post_id' => $post_id,
            'document' => $document ?: null,
            'has_document' => !empty($document),
        ]);
    }

    /**
     * Saves the collaboration document state for a post
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response The response object
     * @since 1.2.16
     */
    public function save_document($request)
    {
        $post_id = $request->get_param('post_id');
        $document = $request->get_param('document');

        // Store Y.js document state as post meta
        $updated = update_post_meta($post_id, '_uix_collab_document', $document);

        return new \WP_REST_Response([
            'success' => true,
            'post_id' => $post_id,
            'updated' => $updated,
        ]);
    }

    /**
     * Gets collaboration settings
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response The response object
     * @since 1.2.16
     */
    public function get_settings($request)
    {
        $user = wp_get_current_user();

        return new \WP_REST_Response([
            'enabled' => $this->is_collaboration_enabled(),
            'serverUrl' => $this->get_server_url(),
            'user' => [
                'id' => $user->ID,
                'name' => $user->display_name,
                'color' => $this->generate_user_color($user->ID),
                'avatar' => get_avatar_url($user->ID),
            ],
        ]);
    }

    /**
     * Gets active collaborators for a post
     * (This is a placeholder - in a real implementation, you'd query the Hocuspocus server)
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response The response object
     * @since 1.2.16
     */
    public function get_active_users($request)
    {
        $post_id = $request->get_param('post_id');

        // In a full implementation, you would query the Hocuspocus server
        // for active connections to this document
        // For now, return an empty array
        return new \WP_REST_Response([
            'post_id' => $post_id,
            'users' => [],
        ]);
    }

    /**
     * Checks if collaboration is enabled
     *
     * @return bool
     * @since 1.2.16
     */
    private function is_collaboration_enabled()
    {
        // Check uixpress_settings for enable_realtime_collaboration
        $settings = get_option('uixpress_settings', []);
        $enabled = isset($settings['enable_realtime_collaboration']) ? (bool) $settings['enable_realtime_collaboration'] : false;
        
        // Also check if modern post editor is enabled (collaboration requires it)
        $modern_editor_enabled = isset($settings['use_modern_post_editor']) ? (bool) $settings['use_modern_post_editor'] : false;
        
        return $enabled && $modern_editor_enabled;
    }

    /**
     * Gets the collaboration server URL (legacy, for backwards compatibility)
     *
     * @return string
     * @since 1.2.16
     */
    private function get_server_url()
    {
        $url = get_option('uix_collaboration_server_url', '');
        
        // Default to localhost for development
        if (empty($url)) {
            $url = 'ws://localhost:1234';
        }
        
        return $url;
    }

    /**
     * Gets the PartyKit host
     *
     * @return string PartyKit host (without protocol)
     * @since 1.2.16
     */
    private function get_partykit_host()
    {
        // Allow override via option, otherwise use default production host
        return get_option('uix_collaboration_partykit_host', 'uixpress-collab.wpuipress.partykit.dev');
    }

    /**
     * Generates a consistent color for a user based on their ID
     *
     * @param int $user_id The user ID
     * @return string Hex color code
     * @since 1.2.16
     */
    private function generate_user_color($user_id)
    {
        $colors = [
            '#F44336', '#E91E63', '#9C27B0', '#673AB7',
            '#3F51B5', '#2196F3', '#03A9F4', '#00BCD4',
            '#009688', '#4CAF50', '#8BC34A', '#CDDC39',
            '#FFC107', '#FF9800', '#FF5722', '#795548',
        ];
        
        // Use user ID to pick a consistent color
        $index = $user_id % count($colors);
        
        return $colors[$index];
    }
}

