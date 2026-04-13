<?php
namespace UiXpress\Pages;
use UiXpress\Options\Settings;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomDashboardPage
 *
 * Handles the custom implementation of the WordPress admin dashboard page
 */
class CustomDashboardPage
{
  /** @var array */
  private static $options;

  /**
   * CustomDashboardPage constructor.
   *
   * Sets up the necessary hooks for the dashboard page
   */
  public function __construct()
  {
    add_action("load-index.php", [$this, "init_dashboard_page"]);
  }

  /**
   * Initializes the custom dashboard page implementation
   *
   * @since 1.0.0
   * @return void
   */
  public function init_dashboard_page()
  {
    // Modern dashboard is not enabled
    if (!Settings::is_enabled("use_custom_dashboard")) {
      return;
    }

    $screen = get_current_screen();

    // Check if current screen is dashboard
    if ($screen->base === "dashboard") {
      $this->prevent_default_loading();
      $this->setup_output_capture();
      add_action("admin_enqueue_scripts", [$this, "load_styles_and_scripts"]);
    }
  }

  /**
   * Loads dashboard styles and scripts
   *
   * @since 1.0.0
   * @return void
   */
  public static function load_styles_and_scripts()
  {
    $url = plugins_url("uixpress/");

    // Get plugin url
    $style = $url . "app/dist/assets/styles/dashboard.css";
    wp_enqueue_style("uixpress-dashboard", $style, [], uixpress_plugin_version);

    add_filter('uixpress/style-layering/exclude', function($excluded_patterns) use ($style) {
      $excluded_patterns[] = $style;
      return $excluded_patterns;
    });

    $script_name = Scripts::get_base_script_path("Dashboard.js");

    // Get dashboard data
    $dashboard_data = self::get_dashboard_data();
    
    wp_print_script_tag([
      "id" => "uipc-dashboard-script",
      "src" => $url . "app/dist/{$script_name}",
      "type" => "module",
      "dashboard-data" => esc_attr(json_encode($dashboard_data)),
    ]);
  }

  /**
   * Gets dashboard data including widgets and styles
   *
   * @since 1.0.0
   * @return array Dashboard data with widgets and styles
   */
  private static function get_dashboard_data()
  {
    // Get dashboard widgets
    $widgets = [];
    
    // Add default dashboard widgets
    $widgets[] = [
      'id' => 'welcome-widget',
      'title' => __('Welcome to WordPress', 'uixpress'),
      'content' => '<p>' . __('Welcome to your WordPress dashboard! This is your site management hub.', 'uixpress') . '</p>'
    ];
    
    $widgets[] = [
      'id' => 'quick-draft-widget',
      'title' => __('Quick Draft', 'uixpress'),
      'content' => '<p>' . __('Create a new post quickly from here.', 'uixpress') . '</p>'
    ];
    
    $widgets[] = [
      'id' => 'activity-widget',
      'title' => __('Activity', 'uixpress'),
      'content' => '<p>' . __('Recent activity on your site.', 'uixpress') . '</p>'
    ];

    // Get dashboard styles
    $styles = [];
    
    return [
      'widgets' => $widgets,
      'styles' => $styles
    ];
  }

  /**
   * Prevents WordPress from loading default dashboard components
   *
   * @since 1.0.0
   * @return void
   */
  private function prevent_default_loading()
  {
    // Remove default admin bar initialization
    remove_action("admin_init", "_wp_admin_bar_init");
    remove_action("admin_init", "wp_admin_bar_init");

    // Remove default dashboard widgets
    remove_action("wp_dashboard_setup", "wp_dashboard_setup");
    
    // Prevent dashboard queries from running
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
   * Renders the custom content for the dashboard page
   *
   * @since 1.0.0
   * @return void
   */
  public function render_custom_content()
  {
    ob_end_clean(); ?>
		<div id="uix-dashboard-page">
		</div>
		<?php
  }
}
