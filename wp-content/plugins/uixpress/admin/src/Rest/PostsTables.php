<?php
namespace UiXpress\Rest;

use UiXpress\Options\Settings;
use UiXpress\Tables\ColumnClassesListTable;
use UiXpress\Utility\ExtendSearchToMeta;
use UiXpress\Utility\CaptureStyles;
use WP_Query;
use WP_Screen;

defined("ABSPATH") || exit();

/**
 * Class PostsTables
 *
 * Creates a hidden admin page that outputs posts data as JSON
 */
class PostsTables
{
  /** @var array */
  private $captured_styles = [];

  /** @var array */
  private $query_params = [];

  /** @var string */
  private $post_type;

  /** @var array */
  private static $options;

  /**
   * Constructor
   */
  public function __construct()
  {
    add_action("admin_menu", [$this, "register_hidden_page"]);
    add_action("plugins_loaded", [$this, "handle_ajax_request"]);
    add_action("admin_enqueue_scripts", [$this, "capture_styles"], 9999);
  }

  /**
   * Registers the hidden admin page
   */
  public function register_hidden_page()
  {
    add_submenu_page("", "Posts Data", "Posts Data", "edit_posts", "uixpress-posts-data", [$this, "render_page"]);
  }

  /**
   * Captures and filters custom styles from the current WordPress admin page.
   *
   * @param string $hook The current admin page hook
   */
  public function capture_styles($hook)
  {
    if (!$this->is_posts_data_page()) {
      return;
    }

    $stylesCapture = new CaptureStyles();
    $this->captured_styles = $stylesCapture::get_styles();
  }

  /**
   * Handles the AJAX request validation
   */
  public function handle_ajax_request()
  {
    if (!$this->is_posts_data_page()) {
      return;
    }

    $this->validate_ajax_request();
    $this->setup_post_type_globals();
  }

  /**
   * Validates AJAX request and user permissions
   */
  private function validate_ajax_request()
  {
    // Check if user is logged in
    if (!is_user_logged_in()) {
      wp_die(__("You must be logged in to access this page."));
    }

    // Use nonce verification - accept both REST API nonce and page-specific nonce
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
    $nonce_valid = false;
    
    if ($nonce) {
      // Try REST API nonce first (most common)
      if (wp_verify_nonce($nonce, 'wp_rest')) {
        $nonce_valid = true;
      }
      // Fallback to page-specific nonce for backward compatibility
      elseif (wp_verify_nonce($nonce, 'uixpress-posts-data')) {
        $nonce_valid = true;
      }
    }
    
    if (!$nonce_valid) {
      wp_die(__("Invalid request. Please refresh the page and try again."));
    }

    if (!current_user_can("edit_posts")) {
      wp_die(__("You do not have sufficient permissions to access this page."));
    }
  }

  /**
   * Sets up global variables for post type
   */
  private function setup_post_type_globals()
  {
    global $pagenow, $typenow;
    $this->post_type = !empty($_GET["post_type"]) ? sanitize_text_field($_GET["post_type"]) : "post";

    $pagenow = "edit.php";
    $typenow = $this->post_type;
  }

  /**
   * Processes and returns query parameters from GET request
   *
   * @return array
   */
  private function get_query_parameters()
  {
    return [
      "post_type" => $this->post_type,
      "per_page" => !empty($_GET["per_page"]) ? absint($_GET["per_page"]) : 20,
      "paged" => !empty($_GET["paged"]) ? absint($_GET["paged"]) : 1,
      "orderby" => !empty($_GET["orderby"]) ? sanitize_text_field($_GET["orderby"]) : "date",
      "order" => !empty($_GET["order"]) ? sanitize_text_field($_GET["order"]) : "DESC",
      "s" => !empty($_GET["s"]) ? sanitize_text_field($_GET["s"]) : "",
      "post_status" => !empty($_GET["post_status"]) ? sanitize_text_field($_GET["post_status"]) : "any",
      "start_date" => !empty($_GET["start_date"]) ? sanitize_text_field($_GET["start_date"]) : false,
      "end_date" => !empty($_GET["end_date"]) ? sanitize_text_field($_GET["end_date"]) : false,
      "categories" => !empty($_GET["categories"]) ? sanitize_text_field($_GET["categories"]) : false,
      "author" => !empty($_GET["author"]) ? absint($_GET["author"]) : false,
    ];
  }

