<?php

namespace UiXpress\Pages;

use UiXpress\Options\Settings;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomMediaPage
 *
 * Handles the replacement of the default WordPress media library page with a custom implementation
 */
class CustomMediaPage
{
  /** @var array */
  private static $options;

  /**
   * CustomMediaPage constructor.
   *
   * Sets up the necessary hooks for the media page
   */
  public function __construct()
  {
    add_action("admin_menu", [$this, "remove_default_media_submenu"], 99);
    add_action("load-upload.php", [$this, "init_media_page"]);
  }

  /**
   * Removes the default WordPress "Add Media File" submenu when modern media page is enabled
   *
   * @return void
   */
  public function remove_default_media_submenu()
  {
    if (!current_user_can("upload_files")) {
      return;
    }

    // Modern media page is not enabled
    if (!Settings::is_enabled("use_modern_media_page")) {
      return;
    }

    // Remove the "Add Media File" submenu item
    remove_submenu_page("upload.php", "media-new.php");
  }

  /**
   * Initializes the custom media page implementation
   *
   * @since 1.0.0
   * @return void
   */
  public function init_media_page()
  {
    if (!current_user_can("upload_files")) {
      return;
    }

    // Modern media page is not enabled
    if (!Settings::is_enabled("use_modern_media_page")) {
      return;
    }

    $screen = get_current_screen();

    // Check if current screen is media library
    if ($screen->base === "upload") {
      $this->prevent_default_loading();
      $this->setup_output_capture();
      add_action("admin_enqueue_scripts", [$this, "load_styles_and_scripts"]);
    }
  }

  /**
   * Loads media library styles and scripts
   *
   * @since 1.0.0
   * @return void
   */
  public static function load_styles_and_scripts()
  {
    $url = plugins_url("uixpress/");

    // Get plugin url
    $style = $url . "app/dist/assets/styles/media.css";
    wp_enqueue_style("uixpress-media", $style, [], uixpress_plugin_version);

    add_filter('uixpress/style-layering/exclude', function($excluded_patterns) use ($style) {
      $excluded_patterns[] = $style;
      return $excluded_patterns;
    });

    $script_name = Scripts::get_base_script_path("Media.js");
    
    wp_print_script_tag([
      "id" => "uipc-media-script",
      "src" => $url . "app/dist/{$script_name}",
      "type" => "module",
    ]);
  }


  /**
   * Prevents WordPress from loading default media library components
   *
   * @since 1.0.0
   * @return void
   */
  private function prevent_default_loading()
  {
    // Remove default media library scripts and styles
    remove_action("admin_enqueue_scripts", "wp_enqueue_media");
    
    // Prevent media library queries from running
    add_filter("pre_get_posts", [$this, "modify_media_query"]);
  }

  /**
   * Modifies the media query to prevent default loading
   *
   * @param WP_Query $query The WordPress query object
   * @return WP_Query
   * @since 1.0.0
   */
  public function modify_media_query($query)
  {
    if ($query->is_main_query() && is_admin() && isset($_GET['page']) && $_GET['page'] === 'upload.php') {
      $query->set("posts_per_page", 0);
      $query->set("no_found_rows", true);
    }
    return $query;
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
   * Renders the custom content for the media library page
   *
   * @since 1.0.0
   * @return void
   */
  public function render_custom_content()
  {
    ob_end_clean(); ?>
		<div id="uix-media-page">
		</div>
		<?php
  }
}
