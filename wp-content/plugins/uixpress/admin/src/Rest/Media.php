<?php

namespace UiXpress\Rest;

use WP_REST_Request;

/**
 * Registers an `unused` filter for the core attachments REST API and
 * adjusts the underlying WP_Query to return only attachments that are
 * considered unused.
 *
 * Definition of "unused" for now:
 * - Unattached (post_parent = 0)
 * - Not referenced as a featured image (not present as _thumbnail_id on any post)
 *
 * This avoids expensive full-content scans. If needed, we can extend this
 * later to scan `post_content` for inlined references.
 */
class Media {

	/**
	 * Bootstrap hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Register the custom collection param on attachments endpoint.
		add_filter( 'rest_attachment_collection_params', [ __CLASS__, 'register_unused_param' ] );

		// Modify the WP_Query for attachments when the param is present.
		add_filter( 'rest_attachment_query', [ __CLASS__, 'filter_unused_attachments' ], 10, 2 );
	}

	/**
	 * Add `unused` boolean to attachments collection params.
	 *
	 * @param array $query_params Existing params.
	 * @return array Modified params.
	 */
	public static function register_unused_param( array $query_params ): array {
		$query_params['unused'] = [
			'description' => __( 'Return only unused media items (unattached and not used as featured image).', 'uixpress' ),
			'type'        => 'boolean',
			'default'     => false,
		];

		$query_params['unused_mode'] = [
			'description' => __( 'Unused detection mode: shallow (default) or deep (scans post_content).', 'uixpress' ),
			'type'        => 'string',
			'enum'        => [ 'shallow', 'deep' ],
			'default'     => 'shallow',
		];

		// Extend orderby enum to accept custom values 'size' & 'type'.
		if ( isset( $query_params['orderby']['enum'] ) && is_array( $query_params['orderby']['enum'] ) ) {
			$query_params['orderby']['enum'][] = 'size';
			$query_params['orderby']['enum'][] = 'type';
		}

		return $query_params;
	}

