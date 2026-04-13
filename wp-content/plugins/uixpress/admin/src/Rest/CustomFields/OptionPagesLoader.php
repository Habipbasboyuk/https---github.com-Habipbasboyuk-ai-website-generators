<?php
namespace UiXpress\Rest\CustomFields;

use UiXpress\Rest\CustomFields\OptionPagesRepository;
use UiXpress\Rest\CustomFields\OptionPageRenderer;
use UiXpress\Rest\CustomFields\OptionPagesScriptLoader;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class OptionPagesLoader
 *
 * Handles registering admin menu pages for custom option pages
 */
class OptionPagesLoader
{
  /**
   * @var OptionPagesRepository
   */
  private $repository;

  /**
   * @var OptionPageRenderer
   */
  private $renderer;

  /**
   * @var array Registered page hooks for script enqueuing
   */
  private $page_hooks = [];

  /**
   * Initialize the loader
   */
  public function __construct()
  {
    $this->repository = new OptionPagesRepository();
    $this->renderer = new OptionPageRenderer();
  }

  /**
   * Initialize hooks
   */
  public function init()
  {
    add_action('admin_menu', [$this, 'register_option_pages'], 99);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    add_action('admin_footer', [$this, 'print_footer_scripts']);
  }

  /**
   * Register all active option pages as admin menu pages
   */
  public function register_option_pages()
  {
    $option_pages = $this->repository->get_active_pages();

    if (empty($option_pages)) {
      return;
    }

    foreach ($option_pages as $page) {
      $this->register_single_page($page);
    }
  }

  /**
   * Register a single option page
   *
   * @param array $page Option page data
   */
  private function register_single_page($page)
  {
    if (empty($page['slug']) || empty($page['title'])) {
      return;
    }

    $menu_slug = 'uixpress-options-' . $page['slug'];
    $capability = !empty($page['capability']) ? $page['capability'] : 'manage_options';
    $page_title = $page['title'];
    $menu_title = $page['title'];

    if ($page['menu_type'] === 'top_level') {
      // Register as top-level menu page
      $icon_name = !empty($page['menu_icon']) ? $page['menu_icon'] : 'settings';
      $icon = $this->get_menu_icon_url($icon_name);
      $position = !empty($page['menu_position']) ? (int) $page['menu_position'] : 100;

      $hook = add_menu_page(
        $page_title,
        $menu_title,
        $capability,
        $menu_slug,
        [$this, 'render_option_page'],
        $icon,
        $position
      );
    } else {
      // Register as submenu page
      $parent_slug = !empty($page['parent_menu']) ? $page['parent_menu'] : 'options-general.php';

      $hook = add_submenu_page(
        $parent_slug,
        $page_title,
        $menu_title,
        $capability,
        $menu_slug,
        [$this, 'render_option_page']
      );
    }

    if ($hook) {
      $this->page_hooks[$hook] = $page;
    }
  }

  /**
   * Render the option page content
   */
  public function render_option_page()
  {
    // Get current screen to identify which page we're on
    $screen = get_current_screen();
    
    if (!$screen) {
      return;
    }

    // Find the page data for this screen
    $page = null;
    foreach ($this->page_hooks as $hook => $page_data) {
      if ($screen->id === $hook) {
        $page = $page_data;
        break;
      }
    }

    if (!$page) {
      echo '<div class="wrap"><h1>' . esc_html__('Option Page Not Found', 'uixpress') . '</h1></div>';
      return;
    }

    // Render the page
    $this->renderer->render($page);
  }

  /**
   * Enqueue scripts for option pages
   *
   * @param string $hook_suffix The current admin page hook suffix
   */
  public function enqueue_scripts($hook_suffix)
  {
    // Check if we're on one of our option pages
    if (!isset($this->page_hooks[$hook_suffix])) {
      return;
    }

    $page = $this->page_hooks[$hook_suffix];

    // Use the script loader to load assets
    OptionPagesScriptLoader::load_assets($page);
  }

  /**
   * Print footer scripts for option pages
   */
  public function print_footer_scripts()
  {
    $screen = get_current_screen();
    
    if (!$screen) {
      return;
    }

    // Find the page data for this screen
    $page = null;
    foreach ($this->page_hooks as $hook => $page_data) {
      if ($screen->id === $hook) {
        $page = $page_data;
        break;
      }
    }

    if (!$page) {
      return;
    }

    // Print option page context script
    OptionPagesScriptLoader::print_option_page_context($page);
  }

  /**
   * Get page hooks array
   *
   * @return array Page hooks
   */
  public function get_page_hooks()
  {
    return $this->page_hooks;
  }

  /**
   * Check if current page is an option page
   *
   * @return bool|array False if not option page, page data if it is
   */
  public function is_option_page()
  {
    $screen = get_current_screen();
    
    if (!$screen) {
      return false;
    }

    foreach ($this->page_hooks as $hook => $page_data) {
      if ($screen->id === $hook) {
        return $page_data;
      }
    }

    return false;
  }

  /**
   * Converts SVG icon name to a data URI for WordPress admin menu
   *
   * @param string $icon_name The icon name (without .svg extension)
   * @return string The icon URL or data URI
   */
  private function get_menu_icon_url($icon_name)
  {
    // If it's already a dashicon, URL, or data URI, return as-is
    if (strpos($icon_name, 'dashicons-') === 0 || strpos($icon_name, 'http') === 0 || strpos($icon_name, 'data:') === 0) {
      return $icon_name;
    }

    // Build path to SVG file
    $plugin_path = defined('UIXPRESS_PLUGIN_PATH') ? UIXPRESS_PLUGIN_PATH : plugin_dir_path(dirname(dirname(dirname(dirname(__FILE__)))));
    $svg_path = $plugin_path . 'assets/icons/' . $icon_name . '.svg';
    
    if (file_exists($svg_path)) {
      $svg_content = file_get_contents($svg_path);
      if ($svg_content) {
        // Encode as base64 data URI
        return 'data:image/svg+xml;base64,' . base64_encode($svg_content);
      }
    }

    // Fallback to dashicons-admin-generic if icon not found
    return 'dashicons-admin-generic';
  }
}
