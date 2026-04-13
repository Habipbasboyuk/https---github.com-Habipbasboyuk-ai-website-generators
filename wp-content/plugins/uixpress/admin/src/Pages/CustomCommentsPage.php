<?php

namespace UiXpress\Pages;

use UiXpress\Options\Settings;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomCommentsPage
 *
 * Handles the replacement of the default WordPress comments page with a custom implementation
 */
class CustomCommentsPage
{
  /** @var array */
  private static $options;

  /**
   * CustomCommentsPage constructor.
   *
   * Sets up the necessary hooks for the comments page
   */
  public function __construct()
  {
    add_action("load-edit-comments.php", [$this, "init_comments_page"]);
  }

  /**
   * Initializes the custom comments page implementation
   *
   * @since 1.0.0
   * @return void
   */
  public function init_comments_page()
  {
    if (!current_user_can("moderate_comments")) {
      return;
    }

    // Modern comments page is not enabled
    if (!Settings::is_enabled("use_modern_comments_page")) {
      return;
    }

    $screen = get_current_screen();

    // Check if current screen is comments page
    if ($screen->base === "edit-comments") {
      $this->prevent_default_loading();
      $this->setup_output_capture();
      add_action("admin_enqueue_scripts", [$this, "load_styles_and_scripts"]);
    }
  }

  /**
   * Loads comments page styles and scripts
   *
   * @since 1.0.0
   * @return void
   */
  public static function load_styles_and_scripts()
  {
    $url = plugins_url("uixpress/");
    $current_user = wp_get_current_user();
    $options = get_option("uixpress_settings", []);

    // Get plugin url
    $style = $url . "app/dist/assets/styles/comments.css";
    wp_enqueue_style("uixpress-comments", $style, [], uixpress_plugin_version);

    add_filter('uixpress/style-layering/exclude', function($excluded_patterns) use ($style) {
      $excluded_patterns[] = $style;
      return $excluded_patterns;
    });

    $script_name = Scripts::get_base_script_path("Comments.js");
    
    wp_print_script_tag([
      "id" => "uipc-comments-script",
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
    ]);
  }


  /**
   * Prevents WordPress from loading default comments page components
   *
   * @since 1.0.0
   * @return void
   */
  private function prevent_default_loading()
  {
    // Remove default comments page scripts and styles if needed
    // WordPress doesn't typically enqueue much for comments page by default
  }

  /**
   * Sets up output buffering and custom content display
   *
   * @since 1.0.0
   * @return void
   */
  private function setup_output_capture()
  {
    // Start output buffering
    add_action("in_admin_header", [$this, "start_output_buffer"], 999);

    // Output custom content
    add_action("admin_footer", [$this, "render_custom_content"], 0);
  }

  /**
   * Starts the output buffer
   *
   * @since 1.0.0
   * @return void
   */
  public function start_output_buffer()
  {
    ob_start();
  }

  /**
   * Renders the custom content for the comments page
   *
   * @since 1.0.0
   * @return void
   */
  public function render_custom_content()
  {
    ob_end_clean(); ?>
		<div id="uix-comments-page">
		</div>
		<?php
  }
}

