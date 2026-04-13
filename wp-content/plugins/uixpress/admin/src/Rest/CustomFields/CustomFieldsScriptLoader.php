<?php
namespace UiXpress\Rest\CustomFields;

use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomFieldsScriptLoader
 *
 * Centralized script and style loading for custom fields across all contexts
 */
class CustomFieldsScriptLoader
{
  /**
   * @var bool Track if scripts have been loaded to prevent duplicate loading
   */
  private static $scripts_loaded = false;

  /**
   * @var bool Track if styles have been loaded to prevent duplicate loading
   */
  private static $styles_loaded = false;

  /**
   * Load the custom fields Vue.js script
   * Can be called multiple times safely - will only load once per request
   *
   * @return bool Whether script was loaded
   */
  public static function load_script()
  {
    if (self::$scripts_loaded) {
      return true;
    }

    $url = plugins_url("uixpress/");
    $script_name = Scripts::get_base_script_path("CustomFieldsMetaBox.js");
 
    if (!$script_name) {
      return false;
    }

    // Print script tag with module type
    wp_print_script_tag([
      "id" => "uixpress-custom-fields-meta-box-script",
      "src" => $url . "app/dist/{$script_name}",
      "type" => "module",
    ]);

    self::$scripts_loaded = true;
    return true;
  }

  /**
   * Load the custom fields stylesheet
   * Can be called multiple times safely - will only load once per request
   *
   * @return bool Whether style was loaded
   */
  public static function load_styles()
  {
    if (self::$styles_loaded) {
      return true;
    }

    $url = plugins_url("uixpress/");
    $style = $url . "app/dist/assets/styles/custom-fields-meta-box.css";
    wp_enqueue_style("uixpress-custom-fields-meta-box", $style, [], null);

    self::$styles_loaded = true;
    return true;
  }

  /**
   * Load both script and styles
   *
   * @return bool Whether assets were loaded
   */
  public static function load_assets()
  {
    self::load_styles();
    return self::load_script();
  }

  /**
   * Check if scripts have been loaded
   *
   * @return bool
   */
  public static function scripts_loaded()
  {
    return self::$scripts_loaded;
  }

  /**
   * Check if styles have been loaded
   *
   * @return bool
   */
  public static function styles_loaded()
  {
    return self::$styles_loaded;
  }

  /**
   * Reset loading state (useful for testing)
   */
  public static function reset()
  {
    self::$scripts_loaded = false;
    self::$styles_loaded = false;
  }
}

