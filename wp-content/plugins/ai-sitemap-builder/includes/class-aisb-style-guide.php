<?php

if (!defined('ABSPATH')) exit;

/**
 * Step 3: Style Guide
 * Generates and manages a brand style guide (colours, typography, components).
 */
class AISB_Style_Guide {

  public function init(): void {
    add_action('init', [$this, 'register_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    // AJAX
    add_action('wp_ajax_aisb_get_style_guide', [$this, 'ajax_get_style_guide']);
    add_action('wp_ajax_aisb_save_style_guide', [$this, 'ajax_save_style_guide']);
  }

  public function register_shortcode(): void {
    add_shortcode('ai_style_guide', [$this, 'render_shortcode']);
  }

  public function enqueue_assets(): void {
    $is_step3 = ((int)($_GET['aisb_step'] ?? 0) === 3);
    $has_ctx  = isset($_GET['aisb_project']);

    $is_sg_shortcode      = $this->current_page_has_shortcode('ai_style_guide');
    $is_builder_shortcode = $this->current_page_has_shortcode('ai_sitemap_builder');
    $is_step3_in_builder  = $is_step3 && $has_ctx;

    if (!$is_sg_shortcode && !$is_step3_in_builder && !$is_builder_shortcode) return;

    wp_enqueue_style(
      'aisb-style-guide-style',
      AISB_PLUGIN_URL . 'assets/style-guide.css',
      [],
      AISB_VERSION
    );

    wp_enqueue_script(
      'aisb-style-guide',
      AISB_PLUGIN_URL . 'assets/style-guide.js',
      [],
      AISB_VERSION,
      true
    );
    wp_localize_script('aisb-style-guide', 'AISB_SG', [
      'ajaxUrl'    => admin_url('admin-ajax.php'),
      'nonce'      => wp_create_nonce('aisb_sg_nonce'),
      'coreNonce'  => wp_create_nonce('aisb_nonce_action'),
    ]);
  }

  public function render_shortcode($atts = [], $content = null): string {
    if (!is_user_logged_in()) {
      return '<div class="aisb-wrap"><div class="aisb-card"><p>You must be logged in to use the Style Guide.</p></div></div>';
    }

    $project_id = isset($_GET['aisb_project']) ? (int)$_GET['aisb_project'] : 0;

    ob_start();
    ?>
    <div class="aisb-wrap" data-aisb-style-guide
         data-project-id="<?php echo esc_attr($project_id); ?>">
      <div class="aisb-card">
        <div class="aisb-sg-head">
          <div>
            <h2 class="aisb-title" style="margin:0;">Style Guide</h2>
            <p class="aisb-subtitle" style="margin-top:6px;">Brand colours · typography · component tokens</p>
          </div>
          <div class="aisb-sg-top-actions">
            <a class="aisb-btn-secondary" href="<?php echo esc_url(remove_query_arg(['aisb_step'])); ?>">Back to sitemap</a>
          </div>
        </div>

        <?php if (!$project_id) : ?>
          <div style="background:#fafafa; border:1px solid #e6e6e6; border-radius:12px; padding:24px; text-align:center; margin-top:14px;">
            <p class="aisb-sg-muted" style="font-size:15px;">Please select a project first.</p>
          </div>
        <?php else : ?>
          <div class="aisb-sg-status" data-aisb-sg-status></div>

          <div class="aisb-sg-layout">
            <!-- Colours -->
            <section class="aisb-sg-section" id="aisb-sg-colours">
              <h3 class="aisb-sg-section-title">Colours</h3>
              <div class="aisb-sg-swatches" data-aisb-sg-swatches>
                <div class="aisb-sg-empty-state">No colours defined yet.</div>
              </div>
            </section>

            <!-- Typography -->
            <section class="aisb-sg-section" id="aisb-sg-typography">
              <h3 class="aisb-sg-section-title">Typography</h3>
              <div class="aisb-sg-type-preview" data-aisb-sg-type>
                <div class="aisb-sg-empty-state">No typography defined yet.</div>
              </div>
            </section>

            <!-- Components -->
            <section class="aisb-sg-section" id="aisb-sg-components">
              <h3 class="aisb-sg-section-title">Components</h3>
              <div class="aisb-sg-components" data-aisb-sg-components>
                <div class="aisb-sg-empty-state">No components defined yet.</div>
              </div>
            </section>
          </div>

          <div class="aisb-sg-actions" style="margin-top:20px;">
            <button class="aisb-btn" type="button" data-aisb-sg-generate>Generate Style Guide</button>
            <button class="aisb-btn-secondary" type="button" data-aisb-sg-save>Save</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  /* ------------------- AJAX ------------------- */

  public function ajax_get_style_guide(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    $guide = get_post_meta($project_id, 'aisb_style_guide', true);
    $data  = $guide ? json_decode((string)$guide, true) : [];
    if (!is_array($data)) $data = [];
    wp_send_json_success(['style_guide' => $data]);
  }

  public function ajax_save_style_guide(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    $raw  = isset($_POST['style_guide_json']) ? wp_unslash($_POST['style_guide_json']) : '';
    $data = json_decode($raw, true);
    if (!is_array($data)) wp_send_json_error(['message' => 'Invalid style_guide_json'], 400);

    update_post_meta($project_id, 'aisb_style_guide', wp_json_encode($data, JSON_UNESCAPED_SLASHES));
    wp_send_json_success(['ok' => 1]);
  }

  /* ------------------- Helpers ------------------- */

  private function require_login(): void {
    if (!is_user_logged_in()) {
      wp_send_json_error(['message' => 'Not logged in'], 401);
    }
  }

  private function check_nonce(): void {
    $nonce  = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $ok_sg  = $nonce && wp_verify_nonce($nonce, 'aisb_sg_nonce');
    $ok_core = $nonce && wp_verify_nonce($nonce, 'aisb_nonce_action');
    if (!$ok_sg && !$ok_core) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }
  }

  private function assert_project_ownership(int $project_id): void {
    if (!$project_id) wp_send_json_error(['message' => 'Missing project_id'], 400);
    $post = get_post($project_id);
    if (!$post || $post->post_type !== 'aisb_project') {
      wp_send_json_error(['message' => 'Project not found'], 404);
    }
    if ((int)$post->post_author !== (int)get_current_user_id()) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }
  }

  private function current_page_has_shortcode(string $shortcode): bool {
    if (!is_singular()) return false;
    global $post;
    if (!$post || empty($post->post_content)) return false;
    return has_shortcode($post->post_content, $shortcode);
  }
}
