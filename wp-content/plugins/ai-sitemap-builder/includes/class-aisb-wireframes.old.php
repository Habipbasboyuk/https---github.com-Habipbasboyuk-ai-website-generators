<?php

if (!defined('ABSPATH')) exit;

class AISB_Wireframes {

  /** @var AISB_Template_Library */
  private $tpl_lib;
  /** @var AISB_Wireframe_Compiler */
  private $compiler;

  public function __construct(AISB_Template_Library $tpl_lib, AISB_Wireframe_Compiler $compiler) {
    $this->tpl_lib = $tpl_lib;
    $this->compiler = $compiler;
  }

  public function init(): void {
    $this->tpl_lib->init();

    // Shortcode + assets
    add_action('init', [$this, 'register_shortcode']);
    add_action('template_redirect', [$this, 'render_bricks_preview']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    // AJAX
    add_action('wp_ajax_aisb_get_wireframe_page', [$this, 'ajax_get_wireframe_page']);
    add_action('wp_ajax_aisb_generate_wireframe_page', [$this, 'ajax_generate_wireframe_page']);
    add_action('wp_ajax_aisb_update_wireframe_page', [$this, 'ajax_update_wireframe_page']);
    add_action('wp_ajax_aisb_shuffle_section_layout', [$this, 'ajax_shuffle_section_layout']);
    add_action('wp_ajax_aisb_replace_section_type', [$this, 'ajax_replace_section_type']);
    add_action('wp_ajax_aisb_compile_wireframe_page', [$this, 'ajax_compile_wireframe_page']);
    add_action('wp_ajax_aisb_get_bricks_section_types', [$this, 'ajax_get_bricks_section_types']);
    add_action('wp_ajax_aisb_save_section_text', [$this, 'ajax_save_section_text']);
  }

  public function register_shortcode(): void {
    add_shortcode('ai_wireframes', [$this, 'render_shortcode']);
  }

  public function render_bricks_preview(): void {
    if (!isset($_GET['aisb_bricks_preview'])) return;
    if (!is_user_logged_in()) {
      wp_die('Unauthorized');
    }
    $id = (int)$_GET['aisb_bricks_preview'];
    if (!$id) wp_die('No valid Bricks ID provided');

    // Load Bricks assets effectively for shortcodes in generic contexts
    if (function_exists('bricks_enqueue_scripts')) {
      bricks_enqueue_scripts();
    }
    if (class_exists('\Bricks\Assets')) {
      \Bricks\Assets::generate_inline_css();
    }

    show_admin_bar(false);
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
      <meta charset="<?php bloginfo('charset'); ?>">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <?php wp_head(); ?>
      <style>
        html, body {
          margin: 0 !important;
          padding: 0 !important;
          background: transparent !important;
          overflow: hidden !important;
        }
        .aisb-bricks-preview-wrap {
          pointer-events: none;
        }
        /* Edit mode: re-enable interaction, highlight editable elements */
        body.aisb-edit-mode .aisb-bricks-preview-wrap {
          pointer-events: auto;
        }
        body.aisb-edit-mode [contenteditable="true"] {
          outline: 2px dashed rgba(59,130,246,.5);
          outline-offset: 2px;
          cursor: text;
          min-height: 1em;
        }
        body.aisb-edit-mode [contenteditable="true"]:focus {
          outline: 2px solid rgba(59,130,246,.8);
          background: rgba(59,130,246,.04);
        }
      </style>
    </head>
    <body <?php body_class(); ?>>
      <div class="aisb-bricks-preview-wrap" id="aisb-preview">
        <?php echo do_shortcode('[bricks_template id="' . $id . '"]'); ?>
      </div>
      <?php wp_footer(); ?>
      <script>
      (function() {
        var TEXT_TAGS = ['H1','H2','H3','H4','H5','H6','P','SPAN','A','LI','BUTTON','LABEL','BLOCKQUOTE','TD','TH','FIGCAPTION','LEGEND'];
        var originalTexts = {}; // Store original text per element index

        function reportHeight() {
          var wrap = document.getElementById('aisb-preview');
          var h = wrap ? wrap.getBoundingClientRect().height : document.body.scrollHeight;
          window.parent.postMessage({ type: 'aisb_iframe_height', height: h }, '*');
        }
        window.addEventListener('load', reportHeight);
        if (window.ResizeObserver) {
          new ResizeObserver(reportHeight).observe(document.body);
        }
        setTimeout(reportHeight, 500);
        setTimeout(reportHeight, 2000);

        // Listen for messages from parent (edit mode toggle, content extraction)
        window.addEventListener('message', function(e) {
          if (!e.data || !e.data.type) return;

          if (e.data.type === 'aisb_enable_edit') {
            document.body.classList.add('aisb-edit-mode');
            document.body.style.overflow = 'auto';
            originalTexts = {};
            // Make text elements editable and tag them for tracking
            var wrap = document.getElementById('aisb-preview');
            var els = wrap.querySelectorAll(TEXT_TAGS.join(','));
            var editIdx = 0;
            els.forEach(function(el) {
              var text = (el.textContent || '').trim();
              if (text.length > 0 && text.length < 2000 && !el.querySelector(TEXT_TAGS.join(','))) {
                el.setAttribute('contenteditable', 'true');
                el.setAttribute('spellcheck', 'false');
                el.setAttribute('data-aisb-edit-idx', editIdx);
                originalTexts[editIdx] = el.innerHTML;
                editIdx++;
              }
            });
            reportHeight();
          }

          if (e.data.type === 'aisb_disable_edit') {
            document.body.classList.remove('aisb-edit-mode');
            document.body.style.overflow = 'hidden';
            var editables = document.querySelectorAll('[contenteditable="true"]');
            editables.forEach(function(el) {
              el.removeAttribute('contenteditable');
              el.removeAttribute('spellcheck');
            });
            reportHeight();
          }

          if (e.data.type === 'aisb_get_edited_content') {
            // Collect changed texts: compare current innerHTML vs original
            var changes = [];
            var editables = document.querySelectorAll('[data-aisb-edit-idx]');
            editables.forEach(function(el) {
              var idx = el.getAttribute('data-aisb-edit-idx');
              var current = el.innerHTML;
              var original = originalTexts[idx] || '';
              if (current !== original) {
                changes.push({
                  original: original,
                  updated: current
                });
              }
            });
            window.parent.postMessage({
              type: 'aisb_edited_content',
              changes: changes
            }, '*');
          }
        });
      })();
      </script>
    </body>
    </html>
    <?php
    exit;
  }

  public function enqueue_assets(): void {
    // IMPORTANT: Do not rely only on has_shortcode(). Many sites (incl. Bricks) render shortcodes
    // via templates/elements, where the raw post_content does not contain the shortcode string.
    // Result: assets wouldn't enqueue and the UI stays empty (AISB_WF undefined).

    $is_step2 = ((int)($_GET['aisb_step'] ?? 0) === 2);
    $has_project_ctx = isset($_GET['aisb_project']) && isset($_GET['aisb_sitemap']);

    // Enqueue when:
    // - Dedicated shortcode is present, OR
    // - Step 2 is requested via URL params (builder tab), OR
    // - The main builder shortcode is present (fallback).
    $is_wireframes_shortcode = $this->current_page_has_shortcode('ai_wireframes');
    $is_builder_shortcode = $this->current_page_has_shortcode('ai_sitemap_builder');
    $is_step2_in_builder = $is_step2 && $has_project_ctx;

    if (!$is_wireframes_shortcode && !$is_step2_in_builder && !$is_builder_shortcode) return;

    wp_register_style('aisb-wireframes-style', false, [], AISB_VERSION);
    wp_enqueue_style('aisb-wireframes-style');
    wp_add_inline_style('aisb-wireframes-style', $this->css());

    wp_register_script('aisb-wireframes', false, [], AISB_VERSION, true);
    wp_enqueue_script('aisb-wireframes');
    wp_localize_script('aisb-wireframes', 'AISB_WF', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'previewUrl' => home_url('/?aisb_bricks_preview='),
      // nonce: used for wireframes endpoints.
      'nonce'   => wp_create_nonce('aisb_wf_nonce'),
      // coreNonce: used when calling existing AISB AJAX endpoints that validate against AISB_Plugin::NONCE_ACTION.
      'coreNonce' => wp_create_nonce('aisb_nonce_action'),
      'patterns'     => $this->patterns(),
      'sectionTypes' => $this->get_all_section_types(),
    ]);
    wp_add_inline_script('aisb-wireframes', $this->js(), 'after');
  }

