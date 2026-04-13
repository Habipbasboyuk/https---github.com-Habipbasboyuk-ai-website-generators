<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class OptionPagesRepository
 *
 * Handles JSON file operations and CRUD operations for option pages
 */
class OptionPagesRepository
{
  /**
   * Path to the JSON storage file
   *
   * @var string
   */
  private $json_file_path;

  /**
   * Initialize the repository
   *
   * @param string $json_file_path Path to the JSON file
   */
  public function __construct($json_file_path = null)
  {
    $this->json_file_path = $json_file_path ?? WP_CONTENT_DIR . '/uixpress-options-pages.json';
  }

  /**
   * Read option pages from JSON file
   *
   * @return array Array of option pages
   */
  public function read()
  {
    if (!file_exists($this->json_file_path)) {
      return [];
    }

    $json_content = file_get_contents($this->json_file_path);
    if ($json_content === false) {
      return [];
    }

    $data = json_decode($json_content, true);
    return is_array($data) ? $data : [];
  }

  /**
   * Write option pages to JSON file
   *
   * @param array $option_pages Array of option pages
   * @return bool True on success, false on failure
   */
  public function write($option_pages)
  {
    $json_content = wp_json_encode($option_pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // Ensure directory exists
    $dir = dirname($this->json_file_path);
    if (!file_exists($dir)) {
      wp_mkdir_p($dir);
    }

    $result = file_put_contents($this->json_file_path, $json_content);
    
    // Clear any caches
    if (function_exists('wp_cache_flush')) {
      wp_cache_flush();
    }
    
    // Fire action when option pages are saved
    if ($result !== false) {
      /**
       * Fires after option pages are saved
       *
       * @since 1.4.0
       * @param array $option_pages The saved option pages
       */
      do_action('uixpress_option_pages_saved', $option_pages);
    }
    
    return $result !== false;
  }

  /**
   * Generate unique slug from title
   *
   * @param string $title The title to generate slug from
   * @param array $existing_pages Existing pages to check against
   * @return string Unique slug
   */
  public function generate_slug($title, $existing_pages = null)
  {
    if ($existing_pages === null) {
      $existing_pages = $this->read();
    }

    // Convert title to slug
    $slug = sanitize_title($title);
    
    // If empty, generate random slug
    if (empty($slug)) {
      $slug = 'option_page_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
    }

    // Make unique if exists
    $original_slug = $slug;
    $counter = 1;
    while ($this->slug_exists($slug, $existing_pages)) {
      $slug = $original_slug . '_' . $counter;
      $counter++;
    }

    return $slug;
  }

  /**
   * Check if slug exists in option pages array
   *
   * @param string $slug The slug to check
   * @param array $option_pages Array of option pages
   * @return bool True if exists, false otherwise
   */
  public function slug_exists($slug, $option_pages = null)
  {
    if ($option_pages === null) {
      $option_pages = $this->read();
    }

    foreach ($option_pages as $page) {
      if (isset($page['slug']) && $page['slug'] === $slug) {
        return true;
      }
    }
    return false;
  }

  /**
   * Find option page by slug
   *
   * @param string $slug Option page slug
   * @return array|null Option page or null if not found
   */
  public function find_by_slug($slug)
  {
    $option_pages = $this->read();
    
    foreach ($option_pages as $page) {
      if (isset($page['slug']) && $page['slug'] === $slug) {
        return $page;
      }
    }
    
    return null;
  }

  /**
   * Find option page index by slug
   *
   * @param string $slug Option page slug
   * @return int Index or -1 if not found
   */
  public function find_index_by_slug($slug)
  {
    $option_pages = $this->read();
    
    foreach ($option_pages as $index => $page) {
      if (isset($page['slug']) && $page['slug'] === $slug) {
        return $index;
      }
    }
    
    return -1;
  }

  /**
   * Check if we have active option pages
   *
   * @return bool True if active pages exist
   */
  public function has_active_pages()
  {
    $option_pages = $this->read();
    
    if (empty($option_pages)) {
      return false;
    }

    foreach ($option_pages as $page) {
      if (!empty($page['active'])) {
        return true;
      }
    }

    return false;
  }

  /**
   * Get all active option pages
   *
   * @return array Array of active option pages
   */
  public function get_active_pages()
  {
    $option_pages = $this->read();
    $active = [];
    
    foreach ($option_pages as $page) {
      if (!empty($page['active'])) {
        $active[] = $page;
      }
    }
    
    return $active;
  }

  /**
   * Get default option page structure
   *
   * @return array Default option page data
   */
  public static function get_defaults()
  {
    return [
      'slug' => '',
      'title' => '',
      'description' => '',
      'menu_type' => 'submenu', // 'top_level' or 'submenu'
      'parent_menu' => 'options-general.php', // Parent menu for submenu
      'menu_icon' => 'settings', // Dashicon for top-level
      'menu_position' => 100,
      'capability' => 'manage_options',
      'active' => true,
      'created_at' => '',
      'updated_at' => '',
    ];
  }

  /**
   * Get available parent menus for submenu pages
   *
   * @return array Parent menu options
   */
  public static function get_parent_menus()
  {
    return [
      ['value' => 'options-general.php', 'label' => __('Settings', 'uixpress')],
      ['value' => 'tools.php', 'label' => __('Tools', 'uixpress')],
      ['value' => 'themes.php', 'label' => __('Appearance', 'uixpress')],
      ['value' => 'plugins.php', 'label' => __('Plugins', 'uixpress')],
      ['value' => 'users.php', 'label' => __('Users', 'uixpress')],
      ['value' => 'upload.php', 'label' => __('Media', 'uixpress')],
      ['value' => 'edit-comments.php', 'label' => __('Comments', 'uixpress')],
      ['value' => 'edit.php', 'label' => __('Posts', 'uixpress')],
      ['value' => 'edit.php?post_type=page', 'label' => __('Pages', 'uixpress')],
      ['value' => 'index.php', 'label' => __('Dashboard', 'uixpress')],
    ];
  }

  /**
   * Get available capabilities
   *
   * @return array Capability options
   */
  public static function get_capabilities()
  {
    return [
      ['value' => 'manage_options', 'label' => __('Administrator (manage_options)', 'uixpress')],
      ['value' => 'edit_others_posts', 'label' => __('Editor (edit_others_posts)', 'uixpress')],
      ['value' => 'publish_posts', 'label' => __('Author (publish_posts)', 'uixpress')],
      ['value' => 'edit_posts', 'label' => __('Contributor (edit_posts)', 'uixpress')],
      ['value' => 'read', 'label' => __('Subscriber (read)', 'uixpress')],
    ];
  }
}