  /**
   * Builds WP_Query arguments based on query parameters
   *
   * @param array $params Query parameters
   * @return array
   */
  private function build_query_args($params)
  {
    $args = [
      "post_type" => $params["post_type"],
      "posts_per_page" => $params["per_page"],
      "paged" => $params["paged"],
      //"orderby" => [
      //"parent" => "ASC",
      //$params["orderby"] => $params["order"],
      //],
      "orderby" => $params["orderby"],
      "order" => $params["order"],
      //"orderby" => "menu_order title",
      //"order" => "ASC",
      "post_status" => explode(",", $params["post_status"]),
    ];

    if ($params["start_date"] && $params["end_date"]) {
      $args["date_query"] = [
        "after" => $params["start_date"],
        "before" => $params["end_date"],
      ];
    }

    if ($params["author"]) {
      $args["author"] = $params["author"];
    }

    if ($params["categories"]) {
      $args["cat"] = $params["categories"];
    }

    if (!empty($params["s"])) {
      $args["s"] = $params["s"];
      new ExtendSearchToMeta($args, $params["s"]);
    }

    if ($params["post_type"] === "page" && $params["post_status"] !== "trash") {
      $args["post_parent"] = 0;
    }

    return $args;
  }

  /**
   * Adds custom ordering to ensure hierarchical posts (parent-child relationships) are properly sorted
   * while maintaining the original sort parameter.
   *
   * @param string $orderby         The original orderby parameter from the query
   * @param WP_Query $query        The WordPress query object
   * @param string $original_orderby The original orderby field to maintain in final sorting
   *
   * @return void
   *
   * @uses add_filter() Hooks into 'posts_orderby' to modify the SQL ORDER BY clause
   * @global wpdb $wpdb WordPress database abstraction object
   *
   * @note This function will only modify queries that have the 'hierarchical' parameter set to true
   */
  private function order_hierarchical($orderby, $query, $original_orderby)
  {
    // Add a filter to modify the final query results
    add_filter(
      "posts_orderby",
      function ($orderby, $query) use ($original_orderby) {
        global $wpdb;

        // Only modify our specific query
        if (!$query->get("hierarchical")) {
          return $orderby;
        }

        // Create a custom ORDER BY clause that respects hierarchy
        $new_orderby = "
            {$wpdb->posts}.post_parent ASC,
            CASE 
                WHEN {$wpdb->posts}.post_parent = 0 THEN {$wpdb->posts}.ID
                ELSE {$wpdb->posts}.post_parent 
            END ASC";

        // Append the original orderby parameter
        if ($original_orderby !== "date") {
          $new_orderby .= ", {$wpdb->posts}.{$original_orderby} " . $query->get("order");
        }

        return $new_orderby;
      },
      10,
      2
    );
  }

  /**
   * Sets up and returns the WP_List_Table instance
   *
   * @param string $post_type
   * @return ColumnClassesListTable
   */
  private function setup_list_table($post_type)
  {
    global $typenow;
    $typenow = $post_type;
    set_current_screen("edit-{$post_type}");
    $screen = get_current_screen();

    $wp_list_table = new ColumnClassesListTable(["screen" => $screen]);
    $wp_list_table->prepare_items();

    return $wp_list_table;
  }

  /**
   * Processes view data for status filters
   *
   * @param array $views
   * @return array
   */
  private function process_views($views)
  {
    $processed_views = [];
    $post_stati = get_post_stati(["show_in_admin_all_list" => true], "objects");
    unset($post_stati["auto-draft"]);

    foreach ($views as $key => $view) {
      preg_match("/\((\d+)\)/", $view, $matches);
      $count = isset($matches[1]) ? (int) $matches[1] : 0;
      $label = preg_replace("/\s*\(\d+\)/", "", strip_tags($view));

      $query_params = $this->get_view_query_params($key);

      $processed_views[$key] = [
        "label" => "{$label} ({$count})",
        "count" => $count,
        "value" => $key,
        "current" => strpos($view, 'class="current"') !== false,
        "query_params" => $query_params,
      ];
    }

    return $processed_views;
  }

  /**
   * Gets query parameters for a specific view
   *
   * @param string $view_key
   * @return array
   */
  private function get_view_query_params($view_key)
  {
    if ($view_key === "mine") {
      return ["author" => get_current_user_id()];
    }

    if ($view_key === "all") {
      return ["post_status" => ["any"]];
    }

    return ["post_status" => $view_key];
  }

