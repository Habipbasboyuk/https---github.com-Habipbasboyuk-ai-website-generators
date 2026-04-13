<?php

namespace UiXpress\Utility;

// Prevent direct access to this file
defined("ABSPATH") || exit();

class CaptureStyles
{
  /**
   * Captures and filters custom styles from the current WordPress admin page.
   *
   * This function captures registered styles from the current admin page while
   * filtering out WordPress core styles and common admin styles. It only runs
   * on the 'uixpress-posts-data' admin page.
   *
   * @param string $hook The current admin page hook
   * @return void
   */
  public static function get_styles()
  {
    global $wp_styles;

    // Define core handles to filter out
    $core_handles = [
      "common",
      "admin-menu",
      "dashboard",
      "list-tables",
      "edit",
      "revisions",
      "media",
      "themes",
      "about",
      "nav-menus",
      "widgets",
      "site-icon",
      "l10n",
      "wp-admin",
      "login",
      "install",
      "wp-color-picker",
      "customize-controls",
      "customize-widgets",
      "customize-nav-menus",
      "press-this",
      "buttons",
      "dashicons",
      "editor-buttons",
      "media-views",
      "wp-components",
      "wp-block-library",
      "wp-nux",
      "wp-block-editor",
      "wp-edit-post",
      "wp-format-library",
      "colors",
      "uixpress-theme",
    ];

    // Capture and filter styles in one pass
    return array_values(
      array_filter(
        array_map(function ($handle) use ($wp_styles, $core_handles) {
          $style = $wp_styles->registered[$handle];

          // Skip core handles and wp-/admin- prefixed handles
          if (in_array($handle, $core_handles) || str_starts_with($handle, "wp-") || str_starts_with($handle, "admin-")) {
            return null;
          }

          // Get full URL
          $src = $style->src;
          if (!preg_match("|^(https?:)?//|", $src)) {
            $src = $wp_styles->base_url . $src;
          }

          return [
            "handle" => $handle,
            "src" => $src,
            "deps" => $style->deps,
            "version" => $style->ver,
            "media" => $style->args,
            // Capture any inline styles
            "before" => $wp_styles->get_data($handle, "before"),
            "after" => $wp_styles->get_data($handle, "after"),
          ];
        }, $wp_styles->queue),
        function ($style) {
          return $style !== null;
        }
      )
    );
  }
}
