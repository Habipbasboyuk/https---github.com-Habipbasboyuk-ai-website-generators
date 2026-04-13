<?php
namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class User_Roles_Endpoint
 *
 * Adds a custom REST API endpoint to fetch all available user roles in WordPress.
 */
class UserRoles
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
  private $base = "user-roles";

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
      "callback" => [$this, "get_user_roles"],
      "permission_callback" => [$this, "get_user_roles_permissions_check"],
    ]);
  }

  /**
   * Check if the user has permission to access the endpoint.
   *
   * @param WP_REST_Request $request The request object.
   * @return bool|WP_Error True if the user has permission, WP_Error object otherwise.
   */
  public function get_user_roles_permissions_check($request)
  {
    return RestPermissionChecker::check_permissions($request, 'manage_options');
  }

  /**
   * Get all available user roles.
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function get_user_roles($request)
  {
    $wp_roles = wp_roles();
    $roles = [];

    foreach ($wp_roles->roles as $role_slug => $role_info) {
      $roles[] = [
        "value" => $role_slug,
        "label" => translate_user_role($role_info["name"]),
      ];
    }

    return new \WP_REST_Response($roles, 200);
  }
}