  /**
   * Processes column data
   *
   * @param WP_Screen $screen
   * @param string $post_type
   * @return array
   */
  private function process_columns($screen, $post_type)
  {
    $columns = get_column_headers($screen);
    $columns = apply_filters("manage_{$post_type}_posts_columns", $columns);
    $allowed_orderby = ["ID", "author", "title", "name", "type", "date", "modified"];

    return $this->format_columns($columns, $allowed_orderby);
  }

  /**
   * Formats columns data
   *
   * @param array $columns
   * @param array $allowed_orderby
   * @return array
   */
  private function format_columns($columns, $allowed_orderby)
  {
    $formatted_columns = [];

    foreach ($columns as $key => $value) {
      if ($key == "cb") {
        continue;
      }

      $formatted_columns[$key] = [
        "sort_key" => in_array($key, $allowed_orderby) ? $key : false,
        "label" => $value,
        "key" => $key,
        "active" => true,
      ];

      if ($key === "title") {
        $formatted_columns["status"] = [
          "label" => __("Status", "uixpress"),
          "sort_key" => "post_status",
          "key" => "status",
          "active" => true,
        ];
      }
    }

    return $this->add_special_columns($formatted_columns);
  }

  /**
   * Adds special columns with custom labels
   *
   * @param array $columns
   * @return array
   */
  private function add_special_columns($columns)
  {
    $columns["date"]["label"] = __("Published", "uixpress");
    $columns["comments"]["label"] = __("Comments", "uixpress");
    $columns["post_actions"] = [
      "label" => "",
      "sort_key" => false,
      "key" => "post_actions",
      "active" => true,
    ];

    return $columns;
  }

  /**
   * Processes post data for a single post
   *
   * @param WP_Post $post
   * @param array $columns
   * @param ColumnClassesListTable $wp_list_table
   * @param string $rest_base
   * @return array
   */
  private function process_post_data($post, $columns, $wp_list_table, $rest_base, $depth)
  {
    $post_data = $this->get_base_post_data($post, $rest_base);
    $post_data["row_actions"] = $this->get_row_actions($post);
    $post_data["children"] = [];
    $post_data["depth"] = $depth;

    foreach ($columns as $column_name => $column_info) {
      if ($column_name === "cb") {
        continue;
      }

      $post_data = $this->process_column_data($post_data, $column_name, $post, $wp_list_table);
    }

    $query_args = self::get_query_parameters();

    $args = [
      "post_type" => "page",
      "post_parent" => $post->ID,
      "posts_per_page" => -1,
      "order" => $query_args["order"],
      "orderby" => $query_args["orderby"],
    ];

    $depth++;
    $query = new WP_Query($args);

    if ($query->have_posts()):
      foreach ($query->posts as $child_post):
        $post_data["children"][] = self::process_post_data($child_post, $columns, $wp_list_table, $rest_base, $depth);
      endforeach;
    endif;
    wp_reset_postdata();

    return $post_data;
  }

  /**
   * Gets base post data
   *
   * @param WP_Post $post
   * @param string $rest_base
   * @return array
   */
  private function get_base_post_data($post, $rest_base)
  {
    return [
      "id" => $post->ID,
      "title" => $post->post_title,
      "type" => $post->post_type,
      "rest_base" => $rest_base,
      "edit_url" => html_entity_decode(get_edit_post_link($post->ID)),
      "view_url" => html_entity_decode(get_permalink($post->ID)),
      "single_status" => $post->post_status,
      "row_actions" => [],
      "is_editable" => current_user_can("edit_post", $post->ID),
    ];
  }

  /**
   * Gets row actions for a post
   *
   * @param WP_Post $post
   * @return array
   */
  private function get_row_actions($post)
  {
    $actions = [];
    $actions = apply_filters("post_row_actions", $actions, $post);
    $actions = apply_filters("{$post->post_type}_row_actions", $actions, $post);

    return array_map(
      function ($link, $action) {
        preg_match('/href=["\'](.*?)["\']/', $link, $url_matches);
        preg_match("/<a.*?>(.*?)<\/a>/", $link, $text_matches);

        return [
          "key" => $action,
          "url" => isset($url_matches[1]) ? html_entity_decode($url_matches[1]) : "",
          "text" => isset($text_matches[1]) ? strip_tags($text_matches[1]) : strip_tags($link),
          "html" => $link,
        ];
      },
      $actions,
      array_keys($actions)
    );
  }

