<?php
namespace UiXpress\Rest;

use UiXpress\Options\Settings;

// Prevent direct access to this file
defined("ABSPATH") || exit("No direct script access allowed.");

/**
 * Class SearchMeta
 *
 * Extends WordPress REST API search functionality to include all meta fields
 * for posts, pages, and users.
 *
 * @package UiXpress\Rest
 */
class SearchMeta
{
  /**
   * Initialize the class and set up WordPress filters.
   *
   * This constructor adds filters to modify the query args for posts, pages,
   * and users in the WordPress REST API.
   */
  public function __construct()
  {
    $search_post_types = Settings::get_setting("search_post_types", []);

    if (is_array($search_post_types) && !empty($search_post_types)) {
      foreach ($search_post_types as $postType) {
        add_filter("rest_{$postType["slug"]}_query", [__CLASS__, "rest_page_query"], 10, 2);
      }
    } else {
      add_filter("rest_post_query", [__CLASS__, "rest_page_query"], 10, 2);
      add_filter("rest_page_query", [__CLASS__, "rest_page_query"], 10, 2);
    }

    add_filter("rest_user_query", [__CLASS__, "rest_user_query"], 10, 2);
  }

  /**
   * Modify the REST API query for posts and pages to include meta search.
   *
   * This method adds a custom WHERE clause to search post/page meta fields.
   *
   * @param array           $args    The query arguments.
   * @param WP_REST_Request $request The REST API request.
   *
   * @return array Modified query arguments.
   */
  public static function rest_page_query($args, $request)
  {
    if (!empty($request["search"])) {
      $search_term = sanitize_text_field($request["search"]);
      $post_type = isset($args["post_type"]) ? sanitize_key($args["post_type"]) : "post";
      
      // Validate post type exists to prevent SQL injection
      if (!post_type_exists($post_type)) {
        return $args;
      }

      // Use posts_where and posts_join filters to search all meta values
      add_filter("posts_where", function ($where) use ($search_term, $post_type) {
        global $wpdb;
        $where .= $wpdb->prepare(
          " OR ($wpdb->posts.post_type = %s AND $wpdb->posts.ID IN (
                        SELECT post_id FROM {$wpdb->postmeta}
                        WHERE meta_value LIKE %s
                    ))",
          $post_type,
          "%" . $wpdb->esc_like($search_term) . "%"
        );
        return $where;
      });

      // Ensure we're not duplicating results
      $args["suppress_filters"] = false;
      $args["groupby"] = "ID";
    }
    return $args;
  }

  /**
   * Modify the REST API query for users to include meta search.
   *
   * This method adds a custom WHERE clause to search user meta fields.
   *
   * @param array           $args    The query arguments.
   * @param WP_REST_Request $request The REST API request.
   *
   * @return array Modified query arguments.
   */
  public static function rest_user_query($args, $request)
  {
    if (!empty($request["search"])) {
      $search_term = sanitize_text_field($request["search"]);
      add_filter(
        "users_pre_query",
        function ($null, $query) use ($search_term) {
          global $wpdb;
          $search_wild = "%" . $wpdb->esc_like($search_term) . "%";
          $query->query_where .= $wpdb->prepare(
            " OR ID IN (
                            SELECT user_id FROM {$wpdb->usermeta}
                            WHERE meta_value LIKE %s
                        )",
            $search_wild
          );
          return $null;
        },
        10,
        2
      );
      $args["suppress_filters"] = false;
    }
    return $args;
  }
}
