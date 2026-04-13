<?php
namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class UserCapabilities
 *
 * Adds a custom REST API endpoint to fetch the current user's capabilities.
 */
class UserCapabilities
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
  private $base = "user-capabilities";

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
      "callback" => [$this, "get_user_capabilities"],
      "permission_callback" => [$this, "get_user_capabilities_permissions_check"],
    ]);
  }

  /**
   * Check if the user has permission to access the endpoint.
   *
   * @param WP_REST_Request $request The request object.
   * @return bool|WP_Error True if the user has permission, WP_Error object otherwise.
   */
  public function get_user_capabilities_permissions_check($request)
  {
    // Only require login, no specific capability needed (users can view their own capabilities)
    return RestPermissionChecker::check_login_only($request);
  }

  /**
   * Get the current user's capabilities.
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object containing the user's capabilities.
   */
  public function get_user_capabilities($request)
  {
    $current_user = wp_get_current_user();
    
    // Get all capabilities for the current user
    $allcaps = $current_user->allcaps;
    
    // Return only the capabilities (not other user data for security)
    return new \WP_REST_Response([
      'allcaps' => $allcaps,
    ], 200);
  }
}

