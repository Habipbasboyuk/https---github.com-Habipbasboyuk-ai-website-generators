<?php
namespace UiXpress\Update;

use UiXpress\Options\Settings;

// Prevent direct access to this file
!defined("ABSPATH") ? exit() : "";

class Updater
{
  private static $version = uixpress_plugin_version;
  private static $transient = "uixpress-update-transient";
  private static $transientFailed = "uixpress-failed-transient";
  private static $updateURL = "https://accounts.uipress.co/api/v1/uixpress/update";
  private static $expiry = 1 * HOUR_IN_SECONDS;
  private static $timeout = 10;

  /**
   * Adds actions and filters to update hooks
   *
   * @since 2.2.0
   */
  public function __construct()
  {
    add_filter("plugins_api", ["UiXpress\Update\Updater", "plugin_info"], 20, 3);
    add_filter("site_transient_update_plugins", ["UiXpress\Update\Updater", "push_update"]);
    add_action("upgrader_process_complete", ["UiXpress\Update\Updater", "after_update"], 10, 2);
  }

  /**
   * Fetches plugin update info
   *
   * @param object $res
   * @param string $action
   * @param object $args
   * @return object
   * @since 2.2.0
   */
  public static function plugin_info($res, $action, $args)
  {
    if ("plugin_information" !== $action) {
      return $res;
    }

    if (true == get_transient(self::$transientFailed)) {
      return $res;
    }

    $plugin_slug = "uixpress";

    if ($plugin_slug !== $args->slug) {
      return $res;
    }

    $remote = self::get_remote_data("plugin_information");

    if (is_wp_error($remote)) {
      return $res;
    }

    $remote = json_decode($remote["body"], true);

    // Check for error response
    if (isset($remote["error"]) && $remote["error"] === true) {
      return $res;
    }

    $res = new \stdClass();

    $res->name = isset($remote["name"]) ? $remote["name"] : "uiXpress";
    $res->slug = $plugin_slug;
    $res->version = isset($remote["version"]) ? $remote["version"] : self::$version;
    $res->tested = isset($remote["tested"]) ? $remote["tested"] : "6.4";
    $res->requires = isset($remote["requires"]) ? $remote["requires"] : "6.0";
    $res->download_link = isset($remote["download_link"]) ? $remote["download_link"] : null;
    $res->trunk = isset($remote["download_link"]) ? $remote["download_link"] : null;
    $res->requires_php = isset($remote["requires_php"]) ? $remote["requires_php"] : "7.4";
    $res->last_updated = isset($remote["last_updated"]) ? $remote["last_updated"] : "";
    
    // Handle sections
    $res->sections = [];
    if (isset($remote["sections"])) {
      if (isset($remote["sections"]["description"])) {
        $res->sections["description"] = $remote["sections"]["description"];
      }
      if (isset($remote["sections"]["changelog"])) {
        $res->sections["changelog"] = $remote["sections"]["changelog"];
      }
      if (isset($remote["sections"]["installation"])) {
        $res->sections["installation"] = $remote["sections"]["installation"];
      }
      if (isset($remote["sections"]["screenshots"])) {
        $res->sections["screenshots"] = $remote["sections"]["screenshots"];
      }
    }

    // Handle banners
    $res->banners = [];
    if (isset($remote["banners"])) {
      if (isset($remote["banners"]["low"])) {
        $res->banners["low"] = $remote["banners"]["low"];
      }
      if (isset($remote["banners"]["high"])) {
        $res->banners["high"] = $remote["banners"]["high"];
      }
    }

    return $res;
  }

  /**
   * Retrieves remote data from the update URL
   *
   * @param string $action The action type (plugin_update_check or plugin_information)
   * @return array|WP_Error
   * @since 3.2.0
   */
  private static function get_remote_data($action = "plugin_update_check")
  {
    // Build query parameters
    $query_args = [
      "action" => $action,
      "slug" => "uixpress",
      "version" => self::$version,
    ];

    // Get license key from plugin options
    $license_key = self::get_license_key();
    if (!empty($license_key)) {
      $query_args["license_key"] = $license_key;
    }

    // Build URL with query parameters
    $url = add_query_arg($query_args, self::$updateURL);

    // Use transient key specific to action and version for better caching
    $transient_key = self::$transient . "-" . $action . "-" . self::$version;

    if (false == ($remote = get_transient($transient_key))) {
      $remote = wp_remote_get($url, [
        "timeout" => self::$timeout,
        "headers" => [
          "Accept" => "application/json",
        ],
      ]);

      if (self::is_response_clean($remote)) {
        set_transient($transient_key, $remote, self::$expiry);
      } else {
        set_transient(self::$transientFailed, true, self::$expiry);
        return new \WP_Error("remote_error", "Failed to retrieve remote data.");
      }
    } else {
      $remote = get_transient($transient_key);
      if (!self::is_response_clean($remote)) {
        set_transient(self::$transientFailed, true, self::$expiry);
        return new \WP_Error("cache_error", "Failed to retrieve data from cache.");
      }
    }

    return $remote;
  }

