<?php
namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class MenuCache
 *
 * Handles menu cache key management and rotation
 *
 * @since 1.0.9
 */
class MenuCache
{
  /**
   * Option name for storing the menu cache key
   *
   * @var string
   */
  private const CACHE_KEY_OPTION = 'uixpress_menu_cache_key';

  /**
   * MenuCache constructor.
   * Registers REST API endpoints
   */
  public function __construct()
  {
    add_action('rest_api_init', [$this, 'register_routes']);
  }

  /**
   * Register REST API routes
   *
   * @since 1.0.9
   * @return void
   */
  public function register_routes()
  {
    register_rest_route('uixpress/v1', '/menu-cache/key', [
      'methods' => 'GET',
      'callback' => [$this, 'get_cache_key'],
      'permission_callback' => [$this, 'check_permissions'],
    ]);

    register_rest_route('uixpress/v1', '/menu-cache/rotate', [
      'methods' => 'POST',
      'callback' => [$this, 'rotate_cache_key'],
      'permission_callback' => [$this, 'check_permissions'],
    ]);
  }

  /**
   * Check if the user has permission to access the endpoint
   *
   * @param \WP_REST_Request $request The request object
   * @return bool|WP_Error True if the user has permission, WP_Error object otherwise
   * @since 1.0.9
   */
  public function check_permissions($request)
  {
    // Any logged-in user can read the cache key for client-side sync.
    if ($request->get_method() === 'GET') {
      return is_user_logged_in();
    }

    return RestPermissionChecker::check_permissions($request, 'manage_options');
  }

  /**
   * Get the current menu cache key
   *
   * @param \WP_REST_Request $request The request object
   * @return \WP_REST_Response The response object with cache key
   * @since 1.0.9
   */
  public function get_cache_key($request)
  {
    $cache_key = self::get_or_create_cache_key();
    return new \WP_REST_Response(['cache_key' => $cache_key], 200);
  }

  /**
   * Rotate the menu cache key to invalidate all client-side caches
   *
   * @param \WP_REST_Request $request The request object
   * @return \WP_REST_Response The response object with new cache key
   * @since 1.0.9
   */
  public function rotate_cache_key($request)
  {
    $new_cache_key = self::generate_cache_key();
    update_option(self::CACHE_KEY_OPTION, $new_cache_key);
    
    return new \WP_REST_Response([
      'success' => true,
      'cache_key' => $new_cache_key,
      'message' => __('Menu cache key rotated successfully. All client caches have been invalidated.', 'uixpress'),
    ], 200);
  }

  /**
   * Get or create the menu cache key
   *
   * @return string The cache key
   * @since 1.0.9
   */
  public static function get_or_create_cache_key()
  {
    $cache_key = get_option(self::CACHE_KEY_OPTION);
    
    if (!$cache_key) {
      $cache_key = self::generate_cache_key();
      update_option(self::CACHE_KEY_OPTION, $cache_key);
    }
    
    return $cache_key;
  }

  /**
   * Generate a new cache key
   *
   * @return string A unique cache key
   * @since 1.0.9
   */
  private static function generate_cache_key()
  {
    return wp_generate_password(32, false);
  }
}

