<?php

namespace UiXpress\Options;

use UiXpress\Options\TextReplacement;
use UiXpress\Options\AdminFavicon;
use UiXpress\Options\LoginOptions;
use UiXpress\Options\MediaOptions;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class GlobalOptions
 *
 * Registers global options
 */
class GlobalOptions
{
  /**
   * GlobalOptions constructor.
   */
  public function __construct()
  {
    add_action("admin_init", ["UiXpress\Options\GlobalOptions", "create_global_option"]);
    add_action("rest_api_init", ["UiXpress\Options\GlobalOptions", "create_global_option"]);

    new TextReplacement();
    new AdminFavicon();
    new LoginOptions();
    new MediaOptions();


  }

  /**
   * Creates global option
   *
   * @return Array
   * @since 3.2.13
   */
  public static function create_global_option()
  {
    $args = [
      "type" => "object",
      "sanitize_callback" => ["UiXpress\Options\GlobalOptions", "sanitize_global_settings"],
      "default" => [],
      "capability" => "manage_options",
      "show_in_rest" => [
        "schema" => [
          "type" => "object",
          "properties" => [
            "license_key" => [
              "type" => "string",
            ],
            "instance_id" => [
              "type" => "string",
            ],
            "plugin_name" => [
              "type" => "string",
            ],
            "logo" => [
              "type" => "string",
            ],
            "dark_logo" => [
              "type" => "string",
            ],
            "auto_dark" => [
              "type" => "boolean",
            ],
            "hide_screenoptions" => [
              "type" => "boolean",
            ],
            "hide_help_toggle" => [
              "type" => "boolean",
            ],
            "style_login" => [
              "type" => "boolean",
            ],
            "login_image" => [
              "type" => "string",
            ],
            "disable_theme" => [
              "type" => "array",
              "default" => [],
            ],
            "search_post_types" => [
              "type" => "array",
              "default" => [],
            ],
            "disable_search" => [
              "type" => "boolean",
              "default" => false,
            ],
            "base_theme_color" => [
              "type" => "string",
              "default" => "",
            ],
            "base_theme_scale" => [
              "type" => "array",
              "default" => [],
            ],
            "accent_theme_color" => [
              "type" => "string",
              "default" => "",
            ],
            "accent_theme_scale" => [
              "type" => "array",
              "default" => [],
            ],
            "custom_css" => [
              "type" => "string",
              "default" => "",
            ],
            "login_path" => [
              "type" => "text",
              "default" => "",
            ],
            "text_replacements" => [
              "type" => "array",
              "default" => [],
            ],
            "enable_turnstyle" => [
              "type" => "boolean",
              "default" => false,
            ],
            "turnstyle_site_key" => [
              "type" => "string",
              "default" => "",
            ],
            "turnstyle_secret_key" => [
              "type" => "string",
              "default" => "",
            ],
            "layout" => [
              "type" => "string",
              "default" => "",
            ],
            "admin_favicon" => [
              "type" => "string",
            ],
            "external_stylesheets" => [
              "type" => "array",
              "default" => [],
            ],
            "force_global_theme" => [
              "type" => "string",
              "default" => "off",
            ],
            "submenu_style" => [
              "type" => "string",
              "default" => "click",
            ],
            "enable_admin_menu_search" => [
              "type" => "boolean",
              "default" => false,
            ],
            "hide_language_selector" => [
              "type" => "boolean",
              "default" => false,
            ],
            "use_classic_post_tables" => [
              "type" => "boolean",
              "default" => false,
            ],
            "use_modern_plugin_page" => [
              "type" => "boolean",
              "default" => false,
            ],
            "use_custom_dashboard" => [
              "type" => "boolean",
              "default" => true,
            ],
            "enable_uixpress_analytics" => [
              "type" => "boolean",
              "default" => false,
            ],
            "analytics_provider" => [
              "type" => "string",
              "default" => "uixpress",
            ],
            "google_analytics_service_account" => [
              "type" => "string",
              "default" => "",
            ],
            "google_analytics_property_id" => [
              "type" => "string",
              "default" => "",
            ],
            "use_modern_media_page" => [
              "type" => "boolean",
              "default" => false,
            ],
            "use_modern_users_page" => [
              "type" => "boolean",
              "default" => false,
            ],
            "use_modern_comments_page" => [
              "type" => "boolean",
              "default" => false,
            ],
            "use_modern_post_editor" => [
              "type" => "boolean",
              "default" => false,
            ],
            "modern_post_editor_post_types" => [
              "type" => "array",
              "default" => [],
            ],
            "enable_realtime_collaboration" => [
              "type" => "boolean",
              "default" => false,
            ],
            "enable_svg_uploads" => [
              "type" => "boolean",
              "default" => false,
            ],
            "enable_font_uploads" => [
              "type" => "boolean",
              "default" => true,
            ],
            "enable_database_explorer" => [
              "type" => "boolean",
              "default" => false,
            ],
            "enable_role_editor" => [
              "type" => "boolean",
              "default" => false,
            ],
            "enable_custom_post_types" => [
              "type" => "boolean",
              "default" => false,
            ],
            "enable_activity_logger" => [
              "type" => "boolean",
              "default" => false,
            ],
            "activity_log_retention_days" => [
              "type" => "integer",
              "default" => 90,
            ],
            "activity_log_level" => [
              "type" => "string",
              "default" => "important",
            ],
            "activity_log_auto_cleanup" => [
              "type" => "boolean",
              "default" => true,
            ],
            "magic_dark_mode_pages" => [
              "type" => "array",
              "default" => [],
            ],
            "remote_sites" => [
              "type" => "array",
              "default" => [],
            ],
            "remote_site_switcher_capability" => [
              "type" => "string",
              "default" => "manage_options",
            ],
            "custom_font_source" => [
              "type" => "string",
              "default" => "system",
            ],
            "custom_font_family" => [
              "type" => "string",
              "default" => "",
            ],
            "custom_font_url" => [
              "type" => "string",
              "default" => "",
            ],
            "custom_font_files" => [
              "type" => "array",
              "default" => [],
            ],
          ],
        ],
      ],
    ];
    register_setting("uixpress", "uixpress_settings", $args);
  }

