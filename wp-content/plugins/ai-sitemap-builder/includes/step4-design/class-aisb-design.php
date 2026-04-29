<?php

if (!defined('ABSPATH')) exit;

/**
 * Step 4: Design
 * Full-page wireframe preview with style-guide overrides applied.
 */
class AISB_Design {

  public function init(): void {
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('wp_ajax_aisb_design_list_templates',   [$this, 'ajax_list_templates']);
    add_action('wp_ajax_aisb_design_replace_section',  [$this, 'ajax_design_replace_section']);
    add_action('wp_ajax_aisb_design_save_patch',       [$this, 'ajax_save_design_patch']);
  }

  /**
   * AJAX: Lijst alle gepubliceerde Bricks-templates op zodat de gebruiker
   * een andere layout kan kiezen voor een sectie in de Step 4 canvas.
   * Optionele filter: ?type=hero (matcht template_tag slug of _bricks_template_type).
   */
  public function ajax_list_templates(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'aisb_sg_nonce')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

    if (!post_type_exists('bricks_template')) {
      wp_send_json_success(['templates' => []]);
    }

    $args = [
      'post_type'      => 'bricks_template',
      'post_status'    => 'publish',
      'posts_per_page' => 200,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'no_found_rows'  => true,
    ];
    if ($search) {
      $args['s'] = $search;
    }
    $posts = get_posts($args);

    $out = [];
    foreach ($posts as $post) {
      $id = (int) $post->ID;
      $title = (string) $post->post_title;
      // Sla AI-gegenereerde wireframes over (intern gebruik)
      if (strpos($title, '[AI]') === 0) continue;

      $tags_raw = get_the_terms($id, 'template_tag');
      $tags = [];
      if (!empty($tags_raw) && !is_wp_error($tags_raw)) {
        $tags = wp_list_pluck($tags_raw, 'slug');
      }
      $ttype = (string) (get_post_meta($id, '_bricks_template_type', true) ?: '');
      $type_keys = array_map('strtolower', $tags);
      if (empty($type_keys) && $ttype !== '') {
        $type_keys = [strtolower($ttype)];
      }

      // Type filter
      if ($type && !in_array($type, $type_keys, true)) continue;

      $out[] = [
        'id'    => $id,
        'title' => $title,
        'tags'  => $type_keys,
        'ttype' => $ttype,
      ];
    }

