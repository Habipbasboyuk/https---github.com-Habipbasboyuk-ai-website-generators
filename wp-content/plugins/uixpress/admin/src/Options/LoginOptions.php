<?php
namespace UiXpress\Options;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class LoginOptions
 *
 * Handles text replacement functionality in the WordPress admin area.
 *
 * @package UiXpress\Options
 */
class LoginOptions
{
  /**
   * Stores the global options.
   *
   * @var array|null
   */
  private static $options = null;

  /**
   * TextReplacement constructor.
   *
   * Initializes the class and adds filters for text replacement.
   */
  public function __construct()
  {
    add_filter("login_display_language_dropdown", [$this, "maybe_remove_language_switcher"], 20);
  }

  /**
   * Checks if language selector should be hidden on login page.
   *
   * @return bool False if language selector should be hidden, true otherwise.
   */
  public static function maybe_remove_language_switcher()
  {
    if (Settings::is_enabled("hide_language_selector")) {
      return false;
    }

    return true;
  }
}
