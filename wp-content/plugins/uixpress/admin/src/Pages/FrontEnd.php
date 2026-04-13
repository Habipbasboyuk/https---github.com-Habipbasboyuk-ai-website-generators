<?php
namespace UiXpress\Pages;

use UiXpress\Options\Settings;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class FrontEnd
 *
 * Main class for initialising the uixpress app.
 */
class FrontEnd
{
  /**
   * uixpress constructor.
   *
   * Initialises the main app.
   */
  public function __construct()
  {
    add_action("init", [$this, "maybe_load_actions"]);
  }

  /**
   * Outputs head actions for site settings
   *
   * @since 3.0.94
   */
  public function maybe_load_actions()
  {
    $currentURL = self::current_url();
    if (!is_admin() && !is_login() && stripos($currentURL, wp_login_url()) === false && stripos($currentURL, admin_url()) === false) {
      add_action("wp_enqueue_scripts", ["UiXpress\Pages\FrontEnd", "load_toolbar_styles"]);
      add_action("admin_bar_menu", [$this, "logo_actions"]);
    }
  }

  /**
   * Outputs head actions for site settings
   *
   * @since 3.0.94
   */
  public function logo_actions($admin_bar)
  {
    $dark_logo = Settings::get_setting("dark_logo", "");
    $dark_logo = $dark_logo != "" ? esc_html($dark_logo) : false;

    // No logo set so bail
    if (!$dark_logo) {
      return;
    }

    $image = esc_url($dark_logo);
    $data = "<img style='height:20px;max-height:20px;margin-top:6px;vertical-align:baseline;' src='{$image}' >";

    $args = [
      "id" => "app-logo",
      "title" => $data,
      "href" => esc_url(admin_url()),
    ];
    $admin_bar->add_node($args);
  }

  /**
   * Loads frontend styles
   *
   * @return void
   */
  public static function load_toolbar_styles()
  {
    if (!is_admin_bar_showing()) {
      return;
    }

    // Get plugin url
    $url = plugins_url("uixpress/");
    $style = $url . "app/dist/assets/styles/frontend.css";
    wp_enqueue_style("uixpress-frontend", $style, [], uixpress_plugin_version);
  }

  /**
   * Returns the current URL
   *
   * @return string
   * @since 3.2.13
   */
  private static function current_url()
  {
    if (!defined("WP_CLI") && isset($_SERVER["HTTP_HOST"])) {
      $protocol = is_ssl() ? "https://" : "http://";
      return $protocol . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
    }
    return "";
  }
}
