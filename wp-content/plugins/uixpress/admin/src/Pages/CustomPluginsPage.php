<?php

namespace UiXpress\Pages;

use UiXpress\Options\Settings;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomPluginsPage
 *
 * Handles the replacement of the default WordPress plugins page with a custom implementation
 */
class CustomPluginsPage
{
  /** @var array */
  private static $options;

  /**
   * CustomPluginsPage constructor.
   *
   * Initializes the custom plugins page functionality
   */
  public function __construct()
  {
    add_action("admin_menu", [$this, "setup_admin_page"], 99);
    //add_action("admin_init", [$this, "handle_redirects"]);
  }

  /**
   * Sets up the admin page by removing default plugins page and adding custom one
   *
   * @return void
   */
  public function setup_admin_page()
  {
    if (!current_user_can("manage_options")) {
      return;
    }

    // Modern plugin page is not enabled
    if (!Settings::is_enabled("use_modern_plugin_page")) {
      return;
    }

    // Remove original plugins menu
    remove_menu_page("plugins.php");
    remove_submenu_page("plugins.php", "plugin-install.php");
    remove_submenu_page("plugins.php", "plugins.php");

    // Get plugin updates count
    $update_plugins = get_plugin_updates();
    $update_count = count($update_plugins);

    // Create menu title with update count
    $menu_title = __("Plugins", "uixpress");
    if ($update_count > 0) {
      $menu_title .= sprintf('<span class="update-plugins count-%d"><span class="plugin-count">%d</span></span>', $update_count, $update_count);
    }

    // Add custom plugins page
    $hook_suffix = add_menu_page("Custom Plugins", $menu_title, "manage_options", "plugin-manager", [$this, "render_page"], "dashicons-admin-plugins", 65);
    add_action("admin_head-{$hook_suffix}", [$this, "load_styles"]);
    add_action("admin_head-{$hook_suffix}", [$this, "load_scripts"]);
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
    $style = $url . "app/dist/assets/styles/plugins.css";
    wp_enqueue_style("uixpress-plugins", $style, [], uixpress_plugin_version);

    add_filter('uixpress/style-layering/exclude', function($excluded_patterns) use ($style) {
      $excluded_patterns[] = $style;
      return $excluded_patterns;
    });
  }

  /**
   * uixpress scripts.
   *
   * Loads main lp scripts
   */
  public static function load_scripts()
  {
    $url = plugins_url("uixpress/");
    $script_name = Scripts::get_base_script_path("Plugins.js");
    $installed_plugins = get_plugins();
    $formatted_plugins = [];
    $plugin_updates = get_plugin_updates();
    $auto_updates = (array) get_site_option("auto_update_plugins", []);

    foreach ($installed_plugins as $plugin_path => $plugin_data) {
      $is_active = is_plugin_active($plugin_path);

      $plugin_data["active"] = $is_active;
      $plugin_data["has_update"] = array_key_exists($plugin_path, $plugin_updates);
      $plugin_data["auto_update_enabled"] = in_array($plugin_path, $auto_updates);

      // Short slug
      $slug_parts = explode("/", $plugin_path);
      $base_slug = $slug_parts[0];
      $plugin_data["splitSlug"] = $base_slug;
      $plugin_data["slug"] = $plugin_path;

      if ($plugin_data["has_update"]) {
        if (isset($plugin_updates[$plugin_path]->update->new_version)) {
          $plugin_data["new_version"] = $plugin_updates[$plugin_path]->update->new_version;
        }
      }

      if ($is_active) {
        $action_links = apply_filters("plugin_action_links_" . $plugin_path, [], $plugin_path, $plugin_data, "");
        $row_meta = apply_filters("plugin_row_meta", [], $plugin_path, $plugin_data, "");

        $cleaned_links = array_reduce(
          array_merge($action_links, $row_meta),
          function ($links, $link) {
            if (preg_match('/<a.*?href=["\'](.*?)["\'].*?>(.*?)<\/a>/i', $link, $matches)) {
              $links[] = [
                "url" => $matches[1],
                "text" => strip_tags($matches[2]),
                "type" => strpos($link, "settings") !== false ? "settings" : (strpos($link, "documentation") !== false ? "documentation" : "other"),
              ];
            }
            return $links;
          },
          []
        );

        $plugin_data["action_links"] = $cleaned_links;
      }

      $formatted_plugins[$plugin_path] = $plugin_data;
    }

    wp_print_script_tag([
      "id" => "uipc-plugins-script",
      "src" => $url . "app/dist/{$script_name}",
      //"plugins" => esc_attr(json_encode($formatted_plugins)),
      "type" => "module",
    ]);
  }

  /**
   * Handles redirects from the original plugins.php to our custom page
   *
   * @return void
   */
  public function handle_redirects()
  {
    global $pagenow;

    if ($pagenow === "plugins.php" && !isset($_GET["action"])) {
      wp_redirect(admin_url("admin.php?page=plugin-manager"));
      exit();
    }
  }

  /**
   * Renders the custom plugins page content
   *
   * @return void
   */
  public function render_page()
  {
    if (!current_user_can("manage_options")) {
      wp_die(__("You do not have sufficient permissions to access this page."));
    } ?>
    <div id="uix-plugins-page"></div>
<?php
  }
}