  public function render_shortcode($atts = [], $content = null): string {
    if (!is_user_logged_in()) {
      return '<div class="aisb-wrap"><div class="aisb-card"><p>You must be logged in to use wireframes.</p></div></div>';
    }

    $project_id = isset($_GET['aisb_project']) ? (int)$_GET['aisb_project'] : 0;
    $sitemap_id = isset($_GET['aisb_sitemap']) ? (int)$_GET['aisb_sitemap'] : 0;

    ob_start();
    ?>
    <div class="aisb-wrap" data-aisb-wireframes
         data-project-id="<?php echo esc_attr($project_id); ?>"
         data-sitemap-id="<?php echo esc_attr($sitemap_id); ?>">
      <div class="aisb-card">
        <div class="aisb-wf-head">
          <div>
            <h2 class="aisb-title" style="margin:0;">Wireframes</h2>
            <p class="aisb-subtitle" style="margin-top:6px;">Relume-like preview · Brixies sections · fast skeleton rendering</p>
          </div>
          <div class="aisb-wf-top-actions">
            <a class="aisb-btn-secondary" href="<?php echo esc_url(remove_query_arg(['aisb_step'])); ?>">Back to sitemap</a>
          </div>
        </div>

        <?php if (!$project_id || !$sitemap_id) : ?>
          <div style="background: #fafafa; border: 1px solid #e6e6e6; border-radius: 12px; padding: 24px; text-align: center; margin-top: 14px;">
            <p class="aisb-wf-muted" style="margin-top:0; margin-bottom: 24px; font-size: 15px;">Please select one of your projects below to start generating wireframes.</p>
            <div style="text-align: left; max-width: 800px; margin: 0 auto;">
              <?php echo do_shortcode('[my-projects title=""]'); ?>
            </div>
          </div>
        <?php else : ?>
        <div class="aisb-wf-layout">
          <div class="aisb-wf-pages">
            <div class="aisb-wf-pages-head">
              <strong>Pages</strong>
              <span class="aisb-wf-muted" data-aisb-wf-pages-meta></span>
            </div>
            <div class="aisb-wf-pages-list" data-aisb-wf-pages></div>
          </div>

          <div class="aisb-wf-canvas">
            <div class="aisb-wf-canvas-head">
              <div>
                <div class="aisb-wf-canvas-title" data-aisb-wf-page-title>Select a page</div>
                <div class="aisb-wf-muted" data-aisb-wf-page-sub>Generate a wireframe to start editing.</div>
              </div>
              <div class="aisb-wf-actions">
                <select data-aisb-wf-pattern class="aisb-select" style="min-width:220px;"></select>
                <button class="aisb-btn-secondary" type="button" data-aisb-wf-generate>Generate wireframe</button>
                <button class="aisb-btn" style="background:#0b6b2f;" type="button" data-aisb-wf-generate-all>Generate all</button>
                <button class="aisb-btn-secondary" type="button" data-aisb-wf-shuffle-page>Shuffle unlocked</button>
                <button class="aisb-btn" type="button" data-aisb-wf-save>Save</button>
                <button class="aisb-btn-secondary" type="button" data-aisb-wf-compile>Compile JSON</button>
              </div>
            </div>

            <div class="aisb-wf-status" data-aisb-wf-status></div>
            <div class="aisb-wf-sections" data-aisb-wf-sections></div>

            <details class="aisb-wf-raw">
              <summary>Compiled Bricks JSON (latest)</summary>
              <pre class="aisb-pre" data-aisb-wf-compiled></pre>
            </details>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  /* ------------------- Data access ------------------- */

  private function table_wireframes(): string {
    global $wpdb;
    return $wpdb->prefix . 'aisb_wireframes';
  }

  private function require_login(): void {
    if (!is_user_logged_in()) {
      wp_send_json_error(['message' => 'Not logged in'], 401);
    }
  }

  private function check_nonce(): void {
    // We accept both the dedicated wireframes nonce and the plugin-wide nonce.
    // Reason: the wireframes UI reuses existing AISB AJAX endpoints (e.g. fetch sitemap)
    // which verify against AISB_Plugin::NONCE_ACTION.
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $ok_wf = $nonce && wp_verify_nonce($nonce, 'aisb_wf_nonce');
    // Core nonce action is defined in AISB_Plugin::NONCE_ACTION (currently 'aisb_nonce_action').
    $ok_core = $nonce && wp_verify_nonce($nonce, 'aisb_nonce_action');
    if (!$ok_wf && !$ok_core) {
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

  private function get_or_create_wireframe_row(int $project_id, int $sitemap_version_id, string $page_slug): array {
    global $wpdb;
    $table = $this->table_wireframes();
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug=%s",
      $project_id, $sitemap_version_id, $page_slug
    ), ARRAY_A);
    if ($row) return $row;

    $now = current_time('mysql');
    $model = [
      'page' => ['slug' => $page_slug, 'title' => ucfirst(str_replace('-', ' ', $page_slug))],
      'pattern' => 'generic',
      'sections' => [],
    ];
    $wpdb->insert($table, [
      'project_id' => $project_id,
      'sitemap_version_id' => $sitemap_version_id,
      'page_slug' => $page_slug,
      'model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES),
      'compiled_bricks_json' => null,
      'created_by' => get_current_user_id(),
      'created_at' => $now,
      'updated_at' => $now,
    ], ['%d','%d','%s','%s','%s','%d','%s','%s']);

    $id = (int)$wpdb->insert_id;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A) ?: [];
  }

  /* ------------------- AJAX endpoints ------------------- */

  public function ajax_get_wireframe_page(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug) wp_send_json_error(['message' => 'Missing params'], 400);

