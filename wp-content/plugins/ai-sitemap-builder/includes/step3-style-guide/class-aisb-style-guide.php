<?php

if (!defined('ABSPATH')) exit;

/**
 * Step 3: Style Guide
 * Generates and manages a brand style guide (colours, typography, components).
 */
class AISB_Style_Guide {

// laadt assets, shortcodes en AJAX handlers voor de Style Guide (stap 3)
  public function init(): void {
    add_action('init', [$this, 'register_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    // AJAX
    add_action('wp_ajax_aisb_get_style_guide', [$this, 'ajax_get_style_guide']);
    add_action('wp_ajax_aisb_save_style_guide', [$this, 'ajax_save_style_guide']);
    add_action('wp_ajax_aisb_generate_style_guide', [$this, 'ajax_generate_style_guide']);
    add_action('wp_ajax_aisb_auto_fonts',             [$this, 'ajax_auto_fonts']);
    add_action('wp_ajax_aisb_get_wireframe_sections', [$this, 'ajax_get_wireframe_sections']);
    add_action('wp_ajax_aisb_get_unsplash_images',   [$this, 'ajax_get_unsplash_images']);
    add_action('wp_ajax_aisb_search_similar_images', [$this, 'ajax_search_similar_images']);
    add_action('wp_ajax_aisb_upload_images',          [$this, 'ajax_upload_images']);
  }

  // Shortcode voor standalone gebruik van de Style Guide
  public function register_shortcode(): void {
    add_shortcode('ai_style_guide', [$this, 'render_shortcode']);
  }

  // Assets alleen laden op de pagina's waar de Style Guide wordt gebruikt (stap 3)
  public function enqueue_assets(): void {
    $is_step3 = ((int)($_GET['aisb_step'] ?? 0) === 3); // Alleen in stap 3
    $has_ctx  = isset($_GET['aisb_project']); // En er moet een project_id in de URL staan

    $is_sg_shortcode      = $this->current_page_has_shortcode('ai_style_guide'); // Of de shortcode [ai_style_guide] gebruiken
    $is_builder_shortcode = $this->current_page_has_shortcode('ai_sitemap_builder'); // Of de algemene builder shortcode (voor het geval we de Style Guide daar ook tonen)
    $is_step3_in_builder  = $is_step3 && $has_ctx; // Of we zitten in stap 3 van de builder (gebaseerd op URL)

    if (!$is_sg_shortcode && !$is_step3_in_builder && !$is_builder_shortcode) return;

    // Assets registreren en enqueuen
    wp_enqueue_style(
      'aisb-style-guide-style', 
      AISB_PLUGIN_URL . 'assets/style-guide.css',
      [],
      AISB_VERSION
    );

    // Color Thief, dominante kleuren uit een afbeelding te halen, voor de "With Logo" modus van de Style Guide.
    wp_enqueue_script(
      'color-thief',
      'https://cdnjs.cloudflare.com/ajax/libs/color-thief/2.4.0/color-thief.umd.min.js',
      [],
      '2.4.0',
      true
    );

    // Hoofdscript voor de Style Guide functionaliteit (uploaden logo, kleuren tonen, AI-aanroepen, live preview, etc.)
    // Split over 4 bestanden: core → colours → typography → images → init
    wp_enqueue_script(
      'aisb-sg-core',
      AISB_PLUGIN_URL . 'assets/js/styleguide/core.js',
      ['color-thief'],
      AISB_VERSION,
      true
    );
    wp_enqueue_script(
      'aisb-sg-helpers',
      AISB_PLUGIN_URL . 'assets/js/styleguide/helpers.js',
      ['aisb-sg-core'],
      AISB_VERSION,
      true
    );
    wp_enqueue_script(
      'aisb-sg-colours',
      AISB_PLUGIN_URL . 'assets/js/styleguide/colours.js',
      ['aisb-sg-helpers'],
      AISB_VERSION,
      true
    );
    wp_enqueue_script(
      'aisb-sg-typography',
      AISB_PLUGIN_URL . 'assets/js/styleguide/typography.js',
      ['aisb-sg-helpers'],
      AISB_VERSION,
      true
    );
    wp_enqueue_script(
      'aisb-sg-images',
      AISB_PLUGIN_URL . 'assets/js/styleguide/images.js',
      ['aisb-sg-helpers'],
      AISB_VERSION,
      true
    );
    wp_enqueue_script(
      'aisb-style-guide',
      AISB_PLUGIN_URL . 'assets/js/styleguide/init.js',
      ['aisb-sg-core', 'aisb-sg-colours', 'aisb-sg-typography', 'aisb-sg-images'],
      AISB_VERSION,
      true
    );
    // brug tussen serverphp en browser javascript
    wp_localize_script('aisb-sg-core', 'AISB_SG', [
      'ajaxUrl'    => admin_url('admin-ajax.php'),
      'nonce'      => wp_create_nonce('aisb_sg_nonce'),
      'coreNonce'  => wp_create_nonce('aisb_nonce_action'),
      'previewUrl' => home_url('/?aisb_bricks_preview='),
    ]);
  }

  /**
   * Shared inner HTML for the Style Guide panel.
   * Used by both the standalone shortcode and the builder Step 3.
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

      <!-- ═══════════════ STEP 1: Colours ═══════════════ -->
      <div class="aisb-sg-wizard-panel" data-wizard-panel="1">
        <h3 class="aisb-sg-panel-title">Choose your brand colours</h3>
        <p class="aisb-sg-panel-desc">Upload your logo to automatically extract colours, or pick a primary colour manually.</p>

        <!-- Sub-tabs: Met Logo / Zonder Logo -->
        <div class="aisb-sg-subtabs" data-colour-tabs>
          <button class="aisb-sg-subtab is-active" type="button" data-colour-mode="logo">With Logo</button>
          <button class="aisb-sg-subtab" type="button" data-colour-mode="manual">Without Logo</button>
        </div>

        <!-- Panel: Met Logo -->
        <div class="aisb-sg-mode-panel" data-colour-panel="logo">
          <div class="aisb-sg-upload-zone" data-logo-dropzone>
            <input type="file" accept="image/*" data-logo-input style="display:none;">
            <div class="aisb-sg-upload-placeholder" data-logo-placeholder>
              <span>Drop your logo here or <a href="#" data-logo-browse>browse</a></span>
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

        <!-- Live preview for colour step -->
        <div class="aisb-sg-step-preview" data-step-preview="1">
          <h4 class="aisb-sg-preview-label">Preview</h4>
          <div class="aisb-sg-live-preview" data-preview-colours></div>
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

        <!-- Live preview for typography step -->
        <div class="aisb-sg-step-preview" data-step-preview="2">
          <h4 class="aisb-sg-preview-label">Preview</h4>
          <div class="aisb-sg-live-preview" data-preview-typography></div>
        </div>

        <div class="aisb-sg-wizard-nav">
          <button class="aisb-btn-secondary" type="button" data-wizard-prev="1">← Back</button>
          <button class="aisb-btn" type="button" data-wizard-next="3">Next: Images →</button>
        </div>
      </div>

      <!-- ═══════════════ STEP 3: Images ═══════════════ -->
      <div class="aisb-sg-wizard-panel" data-wizard-panel="3" style="display:none;">
        <h3 class="aisb-sg-panel-title">Image style</h3>
        <p class="aisb-sg-panel-desc">Upload your own images or let AI find matching stock photos. If you upload fewer than needed, AI fills the rest from Unsplash.</p>

        <!-- Upload zone -->
        <div class="aisb-sg-upload-zone" data-upload-zone>
          <div class="aisb-sg-upload-zone-inner">
            <span class="aisb-sg-upload-icon">📁</span>
            <p class="aisb-sg-upload-text">Drag &amp; drop images here or <label class="aisb-sg-upload-label">browse<input type="file" multiple accept="image/*" data-upload-input style="display:none;"></label></p>
            <p class="aisb-sg-upload-hint" data-upload-hint>0 images uploaded · <span data-total-needed>0</span> needed total</p>
          </div>
        </div>

        <!-- Uploaded images -->
        <div class="aisb-sg-uploaded-images" data-uploaded-grid style="display:none;">
          <h5 class="aisb-sg-auto-group-title">Your uploads <span class="aisb-sg-auto-group-count" data-uploaded-count></span></h5>
          <div class="aisb-sg-auto-grid" data-uploaded-grid-inner></div>
        </div>

        <!-- Auto-assigned images grid — populated by JS -->
        <div class="aisb-sg-auto-images" data-images-grid>
          <div class="aisb-sg-empty-state">Images will be loaded automatically…</div>
        </div>

        <!-- Live preview for images step -->
        <div class="aisb-sg-step-preview" data-step-preview="3">
          <h4 class="aisb-sg-preview-label">Preview</h4>
          <div class="aisb-sg-live-preview" data-preview-images></div>
        </div>

        <div class="aisb-sg-wizard-nav">
          <button class="aisb-btn-secondary" type="button" data-wizard-prev="2">← Back</button>
          <button class="aisb-btn" type="button" data-save-button>Save &amp; Finish</button>
        </div>
      </div>

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

    // haalt project id uit de post data
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

    // controleert of de gebruiker eigenaar is van het project
    $this->assert_project_ownership($project_id);

    // ontvangt de style guide data als json en decodeert deze naar eena array
    $raw  = isset($_POST['style_guide_json']) ? wp_unslash($_POST['style_guide_json']) : '';
    $data = json_decode($raw, true);

    if (!is_array($data)) wp_send_json_error(['message' => 'Invalid style_guide_json'], 400);

    // slaat de style guide data op als post meta, geëncodeerd als json
    update_post_meta($project_id, 'aisb_style_guide', wp_json_encode($data, JSON_UNESCAPED_SLASHES));
    // stuurt een succes response terug naar de client
    wp_send_json_success(['ok' => 1]);
  }

  /**
   * AJAX: Genereer style guide — ontvangt kleuren van JS, vraagt OpenAI om font-pairings.
   */
  public function ajax_generate_style_guide(): void {
    $this->require_login();
    $this->check_nonce();

    // controleer project_id en eigenaarschap
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    // Kleuren ontvangen van de client (ColorThief of handmatig)
    $colours_raw = isset($_POST['colours']) ? wp_unslash($_POST['colours']) : '[]';

    // Decodeer de kleurenlijst en valideer deze
    $colours = json_decode($colours_raw, true);
    if (!is_array($colours) || empty($colours)) {
      wp_send_json_error(['message' => 'No colours supplied'], 400);
    }

    // Saniteer hex-waarden
    $colour_list = [];
    foreach ($colours as $c) {

    // Verwijder alles behalve # en hex-tekens, en zorg dat het veld bestaat
      $hex = isset($c['hex']) ? preg_replace('/[^#0-9a-fA-F]/', '', $c['hex']) : '';
      if ($hex) $colour_list[] = $hex;
    }


    if (empty($colour_list)) {
      wp_send_json_error(['message' => 'No valid hex colours'], 400);
    }

    // Controleer of de OpenAI API-sleutel is ingesteld in de plugin-instellingen
    $settings = get_option('aisb_settings', []);
    if (empty($settings['api_key'])) {
      wp_send_json_error(['message' => 'OpenAI API key not configured. Go to Settings.'], 400);
    }


    // Prompt voor OpenAI om een font pairing en type scale te genereren op basis van de kleuren. vraag expliciet om alleen JSON terug te geven in een specifiek format, zodat we dit makkelijk kunnen parsen aan de client-side
    $prompt = "I have the following brand colours: " . implode(', ', $colour_list) . "\n\n"
      . "Suggest a complementary Google Fonts pairing (one heading font, one body font) that matches these colours' mood.\n"
      . "Also return a type scale with 5 levels: H1, H2, H3, Body, Small.\n\n"
      . "Return ONLY valid JSON in this exact format:\n"
      . '{"heading_font":"Font Name","body_font":"Font Name","type_scale":['
      . '{"label":"H1","cls":"h1","fontFamily":"HEADING_FONT","sample":"Heading One"},'
      . '{"label":"H2","cls":"h2","fontFamily":"HEADING_FONT","sample":"Heading Two"},'
      . '{"label":"H3","cls":"h3","fontFamily":"HEADING_FONT","sample":"Heading Three"},'
      . '{"label":"Body","cls":"body","fontFamily":"BODY_FONT","sample":"The quick brown fox jumps over the lazy dog."},'
      . '{"label":"Small","cls":"small","fontFamily":"BODY_FONT","sample":"Fine print and captions"}'
      . "]}";

    $system = "You are a brand typography expert. Return ONLY valid JSON, no explanation, no markdown fences.";


    // maak contact met openai api
    $openai = new AISB_OpenAI();
    // roep de chat completions endpoint aan met het prompt en systeembericht, en de API-sleutel uit de plugin-instellingen. ontvang het resultaat of een WP_Error als er iets misgaat
    $result = $openai->call_openai_chat_completions($prompt, $settings, $system);

    // als de API-aanroep mislukt of geen geldig antwoord teruggeeft, stuur dan een JSON error response terug naar de client
    if (is_wp_error($result)) {
      wp_send_json_error(['message' => $result->get_error_message()]);
    }


    $fonts = json_decode($result, true);
    if (!is_array($fonts) || empty($fonts['heading_font'])) {
      wp_send_json_error(['message' => 'Invalid AI response — could not parse font suggestion.']);
    }

    wp_send_json_success([
      'fonts'   => $fonts,
      'colours' => $colours,
    ]);
  }

  /**
   * AJAX: Auto-generate font pairing using AI + validate via Google Fonts API.
   * Google Fonts API key lives in wp-config.php — never exposed to browser.
   */
  public function ajax_auto_fonts(): void {
    $this->require_login();
    $this->check_nonce();

    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    $settings = get_option('aisb_settings', []);
    if (empty($settings['api_key'])) {
      wp_send_json_error(['message' => 'OpenAI API key not configured.'], 400);
    }

    // Get colours from POST
    $colours_raw = isset($_POST['colours']) ? wp_unslash($_POST['colours']) : '[]';
    $colours = json_decode($colours_raw, true);
    $colour_list = [];
    if (is_array($colours)) {
      foreach ($colours as $c) {
        $hex = isset($c['hex']) ? preg_replace('/[^#0-9a-fA-F]/', '', $c['hex']) : '';
        if ($hex) $colour_list[] = $hex;
      }
    }

    // Get site name for context
    $site_name = get_the_title($project_id) ?: get_bloginfo('name');

    // Build available fonts list from Google Fonts API (if key present)
    $available_fonts = [];
    if (defined('AISB_GOOGLE_FONTS_KEY') && AISB_GOOGLE_FONTS_KEY) {
      $gf_url = add_query_arg([
        'key'  => AISB_GOOGLE_FONTS_KEY,
        'sort' => 'popularity',
      ], 'https://www.googleapis.com/webfonts/v1/webfonts');
      $gf_response = wp_remote_get($gf_url, ['timeout' => 10]);
      if (!is_wp_error($gf_response) && wp_remote_retrieve_response_code($gf_response) === 200) {
        $gf_body = json_decode(wp_remote_retrieve_body($gf_response), true);
        if (!empty($gf_body['items'])) {
          // Take top 200 popular fonts for the AI to choose from
          $items = array_slice($gf_body['items'], 0, 200);
          foreach ($items as $f) {
            $available_fonts[] = $f['family'];
          }
        }
      }
    }

    $font_list_hint = '';
    if ($available_fonts) {
      $font_list_hint = "\n\nYou MUST choose ONLY from these Google Fonts (pick two DIFFERENT fonts — one for headings, one for body):\n" . implode(', ', $available_fonts);
    } else {
      // Fallback: give the AI a curated list so it doesn't always pick the same generic font
      $font_list_hint = "\n\nChoose from popular Google Fonts such as: Montserrat, Playfair Display, Raleway, Lora, Poppins, Merriweather, Oswald, Source Sans 3, Nunito, Roboto Slab, Inter, Work Sans, Fira Sans, Libre Baskerville, DM Sans, Josefin Sans, Rubik, Bitter, Karla, Mulish, Cabin, Archivo, Crimson Text, PT Serif, Quicksand, Space Grotesk, Barlow, Cormorant Garamond, Outfit, Sora.";
    }

    $prompt = "Website name: \"{$site_name}\""
      . ($colour_list ? "\nBrand colours: " . implode(', ', $colour_list) : '')
      . "\n\nPick the best Google Fonts pairing (one heading font, one body font) that matches this brand."
      . " The heading font and body font should be DIFFERENT from each other."
      . "\n\nAlso pick TWO alternating section background colours for the website layout:"
      . " - section_bg_1: the main/lighter background (e.g. white, very light tint)"
      . " - section_bg_2: the alternating background (a noticeably different but still light tint that complements the brand)"
      . " These two colours MUST be visually distinct from each other — the user must clearly see the alternation."
      . " They can be e.g. white + light blue, cream + soft lavender, off-white + pale brand tint, etc."
      . " Do NOT use plain grey. Make it match the brand feel."
      . " Return them as hex colour values."
      . $font_list_hint
      . "\n\nReturn ONLY valid JSON. Replace every placeholder with the actual value you chose:"
      . "\n" . '{"heading_font":"<actual heading font name>","body_font":"<actual body font name>","section_bg_1":"<hex>","section_bg_2":"<hex>","type_scale":['
      . '{"label":"H1","cls":"h1","fontFamily":"<actual heading font name>","sample":"Heading One"},'
      . '{"label":"H2","cls":"h2","fontFamily":"<actual heading font name>","sample":"Heading Two"},'
      . '{"label":"H3","cls":"h3","fontFamily":"<actual heading font name>","sample":"Heading Three"},'
      . '{"label":"Body","cls":"body","fontFamily":"<actual body font name>","sample":"The quick brown fox jumps over the lazy dog."},'
      . '{"label":"Small","cls":"small","fontFamily":"<actual body font name>","sample":"Fine print and captions"}'
      . ']}';

    $openai = new AISB_OpenAI();
    $result = $openai->call_openai_chat_completions($prompt, $settings, 'You are a brand typography and colour expert. Return ONLY valid JSON, no explanation, no markdown fences. Every fontFamily value must be a real Google Font name, never a placeholder. section_bg_1 and section_bg_2 must be valid hex colours that are visually distinct.');

    if (is_wp_error($result)) {
      wp_send_json_error(['message' => $result->get_error_message()]);
    }

    $fonts = json_decode($result, true);
    if (!is_array($fonts) || empty($fonts['heading_font'])) {
      wp_send_json_error(['message' => 'Invalid AI response.']);
    }

    // Safety net: replace any leftover placeholders in type_scale with actual font names
    $heading = $fonts['heading_font'];
    $body    = $fonts['body_font'] ?: $heading;
    if (!empty($fonts['type_scale']) && is_array($fonts['type_scale'])) {
      foreach ($fonts['type_scale'] as &$item) {
        if (!isset($item['fontFamily'])) continue;
        $ff = $item['fontFamily'];
        // Replace known placeholders the AI might have returned literally
        if (in_array($ff, ['HEADING_FONT', '<actual heading font name>', 'heading_font', 'Font Name', ''], true)) {
          $item['fontFamily'] = $heading;
        } elseif (in_array($ff, ['BODY_FONT', '<actual body font name>', 'body_font', ''], true)) {
          $item['fontFamily'] = $body;
        }
      }
      unset($item);
    }

    wp_send_json_success([
      'fonts'   => $fonts,
      'colours' => $colours ?: [],
    ]);
  }

  /**
   * AJAX: Fetch Unsplash images based on the project/website name.
   * The API key lives only in wp-config.php and is never sent to the browser.
   */
  public function ajax_get_unsplash_images(): void {
    $this->require_login();
    $this->check_nonce();

    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    if (!defined('AISB_UNSPLASH_KEY') || !AISB_UNSPLASH_KEY) {
      wp_send_json_error(['message' => 'Unsplash API key not configured (AISB_UNSPLASH_KEY missing from wp-config.php).'], 400);
    }

    // Derive a search keyword from the project title via OpenAI
    $site_name = get_the_title($project_id) ?: get_bloginfo('name');
    $settings  = get_option('aisb_settings', []);

    $keyword = $site_name; // fallback — used as-is if OpenAI call fails

    if (!empty($settings['api_key'])) {
      $openai = new AISB_OpenAI();
      $prompt = "Website name: \"{$site_name}\"\nReturn ONLY a single English noun keyword for a stock photo search that best represents this website's topic. Examples: \"bakerij\" → \"bakery\", \"advocatenkantoor\" → \"lawyer\". Return ONLY the keyword, nothing else.";
      $result = $openai->call_openai_chat_completions($prompt, $settings, 'You are a keyword extractor. Return ONLY one English word.');
      if (!is_wp_error($result)) {
        $kw = trim(wp_strip_all_tags($result));
        // Accept only a single short word/phrase (guard against garbage output)
        if ($kw && strlen($kw) < 60 && !str_contains($kw, '{')) {
          $keyword = $kw;
        }
      }
    }

    // How many images do we need?
    $total_needed = isset($_POST['total_needed']) ? max(1, (int)$_POST['total_needed']) : 30;
    $total_needed = min($total_needed, 60); // hard cap

    // Fetch from Unsplash — paginate if needed (max 30 per page)
    $images = [];
    $pages_needed = (int) ceil($total_needed / 30);
    for ($page = 1; $page <= $pages_needed; $page++) {
      $per_page = min(30, $total_needed - count($images));
      $api_url  = add_query_arg([
        'query'       => $keyword,
        'per_page'    => $per_page,
        'page'        => $page,
        'orientation' => 'landscape',
      ], 'https://api.unsplash.com/search/photos');

      $response = wp_remote_get($api_url, [
        'headers' => [
          'Authorization'  => 'Client-ID ' . AISB_UNSPLASH_KEY,
          'Accept-Version' => 'v1',
        ],
        'timeout' => 15,
      ]);

      if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Unsplash request failed: ' . $response->get_error_message()]);
      }
      $code = wp_remote_retrieve_response_code($response);
      if ($code !== 200) {
        wp_send_json_error(['message' => 'Unsplash returned HTTP ' . $code]);
      }

      $body = json_decode(wp_remote_retrieve_body($response), true);
      if (!is_array($body) || empty($body['results'])) break;

      foreach ($body['results'] as $photo) {
        $images[] = [
          'thumb'        => $photo['urls']['small']   ?? '',
          'full'         => $photo['urls']['regular'] ?? '',
          'alt'          => $photo['alt_description'] ?? $keyword,
          'photographer' => $photo['user']['name']    ?? '',
          'link'         => $photo['links']['html']   ?? '',
        ];
        if (count($images) >= $total_needed) break;
      }
      if (count($images) >= $total_needed) break;
    }

    wp_send_json_success(['images' => $images, 'keyword' => $keyword]);
  }

  /**
   * AJAX: Search similar Unsplash images for a given keyword.
   * Used when the user clicks an image to swap it.
   */
  public function ajax_search_similar_images(): void {
    $this->require_login();
    $this->check_nonce();

    if (!defined('AISB_UNSPLASH_KEY') || !AISB_UNSPLASH_KEY) {
      wp_send_json_error(['message' => 'Unsplash API key not configured.'], 400);
    }

    $keyword = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
    if (!$keyword) {
      wp_send_json_error(['message' => 'No keyword supplied.'], 400);
    }

    $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;

    $api_url = add_query_arg([
      'query'       => $keyword,
      'per_page'    => 12,
      'page'        => $page,
      'orientation' => 'landscape',
    ], 'https://api.unsplash.com/search/photos');

    $response = wp_remote_get($api_url, [
      'headers' => [
        'Authorization'  => 'Client-ID ' . AISB_UNSPLASH_KEY,
        'Accept-Version' => 'v1',
      ],
      'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
      wp_send_json_error(['message' => 'Unsplash request failed.']);
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
      wp_send_json_error(['message' => 'Unsplash returned HTTP ' . wp_remote_retrieve_response_code($response)]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $images = [];
    if (!empty($body['results'])) {
      foreach ($body['results'] as $photo) {
        $images[] = [
          'thumb'        => $photo['urls']['small']   ?? '',
          'full'         => $photo['urls']['regular'] ?? '',
          'alt'          => $photo['alt_description'] ?? $keyword,
          'photographer' => $photo['user']['name']    ?? '',
          'link'         => $photo['links']['html']   ?? '',
        ];
      }
    }

    $total_pages = isset($body['total_pages']) ? (int)$body['total_pages'] : 1;
    wp_send_json_success(['images' => $images, 'page' => $page, 'total_pages' => $total_pages]);
  }

  /**
   * AJAX: Haal wireframe-secties op voor de live preview.
   * Gebruikt project_id → aisb_latest_sitemap_id → aisb_wireframes tabel.
   */
  public function ajax_get_wireframe_sections(): void {
    $this->require_login();
    $this->check_nonce();

    // controleer project_id en eigenaarschap
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    // Zoek de laatste sitemap voor dit project
    $sitemap_id = (int) get_post_meta($project_id, 'aisb_latest_sitemap_id', true);
    if (!$sitemap_id) {
      wp_send_json_success(['sections' => []]);
    }

    // Haal de sitemap JSON op om de eerste pagina te vinden
    $sitemap_json = get_post_meta($sitemap_id, 'aisb_sitemap_json', true);

    // Decodeer de sitemap JSON en pak de pagina's eruit (onder 'sitemap' of 'pages', afhankelijk van het format)
    $sitemap_data = $sitemap_json ? json_decode((string)$sitemap_json, true) : [];

    // Haal alle pagina slugs op uit de sitemap
    $page_slugs = [];
    $sitemap_pages = [];
    if (!empty($sitemap_data['sitemap']) && is_array($sitemap_data['sitemap'])) {
      $sitemap_pages = $sitemap_data['sitemap'];
    } elseif (!empty($sitemap_data['pages']) && is_array($sitemap_data['pages'])) {
      $sitemap_pages = $sitemap_data['pages'];
    }
    foreach ($sitemap_pages as $p) {
      $slug = $p['slug'] ?? $p['page_slug'] ?? $p['url'] ?? $p['path'] ?? '';
      $slug = sanitize_title($slug);
      if ($slug) $page_slugs[] = $slug;
    }
    if (empty($page_slugs)) {
      wp_send_json_success(['pages' => []]);
    }

    // Haal alle wireframe models op uit de database
    global $wpdb;
    $table = $wpdb->prefix . 'aisb_wireframes';
    $placeholders = implode(',', array_fill(0, count($page_slugs), '%s'));
    $query_args   = array_merge([$project_id, $sitemap_id], $page_slugs);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT page_slug, model_json FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug IN ({$placeholders})",
      ...$query_args
    ), ARRAY_A);

    // Zet de rows om in een slug-geïndexeerde map
    $models_by_slug = [];
    foreach (($rows ?: []) as $row) {
      $models_by_slug[$row['page_slug']] = json_decode($row['model_json'], true);
    }

    // Bouw het resultaat op per pagina (behoud volgorde van de sitemap)
    $result_pages = [];
    $total_media = 0;
    foreach ($page_slugs as $slug) {
      $model = $models_by_slug[$slug] ?? null;
      if (!$model || empty($model['sections'])) continue;
      $sections = [];
      foreach ($model['sections'] as $s) {
        $ai_id    = !empty($s['ai_wireframe_id']) ? (int) $s['ai_wireframe_id'] : 0;
        $tmpl_id  = !empty($s['bricks_template_id']) ? (int) $s['bricks_template_id'] : 0;

        // Count media elements in this section
        $post_id_for_schema = $ai_id ?: $tmpl_id;
        $media_count = 0;
        if ($post_id_for_schema) {
          $schema = $this->extract_content_schema($post_id_for_schema, $s['type'] ?? 'generic');
          if ($schema && !empty($schema['elements'])) {
            foreach ($schema['elements'] as $el) {
              if (($el['tag'] ?? '') === 'media') $media_count++;
            }
          }
        }
        $total_media += $media_count;

        $sections[] = [
          'type'               => $s['type'] ?? 'generic',
          'ai_wireframe_id'    => $ai_id,
          'bricks_template_id' => $tmpl_id,
          'layout_key'         => $s['layout_key'] ?? '',
          'media_count'        => $media_count,
        ];
      }
      $result_pages[] = [
        'slug'     => $slug,
        'title'    => $model['page']['title'] ?? ucfirst(str_replace('-', ' ', $slug)),
        'sections' => $sections,
      ];
    }

    wp_send_json_success(['pages' => $result_pages, 'total_media' => $total_media]);
  }

  /* ------------------- Helpers ------------------- */

  /**
   * Extract a content schema from an ai_wireframe post's Bricks element data.
   * Produces a { type, elements: [...] } object the JS skeleton renderer can use
   * with real AI-generated text instead of dummy placeholders.
   */
  private function extract_content_schema(int $post_id, string $section_type): ?array {
    $elements = get_post_meta($post_id, '_bricks_page_content_2', true);
    if (!is_array($elements) || empty($elements)) return null;

    $text_keys = ['text', 'title', 'subtitle', 'heading', 'content', 'description',
                  'label', 'buttonText', 'link_text', 'tag_line', 'quote', 'name'];

    $schema_elements = [];

    foreach ($elements as $node) {
      if (empty($node['settings'])) continue;
      $name = $node['name'] ?? '';
      $s    = $node['settings'];

      // Heading elements
      if (in_array($name, ['heading', 'post-title'], true) || !empty($s['tag']) && in_array($s['tag'] ?? '', ['h1','h2','h3','h4'])) {
        $txt = $this->first_text($s, ['text', 'title', 'heading', 'content']);
        if ($txt) {
          $tag_val = $s['tag'] ?? ($section_type === 'hero' ? 'h1' : 'h2');
          $schema_elements[] = ['tag' => $tag_val, 'text' => $txt];
        }
        continue;
      }

      // Text / rich-text / paragraph
      if (in_array($name, ['text', 'text-basic', 'rich-text', 'post-excerpt', 'post-content'], true)) {
        $txt = $this->first_text($s, ['text', 'content', 'description']);
        if ($txt) {
          // Strip HTML tags for clean preview
          $txt = wp_strip_all_tags($txt);
          if (mb_strlen($txt) > 200) $txt = mb_substr($txt, 0, 200) . '…';
          $schema_elements[] = ['tag' => 'p', 'text' => $txt];
        }
        continue;
      }

      // Buttons
      if (in_array($name, ['button', 'icon-button'], true)) {
        $txt = $this->first_text($s, ['text', 'label', 'buttonText', 'link_text']);
        if ($txt) {
          $schema_elements[] = ['tag' => 'button', 'text' => $txt];
        }
        continue;
      }

      // Images / video
      if (in_array($name, ['image', 'video', 'svg'], true)) {
        $schema_elements[] = ['tag' => 'media', 'text' => ucfirst($name)];
        continue;
      }

      // For any other element with text content, add as paragraph
      $txt = $this->first_text($s, $text_keys);
      if ($txt && mb_strlen(wp_strip_all_tags($txt)) > 5) {
        $clean = wp_strip_all_tags($txt);
        if (mb_strlen($clean) > 200) $clean = mb_substr($clean, 0, 200) . '…';
        // Determine tag based on content
        if (!empty($s['tag']) && in_array($s['tag'], ['h1','h2','h3','h4'])) {
          $schema_elements[] = ['tag' => $s['tag'], 'text' => $clean];
        } else {
          $schema_elements[] = ['tag' => 'p', 'text' => $clean];
        }
      }
    }

    if (empty($schema_elements)) return null;

    return [
      'type'     => $section_type,
      'elements' => $schema_elements,
    ];
  }

  /**
   * Return the first non-empty text value from a settings array, checking the given keys.
   */
  private function first_text(array $settings, array $keys): string {
    foreach ($keys as $k) {
      if (!empty($settings[$k]) && is_string($settings[$k])) {
        return trim($settings[$k]);
      }
    }
    return '';
  }

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

  /**
   * AJAX: Upload images to the WP Media Library.
   * Accepts multipart file uploads, returns image objects.
   */
  public function ajax_upload_images(): void {
    $this->require_login();
    // nonce sent as form field
    $nonce  = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $ok_sg  = $nonce && wp_verify_nonce($nonce, 'aisb_sg_nonce');
    $ok_core = $nonce && wp_verify_nonce($nonce, 'aisb_nonce_action');
    if (!$ok_sg && !$ok_core) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    if (empty($_FILES['images'])) {
      wp_send_json_error(['message' => 'No files uploaded.'], 400);
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $files = $_FILES['images'];
    $results = [];
    $count = is_array($files['name']) ? count($files['name']) : 1;

    for ($i = 0; $i < $count; $i++) {
      // Build a single-file $_FILES entry for media_handle_upload
      $single = [
        'name'     => is_array($files['name'])     ? $files['name'][$i]     : $files['name'],
        'type'     => is_array($files['type'])     ? $files['type'][$i]     : $files['type'],
        'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
        'error'    => is_array($files['error'])    ? $files['error'][$i]    : $files['error'],
        'size'     => is_array($files['size'])      ? $files['size'][$i]     : $files['size'],
      ];

      // Validate it's actually an image
      $check = wp_check_filetype($single['name']);
      if (empty($check['type']) || strpos($check['type'], 'image/') !== 0) {
        continue;
      }

      $_FILES['aisb_upload'] = $single;
      $attachment_id = media_handle_upload('aisb_upload', 0);
      if (is_wp_error($attachment_id)) {
        continue;
      }

      $thumb = wp_get_attachment_image_url($attachment_id, 'medium') ?: '';
      $full  = wp_get_attachment_image_url($attachment_id, 'large')  ?: wp_get_attachment_url($attachment_id);
      $alt   = get_post_meta($attachment_id, '_wp_attachment_image_alt', true) ?: '';

      $results[] = [
        'thumb'        => $thumb,
        'full'         => $full,
        'alt'          => $alt,
        'photographer' => 'Uploaded',
        'link'         => '',
        'uploaded'     => true,
        'attachment_id' => $attachment_id,
      ];
    }

    if (empty($results)) {
      wp_send_json_error(['message' => 'No valid images could be uploaded.'], 400);
    }

    wp_send_json_success(['images' => $results]);
  }

  private function current_page_has_shortcode(string $shortcode): bool {
    if (!is_singular()) return false;
    global $post;
    if (!$post || empty($post->post_content)) return false;
    return has_shortcode($post->post_content, $shortcode);
  }
}
