<?php

namespace UiXpress\Pages;

use UiXpress\Options\Settings;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomPostEditor
 *
 * Handles the replacement of the default WordPress post editor with a custom implementation
 */
class CustomPostEditor
{
  /** @var array */
  private static $options;

  /**
   * CustomPostEditor constructor.
   *
   * Sets up the necessary hooks for the post editor page
   */
  public function __construct()
  {
    add_action("load-post.php", [$this, "init_post_editor"]);
    add_action("load-post-new.php", [$this, "init_post_editor"]);
  }

  /**
   * Initializes the custom post editor implementation
   *
   * @since 1.0.0
   * @return void
   */
  public function init_post_editor()
  {
    // Check user capability
    if (!current_user_can("edit_posts")) {
      return;
    }

    // Modern post editor is not enabled
    if (!Settings::is_enabled("use_modern_post_editor")) {
      return;
    }

    $screen = get_current_screen();

    // Check if we're on a post edit page
    if ($screen->base !== "post" && $screen->base !== "post-new") {
      return;
    }

    // Get current post type
    $post_type = $screen->post_type;

    // Get allowed post types from settings
    $allowed_post_types = Settings::get_setting("modern_post_editor_post_types", []);
    
    // If no post types are selected, default to 'post'
    if (empty($allowed_post_types)) {
      $allowed_post_types = [["slug" => "post"]];
    }

    // Extract slugs from allowed post types array
    $allowed_slugs = array_map(function($pt) {
      return isset($pt["slug"]) ? $pt["slug"] : "";
    }, $allowed_post_types);

    // Check if current post type is in allowed list
    if (!in_array($post_type, $allowed_slugs, true)) {
      return;
    }

    // Suppress PHP warnings on this page (workaround for WordPress core warnings)
    $this->suppress_warnings();
    
    $this->prevent_default_loading();
    $this->setup_output_capture();
    add_action("admin_enqueue_scripts", [$this, "load_styles_and_scripts"]);
  }

  /**
   * Loads post editor styles and scripts
   *
   * @since 1.0.0
   * @return void
   */
  public static function load_styles_and_scripts()
  {
    $url = plugins_url("uixpress/");

    // Get plugin url
    $style = $url . "app/dist/assets/styles/post-editor.css";
    wp_enqueue_style("uixpress-post-editor", $style, [], uixpress_plugin_version);

    $script_name = Scripts::get_base_script_path("PostEditor.js");
    
    // Get current post ID and post type
    $post_id = isset($_GET["post"]) ? absint($_GET["post"]) : 0;
    $post_type = isset($_GET["post_type"]) ? sanitize_text_field($_GET["post_type"]) : "post";
    
    // Get post type object for additional data
    $post_type_obj = get_post_type_object($post_type);
    
    wp_print_script_tag([
      "id" => "uipc-post-editor-script",
      "src" => $url . "app/dist/{$script_name}",
      "type" => "module",
      "data-post-id" => esc_attr($post_id),
      "data-post-type" => esc_attr($post_type),
      "data-post-type-name" => esc_attr($post_type_obj ? $post_type_obj->labels->singular_name : ""),
    ]);
  }

  /**
   * Suppresses PHP warnings on the post editor page
   *
   * @since 1.0.0
   * @return void
   */
  private function suppress_warnings()
  {
    // Suppress warnings and notices, but keep errors
    // This is a workaround for WordPress core warnings in edit-form-blocks.php
    // Set error reporting to only show errors, not warnings
    error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR);
    
    // Also suppress warnings via error handler for this page only
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
      // Suppress warnings and notices, but allow errors through
      if ($errno === E_WARNING || $errno === E_NOTICE || $errno === E_USER_WARNING || $errno === E_USER_NOTICE) {
        return true; // Suppress the error
      }
      return false; // Let other errors through
    }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
  }

  /**
   * Prevents WordPress from loading default post editor components
   *
   * @since 1.0.0
   * @return void
   */
  private function prevent_default_loading()
  {
    // Remove default editor scripts and styles
    remove_action("admin_enqueue_scripts", "wp_enqueue_media");
    
    // Prevent default editor initialization
    //add_filter("replace_editor", [$this, "replace_editor"], 10, 2);
    
    // Remove is-fullscreen-mode class from admin body
    add_filter("admin_body_class", [$this, "remove_fullscreen_mode_class"], 999);
    
    // Remove editor meta boxes
    add_action("add_meta_boxes", [$this, "remove_editor_meta_boxes"], 999);
    
    // Prevent editor scripts from loading
    add_action("admin_enqueue_scripts", [$this, "remove_editor_scripts"], 999);
  }

  /**
   * Removes the is-fullscreen-mode class from admin body
   *
   * @param string $classes Space-separated list of CSS classes
   * @return string Modified classes
   * @since 1.0.0
   */
  public function remove_fullscreen_mode_class($classes)
  {
    // Remove is-fullscreen-mode class
    $classes = str_replace("is-fullscreen-mode", "", $classes);
    // Clean up any double spaces
    $classes = preg_replace("/\s+/", " ", $classes);
    return trim($classes);
  }

  /**
   * Replaces the default editor with custom implementation
   *
   * @param bool $replace Whether to replace the editor
   * @param WP_Post $post The post object
   * @return bool
   * @since 1.0.0
   */
  public function replace_editor($replace, $post)
  {
    return true;
  }

  /**
   * Removes editor meta boxes
   *
   * @since 1.0.0
   * @return void
   */
  public function remove_editor_meta_boxes()
  {
    global $wp_meta_boxes;
    
    // Get current post type
    $screen = get_current_screen();
    $post_type = $screen->post_type;
    
    // Remove all meta boxes for this post type
    if (isset($wp_meta_boxes[$screen->id])) {
      unset($wp_meta_boxes[$screen->id]);
    }
  }

  /**
   * Removes editor-related scripts
   *
   * @since 1.0.0
   * @return void
   */
  public function remove_editor_scripts()
  {
    // Remove WordPress editor scripts
    wp_dequeue_script("editor");
    wp_deregister_script("editor");
    wp_dequeue_script("word-count");
    wp_deregister_script("word-count");
    wp_dequeue_script("post");
    wp_deregister_script("post");
  }

  /**
   * Sets up output buffering and custom content display
   *
   * @since 1.0.0
   * @return void
   */
  private function setup_output_capture()
  {
    // Start output buffering
    add_action("in_admin_header", [$this, "start_output_buffer"], 999);

    // Output custom content
    add_action("admin_footer", [$this, "render_custom_content"], 0);
  }

  /**
   * Starts the output buffer
   *
   * @since 1.0.0
   * @return void
   */
  public function start_output_buffer()
  {
    ob_start();
  }

  /**
   * Renders the custom content for the post editor page
   *
   * @since 1.0.0
   * @return void
   */
  public function render_custom_content()
  {
    ob_end_clean(); ?>
		<div id="uix-post-editor">
		</div>
		<?php
  }
}

