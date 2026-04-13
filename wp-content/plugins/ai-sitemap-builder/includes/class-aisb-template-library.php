<?php

if (!defined('ABSPATH')) exit;

class AISB_Template_Library {

  /** @var AISB_Template_Analyzer */
  private $analyzer;

  public function __construct(AISB_Template_Analyzer $analyzer) {
    $this->analyzer = $analyzer;
  }

  public function init(): void {
    // Ensure tables exist even if activation hook was missed.
    add_action('init', ['AISB_Installer', 'maybe_install']);

    // Admin UI
    add_action('admin_menu', [$this, 'admin_menu']);

    // AJAX
    add_action('wp_ajax_aisb_save_section_template', [$this, 'ajax_save']);
    add_action('wp_ajax_aisb_list_section_templates', [$this, 'ajax_list']);
    add_action('wp_ajax_aisb_delete_section_template', [$this, 'ajax_delete']);
    // Used by the wireframe UI to fetch a full template (incl. preview schema) with lazy regeneration.
    add_action('wp_ajax_aisb_get_section_template', [$this, 'ajax_get']);
    // One-click (admin) repair utility for older libraries that have no preview_schema.
    add_action('wp_ajax_aisb_regenerate_preview_schemas', [$this, 'ajax_regenerate_preview_schemas']);
  }

  public function admin_menu(): void {
    if (!current_user_can('manage_options')) return;
    add_menu_page(
      'AISB Wireframe Templates',
      'AISB Templates',
      'manage_options',
      'aisb-wireframe-templates',
      [$this, 'render_admin_page'],
      'dashicons-screenoptions',
      58
    );
  }

