<?php
namespace UiXpress\Pages;
use UiXpress\Options\GlobalOptions;
use UiXpress\Update\Updater;
use UiXpress\Rest\RestLogout;
use UiXpress\Rest\MenuCache;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class MenuBuilder
 *
 * Main class for initialising the uixpress app.
 */
class MenuBuilder
{
  /**
   * uixpress constructor.
   *
   * Initialises the main app.
   */
  public function __construct()
  {
    add_action("admin_menu", ["UiXpress\Pages\MenuBuilder", "admin_settings_page"]);
    add_action("init", ["UiXpress\Pages\MenuBuilder", "create_post_type"]);
    add_action("init", ["UiXpress\Pages\MenuBuilder", "register_meta"]);
    add_filter("rest_uipx-menu_query", ["UiXpress\Pages\MenuBuilder", "rest_permission_callback"], 10, 2);
    new MenuCache();
  }

  /**
   * Custom permission callback for REST API
   *
   * @param WP_REST_Request $request Full details about the request.
   * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
   */
  public static function rest_permission_callback($args, $request)
  {
    if (!is_user_logged_in()) {
      return new \WP_REST_Response("You must be logged in to access this endpoint.", 401);
    }

    // For GET requests (reading), allow any logged-in user
    if ($request->get_method() === "GET") {
      return $args;
    }

    // For other methods (creating, updating, deleting), require manage_options capability
    if (!current_user_can("manage_options")) {
      return new \WP_REST_Response("You do not have permission to edit this resource.", 401);
    }

    return $args;
  }

  /**
   * Adds settings page.
   *
   * Calls add_menu_page to add new page .
   */
  public static function admin_settings_page()
  {
    $menu_name = __("Menu Creator", "uixpress");
    $hook_suffix = add_submenu_page('uipx-settings', $menu_name, $menu_name, "manage_options", "uipx-menucreator", ["UiXpress\Pages\MenuBuilder", "build_uipc_menu_creator"]);

    add_action("admin_head-{$hook_suffix}", ["UiXpress\Pages\MenuBuilder", "load_styles"]);
    add_action("admin_head-{$hook_suffix}", ["UiXpress\Pages\MenuBuilder", "load_scripts"]);
  }

  /**
   * Registers meta fields
   *
   * @return Array
   * @since 4.0.0
   */
  public static function register_meta()
  {
    foreach (self::return_meta_types() as $type) {
      register_meta("post", $type["name"], [
        "object_subtype" => "uipx-menu",
        "single" => true,
        "default" => $type["default"],
        "show_in_rest" => [
          "schema" => [
            "type" => $type["type"],
            "default" => $type["default"],
            "context" => ["edit", "view", "embed"],
            "properties" => isset($type["properties"]) ? $type["properties"] : null,
          ],
        ],
        "auth_callback" => function () {
          return is_user_logged_in();
        },
        "sanitize_callback" => $type["sanitize"],
      ]);
    }
  }

  /**
   * Attempts to sanitize fields
   *
   * @return Array
   * @since 4.0.0
   */
  public static function sanitize_fields($meta_value, $meta_key, $object_type, $object_subtype)
  {
    // Setup default value
    $sanitized_value = [];

    if (is_array($meta_value)) {
      foreach ($meta_value as $link) {
        $sanitized_value[] = self::sanitize_menu_item($link);
      }
    }
    return $sanitized_value;
  }

  /**
   * Sanitises menu links
   *
   * @return Array
   * @since 4.0.0
   */
  private static function sanitize_menu_item($link)
  {
    $sanitized_link = [
      "id" => isset($link["id"]) ? sanitize_text_field($link["id"]) : "",
      "custom" => isset($link["custom"]) ? (bool) sanitize_text_field($link["custom"]) : false,
      "name" => isset($link["name"]) ? sanitize_text_field($link["name"]) : "",
      "url" => isset($link["url"]) ? sanitize_text_field($link["url"]) : "",
      "imageClasses" => isset($link["imageClasses"]) ? self::sanitize_array($link["imageClasses"]) : [],
      "iconStyles" => isset($link["iconStyles"]) ? sanitize_text_field($link["iconStyles"]) : "",
      "target" => isset($link["target"]) ? sanitize_text_field($link["target"]) : "",
      "type" => isset($link["type"]) ? sanitize_text_field($link["type"]) : "",
    ];

    if (isset($link["settings"])) {
      $sanitized_settings = [
        "name" => isset($link["settings"]["name"]) ? sanitize_text_field($link["settings"]["name"]) : "",
        "icon" => isset($link["settings"]["icon"]) ? sanitize_text_field($link["settings"]["icon"]) : "",
        "open_new" => isset($link["settings"]["open_new"]) ? (bool) sanitize_text_field($link["settings"]["open_new"]) : false,
        "hidden" => isset($link["settings"]["hidden"]) ? (bool) sanitize_text_field($link["settings"]["hidden"]) : false,
      ];

      $sanitized_link["settings"] = $sanitized_settings;
    }

    // Sanitize submenu
    if (isset($link["submenu"]) && is_array($link["submenu"])) {
      $subLinks = [];
      foreach ($link["submenu"] as $sublink) {
        $subLinks[] = self::sanitize_menu_item($sublink);
      }
      $sanitized_link["submenu"] = $subLinks;
    }

    return $sanitized_link;
  }

  /**
   * Sanitises menu links
   *
   * @return Array
   * @since 4.0.0
   */
  private static function sanitize_array($items)
  {
    $sanitized_value = [];

    if (!is_array($items)) {
      return [];
    }

    foreach ($items as $item) {
      $sanitized_value[] = sanitize_text_field($item);
    }

    return $sanitized_value;
  }

