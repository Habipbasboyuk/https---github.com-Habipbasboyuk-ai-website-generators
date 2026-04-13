<?php
namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class ServerHealth
 *
 * Adds a custom REST API endpoint to fetch server health and system statistics.
 */
class ServerHealth
{
  /**
   * The namespace for the REST API endpoint.
   *
   * @var string
   */
  private $namespace = "uixpress/v1";

  /**
   * The base for the REST API endpoint.
   *
   * @var string
   */
  private $base = "server-health";

  /**
   * Initialize the class and set up REST API routes.
   */
  public function __construct()
  {
    add_action("rest_api_init", [$this, "register_routes"]);
  }

  /**
   * Register the REST API routes.
   */
  public function register_routes()
  {
    register_rest_route($this->namespace, "/" . $this->base, [
      "methods" => "GET",
      "callback" => [$this, "get_server_health"],
      "permission_callback" => [$this, "get_server_health_permissions_check"],
    ]);
  }

  /**
   * Check if the user has permission to access the endpoint.
   *
   * @param WP_REST_Request $request The request object.
   * @return bool|WP_Error True if the user has permission, WP_Error object otherwise.
   */
  public function get_server_health_permissions_check($request)
  {
    return RestPermissionChecker::check_permissions($request, 'manage_options');
  }

  /**
   * Get server health and system statistics.
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function get_server_health($request)
  {
    // Get WordPress version
    global $wp_version;
    
    // Get PHP version
    $php_version = PHP_VERSION;
    
    // Get server software - sanitize $_SERVER access
    $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field($_SERVER['SERVER_SOFTWARE']) : 'Unknown';
    
    // Get memory usage
    $memory_usage = memory_get_usage(true);
    $memory_limit = ini_get('memory_limit');
    $memory_usage_mb = round($memory_usage / 1024 / 1024, 2);
    $memory_limit_mb = $this->convert_to_mb($memory_limit);
    $memory_percentage = $memory_limit_mb > 0 ? round(($memory_usage_mb / $memory_limit_mb) * 100, 1) : 0;
    
    // Get server load (if available)
    $server_load = $this->get_server_load();
    
    // Get disk space
    $disk_space = $this->get_disk_space();
    
    // Get plugin updates count
    $plugin_updates = $this->get_plugin_updates_count();
    
    // Get theme updates count
    $theme_updates = $this->get_theme_updates_count();
    
    // Get core updates count
    $core_updates = $this->get_core_updates_count();
    
    // Get database size
    $database_size = $this->get_database_size();
    
    // Get active plugins count
    $active_plugins_count = count(get_option('active_plugins', []));
    
    // Get total plugins count
    $all_plugins = get_plugins();
    $total_plugins_count = count($all_plugins);
    
    // Get active theme
    $active_theme = wp_get_theme();
    
    // Get uploads directory size
    $uploads_size = $this->get_uploads_directory_size();
    
    // Get last backup date (if available)
    $last_backup = $this->get_last_backup_date();
    
    // Get SSL status
    $ssl_status = $this->get_ssl_status();
    
    // Get server timezone
    $timezone = wp_timezone_string();
    
    // Get max execution time
    $max_execution_time = ini_get('max_execution_time');
    
    // Get upload max filesize
    $upload_max_filesize = ini_get('upload_max_filesize');
    
    // Get post max size
    $post_max_size = ini_get('post_max_size');
    
    // Get MySQL version
    global $wpdb;
    $mysql_version = $wpdb->get_var("SELECT VERSION()");
    
    $health_data = [
      'wordpress' => [
        'version' => $wp_version,
        'core_updates' => $core_updates,
        'last_backup' => $last_backup,
        'ssl_status' => $ssl_status,
        'timezone' => $timezone,
      ],
      'php' => [
        'version' => $php_version,
        'memory_usage' => $memory_usage_mb,
        'memory_limit' => $memory_limit_mb,
        'memory_percentage' => $memory_percentage,
        'max_execution_time' => $max_execution_time,
        'upload_max_filesize' => $upload_max_filesize,
        'post_max_size' => $post_max_size,
      ],
      'server' => [
        'software' => $server_software,
        'load' => $server_load,
        'disk_space' => $disk_space,
        'mysql_version' => $mysql_version,
      ],
      'plugins' => [
        'total' => $total_plugins_count,
        'active' => $active_plugins_count,
        'updates_available' => $plugin_updates,
      ],
      'themes' => [
        'active' => $active_theme->get('Name'),
        'version' => $active_theme->get('Version'),
        'updates_available' => $theme_updates,
      ],
      'storage' => [
        'database_size' => $database_size,
        'uploads_size' => $uploads_size,
      ],
    ];

    return new \WP_REST_Response($health_data, 200);
  }

  /**
   * Convert memory limit string to MB
   */
  private function convert_to_mb($memory_limit)
  {
    $memory_limit = trim($memory_limit);
    $last = strtolower($memory_limit[strlen($memory_limit) - 1]);
    $memory_limit = (int) $memory_limit;
    
    switch ($last) {
      case 'g':
        $memory_limit *= 1024;
        break;
      case 'm':
        break;
      case 'k':
        $memory_limit /= 1024;
        break;
    }
    
    return $memory_limit;
  }

