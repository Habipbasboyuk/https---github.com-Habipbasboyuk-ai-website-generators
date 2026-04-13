<?php
namespace UiXpress\Options;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class AdminFavicon
 *
 * Handles custom favicon functionality in the WordPress admin area.
 *
 * @package UiXpress\Options
 */
class AdminFavicon
{
  /**
   * Stores the global options.
   *
   * @var array|null
   */
  private static $options = null;

  /**
   * Stores the custom favicon URL.
   *
   * @var string|null
   */
  private static $favicon_url = null;

  /**
   * AdminFavicon constructor.
   *
   * Initializes the class and adds actions for favicon replacement.
   */
  public function __construct()
  {
    add_action("admin_head", [$this, "replace_favicon"], 1);
    add_action("init", [$this, "remove_default_favicon"]);
  }

  /**
   * Retrieves the custom favicon URL.
   *
   * @return string|false The custom favicon URL, or false if not set.
   */
  private static function get_favicon_url()
  {
    if (!is_null(self::$favicon_url)) {
      return self::$favicon_url;
    }

    $admin_favicon = Settings::get_setting("admin_favicon", "");
    
    if ($admin_favicon != "") {
      self::$favicon_url = esc_url($admin_favicon);
      return self::$favicon_url;
    }

    return false;
  }

  /**
   * Replaces the default WordPress favicon in the admin area.
   */
  public function replace_favicon()
  {
    $favicon_url = self::get_favicon_url();

    if (!$favicon_url) {
      return;
    }

    echo '<link rel="shortcut icon" id="uipx-favicon" href="' . esc_url($favicon_url) . '" />';
  }

  /**
   * Removes the default WordPress favicon action.
   */
  public function remove_default_favicon()
  {
    remove_action("admin_head", "wp_favicon");
  }
}
