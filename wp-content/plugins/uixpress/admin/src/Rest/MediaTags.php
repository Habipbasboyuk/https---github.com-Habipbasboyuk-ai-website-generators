<?php

namespace UiXpress\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access to this file
defined('ABSPATH') || exit();

/**
 * Class MediaTags
 *
 * Registers a taxonomy for media attachments and provides REST API endpoints
 * for managing media tags.
 */
class MediaTags
{
	/**
	 * Taxonomy slug for media tags
	 *
	 * @var string
	 */
	private const TAXONOMY_SLUG = 'media_tag';

	/**
	 * Namespace for REST API endpoints
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = 'uixpress/v1';

	/**
	 * Bootstrap hooks
	 *
	 * @return void
	 */
	public function __construct()
	{
		// Register taxonomy on init
		add_action('init', [__CLASS__, 'register_taxonomy'], 0);

		// Register REST API endpoints
		add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);

		// Ensure taxonomy is available in REST API
		add_filter('rest_prepare_attachment', [__CLASS__, 'add_tags_to_rest_response'], 10, 3);

		// Register tag_ids parameter for media collection
		add_filter('rest_attachment_collection_params', [__CLASS__, 'register_tag_ids_param'], 10, 1);

		// Add tag filtering support to media queries
		add_filter('rest_attachment_query', [__CLASS__, 'filter_media_query_by_tags'], 10, 2);
	}

	/**
	 * Register tag_ids parameter for media collection
	 *
	 * @param array $query_params Existing collection parameters
	 * @return array Modified parameters
	 */
	public static function register_tag_ids_param($query_params)
	{
		$query_params['tag_ids'] = [
			'description' => __('Filter media items by tag IDs.', 'uixpress'),
			'type' => 'array',
			'items' => [
				'type' => 'integer',
			],
			'default' => [],
			'sanitize_callback' => function ($param) {
				if (!is_array($param)) {
					return [];
				}
				return array_map('intval', array_filter($param));
			},
		];

		return $query_params;
	}

	/**
	 * Filter media query by tags when tag_ids parameter is present
	 *
	 * @param array $args Query arguments
	 * @param WP_REST_Request $request The request object
	 * @return array Modified query arguments
	 */
	public static function filter_media_query_by_tags($args, $request)
	{
		// WordPress REST API parses tag_ids[]=1&tag_ids[]=2 into an array automatically
		$tag_ids = $request->get_param('tag_ids');

		// Handle both array and single value formats
		if (!empty($tag_ids)) {
			// Convert to array if it's a single value
			if (!is_array($tag_ids)) {
				$tag_ids = [$tag_ids];
			}

			$tag_ids = array_map('intval', array_filter($tag_ids));

			if (!empty($tag_ids)) {
				// Handle existing tax_query if present
				if (isset($args['tax_query']) && is_array($args['tax_query'])) {
					$args['tax_query']['relation'] = 'AND';
					$args['tax_query'][] = [
						'taxonomy' => self::TAXONOMY_SLUG,
						'field' => 'term_id',
						'terms' => $tag_ids,
						'operator' => 'IN',
					];
				} else {
					$args['tax_query'] = [
						[
							'taxonomy' => self::TAXONOMY_SLUG,
							'field' => 'term_id',
							'terms' => $tag_ids,
							'operator' => 'IN',
						],
					];
				}
			}
		}

		return $args;
	}

	/**
	 * Register the media_tag taxonomy for attachments
	 *
	 * @return void
	 */
	public static function register_taxonomy(): void
	{
		$labels = [
			'name' => __('Media Tags', 'uixpress'),
			'singular_name' => __('Media Tag', 'uixpress'),
			'menu_name' => __('Tags', 'uixpress'),
			'all_items' => __('All Tags', 'uixpress'),
			'edit_item' => __('Edit Tag', 'uixpress'),
			'view_item' => __('View Tag', 'uixpress'),
			'update_item' => __('Update Tag', 'uixpress'),
			'add_new_item' => __('Add New Tag', 'uixpress'),
			'new_item_name' => __('New Tag Name', 'uixpress'),
			'search_items' => __('Search Tags', 'uixpress'),
			'popular_items' => __('Popular Tags', 'uixpress'),
			'separate_items_with_commas' => __('Separate tags with commas', 'uixpress'),
			'add_or_remove_items' => __('Add or remove tags', 'uixpress'),
			'choose_from_most_used' => __('Choose from the most used tags', 'uixpress'),
			'not_found' => __('No tags found', 'uixpress'),
		];

		$args = [
			'labels' => $labels,
			'public' => false,
			'publicly_queryable' => false,
			'show_ui' => false, // Hide from admin UI since we're using custom UI
			'show_in_menu' => false,
			'show_in_nav_menus' => false,
			'show_in_rest' => true, // Enable REST API support
			'rest_base' => self::TAXONOMY_SLUG,
			'hierarchical' => false,
			'show_admin_column' => false,
			'update_count_callback' => '_update_generic_term_count',
			'query_var' => false,
			'rewrite' => false,
			'capabilities' => [
				'manage_terms' => 'upload_files',
				'edit_terms' => 'upload_files',
				'delete_terms' => 'upload_files',
				'assign_terms' => 'upload_files',
			],
		];

		register_taxonomy(self::TAXONOMY_SLUG, 'attachment', $args);
	}

	/**
	 * Register REST API routes for media tags
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void
	{
		// Get all tags
		register_rest_route(self::REST_NAMESPACE, '/media/tags', [
			'methods' => 'GET',
			'callback' => [__CLASS__, 'get_all_tags'],
			'permission_callback' => [__CLASS__, 'check_permissions'],
		]);

		// Create a new tag
		register_rest_route(self::REST_NAMESPACE, '/media/tags', [
			'methods' => 'POST',
			'callback' => [__CLASS__, 'create_tag'],
			'permission_callback' => [__CLASS__, 'check_permissions'],
			'args' => [
				'name' => [
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ($param) {
						return !empty(trim($param));
					},
				],
			],
		]);

		// Update media item tags
		register_rest_route(self::REST_NAMESPACE, '/media/(?P<id>\d+)/tags', [
			'methods' => 'POST',
			'callback' => [__CLASS__, 'update_media_tags'],
			'permission_callback' => [__CLASS__, 'check_permissions'],
			'args' => [
				'id' => [
					'required' => true,
					'type' => 'integer',
					'validate_callback' => function ($param) {
						return is_numeric($param) && $param > 0;
					},
				],
				'tag_ids' => [
					'required' => false,
					'type' => 'array',
					'items' => [
						'type' => 'integer',
					],
					'default' => [],
				],
				'tag_names' => [
					'required' => false,
					'type' => 'array',
					'items' => [
						'type' => 'string',
					],
					'default' => [],
				],
			],
		]);

	}

	/**
	 * Add tags to REST API response for attachments
	 *
	 * @param WP_REST_Response $response The response object
	 * @param WP_Post $post The attachment post object
	 * @param WP_REST_Request $request The request object
	 * @return WP_REST_Response Modified response with tags
	 */
	public static function add_tags_to_rest_response($response, $post, $request)
	{
		if ($post->post_type !== 'attachment') {
			return $response;
		}

		$tags = wp_get_object_terms($post->ID, self::TAXONOMY_SLUG, [
			'fields' => 'all',
		]);

		$tags_data = [];
		foreach ($tags as $tag) {
			$tags_data[] = [
				'id' => $tag->term_id,
				'name' => $tag->name,
				'slug' => $tag->slug,
			];
		}

		$response->data['media_tags'] = $tags_data;

		return $response;
	}

	/**
	 * Get all media tags
	 *
	 * @param WP_REST_Request $request The request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_all_tags($request)
	{
		$args = [
			'taxonomy' => self::TAXONOMY_SLUG,
			'hide_empty' => false,
			'orderby' => 'name',
			'order' => 'ASC',
		];

		$tags = get_terms($args);

		if (is_wp_error($tags)) {
			return $tags;
		}

		$tags_data = [];
		foreach ($tags as $tag) {
			$tags_data[] = [
				'id' => $tag->term_id,
				'name' => $tag->name,
				'slug' => $tag->slug,
				'count' => $tag->count,
			];
		}

		return new WP_REST_Response($tags_data, 200);
	}

	/**
	 * Create a new tag
	 *
	 * @param WP_REST_Request $request The request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_tag($request)
	{
		$name = $request->get_param('name');

		if (empty($name)) {
			return new WP_Error(
				'invalid_param',
				__('Tag name is required', 'uixpress'),
				['status' => 400]
			);
		}

		// Check if tag already exists
		$existing = get_term_by('name', $name, self::TAXONOMY_SLUG);
		if ($existing) {
			return new WP_REST_Response([
				'id' => $existing->term_id,
				'name' => $existing->name,
				'slug' => $existing->slug,
			], 200);
		}

		// Create new tag
		$result = wp_insert_term($name, self::TAXONOMY_SLUG);

		if (is_wp_error($result)) {
			return $result;
		}

		$tag = get_term($result['term_id'], self::TAXONOMY_SLUG);

		return new WP_REST_Response([
			'id' => $tag->term_id,
			'name' => $tag->name,
			'slug' => $tag->slug,
		], 201);
	}

	/**
	 * Update tags for a media item
	 *
	 * @param WP_REST_Request $request The request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_media_tags($request)
	{
		$media_id = $request->get_param('id');
		$tag_ids = $request->get_param('tag_ids') ?: [];
		$tag_names = $request->get_param('tag_names') ?: [];

		// Verify attachment exists
		$attachment = get_post($media_id);
		if (!$attachment || $attachment->post_type !== 'attachment') {
			return new WP_Error(
				'invalid_attachment',
				__('Invalid media item', 'uixpress'),
				['status' => 404]
			);
		}

		// Convert tag names to IDs if provided
		$final_tag_ids = $tag_ids;
		if (!empty($tag_names)) {
			foreach ($tag_names as $tag_name) {
				$tag_name = trim($tag_name);
				if (empty($tag_name)) {
					continue;
				}

				// Check if tag exists
				$existing = get_term_by('name', $tag_name, self::TAXONOMY_SLUG);
				if ($existing) {
					$final_tag_ids[] = $existing->term_id;
				} else {
					// Create new tag
					$result = wp_insert_term($tag_name, self::TAXONOMY_SLUG);
					if (!is_wp_error($result)) {
						$final_tag_ids[] = $result['term_id'];
					}
				}
			}
		}

		// Remove duplicates
		$final_tag_ids = array_unique(array_map('intval', $final_tag_ids));

		// Update tags
		$result = wp_set_object_terms($media_id, $final_tag_ids, self::TAXONOMY_SLUG);

		if (is_wp_error($result)) {
			return $result;
		}

		// Get updated tags
		$tags = wp_get_object_terms($media_id, self::TAXONOMY_SLUG, [
			'fields' => 'all',
		]);

		$tags_data = [];
		foreach ($tags as $tag) {
			$tags_data[] = [
				'id' => $tag->term_id,
				'name' => $tag->name,
				'slug' => $tag->slug,
			];
		}

		return new WP_REST_Response([
			'success' => true,
			'tags' => $tags_data,
		], 200);
	}

	/**
	 * Filter media by tags (modify WP_Query)
	 *
	 * This modifies the existing media query to filter by tags
	 *
	 * @param WP_REST_Request $request The request object
	 * @return void
	 */
	public static function filter_media_by_tags($request)
	{
		$tag_ids = $request->get_param('tag_ids') ?: [];

		if (!empty($tag_ids)) {
			add_filter('rest_attachment_query', function ($args, $request) use ($tag_ids) {
				$args['tax_query'] = [
					[
						'taxonomy' => self::TAXONOMY_SLUG,
						'field' => 'term_id',
						'terms' => array_map('intval', $tag_ids),
						'operator' => 'IN',
					],
				];
				return $args;
			}, 10, 2);
		}
	}

	/**
	 * Check if user has permission to manage media tags
	 *
	 * @param WP_REST_Request $request The request object
	 * @return bool|WP_Error
	 */
	public static function check_permissions($request)
	{
		return RestPermissionChecker::check_permissions($request, 'upload_files');
	}
}

