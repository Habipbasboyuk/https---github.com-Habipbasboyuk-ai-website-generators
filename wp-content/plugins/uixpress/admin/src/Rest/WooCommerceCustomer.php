<?php
namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class WooCommerceCustomer
 *
 * Adds a simple REST API endpoint to check if WooCommerce is installed and active.
 * Frontend will fetch customer data directly from WooCommerce REST API.
 */
class WooCommerceCustomer
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
  private $base = "woocommerce-active";

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
      "callback" => [$this, "check_woocommerce_active"],
      "permission_callback" => [$this, "check_permissions"],
    ]);
  }

  /**
   * Check if the user has permission to access the endpoint.
   *
   * @param \WP_REST_Request $request The request object.
   * @return bool|\WP_Error True if the user has permission, WP_Error object otherwise.
   */
  public function check_permissions($request)
  {
    return RestPermissionChecker::check_permissions($request, 'edit_users');
  }

  /**
   * Check if WooCommerce is installed and active.
   *
   * @return bool True if WooCommerce is active, false otherwise.
   */
  private function is_woocommerce_active()
  {
    // Check if WooCommerce class exists
    if (!class_exists('WooCommerce')) {
      return false;
    }

    // Check if WooCommerce plugin is active
    if (!function_exists('is_plugin_active')) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $woocommerce_plugin = 'woocommerce/woocommerce.php';
    return is_plugin_active($woocommerce_plugin);
  }

  /**
   * Check if WooCommerce is active.
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function check_woocommerce_active($request)
  {
    $is_active = $this->is_woocommerce_active();

    return new \WP_REST_Response(
      [
        "success" => true,
        "woocommerce_active" => $is_active,
      ],
      200
    );
  }
}

