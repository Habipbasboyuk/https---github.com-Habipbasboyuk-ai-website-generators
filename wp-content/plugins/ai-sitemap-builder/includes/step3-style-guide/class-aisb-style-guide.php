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
    add_action('wp_ajax_aisb_get_wireframe_sections', [$this, 'ajax_get_wireframe_sections']);
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
    wp_enqueue_script(
      'aisb-style-guide',
      AISB_PLUGIN_URL . 'assets/style-guide.js',
      ['color-thief'],
      AISB_VERSION,
      true
    );
    // brug tussen serverphp en browser javascript
    wp_localize_script('aisb-style-guide', 'AISB_SG', [
      'ajaxUrl'    => admin_url('admin-ajax.php'),
      'nonce'      => wp_create_nonce('aisb_sg_nonce'),
      'coreNonce'  => wp_create_nonce('aisb_nonce_action'),
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
      <div class="aisb-sg-status" data-aisb-sg-status></div>

      <!-- Sub-tabs: Met Logo / Zonder Logo -->
      <div class="aisb-sg-subtabs" data-aisb-sg-subtabs>
        <button class="aisb-sg-subtab is-active" type="button" data-sg-mode="logo">With Logo</button>
        <button class="aisb-sg-subtab" type="button" data-sg-mode="manual">Without Logo</button>
      </div>

      <!-- Paneel: Met Logo -->
      <div class="aisb-sg-mode-panel" data-sg-panel="logo">
        <div class="aisb-sg-upload-zone" data-aisb-sg-dropzone>
          <input type="file" accept="image/*" data-aisb-sg-logo-input style="display:none;">
          <div class="aisb-sg-upload-placeholder" data-aisb-sg-upload-placeholder>
            <span>Drop your logo here or <a href="#" data-aisb-sg-browse>browse</a></span>
          </div>
          <img data-aisb-sg-logo-preview class="aisb-sg-logo-preview" style="display:none;" alt="Logo preview" crossorigin="anonymous">
        </div>
        <div class="aisb-sg-extracted" data-aisb-sg-extracted style="display:none;">
          <h4 style="margin:0 0 10px;">Extracted Colours</h4>
          <div class="aisb-sg-swatches" data-aisb-sg-extracted-swatches></div>
        </div>
      </div>

      <!-- Paneel: Zonder Logo -->
      <div class="aisb-sg-mode-panel" data-sg-panel="manual" style="display:none;">
        <label class="aisb-label">Primary colour</label>
        <div class="aisb-sg-color-picker-row">
          <input type="color" value="#4F46E5" data-aisb-sg-primary-picker>
          <input type="text" value="#4F46E5" data-aisb-sg-primary-hex class="aisb-sg-hex-input" maxlength="7" placeholder="#HEX">
        </div>
        <div class="aisb-sg-swatches" data-aisb-sg-manual-swatches style="margin-top:14px;"></div>
      </div>

      <div class="aisb-sg-layout">
        <section class="aisb-sg-section" id="aisb-sg-colours">
          <h3 class="aisb-sg-section-title">Colours</h3>
          <div class="aisb-sg-swatches" data-aisb-sg-swatches>
            <div class="aisb-sg-empty-state">No colours defined yet.</div>
          </div>
        </section>

        <section class="aisb-sg-section" id="aisb-sg-typography">
          <h3 class="aisb-sg-section-title">Typography</h3>
          <div class="aisb-sg-type-preview" data-aisb-sg-type>
            <div class="aisb-sg-empty-state">No typography defined yet.</div>
          </div>
        </section>

        <section class="aisb-sg-section" id="aisb-sg-components">
          <h3 class="aisb-sg-section-title">Components</h3>
          <div class="aisb-sg-components" data-aisb-sg-components>
            <div class="aisb-sg-empty-state">No components defined yet.</div>
          </div>
        </section>
      </div>

      <!-- Live Preview (dynamisch gevuld vanuit wireframe-secties) -->
      <section class="aisb-sg-section" id="aisb-sg-preview" style="margin-top:28px;">
        <h3 class="aisb-sg-section-title">Live Preview</h3>
        <div class="aisb-sg-live-preview" data-aisb-sg-preview>
          <div class="aisb-sg-empty-state">Loading wireframe preview…</div>
        </div>
      </section>

      <div class="aisb-sg-actions" style="margin-top:20px;">
        <button class="aisb-btn" type="button" data-aisb-sg-generate>Generate Style Guide</button>
        <button class="aisb-btn-secondary" type="button" data-aisb-sg-save>Save &amp; Lock</button>
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

    // Soms genereert de ai een 'sitemap' veld en soms een 'pages' veld.Uiteindelijk willen we de slug van de eerste pagina (homepage) hebben om de juiste wireframe op te halen.
    $pages = [];
    if (!empty($sitemap_data['sitemap']) && is_array($sitemap_data['sitemap'])) {
      $pages = $sitemap_data['sitemap'];
    } elseif (!empty($sitemap_data['pages']) && is_array($sitemap_data['pages'])) {
      $pages = $sitemap_data['pages'];
    }

    // Pak de eerste pagina slug (homepage)
    $page_slug = '';
    if (!empty($pages)) {
      $first = $pages[0];
      $page_slug = $first['slug'] ?? $first['page_slug'] ?? $first['url'] ?? $first['path'] ?? '';
      $page_slug = sanitize_title($page_slug);
    }
    if (!$page_slug) {
      wp_send_json_success(['sections' => []]);
    }

    // Haal wireframe model op uit de database
    global $wpdb;
    $table = $wpdb->prefix . 'aisb_wireframes';
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT model_json FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug=%s",
      $project_id, $sitemap_id, $page_slug
    ), ARRAY_A);

    if (!$row || empty($row['model_json'])) {
      wp_send_json_success(['sections' => []]);
    }

    $model = json_decode($row['model_json'], true);
    $sections = [];
    if (!empty($model['sections']) && is_array($model['sections'])) {
      // Stuur alleen de relevante velden mee (type, preview_schema)
      foreach ($model['sections'] as $s) {
        $sections[] = [
          'type'           => $s['type'] ?? 'generic',
          'preview_schema' => $s['preview_schema'] ?? null,
          'layout_key'     => $s['layout_key'] ?? '',
        ];
      }
    }

    wp_send_json_success(['sections' => $sections, 'page_title' => $model['page']['title'] ?? ucfirst($page_slug)]);
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
