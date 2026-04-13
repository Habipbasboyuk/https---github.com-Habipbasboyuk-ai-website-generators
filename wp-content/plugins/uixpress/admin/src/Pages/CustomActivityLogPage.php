<?php

namespace UiXpress\Pages;

use UiXpress\Options\Settings;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomActivityLogPage
 *
 * Handles the custom Activity Log page implementation
 * 
 * @since 1.0.0
 */
class CustomActivityLogPage
{
  /**
   * CustomActivityLogPage constructor.
   *
   * Sets up the necessary hooks for the activity log page
   */
  public function __construct()
  {
    add_action("admin_menu", ["UiXpress\Pages\CustomActivityLogPage", "add_activity_log_menu"]);
  }

  /**
   * Adds Activity Log menu item
   *
   * @since 1.0.0
   * @return void
   */
  public static function add_activity_log_menu()
  {
    // Check if activity logger is enabled
    if (!Settings::is_enabled("enable_activity_logger")) {
      return;
    }

    $hook_suffix = add_submenu_page(
      "uipx-settings",
      __("Activity Log", "uixpress"),
      __("Activity Log", "uixpress"),
      "manage_options",
      "uixpress-activity-log",
      ["UiXpress\Pages\CustomActivityLogPage", "render_activity_log_page"]
    );

    add_action("admin_head-{$hook_suffix}", ["UiXpress\Pages\CustomActivityLogPage", "load_styles"]);
    add_action("admin_head-{$hook_suffix}", ["UiXpress\Pages\CustomActivityLogPage", "load_scripts"]);
  }

  /**
   * Renders the activity log page
   *
   * @since 1.0.0
   * @return void
   */
  public static function render_activity_log_page()
  {
    // Output the app
    echo "<div id='uix-activity-log-page'></div>";
  }

  /**
   * Loads activity log page styles
   *
   * @since 1.0.0
   * @return void
   */
  public static function load_styles()
  {
    // Get plugin url
    $url = plugins_url("uixpress/");
    $style = $url . "app/dist/assets/styles/activity-log.css";
    wp_enqueue_style("uixpress-activity-log", $style, [], uixpress_plugin_version);

    add_filter('uixpress/style-layering/exclude', function($excluded_patterns) use ($style) {
      $excluded_patterns[] = $style;
      return $excluded_patterns;
    });
  }

  /**
   * Loads activity log page scripts
   *
   * @since 1.0.0
   * @return void
   */
  public static function load_scripts()
  {
    // Get plugin url
    $url = plugins_url("uixpress/");
    $current_user = wp_get_current_user();
    $options = get_option("uixpress_settings", []);
    $script_name = Scripts::get_base_script_path("ActivityLog.js");

    // Setup script object
    $activityLogScript = [
      "id" => "uipc-activity-log-script",
      "src" => $url . "app/dist/{$script_name}",
      "plugin-base" => esc_url($url),
      "rest-base" => esc_url(rest_url()),
      "rest-nonce" => wp_create_nonce("wp_rest"),
      "admin-url" => esc_url(admin_url()),
      "site-url" => esc_url(site_url()),
      "user-id" => absint($current_user->ID),
      "user-name" => esc_attr($current_user->display_name),
      "user-email" => esc_attr($current_user->user_email),
      "front-page" => is_front_page() ? "true" : "false",
      "uixpress-settings" => esc_attr(json_encode($options)),
      "type" => "module",
    ];

    // Print tag
    wp_print_script_tag($activityLogScript);
  }
}

