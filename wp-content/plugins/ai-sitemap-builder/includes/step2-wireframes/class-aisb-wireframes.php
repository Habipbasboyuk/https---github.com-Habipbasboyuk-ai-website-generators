<?php

if (!defined('ABSPATH')) exit;

class AISB_Wireframes {

  /** @var AISB_Template_Library */
  private $tpl_lib;
  /** @var AISB_Wireframe_Compiler */
  private $compiler;
  /** @var AISB_Wireframes_Bricks */
  private $bricks;
  /** @var AISB_Wireframes_AI */
  private $ai;

  public function __construct(AISB_Template_Library $tpl_lib, AISB_Wireframe_Compiler $compiler) {
    $this->tpl_lib = $tpl_lib;
    $this->compiler = $compiler;
    $this->bricks = new AISB_Wireframes_Bricks();
    $this->ai = new AISB_Wireframes_AI();
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

    $post = get_post($id);
    if (!$post) wp_die('Post not found');

    // Zowel bricks_template als ai_wireframe posts toestaan voor preview
    $allowed_types = ['bricks_template', 'ai_wireframe'];
    if (!in_array($post->post_type, $allowed_types, true)) {
      wp_die('Invalid post type for preview');
    }

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
        <?php
        if ($post->post_type === 'ai_wireframe') {
          // AI wireframe: Bricks elementen direct renderen (geen shortcode, want het is geen bricks_template)
          if (class_exists('\Bricks\Frontend')) {
            $elements = get_post_meta($id, '_bricks_page_content_2', true);
            if (is_array($elements) && !empty($elements)) {
              echo \Bricks\Frontend::render_data($elements);
            } else {
              echo '<p style="padding:20px;color:#999;">Geen Bricks elementen gevonden in deze AI wireframe.</p>';
            }
          } else {
            echo '<p style="padding:20px;color:#999;">Bricks Builder is vereist om AI wireframes te bekijken.</p>';
          }
        } else {
          // Bricks template: via de standaard Bricks shortcode renderen
          echo do_shortcode('[bricks_template id="' . $id . '"]');
        }
        ?>
      </div>
      <?php wp_footer(); ?>
      <script>
      (function() {
        var TEXT_TAGS = ['H1','H2','H3','H4','H5','H6','P','SPAN','A','LI','BUTTON','LABEL','BLOCKQUOTE','TD','TH','FIGCAPTION','LEGEND'];
        var originalTexts = {};

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

        window.addEventListener('message', function(e) {
          if (!e.data || !e.data.type) return;

          if (e.data.type === 'aisb_enable_edit') {
            document.body.classList.add('aisb-edit-mode');
            document.body.style.overflow = 'auto';
            originalTexts = {};
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
    $is_step2 = ((int)($_GET['aisb_step'] ?? 0) === 2);
    $has_project_ctx = isset($_GET['aisb_project']) && isset($_GET['aisb_sitemap']);

    $is_wireframes_shortcode = $this->current_page_has_shortcode('ai_wireframes');
    $is_builder_shortcode = $this->current_page_has_shortcode('ai_sitemap_builder');
    $is_step2_in_builder = $is_step2 && $has_project_ctx;

    if (!$is_wireframes_shortcode && !$is_step2_in_builder && !$is_builder_shortcode) return;

    wp_enqueue_style(
      'aisb-wireframes-style',
      AISB_PLUGIN_URL . 'assets/wireframes.css',
      [],
      AISB_VERSION
    );

    wp_enqueue_script(
      'aisb-wireframes',
      AISB_PLUGIN_URL . 'assets/wireframes.js',
      [],
      AISB_VERSION,
      true
    );
    wp_localize_script('aisb-wireframes', 'AISB_WF', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'previewUrl' => home_url('/?aisb_bricks_preview='),
      'nonce'   => wp_create_nonce('aisb_wf_nonce'),
      'coreNonce' => wp_create_nonce('aisb_nonce_action'),
      'patterns'     => $this->patterns(),
      'sectionTypes' => $this->bricks->get_all_section_types(),
    ]);
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
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $ok_wf = $nonce && wp_verify_nonce($nonce, 'aisb_wf_nonce');
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

    $bricks_by_type   = $this->bricks->get_bricks_templates_by_type();
    $used_bricks_ids  = [];
    $used_layout_keys = [];

    foreach ($types as $t) {
      $bricks_tpl = $this->bricks->pick_bricks_template($t, $bricks_by_type, $used_bricks_ids);

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

    $model = $this->ai->populate_bricks_content_with_ai($model, $project_id, $sitemap_version_id, $page_slug);
    $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, true);
    wp_send_json_success(['wireframe' => $model]);
  }

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
    if (!$post || !in_array($post->post_type, ['bricks_template', 'ai_wireframe'], true)) {
      wp_send_json_error(['message' => 'Template not found'], 404);
    }

    $elements = get_post_meta($template_id, '_bricks_page_content_2', true);
    if (!is_array($elements)) {
      wp_send_json_error(['message' => 'No Bricks elements found for this template'], 400);
    }

    $text_keys = ['text', 'title', 'subtitle', 'description', 'heading', 'content', 'label'];
    $changed = 0;

    foreach ($changes as $change) {
      if (!isset($change['original']) || !isset($change['updated'])) continue;
      $original = $change['original'];
      $updated  = $change['updated'];
      if ($original === $updated) continue;

      $original_text = trim(strip_tags($original));

      foreach ($elements as &$node) {
        if (empty($node['settings'])) continue;
        foreach ($text_keys as $key) {
          if (!isset($node['settings'][$key]) || !is_string($node['settings'][$key])) continue;
          $setting_text = trim(strip_tags($node['settings'][$key]));
          if ($setting_text === $original_text) {
            $node['settings'][$key] = wp_kses_post($updated);
            $changed++;
            break 2;
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

    $bricks_by_type = $this->bricks->get_bricks_templates_by_type();

    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s)) continue;
      if (($s['uuid'] ?? '') !== $uuid) continue;
      if (!empty($s['locked'])) wp_send_json_error(['message' => 'Section is locked'], 400);
      $type = (string) ($s['type'] ?? 'generic');

      $bricks_tpl = $this->bricks->pick_bricks_template($type, $bricks_by_type, $used_bricks_ids);
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

    $bricks_by_type = $this->bricks->get_bricks_templates_by_type();

    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s)) continue;
      if (($s['uuid'] ?? '') !== $uuid) continue;

      $model['sections'][$i]['type'] = $new_type;

      $bricks_tpl = $this->bricks->pick_bricks_template($new_type, $bricks_by_type, $used_bricks_ids);
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

  public function ajax_get_bricks_section_types(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
    $by_type = $this->bricks->get_bricks_templates_by_type();
    $out = [];
    foreach ($by_type as $key => $templates) {
      $out[] = ['type' => $key, 'count' => count($templates)];
    }
    usort($out, fn($a, $b) => strcmp((string) $a['type'], (string) $b['type']));
    wp_send_json_success(['types' => $out]);
  }

  /* ------------------- Helpers ------------------- */

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
}
