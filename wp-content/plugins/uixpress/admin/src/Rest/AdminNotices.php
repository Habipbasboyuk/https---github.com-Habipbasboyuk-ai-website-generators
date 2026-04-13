<?php

namespace UiXpress\Rest;

use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class AdminNotices
 *
 * Handles persistent admin notices via custom post type and REST API meta fields.
 *
 * @since 1.0.0
 */
class AdminNotices
{
    /**
     * AdminNotices constructor.
     * Registers post type, meta fields, and settings page.
     */
    public function __construct()
    {
        add_action("init", [__CLASS__, "register_post_type"]);
        add_action("init", [__CLASS__, "register_meta_fields"]);
        add_action("admin_menu", [__CLASS__, "admin_notices_settings_page"]);
        add_action('rest_api_init', [__CLASS__, 'register_rest_endpoints']);
    }

    /**
     * Registers the uixpress_notice custom post type.
     *
     * @since 1.0.0
     */
    public static function register_post_type()
    {
        $labels = [
            "name" => _x("Admin Notices", "post type general name", "uixpress"),
            "singular_name" => _x("Admin Notice", "post type singular name", "uixpress"),
            "menu_name" => _x("Admin Notices", "admin menu", "uixpress"),
            "add_new" => _x("Add New", "notice", "uixpress"),
            "add_new_item" => __("Add New Admin Notice", "uixpress"),
            "edit_item" => __("Edit Admin Notice", "uixpress"),
            "view_item" => __("View Admin Notice", "uixpress"),
            "all_items" => __("All Admin Notices", "uixpress"),
            "search_items" => __("Search Admin Notices", "uixpress"),
            "not_found" => __("No Admin Notices found.", "uixpress"),
        ];
        $args = [
            "labels" => $labels,
            "description" => __("Persistent admin notices for uiXpress.", "uixpress"),
            "public" => false,
            "publicly_queryable" => false,
            "show_ui" => false,
            "show_in_menu" => false,
            "query_var" => false,
            "has_archive" => false,
            "hierarchical" => false,
            "supports" => ["title", "editor", "custom-fields"],
            "show_in_rest" => true,
            "rest_base" => "uixpress-notices",
        ];
        register_post_type("uixpress_notice", $args);
    }

    /**
     * Registers meta fields for the notice post type.
     *
     * @since 1.0.0
     */
    public static function register_meta_fields()
    {
        // notice_type
        register_post_meta("uixpress_notice", "notice_type", [
            "single" => true,
            "default" => "info",
            "show_in_rest" => true,
            "sanitize_callback" => "sanitize_text_field",
            "auth_callback" => function () {
                return is_user_logged_in();
            },
        ]);

        // roles (array of objects: {id, value, type})
        register_post_meta("uixpress_notice", "roles", [
            "type" => "array",
            "single" => true,
            "default" => [],
            "show_in_rest" => [
                "schema" => [
                    "type" => "array",
                    "items" => [
                        "type" => "object",
                        "properties" => [
                            "id" => ["type" => "string"],
                            "value" => ["type" => "string"],
                            "type" => ["type" => "string"],
                        ],
                    ],
                ],
            ],
            "sanitize_callback" => [__CLASS__, "sanitize_roles"],
            "auth_callback" => function () {
                return is_user_logged_in();
            },
        ]);

        // dismissible
        register_post_meta("uixpress_notice", "dismissible", [
            "type" => "boolean",
            "single" => true,
            "default" => true,
            "show_in_rest" => true,
            "sanitize_callback" => "rest_sanitize_boolean",
            "auth_callback" => function () {
                return is_user_logged_in();
            },
        ]);

        // important
        register_post_meta("uixpress_notice", "important", [
            "type" => "boolean",
            "single" => true,
            "default" => false,
            "show_in_rest" => true,
            "sanitize_callback" => "rest_sanitize_boolean",
            "auth_callback" => function () {
                return is_user_logged_in();
            },
        ]);

        // seen_by (array of integers)
        register_post_meta("uixpress_notice", "seen_by", [
            "type" => "array",
            "single" => true,
            "default" => [],
            "show_in_rest" => [
                "schema" => [
                    "type" => "array",
                    "items" => ["type" => "integer"],
                ],
            ],
            "sanitize_callback" => [__CLASS__, "sanitize_seen_by"],
            "auth_callback" => function () {
                return is_user_logged_in();
            },
        ]);
    }

