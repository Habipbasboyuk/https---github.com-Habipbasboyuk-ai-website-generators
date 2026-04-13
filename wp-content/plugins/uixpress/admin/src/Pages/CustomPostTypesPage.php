<?php

namespace UiXpress\Pages;

use UiXpress\Options\Settings;
use UiXpress\Utility\Scripts;
use UiXpress\Rest\CustomPostTypes;
use UiXpress\Rest\CustomFields\CustomFields;
use UiXpress\Rest\CustomFields\CustomFieldsLoader;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomPostTypesPage
 *
 * Handles the custom post types page for managing custom post types
 * Also initializes custom post types and custom fields functionality
 */
class CustomPostTypesPage
{
  /**
   * CustomPostTypesPage constructor.
   *
   * Sets up the necessary hooks for the custom post types page
   * and initializes custom post types and custom fields functionality
   */
  public function __construct()
  {
    add_action("admin_menu", [$this, "setup_admin_page"]);
    
    // Initialize custom post types and custom fields
    $this->init_custom_post_types();
    $this->init_custom_fields();
  }

  /**
   * Initialize custom post types functionality
   */
  private function init_custom_post_types()
  {
    // Register custom post types from JSON file at priority 0 (early)
    add_action("init", [CustomPostTypes::class, "register_custom_post_types"], 0);
  }

  /**
   * Initialize custom fields functionality
   * This sets up all the hooks for custom fields across all WordPress contexts
   */
  private function init_custom_fields()
  {
    // Initialize the custom fields loader on init hook after post types are registered
    add_action("init", function() {
      $loader = CustomFieldsLoader::instance();
      $loader->init();
    }, 20);
  }

  /**
   * Sets up the admin page by adding it to the uiXPress menu
   *
   * @return void
   */
  public function setup_admin_page()
  {
    if (!current_user_can("manage_options")) {
      return;
    }

    // Check if custom post types is enabled
    if (!Settings::is_enabled("enable_custom_post_types")) {
      return;
    }

    $menu_name = __("Post Types", "uixpress");
   
    $hook_suffix = add_submenu_page(
      "uipx-settings",
      $menu_name,
      $menu_name,
      "manage_options",
      "uipx-custom-post-types",
      [$this, "render_page"]
    );

    $cf_menu_name = __("Fields groups", "uixpress");
    add_submenu_page(
      "uipx-settings",
      $cf_menu_name,
      $cf_menu_name,
      "manage_options",
      "uipx-custom-post-types#/custom-fields/",
      [$this, "render_page"]
    );

    add_action("admin_head-{$hook_suffix}", [$this, "load_styles"]);
    add_action("admin_head-{$hook_suffix}", [$this, "load_scripts"]);
  }

  /**
   * Loads custom post types styles
   *
   * @return void
   */
  public static function load_styles()
  {
    $url = plugins_url("uixpress/");
    $style = $url . "app/dist/assets/styles/custom-post-types.css";
    wp_enqueue_style("uixpress-custom-post-types", $style, [], uixpress_plugin_version);
  }

  /**
   * Loads custom post types scripts
   *
   * @return void
   */
  public static function load_scripts()
  {
    $url = plugins_url("uixpress/");
    $script_name = Scripts::get_base_script_path("CustomPostTypes.js");

    if (!$script_name) {
      return;
    }

    // Get plugin settings
    $options = Settings::get();
    $plugin_name = Settings::get_setting("plugin_name", "uiXPress");
    $plugin_name = $plugin_name != "" ? esc_html($plugin_name) : "uiXPress";

    // Get current user and escape user data for security
    $current_user = wp_get_current_user();
    if (!$current_user || !$current_user->exists()) {
      return;
    }

    wp_print_script_tag([
      "id" => "uipc-custom-post-types-script",
      "src" => $url . "app/dist/{$script_name}",
      "plugin-base" => esc_url($url),
      "rest-base" => esc_url(rest_url()),
      "rest-nonce" => wp_create_nonce("wp_rest"),
      "admin-url" => esc_url(admin_url()),
      "site-url" => esc_url(site_url()),
      "user-id" => absint($current_user->ID),
      "user-name" => esc_attr($current_user->display_name),
      "user-email" => esc_attr($current_user->user_email),
      "uixpress-settings" => wp_json_encode($options),
      "type" => "module",
    ]);
  }

  /**
   * Renders the custom post types page content
   *
   * @return void
   */
  public function render_page()
  {
    if (!current_user_can("manage_options")) {
      wp_die(__("You do not have sufficient permissions to access this page."));
    }
    ?>
    <div id="uix-custom-post-types-page"></div>
    <?php
  }
}

