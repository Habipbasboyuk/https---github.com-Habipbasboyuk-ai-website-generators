<?php
namespace UiXpress\Rest\CustomFields;

use UiXpress\Rest\CustomFields\FieldGroupRepository;
use UiXpress\Rest\CustomFields\FieldGroupSanitizer;
use UiXpress\Rest\CustomFields\LocationDataProvider;
use UiXpress\Rest\CustomFields\LocationRuleEvaluator;
use UiXpress\Rest\CustomFields\MetaBoxManager;
use UiXpress\Rest\CustomFields\FieldSaver;
use UiXpress\Rest\RestPermissionChecker;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomFields
 *
 * REST API endpoints for managing custom field groups stored in JSON
 */
class CustomFields
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
  private $base = "custom-fields";

  /**
   * Allowed functions for preview endpoint (security whitelist)
   *
   * @var array
   */
  private static $allowed_preview_functions = [
    'uixpress_get_field',
    'uixpress_get_post_field',
    'uixpress_get_term_field',
    'uixpress_get_user_field',
    'uixpress_get_option_field',
    'uixpress_get_image_field',
    'uixpress_get_file_field',
    'uixpress_get_link_field',
    'uixpress_get_repeater_field',
    'uixpress_get_relationship_field',
    'uixpress_get_google_map_field',
    'uixpress_get_date_field',
  ];

  /**
   * @var FieldGroupRepository
   */
  private $repository;

  /**
   * @var FieldGroupSanitizer
   */
  private $sanitizer;

  /**
   * @var LocationDataProvider
   */
  private $location_data_provider;

  /**
   * @var MetaBoxManager
   */
  private $meta_box_manager;

  /**
   * @var FieldSaver
   */
  private $field_saver;

  /**
   * Initialize the class and set up REST API routes.
   */
  public function __construct()
  {
    $json_file_path = WP_CONTENT_DIR . '/uixpress-custom-fields.json';
    
    // Initialize components
    $this->repository = new FieldGroupRepository($json_file_path);
    $this->sanitizer = new FieldGroupSanitizer($this->repository);
    $this->location_data_provider = new LocationDataProvider();
    
    $evaluator = new LocationRuleEvaluator();
    $this->meta_box_manager = new MetaBoxManager($this->repository, $evaluator);
    $this->field_saver = new FieldSaver($this->repository, $evaluator);
    
    add_action("rest_api_init", [$this, "register_routes"]);
  }

  /**
   * Register the REST API routes.
   */
  public function register_routes()
  {
    // Get all field groups
    register_rest_route($this->namespace, "/" . $this->base, [
      "methods" => "GET",
      "callback" => [$this, "get_field_groups"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Export all field groups as JSON
    register_rest_route($this->namespace, "/" . $this->base . "/export", [
      "methods" => "GET",
      "callback" => [$this, "export_field_groups"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Import field groups from JSON
    register_rest_route($this->namespace, "/" . $this->base . "/import", [
      "methods" => "POST",
      "callback" => [$this, "import_field_groups"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Get available post types for location rules
    register_rest_route($this->namespace, "/" . $this->base . "-post-types", [
      "methods" => "GET",
      "callback" => [$this, "get_post_types"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Get available templates for location rules
    register_rest_route($this->namespace, "/" . $this->base . "-templates", [
      "methods" => "GET",
      "callback" => [$this, "get_templates"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Get all location rule data (taxonomies, users, etc.)
    register_rest_route($this->namespace, "/" . $this->base . "-location-data", [
      "methods" => "GET",
      "callback" => [$this, "get_location_data"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Preview field value (for code examples)
    // NOTE: Must be registered BEFORE dynamic (?P<id>...) routes to avoid conflicts
    register_rest_route($this->namespace, "/" . $this->base . "/preview-value", [
      "methods" => "POST",
      "callback" => [$this, "preview_field_value"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Search posts for preview selector
    // NOTE: Must be registered BEFORE dynamic (?P<id>...) routes to avoid conflicts
    register_rest_route($this->namespace, "/" . $this->base . "/search-posts", [
      "methods" => "GET",
      "callback" => [$this, "search_posts"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Search taxonomy terms for preview selector
    // NOTE: Must be registered BEFORE dynamic (?P<id>...) routes to avoid conflicts
    register_rest_route($this->namespace, "/" . $this->base . "/search-terms", [
      "methods" => "GET",
      "callback" => [$this, "search_terms"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Search users for preview selector
    // NOTE: Must be registered BEFORE dynamic (?P<id>...) routes to avoid conflicts
    register_rest_route($this->namespace, "/" . $this->base . "/search-users", [
      "methods" => "GET",
      "callback" => [$this, "search_users"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Get a single field group
    register_rest_route($this->namespace, "/" . $this->base . "/(?P<id>[a-zA-Z0-9_-]+)", [
      "methods" => "GET",
      "callback" => [$this, "get_field_group"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Create a new field group
    register_rest_route($this->namespace, "/" . $this->base, [
      "methods" => "POST",
      "callback" => [$this, "create_field_group"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Update a field group
    register_rest_route($this->namespace, "/" . $this->base . "/(?P<id>[a-zA-Z0-9_-]+)", [
      "methods" => "PUT,PATCH",
      "callback" => [$this, "update_field_group"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Delete a field group
    register_rest_route($this->namespace, "/" . $this->base . "/(?P<id>[a-zA-Z0-9_-]+)", [
      "methods" => "DELETE",
      "callback" => [$this, "delete_field_group"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Duplicate a field group
    register_rest_route($this->namespace, "/" . $this->base . "/(?P<id>[a-zA-Z0-9_-]+)/duplicate", [
      "methods" => "POST",
      "callback" => [$this, "duplicate_field_group"],
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
   * Get all field groups
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function get_field_groups($request)
  {
    $field_groups = $this->repository->read();
    
    // Add field count for each group
    foreach ($field_groups as &$group) {
      $group['field_count'] = isset($group['fields']) ? count($group['fields']) : 0;
    }

    return new \WP_REST_Response($field_groups, 200);
  }

  /**
   * Get a single field group
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function get_field_group($request)
  {
    $id = sanitize_text_field($request->get_param('id'));
    
    if (!$this->sanitizer->is_valid_id($id)) {
      return new \WP_Error('rest_invalid_id', __('Invalid field group ID.', 'uixpress'), ['status' => 400]);
    }

    $group = $this->repository->find_by_id($id);
    
    if ($group === null) {
      return new \WP_Error('rest_not_found', __('Field group not found.', 'uixpress'), ['status' => 404]);
    }

    return new \WP_REST_Response($group, 200);
  }

  /**
   * Create a new field group
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function create_field_group($request)
  {
    $body = $request->get_json_params();
    
    // Validate required fields
    if (empty($body['title'])) {
      return new \WP_Error('rest_missing_title', __('Field group title is required.', 'uixpress'), ['status' => 400]);
    }

    $field_groups = $this->repository->read();
    
    // Generate unique ID
    $id = $this->repository->generate_id();
    
    // Ensure ID is unique
    while ($this->repository->id_exists($id, $field_groups)) {
      $id = $this->repository->generate_id();
    }

    // Sanitize and prepare the new field group
    $new_group = $this->sanitizer->sanitize_field_group_data($body);
    $new_group['id'] = $id;
    $new_group['created_at'] = current_time('mysql');
    $new_group['updated_at'] = current_time('mysql');
    
    // Add to array
    $field_groups[] = $new_group;
    
    // Save to file
    if (!$this->repository->write($field_groups)) {
      return new \WP_Error('rest_save_failed', __('Failed to save field group.', 'uixpress'), ['status' => 500]);
    }

    return new \WP_REST_Response([
      'message' => __('Field group created successfully.', 'uixpress'),
      'data' => $new_group,
    ], 201);
  }

  /**
   * Update an existing field group
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function update_field_group($request)
  {
    $id = sanitize_text_field($request->get_param('id'));
    $body = $request->get_json_params();
    
    if (!$this->sanitizer->is_valid_id($id)) {
      return new \WP_Error('rest_invalid_id', __('Invalid field group ID.', 'uixpress'), ['status' => 400]);
    }

    $field_groups = $this->repository->read();
    $found_index = $this->repository->find_index_by_id($id);

    if ($found_index === -1) {
      return new \WP_Error('rest_not_found', __('Field group not found.', 'uixpress'), ['status' => 404]);
    }

    // Preserve original ID and created_at
    $body['id'] = $id;
    $body['created_at'] = $field_groups[$found_index]['created_at'];
    
    // Sanitize and update
    $updated_group = $this->sanitizer->sanitize_field_group_data($body);
    $updated_group['id'] = $id;
    $updated_group['updated_at'] = current_time('mysql');
    
    $field_groups[$found_index] = $updated_group;
    
    // Save to file
    if (!$this->repository->write($field_groups)) {
      return new \WP_Error('rest_save_failed', __('Failed to save field group.', 'uixpress'), ['status' => 500]);
    }

    return new \WP_REST_Response([
      'message' => __('Field group updated successfully.', 'uixpress'),
      'data' => $updated_group,
    ], 200);
  }

  /**
   * Delete a field group
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function delete_field_group($request)
  {
    $id = sanitize_text_field($request->get_param('id'));
    
    if (!$this->sanitizer->is_valid_id($id)) {
      return new \WP_Error('rest_invalid_id', __('Invalid field group ID.', 'uixpress'), ['status' => 400]);
    }

    $field_groups = $this->repository->read();
    $found = false;
    
    $field_groups = array_filter($field_groups, function($group) use ($id, &$found) {
      if ($group['id'] === $id) {
        $found = true;
        return false;
      }
      return true;
    });

    if (!$found) {
      return new \WP_Error('rest_not_found', __('Field group not found.', 'uixpress'), ['status' => 404]);
    }

    // Re-index array
    $field_groups = array_values($field_groups);
    
    // Save to file
    if (!$this->repository->write($field_groups)) {
      return new \WP_Error('rest_save_failed', __('Failed to delete field group.', 'uixpress'), ['status' => 500]);
    }

    return new \WP_REST_Response([
      'message' => __('Field group deleted successfully.', 'uixpress'),
    ], 200);
  }

  /**
   * Duplicate a field group
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function duplicate_field_group($request)
  {
    $id = sanitize_text_field($request->get_param('id'));
    
    if (!$this->sanitizer->is_valid_id($id)) {
      return new \WP_Error('rest_invalid_id', __('Invalid field group ID.', 'uixpress'), ['status' => 400]);
    }

    $field_groups = $this->repository->read();
    $original = $this->repository->find_by_id($id);

    if (!$original) {
      return new \WP_Error('rest_not_found', __('Field group not found.', 'uixpress'), ['status' => 404]);
    }

    // Generate new ID
    $new_id = $this->repository->generate_id();
    while ($this->repository->id_exists($new_id, $field_groups)) {
      $new_id = $this->repository->generate_id();
    }

    // Create duplicate
    $duplicate = $original;
    $duplicate['id'] = $new_id;
    $duplicate['title'] = $original['title'] . ' ' . __('(Copy)', 'uixpress');
    $duplicate['created_at'] = current_time('mysql');
    $duplicate['updated_at'] = current_time('mysql');

    // Regenerate field IDs
    if (!empty($duplicate['fields'])) {
      $duplicate['fields'] = $this->sanitizer->regenerate_field_ids($duplicate['fields']);
    }

    $field_groups[] = $duplicate;
    
    // Save to file
    if (!$this->repository->write($field_groups)) {
      return new \WP_Error('rest_save_failed', __('Failed to duplicate field group.', 'uixpress'), ['status' => 500]);
    }

    return new \WP_REST_Response([
      'message' => __('Field group duplicated successfully.', 'uixpress'),
      'data' => $duplicate,
    ], 201);
  }

  /**
   * Export all field groups as JSON
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object with JSON data.
   */
  public function export_field_groups($request)
  {
    $field_groups = $this->repository->read();
    
    // Remove runtime data
    $export_data = array_map(function($group) {
      $export = $group;
      unset($export['field_count']);
      return $export;
    }, $field_groups);

    return new \WP_REST_Response([
      'data' => $export_data,
      'exported_at' => current_time('mysql'),
      'version' => '1.0',
    ], 200);
  }

  /**
   * Import field groups from JSON
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object or error.
   */
  public function import_field_groups($request)
  {
    $body = $request->get_json_params();
    
    // Validate request body
    if (empty($body['data']) || !is_array($body['data'])) {
      return new \WP_Error('rest_invalid_data', __('Invalid import data. Expected an array of field groups.', 'uixpress'), ['status' => 400]);
    }

    $import_data = $body['data'];
    $mode = sanitize_text_field($body['mode'] ?? 'merge'); // 'merge' or 'replace'
    
    // Validate mode
    if (!in_array($mode, ['merge', 'replace'], true)) {
      return new \WP_Error('rest_invalid_mode', __('Invalid import mode. Use "merge" or "replace".', 'uixpress'), ['status' => 400]);
    }

    $existing_groups = $mode === 'replace' ? [] : $this->repository->read();
    $errors = [];
    $imported = 0;
    $updated = 0;

    // Process each field group
    foreach ($import_data as $index => $group) {
      // Basic validation
      if (empty($group['title'])) {
        $errors[] = sprintf(__('Field group at index %d is missing a title.', 'uixpress'), $index);
        continue;
      }

      // Sanitize the field group data
      $sanitized_group = $this->sanitizer->sanitize_field_group_data($group);
      
      // Check if group ID already exists
      $existing_index = -1;
      if (!empty($sanitized_group['id'])) {
        $existing_index = $this->repository->find_index_by_id($sanitized_group['id']);
      }

      if ($existing_index >= 0) {
        // Update existing
        $sanitized_group['created_at'] = $existing_groups[$existing_index]['created_at'];
        $sanitized_group['updated_at'] = current_time('mysql');
        $existing_groups[$existing_index] = $sanitized_group;
        $updated++;
      } else {
        // Add new group with new ID if needed
        if (empty($sanitized_group['id']) || $this->repository->id_exists($sanitized_group['id'], $existing_groups)) {
          $sanitized_group['id'] = $this->repository->generate_id();
        }
        $sanitized_group['created_at'] = current_time('mysql');
        $sanitized_group['updated_at'] = current_time('mysql');
        $existing_groups[] = $sanitized_group;
        $imported++;
      }
    }

    // Save to file
    if (!$this->repository->write($existing_groups)) {
      return new \WP_Error('rest_save_failed', __('Failed to save imported field groups.', 'uixpress'), ['status' => 500]);
    }

    return new \WP_REST_Response([
      'message' => __('Import completed successfully.', 'uixpress'),
      'imported' => $imported,
      'updated' => $updated,
      'errors' => $errors,
    ], 200);
  }

  /**
   * Get available post types for location rules
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function get_post_types($request)
  {
    $result = $this->location_data_provider->get_post_types();
    return new \WP_REST_Response($result, 200);
  }

  /**
   * Get available templates for location rules
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function get_templates($request)
  {
    $result = $this->location_data_provider->get_templates();
    return new \WP_REST_Response($result, 200);
  }

  /**
   * Get all location rule data for the UI
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function get_location_data($request)
  {
    try {
      $data = $this->location_data_provider->get_location_data();
      return new \WP_REST_Response($data, 200);
    } catch (\Exception $e) {
      return new \WP_Error('location_data_error', $e->getMessage(), ['status' => 500]);
    }
  }

  /**
   * Preview a field value for code examples
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response|\WP_Error The response object.
   */
  public function preview_field_value($request)
  {
    $body = $request->get_json_params();

    // Validate required parameters
    $function = sanitize_text_field($body['function'] ?? '');
    $field_name = sanitize_text_field($body['field_name'] ?? '');
    $object_id = absint($body['object_id'] ?? 0);
    $context = sanitize_text_field($body['context'] ?? 'post');
    $option_page = sanitize_text_field($body['option_page'] ?? '');
    $options = isset($body['options']) && is_array($body['options']) ? $body['options'] : [];

    // Security: Validate function is in whitelist
    if (!in_array($function, self::$allowed_preview_functions, true)) {
      return new \WP_Error(
        'rest_invalid_function',
        __('Invalid or unauthorized function.', 'uixpress'),
        ['status' => 400]
      );
    }

    // Validate field name
    if (empty($field_name)) {
      return new \WP_Error(
        'rest_missing_field_name',
        __('Field name is required.', 'uixpress'),
        ['status' => 400]
      );
    }

    // Validate object ID or option page based on context
    if ($context === 'option') {
      if (empty($option_page)) {
        return new \WP_Error(
          'rest_invalid_option_page',
          __('Valid option page is required.', 'uixpress'),
          ['status' => 400]
        );
      }
    } else {
      if ($object_id <= 0) {
        return new \WP_Error(
          'rest_invalid_object_id',
          __('Valid object ID is required.', 'uixpress'),
          ['status' => 400]
        );
      }
    }

    // Check if the helper functions file is loaded
    if (!function_exists($function)) {
      return new \WP_Error(
        'rest_function_not_available',
        __('Helper function not available. Please ensure the plugin is properly initialized.', 'uixpress'),
        ['status' => 500]
      );
    }

    try {
      // Build options array with context
      $call_options = array_merge($options, ['context' => $context, 'format' => 'raw']);

      // Call the appropriate helper function
      if ($context === 'option') {
        // For option pages, pass the option page slug instead of object ID
        $value = call_user_func($function, $field_name, $option_page, $call_options);
      } else {
        $value = call_user_func($function, $field_name, $object_id, $call_options);
      }

      // Format for JSON display
      $formatted = $this->format_value_for_display($value);

      $response_data = [
        'success' => true,
        'value' => $value,
        'formatted' => $formatted,
        'context' => $context,
      ];

      if ($context === 'option') {
        $response_data['option_page'] = $option_page;
      } else {
        $response_data['object_id'] = $object_id;
      }

      return new \WP_REST_Response($response_data, 200);
    } catch (\Exception $e) {
      return new \WP_Error(
        'rest_preview_error',
        $e->getMessage(),
        ['status' => 500]
      );
    }
  }

  /**
   * Format a value for JSON display in code examples
   *
   * @param mixed $value The value to format.
   * @return string JSON formatted string.
   */
  private function format_value_for_display($value)
  {
    if ($value === null) {
      return 'null';
    }
    
    if ($value === '') {
      return '""';
    }
    
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    
    if (is_array($value) || is_object($value)) {
      return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
    if (is_numeric($value)) {
      return (string) $value;
    }
    
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  /**
   * Search posts for preview selector
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function search_posts($request)
  {
    $search = sanitize_text_field($request->get_param('search') ?? '');
    $post_type = sanitize_text_field($request->get_param('post_type') ?? 'post');
    $per_page = min(absint($request->get_param('per_page') ?? 10), 50);
    $page = max(absint($request->get_param('page') ?? 1), 1);

    // Validate post type exists
    if (!post_type_exists($post_type)) {
      $post_type = 'post';
    }

    $args = [
      'post_type' => $post_type,
      'post_status' => 'any',
      'posts_per_page' => $per_page,
      'paged' => $page,
      'orderby' => 'date',
      'order' => 'DESC',
    ];

    if (!empty($search)) {
      $args['s'] = $search;
    }

    $query = new \WP_Query($args);
    $results = [];

    foreach ($query->posts as $post) {
      $results[] = [
        'id' => $post->ID,
        'title' => $post->post_title ?: __('(no title)', 'uixpress'),
        'status' => $post->post_status,
        'type' => $post->post_type,
        'date' => get_the_date('Y-m-d', $post),
      ];
    }

    return new \WP_REST_Response([
      'results' => $results,
      'total' => $query->found_posts,
      'page' => $page,
      'per_page' => $per_page,
      'total_pages' => ceil($query->found_posts / $per_page),
    ], 200);
  }

  /**
   * Search taxonomy terms for preview selector
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function search_terms($request)
  {
    $search = sanitize_text_field($request->get_param('search') ?? '');
    $taxonomy = sanitize_text_field($request->get_param('taxonomy') ?? 'category');
    $per_page = min(absint($request->get_param('per_page') ?? 10), 50);
    $page = max(absint($request->get_param('page') ?? 1), 1);

    // Validate taxonomy exists
    if (!taxonomy_exists($taxonomy)) {
      $taxonomy = 'category';
    }

    // First get total count
    $count_args = [
      'taxonomy' => $taxonomy,
      'hide_empty' => false,
      'fields' => 'count',
    ];
    if (!empty($search)) {
      $count_args['search'] = $search;
    }
    $total = wp_count_terms($count_args);
    if (is_wp_error($total)) {
      $total = 0;
    }

    // Then get paginated results
    $args = [
      'taxonomy' => $taxonomy,
      'hide_empty' => false,
      'number' => $per_page,
      'offset' => ($page - 1) * $per_page,
      'orderby' => 'name',
      'order' => 'ASC',
    ];

    if (!empty($search)) {
      $args['search'] = $search;
    }

    $terms = get_terms($args);
    $results = [];

    if (!is_wp_error($terms)) {
      foreach ($terms as $term) {
        $results[] = [
          'id' => $term->term_id,
          'name' => $term->name,
          'slug' => $term->slug,
          'taxonomy' => $term->taxonomy,
          'count' => $term->count,
        ];
      }
    }

    return new \WP_REST_Response([
      'results' => $results,
      'total' => (int) $total,
      'page' => $page,
      'per_page' => $per_page,
      'total_pages' => ceil($total / $per_page),
    ], 200);
  }

  /**
   * Search users for preview selector
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function search_users($request)
  {
    $search = sanitize_text_field($request->get_param('search') ?? '');
    $role = sanitize_text_field($request->get_param('role') ?? '');
    $per_page = min(absint($request->get_param('per_page') ?? 10), 50);
    $page = max(absint($request->get_param('page') ?? 1), 1);

    // Build count args
    $count_args = [
      'count_total' => true,
      'number' => -1,
      'fields' => 'ID',
    ];

    if (!empty($search)) {
      $count_args['search'] = '*' . $search . '*';
      $count_args['search_columns'] = ['user_login', 'user_nicename', 'user_email', 'display_name'];
    }

    if (!empty($role)) {
      $count_args['role'] = $role;
    }

    $total_query = new \WP_User_Query($count_args);
    $total = $total_query->get_total();

    // Build paginated query args
    $args = [
      'number' => $per_page,
      'offset' => ($page - 1) * $per_page,
      'orderby' => 'display_name',
      'order' => 'ASC',
    ];

    if (!empty($search)) {
      $args['search'] = '*' . $search . '*';
      $args['search_columns'] = ['user_login', 'user_nicename', 'user_email', 'display_name'];
    }

    if (!empty($role)) {
      $args['role'] = $role;
    }

    $users = get_users($args);
    $results = [];

    foreach ($users as $user) {
      $results[] = [
        'id' => $user->ID,
        'display_name' => $user->display_name,
        'user_login' => $user->user_login,
        'email' => $user->user_email,
        'roles' => $user->roles,
      ];
    }

    return new \WP_REST_Response([
      'results' => $results,
      'total' => (int) $total,
      'page' => $page,
      'per_page' => $per_page,
      'total_pages' => ceil($total / $per_page),
    ], 200);
  }

  /**
   * Static method wrappers for backward compatibility
   */
  
  /**
   * Check if we have active field groups
   */
  public static function has_active_field_groups()
  {
    $json_file_path = WP_CONTENT_DIR . '/uixpress-custom-fields.json';
    $repository = new FieldGroupRepository($json_file_path);
    return $repository->has_active_groups();
  }

  /**
   * Print scripts for block editor
   */
  public static function enqueue_block_editor_scripts()
  {
    $json_file_path = WP_CONTENT_DIR . '/uixpress-custom-fields.json';
    $repository = new FieldGroupRepository($json_file_path);
    $evaluator = new LocationRuleEvaluator();
    $manager = new MetaBoxManager($repository, $evaluator);
    $manager->enqueue_block_editor_scripts();
  }

  /**
   * Print scripts for classic editor
   */
  public static function print_meta_box_scripts()
  {
    $json_file_path = WP_CONTENT_DIR . '/uixpress-custom-fields.json';
    $repository = new FieldGroupRepository($json_file_path);
    $evaluator = new LocationRuleEvaluator();
    $manager = new MetaBoxManager($repository, $evaluator);
    $manager->print_meta_box_scripts();
  }

  /**
   * Register custom fields meta boxes
   */
  public static function register_meta_boxes()
  {
    $json_file_path = WP_CONTENT_DIR . '/uixpress-custom-fields.json';
    $repository = new FieldGroupRepository($json_file_path);
    $evaluator = new LocationRuleEvaluator();
    $manager = new MetaBoxManager($repository, $evaluator);
    $manager->register_meta_boxes();
  }

  /**
   * Save custom fields
   */
  public static function save_custom_fields($post_id)
  {
    $json_file_path = WP_CONTENT_DIR . '/uixpress-custom-fields.json';
    $repository = new FieldGroupRepository($json_file_path);
    $evaluator = new LocationRuleEvaluator();
    $saver = new FieldSaver($repository, $evaluator);
    $saver->save_custom_fields($post_id);
  }
}