  /**
   * Processes column data for a specific column
   *
   * @param array $post_data
   * @param string $column_name
   * @param WP_Post $post
   * @param ColumnClassesListTable $wp_list_table
   * @return array
   */
  private function process_column_data($post_data, $column_name, $post, $wp_list_table)
  {
    ob_start();

    if (method_exists($wp_list_table, "column_$column_name")) {
      call_user_func([$wp_list_table, "column_$column_name"], $post);
    } else {
      do_action("manage_{$post->post_type}_posts_custom_column", $column_name, $post->ID);
      do_action("manage_posts_custom_column", $column_name, $post->ID);
    }

    $column_value = ob_get_clean();
    $post_data[$column_name] = $column_value;

    $post_data = $this->process_special_columns($post_data, $column_name, $post);

    $post_data["cell_classes"][$column_name] = $wp_list_table->get_cell_classes($column_name, $post);

    return $post_data;
  }

  /**
   * Processes special columns with custom formatting
   *
   * @param array $post_data
   * @param string $column_name
   * @param WP_Post $post
   * @return array
   */
  private function process_special_columns($post_data, $column_name, $post)
  {
    $processors = [
      "taxonomy-" => [$this, "process_taxonomy_column"],
      "title" => [$this, "process_title_column"],
      "date" => [$this, "process_date_column"],
      "categories" => [$this, "process_categories_column"],
      "tags" => [$this, "process_tags_column"],
      "status" => [$this, "process_status_column"],
      "author" => [$this, "process_author_column"],
      "comments" => [$this, "process_comments_column"],
    ];

    foreach ($processors as $key => $callback) {
      if (strpos($column_name, $key) === 0 || $column_name === $key) {
        $post_data[$column_name] = call_user_func($callback, $post, $column_name);
      }
    }

    return $post_data;
  }

  /**
   * Processes taxonomy column data
   *
   * @param WP_Post $post
   * @param string $column_name
   * @return array
   */
  private function process_taxonomy_column($post, $column_name)
  {
    $taxonomy = str_replace("taxonomy-", "", $column_name);
    $terms = get_the_terms($post->ID, $taxonomy);

    if (!is_wp_error($terms) && !empty($terms)) {
      return array_map(function ($term) {
        return [
          "title" => $term->name,
          "url" => get_edit_term_link($term->term_id, $term->taxonomy),
          "id" => $term->term_id,
        ];
      }, $terms);
    }

    return [];
  }

  /**
   * Processes title column data
   *
   * @param WP_Post $post
   * @return array
   */
  private function process_title_column($post)
  {
    $thumbnail_id = get_post_thumbnail_id($post->ID);
    $thumbnail = false;

    if ($thumbnail_id) {
      $thumbnail = wp_get_attachment_image_src($thumbnail_id, "thumbnail");
    }

    return [
      "value" => $post->post_title == "" ? __("(No title)", "uixpress") : $post->post_title,
      "image_url" => $thumbnail ? $thumbnail[0] : null,
    ];
  }

  /**
   * Processes date column data
   *
   * @param WP_Post $post
   * @return array
   */
  private function process_date_column($post)
  {
    $date_format = get_option("date_format");
    $current_time = current_time("timestamp");

    // Published date
    $published_timestamp = get_post_time("U", false, $post);
    $published_time_diff = human_time_diff($published_timestamp, $current_time);
    $published_exact_date = get_the_date($date_format, $post);

    // Modified date
    $modified_timestamp = get_post_modified_time("U", false, $post);
    $modified_time_diff = human_time_diff($modified_timestamp, $current_time);
    $modified_exact_date = get_the_modified_date($date_format, $post);

    // Check if modified date is different from published date
    $has_been_modified = $modified_timestamp > $published_timestamp;

    if (get_post_status($post) === "future") {
      return [
        "published" => [
          "label" => __("Scheduled", "uixpress"),
          "relative" => sprintf(__("%s from now", "uixpress"), $published_time_diff),
          "exact" => $published_exact_date,
          "timestamp" => (int) $published_timestamp,
        ],
        "modified" => $has_been_modified ? [
          "label" => __("Modified", "uixpress"),
          "relative" => sprintf(__("%s ago", "uixpress"), $modified_time_diff),
          "exact" => $modified_exact_date,
          "timestamp" => (int) $modified_timestamp,
        ] : null,
      ];
    }

    return [
      "published" => [
        "label" => __("Published", "uixpress"),
        "relative" => sprintf(__("%s ago", "uixpress"), $published_time_diff),
        "exact" => $published_exact_date,
        "timestamp" => (int) $published_timestamp,
      ],
      "modified" => $has_been_modified ? [
        "label" => __("Modified", "uixpress"),
        "relative" => sprintf(__("%s ago", "uixpress"), $modified_time_diff),
        "exact" => $modified_exact_date,
        "timestamp" => (int) $modified_timestamp,
      ] : null,
    ];
  }

