<?php
namespace UiXpress\Rest\CustomFields;

use UiXpress\Rest\CustomFields\OptionPagesRepository;
use UiXpress\Rest\RestPermissionChecker;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class OptionPages
 *
 * REST API endpoints for managing option pages stored in JSON
 */
class OptionPages
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
  private $base = "options-pages";

  /**
   * @var OptionPagesRepository
   */
  private $repository;

  /**
   * Initialize the class and set up REST API routes.
   */
  public function __construct()
  {
    $this->repository = new OptionPagesRepository();
    add_action("rest_api_init", [$this, "register_routes"]);
  }

  /**
   * Register the REST API routes.
   */
  public function register_routes()
  {
    // Get all option pages
    register_rest_route($this->namespace, "/" . $this->base, [
      "methods" => "GET",
      "callback" => [$this, "get_option_pages"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Get parent menus and capabilities data
    register_rest_route($this->namespace, "/" . $this->base . "/meta", [
      "methods" => "GET",
      "callback" => [$this, "get_meta_data"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Get a single option page
    register_rest_route($this->namespace, "/" . $this->base . "/(?P<slug>[a-zA-Z0-9_-]+)", [
      "methods" => "GET",
      "callback" => [$this, "get_option_page"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Create a new option page
    register_rest_route($this->namespace, "/" . $this->base, [
      "methods" => "POST",
      "callback" => [$this, "create_option_page"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Update an option page
    register_rest_route($this->namespace, "/" . $this->base . "/(?P<slug>[a-zA-Z0-9_-]+)", [
      "methods" => "PUT,PATCH",
      "callback" => [$this, "update_option_page"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Delete an option page
    register_rest_route($this->namespace, "/" . $this->base . "/(?P<slug>[a-zA-Z0-9_-]+)", [
      "methods" => "DELETE",
      "callback" => [$this, "delete_option_page"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Duplicate an option page
    register_rest_route($this->namespace, "/" . $this->base . "/(?P<slug>[a-zA-Z0-9_-]+)/duplicate", [
      "methods" => "POST",
      "callback" => [$this, "duplicate_option_page"],
      "permission_callback" => [$this, "permissions_check"],
    ]);
  }

  /**
   * Check if the user has permission to access the endpoint.
   *
   * @param \WP_REST_Request $request The request object.
   * @return bool|\WP_Error True if the user has permission, WP_Error object otherwise.
   */
  public function permissions_check($request)
  {
    return RestPermissionChecker::check_permissions($request, 'manage_options');
  }

  /**
   * Validate slug format
   *
   * @param string $slug The slug to validate
   * @return bool True if valid
   */
  private function is_valid_slug($slug)
  {
    return preg_match('/^[a-zA-Z0-9_-]+$/', $slug) === 1;
  }

  /**
   * Sanitize option page data
   *
   * @param array $data Raw option page data
   * @return array Sanitized data
   */
  private function sanitize_option_page_data($data)
  {
    $defaults = OptionPagesRepository::get_defaults();
    
    return [
      'slug' => isset($data['slug']) ? sanitize_key($data['slug']) : $defaults['slug'],
      'title' => isset($data['title']) ? sanitize_text_field($data['title']) : $defaults['title'],
      'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : $defaults['description'],
      'menu_type' => isset($data['menu_type']) && in_array($data['menu_type'], ['top_level', 'submenu']) 
        ? $data['menu_type'] 
        : $defaults['menu_type'],
      'parent_menu' => isset($data['parent_menu']) ? sanitize_text_field($data['parent_menu']) : $defaults['parent_menu'],
      'menu_icon' => isset($data['menu_icon']) ? sanitize_text_field($data['menu_icon']) : $defaults['menu_icon'],
      'menu_position' => isset($data['menu_position']) ? absint($data['menu_position']) : $defaults['menu_position'],
      'capability' => isset($data['capability']) ? sanitize_text_field($data['capability']) : $defaults['capability'],
      'active' => isset($data['active']) ? (bool) $data['active'] : $defaults['active'],
      'created_at' => isset($data['created_at']) ? sanitize_text_field($data['created_at']) : $defaults['created_at'],
      'updated_at' => isset($data['updated_at']) ? sanitize_text_field($data['updated_at']) : $defaults['updated_at'],
    ];
  }

  /**
   * Get all option pages
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function get_option_pages($request)
  {
    $option_pages = $this->repository->read();
    return new \WP_REST_Response($option_pages, 200);
  }

  /**
   * Get meta data (parent menus, capabilities)
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function get_meta_data($request)
  {
    return new \WP_REST_Response([
      'parent_menus' => OptionPagesRepository::get_parent_menus(),
      'capabilities' => OptionPagesRepository::get_capabilities(),
    ], 200);
  }

  /**
   * Get a single option page
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function get_option_page($request)
  {
    $slug = sanitize_key($request->get_param('slug'));
    
    if (!$this->is_valid_slug($slug)) {
      return new \WP_Error('rest_invalid_slug', __('Invalid option page slug.', 'uixpress'), ['status' => 400]);
    }

    $page = $this->repository->find_by_slug($slug);
    
    if ($page === null) {
      return new \WP_Error('rest_not_found', __('Option page not found.', 'uixpress'), ['status' => 404]);
    }

    return new \WP_REST_Response($page, 200);
  }

  /**
   * Create a new option page
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function create_option_page($request)
  {
    $body = $request->get_json_params();
    
    // Validate required fields
    if (empty($body['title'])) {
      return new \WP_Error('rest_missing_title', __('Option page title is required.', 'uixpress'), ['status' => 400]);
    }

    $option_pages = $this->repository->read();
    
    // Sanitize data
    $new_page = $this->sanitize_option_page_data($body);
    
    // Generate slug if not provided or ensure uniqueness
    if (empty($new_page['slug'])) {
      $new_page['slug'] = $this->repository->generate_slug($new_page['title'], $option_pages);
    } else {
      // Check if slug already exists
      if ($this->repository->slug_exists($new_page['slug'], $option_pages)) {
        return new \WP_Error('rest_slug_exists', __('An option page with this slug already exists.', 'uixpress'), ['status' => 400]);
      }
    }
    
    $new_page['created_at'] = current_time('mysql');
    $new_page['updated_at'] = current_time('mysql');
    
    // Add to array
    $option_pages[] = $new_page;
    
    // Save to file
    if (!$this->repository->write($option_pages)) {
      return new \WP_Error('rest_save_failed', __('Failed to save option page.', 'uixpress'), ['status' => 500]);
    }

    return new \WP_REST_Response([
      'message' => __('Option page created successfully.', 'uixpress'),
      'data' => $new_page,
    ], 201);
  }

  /**
   * Update an existing option page
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function update_option_page($request)
  {
    $slug = sanitize_key($request->get_param('slug'));
    $body = $request->get_json_params();
    
    if (!$this->is_valid_slug($slug)) {
      return new \WP_Error('rest_invalid_slug', __('Invalid option page slug.', 'uixpress'), ['status' => 400]);
    }

    $option_pages = $this->repository->read();
    $found_index = $this->repository->find_index_by_slug($slug);

    if ($found_index === -1) {
      return new \WP_Error('rest_not_found', __('Option page not found.', 'uixpress'), ['status' => 404]);
    }

    // Preserve original slug and created_at
    $body['slug'] = $slug;
    $body['created_at'] = $option_pages[$found_index]['created_at'];
    
    // Sanitize and update
    $updated_page = $this->sanitize_option_page_data($body);
    $updated_page['slug'] = $slug;
    $updated_page['updated_at'] = current_time('mysql');
    
    $option_pages[$found_index] = $updated_page;
    
    // Save to file
    if (!$this->repository->write($option_pages)) {
      return new \WP_Error('rest_save_failed', __('Failed to save option page.', 'uixpress'), ['status' => 500]);
    }

    return new \WP_REST_Response([
      'message' => __('Option page updated successfully.', 'uixpress'),
      'data' => $updated_page,
    ], 200);
  }

  /**
   * Delete an option page
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function delete_option_page($request)
  {
    $slug = sanitize_key($request->get_param('slug'));
    
    if (!$this->is_valid_slug($slug)) {
      return new \WP_Error('rest_invalid_slug', __('Invalid option page slug.', 'uixpress'), ['status' => 400]);
    }

    $option_pages = $this->repository->read();
    $found = false;
    
    $option_pages = array_filter($option_pages, function($page) use ($slug, &$found) {
      if (isset($page['slug']) && $page['slug'] === $slug) {
        $found = true;
        return false;
      }
      return true;
    });

    if (!$found) {
      return new \WP_Error('rest_not_found', __('Option page not found.', 'uixpress'), ['status' => 404]);
    }

    // Re-index array
    $option_pages = array_values($option_pages);
    
    // Save to file
    if (!$this->repository->write($option_pages)) {
      return new \WP_Error('rest_save_failed', __('Failed to delete option page.', 'uixpress'), ['status' => 500]);
    }

    return new \WP_REST_Response([
      'message' => __('Option page deleted successfully.', 'uixpress'),
    ], 200);
  }

  /**
   * Duplicate an option page
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function duplicate_option_page($request)
  {
    $slug = sanitize_key($request->get_param('slug'));
    
    if (!$this->is_valid_slug($slug)) {
      return new \WP_Error('rest_invalid_slug', __('Invalid option page slug.', 'uixpress'), ['status' => 400]);
    }

    $option_pages = $this->repository->read();
    $original = $this->repository->find_by_slug($slug);

    if (!$original) {
      return new \WP_Error('rest_not_found', __('Option page not found.', 'uixpress'), ['status' => 404]);
    }

    // Create duplicate
    $duplicate = $original;
    $duplicate['title'] = $original['title'] . ' ' . __('(Copy)', 'uixpress');
    $duplicate['slug'] = $this->repository->generate_slug($duplicate['title'], $option_pages);
    $duplicate['created_at'] = current_time('mysql');
    $duplicate['updated_at'] = current_time('mysql');

    $option_pages[] = $duplicate;
    
    // Save to file
    if (!$this->repository->write($option_pages)) {
      return new \WP_Error('rest_save_failed', __('Failed to duplicate option page.', 'uixpress'), ['status' => 500]);
    }

    return new \WP_REST_Response([
      'message' => __('Option page duplicated successfully.', 'uixpress'),
      'data' => $duplicate,
    ], 201);
  }
}
