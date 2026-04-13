<?php

namespace UiXpress\Utility;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Extends WordPress search functionality to include post meta values.
 *
 * This class modifies the default WordPress search behavior to include
 * post meta values in search queries. When instantiated, it adds a filter
 * to the posts_search hook that modifies the SQL query to include searches
 * within the postmeta table.
 *
 * @package UiXpress\Utility
 * @since 1.0.0
 */
class ExtendSearchToMeta
{
  /**
   * Initialize the extended search functionality.
   *
   * Creates an instance of the class and sets up the search modification
   * by calling the extend_search_to_meta method.
   *
   * @param array  $args The WordPress query arguments
   * @param string $s    The search term
   */
  public function __construct($args, $s)
  {
    self::extend_search_to_meta($args, $s);
  }

  /**
   * Extends WordPress search to include post meta values.
   *
   * Adds a filter to the posts_search hook that modifies the SQL query
   * to search within post meta values in addition to post title, content,
   * and excerpt.
   *
   * @param array  $args The WordPress query arguments
   * @param string $s    The search term to look for
   * @return void
   *
   * @global wpdb $wpdb WordPress database abstraction object
   *
   * @uses add_filter()  Hooks into 'posts_search'
   * @uses esc_sql()     Escapes SQL query
   * @uses wpdb::esc_like() Escapes LIKE query
   */
  private static function extend_search_to_meta($args, $s)
  {
    $args["s"] = $s;

    add_filter(
      "posts_search",
      function ($search, $wp_query) use ($s) {
        global $wpdb;

        if (!empty($search) && !empty($wp_query->query_vars["s"])) {
          $s = esc_sql($wpdb->esc_like($s));
          // Create the new search SQL that includes postmeta
          $search = " AND (
						({$wpdb->posts}.post_title LIKE '%{$s}%')
						OR ({$wpdb->posts}.post_content LIKE '%{$s}%')
						OR ({$wpdb->posts}.post_excerpt LIKE '%{$s}%')
						OR EXISTS (
							SELECT 1 
							FROM {$wpdb->postmeta} 
							WHERE post_id = {$wpdb->posts}.ID 
							AND meta_value LIKE '%{$s}%'
						)
					)";
        }
        return $search;
      },
      10,
      2
    );
  }
}