  /**
   * Processes categories column data
   *
   * @param WP_Post $post
   * @return array
   */
  private function process_categories_column($post)
  {
    $categories = get_the_category($post->ID);
    return array_map(function ($cat) {
      return [
        "title" => $cat->name,
        "url" => get_edit_term_link($cat->term_id, "category"),
        "id" => $cat->term_id,
      ];
    }, $categories);
  }

  /**
   * Processes tags column data
   *
   * @param WP_Post $post
   * @return array
   */
  private function process_tags_column($post)
  {
    $tags = get_the_tags($post->ID);
    if (!$tags) {
      return [];
    }

    return array_map(function ($tag) {
      return [
        "title" => $tag->name,
        "url" => get_edit_term_link($tag->term_id, "post_tag"),
        "id" => $tag->term_id,
      ];
    }, $tags);
  }

  /**
   * Processes status column data
   *
   * @param WP_Post $post
   * @return array
   */
  private function process_status_column($post)
  {
    $status_obj = get_post_status_object($post->post_status);
    $post_status = $status_obj ? $status_obj->label : $post->post_status;

    return [
      "value" => $post->post_status,
      "label" => $post_status,
    ];
  }

  /**
   * Processes author column data
   *
   * @param WP_Post $post
   * @return array
   */
  private function process_author_column($post)
  {
    $author_id = $post->post_author;
    $author = get_userdata($author_id);
    $display_name = $author->display_name ?: $author->user_login;
    $avatar = get_avatar($author_id, 32, "", "", ["class" => "author-avatar"]);
    $author_url = get_author_posts_url($author_id);

    return [
      "name" => $display_name,
      "avatar" => $avatar,
      "url" => $author_url,
      "id" => $author_id,
    ];
  }

  /**
   * Processes comments column data
   *
   * @param WP_Post $post
   * @return string
   */
  private function process_comments_column($post)
  {
    $comment_count = get_comments_number($post->ID);
    return $comment_count > 0 ? "<span class='uix-comment-count'>{$comment_count}</span>" : "";
  }

  /**
   * Checks if current page is the posts data page
   *
   * @return boolean
   */
  private function is_posts_data_page()
  {
    return isset($_GET["page"]) && $_GET["page"] === "uixpress-posts-data";
  }

  public function modify_pages_orderby($orderby, $query)
  {
    global $wpdb;
    if (isset($query->query["post_type"]) && $query->query["post_type"] === "page") {
      return "CONCAT(LPAD(COALESCE($wpdb->posts.post_parent, 0), 10, '0'), $wpdb->posts.post_title) ASC";
    }
    return $orderby;
  }

  /**
   * Renders the page with JSON data
   */
  public function render_page()
  {
    // Modern post tables are disabled
    if (Settings::is_enabled("use_classic_post_tables")) {
      return;
    }

    global $post;

    $params = $this->get_query_parameters();
    $args = $this->build_query_args($params);

    $post_type_object = get_post_type_object($params["post_type"]);
    $rest_base = $post_type_object->rest_base === false ? $post_type_object->name : $post_type_object->rest_base;

    //add_filter("posts_orderby", [$this, "modify_pages_orderby"], 10, 2);
    $query = new WP_Query($args);
    $wp_list_table = $this->setup_list_table($params["post_type"]);

    // Get and process views
    $views = $wp_list_table->get_views();
    $processed_views = $this->process_views($views);

    // Get and process columns
    $wp_list_table->print_column_headers(false);
    $column_classes = $wp_list_table->captured_classes;
    $columns = $this->process_columns(get_current_screen(), $params["post_type"]);

    // Process posts data
    $posts = [];
    if ($query->have_posts()) {
      while ($query->have_posts()) {
        $query->the_post();
        $posts[] = $this->process_post_data($post, $columns, $wp_list_table, $rest_base, 0);
      }
    }
    wp_reset_postdata();

    // Prepare response
    $response = [
      "items" => $posts,
      "total" => $query->found_posts,
      "pages" => ceil($query->found_posts / $params["per_page"]),
      "columns" => $columns,
      "column_classes" => $column_classes,
      "custom_styles" => array_values($this->captured_styles),
      "views" => $processed_views,
    ];
    ?>
        <script type="application/json" id="uixpress-posts-data">
            <?php echo wp_json_encode($response); ?>
        </script>
        <?php die();
  }
}
