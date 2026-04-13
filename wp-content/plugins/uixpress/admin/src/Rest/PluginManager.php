<?php

namespace UiXpress\Rest;

use UiXpress\Rest\PluginMetricsCollector;

// Prevent direct access to this file
defined("ABSPATH") || exit();

// Include required files for the upgrader
require_once ABSPATH . "wp-admin/includes/class-wp-upgrader.php";
require_once ABSPATH . "wp-admin/includes/update.php";
require_once ABSPATH . "wp-admin/includes/plugin.php";
require_once ABSPATH . "wp-admin/includes/file.php";
require_once ABSPATH . "wp-admin/includes/misc.php";

/**
 * Custom upgrader skin that doesn't require user input
 */
class Silent_Upgrader_Skin extends \WP_Upgrader_Skin
{
  protected $errors = null;

  public function __construct()
  {
    $this->errors = new \WP_Error();
  }

  public function request_filesystem_credentials($error = false, $context = "", $allow_relaxed_file_ownership = false)
  {
    return true;
  }

  public function get_upgrade_messages()
  {
    return [];
  }

  public function feedback($string, ...$args) {}

  public function header() {}

  public function footer() {}

  public function error($errors)
  {
    if (is_string($errors)) {
      $this->errors->add("unknown", $errors);
    } elseif (is_wp_error($errors)) {
      foreach ($errors->get_error_codes() as $code) {
        $this->errors->add($code, $errors->get_error_message($code), $errors->get_error_data($code));
      }
    }
  }

  public function get_errors()
  {
    return $this->errors;
  }
}

/**
 * Class PluginManager
 *
 * Creates new REST API endpoints to manage WordPress plugins
 */
class PluginManager
{
  /**
   * Constructor - registers REST API endpoints
   */
  public function __construct()
  {
    add_action("rest_api_init", ["UiXpress\Rest\PluginManager", "register_custom_endpoints"]);
    new PluginMetricsCollector();
  }

