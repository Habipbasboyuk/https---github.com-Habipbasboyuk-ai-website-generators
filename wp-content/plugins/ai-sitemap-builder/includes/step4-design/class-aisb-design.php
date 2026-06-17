<?php

if (!defined('ABSPATH')) exit;

/**
 * Stap 4: designpreview — orchestrator.
 *
 * Delegeert AJAX-acties naar AISB_Design_Ajax en AISB_Design_Figma.
 * Bevat zelf alleen assets, de shortcode-renderhelper en de detectiehelper.
 */
class AISB_Design {

  private $ajax;
  private $figma;

  public function __construct() {
    $this->ajax  = new AISB_Design_Ajax();
    $this->figma = new AISB_Design_Figma();
  }

  public function init(): void {
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('wp_ajax_aisb_design_list_templates',   [$this->ajax,  'ajax_list_templates']);
    add_action('wp_ajax_aisb_design_replace_section',  [$this->ajax,  'ajax_design_replace_section']);
    add_action('wp_ajax_aisb_design_save_patch',       [$this->ajax,  'ajax_save_design_patch']);
    add_action('wp_ajax_aisb_design_insert_section',   [$this->ajax,  'ajax_insert_section']);
    add_action('wp_ajax_aisb_design_reorder_sections', [$this->ajax,  'ajax_reorder_sections']);
    add_action('wp_ajax_aisb_export_figma_json',       [$this->figma, 'ajax_export_figma_json']);
  }

  public function enqueue_assets(): void {
    $is_step4   = ((int)($_GET['aisb_step'] ?? 0) === 4);
    $has_ctx    = isset($_GET['aisb_project']);
    $is_builder = $this->current_page_has_shortcode('ai_sitemap_builder');

    if (!($is_step4 && $has_ctx) && !$is_builder) return;

    wp_enqueue_style(
      'aisb-design-style',
      AISB_PLUGIN_URL . 'assets/design.css',
      [],
      AISB_VERSION
    );

    wp_enqueue_style(
      'aisb-editor-panel-style',
      AISB_PLUGIN_URL . 'assets/css/editor-panel.css',
      ['aisb-design-style'],
      AISB_VERSION
    );

    // Design scripts gesplitst over 5 bestanden: core → overrides → images → canvas → init
    wp_enqueue_script(
      'aisb-design-core',
      AISB_PLUGIN_URL . 'assets/js/design/core.js',
      [],
      AISB_VERSION,
      true
    );
    wp_enqueue_script(
      'aisb-design-overrides',
      AISB_PLUGIN_URL . 'assets/js/design/overrides.js',
      ['aisb-design-core'],
      AISB_VERSION,
      true
    );
    wp_enqueue_script(
      'aisb-design-images',
      AISB_PLUGIN_URL . 'assets/js/design/images.js',
      ['aisb-design-core'],
      AISB_VERSION,
      true
    );
    wp_enqueue_script(
      'aisb-design-canvas',
      AISB_PLUGIN_URL . 'assets/js/design/canvas.js',
      ['aisb-design-overrides', 'aisb-design-images'],
      AISB_VERSION,
      true
    );
    wp_enqueue_script(
      'aisb-design',
      AISB_PLUGIN_URL . 'assets/js/design/init.js',
      ['aisb-design-canvas'],
      file_exists(AISB_PLUGIN_DIR . 'assets/js/design/init.js') ? filemtime(AISB_PLUGIN_DIR . 'assets/js/design/init.js') : AISB_VERSION,
      true
    );

    wp_enqueue_script(
      'aisb-design-editor-panel',
      AISB_PLUGIN_URL . 'assets/js/design/editor-panel.js',
      ['aisb-design-canvas'],
      AISB_VERSION,
      true
    );

    wp_enqueue_script(
      'aisb-design-figma-export',
      AISB_PLUGIN_URL . 'assets/js/design/figma-export.js',
      ['aisb-design'],
      AISB_VERSION,
      true
    );

    wp_enqueue_script(
      'aisb-design-figma-import',
      AISB_PLUGIN_URL . 'assets/js/design/figma-import.js',
      ['aisb-design'],
      AISB_VERSION,
      true
    );

    wp_localize_script('aisb-design-core', 'AISB_DESIGN', [
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
   * Render het Step 4 paneel HTML in de builder shortcode.
   */
  public static function render_design_html(int $project_id): void {
    if (!$project_id) {
      ?>
      <div class="aisb-design-wrap">
        <a href="http://ai-sitemap-generators.local/?aisb_step=2" style="padding: 1rem; background-color: #5398DB; color: white; text-decoration: none; border-radius: 25px; ">Please select a project to design in step 2.</a>
      </div>
      <?php
      return;
    }

    $figma     = new AISB_Design_Figma();
    $guide_raw = (string) get_post_meta($project_id, 'aisb_style_guide', true);

    // Converteer logoUrl naar inline data-URI zodat de canvas het logo kan tonen
    // zonder afhankelijk te zijn van het lokale WordPress-URL.
    if ($guide_raw) {
      $guide_arr = json_decode($guide_raw, true);
      if (is_array($guide_arr) && !empty($guide_arr['logoUrl'])
          && strpos($guide_arr['logoUrl'], 'data:') !== 0) {
        $guide_arr['logoUrl'] = $figma->url_to_data_uri($guide_arr['logoUrl']);
        $guide_raw = wp_json_encode($guide_arr, JSON_UNESCAPED_SLASHES);
      }
    }
    ?>
    <div class="aisb-design-wrap" data-design
         data-design-project="<?php echo esc_attr($project_id); ?>"
         data-design-guide="<?php echo esc_attr($guide_raw ?: '{}'); ?>">
      <div class="aisb-design-toolbar">
        <span class="aisb-design-toolbar-title">Design Canvas</span>
        <div class="aisb-design-toolbar-actions">
          <button id="aisb-design-save-btn" class="aisb-design-save-btn aisb-design-save-action" type="button" title="Alle wijzigingen opslaan">&#128190; Opslaan</button>
          <button id="aisb-design-publish-btn" class="aisb-design-save-btn aisb-design-publish-action" type="button" title="Clone en publish deze site via InstaWP">&#128640; Publish</button>
          <div class="aisb-figma-export-wrap" id="aisb-figma-export-wrap">
            <button class="aisb-design-save-btn aisb-design-figma-btn" type="button">&#128196; Export to Figma</button>
            <div class="aisb-figma-export-dropdown">
              <div class="aisb-figma-export-dropdown-inner">
                <button id="aisb-design-figma-copy-btn" type="button">Copy JSON</button>
                <button id="aisb-design-figma-download-btn" type="button">Download JSON</button>
              </div>
            </div>
          </div>
          <button id="aisb-design-figma-import-btn" class="aisb-design-save-btn aisb-design-figma-import-btn" type="button" title="Plak Figma Brixies JSON om styling &amp; content toe te passen">&#128229; Import from Figma</button>
        </div>
      </div>
      <div class="aisb-design-canvas" data-design-canvas></div>
      <p class="aisb-design-hint">Scroll to pan · Ctrl+scroll to zoom · Double-click to fit all</p>
    </div>
    <?php
  }
}
