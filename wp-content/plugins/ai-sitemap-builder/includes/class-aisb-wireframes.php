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
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    // AJAX
    add_action('wp_ajax_aisb_get_wireframe_page', [$this, 'ajax_get_wireframe_page']);
    add_action('wp_ajax_aisb_generate_wireframe_page', [$this, 'ajax_generate_wireframe_page']);
    add_action('wp_ajax_aisb_update_wireframe_page', [$this, 'ajax_update_wireframe_page']);
    add_action('wp_ajax_aisb_shuffle_section_layout', [$this, 'ajax_shuffle_section_layout']);
    add_action('wp_ajax_aisb_replace_section_type', [$this, 'ajax_replace_section_type']);
    add_action('wp_ajax_aisb_compile_wireframe_page', [$this, 'ajax_compile_wireframe_page']);
  }

  public function register_shortcode(): void {
    add_shortcode('ai_wireframes', [$this, 'render_shortcode']);
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
      // nonce: used for wireframes endpoints.
      'nonce'   => wp_create_nonce('aisb_wf_nonce'),
      // coreNonce: used when calling existing AISB AJAX endpoints that validate against AISB_Plugin::NONCE_ACTION.
      'coreNonce' => wp_create_nonce('aisb_nonce_action'),
      'patterns' => $this->patterns(),
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

    $used = [];
    foreach ($types as $t) {
      $min_complexity = null;
      if ($pattern === 'homepage' && $t === 'hero') $min_complexity = 40;

      $tpl = $this->tpl_lib->pick_random($t, $used, $min_complexity);
      // Even if no template exists yet for this section type, we still return a section
      // so the UI can preview the *full* page (multi-section) and the user can edit.
      $layout_key = $tpl && !empty($tpl['layout_key'])
        ? (string) $tpl['layout_key']
        : ('cf_' . $t . '_section_1');

      if ($tpl && !empty($tpl['layout_key'])) {
        $used[] = (string) $tpl['layout_key'];
      }

      $preview_schema = null;
      if ($tpl && isset($tpl['preview_schema'])) {
        $decoded = json_decode((string) $tpl['preview_schema'], true);
        $preview_schema = is_array($decoded) ? $decoded : null;
      }

      $model['sections'][] = [
        'uuid' => wp_generate_uuid4(),
        'type' => $t,
        'layout_key' => $layout_key,
        'locked' => false,
        'preview_schema' => $preview_schema,
      ];
    }

    $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, true);
    wp_send_json_success(['wireframe' => $model]);
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

    $used = [];
    foreach (($model['sections'] ?? []) as $s) {
      if (is_array($s) && !empty($s['layout_key'])) $used[] = (string)$s['layout_key'];
    }

    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s)) continue;
      if (($s['uuid'] ?? '') !== $uuid) continue;
      if (!empty($s['locked'])) wp_send_json_error(['message' => 'Section is locked'], 400);
      $type = (string)($s['type'] ?? 'generic');
      $tpl = $this->tpl_lib->pick_random($type, $used, null);
      // Allow shuffling even when the library doesn't have a matching template yet.
      // The preview renderer can fall back to a type-based preview schema.
      $model['sections'][$i]['layout_key'] = $tpl ? (string)$tpl['layout_key'] : ('cf_' . $type . '_1');
      $model['sections'][$i]['preview_schema'] = $tpl ? json_decode((string)($tpl['preview_schema'] ?? '{}'), true) : null;
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

    $used = [];
    foreach (($model['sections'] ?? []) as $s) {
      if (is_array($s) && !empty($s['layout_key'])) $used[] = (string)$s['layout_key'];
    }

    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s)) continue;
      if (($s['uuid'] ?? '') !== $uuid) continue;
      $tpl = $this->tpl_lib->pick_random($new_type, $used, null);
      $model['sections'][$i]['type'] = $new_type;
      $model['sections'][$i]['layout_key'] = $tpl ? (string)$tpl['layout_key'] : ('cf_' . $new_type . '_1');
      $model['sections'][$i]['preview_schema'] = $tpl ? json_decode((string)($tpl['preview_schema'] ?? '{}'), true) : null;
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
      'homepage' => ['hero','social_proof','features','testimonials','pricing','faq','cta','footer'],
      'service_page' => ['hero','features','process','testimonials','faq','cta'],
      'about' => ['hero','story','team','values','testimonials','cta'],
      'contact' => ['hero','contact_form','locations','faq','cta'],
      'generic' => ['hero','content','features','faq','cta'],
    ];
  }

  private function current_page_has_shortcode(string $shortcode): bool {
    if (!is_singular()) return false;
    global $post;
    if (!$post || empty($post->post_content)) return false;
    return has_shortcode($post->post_content, $shortcode);
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
.aisb-wf-sections{padding:12px; display:flex; flex-direction:column; gap:12px;}
.aisb-wf-section{border:1px solid rgba(0,0,0,.1); border-radius:14px; overflow:hidden;}
.aisb-wf-section-head{display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 12px; background:#fafafa; border-bottom:1px solid rgba(0,0,0,.06);}
.aisb-wf-section-head strong{font-size:13px;}
.aisb-wf-controls{display:flex; gap:6px; align-items:center; flex-wrap:wrap;}
.aisb-wf-iconbtn{border:1px solid rgba(0,0,0,.14); background:#fff; border-radius:10px; padding:6px 8px; cursor:pointer; font-size:12px;}
.aisb-wf-iconbtn:hover{background:#f6f6f6;}
.aisb-wf-lock{opacity:.75;}
.aisb-wf-body{padding:12px; background:#fff;}
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
  const btnSave = root.querySelector('[data-aisb-wf-save]');
  const btnShufflePage = root.querySelector('[data-aisb-wf-shuffle-page]');
  const btnCompile = root.querySelector('[data-aisb-wf-compile]');

  const patterns = (window.AISB_WF && AISB_WF.patterns) ? AISB_WF.patterns : {};
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
      return `
      <div class="aisb-wf-section" data-uuid="${escapeHtml(s.uuid)}">
        <div class="aisb-wf-section-head">
          <div>
            <strong>${escapeHtml(type)}</strong>
            <span class="aisb-wf-muted" style="margin-left:8px;">${escapeHtml(s.layout_key||'')}</span>
          </div>
          <div class="aisb-wf-controls">
            <button class="aisb-wf-iconbtn" data-act="up" ${idx===0?'disabled':''}>↑</button>
            <button class="aisb-wf-iconbtn" data-act="down" ${idx===sections.length-1?'disabled':''}>↓</button>
            <select class="aisb-wf-iconbtn" data-act="type">
              ${['hero','features','process','testimonials','pricing','faq','cta','content','team','story','values','contact_form','locations','social_proof','footer','generic'].map(t => `<option value="${t}" ${t===type?'selected':''}>${t}</option>`).join('')}
            </select>
            <button class="aisb-wf-iconbtn" data-act="shuffle" ${locked?'disabled':''}>Shuffle</button>
            <button class="aisb-wf-iconbtn aisb-wf-lock" data-act="lock">${lockTxt}</button>
            <button class="aisb-wf-iconbtn" data-act="dup">Duplicate</button>
            <button class="aisb-wf-iconbtn" data-act="del">Delete</button>
          </div>
        </div>
        <div class="aisb-wf-body">${sectionPreview(schema)}</div>
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
