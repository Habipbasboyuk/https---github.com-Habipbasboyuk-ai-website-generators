<?php
namespace UiXpress\Options;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class TextReplacement
 *
 * Handles text replacement functionality in the WordPress admin area.
 *
 * @package UiXpress\Options
 */
class TextReplacement
{
  /**
   * Stores the global options.
   *
   * @var array|null
   */
  private static $options = null;

  /**
   * Stores the text replacement pairs.
   *
   * @var array|null
   */
  private static $pairs = null;

  /**
   * TextReplacement constructor.
   *
   * Initializes the class and adds filters for text replacement.
   */
  public function __construct()
  {
    add_filter("gettext", [$this, "custom_replace_admin_text"], 20);
    add_filter("ngettext", [$this, "custom_replace_admin_text"], 20);
  }

  /**
   * Retrieves and processes the text replacement pairs.
   *
   * @return array|false The processed text replacement pairs, or false if no valid pairs are found.
   */
  private static function replacement_pairs()
  {
    if (is_array(self::$pairs)) {
      return self::$pairs;
    }

    $text_replacements = Settings::get_setting("text_replacements", []);
    
    if (is_array($text_replacements) && !empty($text_replacements)) {
      $cleanedPairs = [];
      foreach ($text_replacements as $pair) {
        if (is_array($pair)) {
          $find = isset($pair[0]) && $pair[0] != "" ? esc_html($pair[0]) : false;
          $replace = isset($pair[1]) && $pair[1] != "" ? esc_html($pair[1]) : false;
          if ($find && $replace) {
            $cleanedPairs[$find] = $replace;
          }
        }
      }
      self::$pairs = $cleanedPairs;
      return $cleanedPairs;
    }

    return false;
  }

  /**
   * Performs the text replacement.
   *
   * @param string $text The original text to be processed.
   * @return string The processed text with replacements applied.
   */
  public static function custom_replace_admin_text($text)
  {
    $pairs = self::replacement_pairs();
    if (!$pairs) {
      return $text;
    }
    return str_replace(array_keys($pairs), array_values($pairs), $text);
  }
}
