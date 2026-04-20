<?php

if (!defined('ABSPATH')) exit;

/**
 * Step 4: Design
 * Full-page wireframe preview with style-guide overrides applied.
 */
class AISB_Design {

  public function init(): void {
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
  }

  public function enqueue_assets(): void {
    $is_step4 = ((int)($_GET['aisb_step'] ?? 0) === 4);
    $has_ctx  = isset($_GET['aisb_project']);
    $is_builder = $this->current_page_has_shortcode('ai_sitemap_builder');

    if (!($is_step4 && $has_ctx) && !$is_builder) return;

    wp_enqueue_style(
      'aisb-design-style',
      AISB_PLUGIN_URL . 'assets/design.css',
      [],
      AISB_VERSION
    );

    wp_enqueue_script(
      'aisb-design',
      AISB_PLUGIN_URL . 'assets/js/design.js',
      [],
      AISB_VERSION,
      true
    );

    wp_localize_script('aisb-design', 'AISB_DESIGN', [
      'ajaxUrl'    => admin_url('admin-ajax.php'),
      'nonce'      => wp_create_nonce('aisb_sg_nonce'),
      'previewUrl' => home_url('/?aisb_bricks_preview='),
    ]);
  }

  private function current_page_has_shortcode(string $tag): bool {
    global $post;
    return is_a($post, 'WP_Post') && has_shortcode($post->post_content, $tag);
  }

  /**
   * Render the Step 4 panel HTML inside the builder shortcode.
   */
  public static function render_design_html(int $project_id): void {
    // Embed the saved guide directly in HTML so design.js has it immediately,
    // without needing an extra AJAX round-trip. Because Save & Design awaits
    // the server save before navigating, the DB always has the latest data.
    $guide_raw = $project_id ? (string) get_post_meta($project_id, 'aisb_style_guide', true) : '';
    ?>
    <div class="aisb-design-wrap" data-design
         data-design-project="<?php echo esc_attr($project_id); ?>"
         data-design-guide="<?php echo esc_attr($guide_raw ?: '{}'); ?>">
      <div class="aisb-design-canvas" data-design-canvas></div>
      <p class="aisb-design-hint">Scroll to pan · Ctrl+scroll to zoom · Double-click to fit all</p>
    </div>
    <?php
  }
}