  public static function sanitize_global_settings($value)
  {
    $sanitized_value = [];
    $options = get_option("uixpress_settings", false);
    $options = !$options ? [] : $options;

    if (isset($value["license_key"])) {
      $sanitized_value["license_key"] = sanitize_text_field($value["license_key"]);
    }

    if (isset($value["instance_id"])) {
      $sanitized_value["instance_id"] = sanitize_text_field($value["instance_id"]);
    }

    if (isset($value["plugin_name"])) {
      $sanitized_value["plugin_name"] = sanitize_text_field($value["plugin_name"]);
    }

    if (isset($value["logo"])) {
      $sanitized_value["logo"] = sanitize_text_field($value["logo"]);
    }

    if (isset($value["dark_logo"])) {
      $sanitized_value["dark_logo"] = sanitize_text_field($value["dark_logo"]);
    }

    if (isset($value["login_image"])) {
      $sanitized_value["login_image"] = sanitize_text_field($value["login_image"]);
    }

    if (isset($value["base_theme_color"])) {
      $sanitized_value["base_theme_color"] = sanitize_text_field($value["base_theme_color"]);
    }

    if (isset($value["accent_theme_color"])) {
      $sanitized_value["accent_theme_color"] = sanitize_text_field($value["accent_theme_color"]);
    }

    if (isset($value["login_path"])) {
      $sanitized_value["login_path"] = sanitize_text_field($value["login_path"]);
    }

    if (isset($value["layout"])) {
      $sanitized_value["layout"] = sanitize_text_field($value["layout"]);
    }

    if (isset($value["submenu_style"])) {
      $sanitized_value["submenu_style"] = sanitize_text_field($value["submenu_style"]);
    }

    if (isset($value["enable_admin_menu_search"])) {
      $sanitized_value["enable_admin_menu_search"] = (bool) $value["enable_admin_menu_search"];
    }

    if (isset($value["auto_dark"])) {
      $sanitized_value["auto_dark"] = (bool) $value["auto_dark"];
    }

    if (isset($value["use_classic_post_tables"])) {
      $sanitized_value["use_classic_post_tables"] = (bool) $value["use_classic_post_tables"];
    }

    if (isset($value["use_modern_plugin_page"])) {
      $sanitized_value["use_modern_plugin_page"] = (bool) $value["use_modern_plugin_page"];
    }

    if (isset($value["use_custom_dashboard"])) {
      $sanitized_value["use_custom_dashboard"] = (bool) $value["use_custom_dashboard"];
    }

    if (isset($value["enable_uixpress_analytics"])) {
      $sanitized_value["enable_uixpress_analytics"] = (bool) $value["enable_uixpress_analytics"];
    }

    if (isset($value["analytics_provider"])) {
      $allowed_providers = ["uixpress", "google_analytics"];
      $sanitized_value["analytics_provider"] = in_array($value["analytics_provider"], $allowed_providers, true)
        ? sanitize_text_field($value["analytics_provider"])
        : "uixpress";
    }

    if (isset($value["google_analytics_service_account"])) {
      // Keep encrypted value as-is (already encrypted when stored via REST API)
      $sanitized_value["google_analytics_service_account"] = $value["google_analytics_service_account"];
    }

    if (isset($value["google_analytics_property_id"])) {
      $sanitized_value["google_analytics_property_id"] = sanitize_text_field($value["google_analytics_property_id"]);
    }

    if (isset($value["use_modern_media_page"])) {
      $sanitized_value["use_modern_media_page"] = (bool) $value["use_modern_media_page"];
    }

    if (isset($value["use_modern_users_page"])) {
      $sanitized_value["use_modern_users_page"] = (bool) $value["use_modern_users_page"];
    }

    if (isset($value["use_modern_comments_page"])) {
      $sanitized_value["use_modern_comments_page"] = (bool) $value["use_modern_comments_page"];
    }

    if (isset($value["use_modern_post_editor"])) {
      $sanitized_value["use_modern_post_editor"] = (bool) $value["use_modern_post_editor"];
    }

    if (isset($value["modern_post_editor_post_types"]) && is_array($value["modern_post_editor_post_types"])) {
      $formattedPostTypes = [];
      foreach ($value["modern_post_editor_post_types"] as $postType) {
        if (is_array($postType)) {
          $sanitized_post_type = [
            "slug" => isset($postType["slug"]) ? sanitize_text_field($postType["slug"]) : "",
            "name" => isset($postType["name"]) ? sanitize_text_field($postType["name"]) : "",
            "rest_base" => isset($postType["rest_base"]) ? sanitize_text_field($postType["rest_base"]) : "",
          ];
          $formattedPostTypes[] = $sanitized_post_type;
        }
      }
      $sanitized_value["modern_post_editor_post_types"] = $formattedPostTypes;
    }

    if (isset($value["enable_realtime_collaboration"])) {
      $sanitized_value["enable_realtime_collaboration"] = (bool) $value["enable_realtime_collaboration"];
    }

    if (isset($value["enable_svg_uploads"])) {
      $sanitized_value["enable_svg_uploads"] = (bool) $value["enable_svg_uploads"];
    }

    if (isset($value["enable_font_uploads"])) {
      $sanitized_value["enable_font_uploads"] = (bool) $value["enable_font_uploads"];
    }

    if (isset($value["enable_database_explorer"])) {
      $sanitized_value["enable_database_explorer"] = (bool) $value["enable_database_explorer"];
    }

    if (isset($value["enable_role_editor"])) {
      $sanitized_value["enable_role_editor"] = (bool) $value["enable_role_editor"];
    }

    if (isset($value["enable_custom_post_types"])) {
      $sanitized_value["enable_custom_post_types"] = false; // Disabled by default
    }

    if (isset($value["enable_activity_logger"])) {
      $sanitized_value["enable_activity_logger"] = (bool) $value["enable_activity_logger"];
    }

    if (isset($value["activity_log_retention_days"])) {
      $sanitized_value["activity_log_retention_days"] = absint($value["activity_log_retention_days"]);
    }

    if (isset($value["activity_log_level"])) {
      $allowed_levels = ["all", "important"];
      $sanitized_value["activity_log_level"] = in_array($value["activity_log_level"], $allowed_levels, true) 
        ? sanitize_text_field($value["activity_log_level"]) 
        : "important";
    }

    if (isset($value["activity_log_auto_cleanup"])) {
      $sanitized_value["activity_log_auto_cleanup"] = (bool) $value["activity_log_auto_cleanup"];
    }

    if (isset($value["magic_dark_mode_pages"]) && is_array($value["magic_dark_mode_pages"])) {
      $sanitized_pages = [];
      foreach ($value["magic_dark_mode_pages"] as $page) {
        if (is_string($page)) {
          $sanitized_pages[] = sanitize_text_field($page);
        }
      }
      $sanitized_value["magic_dark_mode_pages"] = $sanitized_pages;
    }

    if (isset($value["hide_language_selector"])) {
      $sanitized_value["hide_language_selector"] = (bool) $value["hide_language_selector"];
    }

    if (isset($value["disable_search"])) {
      $sanitized_value["disable_search"] = (bool) $value["disable_search"];
    }

    if (isset($value["hide_screenoptions"])) {
      $sanitized_value["hide_screenoptions"] = (bool) $value["hide_screenoptions"];
    }

    if (isset($value["hide_help_toggle"])) {
      $sanitized_value["hide_help_toggle"] = (bool) $value["hide_help_toggle"];
    }

    if (isset($value["style_login"])) {
      $sanitized_value["style_login"] = (bool) $value["style_login"];
    }

    if (isset($value["enable_turnstyle"])) {
      $sanitized_value["enable_turnstyle"] = (bool) $value["enable_turnstyle"];
    }

    if (isset($value["turnstyle_site_key"])) {
      $sanitized_value["turnstyle_site_key"] = sanitize_text_field($value["turnstyle_site_key"]);
    }

    if (isset($value["turnstyle_secret_key"])) {
      $sanitized_value["turnstyle_secret_key"] = sanitize_text_field($value["turnstyle_secret_key"]);
    }

    if (isset($value["admin_favicon"])) {
      $sanitized_value["admin_favicon"] = sanitize_text_field($value["admin_favicon"]);
    }

    if (isset($value["disable_theme"]) && is_array($value["disable_theme"])) {
      $formattedMenuLinks = [];
      foreach ($value["disable_theme"] as $link) {
        if (is_array($link)) {
          $sanitized_link = [
            "id" => isset($link["id"]) ? (int) sanitize_text_field($link["id"]) : "",
            "value" => isset($link["value"]) ? sanitize_text_field($link["value"]) : "",
            "type" => isset($link["type"]) ? sanitize_text_field($link["type"]) : "",
          ];
          $formattedMenuLinks[] = $sanitized_link;
        }
      }
      $sanitized_value["disable_theme"] = $formattedMenuLinks;
    }

    if (isset($value["search_post_types"]) && is_array($value["search_post_types"])) {
      $formattedMenuLinks = [];
      foreach ($value["search_post_types"] as $postType) {
        if (is_array($postType)) {
          $sanitized_link = [
            "slug" => isset($postType["slug"]) ? sanitize_text_field($postType["slug"]) : "",
            "name" => isset($postType["name"]) ? sanitize_text_field($postType["name"]) : "",
            "rest_base" => isset($postType["rest_base"]) ? sanitize_text_field($postType["rest_base"]) : "",
          ];
          $formattedMenuLinks[] = $sanitized_link;
        }
      }
      $sanitized_value["search_post_types"] = $formattedMenuLinks;
    }

    if (isset($value["base_theme_scale"]) && is_array($value["base_theme_scale"])) {
      $formattedScale = [];
      foreach ($value["base_theme_scale"] as $color) {
        if (is_array($color)) {
          $sanitized_color = [
            "step" => isset($color["step"]) ? sanitize_text_field($color["step"]) : "",
            "color" => isset($color["color"]) ? sanitize_text_field($color["color"]) : "",
          ];
          $formattedScale[] = $sanitized_color;
        }
      }
      $sanitized_value["base_theme_scale"] = $formattedScale;
    }

    if (isset($value["accent_theme_scale"]) && is_array($value["accent_theme_scale"])) {
      $formattedScale = [];
      foreach ($value["accent_theme_scale"] as $color) {
        if (is_array($color)) {
          $sanitized_color = [
            "step" => isset($color["step"]) ? sanitize_text_field($color["step"]) : "",
            "color" => isset($color["color"]) ? sanitize_text_field($color["color"]) : "",
          ];
          $formattedScale[] = $sanitized_color;
        }
      }
      $sanitized_value["accent_theme_scale"] = $formattedScale;
    }

    if (isset($value["text_replacements"]) && is_array($value["text_replacements"])) {
      $cleanedPairs = [];
      foreach ($value["text_replacements"] as $pair) {
        if (is_array($pair)) {
          $find = isset($pair[0]) && $pair[0] != "" ? sanitize_text_field($pair[0]) : false;
          $replace = isset($pair[1]) && $pair[1] != "" ? sanitize_text_field($pair[1]) : false;

          if ($find && $replace) {
            $cleanedPairs[] = [$find, $replace];
          }
        }
      }
      $sanitized_value["text_replacements"] = $cleanedPairs;
    }

    if (isset($value["custom_css"])) {
      $sanitized_value["custom_css"] = wp_filter_nohtml_kses($value["custom_css"]);
    }

    if (isset($value["force_global_theme"])) {
      $sanitized_value["force_global_theme"] = sanitize_text_field($value["force_global_theme"]);
    }

    if (isset($value["external_stylesheets"]) && is_array($value["external_stylesheets"])) {
      $formattedSheets = [];
      foreach ($value["external_stylesheets"] as $link) {
        $formattedSheets[] = sanitize_url($link);
      }
      $sanitized_value["external_stylesheets"] = $formattedSheets;
    }

    if (isset($value["remote_sites"]) && is_array($value["remote_sites"])) {
      $sanitized_sites = [];
      foreach ($value["remote_sites"] as $site) {
        if (is_array($site) && isset($site["url"])) {
          $sanitized_site = [
            "url" => sanitize_url($site["url"]),
            "username" => isset($site["username"]) ? sanitize_text_field($site["username"]) : "",
            "app_password" => isset($site["app_password"]) ? sanitize_text_field($site["app_password"]) : "",
          ];
          $sanitized_sites[] = $sanitized_site;
        }
      }
      $sanitized_value["remote_sites"] = $sanitized_sites;
    }

    if (isset($value["remote_site_switcher_capability"])) {
      $sanitized_value["remote_site_switcher_capability"] = sanitize_text_field($value["remote_site_switcher_capability"]);
    }

    // Custom font settings
    if (isset($value["custom_font_source"])) {
      $allowed_sources = ["system", "google", "url", "upload"];
      $sanitized_value["custom_font_source"] = in_array($value["custom_font_source"], $allowed_sources, true)
        ? sanitize_text_field($value["custom_font_source"])
        : "system";
    }

    if (isset($value["custom_font_family"])) {
      $sanitized_value["custom_font_family"] = sanitize_text_field($value["custom_font_family"]);
    }

    if (isset($value["custom_font_url"])) {
      $sanitized_value["custom_font_url"] = sanitize_url($value["custom_font_url"]);
    }

    if (isset($value["custom_font_files"]) && is_array($value["custom_font_files"])) {
      $sanitized_files = [];
      foreach ($value["custom_font_files"] as $file) {
        if (is_array($file)) {
          $sanitized_file = [
            "url" => isset($file["url"]) ? sanitize_url($file["url"]) : "",
            "weight" => isset($file["weight"]) ? sanitize_text_field($file["weight"]) : "400",
            "style" => isset($file["style"]) ? sanitize_text_field($file["style"]) : "normal",
          ];
          if (!empty($sanitized_file["url"])) {
            $sanitized_files[] = $sanitized_file;
          }
        }
      }
      $sanitized_value["custom_font_files"] = $sanitized_files;
    }

    return array_merge($options, $sanitized_value);
  }
}
