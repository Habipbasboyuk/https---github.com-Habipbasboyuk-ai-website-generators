<?php
namespace UiXpress\Rest\CustomFields;

use UiXpress\Rest\RestPermissionChecker;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class RelationshipSearch
 *
 * REST API endpoints for searching posts and taxonomy terms
 * for the relationship field type.
 */
class RelationshipSearch
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
  private $base = "relationship-search";

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
    // Search posts
    register_rest_route($this->namespace, "/" . $this->base . "/posts", [
      "methods" => "GET",
      "callback" => [$this, "search_posts"],
      "permission_callback" => [$this, "permissions_check"],
      "args" => [
        "search" => [
          "type" => "string",
          "sanitize_callback" => "sanitize_text_field",
          "default" => "",
        ],
        "post_type" => [
          "type" => "array",
          "items" => ["type" => "string"],
          "default" => ["post", "page"],
        ],
        "status" => [
          "type" => "array",
          "items" => ["type" => "string"],
          "default" => ["publish"],
        ],
        "exclude" => [
          "type" => "array",
          "items" => ["type" => "integer"],
          "default" => [],
        ],
        "include" => [
          "type" => "array",
          "items" => ["type" => "integer"],
          "default" => [],
        ],
        "per_page" => [
          "type" => "integer",
          "default" => 20,
          "minimum" => 1,
          "maximum" => 100,
        ],
        "page" => [
          "type" => "integer",
          "default" => 1,
          "minimum" => 1,
        ],
      ],
    ]);

    // Search taxonomy terms
    register_rest_route($this->namespace, "/" . $this->base . "/terms", [
      "methods" => "GET",
      "callback" => [$this, "search_terms"],
      "permission_callback" => [$this, "permissions_check"],
      "args" => [
        "search" => [
          "type" => "string",
          "sanitize_callback" => "sanitize_text_field",
          "default" => "",
        ],
        "taxonomy" => [
          "type" => "array",
          "items" => ["type" => "string"],
          "default" => ["category", "post_tag"],
        ],
        "exclude" => [
          "type" => "array",
          "items" => ["type" => "integer"],
          "default" => [],
        ],
        "include" => [
          "type" => "array",
          "items" => ["type" => "integer"],
          "default" => [],
        ],
        "per_page" => [
          "type" => "integer",
          "default" => 20,
          "minimum" => 1,
          "maximum" => 100,
        ],
        "page" => [
          "type" => "integer",
          "default" => 1,
          "minimum" => 1,
        ],
      ],
    ]);

    // Get posts by IDs (for loading existing selections)
    register_rest_route($this->namespace, "/" . $this->base . "/posts/by-ids", [
      "methods" => "GET",
      "callback" => [$this, "get_posts_by_ids"],
      "permission_callback" => [$this, "permissions_check"],
      "args" => [
        "ids" => [
          "type" => "array",
          "items" => ["type" => "integer"],
          "required" => true,
        ],
      ],
    ]);

    // Get terms by IDs (for loading existing selections)
    register_rest_route($this->namespace, "/" . $this->base . "/terms/by-ids", [
      "methods" => "GET",
      "callback" => [$this, "get_terms_by_ids"],
      "permission_callback" => [$this, "permissions_check"],
      "args" => [
        "ids" => [
          "type" => "array",
          "items" => ["type" => "integer"],
          "required" => true,
        ],
      ],
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
    return RestPermissionChecker::check_permissions($request, 'edit_posts');
  }

  /**
   * Search posts
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function search_posts($request)
  {
    $search = $request->get_param('search');
    $post_types = $request->get_param('post_type');
    $statuses = $request->get_param('status');
    $exclude = $request->get_param('exclude');
    $include = $request->get_param('include');
    $per_page = $request->get_param('per_page');
    $page = $request->get_param('page');

    // Sanitize post types
    $post_types = array_map('sanitize_key', (array) $post_types);
    
    // Filter to only allowed post types
    $allowed_post_types = get_post_types(['public' => true], 'names');
    $post_types = array_intersect($post_types, $allowed_post_types);
    
    if (empty($post_types)) {
      $post_types = ['post', 'page'];
    }

    // Build query args
    $args = [
      'post_type' => $post_types,
      'post_status' => array_map('sanitize_key', (array) $statuses),
      'posts_per_page' => $per_page,
      'paged' => $page,
      'orderby' => 'title',
      'order' => 'ASC',
      'suppress_filters' => false,
    ];

    // Add search
    if (!empty($search)) {
      $args['s'] = $search;
    }

    // Exclude posts
    if (!empty($exclude)) {
      $args['post__not_in'] = array_map('absint', (array) $exclude);
    }

    // Include specific posts only
    if (!empty($include)) {
      $args['post__in'] = array_map('absint', (array) $include);
    }

    $query = new \WP_Query($args);
    $results = [];

    foreach ($query->posts as $post) {
      $results[] = $this->format_post($post);
    }

    return new \WP_REST_Response([
      'results' => $results,
      'total' => $query->found_posts,
      'pages' => $query->max_num_pages,
    ], 200);
  }

  /**
   * Search taxonomy terms
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function search_terms($request)
  {
    $search = $request->get_param('search');
    $taxonomies = $request->get_param('taxonomy');
    $exclude = $request->get_param('exclude');
    $include = $request->get_param('include');
    $per_page = $request->get_param('per_page');
    $page = $request->get_param('page');

    // Sanitize taxonomies
    $taxonomies = array_map('sanitize_key', (array) $taxonomies);
    
    // Filter to only allowed taxonomies
    $allowed_taxonomies = get_taxonomies(['public' => true], 'names');
    $taxonomies = array_intersect($taxonomies, $allowed_taxonomies);
    
    if (empty($taxonomies)) {
      $taxonomies = ['category', 'post_tag'];
    }

    // Build query args
    $args = [
      'taxonomy' => $taxonomies,
      'hide_empty' => false,
      'number' => $per_page,
      'offset' => ($page - 1) * $per_page,
      'orderby' => 'name',
      'order' => 'ASC',
    ];

    // Add search
    if (!empty($search)) {
      $args['search'] = $search;
    }

    // Exclude terms
    if (!empty($exclude)) {
      $args['exclude'] = array_map('absint', (array) $exclude);
    }

    // Include specific terms only
    if (!empty($include)) {
      $args['include'] = array_map('absint', (array) $include);
    }

    $terms = get_terms($args);
    
    if (is_wp_error($terms)) {
      return new \WP_REST_Response([
        'results' => [],
        'total' => 0,
        'pages' => 0,
      ], 200);
    }

    // Get total count
    $count_args = $args;
    unset($count_args['number'], $count_args['offset']);
    $count_args['count'] = true;
    $total = get_terms($count_args);
    $total = is_wp_error($total) ? 0 : (int) $total;

    $results = [];
    foreach ($terms as $term) {
      $results[] = $this->format_term($term);
    }

    return new \WP_REST_Response([
      'results' => $results,
      'total' => $total,
      'pages' => $per_page > 0 ? ceil($total / $per_page) : 1,
    ], 200);
  }

  /**
   * Get posts by IDs
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function get_posts_by_ids($request)
  {
    $ids = $request->get_param('ids');
    
    if (empty($ids)) {
      return new \WP_REST_Response(['results' => []], 200);
    }

    $ids = array_map('absint', (array) $ids);

    $args = [
      'post_type' => 'any',
      'post_status' => 'any',
      'post__in' => $ids,
      'posts_per_page' => count($ids),
      'orderby' => 'post__in',
      'suppress_filters' => false,
    ];

    $query = new \WP_Query($args);
    $results = [];

    foreach ($query->posts as $post) {
      $results[] = $this->format_post($post);
    }

    return new \WP_REST_Response(['results' => $results], 200);
  }

  /**
   * Get terms by IDs
   *
   * @param \WP_REST_Request $request The request object.
   * @return \WP_REST_Response The response object.
   */
  public function get_terms_by_ids($request)
  {
    $ids = $request->get_param('ids');
    
    if (empty($ids)) {
      return new \WP_REST_Response(['results' => []], 200);
    }

    $ids = array_map('absint', (array) $ids);

    $terms = get_terms([
      'include' => $ids,
      'hide_empty' => false,
      'orderby' => 'include',
    ]);

    if (is_wp_error($terms)) {
      return new \WP_REST_Response(['results' => []], 200);
    }

    $results = [];
    foreach ($terms as $term) {
      $results[] = $this->format_term($term);
    }

    return new \WP_REST_Response(['results' => $results], 200);
  }

  /**
   * Format a post object for the response
   *
   * @param \WP_Post $post The post object.
   * @return array Formatted post data.
   */
  private function format_post($post)
  {
    $post_type_obj = get_post_type_object($post->post_type);
    $thumbnail = null;
    
    if (has_post_thumbnail($post->ID)) {
      $thumbnail = get_the_post_thumbnail_url($post->ID, 'thumbnail');
    }

    return [
      'id' => $post->ID,
      'title' => $post->post_title ?: __('(no title)', 'uixpress'),
      'type' => $post->post_type,
      'type_label' => $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type,
      'status' => $post->post_status,
      'status_label' => get_post_status_object($post->post_status)->label ?? $post->post_status,
      'thumbnail' => $thumbnail,
      'edit_link' => get_edit_post_link($post->ID, 'raw'),
      'permalink' => get_permalink($post->ID),
    ];
  }

  /**
   * Format a term object for the response
   *
   * @param \WP_Term $term The term object.
   * @return array Formatted term data.
   */
  private function format_term($term)
  {
    $taxonomy_obj = get_taxonomy($term->taxonomy);

    return [
      'id' => $term->term_id,
      'title' => $term->name,
      'type' => $term->taxonomy,
      'type_label' => $taxonomy_obj ? $taxonomy_obj->labels->singular_name : $term->taxonomy,
      'slug' => $term->slug,
      'count' => $term->count,
      'parent' => $term->parent,
      'edit_link' => get_edit_term_link($term->term_id, $term->taxonomy),
    ];
  }
}