  /**
   * Gets the license key from plugin options
   *
   * @return string License key or empty string
   * @since 3.2.0
   */
  private static function get_license_key()
  {
    // Get license key from global options using Settings class
    $license_key = Settings::get_setting("license_key", "");

    // Allow filtering the license key
    return apply_filters("uixpress_license_key", $license_key);
  }

  /**
   * Checks if the response is clean and valid
   *
   * @param object $status
   * @return bool
   * @since 3.2.0
   */
  private static function is_response_clean($status)
  {
    if (isset($status->errors)) {
      return false;
    }

    if (isset($status["response"]["code"]) && $status["response"]["code"] != 200) {
      return false;
    }

    if (is_wp_error($status)) {
      return false;
    }

    return true;
  }

  /**
   * Pushes plugin update to the plugin table
   *
   * @param object $transient
   * @return object
   * @since 1.4
   */
  public static function push_update($transient)
  {
    if (empty($transient->checked)) {
      return $transient;
    }

    if (true == get_transient(self::$transientFailed)) {
      return $transient;
    }

    $remote = self::get_remote_data("plugin_update_check");

    if (is_wp_error($remote)) {
      return $transient;
    }

    $remote = json_decode($remote["body"], true);

    // Check for error response
    if (isset($remote["error"]) && $remote["error"] === true) {
      return $transient;
    }

    // The API returns data keyed by plugin file path
    $plugin_file = "uixpress/uixpress.php";
    
    if (isset($remote[$plugin_file])) {
      $update_data = $remote[$plugin_file];
      
      // Check if update is available
      if (
        isset($update_data["new_version"]) &&
        version_compare(self::$version, $update_data["new_version"], "<")
      ) {
        $res = new \stdClass();
        $res->id = isset($update_data["id"]) ? $update_data["id"] : $plugin_file;
        $res->slug = isset($update_data["slug"]) ? $update_data["slug"] : "uixpress";
        $res->plugin = $plugin_file;
        $res->new_version = $update_data["new_version"];
        $res->tested = isset($update_data["tested"]) ? $update_data["tested"] : "6.4";
        $res->requires = isset($update_data["requires"]) ? $update_data["requires"] : "6.0";
        $res->requires_php = isset($update_data["requires_php"]) ? $update_data["requires_php"] : "7.4";
        $res->package = isset($update_data["package"]) ? $update_data["package"] : null;
        $res->url = isset($update_data["url"]) ? $update_data["url"] : "https://uipress.co";
        $res->compatibility = isset($update_data["compatibility"]) ? (object) $update_data["compatibility"] : new \stdClass();
        
        $transient->response[$res->plugin] = $res;
      } else {
        // If there's no update, add the plugin to the 'no_update' list
        $transient->no_update[$plugin_file] = (object) self::getNoUpdateItemFields();
      }
    } else {
      // If response doesn't contain our plugin, add to no_update
      $transient->no_update[$plugin_file] = (object) self::getNoUpdateItemFields();
    }

    return $transient;
  }

  /**
   * Get fields for the no update item
   *
   * @return array
   */
  private static function getNoUpdateItemFields()
  {
    return [
      "new_version" => self::$version,
      "url" => "", // You can add a URL to your plugin's page if you want
      "package" => "",
      "requires_php" => "7.4", // Adjust this to your plugin's PHP requirement
      "requires" => "5.0", // Adjust this to your plugin's WordPress requirement
      "icons" => [], // Add icons if you have them
      "banners" => [], // Add banners if you have them
      "banners_rtl" => [], // Add RTL banners if you have them
      "tested" => "6.2", // Adjust this to the latest WordPress version you've tested with
      "id" => "uixpress/uixpress.php",
    ];
  }

  /**
   * Cleans cache after update
   *
   * @param object $upgrader_object
   * @param array $options
   * @since 1.4
   */
  public static function after_update($upgrader_object, $options)
  {
    if ($options["action"] == "update" && $options["type"] === "plugin") {
      // Clear all update transients after successful update
      delete_transient(self::$transient . "-plugin_update_check-" . self::$version);
      delete_transient(self::$transient . "-plugin_information-" . self::$version);
      delete_transient(self::$transientFailed);
    }
  }
}