    wp_send_json_success(['templates' => $out]);
  }

  /**
   * AJAX: Vervang een sectie in het wireframe model door een specifiek Bricks-template
   * en vul het daarna via AI zodat het eigen tekst krijgt (geen lorem ipsum).
   * Wordt aangeroepen vanuit Design (Step 4) editor-panel.js swapSectionTemplate().
   */
  public function ajax_design_replace_section(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'aisb_sg_nonce')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    set_time_limit(180);
    ignore_user_abort(true);

    $project_id         = isset($_POST['project_id'])         ? (int) $_POST['project_id']                                         : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int) $_POST['sitemap_version_id']                                  : 0;
    $page_slug          = isset($_POST['page_slug'])          ? sanitize_title(wp_unslash($_POST['page_slug']))                      : '';
    $uuid               = isset($_POST['uuid'])               ? sanitize_text_field(wp_unslash($_POST['uuid']))                      : '';
    $bricks_template_id = isset($_POST['bricks_template_id']) ? (int) $_POST['bricks_template_id']                                  : 0;

    if (!$project_id || !$sitemap_version_id || !$page_slug || !$uuid || !$bricks_template_id) {
      wp_send_json_error(['message' => 'Missing params'], 400);
    }

    // Eigenaarschapscontrole
    $post = get_post($project_id);
    if (!$post || $post->post_type !== 'aisb_project' || (int) $post->post_author !== (int) get_current_user_id()) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    error_log('[AISB] ajax_design_replace_section: project=' . $project_id . ' uuid=' . $uuid . ' tpl=' . $bricks_template_id . ' page=' . $page_slug);

    // Wireframe model laden
    global $wpdb;
    $table = $wpdb->prefix . 'aisb_wireframes';
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug=%s",
      $project_id, $sitemap_version_id, $page_slug
    ), ARRAY_A);

    if (!$row) {
      wp_send_json_error(['message' => 'Wireframe row not found'], 404);
    }

    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) {
      wp_send_json_error(['message' => 'Invalid wireframe model'], 500);
    }

    // Bricks template metadata ophalen
    $tpl_post = get_post($bricks_template_id);
    if (!$tpl_post || $tpl_post->post_type !== 'bricks_template') {
      wp_send_json_error(['message' => 'Bricks template not found'], 404);
    }
    $tags_raw  = get_the_terms($bricks_template_id, 'template_tag');
    $tags      = (!empty($tags_raw) && !is_wp_error($tags_raw)) ? wp_list_pluck($tags_raw, 'slug') : [];
    $ttype     = (string)(get_post_meta($bricks_template_id, '_bricks_template_type', true) ?: '');

    // Sectie in model bijwerken
    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s) || ($s['uuid'] ?? '') !== $uuid) continue;

      // Oude ai_wireframe post verwijderen
      $old_ai = isset($s['ai_wireframe_id']) ? (int) $s['ai_wireframe_id'] : 0;
      if ($old_ai > 0) {
        wp_delete_post($old_ai, true);
      }

      $model['sections'][$i]['bricks_template_id']    = $bricks_template_id;
      $model['sections'][$i]['bricks_template_title'] = $tpl_post->post_title;
      $model['sections'][$i]['bricks_template_ttype'] = $ttype;
      $model['sections'][$i]['layout_key']            = 'bricks_' . $bricks_template_id;
      $model['sections'][$i]['match_tags']            = implode(', ', $tags);
      $model['sections'][$i]['preview_schema']        = null;
      $model['sections'][$i]['ai_wireframe_id']       = null;
      break;
    }

    // Model opslaan (compiled cache wissen)
    $wpdb->update($table,
      ['model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES), 'compiled_bricks_json' => null, 'updated_at' => current_time('mysql')],
      ['project_id' => $project_id, 'sitemap_version_id' => $sitemap_version_id, 'page_slug' => $page_slug],
      ['%s', '%s', '%s'], ['%d', '%d', '%s']
    );

    // AI tekst fill voor uitsluitend deze sectie
    $ai = new AISB_Wireframes_AI();
    $model = $ai->populate_bricks_content_with_ai($model, $project_id, $sitemap_version_id, $page_slug, [$uuid]);

    // Bijgewerkt model opslaan (ai_wireframe_id is nu gevuld)
    $wpdb->update($table,
      ['model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES), 'updated_at' => current_time('mysql')],
      ['project_id' => $project_id, 'sitemap_version_id' => $sitemap_version_id, 'page_slug' => $page_slug],
      ['%s', '%s'], ['%d', '%d', '%s']
    );

    // ai_wireframe_id voor de sectie teruggeven zodat JS de iframe src kan updaten
    $new_ai_wireframe_id = 0;
    foreach (($model['sections'] ?? []) as $s) {
      if (!is_array($s) || ($s['uuid'] ?? '') !== $uuid) continue;
      $new_ai_wireframe_id = (int)($s['ai_wireframe_id'] ?? 0);
      break;
    }

    wp_send_json_success([
      'ai_wireframe_id' => $new_ai_wireframe_id,
      'bricks_template_id' => $bricks_template_id,
    ]);
  }

  /**
   * AJAX: Sla design-patches op voor een of meerdere ai_wireframe posts.
   * Een patch is een JSON-array van bewerkingen:
   *   [{type:'text'|'css'|'img'|'mirror', selector, ...}, ...]
   * Elke post krijgt zijn patches opgeslagen als '_aisb_design_patch' post meta.
   */
  public function ajax_save_design_patch(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'aisb_sg_nonce')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    if (!$project_id) wp_send_json_error(['message' => 'Missing project_id'], 400);

    // Eigenaarschapscontrole op project
    $project = get_post($project_id);
    if (!$project || $project->post_type !== 'aisb_project' || (int) $project->post_author !== (int) get_current_user_id()) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    $patches_raw = isset($_POST['patches']) ? wp_unslash($_POST['patches']) : '[]';
    $patches = json_decode($patches_raw, true);
    if (!is_array($patches)) wp_send_json_error(['message' => 'Invalid patches JSON'], 400);

    $saved = 0;
    foreach ($patches as $item) {
      $post_id = isset($item['post_id']) ? (int) $item['post_id'] : 0;
      $patch   = isset($item['patch']) && is_array($item['patch']) ? $item['patch'] : [];
      if (!$post_id) continue;

      // Controleer of dit een ai_wireframe post is
      $p = get_post($post_id);
      if (!$p || $p->post_type !== 'ai_wireframe') continue;

      // Saneer elke patch-operatie
      $clean = [];
      foreach ($patch as $op) {
        if (!isset($op['type'])) continue;
        $type = sanitize_key($op['type']);
        $entry = ['type' => $type];
        if (isset($op['selector'])) $entry['selector'] = sanitize_text_field($op['selector']);
        if ($type === 'text')   $entry['text']  = wp_kses_post($op['text'] ?? '');
        if ($type === 'css')  { $entry['prop']  = sanitize_key($op['prop'] ?? ''); $entry['value'] = sanitize_text_field($op['value'] ?? ''); }
        if ($type === 'img')    $entry['src']   = esc_url_raw($op['src'] ?? '');
        if ($type === 'mirror') $entry['mirrored'] = (bool) ($op['mirrored'] ?? false);
        $clean[] = $entry;
      }

      update_post_meta($post_id, '_aisb_design_patch', wp_json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      $saved++;
    }

    wp_send_json_success(['saved' => $saved]);
  }

  public function enqueue_assets(): void {    $is_step4 = ((int)($_GET['aisb_step'] ?? 0) === 4);
    $has_ctx  = isset($_GET['aisb_project']);
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
      AISB_VERSION,
      true
    );

    wp_enqueue_script(
      'aisb-design-editor-panel',
      AISB_PLUGIN_URL . 'assets/js/design/editor-panel.js',
      ['aisb-design-canvas'],
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
      <div class="aisb-design-toolbar">
        <span class="aisb-design-toolbar-title">Design Canvas</span>
        <button id="aisb-design-save-btn" class="aisb-design-save-btn" type="button" title="Alle wijzigingen opslaan">&#128190; Opslaan</button>
      </div>
      <div class="aisb-design-canvas" data-design-canvas></div>
      <p class="aisb-design-hint">Scroll to pan · Ctrl+scroll to zoom · Double-click to fit all</p>
    </div>
    <?php
  }
}
