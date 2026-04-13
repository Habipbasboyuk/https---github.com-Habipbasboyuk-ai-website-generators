<?php
namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class RoleEditor
 *
 * REST API endpoints for managing WordPress user roles and capabilities
 */
class RoleEditor
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
  private $base = "role-editor";

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
    // Get role details with capabilities
    register_rest_route($this->namespace, "/" . $this->base . "/role/(?P<role>[a-zA-Z0-9_-]+)", [
      "methods" => "GET",
      "callback" => [$this, "get_role_details"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Update role capabilities
    register_rest_route($this->namespace, "/" . $this->base . "/role/(?P<role>[a-zA-Z0-9_-]+)", [
      "methods" => "POST",
      "callback" => [$this, "update_role_capabilities"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Update role name
    register_rest_route($this->namespace, "/" . $this->base . "/role/(?P<role>[a-zA-Z0-9_-]+)/name", [
      "methods" => "POST",
      "callback" => [$this, "update_role_name"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Get all available capabilities
    register_rest_route($this->namespace, "/" . $this->base . "/capabilities", [
      "methods" => "GET",
      "callback" => [$this, "get_all_capabilities"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Create new role
    register_rest_route($this->namespace, "/" . $this->base . "/roles", [
      "methods" => "POST",
      "callback" => [$this, "create_role"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Delete role
    register_rest_route($this->namespace, "/" . $this->base . "/role/(?P<role>[a-zA-Z0-9_-]+)", [
      "methods" => "DELETE",
      "callback" => [$this, "delete_role"],
      "permission_callback" => [$this, "permissions_check"],
    ]);
  }

  /**
   * Check if the user has permission to access the endpoint.
   *
   * @param WP_REST_Request $request The request object.
   * @return bool|WP_Error True if the user has permission, WP_Error object otherwise.
   */
  public function permissions_check($request)
  {
    return RestPermissionChecker::check_permissions($request, 'manage_options');
  }

  /**
   * Get role details including capabilities
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function get_role_details($request)
  {
    $role_slug = sanitize_text_field($request->get_param('role'));
    
    // Validate role slug format
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $role_slug)) {
      return new \WP_Error('rest_invalid_role', __('Invalid role format.', 'uixpress'), ['status' => 400]);
    }
    
    $wp_roles = wp_roles();

    if (!isset($wp_roles->roles[$role_slug])) {
      return new \WP_Error('rest_invalid_role', __('Invalid role.', 'uixpress'), ['status' => 404]);
    }

    $role = $wp_roles->get_role($role_slug);
    $role_info = $wp_roles->roles[$role_slug];

    // Get user count for this role
    $user_count = count_users();
    $users_with_role = isset($user_count['avail_roles'][$role_slug]) ? absint($user_count['avail_roles'][$role_slug]) : 0;

    // Get all capabilities for this role
    $capabilities = [];
    if ($role && isset($role->capabilities)) {
      foreach ($role->capabilities as $cap => $granted) {
        if ($granted && is_string($cap)) {
          $capabilities[] = sanitize_text_field($cap);
        }
      }
    }

    return new \WP_REST_Response([
      'slug' => sanitize_text_field($role_slug),
      'name' => translate_user_role($role_info['name']),
      'userCount' => $users_with_role,
      'capabilities' => $capabilities,
    ], 200);
  }

  /**
   * Update role capabilities
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function update_role_capabilities($request)
  {
    $role_slug = sanitize_text_field($request->get_param('role'));
    
    // Validate role slug format
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $role_slug)) {
      return new \WP_Error('rest_invalid_role', __('Invalid role format.', 'uixpress'), ['status' => 400]);
    }
    
    $wp_roles = wp_roles();

    if (!isset($wp_roles->roles[$role_slug])) {
      return new \WP_Error('rest_invalid_role', __('Invalid role.', 'uixpress'), ['status' => 404]);
    }

    $body = $request->get_json_params();
    $capabilities = isset($body['capabilities']) ? $body['capabilities'] : [];

    if (!is_array($capabilities)) {
      return new \WP_Error('rest_invalid_capabilities', __('Capabilities must be an array.', 'uixpress'), ['status' => 400]);
    }

    $role = $wp_roles->get_role($role_slug);
    if (!$role) {
      return new \WP_Error('rest_role_not_found', __('Role not found.', 'uixpress'), ['status' => 404]);
    }

    // Get all available capabilities
    $all_capabilities = $this->get_all_available_capabilities();

    // Validate and sanitize each capability
    $validated_capabilities = [];
    foreach ($capabilities as $cap) {
      if (!is_string($cap)) {
        continue;
      }
      $cap = sanitize_text_field($cap);
      // Validate capability format (WordPress capabilities are typically lowercase with underscores)
      if (preg_match('/^[a-z0-9_]+$/', $cap) && in_array($cap, $all_capabilities)) {
        $validated_capabilities[] = $cap;
      }
    }

    // Remove all current capabilities
    foreach ($all_capabilities as $cap) {
      $role->remove_cap($cap);
    }

    // Add new capabilities
    foreach ($validated_capabilities as $cap) {
      $role->add_cap($cap);
    }

    // Refresh roles
    $wp_roles = wp_roles();
    $role = $wp_roles->get_role($role_slug);
    $updated_capabilities = [];
    if ($role && isset($role->capabilities)) {
      foreach ($role->capabilities as $cap => $granted) {
        if ($granted && is_string($cap)) {
          $updated_capabilities[] = sanitize_text_field($cap);
        }
      }
    }

    return new \WP_REST_Response([
      'slug' => sanitize_text_field($role_slug),
      'capabilities' => $updated_capabilities,
      'message' => __('Role capabilities updated successfully.', 'uixpress'),
    ], 200);
  }

  /**
   * Update role name
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function update_role_name($request)
  {
    $role_slug = sanitize_text_field($request->get_param('role'));
    
    // Validate role slug format
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $role_slug)) {
      return new \WP_Error('rest_invalid_role', __('Invalid role format.', 'uixpress'), ['status' => 400]);
    }
    
    $wp_roles = wp_roles();

    if (!isset($wp_roles->roles[$role_slug])) {
      return new \WP_Error('rest_invalid_role', __('Invalid role.', 'uixpress'), ['status' => 404]);
    }

    $body = $request->get_json_params();
    $new_name = isset($body['name']) ? sanitize_text_field($body['name']) : '';
    
    // Strip HTML tags and trim whitespace
    $new_name = wp_strip_all_tags($new_name);
    $new_name = trim($new_name);

    if (empty($new_name)) {
      return new \WP_Error('rest_invalid_name', __('Role name cannot be empty.', 'uixpress'), ['status' => 400]);
    }
    
    // Validate name length (WordPress role names are typically under 50 characters)
    if (strlen($new_name) > 100) {
      return new \WP_Error('rest_invalid_name', __('Role name is too long.', 'uixpress'), ['status' => 400]);
    }

    // Update the role name in the roles object
    $wp_roles->roles[$role_slug]['name'] = $new_name;
    
    // Persist the change to the database
    update_option($wp_roles->role_key, $wp_roles->roles);

    return new \WP_REST_Response([
      'slug' => sanitize_text_field($role_slug),
      'name' => translate_user_role($new_name),
      'message' => __('Role name updated successfully.', 'uixpress'),
    ], 200);
  }

  /**
   * Get all available capabilities
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function get_all_capabilities($request)
  {
    $capabilities = $this->get_all_available_capabilities();
    
    // Group capabilities by category
    $grouped = [
      'general' => [],
      'posts' => [],
      'pages' => [],
      'media' => [],
      'users' => [],
      'plugins' => [],
      'themes' => [],
      'settings' => [],
      'other' => [],
    ];

    foreach ($capabilities as $cap) {
      $category = $this->categorize_capability($cap);
      $grouped[$category][] = $cap;
    }

    // Remove empty categories
    $grouped = array_filter($grouped, function($caps) {
      return !empty($caps);
    });

    return new \WP_REST_Response([
      'all' => $capabilities,
      'grouped' => $grouped,
    ], 200);
  }

  /**
   * Get all available capabilities from WordPress
   *
   * @return array Array of capability strings
   */
  private function get_all_available_capabilities()
  {
    global $wp_roles;
    $all_caps = [];

    // Get capabilities from all roles
    foreach ($wp_roles->roles as $role_slug => $role_info) {
      $role = $wp_roles->get_role($role_slug);
      if ($role && isset($role->capabilities)) {
        foreach ($role->capabilities as $cap => $granted) {
          if ($granted && is_string($cap) && !in_array($cap, $all_caps)) {
            // Validate capability format
            if (preg_match('/^[a-z0-9_]+$/', $cap)) {
              $all_caps[] = $cap;
            }
          }
        }
      }
    }

    // Sort alphabetically
    sort($all_caps);

    return $all_caps;
  }

  /**
   * Create a new role
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function create_role($request)
  {
    $body = $request->get_json_params();
    $role_name = isset($body['name']) ? sanitize_text_field($body['name']) : '';
    $role_slug = isset($body['slug']) ? sanitize_text_field($body['slug']) : '';
    $capabilities = isset($body['capabilities']) && is_array($body['capabilities']) ? $body['capabilities'] : [];

    // Strip HTML tags and trim whitespace from role name
    $role_name = wp_strip_all_tags($role_name);
    $role_name = trim($role_name);

    // Validate role name
    if (empty($role_name)) {
      return new \WP_Error('rest_invalid_name', __('Role name cannot be empty.', 'uixpress'), ['status' => 400]);
    }
    
    // Validate name length
    if (strlen($role_name) > 100) {
      return new \WP_Error('rest_invalid_name', __('Role name is too long.', 'uixpress'), ['status' => 400]);
    }

    // Generate slug from name if not provided
    if (empty($role_slug)) {
      $role_slug = sanitize_title($role_name);
    }

    // Validate slug format
    if (!preg_match('/^[a-z0-9_-]+$/', $role_slug)) {
      return new \WP_Error('rest_invalid_slug', __('Role slug can only contain lowercase letters, numbers, hyphens, and underscores.', 'uixpress'), ['status' => 400]);
    }
    
    // Validate slug length (WordPress role slugs are typically under 40 characters)
    if (strlen($role_slug) > 60) {
      return new \WP_Error('rest_invalid_slug', __('Role slug is too long.', 'uixpress'), ['status' => 400]);
    }

    $wp_roles = wp_roles();

    // Check if role already exists
    if (isset($wp_roles->roles[$role_slug])) {
      return new \WP_Error('rest_role_exists', __('A role with this slug already exists.', 'uixpress'), ['status' => 409]);
    }

    // Get all available capabilities for validation
    $all_capabilities = $this->get_all_available_capabilities();

    // Prepare capabilities array (WordPress expects capability => true format)
    $role_capabilities = [];
    foreach ($capabilities as $cap) {
      if (!is_string($cap)) {
        continue;
      }
      $cap = sanitize_text_field($cap);
      // Validate capability format and existence
      if (preg_match('/^[a-z0-9_]+$/', $cap) && in_array($cap, $all_capabilities)) {
        $role_capabilities[$cap] = true;
      }
    }

    // Create the role
    $result = add_role($role_slug, $role_name, $role_capabilities);

    if (!$result) {
      return new \WP_Error('rest_role_creation_failed', __('Failed to create role.', 'uixpress'), ['status' => 500]);
    }

    // Get user count (will be 0 for new role)
    $user_count = count_users();
    $users_with_role = isset($user_count['avail_roles'][$role_slug]) ? absint($user_count['avail_roles'][$role_slug]) : 0;

    // Return sanitized capabilities list
    $returned_capabilities = array_keys($role_capabilities);

    return new \WP_REST_Response([
      'slug' => sanitize_text_field($role_slug),
      'name' => translate_user_role($role_name),
      'userCount' => $users_with_role,
      'capabilities' => $returned_capabilities,
      'message' => __('Role created successfully.', 'uixpress'),
    ], 201);
  }

  /**
   * Delete a role
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function delete_role($request)
  {
    $role_slug = sanitize_text_field($request->get_param('role'));
    
    // Validate role slug format
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $role_slug)) {
      return new \WP_Error('rest_invalid_role', __('Invalid role format.', 'uixpress'), ['status' => 400]);
    }
    
    $wp_roles = wp_roles();

    if (!isset($wp_roles->roles[$role_slug])) {
      return new \WP_Error('rest_invalid_role', __('Invalid role.', 'uixpress'), ['status' => 404]);
    }

    // Prevent deletion of default WordPress roles
    $default_roles = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
    if (in_array($role_slug, $default_roles)) {
      return new \WP_Error('rest_cannot_delete_default', __('Cannot delete default WordPress roles.', 'uixpress'), ['status' => 403]);
    }

    // Check if role has users
    $user_count = count_users();
    $users_with_role = isset($user_count['avail_roles'][$role_slug]) ? absint($user_count['avail_roles'][$role_slug]) : 0;

    if ($users_with_role > 0) {
      return new \WP_Error('rest_role_has_users', sprintf(__('Cannot delete role. There are %d user(s) with this role. Please reassign users to another role first.', 'uixpress'), $users_with_role), ['status' => 409]);
    }

    // Delete the role
    remove_role($role_slug);

    return new \WP_REST_Response([
      'slug' => sanitize_text_field($role_slug),
      'message' => __('Role deleted successfully.', 'uixpress'),
    ], 200);
  }

  /**
   * Categorize a capability based on its name
   *
   * @param string $capability The capability name
   * @return string The category name
   */
  private function categorize_capability($capability)
  {
    if (strpos($capability, 'post') !== false || strpos($capability, 'edit_') !== false) {
      return 'posts';
    }
    if (strpos($capability, 'page') !== false) {
      return 'pages';
    }
    if (strpos($capability, 'upload') !== false || strpos($capability, 'media') !== false) {
      return 'media';
    }
    if (strpos($capability, 'user') !== false || strpos($capability, 'role') !== false) {
      return 'users';
    }
    if (strpos($capability, 'plugin') !== false) {
      return 'plugins';
    }
    if (strpos($capability, 'theme') !== false) {
      return 'themes';
    }
    if (strpos($capability, 'manage_options') !== false || strpos($capability, 'settings') !== false) {
      return 'settings';
    }
    if (strpos($capability, 'read') !== false || strpos($capability, 'level_') !== false) {
      return 'general';
    }
    return 'other';
  }
}