  /**
   * Get server load if available
   */
  private function get_server_load()
  {
    if (function_exists('sys_getloadavg')) {
      $load = sys_getloadavg();
      return [
        '1min' => round($load[0], 2),
        '5min' => round($load[1], 2),
        '15min' => round($load[2], 2),
      ];
    }
    return null;
  }

  /**
   * Get disk space information
   *
   * @return array|null Disk space information or null if unavailable
   */
  private function get_disk_space()
  {
    // Check if PHP functions are available
    if (!function_exists('disk_free_space') || !function_exists('disk_total_space')) {
      return null;
    }

    $path = ABSPATH;
    $bytes_free = disk_free_space($path);
    $bytes_total = disk_total_space($path);
    
    if ($bytes_free !== false && $bytes_total !== false) {
      return [
        'free' => round($bytes_free / 1024 / 1024 / 1024, 2),
        'total' => round($bytes_total / 1024 / 1024 / 1024, 2),
        'used' => round(($bytes_total - $bytes_free) / 1024 / 1024 / 1024, 2),
        'percentage' => round((($bytes_total - $bytes_free) / $bytes_total) * 100, 1),
      ];
    }
    return null;
  }

  /**
   * Get plugin updates count
   */
  private function get_plugin_updates_count()
  {
    if (!function_exists('get_plugin_updates')) {
      require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    
    $plugin_updates = get_plugin_updates();
    return count($plugin_updates);
  }

  /**
   * Get theme updates count
   */
  private function get_theme_updates_count()
  {
    if (!function_exists('get_theme_updates')) {
      require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    
    $theme_updates = get_theme_updates();
    return count($theme_updates);
  }

  /**
   * Get core updates count
   */
  private function get_core_updates_count()
  {
    if (!function_exists('get_core_updates')) {
      require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    
    $core_updates = get_core_updates();
    return count($core_updates);
  }

  /**
   * Get database size
   */
  private function get_database_size()
  {
    global $wpdb;
    
    $result = $wpdb->get_var("
      SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'db_size'
      FROM information_schema.tables
      WHERE table_schema = '" . DB_NAME . "'
    ");
    
    return $result ? (float) $result : 0;
  }

  /**
   * Get uploads directory size
   */
  private function get_uploads_directory_size()
  {
    $upload_dir = wp_upload_dir();
    $upload_path = $upload_dir['basedir'];
    
    if (is_dir($upload_path)) {
      $size = $this->get_directory_size($upload_path);
      return round($size / 1024 / 1024, 2);
    }
    
    return 0;
  }

  /**
   * Get directory size recursively
   */
  private function get_directory_size($directory)
  {
    $size = 0;
    if (is_dir($directory)) {
      foreach (glob($directory . '/*', GLOB_NOSORT) as $file) {
        if (is_file($file)) {
          $size += filesize($file);
        } elseif (is_dir($file)) {
          $size += $this->get_directory_size($file);
        }
      }
    }
    return $size;
  }

  /**
   * Get last backup date (placeholder - would need backup plugin integration)
   */
  private function get_last_backup_date()
  {
    // This would need to be integrated with backup plugins
    // For now, return null
    return null;
  }

  /**
   * Get SSL status
   */
  private function get_ssl_status()
  {
    return is_ssl() ? 'enabled' : 'disabled';
  }
}