    $row = $this->get_or_create_wireframe_row($project_id, $sitemap_version_id, $page_slug);
    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) $model = [];
    wp_send_json_success(['wireframe' => $model]);
  }

  public function ajax_generate_wireframe_page(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $pattern = isset($_POST['pattern']) ? sanitize_key(wp_unslash($_POST['pattern'])) : 'generic';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug) wp_send_json_error(['message' => 'Missing params'], 400);

    $types = $this->patterns()[$pattern] ?? $this->patterns()['generic'];
    $model = [
      'page' => ['slug' => $page_slug, 'title' => ucfirst(str_replace('-', ' ', $page_slug))],
      'pattern' => $pattern,
      'sections' => [],
    ];

    $bricks_by_type   = $this->get_bricks_templates_by_type();
    $used_bricks_ids  = [];
    $used_layout_keys = [];

    foreach ($types as $t) {
      // --- Primary: pick from Bricks template library ---
      $bricks_tpl = $this->pick_bricks_template($t, $bricks_by_type, $used_bricks_ids);

      if ($bricks_tpl) {
        $used_bricks_ids[] = (int) $bricks_tpl['id'];
        $model['sections'][] = [
          'uuid'                  => wp_generate_uuid4(),
          'type'                  => $t,
          'layout_key'            => 'bricks_' . $bricks_tpl['id'],
          'bricks_template_id'    => $bricks_tpl['id'],
          'bricks_template_title' => $bricks_tpl['title'],
          'bricks_template_ttype' => $bricks_tpl['ttype'],
          'bricks_shortcode'      => $bricks_tpl['shortcode'],
          'locked'                => false,
          'preview_schema'        => null,
          'match_score'           => null,
          'match_tags'            => implode(', ', $bricks_tpl['tags']),
        ];
        continue;
      }

      // --- Fallback: custom template library (legacy) ---
      $min_complexity = null;
      if ($pattern === 'homepage' && $t === 'hero') $min_complexity = 40;

      $context = $this->build_selection_context($project_id, $sitemap_version_id, $page_slug, $t);
      $tpl = $this->tpl_lib->pick_best_match($t, $context, $used_layout_keys, $min_complexity);

      $layout_key = $tpl && !empty($tpl['layout_key'])
        ? (string) $tpl['layout_key']
        : ('cf_' . $t . '_section_1');

      if ($tpl && !empty($tpl['layout_key'])) {
        $used_layout_keys[] = (string) $tpl['layout_key'];
      }

      $preview_schema = null;
      if ($tpl && isset($tpl['preview_schema'])) {
        $decoded = json_decode((string) $tpl['preview_schema'], true);
        $preview_schema = is_array($decoded) ? $decoded : null;
      }

      $model['sections'][] = [
        'uuid'                  => wp_generate_uuid4(),
        'type'                  => $t,
        'layout_key'            => $layout_key,
        'bricks_template_id'    => null,
        'bricks_template_title' => null,
        'bricks_template_ttype' => null,
        'bricks_shortcode'      => null,
        'locked'                => false,
        'preview_schema'        => $preview_schema,
        'match_score'           => $tpl && isset($tpl['_match_score']) ? round((float) $tpl['_match_score'], 2) : null,
        'match_tags'            => $tpl && !empty($tpl['tags']) ? (string) $tpl['tags'] : '',
      ];
    }

    $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, true);

    // --- AI Population ---
      $model = $this->populate_bricks_content_with_ai($model, $project_id, $sitemap_version_id, $page_slug);
      $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, true);
    wp_send_json_success(['wireframe' => $model]);
  }

  private function populate_bricks_content_with_ai(array $model, int $project_id, int $sitemap_version_id, string $page_slug): array {
    $brief = (string) get_post_meta($project_id, 'aisb_project_brief', true);
    $sitemap_json = get_post_meta($sitemap_version_id, 'aisb_sitemap_json', true);
    $sitemap_data = json_decode((string)$sitemap_json, true) ?: [];

    $sections_with_id = array_filter($model['sections'] ?? [], fn($s) => !empty($s['bricks_template_id']));
    error_log('[AISB] populate_bricks_content_with_ai START: page=' . $page_slug . ' total_sections=' . count($model['sections'] ?? []) . ' sections_with_bricks_id=' . count($sections_with_id));
    
    // 1. Zoek de juiste pagina info in de sitemap
    $page_info = ['page_title' => $page_slug, 'sections' => []];
    $sitemap_array = array_merge($sitemap_data['sitemap'] ?? [], $sitemap_data['pages'] ?? []);
    foreach($sitemap_array as $p) {
        if (!is_array($p)) continue;
        $slug = $this->normalize_slug((string)($p['slug'] ?? $p['page_slug'] ?? ''));
        if ($slug === $this->normalize_slug($page_slug)) {
            $page_info = $p;
            break;
        }
    }
    
    $page_title = $page_info['page_title'] ?? $page_info['nav_label'] ?? $page_slug;
    $sitemap_sections = $page_info['sections'] ?? [];
    $text_keys = ['text', 'title', 'subtitle', 'description', 'heading', 'content', 'label'];
    
    $to_translate = [];
    $raw_bricks_data_maps = [];

    // 2. Verzamel alle tekst uit de gekozen wireframe templates
    foreach ($model['sections'] as $idx => $sec) {
        if (empty($sec['bricks_template_id'])) {
            error_log('[AISB] Section ' . $idx . ' (type=' . ($sec['type'] ?? '?') . ') has no bricks_template_id — skipped');
            continue;
        }
        
        $post_id = (int) $sec['bricks_template_id'];
        // Bricks stores template elements in _bricks_page_content_2 (BRICKS_DB_PAGE_CONTENT)
        // _bricks_data is the raw editor string (JSON) and is NOT what Bricks renders from.
        $bricks_data = get_post_meta($post_id, '_bricks_page_content_2', true);
        if (!is_array($bricks_data)) {
            // Fallback: try _bricks_page_header_2, _bricks_page_footer_2
            $bricks_data = get_post_meta($post_id, '_bricks_page_header_2', true);
        }
        if (!is_array($bricks_data)) {
            $bricks_data = get_post_meta($post_id, '_bricks_page_footer_2', true);
        }
        if (!is_array($bricks_data)) {
            error_log('[AISB] Section ' . $idx . ': No valid Bricks element data found for post ' . $post_id . ' — skipped');
            continue;
        }
        error_log('[AISB] Section ' . $idx . ': loaded Bricks elements for post ' . $post_id . ' (' . count($bricks_data) . ' nodes)');

        $raw_bricks_data_maps[$idx] = $bricks_data;
        $module_data_to_translate = [];

        foreach ($bricks_data as $node) {
            if (empty($node['settings'])) continue;
            
            $extracted = [];
            foreach ($text_keys as $tk) {
                if (isset($node['settings'][$tk]) && is_string($node['settings'][$tk])) {
                    $val = trim($node['settings'][$tk]);
                    // We pakken alleen tekst met inhoud, negeer lege velden of CSS vars
                    if (strlen(wp_strip_all_tags($val)) > 0 && strpos($val, 'var(') === false) {
                        $extracted[$tk] = $val;
                    }
                }
            }

            if (!empty($extracted)) {
                $module_data_to_translate[$node['id']] = [
                    'type' => $node['name'] ?? 'block',
                    'settings' => $extracted
                ];
            }
        }

        if (!empty($module_data_to_translate)) {
            $to_translate["section_{$idx}"] = [
                'purpose' => $sec['type'] ?? 'content',
                'modules' => $module_data_to_translate
            ];
        }
    }

    if (empty($to_translate)) {
        error_log('[AISB] to_translate is EMPTY — no sections with _bricks_data found. Returning model unchanged.');
        return $model;
    }

    error_log('[AISB] to_translate has ' . count($to_translate) . ' section(s) — sending to OpenAI');

    // 3. Stuur de hele buit naar OpenAI
    $prompt = "You are a copywriter. Replace the placeholder texts in the following JSON with professional copy for: $brief. \n";
    $prompt .= "Page: $page_title. Return ONLY the JSON with updated 'settings' values.";
    $prompt .= "\n\nTarget JSON:\n" . wp_json_encode($to_translate);

    $settings = get_option('aisb_settings', []);
    $openai = new \AISB_OpenAI();
    $res = $openai->call_openai_chat_completions($prompt, $settings, "Return valid JSON only. No markdown. No explanation.");

    if (is_wp_error($res)) {
        error_log('[AISB] OpenAI returned WP_Error: ' . $res->get_error_message());
        return $model;
    }

    $translated = json_decode($this->clean_json_response($res), true);
    if (!is_array($translated)) {
        error_log('[AISB] OpenAI response could not be parsed as JSON. Raw: ' . substr((string)$res, 0, 500));
        return $model;
    }
    error_log('[AISB] OpenAI returned ' . count($translated) . ' translated section(s)');

    // 4. Maak de nieuwe templates aan met de AI tekst
    foreach ($translated as $sec_key => $data) {
        $idx = (int) str_replace('section_', '', $sec_key);
        if (!isset($raw_bricks_data_maps[$idx])) continue;
        $original_id = (int)($model['sections'][$idx]['bricks_template_id'] ?? 0);
        $cloned_data = $raw_bricks_data_maps[$idx];

        // Vervang de tekst in de gekloonde data
        foreach ($cloned_data as &$node) {
            $nid = $node['id'];
            if (isset($data['modules'][$nid]['settings'])) {
                foreach ($data['modules'][$nid]['settings'] as $key => $new_text) {
                    $node['settings'][$key] = $new_text;
                }
            }
        }
        unset($node);

        // Maak een NIEUWE template post aan in WordPress
        $new_post_id = wp_insert_post([
            'post_title'  => "[AI] " . $page_title . " - Section " . $idx,
            'post_type'   => 'bricks_template',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($new_post_id) || !$new_post_id) {
            error_log('[AISB] wp_insert_post FAILED for section ' . $idx . ': ' . (is_wp_error($new_post_id) ? $new_post_id->get_error_message() : 'returned 0'));
        } else {
            // Save elements to _bricks_page_content_2 — this is the meta key Bricks
            // actually reads when rendering [bricks_template id="..."].
            update_post_meta($new_post_id, '_bricks_page_content_2', $cloned_data);

            // Haal het type van de originele template op en neem dit over
            $original_type = $original_id ? get_post_meta($original_id, '_bricks_template_type', true) : '';
            if (!$original_type) {
                $original_type = 'section'; // Fallback
            }
            update_post_meta($new_post_id, '_bricks_template_type', $original_type);

            // Neem ook eventuele Bricks taxonomy terms over (template_type, template_tag)
            $taxonomies = ['template_type', 'template_tag'];
            foreach ($taxonomies as $tax) {
                $terms = $original_id ? wp_get_object_terms($original_id, $tax, ['fields' => 'slugs']) : [];
                if (!empty($terms) && !is_wp_error($terms)) {
                    wp_set_object_terms($new_post_id, $terms, $tax);
                }
            }

            // Koppel het nieuwe ID aan je model zodat Bricks deze sectie laadt
            $model['sections'][$idx]['bricks_template_id'] = $new_post_id;
            $model['sections'][$idx]['bricks_shortcode'] = '[bricks_template id="' . $new_post_id . '"]';
            error_log('[AISB] Created AI Bricks template post ID=' . $new_post_id . ' for section ' . $idx . ' (type=' . $original_type . ')');
        }
    }

    return $model;
}

// Hulpfunctie om Markdown blokken van AI te strippen
private function clean_json_response($string) {
    return preg_replace('/^```json|```$/m', '', trim($string));
}

  /**
   * AJAX: Save inline-edited text back to a Bricks template post.
   * Receives: bricks_template_id, changes (JSON array of {original, updated} pairs)
   * Matches original text against element settings and replaces with updated text.
   */
  public function ajax_save_section_text(): void {
    $this->require_login();
    $this->check_nonce();

    $template_id = isset($_POST['bricks_template_id']) ? (int)$_POST['bricks_template_id'] : 0;
    $changes_raw = isset($_POST['changes']) ? wp_unslash($_POST['changes']) : '';
    $changes     = json_decode($changes_raw, true);

    if (!$template_id || !is_array($changes) || empty($changes)) {
      wp_send_json_error(['message' => 'Missing template_id or changes'], 400);
    }

    $post = get_post($template_id);
    if (!$post || $post->post_type !== 'bricks_template') {
      wp_send_json_error(['message' => 'Template not found'], 404);
    }

    // Load current Bricks elements
    $elements = get_post_meta($template_id, '_bricks_page_content_2', true);
    if (!is_array($elements)) {
      wp_send_json_error(['message' => 'No Bricks elements found for this template'], 400);
    }

    $text_keys = ['text', 'title', 'subtitle', 'description', 'heading', 'content', 'label'];
    $changed = 0;

    // For each change, find the Bricks element whose text content matches the original
    foreach ($changes as $change) {
      if (!isset($change['original']) || !isset($change['updated'])) continue;
      $original = $change['original'];
      $updated  = $change['updated'];
      if ($original === $updated) continue;

      // Strip HTML tags for comparison (Bricks stores raw HTML in settings)
      $original_text = trim(strip_tags($original));

      foreach ($elements as &$node) {
        if (empty($node['settings'])) continue;
        foreach ($text_keys as $key) {
          if (!isset($node['settings'][$key]) || !is_string($node['settings'][$key])) continue;
          $setting_text = trim(strip_tags($node['settings'][$key]));
          if ($setting_text === $original_text) {
            $node['settings'][$key] = wp_kses_post($updated);
            $changed++;
            break 2; // This change is applied, move to next change
          }
        }
      }
      unset($node);
    }

    if ($changed > 0) {
      update_post_meta($template_id, '_bricks_page_content_2', $elements);
    }

    wp_send_json_success(['changed' => $changed, 'template_id' => $template_id]);
  }

  public function ajax_update_wireframe_page(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $model_raw = isset($_POST['model_json']) ? wp_unslash($_POST['model_json']) : '';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug) wp_send_json_error(['message' => 'Missing params'], 400);
    $model = json_decode($model_raw, true);
    if (!is_array($model)) wp_send_json_error(['message' => 'Invalid model_json'], 400);

    $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, false);
    wp_send_json_success(['ok' => 1]);
  }

  public function ajax_shuffle_section_layout(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $uuid = isset($_POST['uuid']) ? sanitize_text_field(wp_unslash($_POST['uuid'])) : '';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug || !$uuid) wp_send_json_error(['message' => 'Missing params'], 400);

    $row = $this->get_or_create_wireframe_row($project_id, $sitemap_version_id, $page_slug);
    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) $model = [];

    $used_bricks_ids  = [];
    $used_layout_keys = [];
    foreach (($model['sections'] ?? []) as $s) {
      if (!is_array($s)) continue;
      if (!empty($s['bricks_template_id'])) $used_bricks_ids[] = (int) $s['bricks_template_id'];
      if (!empty($s['layout_key'])) $used_layout_keys[] = (string) $s['layout_key'];
    }

    $bricks_by_type = $this->get_bricks_templates_by_type();

    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s)) continue;
      if (($s['uuid'] ?? '') !== $uuid) continue;
      if (!empty($s['locked'])) wp_send_json_error(['message' => 'Section is locked'], 400);
      $type = (string) ($s['type'] ?? 'generic');

      // Primary: shuffle within Bricks templates
      $bricks_tpl = $this->pick_bricks_template($type, $bricks_by_type, $used_bricks_ids);
      if ($bricks_tpl) {
        $model['sections'][$i]['layout_key']            = 'bricks_' . $bricks_tpl['id'];
        $model['sections'][$i]['bricks_template_id']    = $bricks_tpl['id'];
        $model['sections'][$i]['bricks_template_title'] = $bricks_tpl['title'];
        $model['sections'][$i]['bricks_template_ttype'] = $bricks_tpl['ttype'];
        $model['sections'][$i]['bricks_shortcode']      = $bricks_tpl['shortcode'];
        $model['sections'][$i]['preview_schema']        = null;
        $model['sections'][$i]['match_tags']            = implode(', ', $bricks_tpl['tags']);
        break;
      }

      // Fallback: custom library
      $context = $this->build_selection_context($project_id, $sitemap_version_id, $page_slug, $type);
      $tpl = $this->tpl_lib->pick_best_match($type, $context, $used_layout_keys, null);
      $model['sections'][$i]['layout_key']            = $tpl ? (string) $tpl['layout_key'] : ('cf_' . $type . '_1');
      $model['sections'][$i]['bricks_template_id']    = null;
      $model['sections'][$i]['bricks_template_title'] = null;
      $model['sections'][$i]['bricks_template_ttype'] = null;
      $model['sections'][$i]['bricks_shortcode']      = null;
      $model['sections'][$i]['preview_schema']        = $tpl ? json_decode((string) ($tpl['preview_schema'] ?? '{}'), true) : null;
      $model['sections'][$i]['match_score']           = $tpl && isset($tpl['_match_score']) ? round((float) $tpl['_match_score'], 2) : null;
      $model['sections'][$i]['match_tags']            = $tpl && !empty($tpl['tags']) ? (string) $tpl['tags'] : '';
      break;
    }

    $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, true);
    wp_send_json_success(['wireframe' => $model]);
  }

  public function ajax_replace_section_type(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $uuid = isset($_POST['uuid']) ? sanitize_text_field(wp_unslash($_POST['uuid'])) : '';
    $new_type = isset($_POST['new_type']) ? sanitize_key(wp_unslash($_POST['new_type'])) : '';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug || !$uuid || !$new_type) wp_send_json_error(['message' => 'Missing params'], 400);

    $row = $this->get_or_create_wireframe_row($project_id, $sitemap_version_id, $page_slug);
    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) $model = [];

    $used_bricks_ids  = [];
    $used_layout_keys = [];
    foreach (($model['sections'] ?? []) as $s) {
      if (!is_array($s)) continue;
      if (!empty($s['bricks_template_id'])) $used_bricks_ids[] = (int) $s['bricks_template_id'];
      if (!empty($s['layout_key'])) $used_layout_keys[] = (string) $s['layout_key'];
    }

    $bricks_by_type = $this->get_bricks_templates_by_type();

    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s)) continue;
      if (($s['uuid'] ?? '') !== $uuid) continue;

      $model['sections'][$i]['type'] = $new_type;

      // Primary: Bricks templates
      $bricks_tpl = $this->pick_bricks_template($new_type, $bricks_by_type, $used_bricks_ids);
      if ($bricks_tpl) {
        $model['sections'][$i]['layout_key']            = 'bricks_' . $bricks_tpl['id'];
        $model['sections'][$i]['bricks_template_id']    = $bricks_tpl['id'];
        $model['sections'][$i]['bricks_template_title'] = $bricks_tpl['title'];
        $model['sections'][$i]['bricks_template_ttype'] = $bricks_tpl['ttype'];
        $model['sections'][$i]['bricks_shortcode']      = $bricks_tpl['shortcode'];
        $model['sections'][$i]['preview_schema']        = null;
        $model['sections'][$i]['match_score']           = null;
        $model['sections'][$i]['match_tags']            = implode(', ', $bricks_tpl['tags']);
        break;
      }

      // Fallback: custom library
      $context = $this->build_selection_context($project_id, $sitemap_version_id, $page_slug, $new_type);
      $tpl = $this->tpl_lib->pick_best_match($new_type, $context, $used_layout_keys, null);
      $model['sections'][$i]['layout_key']            = $tpl ? (string) $tpl['layout_key'] : ('cf_' . $new_type . '_1');
      $model['sections'][$i]['bricks_template_id']    = null;
      $model['sections'][$i]['bricks_template_title'] = null;
      $model['sections'][$i]['bricks_template_ttype'] = null;
      $model['sections'][$i]['bricks_shortcode']      = null;
      $model['sections'][$i]['preview_schema']        = $tpl ? json_decode((string) ($tpl['preview_schema'] ?? '{}'), true) : null;
      $model['sections'][$i]['match_score']           = $tpl && isset($tpl['_match_score']) ? round((float) $tpl['_match_score'], 2) : null;
      $model['sections'][$i]['match_tags']            = $tpl && !empty($tpl['tags']) ? (string) $tpl['tags'] : '';
      break;
    }

    $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, true);
    wp_send_json_success(['wireframe' => $model]);
  }

  public function ajax_compile_wireframe_page(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug) wp_send_json_error(['message' => 'Missing params'], 400);

    $row = $this->get_or_create_wireframe_row($project_id, $sitemap_version_id, $page_slug);
    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) $model = [];
    $compiled = $this->compiler->compile_page($model);
    $compiled_json = wp_json_encode($compiled, JSON_UNESCAPED_SLASHES);

    global $wpdb;
    $table = $this->table_wireframes();
    $wpdb->update($table, [
      'compiled_bricks_json' => $compiled_json,
      'updated_at' => current_time('mysql'),
    ], [
      'project_id' => $project_id,
      'sitemap_version_id' => $sitemap_version_id,
      'page_slug' => $page_slug,
    ], ['%s','%s'], ['%d','%d','%s']);

    wp_send_json_success(['compiled' => $compiled]);
  }

  /* ------------------- Bricks template source ------------------- */

  /**
   * Query all published Bricks templates and group them by WordPress tag slug.
   * Falls back to `_bricks_template_type` meta when a template has no tags.
   * Result is statically cached per request.
   *
   * @return array<string, list<array{id:int,title:string,ttype:string,tags:string[],shortcode:string}>>
   */
  private function get_bricks_templates_by_type(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    if (!post_type_exists('bricks_template')) {
      $cache = [];
      return $cache;
    }

    $posts = get_posts([
      'post_type'      => 'bricks_template',
      'post_status'    => 'publish',
      'posts_per_page' => 500,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'no_found_rows'  => true,
    ]);

    $by_type = [];

    foreach ($posts as $post) {
      $id    = (int) $post->ID;

      // Accept templates that have Bricks content in _bricks_data (section templates)
      // OR in _bricks_page_content_2/_header_2/_footer_2 (full-page/header/footer templates).
      // Note: section templates store their content in _bricks_data, NOT in _bricks_page_content_2.
      $bricks_data_meta = get_post_meta($id, '_bricks_data', true);
      $content_2 = get_post_meta($id, '_bricks_page_content_2', true);
      $header_2  = get_post_meta($id, '_bricks_page_header_2', true);
      $footer_2  = get_post_meta($id, '_bricks_page_footer_2', true);

      if (empty($bricks_data_meta) && empty($content_2) && empty($header_2) && empty($footer_2)) {
        continue;
      }

      $title = (string) $post->post_title;
      // Skip corrupt AI copies from previous plugin versions just to be safe
      if (strpos($title, '[AI]') === 0) {
        continue;
      }

      $ttype = (string) (get_post_meta($id, '_bricks_template_type', true) ?: '');
        $tags_raw  = get_the_terms($id, 'template_tag');
        $tags = [];
        if (!empty($tags_raw) && !is_wp_error($tags_raw)) {
            $tags = wp_list_pluck($tags_raw, 'slug');
        }
      $type_keys = array_map('strtolower', (array) $tags);

      // Fallback: use Bricks template type when no tags are present.
      if (empty($type_keys) && $ttype !== '') {
        $type_keys = [strtolower($ttype)];
      }

      $entry = [
        'id'        => $id,
        'title'     => $title,
        'ttype'     => $ttype,
        'tags'      => $type_keys,
        'shortcode' => '[bricks_template id="' . $id . '"]',
      ];

      foreach ($type_keys as $key) {
        if (!isset($by_type[$key])) $by_type[$key] = [];
        $by_type[$key][] = $entry;
      }
    }

    $cache = $by_type;
    return $cache;
  }

  /**
   * Pick a random Bricks template for $section_type, respecting exclusions.
   * Falls back to configured aliases when no direct match exists.
   *
   * @param array<string,list<array>> $by_type     Output of get_bricks_templates_by_type()
   * @param int[]                     $exclude_ids Template IDs already used on this page
   */
  private function pick_bricks_template(string $section_type, array $by_type, array $exclude_ids = []): ?array {
    $try = function(string $key) use ($by_type, $exclude_ids): ?array {
      $pool = $by_type[strtolower($key)] ?? [];
      if ($exclude_ids) {
        $pool = array_values(array_filter($pool, function($t) use ($exclude_ids) {
          return !in_array((int) $t['id'], $exclude_ids, true);
        }));
      }
      return $pool ? $pool[array_rand($pool)] : null;
    };

    $tpl = $try($section_type);
    if ($tpl) return $tpl;

    foreach ($this->section_type_aliases()[$section_type] ?? [] as $alt) {
      $tpl = $try($alt);
      if ($tpl) return $tpl;
    }

      // 1st Fallback: Probeer algemene templates te gebruiken zoals 'content', 'features', of 'generic'
      foreach (['content', 'features', 'generic'] as $alt) {
        $tpl = $try($alt);
        if ($tpl) return $tpl;
      }

      // 2nd Fallback: Pak een willekeurige Bricks template uit je bibliotheek (uitgezonderd header/footer)
      $all_pool = [];
      foreach ($by_type as $k => $templates) {
        if (in_array(strtolower($k), ['header', 'footer'])) continue;
        foreach ($templates as $t) {
          if (!in_array((int) $t['id'], $exclude_ids, true)) {
            $all_pool[$t['id']] = $t;
          }
        }
      }
      if ($all_pool) {
        return $all_pool[array_rand($all_pool)];
      }

      // 3rd Fallback: If exhausted unique, ignore exclude_ids.
      $all_pool = [];
      foreach ($by_type as $k => $templates) {
        if (in_array(strtolower($k), ['header', 'footer'])) continue;
        foreach ($templates as $t) {
          $all_pool[$t['id']] = $t;
        }
      }
      if ($all_pool) {
        return $all_pool[array_rand($all_pool)];
      }

      return null;
    }

    /** Tag aliases so e.g. "features" also matches "feature", "services" etc. */
    private function section_type_aliases(): array {
      return [
        'features'     => ['feature', 'benefits', 'benefit', 'services', 'service'],
        'testimonials' => ['testimonial', 'reviews', 'review'],
        'cta'          => ['call-to-action', 'call_to_action'],
        'faq'          => ['faqs', 'questions'],
      'pricing'      => ['plans', 'plan', 'packages'],
      'social_proof' => ['logos', 'clients', 'brands', 'partners', 'trust'],
      'team'         => ['staff', 'people'],
      'contact_form' => ['contact', 'form', 'contact-form'],
      'header'       => ['nav', 'navigation', 'navbar', 'menu'],
    ];
  }

  /**
   * All unique section-type keys: built-ins merged with available Bricks tags.
   * @return string[]
   */
  private function get_all_section_types(): array {
    $builtin = ['header','hero','features','process','testimonials','pricing','faq','cta','content','team','story','values','contact_form','locations','social_proof','footer','generic'];
    $bricks  = array_keys($this->get_bricks_templates_by_type());
    $merged  = array_unique(array_merge($builtin, $bricks));
    sort($merged);
    return array_values($merged);
  }

  /** AJAX: return all available Bricks section types (tag → count). */
  public function ajax_get_bricks_section_types(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
    $by_type = $this->get_bricks_templates_by_type();
    $out = [];
    foreach ($by_type as $key => $templates) {
      $out[] = ['type' => $key, 'count' => count($templates)];
    }
    usort($out, fn($a, $b) => strcmp((string) $a['type'], (string) $b['type']));
    wp_send_json_success(['types' => $out]);
  }

  private function save_model(int $project_id, int $sitemap_version_id, string $page_slug, array $model, bool $clear_compiled): void {
    global $wpdb;
    $table = $this->table_wireframes();
    $now = current_time('mysql');
    $data = [
      'model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES),
      'updated_at' => $now,
    ];
    $formats = ['%s','%s'];
    if ($clear_compiled) {
      $data['compiled_bricks_json'] = null;
      $formats[] = '%s';
    }
    $wpdb->update($table, $data, [
      'project_id' => $project_id,
      'sitemap_version_id' => $sitemap_version_id,
      'page_slug' => $page_slug,
    ], $formats, ['%d','%d','%s']);
  }

  /* ------------------- Patterns ------------------- */

  public function patterns(): array {
    return [
      'homepage'     => ['hero','social_proof','features','testimonials','pricing','faq','cta'],
      'service_page' => ['hero','features','process','testimonials','faq','cta'],
      'about'        => ['hero','story','team','values','testimonials','cta'],
      'contact'      => ['hero','contact_form','locations','faq','cta'],
      'generic'      => ['hero','features','faq','cta'],
    ];
  }

  private function current_page_has_shortcode(string $shortcode): bool {
    if (!is_singular()) return false;
    global $post;
    if (!$post || empty($post->post_content)) return false;
    return has_shortcode($post->post_content, $shortcode);
  }

  private function build_selection_context(int $project_id, int $sitemap_version_id, string $page_slug, string $section_type): array {
    $brief = (string) get_post_meta($project_id, 'aisb_project_brief', true);
    $page_title = $this->get_page_title_from_sitemap($sitemap_version_id, $page_slug);
    return [
      'brief' => $brief,
      'page_title' => $page_title,
      'page_slug' => $page_slug,
      'section_type' => $section_type,
    ];
  }

  private function get_page_title_from_sitemap(int $sitemap_id, string $page_slug): string {
    if (!$sitemap_id || $page_slug === '') return '';
    $json = get_post_meta($sitemap_id, 'aisb_sitemap_json', true);
    if (!$json) return '';
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return '';

    $needle = $this->normalize_slug($page_slug);
    $candidates = [];

    if (isset($data['sitemap']) && is_array($data['sitemap'])) {
      foreach ($data['sitemap'] as $p) {
        if (!is_array($p)) continue;
        $slug = $this->normalize_slug((string)($p['slug'] ?? $p['page_slug'] ?? $p['url'] ?? $p['path'] ?? ''));
        $title = (string)($p['page_title'] ?? $p['nav_label'] ?? $p['title'] ?? $p['name'] ?? $p['label'] ?? '');
        if ($slug) $candidates[] = ['slug' => $slug, 'title' => $title];
      }
    }

    if (isset($data['pages']) && is_array($data['pages'])) {
      foreach ($data['pages'] as $p) {
        if (!is_array($p)) continue;
        $slug = $this->normalize_slug((string)($p['slug'] ?? $p['page_slug'] ?? $p['url'] ?? $p['path'] ?? ''));
        $title = (string)($p['title'] ?? $p['name'] ?? $p['label'] ?? '');
        if ($slug) $candidates[] = ['slug' => $slug, 'title' => $title];
      }
    }

    $hier = $data['hierarchy'] ?? $data['tree'] ?? $data['structure'] ?? $data['navigation'] ?? null;
    if ($hier) {
      $this->flatten_sitemap_hierarchy($hier, $candidates);
    }

    foreach ($candidates as $c) {
      if (!is_array($c)) continue;
      if (($c['slug'] ?? '') === $needle) {
        return (string)($c['title'] ?? '');
      }
    }
    return '';
  }

  private function flatten_sitemap_hierarchy($nodes, array &$out): void {
    if (!is_array($nodes)) return;
    foreach ($nodes as $n) {
      if (!is_array($n)) continue;
      $slug = $this->normalize_slug((string)($n['slug'] ?? $n['page_slug'] ?? $n['url'] ?? $n['path'] ?? ''));
      $title = (string)($n['title'] ?? $n['name'] ?? $n['label'] ?? '');
      if ($slug) $out[] = ['slug' => $slug, 'title' => $title];
      $kids = $n['children'] ?? $n['items'] ?? $n['pages'] ?? $n['subpages'] ?? null;
      if ($kids) $this->flatten_sitemap_hierarchy($kids, $out);
    }
  }

  private function normalize_slug(string $slug): string {
    $slug = trim($slug);
    $slug = preg_replace('/^\/+/', '', $slug);
    return (string)$slug;
  }

  /* ------------------- UI assets ------------------- */

  private function css(): string {
    return <<<'CSS'
.aisb-wf-head{display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;}
.aisb-wf-layout{display:grid; grid-template-columns:280px 1fr; gap:14px; margin-top:14px;}
@media (max-width: 980px){.aisb-wf-layout{grid-template-columns:1fr;}}
.aisb-wf-pages{border:1px solid rgba(0,0,0,.08); border-radius:14px; background:#fff; overflow:hidden;}
.aisb-wf-pages-head{padding:12px 12px; border-bottom:1px solid rgba(0,0,0,.06); display:flex; align-items:center; justify-content:space-between; gap:8px;}
.aisb-wf-pages-list{max-height:520px; overflow:auto; padding:8px; display:flex; flex-direction:column; gap:8px;}
.aisb-wf-page-btn{display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 10px; border:1px solid rgba(0,0,0,.08); border-radius:12px; background:#fafafa; cursor:pointer;}
.aisb-wf-page-btn:hover{background:#f3f3f3;}
.aisb-wf-page-btn.is-active{background:#111; color:#fff; border-color:#111;}
.aisb-wf-muted{font-size:12px; color:#666;}
.aisb-wf-page-btn.is-active .aisb-wf-muted{color:rgba(255,255,255,.75);}
.aisb-wf-canvas{border:1px solid rgba(0,0,0,.08); border-radius:14px; background:#fff; overflow:hidden;}
.aisb-wf-canvas-head{padding:12px 12px; border-bottom:1px solid rgba(0,0,0,.06); display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;}
.aisb-wf-canvas-title{font-weight:800; font-size:14px;}
.aisb-wf-actions{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
.aisb-wf-status{padding:10px 12px; font-size:13px;}
.aisb-wf-sections{padding:0; display:flex; flex-direction:column; gap:0;}
.aisb-wf-section{position:relative; width:100%; overflow:hidden; border:none;}
.aisb-wf-section:hover{outline:2px solid rgba(59,130,246,.35); outline-offset:-2px; z-index:2;}
.aisb-wf-section.aisb-editing{outline:2px solid rgba(16,185,129,.6); outline-offset:-2px; z-index:2;}
.aisb-wf-section.aisb-editing .aisb-wf-section-toolbar{display:flex;}
.aisb-wf-section-toolbar{display:none; position:absolute; top:6px; right:6px; z-index:20; gap:3px; align-items:center; background:rgba(0,0,0,.82); border-radius:8px; padding:3px 4px; box-shadow:0 2px 8px rgba(0,0,0,.18);}
.aisb-wf-section:hover .aisb-wf-section-toolbar{display:flex;}
.aisb-wf-tbtn{background:none; border:none; color:#fff; cursor:pointer; font-size:11px; padding:4px 7px; border-radius:6px; white-space:nowrap; opacity:.85;}
.aisb-wf-tbtn:hover{background:rgba(255,255,255,.18); opacity:1;}
.aisb-wf-tbtn.active{background:rgba(59,130,246,.6);}
.aisb-wf-body{padding:0; background:#fff;}
.aisb-wf-skel{border:1px dashed rgba(0,0,0,.18); border-radius:12px; padding:12px; background:linear-gradient(0deg, rgba(0,0,0,.02), rgba(0,0,0,.02));}
.aisb-wf-hero-grid{display:grid; grid-template-columns:1.15fr .85fr; gap:16px; align-items:center;}
@media (max-width: 860px){.aisb-wf-hero-grid{grid-template-columns:1fr;}}
.aisb-wf-txt{display:flex; flex-direction:column; gap:10px;}
.aisb-wf-h1{font-size:22px; line-height:1.2; font-weight:700; letter-spacing:-.02em; color:rgba(0,0,0,.78);}
.aisb-wf-h2{font-size:18px; line-height:1.25; font-weight:650; color:rgba(0,0,0,.72);}
.aisb-wf-p{font-size:13px; line-height:1.45; color:rgba(0,0,0,.55); max-width:56ch;}
.aisb-wf-cta-row{display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:2px;}
.aisb-wf-btnlbl{display:inline-flex; align-items:center; justify-content:center; padding:10px 12px; border-radius:10px; border:1px solid rgba(0,0,0,.12); background:rgba(0,0,0,.02); font-size:12px; color:rgba(0,0,0,.6); min-width:110px;}
.aisb-wf-btnlbl.primary{background:rgba(0,0,0,.06); border-color:rgba(0,0,0,.18); color:rgba(0,0,0,.72); font-weight:600;}
.aisb-wf-media{border-radius:14px; border:1px dashed rgba(0,0,0,.16); background:rgba(0,0,0,.03); height:210px; display:flex; align-items:center; justify-content:center; position:relative; overflow:hidden;}
.aisb-wf-media:before{content:""; position:absolute; inset:-40px; background:linear-gradient(135deg, rgba(0,0,0,.06), transparent); transform:rotate(6deg);} 
.aisb-wf-media:after{content:"Image"; position:relative; font-size:12px; color:rgba(0,0,0,.38);} 
.aisb-wf-block{height:10px; border-radius:8px; background:rgba(0,0,0,.08);}
.aisb-wf-block.sm{width:38%;}
.aisb-wf-block.md{width:62%;}
.aisb-wf-block.lg{width:88%;}
.aisb-wf-chips{display:flex; gap:8px; flex-wrap:wrap;}
.aisb-wf-chip{height:24px; border-radius:999px; border:1px solid rgba(0,0,0,.12); background:rgba(0,0,0,.02); width:96px;}
.aisb-wf-badges{display:flex; gap:10px; flex-wrap:wrap;}
.aisb-wf-badge{height:58px; border-radius:14px; border:1px solid rgba(0,0,0,.12); background:rgba(0,0,0,.02); width:150px;}
.aisb-wf-row{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
.aisb-wf-box{height:12px; border-radius:999px; background:rgba(0,0,0,.12);}
.aisb-wf-box.h1{height:18px; width:60%;}
.aisb-wf-box.p{width:85%;}
.aisb-wf-box.p2{width:70%;}
.aisb-wf-btn{height:28px; width:120px; border-radius:10px; background:rgba(0,0,0,.14);}
.aisb-wf-cards{display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-top:12px;}
.aisb-wf-card{height:70px; border-radius:12px; background:rgba(0,0,0,.10);}
.aisb-wf-raw{margin:12px;}
/* Bricks template card preview */
.aisb-wf-bricks-preview{display:flex; align-items:center; gap:14px; padding:16px 12px; border-radius:12px; background:linear-gradient(135deg,#f0f4ff 0%,#f8f8ff 100%); border:1px solid rgba(90,110,255,.14);}
.aisb-wf-bricks-icon{width:44px; height:44px; border-radius:12px; background:#111; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:18px; flex-shrink:0; letter-spacing:-.04em;}
.aisb-wf-bricks-info{display:flex; flex-direction:column; gap:5px; min-width:0;}
.aisb-wf-bricks-title{font-weight:700; font-size:14px; color:rgba(0,0,0,.82); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
.aisb-wf-bricks-sc{font-size:11px; font-family:monospace; background:rgba(0,0,0,.06); border-radius:6px; padding:3px 7px; display:inline-block; color:rgba(0,0,0,.55); white-space:nowrap;}
.aisb-wf-bricks-type{font-size:11px; color:rgba(0,0,0,.45); font-style:italic;}
.aisb-wf-bricks-notfound{padding:16px 12px; background:rgba(0,0,0,.03); border-radius:12px; border:1px dashed rgba(0,0,0,.15); font-size:12px; color:rgba(0,0,0,.45);}
CSS;
  }

  private function js(): string {
    return <<<'JS'
(function(){
  const root = document.querySelector('[data-aisb-wireframes]');
  if (!root) return;

  const state = {
    projectId: parseInt(root.getAttribute('data-project-id')||'0',10) || 0,
    sitemapId: parseInt(root.getAttribute('data-sitemap-id')||'0',10) || 0,
    pageSlug: '',
    model: null,
    pages: [],
  };

  const elPages = root.querySelector('[data-aisb-wf-pages]');
  const elPagesMeta = root.querySelector('[data-aisb-wf-pages-meta]');
  const elTitle = root.querySelector('[data-aisb-wf-page-title]');
  const elSub = root.querySelector('[data-aisb-wf-page-sub]');
  const elSections = root.querySelector('[data-aisb-wf-sections]');
  const elStatus = root.querySelector('[data-aisb-wf-status]');
  const elCompiled = root.querySelector('[data-aisb-wf-compiled]');
  const elPattern = root.querySelector('[data-aisb-wf-pattern]');

  const btnGenerate = root.querySelector('[data-aisb-wf-generate]');
  const btnGenerateAll = root.querySelector('[data-aisb-wf-generate-all]');
  const btnSave = root.querySelector('[data-aisb-wf-save]');
  const btnShufflePage = root.querySelector('[data-aisb-wf-shuffle-page]');
  const btnCompile = root.querySelector('[data-aisb-wf-compile]');

  const patterns     = (window.AISB_WF && AISB_WF.patterns) ? AISB_WF.patterns : {};
  const sectionTypes = (window.AISB_WF && AISB_WF.sectionTypes && AISB_WF.sectionTypes.length) ? AISB_WF.sectionTypes : ['hero','features','process','testimonials','pricing','faq','cta','content','team','story','values','contact_form','locations','social_proof','footer','header','generic'];
  const patternKeys = Object.keys(patterns);
  elPattern.innerHTML = patternKeys.map(k => `<option value="${k}">${k.replace(/_/g,' ')}</option>`).join('');

  function setStatus(msg, kind){
    elStatus.innerHTML = msg ? `<span class="${kind==='err'?'aisb-error':'aisb-ok'}">${escapeHtml(msg)}</span>` : '';
  }

  function escapeHtml(str){
    return String(str||'').replace(/[&<>\"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#039;'}[s]));
  }

  function qs(obj){
    return Object.keys(obj).map(k => encodeURIComponent(k)+'='+encodeURIComponent(obj[k])).join('&');
  }

  async function postWithNonce(action, data, nonce){
    const res = await fetch(AISB_WF.ajaxUrl, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: qs(Object.assign({action, nonce: nonce || ''}, data||{}))
    });
    return res.json();
  }

  async function post(action, data){
    return postWithNonce(action, data, AISB_WF.nonce);
  }

  async function postCore(action, data){
    return postWithNonce(action, data, AISB_WF.coreNonce);
  }

  async function loadSitemapPages(){
    if (!state.projectId || !state.sitemapId) {
      elPages.innerHTML = '<div class="aisb-wf-muted" style="padding:8px;">Open this screen with ?aisb_project=ID&aisb_sitemap=ID</div>';
      return;
    }
    // Reuse existing sitemap endpoint
    // Use core nonce because this endpoint is owned by the core AISB ajax controller.
    const out = await postCore('aisb_get_sitemap_by_id', { sitemap_id: state.sitemapId });
    if (!out || !out.success) {
      elPages.innerHTML = '<div class="aisb-wf-muted" style="padding:8px;">Failed to load sitemap.</div>';
      return;
    }
    const data = out.data && out.data.data ? out.data.data : (out.data || {});

    // The sitemap JSON structure may differ depending on the prompt/version.
    // We normalize it to a flat [{slug,title}] list for the wireframes UI.
    function normalizeSlug(v){
      return (v||'').toString().trim().replace(/^\//,'');
    }

    function pushUnique(list, item){
      if (!item || !item.slug) return;
      if (list.some(x => x.slug === item.slug)) return;
      list.push(item);
    }

    function flattenHierarchy(nodes, out){
      if (!Array.isArray(nodes)) return;
      nodes.forEach(n => {
        if (!n || typeof n !== 'object') return;
        const slug = normalizeSlug(n.slug || n.page_slug || n.url || n.path || '');
        const title = (n.title || n.name || n.label || slug || '').toString();
        if (slug) pushUnique(out, {slug, title});
        const kids = n.children || n.items || n.pages || n.subpages;
        if (Array.isArray(kids)) flattenHierarchy(kids, out);
      });
    }

    const normalized = [];

    // 0) AISB Step 1 output stores pages as a flat array under `data.sitemap`.
    // Page fields commonly include: page_title, nav_label, slug, page_type, parent_slug.
    if (Array.isArray(data.sitemap)) {
      data.sitemap.forEach(p => {
        const slug = normalizeSlug(p && (p.slug || p.page_slug || p.url || p.path));
        const title = (p && (p.page_title || p.nav_label || p.title || p.name || p.label || slug) || '').toString();
        if (slug) pushUnique(normalized, { slug, title });
      });
    }

    // 1) Direct pages array (alternative schema)
    if (!normalized.length && Array.isArray(data.pages)) {
      data.pages.forEach(p => {
        const slug = normalizeSlug(p && (p.slug || p.page_slug || p.url || p.path));
        const title = (p && (p.title || p.name || p.label || slug) || '').toString();
        if (slug) pushUnique(normalized, {slug, title});
      });
    }

    // 2) Common alternatives
    if (!normalized.length) {
      const alt = data.hierarchy || data.tree || data.structure || data.sitemap || data.navigation;
      flattenHierarchy(alt, normalized);
    }

    // 3) Some outputs store hierarchy under e.g. data.data.hierarchy already handled above.

    state.pages = normalized;
    elPagesMeta.textContent = state.pages.length ? (state.pages.length + ' pages') : '';
    renderPageList();
    if (state.pages[0]) selectPage(state.pages[0].slug);
  }

  function renderPageList(){
    elPages.innerHTML = state.pages.map(p => {
      const active = p.slug === state.pageSlug;
      return `<div class="aisb-wf-page-btn ${active?'is-active':''}" data-page="${escapeHtml(p.slug)}">
        <div>
          <div style="font-weight:700; font-size:13px;">${escapeHtml(p.title||p.slug)}</div>
          <div class="aisb-wf-muted">/${escapeHtml(p.slug)}</div>
        </div>
      </div>`;
    }).join('');
  }

  elPages.addEventListener('click', (e)=>{
    const btn = e.target.closest('[data-page]');
    if (!btn) return;
    selectPage(btn.getAttribute('data-page'));
  });

  async function selectPage(slug){
    state.pageSlug = slug;
    renderPageList();
    const page = state.pages.find(p => p.slug === slug);
    elTitle.textContent = page ? (page.title || slug) : slug;
    elSub.textContent = 'Loading wireframe...';
    elCompiled.textContent = '';
    const out = await post('aisb_get_wireframe_page', {
      project_id: state.projectId,
      sitemap_version_id: state.sitemapId,
      page_slug: slug
    });
    if (out && out.success) {
      state.model = out.data.wireframe;
      // default pattern
      elPattern.value = state.model.pattern || 'generic';
      elSub.textContent = state.model.sections && state.model.sections.length ? 'Edit sections below.' : 'Generate a wireframe to start editing.';
      renderSections();
      setStatus('', 'ok');
    } else {
      setStatus((out && out.data && out.data.message) ? out.data.message : 'Failed', 'err');
    }
  }

  function defaultPreviewSchema(type){
    // Lightweight fallback schema so the preview is always informative even without a stored preview_schema.
    const t = (type||'generic').toString();
    const base = {
      type: t,
      elements: [
        { tag: 'h2', text: 'Section headline' },
        { tag: 'p',  text: 'Short supporting copy that explains the value proposition in one or two sentences.' },
        { tag: 'button', text: 'Call to action' }
      ]
    };
    if (t === 'hero') {
      return {
        type: t,
        layout: 'hero-1',
        elements: [
          { tag: 'eyebrow', text: 'Tagline / Category' },
          { tag: 'h1', text: 'A clear headline that explains what you do' },
          { tag: 'p',  text: 'A short paragraph that supports the headline and nudges the visitor to take action.' },
          { tag: 'button', text: 'Primary action' },
          { tag: 'button', text: 'Secondary action', variant: 'secondary' },
          { tag: 'media', text: 'Hero image / illustration' }
        ]
      };
    }
    if (t === 'features' || t === 'values' || t === 'social_proof') {
      return {
        ...base,
        elements: [
          { tag: 'h2', text: 'Why choose us' },
          { tag: 'p', text: 'A one-liner that frames the feature grid.' },
          { tag: 'cards', count: 3, item: { title: 'Feature title', text: 'Short description.' } }
        ]
      };
    }
    if (t === 'testimonials') {
      return {
        type: t,
        elements: [
          { tag: 'h2', text: 'What customers say' },
          { tag: 'cards', count: 2, item: { title: 'Name · Company', text: '“A short testimonial quote that builds trust.”' } }
        ]
      };
    }
    if (t === 'pricing') {
      return {
        type: t,
        elements: [
          { tag: 'h2', text: 'Pricing' },
          { tag: 'p', text: 'Pick the plan that matches your needs.' },
          { tag: 'cards', count: 3, item: { title: 'Plan name', text: 'Key benefits and price.' } }
        ]
      };
    }
    if (t === 'faq') {
      return {
        type: t,
        elements: [
          { tag: 'h2', text: 'Frequently asked questions' },
          { tag: 'list', count: 3, item: { title: 'Question', text: 'Short answer.' } }
        ]
      };
    }
    if (t === 'cta') {
      return {
        type: t,
        elements: [
          { tag: 'h2', text: 'Ready to get started?' },
          { tag: 'p', text: 'A clear CTA line that reduces friction and tells people what happens next.' },
          { tag: 'button', text: 'Get in touch' }
        ]
      };
    }
    if (t === 'footer') {
      return {
        type: t,
        elements: [
          { tag: 'p', text: '© Company name · Links · Contact' }
        ]
      };
    }
    return base;
  }

  function schemaFromSection(section){
    const s = section || {};
    const schema = s.preview_schema && typeof s.preview_schema === 'object' ? s.preview_schema : null;
    return schema || defaultPreviewSchema(s.type || 'generic');
  }

  function sectionPreview(schema){
    const sc = schema || defaultPreviewSchema('generic');
    const type = (sc.type || 'generic').toString();
    const els = Array.isArray(sc.elements) ? sc.elements : [];

    // Helpers
    const renderText = (tag, text, extraClass='') => `<${tag} class="aisb-wf-txt ${extraClass}">${escapeHtml(text||'')}</${tag}>`;

    if (type === 'hero') {
      const eyebrow = els.find(e=>e.tag==='eyebrow')?.text || 'Tagline';
      const h1 = els.find(e=>e.tag==='h1')?.text || 'Hero headline';
      const p = els.find(e=>e.tag==='p')?.text || 'Supporting paragraph.';
      const buttons = els.filter(e=>e.tag==='button');
      const primary = buttons[0]?.text || 'Primary action';
      const secondary = buttons[1]?.text || 'Secondary action';
      return `
        <div class="aisb-wf-hero">
          <div class="aisb-wf-hero-left">
            <div class="aisb-wf-eyebrow">${escapeHtml(eyebrow)}</div>
            <h1 class="aisb-wf-h1">${escapeHtml(h1)}</h1>
            <p class="aisb-wf-p">${escapeHtml(p)}</p>
            <div class="aisb-wf-row">
              <span class="aisb-wf-btnlbl primary">${escapeHtml(primary)}</span>
              <span class="aisb-wf-btnlbl">${escapeHtml(secondary)}</span>
            </div>
          </div>
          <div class="aisb-wf-hero-right">
            <div class="aisb-wf-media">Hero image</div>
          </div>
        </div>
      `;
    }

    // Generic stacked sections
    let out = '<div class="aisb-wf-skel">';
    for (const e of els) {
      if (!e || !e.tag) continue;
      if (e.tag === 'h1') out += renderText('h2', e.text, 'aisb-wf-h1');
      else if (e.tag === 'h2') out += renderText('h3', e.text, 'aisb-wf-h2');
      else if (e.tag === 'h3' || e.tag === 'h4' || e.tag === 'h5' || e.tag === 'h6') out += renderText('h4', e.text, 'aisb-wf-h3');
      else if (e.tag === 'p') out += renderText('p', e.text, 'aisb-wf-p');
      else if (e.tag === 'button') out += `<span class="aisb-wf-btnlbl ${e.variant==='secondary'?'':'primary'}">${escapeHtml(e.text||'Button')}</span>`;
      else if (e.tag === 'media') out += `<div class="aisb-wf-media">${escapeHtml(e.text||'Media')}</div>`;
      else if (e.tag === 'cards') {
        const n = Math.max(1, Math.min(6, parseInt(e.count||3,10)||3));
        out += '<div class="aisb-wf-cards">' + Array.from({length:n}).map(()=>{
          const title = e.item?.title || 'Card title';
          const txt = e.item?.text || 'Short description.';
          return `<div class="aisb-wf-card"><div class="aisb-wf-cardtitle">${escapeHtml(title)}</div><div class="aisb-wf-cardtxt">${escapeHtml(txt)}</div></div>`;
        }).join('') + '</div>';
      }
      else if (e.tag === 'list') {
        const n = Math.max(1, Math.min(6, parseInt(e.count||3,10)||3));
        out += '<div class="aisb-wf-list">' + Array.from({length:n}).map(()=>{
          const title = e.item?.title || 'Item';
          const txt = e.item?.text || '';
          return `<div class="aisb-wf-listitem"><div class="aisb-wf-cardtitle">${escapeHtml(title)}</div><div class="aisb-wf-cardtxt">${escapeHtml(txt)}</div></div>`;
        }).join('') + '</div>';
      }
    }
    out += '</div>';
    return out;
  }

  function bricksTemplatePreview(section){
    const id    = section.bricks_template_id;
    if (!id) return '<div class="aisb-wf-bricks-notfound">No Bricks template assigned.</div>';
    const title = section.bricks_template_title || ('Template #' + id);
    const sc    = section.bricks_shortcode || ('[bricks_template id="' + id + '"]');
    const ttype = section.bricks_template_ttype ? `<div class="aisb-wf-bricks-type" style="display:inline-block; margin-right:8px;">${escapeHtml(section.bricks_template_ttype)}</div>` : '';
    const iframeUrl = (AISB_WF.previewUrl || '') + id;

    return `
    <div style="background:#fff; border:none; border-radius:0; overflow:hidden; position:relative; display:flex; flex-direction:column;">
      <div style="width:100%; position:relative;">
        <iframe src="${escapeHtml(iframeUrl)}" loading="lazy" class="aisb-bricks-iframe" style="width:100%; height:400px; border:none; display:block;" title="Bricks Preview" scrolling="no"></iframe>
      </div>
    </div>`;
  }

  // Auto-resize iframes + handle edit responses
  window.addEventListener('message', function(e) {
    if (!e.data || !e.data.type) return;

    if (e.data.type === 'aisb_iframe_height' && e.data.height) {
      const iframes = document.querySelectorAll('iframe.aisb-bricks-iframe');
      for (let i = 0; i < iframes.length; i++) {
        if (iframes[i].contentWindow === e.source) {
          iframes[i].style.height = e.data.height + 'px';
          break;
        }
      }
    }

    if (e.data.type === 'aisb_edited_content') {
      // Find which section this iframe belongs to
      const iframes = document.querySelectorAll('iframe.aisb-bricks-iframe');
      let sectionCard = null;
      for (let i = 0; i < iframes.length; i++) {
        if (iframes[i].contentWindow === e.source) {
          sectionCard = iframes[i].closest('[data-uuid]');
          break;
        }
      }
      if (!sectionCard || !state.model) return;
      const uuid = sectionCard.getAttribute('data-uuid');
      const section = (state.model.sections || []).find(s => s.uuid === uuid);
      if (!section || !section.bricks_template_id) return;

      const changes = e.data.changes || [];
      if (changes.length === 0) {
        setStatus('No changes detected.', 'ok');
        return;
      }

      // Save via AJAX
      post('aisb_save_section_text', {
        bricks_template_id: section.bricks_template_id,
        changes: JSON.stringify(changes)
      }).then(out => {
        if (out && out.success) {
          setStatus('Saved ' + (out.data.changed || 0) + ' text change(s). Refreshing...', 'ok');
          // Reload the iframe to show updated content
          const iframe = sectionCard.querySelector('iframe.aisb-bricks-iframe');
          if (iframe) {
            iframe.src = iframe.src;
          }
        } else {
          setStatus('Save failed: ' + ((out && out.data && out.data.message) || 'Unknown error'), 'err');
        }
      });
    }
  });

  function renderSections(){
    const model = state.model || {};
    const sections = Array.isArray(model.sections) ? model.sections : [];
    if (!sections.length) {
      elSections.innerHTML = '<div class="aisb-wf-muted">No sections yet.</div>';
      return;
    }
    elSections.innerHTML = sections.map((s, idx) => {
      const type = (s.type||'generic').toString();
      const locked = !!s.locked;
      const schema = schemaFromSection(s);
      const lockTxt = locked ? 'Unlock' : 'Lock';
      const isBricks = !!(s.bricks_template_id);
      const bricksBadge = isBricks
        ? `<span style="display:inline-block;margin-left:8px;padding:1px 7px;border-radius:999px;font-size:11px;background:#111;color:#fff;font-weight:600;vertical-align:middle;">Bricks #${escapeHtml(String(s.bricks_template_id))}</span>`
        : '';
      const score = (!isBricks && s.match_score !== undefined && s.match_score !== null) ? (' · score ' + (Math.round(parseFloat(s.match_score) * 10) / 10)) : '';
      const tags  = (!isBricks && s.match_tags) ? (' · ' + s.match_tags) : '';
      const bodyHtml = isBricks ? bricksTemplatePreview(s) : sectionPreview(schemaFromSection(s));
      return `
      <div class="aisb-wf-section" data-uuid="${escapeHtml(s.uuid)}">
        <div class="aisb-wf-section-toolbar">
          <button class="aisb-wf-tbtn" data-act="up" ${idx===0?'disabled':''}  title="Move up">↑</button>
          <button class="aisb-wf-tbtn" data-act="down" ${idx===sections.length-1?'disabled':''}  title="Move down">↓</button>
          <button class="aisb-wf-tbtn" data-act="shuffle" ${locked?'disabled':''} title="Shuffle layout">⟳</button>
          <button class="aisb-wf-tbtn ${locked?'active':''}" data-act="lock" title="${lockTxt}">🔒</button>
          <button class="aisb-wf-tbtn" data-act="edit" title="Edit text">✏️</button>
          <button class="aisb-wf-tbtn" data-act="dup" title="Duplicate">⧉</button>
          <button class="aisb-wf-tbtn" data-act="del" title="Delete" style="color:#f87171;">✕</button>
        </div>
        <div class="aisb-wf-body">${bodyHtml}</div>
      </div>`;
    }).join('');
  }

  elSections.addEventListener('click', async (e)=>{
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const card = e.target.closest('[data-uuid]');
    if (!card) return;
    const uuid = card.getAttribute('data-uuid');
    const act = btn.getAttribute('data-act');
    if (!state.model) return;
    const sections = state.model.sections || [];
    const idx = sections.findIndex(s => s.uuid === uuid);
    if (idx < 0) return;

    if (act === 'up' && idx > 0) {
      const tmp = sections[idx-1]; sections[idx-1] = sections[idx]; sections[idx] = tmp;
      renderSections();
      return;
    }
    if (act === 'down' && idx < sections.length-1) {
      const tmp = sections[idx+1]; sections[idx+1] = sections[idx]; sections[idx] = tmp;
      renderSections();
      return;
    }
    if (act === 'del') {
      sections.splice(idx, 1);
      renderSections();
      return;
    }
    if (act === 'dup') {
      const clone = JSON.parse(JSON.stringify(sections[idx]));
      clone.uuid = (crypto && crypto.randomUUID) ? crypto.randomUUID() : ('dup_'+Math.random().toString(16).slice(2));
      clone.locked = false;
      sections.splice(idx+1, 0, clone);
      renderSections();
      return;
    }
    if (act === 'lock') {
      sections[idx].locked = !sections[idx].locked;
      renderSections();
      return;
    }
    if (act === 'shuffle') {
      setStatus('Shuffling section...', 'ok');
      const out = await post('aisb_shuffle_section_layout', {
        project_id: state.projectId,
        sitemap_version_id: state.sitemapId,
        page_slug: state.pageSlug,
        uuid
      });
      if (out && out.success) {
        state.model = out.data.wireframe;
        renderSections();
        setStatus('Shuffled.', 'ok');
      } else {
        setStatus((out && out.data && out.data.message) ? out.data.message : 'Shuffle failed', 'err');
      }
      return;
    }
    if (act === 'edit') {
      const section = sections[idx];
      if (!section.bricks_template_id) {
        setStatus('Only Bricks template sections can be edited inline.', 'err');
        return;
      }
      const iframe = card.querySelector('iframe.aisb-bricks-iframe');
      if (!iframe || !iframe.contentWindow) {
        setStatus('Preview iframe not loaded yet.', 'err');
        return;
      }
      const isEditing = card.classList.contains('aisb-editing');
      if (isEditing) {
        // Disable edit mode and save
        card.classList.remove('aisb-editing');
        btn.classList.remove('active');
        iframe.contentWindow.postMessage({ type: 'aisb_disable_edit' }, '*');
        iframe.style.overflow = 'hidden';
        // Save: ask iframe for updated content, then POST to server
        setStatus('Saving changes...', 'ok');
        iframe.contentWindow.postMessage({ type: 'aisb_get_edited_content' }, '*');
        // We handle the response via the message listener below
      } else {
        // Enable edit mode
        card.classList.add('aisb-editing');
        btn.classList.add('active');
        iframe.style.overflow = 'auto';
        iframe.contentWindow.postMessage({ type: 'aisb_enable_edit' }, '*');
        setStatus('Edit mode: click on text in the preview to change it. Click ✏️ again to save.', 'ok');
      }
      return;
    }
  });

  elSections.addEventListener('change', async (e)=>{
    const sel = e.target.closest('[data-act="type"]');
    if (!sel) return;
    const card = e.target.closest('[data-uuid]');
    if (!card) return;
    const uuid = card.getAttribute('data-uuid');
    const newType = sel.value;
    setStatus('Replacing section type...', 'ok');
    const out = await post('aisb_replace_section_type', {
      project_id: state.projectId,
      sitemap_version_id: state.sitemapId,
      page_slug: state.pageSlug,
      uuid,
      new_type: newType
    });
    if (out && out.success) {
      state.model = out.data.wireframe;
      renderSections();
      setStatus('Replaced.', 'ok');
    } else {
      setStatus((out && out.data && out.data.message) ? out.data.message : 'Replace failed', 'err');
    }
  });

  btnGenerate.addEventListener('click', async ()=>{
    if (!state.pageSlug) return;
    setStatus('Generating wireframe...', 'ok');
    const out = await post('aisb_generate_wireframe_page', {
      project_id: state.projectId,
      sitemap_version_id: state.sitemapId,
      page_slug: state.pageSlug,
      pattern: elPattern.value
    });
    if (out && out.success) {
      state.model = out.data.wireframe;
      renderSections();
      setStatus('Generated.', 'ok');
    } else {
      setStatus((out && out.data && out.data.message) ? out.data.message : 'Generate failed', 'err');
    }
  });

  btnGenerateAll?.addEventListener('click', async ()=>{
    if (!state.pages || state.pages.length === 0) {
      setStatus('No pages found in sitemap.', 'err');
      return;
    }
    if (!confirm('This will generate wireframes for ALL pages sequentially. Existing un-saved wireframe edits could be overridden. Continue?')) {
      return;
    }

    btnGenerateAll.disabled = true;
    btnGenerate.disabled = true;
    
    const initialSlug = state.pageSlug;
    let successCount = 0;
    let failCount = 0;
    
    for (const p of state.pages) {
      state.pageSlug = p.slug;
      
      // Update UI active state visually
      const allBtns = elPagesList.querySelectorAll('.aisb-wf-page-btn');
      allBtns.forEach(b => b.classList.remove('is-active'));
      const activeBtn = elPagesList.querySelector(`[data-aisb-wf-page-btn="${p.slug}"]`);
      if (activeBtn) activeBtn.classList.add('is-active');
      
      elPageTitle.textContent = p.title;
      elPageSub.textContent = p.slug;
      elSections.innerHTML = '<div class="aisb-wf-muted" style="padding:16px;">Generating wireframe... Please wait.</div>';
      
      setStatus(`Generating wireframe for ${p.title} (${successCount + failCount + 1}/${state.pages.length})...`, 'ok');

      try {
        const out = await post('aisb_generate_wireframe_page', {
          project_id: state.projectId,
          sitemap_version_id: state.sitemapId,
          page_slug: p.slug,
          pattern: 'generic' // Default to generic for bulk generation to ensure consistency, or could use elPattern.value but generic is safer
        });
        
        if (out && out.success) {
          state.model = out.data.wireframe;
          renderSections();
          
          // Auto-save the individual page so it persists
          setStatus(`Saving wireframe for ${p.title}...`, 'ok');
          await post('aisb_update_wireframe_page', {
            project_id: state.projectId,
            sitemap_version_id: state.sitemapId,
            page_slug: p.slug,
            model_json: JSON.stringify(state.model)
          });
          
          successCount++;
        } else {
          failCount++;
          elSections.innerHTML = `<div class="aisb-error" style="padding:16px;">Generation failed for ${p.title}.</div>`;
        }
      } catch (e) {
        failCount++;
        elSections.innerHTML = `<div class="aisb-error" style="padding:16px;">Error mapping ${p.title}.</div>`;
      }
    }
    
    btnGenerateAll.disabled = false;
    btnGenerate.disabled = false;
    
    // Load back initial or first page
    state.pageSlug = initialSlug || state.pages[0].slug;
    loadWireframePage(state.pageSlug);
    
    setStatus(`Finished! Generated: ${successCount}. Failed: ${failCount}.`, failCount > 0 ? 'err' : 'ok');
  });

  btnShufflePage.addEventListener('click', async ()=>{
    // client-side shuffle: call shuffle endpoint per unlocked section
    if (!state.model || !state.model.sections) return;
    for (const s of state.model.sections) {
      if (s.locked) continue;
      const out = await post('aisb_shuffle_section_layout', {
        project_id: state.projectId,
        sitemap_version_id: state.sitemapId,
        page_slug: state.pageSlug,
        uuid: s.uuid
      });
      if (out && out.success) {
        state.model = out.data.wireframe;
      }
    }
    renderSections();
    setStatus('Shuffled unlocked sections.', 'ok');
  });

  btnSave.addEventListener('click', async ()=>{
    if (!state.model) return;
    setStatus('Saving...', 'ok');
    const out = await post('aisb_update_wireframe_page', {
      project_id: state.projectId,
      sitemap_version_id: state.sitemapId,
      page_slug: state.pageSlug,
      model_json: JSON.stringify(state.model)
    });
    if (out && out.success) {
      setStatus('Saved.', 'ok');
    } else {
      setStatus((out && out.data && out.data.message) ? out.data.message : 'Save failed', 'err');
    }
  });

  btnCompile.addEventListener('click', async ()=>{
    if (!state.pageSlug) return;
    setStatus('Compiling...', 'ok');
    const out = await post('aisb_compile_wireframe_page', {
      project_id: state.projectId,
      sitemap_version_id: state.sitemapId,
      page_slug: state.pageSlug
    });
    if (out && out.success) {
      elCompiled.textContent = JSON.stringify(out.data.compiled, null, 2);
      setStatus('Compiled.', 'ok');
    } else {
      setStatus((out && out.data && out.data.message) ? out.data.message : 'Compile failed', 'err');
    }
  });

  loadSitemapPages();
})();
JS;
  }
}

?>