  /**
   * Registers custom endpoints for plugin management
   */
  public static function register_custom_endpoints()
  {
    // Common slug argument schema
    $slug_arg_schema = [
      'required' => true,
      'validate_callback' => function ($param, $request, $key) {
        return is_string($param) && !empty($param) && preg_match('/^[a-zA-Z0-9-_]+$/', $param);
      },
      'sanitize_callback' => 'sanitize_text_field',
      'description' => 'Plugin slug identifier'
    ];

    // Endpoint for plugin activation
    register_rest_route('uixpress/v1', '/plugin/activate/(?P<slug>[a-zA-Z0-9-_]+)', [
      'methods' => 'POST',
      'callback' => ['UiXpress\Rest\PluginManager', 'activate_plugin'],
      'permission_callback' => ['UiXpress\Rest\PluginManager', 'check_permissions'],
      'args' => [
        'slug' => $slug_arg_schema,
      ],
    ]);

    // Endpoint for plugin deactivation
    register_rest_route('uixpress/v1', '/plugin/deactivate/(?P<slug>[a-zA-Z0-9-_]+)', [
      'methods' => 'POST',
      'callback' => ['UiXpress\Rest\PluginManager', 'deactivate_plugin'],
      'permission_callback' => ['UiXpress\Rest\PluginManager', 'check_permissions'],
      'args' => [
        'slug' => $slug_arg_schema,
      ],
    ]);

    // Endpoint for plugin deletion
    register_rest_route('uixpress/v1', '/plugin/delete/(?P<slug>[a-zA-Z0-9-_]+)', [
      'methods' => 'DELETE',
      'callback' => ['UiXpress\Rest\PluginManager', 'delete_plugin'],
      'permission_callback' => ['UiXpress\Rest\PluginManager', 'check_permissions'],
      'args' => [
        'slug' => $slug_arg_schema,
      ],
    ]);

    // Endpoint for plugin update
    register_rest_route('uixpress/v1', '/plugin/update/(?P<slug>[a-zA-Z0-9-_]+)', [
      'methods' => 'POST',
      'callback' => ['UiXpress\Rest\PluginManager', 'update_plugin'],
      'permission_callback' => ['UiXpress\Rest\PluginManager', 'check_permissions'],
      'args' => [
        'slug' => $slug_arg_schema,
      ],
    ]);

    // Endpoint for plugin auto updates
    register_rest_route('uixpress/v1', '/plugin/toggle-auto-update/(?P<slug>[a-zA-Z0-9-_]+)', [
      'methods' => 'POST',
      'callback' => ['UiXpress\Rest\PluginManager', 'toggle_auto_update'],
      'permission_callback' => ['UiXpress\Rest\PluginManager', 'check_permissions'],
      'args' => [
        'slug' => $slug_arg_schema,
      ],
    ]);

    // Endpoint for plugin installation from ZIP
    register_rest_route('uixpress/v1', '/plugin/install', [
      "methods" => "POST",
      "callback" => ["UiXpress\Rest\PluginManager", "install_plugin_from_zip"],
      "permission_callback" => ["UiXpress\Rest\PluginManager", "check_permissions"],
      "accept_file_uploads" => true,
    ]);

    // Endpoint for plugin installation from repository
    register_rest_route('uixpress/v1', '/plugin/install-repo/(?P<slug>[a-zA-Z0-9-_]+)', [
      'methods' => 'POST',
      'callback' => ['UiXpress\Rest\PluginManager', 'install_plugin_from_repo'],
      'permission_callback' => ['UiXpress\Rest\PluginManager', 'check_permissions'],
      'args' => [
        'slug' => $slug_arg_schema,
        'version' => [
          'required' => false,
          'validate_callback' => function ($param, $request, $key) {
            return empty($param) || (is_string($param) && preg_match('/^[\d\.]+$/', $param));
          },
          'sanitize_callback' => 'sanitize_text_field',
          'description' => 'Specific version to install (optional)'
        ],
      ],
    ]);

    // Endpoint for querying plugin performance metrics
    register_rest_route('uixpress/v1', '/plugins/performance/(?P<slug>[a-zA-Z0-9-_]+)', [
      'methods' => 'GET',
      'callback' => ['UiXpress\Rest\PluginManager', 'get_plugin_performance_metrics'],
      'permission_callback' => function ($request) {
        return RestPermissionChecker::check_permissions($request, 'manage_options');
      },
      'args' => [
        'slug' => [
          'required' => false,
          'validate_callback' => function ($param, $request, $key) {
            return empty($param) || (is_string($param) && preg_match('/^[a-zA-Z0-9-_]+$/', $param));
          },
          'sanitize_callback' => 'sanitize_text_field',
          'description' => 'Plugin slug to get metrics for (optional)'
        ],
        'timeframe' => [
          'required' => false,
          'validate_callback' => function ($param, $request, $key) {
            $valid_timeframes = ['hour', 'day', 'week', 'month'];
            return empty($param) || in_array($param, $valid_timeframes, true);
          },
          'sanitize_callback' => 'sanitize_text_field',
          'default' => 'day',
          'description' => 'Timeframe for metrics (hour, day, week, month)'
        ],
      ],
    ]);

    // Endpoint for fetching all plugins list
    register_rest_route('uixpress/v1', '/plugins', [
      'methods' => 'GET',
      'callback' => ['UiXpress\Rest\PluginManager', 'get_plugins_list'],
      'permission_callback' => function ($request) {
        return RestPermissionChecker::check_permissions($request, 'activate_plugins');
      },
    ]);
  }

  /**
   * Check if user has required permissions
   *
   * @param \WP_REST_Request $request The REST request object
   * @return bool|WP_Error True if user has permission, WP_Error otherwise
   */
  public static function check_permissions($request = null)
  {
    if (!$request) {
      return new \WP_Error('rest_forbidden', __('Invalid request.', 'uixpress'), ['status' => 400]);
    }
    
    return RestPermissionChecker::check_permissions($request, ['activate_plugins', 'delete_plugins']);
  }

  /**
   * Activate a plugin
   *
   * @param WP_REST_Request $request
   * @return WP_REST_Response
   */
  public static function activate_plugin($request)
  {
    // Set a flag to indicate we're in a REST request
    if (!defined("REST_REQUEST")) {
      define("REST_REQUEST", true);
    }

    // Force WordPress to think this is an AJAX request
    if (!defined("DOING_AJAX")) {
      define("DOING_AJAX", true);
    }

    $plugin_slug = $request->get_param("slug");
    $plugin_file = self::get_plugin_file($plugin_slug);
    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . "/" . $plugin_file);

