<?php

namespace UiXpress\Options;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class Settings
 *
 * Provides centralized access to uiXpress settings.
 * This class handles retrieval and caching of the uixpress_settings option.
 *
 * IMPORTANT: This class uses static properties and methods, meaning the cache
 * is shared across the entire PHP request. All classes using Settings::get()
 * will share the same cached data, regardless of how many instances are created.
 * The cache persists for the duration of the request and is not affected by
 * creating new instances of other classes.
 *
 * @package UiXpress\Options
 * @since 1.2.12
 */
class Settings
{
  /**
   * Stores the cached settings.
   *
   * This is a static property, so it's shared across all instances and classes
   * that use this Settings class. The cache persists for the entire PHP request.
   *
   * @var array|null
   */
  private static $settings = null;

  /**
   * Retrieves all uiXpress settings.
   *
   * Returns the cached settings if available, otherwise fetches from the database.
   * Settings are cached for the duration of the request to avoid multiple database calls.
   *
   * @param bool $force_refresh Whether to force a refresh of the cached settings
   * @return array Array of uiXpress settings, or empty array if not set
   * @since 1.2.12
   */
  public static function get($force_refresh = false)
  {
    if ($force_refresh || is_null(self::$settings)) {
      self::$settings = get_option("uixpress_settings", []);
      
      // Ensure we always return an array
      if (!is_array(self::$settings)) {
        self::$settings = [];
      }
    }

    return self::$settings;
  }

  /**
   * Retrieves a specific setting value.
   *
   * @param string $key The setting key to retrieve
   * @param mixed $default The default value to return if the setting is not found
   * @return mixed The setting value or default if not found
   * @since 1.2.12
   */
  public static function get_setting($key, $default = null)
  {
    $settings = self::get();
    
    return isset($settings[$key]) ? $settings[$key] : $default;
  }

  /**
   * Checks if a boolean setting is enabled.
   *
   * @param string $key The setting key to check
   * @return bool True if the setting exists and is true, false otherwise
   * @since 1.2.12
   */
  public static function is_enabled($key)
  {
    $settings = self::get();
    
    return (
      is_array($settings) &&
      isset($settings[$key]) &&
      $settings[$key] === true
    );
  }

  /**
   * Clears the cached settings.
   *
   * Useful when settings are updated and you need to force a refresh.
   *
   * @return void
   * @since 1.2.12
   */
  public static function clear_cache()
  {
    self::$settings = null;
  }
}

