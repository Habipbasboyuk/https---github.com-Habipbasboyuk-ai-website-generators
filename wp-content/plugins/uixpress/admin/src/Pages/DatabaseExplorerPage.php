<?php

namespace UiXpress\Pages;

use UiXpress\Options\Settings;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class DatabaseExplorerPage
 *
 * Handles the database explorer admin page
 */
class DatabaseExplorerPage
{
  /**
   * DatabaseExplorerPage constructor.
   *
   * Sets up the necessary hooks for the database explorer page
   */
  public function __construct()
  {
    add_action("admin_menu", [$this, "add_admin_page"], 99);
  }

  /**
   * Adds the database explorer page to the admin menu
   *
   * @return void
   */
  public function add_admin_page()
  {
    if (!current_user_can("manage_options")) {
      return;
    }

    // Check if database explorer is enabled
    if (!Settings::is_enabled("enable_database_explorer")) {
      return;
    }

    // Add submenu page under uiXpress top-level menu
    $hook_suffix = add_submenu_page(
      "uipx-settings",
      __("Database Explorer", "uixpress"),
      __("Database Explorer", "uixpress"),
      "manage_options",
      "uixpress-database-explorer",
      [$this, "render_page"]
    );

    add_action("admin_head-{$hook_suffix}", [$this, "load_styles_and_scripts"]);
  }

  /**
   * Loads database explorer styles and scripts
   *
   * @since 1.0.0
   * @return void
   */
  public static function load_styles_and_scripts()
  {
    $url = plugins_url("uixpress/");

    // Get plugin url
    $style = $url . "app/dist/assets/styles/database.css";
    wp_enqueue_style("uixpress-database", $style, [], uixpress_plugin_version);

    add_filter('uixpress/style-layering/exclude', function($excluded_patterns) use ($style) {
      $excluded_patterns[] = $style;
      return $excluded_patterns;
    });


    // Add ID to stylesheet for shadow DOM access
    add_filter("style_loader_tag", function ($tag, $handle) {
      if ($handle === "uixpress-database") {
        return str_replace('<link ', '<link id="uixpress-database-css" ', $tag);
      }
      return $tag;
    }, 10, 2);

    $script_name = Scripts::get_base_script_path("Database.js");
    
    wp_print_script_tag([
      "id" => "uipc-database-script",
      "src" => $url . "app/dist/{$script_name}",
      "type" => "module",
    ]);
  }

  /**
   * Renders the database explorer page
   *
   * @since 1.0.0
   * @return void
   */
  public function render_page()
  {
    ?>
    <div id="uix-database-page"></div>
    <?php
  }
}