    if (!$plugin_file) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Plugin not found",
        ],
        404
      );
    }

    // Prevents any html from redirects being returned in the response
    ob_start();

    $result = activate_plugin($plugin_file);

    // Clean up buffer
    ob_end_clean();

    if (is_wp_error($result)) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => $result->get_error_message(),
        ],
        500
      );
    }

    // Get the default action links (like "Settings", "Deactivate")
    $action_links = apply_filters("plugin_action_links_" . $plugin_file, [], $plugin_file, $plugin_data, "");

    // Get additional meta links (like "View details", "Documentation", etc)
    $row_meta = apply_filters("plugin_row_meta", [], $plugin_file, $plugin_data, "");

    // Combine all links and clean them up
    $all_links = array_merge($action_links, $row_meta);
    $cleaned_links = [];

    foreach ($all_links as $link) {
      // Extract URL and text from HTML link
      if (preg_match('/<a.*?href=["\'](.*?)["\'].*?>(.*?)<\/a>/i', $link, $matches)) {
        $cleaned_links[] = [
          "url" => $matches[1],
          "text" => strip_tags($matches[2]),
          "type" => strpos($link, "settings") !== false ? "settings" : (strpos($link, "documentation") !== false ? "documentation" : "other"),
        ];
      }
    }

    return new \WP_REST_Response(
      [
        "success" => true,
        "message" => "Plugin activated successfully",
        "action_links" => $cleaned_links,
      ],
      200
    );
  }

  /**
   * Deactivate a plugin
   *
   * @param WP_REST_Request $request
   * @return WP_REST_Response
   */
  public static function deactivate_plugin($request)
  {
    $plugin_slug = $request->get_param("slug");
    $plugin_file = self::get_plugin_file($plugin_slug);

    if (!$plugin_file) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Plugin not found",
        ],
        404
      );
    }

    deactivate_plugins($plugin_file);

    if (is_plugin_active($plugin_file)) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Failed to deactivate plugin",
        ],
        500
      );
    }

    return new \WP_REST_Response(
      [
        "success" => true,
        "message" => "Plugin deactivated successfully",
      ],
      200
    );
  }

  /**
   * Delete a plugin
   *
   * @param WP_REST_Request $request
   * @return WP_REST_Response
   */
  public static function delete_plugin($request)
  {
    $plugin_slug = $request->get_param("slug");
    $plugin_file = self::get_plugin_file($plugin_slug);

    if (!$plugin_file) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Plugin not found",
        ],
        404
      );
    }

    // Deactivate plugin first
    deactivate_plugins($plugin_file);

    // Delete plugin
    $result = delete_plugins([$plugin_file]);

    if (is_wp_error($result)) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => $result->get_error_message(),
        ],
        500
      );
    }

    return new \WP_REST_Response(
      [
        "success" => true,
        "message" => "Plugin deleted successfully",
      ],
      200
    );
  }

  /**
   * Update a plugin
   *
   * @param WP_REST_Request $request
   * @return WP_REST_Response
   */
  public static function update_plugin($request)
  {
    $plugin_slug = $request->get_param("slug");
    $plugin_file = self::get_plugin_file($plugin_slug);

    if (!$plugin_file) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Plugin not found",
        ],
        404
      );
    }

    // Store the active status before update
    $was_active = is_plugin_active($plugin_file);

    // Check if update is available
    wp_update_plugins(); // Check for plugin updates
    $update_plugins = get_site_transient("update_plugins");

    if (!isset($update_plugins->response[$plugin_file])) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "No update available for this plugin",
        ],
        400
      );
    }

    // Initialize WordPress filesystem
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
      WP_Filesystem();
    }

    // Prepare the upgrader with our custom skin
    $skin = new Silent_Upgrader_Skin();
    $upgrader = new \Plugin_Upgrader($skin);

    // Perform the update
    $result = $upgrader->upgrade($plugin_file);

    // Check for errors
    if (is_wp_error($result)) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => $result->get_error_message(),
        ],
        500
      );
    }

    // Get error messages if any
    $errors = $skin->get_errors();
    if ($errors && $errors->has_errors()) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => $errors->get_error_message(),
        ],
        500
      );
    }

    if (false === $result) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Plugin update failed",
        ],
        500
      );
    }

    // Reactivate the plugin if it was active before the update
    if ($was_active) {
      $activate_result = activate_plugin($plugin_file);
      if (is_wp_error($activate_result)) {
        return new \WP_REST_Response(
          [
            "success" => true,
            "message" => "Plugin updated successfully but reactivation failed: " . $activate_result->get_error_message(),
          ],
          200
        );
      }
    }

    return new \WP_REST_Response(
      [
        "success" => true,
        "message" => "Plugin updated successfully" . ($was_active ? " and reactivated" : ""),
      ],
      200
    );
  }

  /**
   * Toggle auto updates for a plugin
   *
   * @param WP_REST_Request $request
   * @return WP_REST_Response
   */
  public static function toggle_auto_update($request)
  {
    $plugin_slug = $request->get_param("slug");
    $plugin_file = self::get_plugin_file($plugin_slug);

    if (!$plugin_file) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Plugin not found",
        ],
        404
      );
    }

    // Get current auto update settings
    $auto_updates = (array) get_site_option("auto_update_plugins", []);

    // Check if plugin is currently set to auto update
    $auto_update_enabled = in_array($plugin_file, $auto_updates);

    if ($auto_update_enabled) {
      // Remove from auto updates
      $auto_updates = array_diff($auto_updates, [$plugin_file]);
      $message = "Auto updates disabled";
    } else {
      // Add to auto updates
      $auto_updates[] = $plugin_file;
      $message = "Auto updates enabled";
    }

    // Update the option
    $update_result = update_site_option("auto_update_plugins", array_values($auto_updates));

    if (!$update_result) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Failed to update auto update settings",
        ],
        500
      );
    }

    return new \WP_REST_Response(
      [
        "success" => true,
        "message" => $message,
        "auto_update_enabled" => !$auto_update_enabled, // Return the new state
      ],
      200
    );
  }

  /**
   * Install a plugin from a ZIP file (with update support)
   *
   * @param WP_REST_Request $request
   * @return WP_REST_Response
   */
  public static function install_plugin_from_zip($request)
  {
    ob_start();

    // Check if file was uploaded
    $files = $request->get_file_params();

    if (empty($files["plugin_zip"])) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "No plugin file uploaded",
        ],
        400
      );
    }

    $file = $files["plugin_zip"];

    // Verify it's a ZIP file
    $file_type = wp_check_filetype($file["name"], ["zip" => "application/zip"]);
    if ($file_type["type"] !== "application/zip") {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Invalid file type. Please upload a ZIP file.",
        ],
        400
      );
    }

    // Initialize WordPress filesystem
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
      WP_Filesystem();
    }

    // Extract the ZIP to get plugin info without installing
    $temp_dir = wp_upload_dir()['basedir'] . '/temp-plugin-' . time();
    $extract_result = unzip_file($file["tmp_name"], $temp_dir);

    if (is_wp_error($extract_result)) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Failed to extract plugin ZIP: " . $extract_result->get_error_message(),
        ],
        500
      );
    }

    // Find the main plugin file to get the slug
    $plugin_files = $wp_filesystem->dirlist($temp_dir);
    $plugin_folder = null;
    $main_plugin_file = null;

    foreach ($plugin_files as $file_name => $file_info) {
      if ($file_info['type'] == 'd') {
        $plugin_folder = $file_name;
        // Look for PHP files in the plugin folder
        $plugin_folder_files = $wp_filesystem->dirlist($temp_dir . '/' . $file_name);
        foreach ($plugin_folder_files as $php_file => $php_info) {
          if (substr($php_file, -4) === '.php') {
            $php_content = $wp_filesystem->get_contents($temp_dir . '/' . $file_name . '/' . $php_file);
            // Check if this file has plugin header
            if (preg_match('/Plugin Name:/i', $php_content)) {
              $main_plugin_file = $file_name . '/' . $php_file;
              break;
            }
          }
        }
        break;
      }
    }

    // Clean up temp directory
    $wp_filesystem->delete($temp_dir, true);

    if (!$main_plugin_file) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Invalid plugin structure - no main plugin file found",
        ],
        400
      );
    }

    // Check if plugin already exists
    $existing_plugin_file = self::get_plugin_file($plugin_folder);
    $is_update = !empty($existing_plugin_file);
    $was_active = false;

    if ($is_update) {
      // Store active status
      $was_active = is_plugin_active($existing_plugin_file);

      // Deactivate if active
      if ($was_active) {
        deactivate_plugins($existing_plugin_file);
      }

      // Delete existing plugin
      $delete_result = delete_plugins([$existing_plugin_file]);
      if (is_wp_error($delete_result)) {
        return new \WP_REST_Response(
          [
            "success" => false,
            "message" => "Failed to remove existing plugin: " . $delete_result->get_error_message(),
          ],
          500
        );
      }
    }

    // Now install the new version
    $skin = new Silent_Upgrader_Skin();
    $upgrader = new \Plugin_Upgrader($skin);

    $result = $upgrader->install($file["tmp_name"]);

    // Check for errors
    if (is_wp_error($result)) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => $result->get_error_message(),
        ],
        500
      );
    }

    $errors = $skin->get_errors();
    if ($errors && $errors->has_errors()) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => $errors->get_error_message(),
        ],
        500
      );
    }

    if (false === $result) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Plugin installation failed",
        ],
        500
      );
    }

    // Get the installed plugin file
    $plugin_file = $upgrader->plugin_info();

    if (!$plugin_file) {
      return new \WP_REST_Response(
        [
          "success" => true,
          "message" => ($is_update ? "Plugin updated" : "Plugin installed") . " successfully but could not determine the plugin file",
        ],
        200
      );
    }

    // Reactivate if it was active before
    if ($was_active) {
      $activate_result = activate_plugin($plugin_file);
      if (is_wp_error($activate_result)) {
        return new \WP_REST_Response(
          [
            "success" => true,
            "message" => ($is_update ? "Plugin updated" : "Plugin installed") . " successfully but reactivation failed: " . $activate_result->get_error_message(),
          ],
          200
        );
      }
    }

    // Get plugin data
    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . "/" . $plugin_file);
    $plugin_data["active"] = $was_active;
    $plugin_data["slug"] = $plugin_file;

    ob_end_clean();

    return new \WP_REST_Response(
      [
        "success" => true,
        "message" => ($is_update ? "Plugin updated" : "Plugin installed") . " successfully" . ($was_active ? " and reactivated" : ""),
        "plugin" => $plugin_data,
        "was_update" => $is_update,
      ],
      200
    );
  }

  public static function install_plugin_from_repo($request)
  {
    $plugin_slug = $request->get_param("slug");

    // Initialize filesystem
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
      WP_Filesystem();
    }

    // Setup upgrader
    $skin = new Silent_Upgrader_Skin();
    $upgrader = new \Plugin_Upgrader($skin);

    // Install the plugin
    $result = $upgrader->install("https://downloads.wordpress.org/plugin/" . $plugin_slug . ".latest-stable.zip");

    if (is_wp_error($result)) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => $result->get_error_message(),
        ],
        500
      );
    }

    // Get error messages
    $errors = $skin->get_errors();
    if ($errors && $errors->has_errors()) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => $errors->get_error_message(),
        ],
        500
      );
    }

    if (false === $result) {
      return new \WP_REST_Response(
        [
          "success" => false,
          "message" => "Plugin installation failed",
        ],
        500
      );
    }

    // Get installed plugin info
    $plugin_file = $upgrader->plugin_info();
    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . "/" . $plugin_file);
    $plugin_data["active"] = false;
    $plugin_data["slug"] = $plugin_file;

    $slug_parts = explode("/", $plugin_file);
    $base_slug = $slug_parts[0];
    $plugin_data["splitSlug"] = $base_slug;

    return new \WP_REST_Response(
      [
        "success" => true,
        "message" => "Plugin installed successfully",
        "plugin" => $plugin_data,
      ],
      200
    );
  }

  /**
   * Helper function to get plugin file from slug
   *
   * @param string $plugin_slug
   * @return string|null
   */
  private static function get_plugin_file($plugin_slug)
  {
    if (!function_exists("get_plugins")) {
      require_once ABSPATH . "wp-admin/includes/plugin.php";
    }

    $plugins = get_plugins();

    foreach ($plugins as $plugin_file => $plugin_info) {
      if (strpos($plugin_file, $plugin_slug . "/") === 0 || $plugin_file === $plugin_slug . ".php") {
        return $plugin_file;
      }
    }

    return null;
  }

  public static function get_plugin_performance_metrics($request)
  {
    $plugin_slug = $request->get_param("slug");
    $backend = $request->get_param("backend");

    $path = $plugin_slug ? "/?collect_plugin_metrics=1&plugin_slug={$plugin_slug}" : "/?collect_plugin_metrics=1";
    $url = $backend ? admin_url($path) : home_url($path);

    $args = [
      "timeout" => 30, // Set timeout to 10 seconds
    ];

    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
      error_log("WP Error: " . $response->get_error_message());
      return new \WP_REST_Response(
        [
          "error" => "Failed to collect metrics",
          "message" => $response->get_error_message(),
        ],
        500
      );
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
      error_log("HTTP Error: " . $status_code . " - " . wp_remote_retrieve_response_message($response));
      return new \WP_REST_Response(
        [
          "error" => "HTTP Error",
          "status" => $status_code,
          "message" => wp_remote_retrieve_response_message($response),
        ],
        500
      );
    }

    $body = wp_remote_retrieve_body($response);

    // Look for script tag with JSON data
    if (preg_match('/<script id="plugin-metrics-data" type="application\/json">(.*?)<\/script>/s', $body, $matches)) {
      $metrics = json_decode($matches[1], true);
      if ($metrics === null) {
        return new \WP_REST_Response(
          [
            "error" => "Failed to parse metrics JSON",
            "json_error" => json_last_error_msg(),
          ],
          500
        );
      }
      return new \WP_REST_Response($metrics, 200);
    }

    return new \WP_REST_Response(["error" => "No metrics found"], 404);
  }

  /**
   * Gets list of all installed plugins
   *
   * @param WP_REST_Request $request
   * @return WP_REST_Response
   */
  public static function get_plugins_list($request)
  {
    if (!function_exists("get_plugins")) {
      require_once ABSPATH . "wp-admin/includes/plugin.php";
    }

    // Get all installed plugins
    $all_plugins = get_plugins();
    
    // Get active plugins
    $active_plugins = get_option('active_plugins', []);
    
    // Get plugins with updates available
    $update_plugins = get_plugin_updates();
    $update_slugs = [];
    if (!empty($update_plugins)) {
      foreach ($update_plugins as $plugin_file => $plugin_data) {
        $update_slugs[] = $plugin_file;
      }
    }

    // Get auto-update enabled plugins
    $auto_updates = (array) get_site_option('auto_update_plugins', []);
    
    // Format plugins data
    $formatted_plugins = [];
    
    foreach ($all_plugins as $plugin_file => $plugin_data) {
      $is_active = in_array($plugin_file, $active_plugins, true);
      $has_update = in_array($plugin_file, $update_slugs, true);
      $auto_update_enabled = in_array($plugin_file, $auto_updates, true);
      
      // Extract base slug from plugin file
      $slug_parts = explode("/", $plugin_file);
      $base_slug = $slug_parts[0];
      
      $formatted_plugins[$plugin_file] = [
        'Name' => $plugin_data['Name'],
        'PluginURI' => $plugin_data['PluginURI'],
        'Version' => $plugin_data['Version'],
        'Description' => $plugin_data['Description'],
        'Author' => $plugin_data['Author'],
        'AuthorURI' => $plugin_data['AuthorURI'],
        'TextDomain' => $plugin_data['TextDomain'],
        'DomainPath' => $plugin_data['DomainPath'],
        'Network' => $plugin_data['Network'],
        'RequiresWP' => isset($plugin_data['RequiresWP']) ? $plugin_data['RequiresWP'] : '',
        'RequiresPHP' => isset($plugin_data['RequiresPHP']) ? $plugin_data['RequiresPHP'] : '',
        'active' => $is_active,
        'slug' => $plugin_file,
        'has_update' => $has_update,
        'deleted' => false,
        'auto_update_enabled' => $auto_update_enabled,
        'splitSlug' => $base_slug,
      ];
    }

    return new \WP_REST_Response(
      [
        'success' => true,
        'plugins' => $formatted_plugins,
      ],
      200
    );
  }
}
