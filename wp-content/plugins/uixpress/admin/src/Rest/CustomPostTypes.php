<?php
namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomPostTypes
 *
 * REST API endpoints for managing custom post types stored in JSON
 */
class CustomPostTypes
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
  private $base = "custom-post-types";

  /**
   * Path to the JSON storage file
   *
   * @var string
   */
  private $json_file_path;

  /**
   * Initialize the class and set up REST API routes.
   */
  public function __construct()
  {
    $this->json_file_path = WP_CONTENT_DIR . '/uixpress-custom-post-types.json';
    add_action("rest_api_init", [$this, "register_routes"]);
  }

  /**
   * Register the REST API routes.
   */
  public function register_routes()
  {
    // Get all custom post types
    register_rest_route($this->namespace, "/" . $this->base, [
      "methods" => "GET",
      "callback" => [$this, "get_post_types"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Export all custom post types as JSON (must be before dynamic slug route)
    register_rest_route($this->namespace, "/" . $this->base . "/export", [
      "methods" => "GET",
      "callback" => [$this, "export_post_types"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Import custom post types from JSON (must be before dynamic slug route)
    register_rest_route($this->namespace, "/" . $this->base . "/import", [
      "methods" => "POST",
      "callback" => [$this, "import_post_types"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Get available icons
    register_rest_route($this->namespace, "/" . $this->base . "-icons", [
      "methods" => "GET",
      "callback" => [$this, "get_icons"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Get existing taxonomies
    register_rest_route($this->namespace, "/" . $this->base . "-taxonomies", [
      "methods" => "GET",
      "callback" => [$this, "get_taxonomies"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Get a single custom post type (dynamic route - must be last)
    register_rest_route($this->namespace, "/" . $this->base . "/(?P<slug>[a-zA-Z0-9_-]+)", [
      "methods" => "GET",
      "callback" => [$this, "get_post_type"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Create a new custom post type
    register_rest_route($this->namespace, "/" . $this->base, [
      "methods" => "POST",
      "callback" => [$this, "create_post_type"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Update a custom post type (dynamic route - must be after specific routes)
    register_rest_route($this->namespace, "/" . $this->base . "/(?P<slug>[a-zA-Z0-9_-]+)", [
      "methods" => "PUT,PATCH",
      "callback" => [$this, "update_post_type"],
      "permission_callback" => [$this, "permissions_check"],
    ]);

    // Delete a custom post type (dynamic route - must be after specific routes)
    register_rest_route($this->namespace, "/" . $this->base . "/(?P<slug>[a-zA-Z0-9_-]+)", [
      "methods" => "DELETE",
      "callback" => [$this, "delete_post_type"],
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
   * Read post types from JSON file
   *
   * @return array Array of custom post types
   */
  private function read_json_file()
  {
    if (!file_exists($this->json_file_path)) {
      return [];
    }

    $json_content = file_get_contents($this->json_file_path);
    if ($json_content === false) {
      return [];
    }

    $data = json_decode($json_content, true);
    return is_array($data) ? $data : [];
  }

  /**
   * Write post types to JSON file
   *
   * @param array $post_types Array of custom post types
   * @return bool True on success, false on failure
   */
  private function write_json_file($post_types)
  {
    $json_content = wp_json_encode($post_types, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // Ensure directory exists
    $dir = dirname($this->json_file_path);
    if (!file_exists($dir)) {
      wp_mkdir_p($dir);
    }

    $result = file_put_contents($this->json_file_path, $json_content);
    
    // Clear any caches
    if (function_exists('wp_cache_flush')) {
      wp_cache_flush();
    }
    
    return $result !== false;
  }

  /**
   * Get all custom post types
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function get_post_types($request)
  {
    $post_types = $this->read_json_file();
    
    // Add post count for each CPT
    foreach ($post_types as &$cpt) {
      $count = wp_count_posts($cpt['slug']);
      $cpt['post_count'] = isset($count->publish) ? (int)$count->publish : 0;
      $cpt['total_count'] = 0;
      if ($count) {
        foreach ((array)$count as $status => $num) {
          $cpt['total_count'] += (int)$num;
        }
      }
    }

    return new \WP_REST_Response($post_types, 200);
  }

  /**
   * Get a single custom post type
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function get_post_type($request)
  {
    $slug = sanitize_key($request->get_param('slug'));
    
    if (!$this->is_valid_slug($slug)) {
      return new \WP_Error('rest_invalid_slug', __('Invalid post type slug.', 'uixpress'), ['status' => 400]);
    }

    $post_types = $this->read_json_file();
    
    foreach ($post_types as $cpt) {
      if ($cpt['slug'] === $slug) {
        // Add post count
        $count = wp_count_posts($slug);
        $cpt['post_count'] = isset($count->publish) ? (int)$count->publish : 0;
        return new \WP_REST_Response($cpt, 200);
      }
    }

    return new \WP_Error('rest_not_found', __('Custom post type not found.', 'uixpress'), ['status' => 404]);
  }

  /**
   * Create a new custom post type
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function create_post_type($request)
  {
    $body = $request->get_json_params();
    
    // Validate required fields
    if (empty($body['slug'])) {
      return new \WP_Error('rest_missing_slug', __('Post type slug is required.', 'uixpress'), ['status' => 400]);
    }

    if (empty($body['name'])) {
      return new \WP_Error('rest_missing_name', __('Post type name is required.', 'uixpress'), ['status' => 400]);
    }

    $slug = sanitize_key($body['slug']);
    
    // Validate slug format
    if (!$this->is_valid_slug($slug)) {
      return new \WP_Error('rest_invalid_slug', __('Invalid post type slug. Use only lowercase letters, numbers, and underscores. Maximum 20 characters.', 'uixpress'), ['status' => 400]);
    }

    // Check for reserved post types
    if ($this->is_reserved_post_type($slug)) {
      return new \WP_Error('rest_reserved_slug', __('This post type slug is reserved by WordPress.', 'uixpress'), ['status' => 400]);
    }

    $post_types = $this->read_json_file();
    
    // Check if slug already exists
    foreach ($post_types as $existing) {
      if ($existing['slug'] === $slug) {
        return new \WP_Error('rest_slug_exists', __('A custom post type with this slug already exists.', 'uixpress'), ['status' => 409]);
      }
    }

    // Sanitize and prepare the new post type
    $new_cpt = $this->sanitize_cpt_data($body);
    $new_cpt['created_at'] = current_time('mysql');
    $new_cpt['updated_at'] = current_time('mysql');
    
    // Add to array
    $post_types[] = $new_cpt;
    
    // Save to file
    if (!$this->write_json_file($post_types)) {
      return new \WP_Error('rest_save_failed', __('Failed to save custom post type.', 'uixpress'), ['status' => 500]);
    }

    // Flush rewrite rules
    flush_rewrite_rules();

    return new \WP_REST_Response([
      'message' => __('Custom post type created successfully.', 'uixpress'),
      'data' => $new_cpt,
    ], 201);
  }

  /**
   * Update an existing custom post type
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function update_post_type($request)
  {
    $slug = sanitize_key($request->get_param('slug'));
    $body = $request->get_json_params();
    
    if (!$this->is_valid_slug($slug)) {
      return new \WP_Error('rest_invalid_slug', __('Invalid post type slug.', 'uixpress'), ['status' => 400]);
    }

    $post_types = $this->read_json_file();
    $found_index = -1;
    
    foreach ($post_types as $index => $cpt) {
      if ($cpt['slug'] === $slug) {
        $found_index = $index;
        break;
      }
    }

    if ($found_index === -1) {
      return new \WP_Error('rest_not_found', __('Custom post type not found.', 'uixpress'), ['status' => 404]);
    }

    // Preserve original slug and created_at
    $body['slug'] = $slug;
    $body['created_at'] = $post_types[$found_index]['created_at'];
    
    // Sanitize and update
    $updated_cpt = $this->sanitize_cpt_data($body);
    $updated_cpt['updated_at'] = current_time('mysql');
    
    $post_types[$found_index] = $updated_cpt;
    
    // Save to file
    if (!$this->write_json_file($post_types)) {
      return new \WP_Error('rest_save_failed', __('Failed to save custom post type.', 'uixpress'), ['status' => 500]);
    }

    // Flush rewrite rules
    flush_rewrite_rules();

    return new \WP_REST_Response([
      'message' => __('Custom post type updated successfully.', 'uixpress'),
      'data' => $updated_cpt,
    ], 200);
  }

  /**
   * Delete a custom post type
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function delete_post_type($request)
  {
    $slug = sanitize_key($request->get_param('slug'));
    
    if (!$this->is_valid_slug($slug)) {
      return new \WP_Error('rest_invalid_slug', __('Invalid post type slug.', 'uixpress'), ['status' => 400]);
    }

    $post_types = $this->read_json_file();
    $found = false;
    
    $post_types = array_filter($post_types, function($cpt) use ($slug, &$found) {
      if ($cpt['slug'] === $slug) {
        $found = true;
        return false;
      }
      return true;
    });

    if (!$found) {
      return new \WP_Error('rest_not_found', __('Custom post type not found.', 'uixpress'), ['status' => 404]);
    }

    // Re-index array
    $post_types = array_values($post_types);
    
    // Save to file
    if (!$this->write_json_file($post_types)) {
      return new \WP_Error('rest_save_failed', __('Failed to delete custom post type.', 'uixpress'), ['status' => 500]);
    }

    // Flush rewrite rules
    flush_rewrite_rules();

    return new \WP_REST_Response([
      'message' => __('Custom post type deleted successfully.', 'uixpress'),
    ], 200);
  }

  /**
   * Get available icons from assets/icons folder
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function get_icons($request)
  {
    $icons_dir = uixpress_plugin_path . '/assets/icons/';
    $icons = [];
    
    if (is_dir($icons_dir)) {
      $files = scandir($icons_dir);
      foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'svg') {
          // Remove .svg extension to get icon name
          $icon_name = pathinfo($file, PATHINFO_FILENAME);
          // Skip logo and branded icons
          if (!in_array($icon_name, ['logo', 'uipress', 'uixpress', 'uixpress-logo', 'uixpress-logo-text', 'uixpress-logo-text copy', 'vendbase', 'woobase', 'pb-logo-fill', 'pb-logo-lines'])) {
            $icons[] = $icon_name;
          }
        }
      }
      // Sort alphabetically
      sort($icons);
    }

    return new \WP_REST_Response($icons, 200);
  }

  /**
   * Get existing taxonomies
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object.
   */
  public function get_taxonomies($request)
  {
    $taxonomies = get_taxonomies(['public' => true], 'objects');
    $result = [];

    foreach ($taxonomies as $tax) {
      $result[] = [
        'name' => $tax->name,
        'label' => $tax->label,
        'hierarchical' => $tax->hierarchical,
      ];
    }

    return new \WP_REST_Response($result, 200);
  }

  /**
   * Export all custom post types as JSON
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response The response object with JSON data.
   */
  public function export_post_types($request)
  {
    $post_types = $this->read_json_file();
    
    // Remove post counts and other runtime data
    $export_data = array_map(function($cpt) {
      $export = $cpt;
      unset($export['post_count']);
      unset($export['total_count']);
      return $export;
    }, $post_types);

    return new \WP_REST_Response([
      'data' => $export_data,
      'exported_at' => current_time('mysql'),
      'version' => '1.0',
    ], 200);
  }

  /**
   * Import custom post types from JSON
   *
   * @param WP_REST_Request $request The request object.
   * @return WP_REST_Response|WP_Error The response object or error.
   */
  public function import_post_types($request)
  {
    $body = $request->get_json_params();
    
    // Validate request body
    if (empty($body['data']) || !is_array($body['data'])) {
      return new \WP_Error('rest_invalid_data', __('Invalid import data. Expected an array of post types.', 'uixpress'), ['status' => 400]);
    }

    $import_data = $body['data'];
    $mode = sanitize_text_field($body['mode'] ?? 'merge'); // 'merge' or 'replace'
    
    // Validate mode
    if (!in_array($mode, ['merge', 'replace'], true)) {
      return new \WP_Error('rest_invalid_mode', __('Invalid import mode. Use "merge" or "replace".', 'uixpress'), ['status' => 400]);
    }

    $existing_post_types = $this->read_json_file();
    $errors = [];
    $imported = 0;
    $skipped = 0;
    $updated = 0;

    // Validate each post type in import data
    foreach ($import_data as $index => $cpt) {
      // Basic validation
      if (empty($cpt['slug']) || empty($cpt['name'])) {
        $errors[] = sprintf(__('Post type at index %d is missing required fields (slug or name).', 'uixpress'), $index);
        continue;
      }

      $slug = sanitize_key($cpt['slug']);
      
      // Validate slug format
      if (!$this->is_valid_slug($slug)) {
        $errors[] = sprintf(__('Post type "%s" has an invalid slug format.', 'uixpress'), $cpt['name']);
        continue;
      }

      // Check for reserved post types
      if ($this->is_reserved_post_type($slug)) {
        $errors[] = sprintf(__('Post type "%s" uses a reserved slug: %s', 'uixpress'), $cpt['name'], $slug);
        continue;
      }

      // Sanitize the post type data
      $sanitized_cpt = $this->sanitize_cpt_data($cpt);
      
      // Set timestamps
      if ($mode === 'replace' || !isset($sanitized_cpt['created_at'])) {
        $sanitized_cpt['created_at'] = current_time('mysql');
      }
      $sanitized_cpt['updated_at'] = current_time('mysql');

      // Check if post type already exists
      $existing_index = -1;
      foreach ($existing_post_types as $idx => $existing) {
        if ($existing['slug'] === $slug) {
          $existing_index = $idx;
          break;
        }
      }

      if ($existing_index >= 0) {
        if ($mode === 'merge') {
          // Update existing
          $sanitized_cpt['created_at'] = $existing_post_types[$existing_index]['created_at'];
          $existing_post_types[$existing_index] = $sanitized_cpt;
          $updated++;
        } else {
          // Skip if replace mode and already exists (or replace it)
          $existing_post_types[$existing_index] = $sanitized_cpt;
          $updated++;
        }
      } else {
        // Add new post type
        $existing_post_types[] = $sanitized_cpt;
        $imported++;
      }
    }

    // If there are validation errors and mode is strict, return errors
    if (!empty($errors) && isset($body['strict']) && $body['strict'] === true) {
      return new \WP_Error('rest_validation_errors', __('Import validation failed.', 'uixpress'), [
        'status' => 400,
        'errors' => $errors,
      ]);
    }

    // Save to file
    if (!$this->write_json_file($existing_post_types)) {
      return new \WP_Error('rest_save_failed', __('Failed to save imported post types.', 'uixpress'), ['status' => 500]);
    }

    // Flush rewrite rules
    flush_rewrite_rules();

    return new \WP_REST_Response([
      'message' => __('Import completed successfully.', 'uixpress'),
      'imported' => $imported,
      'updated' => $updated,
      'skipped' => $skipped,
      'errors' => $errors,
    ], 200);
  }

  /**
   * Validate slug format
   *
   * @param string $slug The slug to validate
   * @return bool True if valid, false otherwise
   */
  private function is_valid_slug($slug)
  {
    if (empty($slug) || !is_string($slug)) {
      return false;
    }
    
    // WordPress post type slugs: max 20 chars, lowercase letters, numbers, underscores
    return preg_match('/^[a-z0-9_]{1,20}$/', $slug);
  }

  /**
   * Check if slug is a reserved WordPress post type
   *
   * @param string $slug The slug to check
   * @return bool True if reserved, false otherwise
   */
  private function is_reserved_post_type($slug)
  {
    $reserved = [
      'post', 'page', 'attachment', 'revision', 'nav_menu_item', 
      'custom_css', 'customize_changeset', 'oembed_cache', 
      'user_request', 'wp_block', 'wp_template', 'wp_template_part',
      'wp_global_styles', 'wp_navigation', 'action', 'author', 'order',
      'theme'
    ];
    
    return in_array($slug, $reserved);
  }

  /**
   * Generate default labels based on name and singular name
   *
   * @param string $name Plural name (e.g., "Products")
   * @param string $singular_name Singular name (e.g., "Product")
   * @return array Array of generated labels
   */
  private function generate_default_labels($name, $singular_name)
  {
    $name_lower = strtolower($name);
    $singular_lower = strtolower($singular_name);
    
    return [
      'name' => $name,
      'singular_name' => $singular_name,
      'add_new' => __('Add New', 'uixpress'),
      'add_new_item' => sprintf(__('Add New %s', 'uixpress'), $singular_name),
      'edit_item' => sprintf(__('Edit %s', 'uixpress'), $singular_name),
      'new_item' => sprintf(__('New %s', 'uixpress'), $singular_name),
      'view_item' => sprintf(__('View %s', 'uixpress'), $singular_name),
      'view_items' => sprintf(__('View %s', 'uixpress'), $name),
      'search_items' => sprintf(__('Search %s', 'uixpress'), $name),
      'not_found' => sprintf(__('No %s found', 'uixpress'), $name_lower),
      'not_found_in_trash' => sprintf(__('No %s found in Trash', 'uixpress'), $name_lower),
      'parent_item_colon' => sprintf(__('Parent %s:', 'uixpress'), $singular_name),
      'all_items' => sprintf(__('All %s', 'uixpress'), $name),
      'archives' => sprintf(__('%s Archives', 'uixpress'), $singular_name),
      'attributes' => sprintf(__('%s Attributes', 'uixpress'), $singular_name),
      'insert_into_item' => sprintf(__('Insert into %s', 'uixpress'), $singular_lower),
      'uploaded_to_this_item' => sprintf(__('Uploaded to this %s', 'uixpress'), $singular_lower),
      'featured_image' => __('Featured image', 'uixpress'),
      'set_featured_image' => __('Set featured image', 'uixpress'),
      'remove_featured_image' => __('Remove featured image', 'uixpress'),
      'use_featured_image' => __('Use as featured image', 'uixpress'),
      'menu_name' => $name,
      'filter_items_list' => sprintf(__('Filter %s list', 'uixpress'), $name_lower),
      'items_list_navigation' => sprintf(__('%s list navigation', 'uixpress'), $name),
      'items_list' => sprintf(__('%s list', 'uixpress'), $name),
      'item_published' => sprintf(__('%s published.', 'uixpress'), $singular_name),
      'item_published_privately' => sprintf(__('%s published privately.', 'uixpress'), $singular_name),
      'item_reverted_to_draft' => sprintf(__('%s reverted to draft.', 'uixpress'), $singular_name),
      'item_scheduled' => sprintf(__('%s scheduled.', 'uixpress'), $singular_name),
      'item_updated' => sprintf(__('%s updated.', 'uixpress'), $singular_name),
    ];
  }

  /**
   * Sanitize custom post type data
   *
   * @param array $data Raw CPT data
   * @return array Sanitized CPT data
   */
  private function sanitize_cpt_data($data)
  {
    $name = sanitize_text_field($data['name'] ?? '');
    $singular_name = sanitize_text_field($data['singular_name'] ?? $name);
    
    // Generate default labels based on name and singular_name
    $default_labels = $this->generate_default_labels($name, $singular_name);
    
    $sanitized = [
      'slug' => sanitize_key($data['slug']),
      'name' => $name,
      'singular_name' => $singular_name,
      'description' => sanitize_textarea_field($data['description'] ?? ''),
      'menu_icon' => sanitize_text_field($data['menu_icon'] ?? 'article'),
      'active' => isset($data['active']) ? (bool)$data['active'] : true,
      
      // Labels - use provided values or fall back to auto-generated defaults
      'labels' => [
        'name' => sanitize_text_field($data['labels']['name'] ?? '') ?: $default_labels['name'],
        'singular_name' => sanitize_text_field($data['labels']['singular_name'] ?? '') ?: $default_labels['singular_name'],
        'add_new' => sanitize_text_field($data['labels']['add_new'] ?? '') ?: $default_labels['add_new'],
        'add_new_item' => sanitize_text_field($data['labels']['add_new_item'] ?? '') ?: $default_labels['add_new_item'],
        'edit_item' => sanitize_text_field($data['labels']['edit_item'] ?? '') ?: $default_labels['edit_item'],
        'new_item' => sanitize_text_field($data['labels']['new_item'] ?? '') ?: $default_labels['new_item'],
        'view_item' => sanitize_text_field($data['labels']['view_item'] ?? '') ?: $default_labels['view_item'],
        'view_items' => sanitize_text_field($data['labels']['view_items'] ?? '') ?: $default_labels['view_items'],
        'search_items' => sanitize_text_field($data['labels']['search_items'] ?? '') ?: $default_labels['search_items'],
        'not_found' => sanitize_text_field($data['labels']['not_found'] ?? '') ?: $default_labels['not_found'],
        'not_found_in_trash' => sanitize_text_field($data['labels']['not_found_in_trash'] ?? '') ?: $default_labels['not_found_in_trash'],
        'parent_item_colon' => sanitize_text_field($data['labels']['parent_item_colon'] ?? '') ?: $default_labels['parent_item_colon'],
        'all_items' => sanitize_text_field($data['labels']['all_items'] ?? '') ?: $default_labels['all_items'],
        'archives' => sanitize_text_field($data['labels']['archives'] ?? '') ?: $default_labels['archives'],
        'attributes' => sanitize_text_field($data['labels']['attributes'] ?? '') ?: $default_labels['attributes'],
        'insert_into_item' => sanitize_text_field($data['labels']['insert_into_item'] ?? '') ?: $default_labels['insert_into_item'],
        'uploaded_to_this_item' => sanitize_text_field($data['labels']['uploaded_to_this_item'] ?? '') ?: $default_labels['uploaded_to_this_item'],
        'featured_image' => sanitize_text_field($data['labels']['featured_image'] ?? '') ?: $default_labels['featured_image'],
        'set_featured_image' => sanitize_text_field($data['labels']['set_featured_image'] ?? '') ?: $default_labels['set_featured_image'],
        'remove_featured_image' => sanitize_text_field($data['labels']['remove_featured_image'] ?? '') ?: $default_labels['remove_featured_image'],
        'use_featured_image' => sanitize_text_field($data['labels']['use_featured_image'] ?? '') ?: $default_labels['use_featured_image'],
        'menu_name' => sanitize_text_field($data['labels']['menu_name'] ?? '') ?: $default_labels['menu_name'],
        'filter_items_list' => sanitize_text_field($data['labels']['filter_items_list'] ?? '') ?: $default_labels['filter_items_list'],
        'items_list_navigation' => sanitize_text_field($data['labels']['items_list_navigation'] ?? '') ?: $default_labels['items_list_navigation'],
        'items_list' => sanitize_text_field($data['labels']['items_list'] ?? '') ?: $default_labels['items_list'],
        'item_published' => sanitize_text_field($data['labels']['item_published'] ?? '') ?: $default_labels['item_published'],
        'item_published_privately' => sanitize_text_field($data['labels']['item_published_privately'] ?? '') ?: $default_labels['item_published_privately'],
        'item_reverted_to_draft' => sanitize_text_field($data['labels']['item_reverted_to_draft'] ?? '') ?: $default_labels['item_reverted_to_draft'],
        'item_scheduled' => sanitize_text_field($data['labels']['item_scheduled'] ?? '') ?: $default_labels['item_scheduled'],
        'item_updated' => sanitize_text_field($data['labels']['item_updated'] ?? '') ?: $default_labels['item_updated'],
      ],
      
      // Options
      'public' => isset($data['public']) ? (bool)$data['public'] : true,
      'publicly_queryable' => isset($data['publicly_queryable']) ? (bool)$data['publicly_queryable'] : true,
      'show_ui' => isset($data['show_ui']) ? (bool)$data['show_ui'] : true,
      'show_in_menu' => isset($data['show_in_menu']) ? (bool)$data['show_in_menu'] : true,
      'show_in_nav_menus' => isset($data['show_in_nav_menus']) ? (bool)$data['show_in_nav_menus'] : true,
      'show_in_admin_bar' => isset($data['show_in_admin_bar']) ? (bool)$data['show_in_admin_bar'] : true,
      'show_in_rest' => isset($data['show_in_rest']) ? (bool)$data['show_in_rest'] : true,
      'rest_base' => sanitize_key($data['rest_base'] ?? $data['slug'] ?? ''),
      'rest_namespace' => sanitize_text_field($data['rest_namespace'] ?? 'wp/v2'),
      'rest_controller_class' => sanitize_text_field($data['rest_controller_class'] ?? 'WP_REST_Posts_Controller'),
      'menu_position' => isset($data['menu_position']) && is_numeric($data['menu_position']) ? absint($data['menu_position']) : 25,
      'capability_type' => sanitize_key($data['capability_type'] ?? 'post'),
      'map_meta_cap' => isset($data['map_meta_cap']) ? (bool)$data['map_meta_cap'] : true,
      'hierarchical' => isset($data['hierarchical']) ? (bool)$data['hierarchical'] : false,
      'supports' => $this->sanitize_supports($data['supports'] ?? ['title', 'editor', 'thumbnail']),
      'taxonomies' => $this->sanitize_taxonomies($data['taxonomies'] ?? []),
      'has_archive' => isset($data['has_archive']) ? (bool)$data['has_archive'] : true,
      'can_export' => isset($data['can_export']) ? (bool)$data['can_export'] : true,
      'delete_with_user' => isset($data['delete_with_user']) ? (bool)$data['delete_with_user'] : false,
      'exclude_from_search' => isset($data['exclude_from_search']) ? (bool)$data['exclude_from_search'] : false,
      'query_var' => isset($data['query_var']) ? (bool)$data['query_var'] : true,
      
      // Rewrite options
      'rewrite' => [
        'slug' => sanitize_title($data['rewrite']['slug'] ?? $data['slug'] ?? ''),
        'with_front' => isset($data['rewrite']['with_front']) ? (bool)$data['rewrite']['with_front'] : true,
        'feeds' => isset($data['rewrite']['feeds']) ? (bool)$data['rewrite']['feeds'] : true,
        'pages' => isset($data['rewrite']['pages']) ? (bool)$data['rewrite']['pages'] : true,
      ],
      
      // Timestamps
      'created_at' => $data['created_at'] ?? current_time('mysql'),
      'updated_at' => $data['updated_at'] ?? current_time('mysql'),
    ];

    return $sanitized;
  }

  /**
   * Sanitize supports array
   *
   * @param array $supports Array of support features
   * @return array Sanitized supports array
   */
  private function sanitize_supports($supports)
  {
    $valid_supports = [
      'title', 'editor', 'author', 'thumbnail', 'excerpt', 
      'trackbacks', 'custom-fields', 'comments', 'revisions', 
      'page-attributes', 'post-formats'
    ];
    
    if (!is_array($supports)) {
      return ['title', 'editor'];
    }
    
    return array_values(array_intersect($supports, $valid_supports));
  }

  /**
   * Sanitize taxonomies array
   *
   * @param array $taxonomies Array of taxonomies
   * @return array Sanitized taxonomies array
   */
  private function sanitize_taxonomies($taxonomies)
  {
    if (!is_array($taxonomies)) {
      return [];
    }
    
    return array_map('sanitize_key', $taxonomies);
  }

  /**
   * Get menu icon URL from icon name
   * Converts SVG icon name to a data URI for WordPress admin menu
   *
   * @param string $icon_name The icon name (without .svg extension)
   * @return string The icon URL or data URI
   */
  private static function get_menu_icon_url($icon_name)
  {
    // If it's already a dashicon or URL, return as-is
    if (strpos($icon_name, 'dashicons-') === 0 || strpos($icon_name, 'http') === 0 || strpos($icon_name, 'data:') === 0) {
      return $icon_name;
    }

    // Build path to SVG file
    $svg_path = uixpress_plugin_path . '/assets/icons/' . $icon_name . '.svg';
    
    if (file_exists($svg_path)) {
      $svg_content = file_get_contents($svg_path);
      if ($svg_content) {
        // Encode as base64 data URI
        return 'data:image/svg+xml;base64,' . base64_encode($svg_content);
      }
    }

    // Fallback to dashicons-admin-post if icon not found
    return 'dashicons-admin-post';
  }

  /**
   * Register custom post types from JSON file
   * This should be called on 'init' hook
   */
  public static function register_custom_post_types()
  {
    $json_file_path = WP_CONTENT_DIR . '/uixpress-custom-post-types.json';
    
    if (!file_exists($json_file_path)) {
      return;
    }

    $json_content = file_get_contents($json_file_path);
    if ($json_content === false) {
      return;
    }

    $post_types = json_decode($json_content, true);
    if (!is_array($post_types)) {
      return;
    }

    foreach ($post_types as $cpt) {
      if (empty($cpt['slug']) || empty($cpt['active'])) {
        continue;
      }

      $args = [
        'label' => $cpt['name'],
        'description' => $cpt['description'] ?? '',
        'labels' => $cpt['labels'] ?? [],
        'public' => $cpt['public'] ?? true,
        'publicly_queryable' => $cpt['publicly_queryable'] ?? true,
        'show_ui' => $cpt['show_ui'] ?? true,
        'show_in_menu' => $cpt['show_in_menu'] ?? true,
        'show_in_nav_menus' => $cpt['show_in_nav_menus'] ?? true,
        'show_in_admin_bar' => $cpt['show_in_admin_bar'] ?? true,
        'show_in_rest' => $cpt['show_in_rest'] ?? true,
        'rest_base' => $cpt['rest_base'] ?? $cpt['slug'],
        'rest_namespace' => $cpt['rest_namespace'] ?? 'wp/v2',
        'rest_controller_class' => $cpt['rest_controller_class'] ?? 'WP_REST_Posts_Controller',
        'menu_position' => $cpt['menu_position'] ?? 25,
        'menu_icon' => self::get_menu_icon_url($cpt['menu_icon'] ?? 'article'),
        'capability_type' => $cpt['capability_type'] ?? 'post',
        'map_meta_cap' => $cpt['map_meta_cap'] ?? true,
        'hierarchical' => $cpt['hierarchical'] ?? false,
        'supports' => $cpt['supports'] ?? ['title', 'editor', 'thumbnail'],
        'taxonomies' => $cpt['taxonomies'] ?? [],
        'has_archive' => $cpt['has_archive'] ?? true,
        'rewrite' => $cpt['rewrite'] ?? ['slug' => $cpt['slug']],
        'can_export' => $cpt['can_export'] ?? true,
        'delete_with_user' => $cpt['delete_with_user'] ?? false,
        'exclude_from_search' => $cpt['exclude_from_search'] ?? false,
        'query_var' => $cpt['query_var'] ?? true,
      ];

      register_post_type($cpt['slug'], $args);
    }
  }
}

