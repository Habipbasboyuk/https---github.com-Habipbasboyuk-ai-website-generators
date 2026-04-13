<?php
namespace UiXpress\Pages;
use UiXpress\Options\Settings;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class PostsList
 *
 * Handles the custom implementation of the WordPress admin posts list page
 */
class PostsList
{
  /** @var array */
  private static $options;

  /**
   * PostsList constructor.
   *
   * Sets up the necessary hooks for the posts list page
   */
  public function __construct()
  {
    add_action("load-edit.php", [$this, "init_posts_page"]);
    //add_action("load-edit-comments.php", [$this, "init_comments_page"]);
  }

  /**
   * Initializes the custom posts page implementation
   *
   * @since 1.0.0
   * @return void
   */
  public function init_posts_page()
  {
    // Modern post tables are disabled
    if (Settings::is_enabled("use_classic_post_tables")) {
      return;
    }

    $screen = get_current_screen();

    // Use global post types variable instead of get_post_types()
    global $wp_post_types;

    if (in_array($screen->post_type, ["product"])) {
      return;
    }

    // Check if current screen is edit page and post type exists
    if ($screen->base === "edit" && isset($wp_post_types[$screen->post_type]) && $wp_post_types[$screen->post_type]->show_in_rest && $wp_post_types[$screen->post_type]->rest_base) {
      $this->prevent_default_loading();
      $this->setup_output_capture();
      add_action("admin_enqueue_scripts", [$this, "load_styles_and_scripts"]);
    }
  }

  /**
   * Initializes the custom comments page implementation
   *
   * @since 1.0.0
   * @return void
   */
  public function init_comments_page()
  {
    $screen = get_current_screen();

    // Check if we're on the comments page
    if ($screen->base === "edit-comments") {
      $this->prevent_default_loading();
      $this->setup_output_capture();
      add_action("admin_enqueue_scripts", [$this, "load_styles_and_scripts"]);
    }
  }

  /**
   * uixpress scripts.
   *
   * Loads main lp scripts
   */
  public static function load_styles_and_scripts()
  {
    $url = plugins_url("uixpress/");

    // Get plugin url
    $style = $url . "app/dist/assets/styles/posts.css";
    wp_enqueue_style("uixpress-posts", $style, [], uixpress_plugin_version);

    add_filter('uixpress/style-layering/exclude', function($excluded_patterns) use ($style) {
      $excluded_patterns[] = $style;
      return $excluded_patterns;
    });

    $script_name = Scripts::get_base_script_path("Posts.js");

    // Method 1: Using is_object_in_taxonomy()
    $post_type = !empty($_GET["post_type"]) ? sanitize_text_field($_GET["post_type"]) : "post";
    $supports_categories = false;
    if (is_object_in_taxonomy($post_type, "category")) {
      $supports_categories = true;
    }

    $supports_tags = false;
    if (is_object_in_taxonomy($post_type, "post_tag")) {
      $supports_tags = true;
    }

    wp_print_script_tag([
      "id" => "uipc-posts-script",
      "src" => $url . "app/dist/{$script_name}",
      "type" => "module",
      "supports_categories" => esc_attr($supports_categories),
      "supports_tags" => esc_attr($supports_tags),
      "post_statuses" => esc_attr(json_encode(self::get_post_type_statuses($post_type))),
    ]);
  }

  /**
   * Get unique available post statuses for a given post type formatted as label/value pairs,
   * filtered to only include statuses that can be set via the REST API.
   *
   * Retrieves post statuses that are:
   * 1. Publicly queryable or accessible to users with appropriate permissions
   * 2. Safe to use with the REST API
   * 3. Not internal-use statuses
   *
   * @since 1.0.0
   *
   * @param string $post_type The post type to check statuses for (e.g., 'post', 'page', 'product')
   *
   * @return array[] Array of status arrays, each containing:
   *                 {
   *                     @type string $label The human-readable status label
   *                     @type string $value The status slug/name
   *                 }
   */
  private static function get_post_type_statuses($post_type)
  {
    // Get all registered statuses
    $statuses = get_post_stati([], "objects");

    // Get the post type object to check supported features
    $post_type_object = get_post_type_object($post_type);

    // Define core statuses that are safe for REST API usage
    $rest_safe_statuses = ["publish", "future", "draft", "private"];

    $available_statuses = [];

    foreach ($statuses as $status) {
      // Skip if this is an internal status
      if (!empty($status->internal)) {
        continue;
      }

      // Skip if this is not a REST-safe status
      if (!in_array($status->name, $rest_safe_statuses) && (empty($status->show_in_rest) || !$status->show_in_rest)) {
        continue;
      }

      // Check if this status is publicly queryable or if the user can edit private posts
      if ($status->show_in_admin_all_list || current_user_can($post_type_object->cap->edit_private_posts)) {
        // Use status name as key to prevent duplicates
        $available_statuses[$status->name] = [
          "label" => $status->label,
          "value" => $status->name,
        ];
      }
    }

    // Reset array keys to return sequential numeric array
    return array_values($available_statuses);
  }

  /**
   * Prevents WordPress from loading default posts page components
   *
   * @since 1.0.0
   * @return void
   */
  private function prevent_default_loading()
  {
    // Remove default admin bar initialization
    remove_action("admin_init", "_wp_admin_bar_init");
    remove_action("admin_init", "wp_admin_bar_init");

    // Prevent post queries from running
    add_filter("pre_get_posts", [$this, "modify_main_query"]);
  }

  /**
   * Modifies the main query to prevent post loading
   *
   * @param WP_Query $query The WordPress query object
   * @return WP_Query
   * @since 1.0.0
   */
  public function modify_main_query($query)
  {
    if ($query->is_main_query() && is_admin()) {
      $query->set("posts_per_page", 0);
      $query->set("no_found_rows", true);
    }
    return $query;
  }

  /**
   * Sets up output buffering and custom content display
   *
   * @since 1.0.0
   * @return void
   */
  private function setup_output_capture()
  {
    // Start output buffering
    add_action("in_admin_header", [$this, "start_output_buffer"], 999);

    // Output custom content
    add_action("admin_footer", [$this, "render_custom_content"], 0);
  }

  /**
   * Starts the output buffer
   *
   * @since 1.0.0
   * @return void
   */
  public function start_output_buffer()
  {
    ob_start();
  }

  /**
   * Renders the custom content for the posts page
   *
   * @since 1.0.0
   * @return void
   */
  public function render_custom_content()
  {
    ob_end_clean(); ?>
		<div id="uix-posts-page">
		</div>
		<?php
  }
}
