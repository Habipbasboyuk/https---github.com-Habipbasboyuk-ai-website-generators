<?php

namespace UiXpress\Pages;

use UiXpress\Options\Settings;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomUsersPage
 *
 * Handles the replacement of the default WordPress users page with a custom implementation
 */
class CustomUsersPage
{
  /** @var array */
  private static $options;

  /**
   * CustomUsersPage constructor.
   *
   * Sets up the necessary hooks for the users page
   */
  public function __construct()
  {
    add_action("load-users.php", [$this, "init_users_page"]);
    add_action("load-user-edit.php", [$this, "init_users_page"]);
    //add_action("load-profile.php", [$this, "init_users_page"]);
  }

  /**
   * Initializes the custom users page implementation
   *
   * @since 1.0.0
   * @return void
   */
  public function init_users_page()
  {
    if (!current_user_can("list_users")) {
      return;
    }

    // Modern users page is not enabled
    if (!Settings::is_enabled("use_modern_users_page")) {
      return;
    }

    $screen = get_current_screen();

    // Check if current screen is users page
    if ($screen->base === "users" || $screen->base === "user-edit" || $screen->base === "profile") {
      $this->prevent_default_loading();
      $this->setup_output_capture();
      add_action("admin_enqueue_scripts", [$this, "load_styles_and_scripts"]);
    }
  }

  /**
   * Loads users page styles and scripts
   *
   * @since 1.0.0
   * @return void
   */
  public static function load_styles_and_scripts()
  {
    $url = plugins_url("uixpress/");

    // Get plugin url
    $style = $url . "app/dist/assets/styles/users.css";
    wp_enqueue_style("uixpress-users", $style, [], uixpress_plugin_version);

    add_filter('uixpress/style-layering/exclude', function($excluded_patterns) use ($style) {
      $excluded_patterns[] = $style;
      return $excluded_patterns;
    });

    $script_name = Scripts::get_base_script_path("Users.js");
    
    wp_print_script_tag([
      "id" => "uipc-users-script",
      "src" => $url . "app/dist/{$script_name}",
      "type" => "module",
    ]);
  }


  /**
   * Prevents WordPress from loading default users page components
   *
   * @since 1.0.0
   * @return void
   */
  private function prevent_default_loading()
  {
    // Remove default users page scripts and styles if needed
    // WordPress doesn't typically enqueue much for users page by default
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
   * Renders the custom content for the users page
   *
   * @since 1.0.0
   * @return void
   */
  public function render_custom_content()
  {
    ob_end_clean(); ?>
		<div id="uix-users-page">
		</div>
		<?php
  }
}

