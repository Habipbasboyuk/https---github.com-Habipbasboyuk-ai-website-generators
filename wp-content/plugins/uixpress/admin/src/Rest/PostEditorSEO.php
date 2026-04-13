<?php

namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit("No direct script access allowed.");

/**
 * Class PostEditorSEO
 *
 * Outputs SEO meta tags (title, description, canonical URL) to the frontend
 *
 * @package UiXpress\Rest
 */
class PostEditorSEO
{
  /**
   * PostEditorSEO constructor.
   * Registers hooks for outputting SEO meta tags
   */
  public function __construct()
  {
    // Output meta tags in head
    add_action("wp_head", [__CLASS__, "output_seo_meta_tags"], 1);
    
    // Modify document title if custom meta title is set
    add_filter("document_title_parts", [__CLASS__, "modify_document_title"], 10, 1);
  }

  /**
   * Outputs SEO meta tags in the head section
   *
   * @return void
   */
  public static function output_seo_meta_tags()
  {
    // Only output on singular posts/pages
    if (!is_singular()) {
      return;
    }

    global $post;
    if (!$post) {
      return;
    }

    // Get meta fields
    $meta_title = get_post_meta($post->ID, "uix_meta_title", true);
    $meta_description = get_post_meta($post->ID, "uix_meta_description", true);
    $canonical_url = get_post_meta($post->ID, "uix_canonical_url", true);

    // Get post URL for canonical fallback
    $post_url = get_permalink($post->ID);
    
    // Determine canonical URL (use custom if set, otherwise use post URL)
    $final_canonical = !empty($canonical_url) ? $canonical_url : $post_url;

    // Output meta description if set
    if (!empty($meta_description)) {
      echo '<meta name="description" content="' . esc_attr($meta_description) . '" />' . "\n";
      
      // Also output as Open Graph description
      echo '<meta property="og:description" content="' . esc_attr($meta_description) . '" />' . "\n";
      
      // Also output as Twitter Card description
      echo '<meta name="twitter:description" content="' . esc_attr($meta_description) . '" />' . "\n";
    }

    // Output canonical URL
    echo '<link rel="canonical" href="' . esc_url($final_canonical) . '" />' . "\n";

    // Output Open Graph and Twitter Card tags if meta title is set
    if (!empty($meta_title)) {
      // Open Graph title
      echo '<meta property="og:title" content="' . esc_attr($meta_title) . '" />' . "\n";
      
      // Twitter Card title
      echo '<meta name="twitter:title" content="' . esc_attr($meta_title) . '" />' . "\n";
    }

    // Output Open Graph URL (use canonical URL)
    echo '<meta property="og:url" content="' . esc_url($final_canonical) . '" />' . "\n";

    // Output Open Graph type
    echo '<meta property="og:type" content="article" />' . "\n";

    // Output site name for Open Graph
    $site_name = get_bloginfo("name");
    if (!empty($site_name)) {
      echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '" />' . "\n";
    }

    // Output Twitter Card type
    echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";

    // Output featured image for Open Graph and Twitter if available
    $featured_image_id = get_post_thumbnail_id($post->ID);
    if ($featured_image_id) {
      $featured_image_url = wp_get_attachment_image_url($featured_image_id, "large");
      if ($featured_image_url) {
        echo '<meta property="og:image" content="' . esc_url($featured_image_url) . '" />' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url($featured_image_url) . '" />' . "\n";
        
        // Get image dimensions for Open Graph
        $image_meta = wp_get_attachment_metadata($featured_image_id);
        if (isset($image_meta["width"]) && isset($image_meta["height"])) {
          echo '<meta property="og:image:width" content="' . esc_attr($image_meta["width"]) . '" />' . "\n";
          echo '<meta property="og:image:height" content="' . esc_attr($image_meta["height"]) . '" />' . "\n";
        }
      }
    }
  }

  /**
   * Modifies the document title if custom meta title is set
   *
   * @param array $title_parts The title parts array
   * @return array Modified title parts
   */
  public static function modify_document_title($title_parts)
  {
    // Only modify on singular posts/pages
    if (!is_singular()) {
      return $title_parts;
    }

    global $post;
    if (!$post) {
      return $title_parts;
    }

    // Get custom meta title
    $meta_title = get_post_meta($post->ID, "uix_meta_title", true);

    // If meta title is set, use it as the title
    if (!empty($meta_title)) {
      $title_parts["title"] = $meta_title;
      // Remove site name from title if meta title is set (optional - you can keep it if preferred)
      // Uncomment the next line if you want to remove site name:
      // $title_parts["site"] = "";
    }

    return $title_parts;
  }
}