    /**
     * Sanitizes the roles array (array of objects: {id, value, type}).
     *
     * @param mixed $value
     * @return array
     */
    public static function sanitize_roles($value)
    {
        if (!is_array($value)) {
            return [];
        }
        $sanitized = [];
        foreach ($value as $item) {
            if (!is_array($item)) continue;
            $sanitized[] = [
                'id' => isset($item['id']) ? sanitize_text_field($item['id']) : '',
                'value' => isset($item['value']) ? sanitize_text_field($item['value']) : '',
                'type' => isset($item['type']) ? sanitize_text_field($item['type']) : '',
            ];
        }
        return $sanitized;
    }

    /**
     * Sanitizes the seen_by array (user IDs).
     *
     * @param mixed $value
     * @return array
     */
    public static function sanitize_seen_by($value)
    {
        if (!is_array($value)) {
            return [];
        }
        return array_map("intval", $value);
    }

    /**
     * Adds the Admin Notices settings page to the WordPress Settings menu.
     *
     * @since 1.0.0
     */
    public static function admin_notices_settings_page()
    {
        $menu_name = __("Admin Notices", "uixpress");
        $hook_suffix = add_submenu_page('uipx-settings', $menu_name, $menu_name, "manage_options", "uixpress-admin-notices", [__CLASS__, "render_admin_notices_app"]);
        add_action("admin_head-{$hook_suffix}", [__CLASS__, "load_styles"]);
        add_action("admin_head-{$hook_suffix}", [__CLASS__, "load_scripts"]);
    }

    /**
     * uixpress styles.
     *
     * Loads main lp styles
     */
    public static function load_styles()
    {
        // Get plugin url
        $url = plugins_url("uixpress/");
        $style = $url . "app/dist/assets/styles/admin-notices.css";
        wp_enqueue_style("uixpress-admin-notices", $style, [], uixpress_plugin_version);
    }

    /**
     * uixpress scripts.
     *
     * Loads main lp scripts
     */
    public static function load_scripts()
    {
        // Get plugin url
        $url = plugins_url("uixpress/");
        $script_name = Scripts::get_base_script_path("AdminNotices.js");

        // Setup script object
        $builderScript = [
            "id" => "uipc-admin-notices-script",
            "src" => $url . "app/dist/{$script_name}",
            "type" => "module",
        ];

        // Print tag
        wp_print_script_tag($builderScript);
    }

    /**
     * Outputs the Vue app holder for the Admin Notices settings page.
     *
     * @since 1.0.0
     */
    public static function render_admin_notices_app()
    {
        echo "<div id='uixpress-admin-notices-app'></div>";
    }

    /**
     * Registers custom REST endpoints for admin notices.
     *
     * @since 1.0.0
     */
    public static function register_rest_endpoints()
    {
        register_rest_route('uixpress/v1', '/notices/seen', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'mark_notice_seen'],
            'permission_callback' => function ($request) {
                return RestPermissionChecker::check_login_only($request);
            },
            'args' => [
                'notice_id' => [
                    'required' => true,
                    'sanitize_callback' => function ($value) {
                        return intval($value);
                    },
                    'description' => 'ID of the notice to mark as seen'
                ],
            ],
        ]);
    }

    /**
     * Marks a notice as seen by the current user (adds user ID to seen_by meta).
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     * @since 1.0.0
     */
    public static function mark_notice_seen($request)
    {
        $notice_id = intval($request->get_param('notice_id'));
        $user_id = get_current_user_id();
        if (!$notice_id || !$user_id) {
            return new \WP_Error('invalid_data', 'Invalid notice or user.', 400);
        }
        $seen_by = get_post_meta($notice_id, 'seen_by', true);
        if (!is_array($seen_by)) $seen_by = [];
        if (!in_array($user_id, $seen_by)) {
            $seen_by[] = $user_id;
            update_post_meta($notice_id, 'seen_by', $seen_by);
        }
        return rest_ensure_response(['success' => true, 'seen_by' => $seen_by]);
    }
}
