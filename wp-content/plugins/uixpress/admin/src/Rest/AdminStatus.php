<?php

namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class AdminStatus
 *
 * REST API endpoint for admin status (updates, comments, new content items).
 * Provides a unified endpoint for the admin status toolbar component.
 *
 * @since 1.0.0
 */
class AdminStatus
{
  /**
   * REST namespace
   *
   * @var string
   */
  private $namespace = "uixpress/v1";

  /**
   * REST base
   *
   * @var string
   */
  private $base = "admin-status";

  /**
   * AdminStatus constructor.
   * Registers REST API endpoints.
   */
  public function __construct()
  {
    add_action("rest_api_init", [$this, "register_endpoints"]);
  }

  /**
   * Registers REST endpoints for admin status.
   *
   * @since 1.0.0
   */
  public function register_endpoints()
  {
    register_rest_route($this->namespace, "/" . $this->base, [
      "methods" => "GET",
      "callback" => [$this, "get_admin_status"],
      "permission_callback" => function ($request) {
        return RestPermissionChecker::check_login_only($request);
      },
    ]);
  }

  /**
   * Get admin status data including updates and comments.
   *
   * @param \WP_REST_Request $request The REST request object
   * @return \WP_REST_Response The response with admin status data
   * @since 1.0.0
   */
  public function get_admin_status($request)
  {
    $response = [
      "updates" => $this->get_updates_data(),
      "comments" => $this->get_comments_data(),
    ];

    return new \WP_REST_Response($response, 200);
  }

  /**
   * Get updates data (core, plugins, themes) with detailed item lists.
   * Only returns data if user has appropriate capabilities.
   *
   * @return array|null Updates data or null if user lacks permissions
   * @since 1.0.0
   */
  private function get_updates_data()
  {
    // Check if user can see any updates
    $can_update_core = current_user_can("update_core");
    $can_update_plugins = current_user_can("update_plugins");
    $can_update_themes = current_user_can("update_themes");

    if (!$can_update_core && !$can_update_plugins && !$can_update_themes) {
      return null;
    }

    // Load required files for update functions
    require_once ABSPATH . "wp-admin/includes/update.php";
    require_once ABSPATH . "wp-admin/includes/plugin.php";

    $updates = [
      "total" => 0,
      "core" => null,
      "plugins" => [],
      "themes" => [],
    ];

    // Core updates
    if ($can_update_core) {
      $core_updates = get_core_updates();
      if (!empty($core_updates) && isset($core_updates[0]) && $core_updates[0]->response === 'upgrade') {
        $core = $core_updates[0];
        $updates["core"] = [
          "current_version" => get_bloginfo('version'),
          "new_version" => $core->version,
          "url" => admin_url("update-core.php"),
          "icon" => "upgrade",
        ];
        $updates["total"] += 1;
      }
    }

    // Plugin updates
    if ($can_update_plugins) {
      $plugin_updates = get_plugin_updates();
      if (!empty($plugin_updates)) {
        foreach ($plugin_updates as $plugin_file => $plugin_data) {
          $slug_parts = explode("/", $plugin_file);
          $base_slug = $slug_parts[0];
          
          $updates["plugins"][] = [
            "name" => $plugin_data->Name,
            "slug" => $base_slug,
            "file" => $plugin_file,
            "current_version" => $plugin_data->Version,
            "new_version" => isset($plugin_data->update->new_version) ? $plugin_data->update->new_version : '',
            "url" => admin_url("plugins.php?plugin_status=upgrade"),
            "icon" => "extension",
          ];
          $updates["total"] += 1;
        }
      }
    }

    // Theme updates
    if ($can_update_themes) {
      $theme_updates = get_theme_updates();
      if (!empty($theme_updates)) {
        foreach ($theme_updates as $stylesheet => $theme_data) {
          $updates["themes"][] = [
            "name" => $theme_data->display('Name'),
            "slug" => $stylesheet,
            "current_version" => $theme_data->display('Version'),
            "new_version" => isset($theme_data->update['new_version']) ? $theme_data->update['new_version'] : '',
            "url" => admin_url("themes.php"),
            "icon" => "palette",
          ];
          $updates["total"] += 1;
        }
      }
    }

    return $updates;
  }

  /**
   * Get comments data (pending moderation).
   * Only returns data if user can moderate comments.
   *
   * @return array|null Comments data or null if user lacks permissions
   * @since 1.0.0
   */
  private function get_comments_data()
  {
    // Check if user can moderate comments
    if (!current_user_can("moderate_comments")) {
      return null;
    }

    // Get comment counts
    $comment_counts = wp_count_comments();
    $pending = isset($comment_counts->moderated) ? (int) $comment_counts->moderated : 0;

    return [
      "pending" => $pending,
      "url" => admin_url("edit-comments.php?comment_status=moderated"),
      "label" => __("Comments awaiting moderation", "uixpress"),
    ];
  }

  /**
   * Get available post types that the user can create.
   *
   * @return array Array of post types with their creation URLs
   * @since 1.0.0
   */
  private function get_post_types_data()
  {
    $post_types = [];

    // Get post types that should appear in admin bar
    $types = get_post_types(["show_in_admin_bar" => true], "objects");

    foreach ($types as $type) {
      // Check if user can create posts of this type
      if (!current_user_can($type->cap->create_posts)) {
        continue;
      }

      // Get the appropriate URL for creating new posts
      $url = "";
      if ($type->name === "post") {
        $url = admin_url("post-new.php");
      } elseif ($type->name === "page") {
        $url = admin_url("post-new.php?post_type=page");
      } else {
        $url = admin_url("post-new.php?post_type=" . $type->name);
      }

      $post_types[] = [
        "name" => $type->name,
        "label" => $type->labels->singular_name,
        "url" => $url,
        "icon" => $this->get_post_type_icon($type->name),
      ];
    }

    // Add media upload if user can upload files
    if (current_user_can("upload_files")) {
      $post_types[] = [
        "name" => "media",
        "label" => __("Media", "uixpress"),
        "url" => admin_url("media-new.php"),
        "icon" => "image",
      ];
    }

    // Add user if user can create users
    if (current_user_can("create_users")) {
      $post_types[] = [
        "name" => "user",
        "label" => __("User", "uixpress"),
        "url" => admin_url("user-new.php"),
        "icon" => "account_circle",
      ];
    }

    return $post_types;
  }

  /**
   * Get an appropriate icon for a post type.
   *
   * @param string $post_type The post type name
   * @return string Icon identifier
   * @since 1.0.0
   */
  private function get_post_type_icon($post_type)
  {
    $icons = [
      "post" => "article",
      "page" => "description",
      "attachment" => "image",
      "product" => "sell",
      "shop_order" => "receipt_long",
    ];

    return isset($icons[$post_type]) ? $icons[$post_type] : "description";
  }
}
