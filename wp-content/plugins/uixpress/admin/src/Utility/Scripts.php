<?php
namespace UiXpress\Utility;
/**
 * Class Scripts
 *
 * Main class for initialising the uixpress app.
 */
class Scripts
{
  /**
   * Get the path of the Vite-built base script.
   *
   * This function reads the Vite manifest file and finds the compiled filename
   * for the 'uixpress.js' entry point. It uses WordPress file reading functions
   * for better compatibility and security.
   *
   * @return string|null The filename of the compiled base script, or null if not found.
   */
  public static function get_base_script_path($filename)
  {
    $manifest_path = uixpress_plugin_path . "app/dist/.vite/manifest.json";

    if (!file_exists($manifest_path)) {
      return null;
    }

    $manifest_content = file_get_contents($manifest_path);
    if ($manifest_content === false) {
      return null;
    }

    $manifest = json_decode($manifest_content, true);
    if (!is_array($manifest)) {
      return null;
    }

    foreach ($manifest as $key => $value) {
      if (isset($value["src"]) && $value["src"] === "apps/js/{$filename}") {
        return $value["file"];
      }
    }

    return null;
  }

  /**
   * Get the path of a Vite-built stylesheet.
   *
   * This function reads the Vite manifest file and finds the compiled filename
   * for a stylesheet. It can accept either a JavaScript entry point (e.g., 'uixpress.js')
   * or a CSS filename (e.g., 'app.css'). If a JS entry is provided, it returns the
   * CSS file associated with that entry.
   *
   * @param string $filename The JavaScript entry filename (e.g., 'uixpress.js') or stylesheet filename (e.g., 'app.css').
   * @return string|null The filename of the compiled stylesheet, or null if not found.
   */
  public static function get_stylesheet_path($filename)
  {
    $manifest_path = uixpress_plugin_path . "app/dist/.vite/manifest.json";

    if (!file_exists($manifest_path)) {
      return null;
    }

    $manifest_content = file_get_contents($manifest_path);
    if ($manifest_content === false) {
      return null;
    }

    $manifest = json_decode($manifest_content, true);
    if (!is_array($manifest)) {
      return null;
    }

    // Check if the filename is a JavaScript entry point
    if (preg_match('/\.js$/', $filename)) {
      foreach ($manifest as $key => $value) {
        // Check if this entry matches the JS filename
        if (isset($value["src"]) && $value["src"] === "apps/js/{$filename}") {
          // Return the first CSS file associated with this entry
          if (isset($value["css"]) && is_array($value["css"]) && !empty($value["css"])) {
            return $value["css"][0];
          }
        }
      }
      return null;
    }

    // Otherwise, search for CSS files by filename
    // Normalize the filename to search for (remove leading path if present)
    $search_filename = basename($filename);
    $search_path = str_replace('assets/styles/', '', $filename);

    foreach ($manifest as $key => $value) {
      // Check if this entry has CSS files
      if (isset($value["css"]) && is_array($value["css"])) {
        foreach ($value["css"] as $css_file) {
          // Check if the CSS file matches our search pattern
          if (
            strpos($css_file, $search_filename) !== false ||
            strpos($css_file, $search_path) !== false
          ) {
            return $css_file;
          }
        }
      }

      // Also check direct CSS entries in the manifest
      if (isset($value["file"]) && preg_match('/\.css$/', $value["file"])) {
        if (
          strpos($value["file"], $search_filename) !== false ||
          strpos($value["file"], $search_path) !== false
        ) {
          return $value["file"];
        }
      }

      // Check if src matches a CSS file pattern
      if (isset($value["src"]) && preg_match('/\.css$/', $value["src"])) {
        if (
          strpos($value["src"], $search_filename) !== false ||
          strpos($value["src"], $search_path) !== false
        ) {
          return isset($value["file"]) ? $value["file"] : $value["src"];
        }
      }
    }

    return null;
  }
}
