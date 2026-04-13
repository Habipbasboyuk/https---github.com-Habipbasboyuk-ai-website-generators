<?php

namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit("No direct script access allowed.");

/**
 * Class PostEditorMeta
 *
 * Registers SEO meta fields for posts in the WordPress REST API
 *
 * @package UiXpress\Rest
 */
class PostEditorMeta
{
  /**
   * PostEditorMeta constructor.
   * Registers meta fields for REST API access
   */
  public function __construct()
  {
    add_action("init", [__CLASS__, "register_meta_fields"]);
  }

  /**
   * Registers SEO meta fields for posts
   *
   * @return void
   */
  public static function register_meta_fields()
  {
    // Get all public post types
    $post_types = get_post_types(["public" => true], "names");

    foreach ($post_types as $post_type) {
      // Register meta title
      register_meta("post", "uix_meta_title", [
        "object_subtype" => $post_type,
        "type" => "string",
        "single" => true,
        "show_in_rest" => true,
        "sanitize_callback" => "sanitize_text_field",
        "auth_callback" => function () {
          return current_user_can("edit_posts");
        },
      ]);

      // Register meta description
      register_meta("post", "uix_meta_description", [
        "object_subtype" => $post_type,
        "type" => "string",
        "single" => true,
        "show_in_rest" => true,
        "sanitize_callback" => "sanitize_textarea_field",
        "auth_callback" => function () {
          return current_user_can("edit_posts");
        },
      ]);

      // Register canonical URL
      register_meta("post", "uix_canonical_url", [
        "object_subtype" => $post_type,
        "type" => "string",
        "single" => true,
        "show_in_rest" => true,
        "sanitize_callback" => "esc_url_raw",
        "auth_callback" => function () {
          return current_user_can("edit_posts");
        },
      ]);
    }
  }
}

