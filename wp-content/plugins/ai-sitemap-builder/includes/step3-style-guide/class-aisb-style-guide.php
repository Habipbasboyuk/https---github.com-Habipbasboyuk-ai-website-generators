<?php

if (!defined('ABSPATH')) exit;

/**
 * Stap 3: stijlgids.
 *
 * Orkestreert kleuren, typografie en afbeeldingen voor het gekozen project.
 * AJAX-handlers zitten in sub-klassen; deze klasse beheert assets, shortcode en HTML-render.
 */
class AISB_Style_Guide {

  /** @var AISB_SG_Ajax */
  private $ajax;
  /** @var AISB_SG_Images */
  private $images;
  /** @var AISB_SG_Wireframes */
  private $wireframes;

  public function __construct() {
    $this->ajax       = new AISB_SG_Ajax();
    $this->images     = new AISB_SG_Images();
    $this->wireframes = new AISB_SG_Wireframes();
  }

  /**
   * Registreert shortcodes, assets en AJAX-handlers voor de stijlgids.
   */
  public function init(): void {
    add_action('init', [$this, 'register_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    add_action('wp_ajax_aisb_get_style_guide',        [$this->ajax,       'ajax_get_style_guide']);
    add_action('wp_ajax_aisb_save_style_guide',       [$this->ajax,       'ajax_save_style_guide']);
    add_action('wp_ajax_aisb_generate_style_guide',   [$this->ajax,       'ajax_generate_style_guide']);
    add_action('wp_ajax_aisb_auto_fonts',             [$this->ajax,       'ajax_auto_fonts']);
    add_action('wp_ajax_aisb_get_wireframe_sections', [$this->wireframes, 'ajax_get_wireframe_sections']);
    add_action('wp_ajax_aisb_get_unsplash_images',    [$this->images,     'ajax_get_unsplash_images']);
    add_action('wp_ajax_aisb_search_similar_images',  [$this->images,     'ajax_search_similar_images']);
    add_action('wp_ajax_aisb_upload_images',          [$this->images,     'ajax_upload_images']);
    add_action('wp_ajax_aisb_upload_logo',            [$this->images,     'ajax_upload_logo']);
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

    // Color Thief haalt dominante kleuren uit een logo voor de "With Logo" modus.
    wp_enqueue_script(
      'color-thief',
      'https://cdnjs.cloudflare.com/ajax/libs/color-thief/2.4.0/color-thief.umd.min.js',
      [],
      '2.4.0',
      true
    );

    wp_enqueue_script('aisb-sg-core',       AISB_PLUGIN_URL . 'assets/js/styleguide/core.js',       ['color-thief'],                                                          AISB_VERSION, true);
    wp_enqueue_script('aisb-sg-helpers',    AISB_PLUGIN_URL . 'assets/js/styleguide/helpers.js',    ['aisb-sg-core'],                                                         AISB_VERSION, true);
    wp_enqueue_script('aisb-sg-colours',    AISB_PLUGIN_URL . 'assets/js/styleguide/colours.js',    ['aisb-sg-helpers'],                                                      AISB_VERSION, true);
    wp_enqueue_script('aisb-sg-typography', AISB_PLUGIN_URL . 'assets/js/styleguide/typography.js', ['aisb-sg-helpers'],                                                      AISB_VERSION, true);
    wp_enqueue_script('aisb-sg-images',     AISB_PLUGIN_URL . 'assets/js/styleguide/images.js',     ['aisb-sg-helpers'],                                                      AISB_VERSION, true);
    wp_enqueue_script('aisb-style-guide',   AISB_PLUGIN_URL . 'assets/js/styleguide/init.js',       ['aisb-sg-core', 'aisb-sg-colours', 'aisb-sg-typography', 'aisb-sg-images'], AISB_VERSION, true);

    // Brug tussen server-PHP en browser-JavaScript.
    wp_localize_script('aisb-sg-core', 'AISB_SG', [
      'ajaxUrl'    => admin_url('admin-ajax.php'),
      'nonce'      => wp_create_nonce('aisb_sg_nonce'),
      'coreNonce'  => wp_create_nonce('aisb_nonce_action'),
      'previewUrl' => home_url('/?aisb_bricks_preview='),
    ]);
  }

  /**
   * Gedeelde HTML voor het stijlgidspaneel.
   * Gebruikt door zowel de standalone shortcode als de builder stap 3.
   */
  public static function render_style_guide_html(int $project_id): void {
    if (!is_user_logged_in()) : ?>
      <p>You must be logged in to use the Style Guide.</p>
    <?php elseif (!$project_id) : ?>
      <div style="background:#fafafa; border:1px solid #e6e6e6; border-radius:12px; padding:24px; text-align:center; margin-top:14px;">
        <p class="aisb-sg-muted" style="font-size:15px;">Please select a project first.</p>
      </div>
    <?php else : ?>
      <div class="aisb-sg-status" data-status-bar></div>

      <!-- Two-column layout: left = wizard controls, right = permanent canvas -->
      <div class="aisb-sg-split">

        <!-- ── LEFT: wizard ─────────────────────────────────── -->
        <div class="aisb-sg-split-left">

          <!-- Onboarding wizard step indicators -->
          <div class="aisb-sg-wizard-steps" data-wizard-steps>
            <button class="aisb-sg-wizard-step is-active" type="button" data-wizard-step="1">
              <span class="aisb-sg-wizard-step-num">1</span>
              <span class="aisb-sg-wizard-step-label">Colours</span>
            </button>
            <div class="aisb-sg-wizard-divider"></div>
            <button class="aisb-sg-wizard-step" type="button" data-wizard-step="2">
              <span class="aisb-sg-wizard-step-num">2</span>
              <span class="aisb-sg-wizard-step-label">Typography</span>
            </button>
            <div class="aisb-sg-wizard-divider"></div>
            <button class="aisb-sg-wizard-step" type="button" data-wizard-step="3">
              <span class="aisb-sg-wizard-step-num">3</span>
              <span class="aisb-sg-wizard-step-label">Images</span>
            </button>
          </div>

          <!-- ═══════════════ STEP 1: Colours & Logo ═══════════════ -->
          <div class="aisb-sg-wizard-panel" data-wizard-panel="1">
            <h3 class="aisb-sg-panel-title">Brand Logo & Colours</h3>

            <!-- Brand Logo Upload -->
            <div class="aisb-sg-mode-panel" style="margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid #e5e7eb;">
              <label class="aisb-label">Brand Logo</label>
              <p class="aisb-sg-panel-desc" style="margin-top:0;">Upload your website logo to place it in the wireframes.</p>
              <div class="aisb-sg-upload-zone" data-actual-logo-dropzone>
                <input type="file" accept="image/*" data-actual-logo-input style="display:none;">
                <div class="aisb-sg-upload-placeholder" data-actual-logo-placeholder>
                  <span>Drop your logo here or <a href="#" data-actual-logo-browse>browse</a></span>
                </div>
                <img data-actual-logo-preview class="aisb-sg-logo-preview" style="display:none;" alt="Logo preview" crossorigin="anonymous">
              </div>
            </div>

            <label class="aisb-label">Brand Colours</label>
            <p class="aisb-sg-panel-desc" style="margin-top:0;">Extract colours from an image or pick manually.</p>

            <!-- Sub-tabs: Met Logo / Zonder Logo -->
            <div class="aisb-sg-subtabs" data-colour-tabs>
              <button class="aisb-sg-subtab is-active" type="button" data-colour-mode="logo">Extract from Image</button>
              <button class="aisb-sg-subtab" type="button" data-colour-mode="manual">Pick Manually</button>
            </div>

            <!-- Panel: Met Logo -->
            <div class="aisb-sg-mode-panel" data-colour-panel="logo">
              <div class="aisb-sg-upload-zone" data-logo-dropzone>
                <input type="file" accept="image/*" data-logo-input style="display:none;">
                <div class="aisb-sg-upload-placeholder" data-logo-placeholder>
                  <span>Drop an image here or <a href="#" data-logo-browse>browse</a></span>
                </div>
                <img data-logo-preview class="aisb-sg-logo-preview" style="display:none;" alt="Logo preview" crossorigin="anonymous">
              </div>
              <div class="aisb-sg-extracted" data-colours-extracted style="display:none;">
                <h4 style="margin:0 0 10px;">Extracted Colours</h4>
                <div class="aisb-sg-swatches" data-colours-swatches></div>
                <button type="button" class="aisb-btn aisb-btn--outline aisb-sg-shuffle-btn" data-shuffle-colours>
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>
                  Shuffle unlocked
                </button>
              </div>
            </div>

            <!-- Panel: Zonder Logo -->
            <div class="aisb-sg-mode-panel" data-colour-panel="manual" style="display:none;">
              <label class="aisb-label">Primary colour</label>
              <div class="aisb-sg-color-picker-row">
                <input type="color" value="#4F46E5" data-colour-picker>
                <input type="text" value="#4F46E5" data-colour-hex class="aisb-sg-hex-input" maxlength="7" placeholder="#HEX">
              </div>
              <div class="aisb-sg-swatches" data-manual-swatches style="margin-top:14px;"></div>
              <button type="button" class="aisb-btn aisb-btn--outline aisb-sg-shuffle-btn" data-shuffle-colours>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/></svg>
                Shuffle unlocked
              </button>
            </div>

            <div class="aisb-sg-wizard-nav">
              <span></span>
              <button class="aisb-btn" type="button" data-wizard-next="2">Next: Typography →</button>
            </div>
          </div>

          <!-- ═══════════════ STEP 2: Typography ═══════════════ -->
          <div class="aisb-sg-wizard-panel" data-wizard-panel="2" style="display:none;">
            <h3 class="aisb-sg-panel-title">Typography</h3>
            <p class="aisb-sg-panel-desc">AI automatically picks the best Google Fonts pairing based on your brand colours and website topic.</p>

            <div class="aisb-sg-type-auto-status" data-typography-status>
              <div class="aisb-sg-empty-state">Fonts will be generated automatically when you arrive at this step…</div>
            </div>

            <div class="aisb-sg-type-result" data-typography-result style="display:none;">
              <section class="aisb-sg-section">
                <h4 class="aisb-sg-section-title">Typography</h4>
                <div class="aisb-sg-type-preview" data-typography-preview></div>
              </section>

              <!-- Manual font override -->
              <section class="aisb-sg-section aisb-sg-font-pickers">
                <h4 class="aisb-sg-section-title">Change fonts</h4>
                <p class="aisb-sg-panel-desc" style="margin-bottom:12px;">Pick a different font if you prefer — or keep the AI suggestion.</p>
                <div class="aisb-sg-font-picker-row">
                  <div class="aisb-sg-font-picker-col">
                    <label class="aisb-sg-font-picker-label">Heading font</label>
                    <select class="aisb-sg-font-select" data-font-select-heading></select>
                  </div>
                  <div class="aisb-sg-font-picker-col">
                    <label class="aisb-sg-font-picker-label">Body font</label>
                    <select class="aisb-sg-font-select" data-font-select-body></select>
                  </div>
                </div>
              </section>
            </div>

            <div class="aisb-sg-wizard-nav">
              <button class="aisb-btn-secondary" type="button" data-wizard-prev="1">← Back</button>
              <button class="aisb-btn" type="button" data-wizard-next="3">Next: Images →</button>
            </div>
          </div>

          <!-- ═══════════════ STEP 3: Images ═══════════════ -->
          <div class="aisb-sg-wizard-panel" data-wizard-panel="3" style="display:none;">
            <h3 class="aisb-sg-panel-title">Image style</h3>
            <p class="aisb-sg-panel-desc">Upload your own images or let AI find matching stock photos.</p>

            <!-- Upload zone -->
            <div class="aisb-sg-upload-zone" data-upload-zone>
              <div class="aisb-sg-upload-zone-inner">
                <span class="aisb-sg-upload-icon">📁</span>
                <p class="aisb-sg-upload-text">Drag &amp; drop images here or <label class="aisb-sg-upload-label">browse<input type="file" multiple accept="image/*" data-upload-input style="display:none;"></label></p>
                <p class="aisb-sg-upload-hint" data-upload-hint>0 images uploaded · <span data-total-needed>0</span> needed total</p>
              </div>
            </div>

            <div class="aisb-sg-image-library">
              <div class="aisb-sg-subtabs aisb-sg-subtabs--images" data-image-tabs>
                <button class="aisb-sg-subtab" type="button" data-image-mode="uploads">
                  Your uploads <span class="aisb-sg-image-tab-count" data-uploaded-tab-count>(0)</span>
                </button>
                <button class="aisb-sg-subtab" type="button" data-image-mode="unsplash">
                  Unsplash images <span class="aisb-sg-image-tab-count" data-unsplash-tab-count>(0)</span>
                </button>
              </div>

              <div class="aisb-sg-image-tab-panel" data-image-panel="uploads" style="display:none;">
                <div class="aisb-sg-empty-state" data-uploaded-empty>No uploaded images yet.</div>
                <div class="aisb-sg-uploaded-images" data-uploaded-grid style="display:none;">
                  <h5 class="aisb-sg-auto-group-title">Your uploads <span class="aisb-sg-auto-group-count" data-uploaded-count></span></h5>
                  <div class="aisb-sg-auto-grid" data-uploaded-grid-inner></div>
                </div>
              </div>

              <div class="aisb-sg-image-tab-panel" data-image-panel="unsplash" style="display:none;">
                <div class="aisb-sg-auto-images" data-images-grid>
                  <div class="aisb-sg-empty-state">Unsplash images will be loaded automatically…</div>
                </div>
              </div>
            </div>

            <div class="aisb-sg-wizard-nav">
              <button class="aisb-btn-secondary" type="button" data-wizard-prev="2">← Back</button>
              <a class="aisb-btn" href="<?php echo esc_url(add_query_arg(['aisb_step' => 4], remove_query_arg(['aisb_step']))); ?>" data-save-button>Save &amp; Design</a>
            </div>
          </div>

        </div><!-- /.aisb-sg-split-left -->

        <!-- ── RIGHT: shared live canvas (always visible) ──── -->
        <div class="aisb-sg-split-right">
          <div class="aisb-sg-preview-header">
            <span class="aisb-sg-preview-label">Live Preview</span>
            <span class="aisb-sg-preview-hint">Scroll · Ctrl+scroll to zoom</span>
          </div>
          <!-- Single canvas used by all three steps -->
          <div class="aisb-sg-live-preview"
               data-preview-colours
               data-preview-typography
               data-preview-images></div>
        </div><!-- /.aisb-sg-split-right -->

      </div><!-- /.aisb-sg-split -->

    <?php endif;
  }

  public function render_shortcode($atts = [], $content = null): string {
    if (!is_user_logged_in()) {
      return '<div class="aisb-wrap"><div class="aisb-card"><p>You must be logged in to use the Style Guide.</p></div></div>';
    }

    $project_id = isset($_GET['aisb_project']) ? (int)$_GET['aisb_project'] : 0;

    ob_start();
    ?>
    <div class="aisb-wrap" data-styleguide
         data-styleguide-project="<?php echo esc_attr($project_id); ?>">
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

        <?php self::render_style_guide_html($project_id); ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  private function current_page_has_shortcode(string $shortcode): bool {
    if (!is_singular()) return false;
    global $post;
    if (!$post || empty($post->post_content)) return false;
    return has_shortcode($post->post_content, $shortcode);
  }
}
