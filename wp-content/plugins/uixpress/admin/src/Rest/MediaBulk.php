<?php

namespace UiXpress\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Prevent direct access to this file
defined('ABSPATH') || exit();

/**
 * Class MediaBulk
 *
 * Provides REST API endpoints for bulk media operations:
 * - Bulk download (ZIP creation)
 * - Media usage tracking
 */
class MediaBulk
{
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
		add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
		add_action('uixpress_cleanup_zip', [__CLASS__, 'cleanup_zip_file'], 10, 1);
	}

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void
	{
		// Bulk download endpoint
		register_rest_route(
			self::REST_NAMESPACE,
			'/media/bulk-download',
			[
				'methods' => 'POST',
				'callback' => [__CLASS__, 'bulk_download'],
				'permission_callback' => [__CLASS__, 'check_permissions'],
				'args' => [
					'media_ids' => [
						'required' => true,
						'type' => 'array',
						'items' => [
							'type' => 'integer',
						],
						'validate_callback' => function ($param) {
							return is_array($param) && !empty($param);
						},
						'sanitize_callback' => function ($param) {
							return array_map('intval', array_filter($param));
						},
					],
				],
			]
		);

		// Media usage tracking endpoint
		register_rest_route(
			self::REST_NAMESPACE,
			'/media/(?P<id>\d+)/usage',
			[
				'methods' => 'GET',
				'callback' => [__CLASS__, 'get_media_usage'],
				'permission_callback' => [__CLASS__, 'check_permissions'],
				'args' => [
					'id' => [
						'required' => true,
						'type' => 'integer',
						'validate_callback' => function ($param) {
							return is_numeric($param) && $param > 0;
						},
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Check if user has required permissions
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permissions($request = null)
	{
		if (!$request) {
			return new \WP_Error('rest_forbidden', __('Invalid request.', 'uixpress'), ['status' => 400]);
		}
		
		return RestPermissionChecker::check_permissions($request, 'upload_files');
	}

	/**
	 * Handle bulk download request - creates ZIP file
	 *
	 * @param WP_REST_Request $request The request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function bulk_download($request)
	{
		$media_ids = $request->get_param('media_ids');

		if (empty($media_ids) || !is_array($media_ids)) {
			return new WP_Error(
				'rest_invalid_param',
				__('Invalid media IDs provided.', 'uixpress'),
				['status' => 400]
			);
		}

		// Limit to prevent abuse
		if (count($media_ids) > 100) {
			return new WP_Error(
				'rest_too_many_items',
				__('Maximum 100 items can be downloaded at once.', 'uixpress'),
				['status' => 400]
			);
		}

		// Check if ZipArchive is available
		if (!class_exists('ZipArchive')) {
			return new WP_Error(
				'rest_zip_not_available',
				__('ZIP functionality is not available on this server.', 'uixpress'),
				['status' => 500]
			);
		}

		$zip = new \ZipArchive();
		$upload_dir = wp_upload_dir();
		$zip_filename = 'media-bulk-' . time() . '-' . wp_generate_password(8, false) . '.zip';
		$zip_path = $upload_dir['basedir'] . '/' . $zip_filename;

		if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			return new WP_Error(
				'rest_zip_create_failed',
				__('Failed to create ZIP file.', 'uixpress'),
				['status' => 500]
			);
		}

		$added_count = 0;
		foreach ($media_ids as $media_id) {
			$media_id = absint($media_id);
			$file_path = get_attached_file($media_id);

			if ($file_path && file_exists($file_path)) {
				$file_info = get_post($media_id);
				$file_name = $file_info ? basename($file_path) : 'media-' . $media_id;
				$zip->addFile($file_path, $file_name);
				$added_count++;
			}
		}

		$zip->close();

		if ($added_count === 0) {
			// Clean up empty ZIP
			if (file_exists($zip_path)) {
				unlink($zip_path);
			}
			return new WP_Error(
				'rest_no_files',
				__('No valid files found to download.', 'uixpress'),
				['status' => 404]
			);
		}

		// Return download URL - ensure HTTPS if site is HTTPS
		// Use set_url_scheme to match the site URL scheme (respects WordPress site URL settings)
		$base_url = $upload_dir['baseurl'] . '/' . $zip_filename;
		// Get the scheme from site URL to ensure consistency
		$site_url_scheme = parse_url(get_site_url(), PHP_URL_SCHEME);
		$download_url = set_url_scheme($base_url, $site_url_scheme ?: 'https');

		// Schedule cleanup of ZIP file after 1 hour
		wp_schedule_single_event(time() + HOUR_IN_SECONDS, 'uixpress_cleanup_zip', [$zip_path]);

		return new WP_REST_Response(
			[
				'success' => true,
				'download_url' => $download_url,
				'filename' => $zip_filename,
				'file_count' => $added_count,
			],
			200
		);
	}

	/**
	 * Cleanup ZIP file after download
	 *
	 * @param string $zip_path Path to ZIP file
	 * @return void
	 */
	public static function cleanup_zip_file($zip_path)
	{
		if (file_exists($zip_path)) {
			unlink($zip_path);
		}
	}

	/**
	 * Get media usage information - where media is being used
	 *
	 * @param WP_REST_Request $request The request object
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_media_usage($request)
	{
		$media_id = absint($request->get_param('id'));

		if (!$media_id) {
			return new WP_REST_Response(
				[
					'success' => true,
					'data' => [],
					'count' => 0,
				],
				200
			);
		}

		// Verify media exists
		$attachment = get_post($media_id);
		if (!$attachment || $attachment->post_type !== 'attachment') {
			return new WP_REST_Response(
				[
					'success' => true,
					'data' => [],
					'count' => 0,
				],
				200
			);
		}

		$usage = [];
		$seen_post_ids = [];

		global $wpdb;

		// Get media URL and all size variations
		$media_url = wp_get_attachment_url($media_id);
		if (!$media_url) {
			return new WP_REST_Response(
				[
					'success' => true,
					'data' => [],
					'count' => 0,
				],
				200
			);
		}

		$media_filename = basename($media_url);
		
		// Get all image sizes URLs to check
		$image_urls = [];
		if (wp_attachment_is_image($media_id)) {
			$image_urls[] = $media_url;
			$sizes = get_intermediate_image_sizes();
			foreach ($sizes as $size) {
				$image_data = wp_get_attachment_image_src($media_id, $size);
				if ($image_data && !empty($image_data[0])) {
					$image_urls[] = $image_data[0];
				}
			}
		} else {
			$image_urls[] = $media_url;
		}

		// Get post types to search - use WordPress query methods instead of raw SQL
		$post_types = ['post', 'page'];
		$custom_post_types = get_post_types(['public' => true, '_builtin' => false], 'names');
		foreach ($custom_post_types as $cpt) {
			if (post_type_supports($cpt, 'editor')) {
				$post_types[] = $cpt;
			}
		}

		// 1. Check if used as featured image using WP_Query
		$featured_query = new \WP_Query([
			'post_type' => $post_types,
			'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
			'posts_per_page' => -1,
			'meta_query' => [
				[
					'key' => '_thumbnail_id',
					'value' => $media_id,
					'compare' => '=',
				],
			],
			'fields' => 'ids',
		]);

		foreach ($featured_query->posts as $post_id) {
			$post_id = (int) $post_id;
			if (!in_array($post_id, $seen_post_ids)) {
				$post = get_post($post_id);
				if ($post) {
					$seen_post_ids[] = $post_id;
					$usage[] = [
						'id' => $post_id,
						'title' => !empty($post->post_title) ? $post->post_title : __('(Untitled)', 'uixpress'),
						'type' => $post->post_type,
						'status' => $post->post_status,
						'edit_url' => get_edit_post_link($post_id, 'raw'),
					];
				}
			}
		}

		// 2. Check if used in post content
		// Use targeted search patterns with LIKE queries for better performance
		// Build search patterns
		$search_patterns = [];
		
		// Gutenberg block patterns
		$search_patterns[] = $wpdb->esc_like('"id":' . $media_id);
		$search_patterns[] = $wpdb->esc_like('"mediaId":' . $media_id);
		$search_patterns[] = $wpdb->esc_like('wp-image-' . $media_id);
		
		// Gallery shortcode patterns
		$search_patterns[] = $wpdb->esc_like('[gallery');
		$search_patterns[] = $wpdb->esc_like('ids="');
		$search_patterns[] = $wpdb->esc_like("ids='");
		
		// URL patterns for each image size
		foreach ($image_urls as $url) {
			$search_patterns[] = $wpdb->esc_like($url);
		}
		
		// Use a single query with multiple LIKE conditions
		$like_conditions = [];
		$prepare_values = [];
		
		// Gutenberg block ID patterns
		$like_conditions[] = "(post_content LIKE %s OR post_content LIKE %s)";
		$prepare_values[] = '%"id":' . $media_id . '%';
		$prepare_values[] = '%"mediaId":' . $media_id . '%';
		
		// Classic editor pattern
		$like_conditions[] = "post_content LIKE %s";
		$prepare_values[] = '%wp-image-' . $media_id . '%';
		
		// Gallery shortcode patterns - search for gallery with media ID in ids attribute
		$like_conditions[] = "(post_content LIKE %s OR post_content LIKE %s)";
		$prepare_values[] = '%[gallery%' . $media_id . '%]%';
		$prepare_values[] = '%ids="%' . $media_id . '%"%';
		
		// URL patterns - check all image URLs
		$url_like_conditions = [];
		foreach ($image_urls as $url) {
			$escaped_url = $wpdb->esc_like($url);
			$url_like_conditions[] = "post_content LIKE %s";
			$prepare_values[] = '%src="' . $escaped_url . '"%';
			$url_like_conditions[] = "post_content LIKE %s";
			$prepare_values[] = '%src=\'' . $escaped_url . '\'%';
			$url_like_conditions[] = "post_content LIKE %s";
			$prepare_values[] = '%href="' . $escaped_url . '"%';
			$url_like_conditions[] = "post_content LIKE %s";
			$prepare_values[] = '%href=\'' . $escaped_url . '\'%';
		}
		if (!empty($url_like_conditions)) {
			$like_conditions[] = '(' . implode(' OR ', $url_like_conditions) . ')';
		}
		
		// Build the query
		$where_clause = '(' . implode(' OR ', $like_conditions) . ')';
		$post_types_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
		
		$query = "SELECT ID, post_title, post_type, post_status, post_content
			FROM {$wpdb->posts}
			WHERE post_type IN ($post_types_placeholders)
			AND post_status IN ('publish', 'draft', 'pending', 'future', 'private')
			AND post_content != ''
			AND post_content IS NOT NULL
			AND $where_clause";
		
		$prepare_args = array_merge($post_types, $prepare_values);
		$content_posts = $wpdb->get_results(
			$wpdb->prepare($query, $prepare_args),
			ARRAY_A
		);

		// Verify matches with regex
		foreach ($content_posts as $post) {
			$post_id = (int) $post['ID'];
			if (in_array($post_id, $seen_post_ids)) {
				continue;
			}

			$content = $post['post_content'];
			$is_used = false;

			// Check for Gutenberg blocks with media ID
			if (preg_match('/"(?:id|mediaId)"\s*:\s*' . $media_id . '\b/', $content)) {
				$is_used = true;
			}
			// Check for classic editor image class
			elseif (preg_match('/wp-image-' . $media_id . '\b/', $content)) {
				$is_used = true;
			}
			// Check for gallery shortcode
			elseif (preg_match('/\[gallery[^\]]*ids=["\']?[^"\']*' . $media_id . '[^"\']*["\']?/', $content)) {
				$is_used = true;
			}
			// Check for media URL in src/href attributes
			else {
				foreach ($image_urls as $url) {
					$escaped_url = preg_quote($url, '/');
					if (preg_match('/src\s*=\s*["\']?' . $escaped_url . '["\']?/', $content) ||
						preg_match('/href\s*=\s*["\']?' . $escaped_url . '["\']?/', $content)) {
						$is_used = true;
						break;
					}
				}
			}

			if ($is_used && in_array($post['post_type'], $post_types)) {
				$seen_post_ids[] = $post_id;
				$usage[] = [
					'id' => $post_id,
					'title' => !empty($post['post_title']) ? $post['post_title'] : __('(Untitled)', 'uixpress'),
					'type' => $post['post_type'], // Ensure type is 'post' or 'page', not 'widget'
					'status' => $post['post_status'],
					'edit_url' => get_edit_post_link($post_id, 'raw'),
				];
			}
		}

		// 3. Check if attached to a post (post_parent)
		if ($attachment->post_parent > 0) {
			$parent_id = (int) $attachment->post_parent;
			if (!in_array($parent_id, $seen_post_ids)) {
				$parent = get_post($parent_id);
				if ($parent && in_array($parent->post_type, $post_types)) {
					$seen_post_ids[] = $parent_id;
					$usage[] = [
						'id' => $parent_id,
						'title' => !empty($parent->post_title) ? $parent->post_title : __('(Untitled)', 'uixpress'),
						'type' => $parent->post_type,
						'status' => $parent->post_status,
						'edit_url' => get_edit_post_link($parent_id, 'raw'),
					];
				}
			}
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'data' => $usage,
				'count' => count($usage),
			],
			200
		);
	}
}