  /**
   * Attempts to sanitize fields
   *
   * @return Array
   * @since 4.0.0
   */
  public static function sanitize_settings($meta_value, $meta_key, $object_type, $object_subtype)
  {
    // Setup default value
    $sanitized_value = [];

    if (isset($meta_value["applies_to_everyone"])) {
      $sanitized_value["applies_to_everyone"] = (bool) $meta_value["applies_to_everyone"];
    }

    if (isset($meta_value["includes"]) && is_array($meta_value["includes"])) {
      $formatted_types = [];
      foreach ($meta_value["includes"] as $link) {
        if (is_array($link)) {
          $sanitized_link = [
            "id" => isset($link["id"]) ? (int) sanitize_text_field($link["id"]) : "",
            "value" => isset($link["value"]) ? sanitize_text_field($link["value"]) : "",
            "type" => isset($link["type"]) ? sanitize_text_field($link["type"]) : "",
          ];
          $formatted_types[] = $sanitized_link;
        }
      }
      $sanitized_value["includes"] = $formatted_types;
    }

    if (isset($meta_value["excludes"]) && is_array($meta_value["excludes"])) {
      $formatted_types = [];
      foreach ($meta_value["excludes"] as $link) {
        if (is_array($link)) {
          $sanitized_link = [
            "id" => isset($link["id"]) ? (int) sanitize_text_field($link["id"]) : "",
            "value" => isset($link["value"]) ? sanitize_text_field($link["value"]) : "",
            "type" => isset($link["type"]) ? sanitize_text_field($link["type"]) : "",
          ];
          $formatted_types[] = $sanitized_link;
        }
      }
      $sanitized_value["excludes"] = $formatted_types;
    }

    return $sanitized_value;
  }

  /**
   * Registers meta fields
   *
   * @return Array
   * @since 4.0.0
   */
  private static function return_meta_types()
  {
    return [
      [
        "name" => "menu_settings",
        "type" => "object",
        "default" => new \stdClass(),
        "sanitize" => ["UiXpress\Pages\MenuBuilder", "sanitize_settings"],
        "properties" => [
          "applies_to_everyone" => [
            "type" => "boolean",
            "default" => false,
          ],
          "includes" => [
            "type" => "array",
            "default" => [],
          ],
          "excludes" => [
            "type" => "array",
            "default" => [],
          ],
        ],
      ],
      [
        "name" => "menu_items",
        "type" => "array",
        "default" => [],
        "sanitize" => ["UiXpress\Pages\MenuBuilder", "sanitize_fields"],
      ],
    ];
  }

  /**
   * Returns post type args for uixmenus
   *
   * @return Array
   * @since 3.2.13
   */
  public static function create_post_type()
  {
    // Hook into admin menu to add the UI Builder page
    $postTypeArgs = self::return_post_type_args();
    register_post_type("uipx-menu", $postTypeArgs);
  }

  /**
   * Returns post type args for uixmenus
   *
   * @return Array
   * @since 3.2.13
   */
  private static function return_post_type_args()
  {
    $labels = [
      "name" => _x("Admin Menu", "post type general name", "uixpress"),
      "singular_name" => _x("Admin Menu", "post type singular name", "uixpress"),
      "menu_name" => _x("Admin Menus", "admin menu", "uixpress"),
      "name_admin_bar" => _x("Admin Menu", "add new on admin bar", "uixpress"),
      "add_new" => _x("Add New", "Template", "uixpress"),
      "add_new_item" => __("Add New Admin Menu", "uixpress"),
      "new_item" => __("New Admin Menu", "uixpress"),
      "edit_item" => __("Edit Admin Menu", "uixpress"),
      "view_item" => __("View Admin Menu", "uixpress"),
      "all_items" => __("All Admin Menus", "uixpress"),
      "search_items" => __("Search Admin Menus", "uixpress"),
      "not_found" => __("No Admin Menus found.", "uixpress"),
      "not_found_in_trash" => __("No Admin Menus found in Trash.", "uixpress"),
    ];
    $args = [
      "labels" => $labels,
      "description" => __("Post type used for the uixpresss menus", "uixpress"),
      "public" => false,
      "publicly_queryable" => false,
      "show_ui" => false,
      "show_in_menu" => false,
      "query_var" => false,
      "has_archive" => false,
      "hierarchical" => false,
      "supports" => ["title", "custom-fields"],
      "show_in_rest" => true,
      "rest_base" => "uipxmenus",
    ];

    return $args;
  }

  /**
   * uixpress settings page.
   *
   * Outputs the app holder
   */
  public static function build_uipc_menu_creator()
  {
    // Output the app
    echo "<div id='uipc-menu-creator-app'></div>";
  }

  /**
   * uixpress styles.
   *
   * Loads main lp styles
   */
  public static function load_styles()
  {
    // Get plugin url
    $url = plugins_url("uixpress/");
    $style = $url . "app/dist/assets/styles/menu-creator.css";
    wp_enqueue_style("uixpress-menu-creator", $style, [], uixpress_plugin_version);

    add_filter('uixpress/style-layering/exclude', function($excluded_patterns) use ($style) {
      $excluded_patterns[] = $style;
      return $excluded_patterns;
    });
  }

  /**
   * uixpress scripts.
   *
   * Loads main lp scripts
   */
  public static function load_scripts()
  {
    // Get plugin url
    $url = plugins_url("uixpress/");
    $script_name = Scripts::get_base_script_path("MenuCreator.js");

    // Setup script object
    $builderScript = [
      "id" => "uipc-menu-creator-script",
      "src" => $url . "app/dist/{$script_name}",
      "type" => "module",
    ];

    // Print tag
    wp_print_script_tag($builderScript);
  }
}
