<?php
namespace UiXpress\App;
use UiXpress\App\UiXpress;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class uixpress
 *
 * Main class for initialising the uixpress app.
 */
class UiXpressFrontEnd
{
  private static $screen = null;
  private static $options = [];
  private static $script_name = false;
  private static $plugin_url = false;

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

    if (!is_admin_bar_showing()) {
      return;
    }

    if (!is_admin() && !is_login() && stripos($currentURL, wp_login_url()) === false && stripos($currentURL, admin_url()) === false) {
      add_action("wp_enqueue_scripts", ["UiXpress\App\UiXpress", "output_script_attributes"]);
      add_action("wp_enqueue_scripts", [$this, "load_toolbar_script"]);
      add_action("wp_head", [$this, "push_temporary_css"]);
    }
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

  /**
   * Loads the toolbar script
   *
   * @return string
   * @since 3.2.13
   */
  public static function load_toolbar_script()
  {
    // Get plugin url
    $plugin_url = plugins_url("uixpress/");
    $script_name = Scripts::get_base_script_path("Frontend.js");

    if (!$script_name) {
      return;
    }

    // Setup script object
    $builderScript = [
      "id" => "uixpress-app-js",
      "type" => "module",
      "src" => $plugin_url . "app/dist/{$script_name}",
    ];
    wp_print_script_tag($builderScript);

    // Set up translations
    wp_enqueue_script("uixpress", $plugin_url . "assets/js/translations.js", ["wp-i18n"], false);
    wp_set_script_translations("uixpress", "uixpress", uixpress_plugin_path . "/languages/");

    // Get plugin url
    $style = $plugin_url . "app/dist/assets/styles/frontend.css";
    wp_enqueue_style("uixpress-frontend", $style, [], uixpress_plugin_version);
  }

  /**
   * Returns the current URL
   *
   * @return string
   * @since 3.2.13
   */
  public static function push_temporary_css()
  {
    ?>
	  <style id="uix-temp-style">
	  #wpadminbar {
		  opacity: 0;
		}
	  </style>
	  <?php
  }
}