	/**
	 * If `unused` is truthy, alter attachment query to exclude featured images
	 * and restrict to unattached attachments.
	 *
	 * @param array           $args
	 * @param WP_REST_Request $request
	 * @return array
	 */
	public static function filter_unused_attachments( array $args, WP_REST_Request $request ): array {
		$unused = filter_var( $request->get_param( 'unused' ), FILTER_VALIDATE_BOOLEAN );
		if ( ! $unused ) {
			// Still handle custom orderby when not filtering by unused
			return self::maybe_apply_custom_orderby( $args, $request );
		}

		error_log( 'filter_unused_attachments: Starting filter' );

		global $wpdb;

		// Collect IDs of attachments that ARE used
		$used_attachment_ids = [];

		// 1. Get featured image attachment IDs
		$featured_ids = get_transient( 'uixpress_featured_attachment_ids_cache' );
		if ( false === $featured_ids ) {
			$featured_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' AND meta_value > 0",
				'_thumbnail_id'
			) );
			$featured_ids = array_values( array_filter( array_map( 'intval', $featured_ids ) ) );
			set_transient( 'uixpress_featured_attachment_ids_cache', $featured_ids, MINUTE_IN_SECONDS * 5 );
			error_log( 'filter_unused_attachments: Found ' . count( $featured_ids ) . ' featured images' );
		}
		$used_attachment_ids = array_merge( $used_attachment_ids, $featured_ids );

		// 2. Get attachments with a post_parent (attached to posts)
		$attached_ids = get_transient( 'uixpress_attached_attachment_ids' );
		if ( false === $attached_ids ) {
			$attached_ids = $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent > 0"
			);
			$attached_ids = array_values( array_filter( array_map( 'intval', $attached_ids ) ) );
			set_transient( 'uixpress_attached_attachment_ids', $attached_ids, MINUTE_IN_SECONDS * 5 );
			error_log( 'filter_unused_attachments: Found ' . count( $attached_ids ) . ' attached files' );
		}
		$used_attachment_ids = array_merge( $used_attachment_ids, $attached_ids );

		// 3. Deep mode: also exclude attachments referenced in post_content
		$mode = $request->get_param( 'unused_mode' );
		if ( 'deep' === $mode ) {
			$used_in_content = self::collect_used_attachment_ids_from_content();
			error_log( 'filter_unused_attachments: Found ' . count( $used_in_content ) . ' used in content (deep mode)' );
			$used_attachment_ids = array_merge( $used_attachment_ids, $used_in_content );
		}

		// Remove duplicates and filter out zeros
		$used_attachment_ids = array_values( array_unique( array_filter( array_map( 'intval', $used_attachment_ids ) ) ) );

		error_log( 'filter_unused_attachments: Total used IDs to exclude: ' . count( $used_attachment_ids ) );

		// Exclude all used attachments
		if ( ! empty( $used_attachment_ids ) ) {
			$existing_exclusions = isset( $args['post__not_in'] ) && is_array( $args['post__not_in'] ) ? $args['post__not_in'] : [];
			$args['post__not_in'] = array_values( array_unique( array_merge( $existing_exclusions, $used_attachment_ids ) ) );
			
			error_log( 'filter_unused_attachments: Final exclusion count: ' . count( $args['post__not_in'] ) );
		} else {
			error_log( 'filter_unused_attachments: No used attachments found - all will show as unused' );
		}

		// Apply custom ordering too (size/type)
		return self::maybe_apply_custom_orderby( $args, $request );
	}

	/**
	 * Apply custom orderby handling for 'size' and 'type'.
	 *
	 * @param array           $args
	 * @param WP_REST_Request $request
	 * @return array
	 */
	private static function maybe_apply_custom_orderby( array $args, WP_REST_Request $request ): array {
		$orderby = $request->get_param( 'orderby' );
		$order   = strtoupper( $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';

		if ( 'size' === $orderby ) {
			// Use custom JOIN/ORDER BY to avoid any unexpected filtering when meta doesn't exist.
			add_filter( 'posts_clauses', function ( $clauses ) use ( $order ) {
				global $wpdb;
				$join  = " LEFT JOIN {$wpdb->postmeta} AS ufsm ON ( {$wpdb->posts}.ID = ufsm.post_id AND ufsm.meta_key = 'uixpress_filesize' ) ";
				$clauses['join'] .= $join;
				$clauses['orderby'] = "CAST(ufsm.meta_value AS UNSIGNED) {$order}, {$wpdb->posts}.post_date DESC";
				return $clauses;
			} );
			$args['orderby'] = 'none';

			// Ensure meta exists for returned posts (best-effort lazy fill for future requests)
			add_filter( 'the_posts', function ( $posts ) {
				foreach ( $posts as $p ) {
					if ( $p->post_type !== 'attachment' ) {
						continue;
					}
					$existing = get_post_meta( $p->ID, 'uixpress_filesize', true );
					if ( '' !== $existing && null !== $existing ) {
						continue;
					}
					$path = get_attached_file( $p->ID );
					if ( $path && file_exists( $path ) ) {
						$bytes = (int) filesize( $path );
						if ( $bytes > 0 ) {
							update_post_meta( $p->ID, 'uixpress_filesize', $bytes );
						}
					}
				}
				return $posts;
			}, 10, 1 );
		}

		if ( 'type' === $orderby ) {
			// Use posts_clauses to order by post_mime_type.
			add_filter( 'posts_clauses', function ( $clauses ) use ( $order ) {
				global $wpdb;
				$clauses['orderby'] = $wpdb->posts . ".post_mime_type " . $order . ", " . $wpdb->posts . ".post_date DESC";
				return $clauses;
			} );
			$args['orderby'] = 'none'; // prevent WP from overriding custom clause
		}

		return $args;
	}

	/**
	 * Parse post_content across public post types to collect attachment IDs used.
	 * Cached via transient to mitigate performance costs.
	 *
	 * @return int[] List of attachment IDs used within post_content or galleries/blocks.
	 */
	private static function collect_used_attachment_ids_from_content(): array {
		$cache_key = 'uixpress_used_attachment_ids_content_cache';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// Fetch posts/pages/custom types except revisions, nav menus, attachments.
		$posts = $wpdb->get_results(
			"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision','nav_menu_item') AND post_status IN ('publish','draft','future','pending','private')",
			ARRAY_A
		);

		$ids = [];
		if ( $posts ) {
			foreach ( $posts as $p ) {
				$content = (string) $p['post_content'];
				if ( $content === '' ) {
					continue;
				}

				// Match Gutenberg image blocks: <!-- wp:image {"id":123 ... } -->
				if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $m1 ) ) {
					$ids = array_merge( $ids, array_map( 'intval', $m1[1] ) );
				}
				// Match classic editor images: class="wp-image-123"
				if ( preg_match_all( '/wp-image-(\d+)/', $content, $m2 ) ) {
					$ids = array_merge( $ids, array_map( 'intval', $m2[1] ) );
				}
				// Match gallery shortcode: [gallery ids="1,2,3"]
				if ( preg_match_all( '/\[gallery[^\]]*ids=\"([^\"]+)\"/i', $content, $m3 ) ) {
					foreach ( $m3[1] as $csv ) {
						$parts = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $csv ) ) ) );
						$ids   = array_merge( $ids, $parts );
					}
				}
				// Match src attributes pointing to uploads
				if ( preg_match_all( '/wp-content\/uploads\/[^"\'>\s]+/', $content, $m4 ) ) {
					foreach ( $m4[0] as $url ) {
						// Try to get attachment ID by URL
						$attachment_id = attachment_url_to_postid( $url );
						if ( $attachment_id > 0 ) {
							$ids[] = $attachment_id;
						}
					}
				}
			}
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		set_transient( $cache_key, $ids, MINUTE_IN_SECONDS * 10 );
		return $ids;
	}
}