  public function render_admin_page(): void {
    if (!current_user_can('manage_options')) return;

    $nonce = wp_create_nonce('aisb_tpl_nonce');
    ?>
    <div class="wrap">
      <h1>AISB Wireframe Templates</h1>
      <p>Paste a <strong>Bricks “copied elements”</strong> JSON payload from Brixies, assign a section type (auto-detected from root label), and save it to the template library.</p>

      <div style="display:grid; grid-template-columns:1fr 420px; gap:16px; align-items:start;">
        <div>
          <h2>New template</h2>
          <textarea id="aisb_tpl_json" class="large-text code" rows="18" placeholder='{"content": [...], "source": ...}'></textarea>
          <p>
            <label><strong>Section type</strong></label><br />
            <input id="aisb_tpl_type" class="regular-text" type="text" placeholder="hero / features / faq / cta ..." />
            <span style="color:#666;">Leave empty to auto-detect from root section label.</span>
          </p>
          <p>
            <label><strong>Layout key</strong></label><br />
            <input id="aisb_tpl_key" class="regular-text" type="text" placeholder="brixies_hero_07" />
          </p>
          <p>
            <label><strong>Source URL (optional)</strong></label><br />
            <input id="aisb_tpl_source" class="regular-text" type="text" placeholder="https://brixies.co/..." />
          </p>
          <p>
            <button class="button button-primary" id="aisb_tpl_save">Save template</button>
            <span id="aisb_tpl_status" style="margin-left:10px;"></span>
          </p>
        </div>

        <div>
          <h2>Existing templates</h2>
          <div id="aisb_tpl_list" style="border:1px solid #ddd; background:#fff; padding:10px; border-radius:8px; max-height:520px; overflow:auto;"></div>
        </div>
      </div>
    </div>

    <script>
      (function(){
        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const nonce   = <?php echo wp_json_encode($nonce); ?>;
        const elJson  = document.getElementById('aisb_tpl_json');
        const elType  = document.getElementById('aisb_tpl_type');
        const elKey   = document.getElementById('aisb_tpl_key');
        const elSrc   = document.getElementById('aisb_tpl_source');
        const elBtn   = document.getElementById('aisb_tpl_save');
        const elStat  = document.getElementById('aisb_tpl_status');
        const elList  = document.getElementById('aisb_tpl_list');

        function qs(obj){
          return Object.keys(obj).map(k => encodeURIComponent(k)+'='+encodeURIComponent(obj[k])).join('&');
        }

        async function post(data){
          const res = await fetch(ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body: qs(data)
          });
          return res.json();
        }

        function badge(txt){
          return '<span style="display:inline-block; padding:2px 8px; border:1px solid #ddd; border-radius:999px; font-size:12px; background:#fafafa;">'+txt+'</span>';
        }

        async function refresh(){
          const out = await post({action:'aisb_list_section_templates', nonce});
          if (!out || !out.success) {
            elList.innerHTML = '<em>Failed to load templates.</em>';
            return;
          }
          const rows = out.data || [];
          if (!rows.length) {
            elList.innerHTML = '<em>No templates yet.</em>';
            return;
          }
          elList.innerHTML = rows.map(r => {
            return (
              '<div style="padding:8px 0; border-bottom:1px solid #eee;">'
              + '<div style="display:flex; justify-content:space-between; gap:8px;">'
              + '<div><strong>'+ (r.layout_key||'') +'</strong><br />'
              + badge(r.section_type||'') + ' ' + badge('score ' + (r.complexity_score||0))
              + '</div>'
              + '<button class="button button-small" data-del="'+r.id+'">Delete</button>'
              + '</div>'
              + '</div>'
            );
          }).join('');
        }

        elList.addEventListener('click', async (e) => {
          const btn = e.target.closest('[data-del]');
          if (!btn) return;
          if (!confirm('Delete this template?')) return;
          const id = btn.getAttribute('data-del');
          const out = await post({action:'aisb_delete_section_template', nonce, id});
          if (out && out.success) refresh();
        });

        elBtn.addEventListener('click', async () => {
          elStat.textContent = 'Saving...';
          const out = await post({
            action:'aisb_save_section_template',
            nonce,
            bricks_json: elJson.value,
            section_type: elType.value,
            layout_key: elKey.value,
            source_url: elSrc.value,
          });
          if (out && out.success) {
            elStat.textContent = 'Saved.';
            elJson.value = '';
            elType.value = '';
            elKey.value = '';
            elSrc.value = '';
            refresh();
          } else {
            elStat.textContent = (out && out.data && out.data.message) ? out.data.message : 'Error.';
          }
        });

        refresh();
      })();
    </script>
    <?php
  }

  /**
   * @return array<int,array>
   */
  public function get_templates_by_type(string $section_type): array {
    global $wpdb;
    $table = $wpdb->prefix . 'aisb_section_templates';
    $section_type = sanitize_key($section_type);
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, section_type, layout_key, bricks_json, preview_schema, signature, complexity_score FROM {$table} WHERE section_type=%s",
      $section_type
    ), ARRAY_A);
    return is_array($rows) ? $rows : [];
  }

  /**
   * @return array|null
   */
  public function get_template_by_layout_key(string $layout_key) {
    global $wpdb;
    $table = $wpdb->prefix . 'aisb_section_templates';
    $layout_key = sanitize_text_field($layout_key);
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE layout_key=%s",
      $layout_key
    ), ARRAY_A);
    return is_array($row) ? $row : null;
  }

  /**
   * Choose random template for section type with simple dedupe.
   * @param string[] $exclude_layout_keys
   */
  public function pick_random(string $section_type, array $exclude_layout_keys = [], ?int $min_complexity = null): ?array {
    $templates = $this->get_templates_by_type($section_type);
    if (!$templates) return null;

    $exclude = array_flip(array_map('strval', $exclude_layout_keys));
    $filtered = array_values(array_filter($templates, function($t) use ($exclude, $min_complexity){
      if (isset($exclude[(string)($t['layout_key'] ?? '')])) return false;
      if ($min_complexity !== null && (int)($t['complexity_score'] ?? 0) < $min_complexity) return false;
      return true;
    }));

    if (!$filtered) $filtered = $templates;
    return $filtered[array_rand($filtered)];
  }

  /* ------------------ AJAX handlers ------------------ */

  private function require_admin(): void {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }
  }

  private function check_nonce(): void {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'aisb_tpl_nonce')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }
  }

  /**
   * Ensure a row has a usable preview_schema/signature/complexity_score.
   * Older installs may have empty preview_schema values.
   *
   * @param array $row A single db row (ARRAY_A)
   * @return array Updated row (ARRAY_A)
   */
  private function ensure_preview_schema(array $row): array {
    $has_schema = !empty($row['preview_schema']);
    $has_signature = !empty($row['signature']);
    $has_complexity = isset($row['complexity_score']) && $row['complexity_score'] !== '';

    if ($has_schema && $has_signature && $has_complexity) {
      return $row;
    }

    $decoded = null;
    $bricks_json = $row['bricks_json'] ?? '';
    if (is_string($bricks_json) && $bricks_json !== '') {
      $decoded = json_decode($bricks_json, true);
    }
    if (!is_array($decoded)) {
      return $row; // Can't regenerate.
    }

    $analyzer = new AISB_Template_Analyzer();
    $an = $analyzer->analyze($decoded);

    global $wpdb;
    $table = $wpdb->prefix . 'aisb_section_templates';
    $wpdb->update(
      $table,
      [
        'preview_schema' => wp_json_encode($an['preview_schema']),
        'signature' => (string) ($an['signature'] ?? ''),
        'complexity_score' => (int) ($an['complexity_score'] ?? 0),
      ],
      ['id' => (int) ($row['id'] ?? 0)],
      ['%s', '%s', '%d'],
      ['%d']
    );

    $row['preview_schema'] = wp_json_encode($an['preview_schema']);
    $row['signature'] = (string) ($an['signature'] ?? '');
    $row['complexity_score'] = (int) ($an['complexity_score'] ?? 0);
    return $row;
  }

  public function ajax_list(): void {
    $this->require_admin();
    $this->check_nonce();
    global $wpdb;
    $table = $wpdb->prefix . 'aisb_section_templates';
    $rows = $wpdb->get_results("SELECT id, section_type, layout_key, bricks_json, preview_schema, signature, complexity_score, created_at FROM {$table} ORDER BY created_at DESC LIMIT 500", ARRAY_A);
    if (!$rows) wp_send_json_success([]);

    // Lazily backfill missing preview schemas.
    $rows = array_map([$this, 'ensure_preview_schema'], $rows);

    // Don't send full bricks JSON to the listing UI by default.
    $rows = array_map(function($r){
      unset($r['bricks_json']);
      return $r;
    }, $rows);

    wp_send_json_success($rows);
  }

  /**
   * Fetch a single template row (incl. bricks_json + preview_schema) by id.
   */
  public function ajax_get(): void {
    $this->require_admin();
    $this->check_nonce();
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if (!$id) wp_send_json_error(['message' => 'Missing id'], 400);

    global $wpdb;
    $table = $wpdb->prefix . 'aisb_section_templates';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
    if (!is_array($row)) wp_send_json_error(['message' => 'Not found'], 404);

    $row = $this->ensure_preview_schema($row);

    // Decode preview_schema JSON for easier consumption.
    $row['preview_schema'] = is_string($row['preview_schema']) ? json_decode($row['preview_schema'], true) : $row['preview_schema'];
    wp_send_json_success($row);
  }

  /**
   * Admin helper: regenerate preview_schema for all templates.
   * Runs in one request; keep the dataset reasonable (<~1k templates).
   */
  public function ajax_regenerate_preview_schemas(): void {
    $this->require_admin();
    $this->check_nonce();
    global $wpdb;
    $table = $wpdb->prefix . 'aisb_section_templates';
    $rows = $wpdb->get_results("SELECT id, bricks_json, preview_schema, signature, complexity_score FROM {$table}", ARRAY_A);
    if (!$rows) wp_send_json_success(['updated' => 0]);

    $updated = 0;
    foreach ($rows as $row) {
      $before = $row['signature'] ?? '';
      $afterRow = $this->ensure_preview_schema($row);
      if (($afterRow['signature'] ?? '') !== $before) {
        $updated++;
      }
    }
    wp_send_json_success(['updated' => $updated]);
  }

  public function ajax_delete(): void {
    $this->require_admin();
    $this->check_nonce();
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if (!$id) wp_send_json_error(['message' => 'Missing id'], 400);
    global $wpdb;
    $table = $wpdb->prefix . 'aisb_section_templates';
    $wpdb->delete($table, ['id' => $id], ['%d']);
    wp_send_json_success(['ok' => 1]);
  }

  public function ajax_save(): void {
    $this->require_admin();
    $this->check_nonce();

    $bricks_json_raw = isset($_POST['bricks_json']) ? wp_unslash($_POST['bricks_json']) : '';
    $layout_key = isset($_POST['layout_key']) ? sanitize_text_field(wp_unslash($_POST['layout_key'])) : '';
    $section_type = isset($_POST['section_type']) ? sanitize_text_field(wp_unslash($_POST['section_type'])) : '';
    $source_url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '';

    if (trim($bricks_json_raw) === '') wp_send_json_error(['message' => 'Missing JSON'], 400);
    if (trim($layout_key) === '') wp_send_json_error(['message' => 'Missing layout_key'], 400);

    $decoded = json_decode($bricks_json_raw, true);
    if (!is_array($decoded)) wp_send_json_error(['message' => 'Invalid JSON'], 400);

    $analysis = $this->analyzer->analyze($decoded);
    $detected_type = (string)($analysis['section_type'] ?? 'generic');
    $final_type = $section_type !== '' ? sanitize_key($section_type) : sanitize_key($detected_type);

    $preview_schema = wp_json_encode($analysis['preview_schema'] ?? [], JSON_UNESCAPED_SLASHES);
    $bricks_json = wp_json_encode($decoded, JSON_UNESCAPED_SLASHES);
    $signature = (string)($analysis['signature'] ?? $final_type);
    $complexity = (int)($analysis['complexity_score'] ?? 0);

    global $wpdb;
    $table = $wpdb->prefix . 'aisb_section_templates';
    $now = current_time('mysql');

    // Upsert by layout_key
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE layout_key=%s", $layout_key));
    $data = [
      'section_type' => $final_type,
      'layout_key' => $layout_key,
      'source_url' => $source_url,
      'bricks_json' => $bricks_json,
      'preview_schema' => $preview_schema,
      'signature' => $signature,
      'complexity_score' => $complexity,
      'updated_at' => $now,
    ];

    if ($existing) {
      $wpdb->update($table, $data, ['id' => (int)$existing], [
        '%s','%s','%s','%s','%s','%s','%d','%s'
      ], ['%d']);
      wp_send_json_success(['id' => (int)$existing, 'updated' => 1, 'section_type' => $final_type]);
    }

    $data['created_at'] = $now;
    $wpdb->insert($table, $data, [
      '%s','%s','%s','%s','%s','%s','%d','%s','%s'
    ]);
    wp_send_json_success(['id' => (int)$wpdb->insert_id, 'created' => 1, 'section_type' => $final_type]);
  }
}
