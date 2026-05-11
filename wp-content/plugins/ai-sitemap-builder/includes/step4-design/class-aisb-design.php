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
    add_action('wp_ajax_aisb_design_insert_section',   [$this, 'ajax_insert_section']);
    add_action('wp_ajax_aisb_design_reorder_sections', [$this, 'ajax_reorder_sections']);
    add_action('wp_ajax_aisb_export_figma_json',       [$this, 'ajax_export_figma_json']);
  }

  /**
   * AJAX: Wijzig de volgorde van secties binnen een pagina.
   * Verwacht een geordende lijst van uuid's; het model wordt op basis daarvan
   * gesorteerd en opgeslagen. Ongeldige/onbekende uuid's worden genegeerd;
   * bestaande secties die niet in de lijst staan blijven aan het einde behouden.
   */
  public function ajax_reorder_sections(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'aisb_sg_nonce')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    $project_id         = isset($_POST['project_id'])         ? (int) $_POST['project_id']                              : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int) $_POST['sitemap_version_id']                      : 0;
    $page_slug          = isset($_POST['page_slug'])          ? sanitize_title(wp_unslash($_POST['page_slug']))         : '';
    $uuids_raw          = isset($_POST['uuids'])              ? wp_unslash($_POST['uuids'])                              : '';

    if (!$project_id || !$sitemap_version_id || !$page_slug) {
      wp_send_json_error(['message' => 'Missing params'], 400);
    }

    $uuids = json_decode((string)$uuids_raw, true);
    if (!is_array($uuids)) wp_send_json_error(['message' => 'Invalid uuids'], 400);
    $uuids = array_values(array_filter(array_map(static function ($u) {
      return is_string($u) ? sanitize_text_field($u) : '';
    }, $uuids), static function ($u) { return $u !== ''; }));

    // Optionele bg_indices map (uuid → 0/1) om de afwisselende achtergrond
    // visueel te bevriezen aan de sectie zelf, los van de positie.
    $bg_raw = isset($_POST['bg_indices']) ? wp_unslash($_POST['bg_indices']) : '';
    $bg_map = [];
    if ($bg_raw !== '') {
      $decoded = json_decode((string)$bg_raw, true);
      if (is_array($decoded)) {
        foreach ($decoded as $u => $v) {
          if (!is_string($u) || $u === '') continue;
          $u = sanitize_text_field($u);
          $bg_map[$u] = ((int)$v === 1) ? 1 : 0;
        }
      }
    }

    // Eigenaarschapscontrole
    $project = get_post($project_id);
    if (!$project || $project->post_type !== 'aisb_project' || (int) $project->post_author !== (int) get_current_user_id()) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'aisb_wireframes';
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug=%s",
      $project_id, $sitemap_version_id, $page_slug
    ), ARRAY_A);
    if (!$row) wp_send_json_error(['message' => 'Wireframe not found'], 404);

    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) wp_send_json_error(['message' => 'Invalid model'], 500);

    $sections = $model['sections'] ?? [];
    if (!is_array($sections) || !$sections) wp_send_json_error(['message' => 'No sections'], 400);

    // Index op uuid
    $by_uuid = [];
    foreach ($sections as $s) {
      if (is_array($s) && !empty($s['uuid'])) $by_uuid[(string)$s['uuid']] = $s;
    }

    $reordered = [];
    $seen = [];
    foreach ($uuids as $u) {
      if (isset($by_uuid[$u]) && !isset($seen[$u])) {
        $reordered[] = $by_uuid[$u];
        $seen[$u] = true;
      }
    }
    // Eventuele resterende secties (niet meegestuurd) achteraan toevoegen
    foreach ($sections as $s) {
      $u = (is_array($s) && isset($s['uuid'])) ? (string)$s['uuid'] : '';
      if ($u !== '' && !isset($seen[$u])) {
        $reordered[] = $s;
        $seen[$u] = true;
      }
    }

    if (count($reordered) !== count($sections)) {
      wp_send_json_error(['message' => 'Section count mismatch'], 500);
    }

    // bg_index per sectie persisteren (indien meegestuurd)
    if ($bg_map) {
      foreach ($reordered as &$s) {
        if (!is_array($s)) continue;
        $u = (string)($s['uuid'] ?? '');
        if ($u !== '' && array_key_exists($u, $bg_map)) {
          $s['bg_index'] = $bg_map[$u];
        }
      }
      unset($s);
    }

    $model['sections'] = $reordered;

    $wpdb->update($table,
      ['model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES), 'compiled_bricks_json' => null, 'updated_at' => current_time('mysql')],
      ['project_id' => $project_id, 'sitemap_version_id' => $sitemap_version_id, 'page_slug' => $page_slug],
      ['%s', '%s', '%s'], ['%d', '%d', '%s']
    );

    error_log('[AISB] ajax_reorder_sections: page=' . $page_slug . ' count=' . count($reordered));

    wp_send_json_success(['ok' => true, 'count' => count($reordered)]);
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
        if ($type === 'css')  {
          $entry['prop']  = sanitize_key($op['prop'] ?? '');
          $entry['value'] = sanitize_text_field($op['value'] ?? '');
          // Behoud de 'cascade' marker (bv. "section") zodat applyPatch na
          // refresh weet dat de bg-kleur ook over alle nested divs verspreid
          // moet worden — anders kleurt alleen het root-element en
          // 'reverten' de inner divs naar de oude kleur.
          if (isset($op['cascade'])) {
            $entry['cascade'] = sanitize_key($op['cascade']);
          }
        }
        if ($type === 'img')    $entry['src']   = esc_url_raw($op['src'] ?? '');
        if ($type === 'mirror') $entry['mirrored'] = (bool) ($op['mirrored'] ?? false);
        $clean[] = $entry;
      }

      update_post_meta($post_id, '_aisb_design_patch', wp_json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      $saved++;
    }

    wp_send_json_success(['saved' => $saved]);
  }

  /**
   * AJAX: Voeg een nieuwe sectie toe na een bestaande sectie (op basis van uuid).
   * Maakt gebruik van een Bricks-template en vult de tekst via AI.
   */
  public function ajax_insert_section(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'aisb_sg_nonce')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    set_time_limit(180);
    ignore_user_abort(true);

    $project_id         = isset($_POST['project_id'])         ? (int) $_POST['project_id']                                : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int) $_POST['sitemap_version_id']                        : 0;
    $page_slug          = isset($_POST['page_slug'])          ? sanitize_title(wp_unslash($_POST['page_slug']))           : '';
    $after_uuid         = isset($_POST['after_uuid'])         ? sanitize_text_field(wp_unslash($_POST['after_uuid']))     : '';
    $bricks_template_id = isset($_POST['bricks_template_id']) ? (int) $_POST['bricks_template_id']                       : 0;

    if (!$project_id || !$sitemap_version_id || !$page_slug || !$bricks_template_id) {
      wp_send_json_error(['message' => 'Missing params'], 400);
    }

    // Eigenaarschapscontrole
    $project = get_post($project_id);
    if (!$project || $project->post_type !== 'aisb_project' || (int) $project->post_author !== (int) get_current_user_id()) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    // Bricks template valideren
    $tpl_post = get_post($bricks_template_id);
    if (!$tpl_post || $tpl_post->post_type !== 'bricks_template') {
      wp_send_json_error(['message' => 'Template not found'], 404);
    }

    $tags_raw  = get_the_terms($bricks_template_id, 'template_tag');
    $tags      = (!empty($tags_raw) && !is_wp_error($tags_raw)) ? wp_list_pluck($tags_raw, 'slug') : [];
    $ttype     = (string)(get_post_meta($bricks_template_id, '_bricks_template_type', true) ?: '');
    $type_keys = array_map('strtolower', $tags);
    if (empty($type_keys) && $ttype !== '') $type_keys = [strtolower($ttype)];
    $section_type = !empty($type_keys) ? $type_keys[0] : 'section';

    // Wireframe model laden
    global $wpdb;
    $table = $wpdb->prefix . 'aisb_wireframes';
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug=%s",
      $project_id, $sitemap_version_id, $page_slug
    ), ARRAY_A);

    if (!$row) wp_send_json_error(['message' => 'Wireframe not found'], 404);

    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) wp_send_json_error(['message' => 'Invalid model'], 500);

    // Nieuwe unieke UUID aanmaken
    $new_uuid = function_exists('wp_generate_uuid4')
      ? wp_generate_uuid4()
      : sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
          mt_rand(0, 0xffff), mt_rand(0, 0xffff),
          mt_rand(0, 0xffff),
          mt_rand(0, 0x0fff) | 0x4000,
          mt_rand(0, 0x3fff) | 0x8000,
          mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

    $new_section = [
      'uuid'                  => $new_uuid,
      'type'                  => $section_type,
      'bricks_template_id'    => $bricks_template_id,
      'bricks_template_title' => $tpl_post->post_title,
      'bricks_template_ttype' => $ttype,
      'layout_key'            => 'bricks_' . $bricks_template_id,
      'match_tags'            => implode(', ', $tags),
      'preview_schema'        => null,
      'ai_wireframe_id'       => null,
    ];

    // Sectie invoegen na after_uuid (of toevoegen aan het einde)
    $sections  = $model['sections'] ?? [];
    $insert_at = count($sections); // default: append
    if ($after_uuid) {
      foreach ($sections as $i => $s) {
        if (is_array($s) && ($s['uuid'] ?? '') === $after_uuid) {
          $insert_at = $i + 1;
          break;
        }
      }
    }
    array_splice($sections, $insert_at, 0, [$new_section]);
    $model['sections'] = $sections;

    // Model opslaan (compiled cache wissen)
    $wpdb->update($table,
      ['model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES), 'compiled_bricks_json' => null, 'updated_at' => current_time('mysql')],
      ['project_id' => $project_id, 'sitemap_version_id' => $sitemap_version_id, 'page_slug' => $page_slug],
      ['%s', '%s', '%s'], ['%d', '%d', '%s']
    );

    // AI fill voor uitsluitend de nieuwe sectie
    $ai    = new AISB_Wireframes_AI();
    $model = $ai->populate_bricks_content_with_ai($model, $project_id, $sitemap_version_id, $page_slug, [$new_uuid]);

    // Bijgewerkt model opslaan
    $wpdb->update($table,
      ['model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES), 'updated_at' => current_time('mysql')],
      ['project_id' => $project_id, 'sitemap_version_id' => $sitemap_version_id, 'page_slug' => $page_slug],
      ['%s', '%s'], ['%d', '%d', '%s']
    );

    // ai_wireframe_id ophalen
    $new_ai_id = 0;
    foreach (($model['sections'] ?? []) as $s) {
      if (!is_array($s) || ($s['uuid'] ?? '') !== $new_uuid) continue;
      $new_ai_id = (int)($s['ai_wireframe_id'] ?? 0);
      break;
    }

    error_log('[AISB] ajax_insert_section: new_uuid=' . $new_uuid . ' ai_id=' . $new_ai_id);

    wp_send_json_success([
      'ai_wireframe_id'    => $new_ai_id,
      'uuid'               => $new_uuid,
      'type'               => $section_type,
      'bricks_template_id' => $bricks_template_id,
    ]);
  }

  /**
   * AJAX: Exporteer alle design-data (pagina's + secties + style guide + patches) als één JSON
   * zodat een Figma-plugin het kan importeren.
   */
  public function ajax_export_figma_json(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'aisb_sg_nonce')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    if (!$project_id) wp_send_json_error(['message' => 'Missing project_id'], 400);

    $project = get_post($project_id);
    if (!$project || $project->post_type !== 'aisb_project' || (int) $project->post_author !== (int) get_current_user_id()) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    // Bricks global classes — build ID→class map once for the entire export
    $global_classes_raw = get_option('bricks_global_classes', []);
    if (!is_array($global_classes_raw)) $global_classes_raw = [];
    $class_map = [];
    foreach ($global_classes_raw as $gc) {
      if (!empty($gc['id'])) $class_map[$gc['id']] = $gc;
    }

    // Style guide
    $guide_raw   = (string) get_post_meta($project_id, 'aisb_style_guide', true);
    $style_guide = $guide_raw ? json_decode($guide_raw, true) : [];
    if (!is_array($style_guide)) $style_guide = [];

    // Laatste sitemap
    $sitemap_id = (int) get_post_meta($project_id, 'aisb_latest_sitemap_id', true);
    if (!$sitemap_id) {
      wp_send_json_success(['export' => $this->_build_figma_export($project_id, $style_guide, [])]);
      return;
    }

    $sitemap_json = (string) get_post_meta($sitemap_id, 'aisb_sitemap_json', true);
    $sitemap_data = $sitemap_json ? json_decode($sitemap_json, true) : [];
    $sitemap_pages = [];
    if (!empty($sitemap_data['sitemap']) && is_array($sitemap_data['sitemap'])) {
      $sitemap_pages = $sitemap_data['sitemap'];
    } elseif (!empty($sitemap_data['pages']) && is_array($sitemap_data['pages'])) {
      $sitemap_pages = $sitemap_data['pages'];
    }

    $page_slugs = [];
    foreach ($sitemap_pages as $p) {
      $slug = sanitize_title($p['slug'] ?? $p['page_slug'] ?? $p['url'] ?? $p['path'] ?? '');
      if ($slug) $page_slugs[] = $slug;
    }

    if (empty($page_slugs)) {
      wp_send_json_success(['export' => $this->_build_figma_export($project_id, $style_guide, [])]);
      return;
    }

    // Wireframe-modellen ophalen
    global $wpdb;
    $table        = $wpdb->prefix . 'aisb_wireframes';
    $placeholders = implode(',', array_fill(0, count($page_slugs), '%s'));
    $query_args   = array_merge([$project_id, $sitemap_id], $page_slugs);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT page_slug, model_json FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug IN ({$placeholders})",
      ...$query_args
    ), ARRAY_A);

    $models_by_slug = [];
    foreach (($rows ?: []) as $row) {
      $models_by_slug[$row['page_slug']] = json_decode($row['model_json'], true);
    }

    // Exportpagina's opbouwen
    $export_pages = [];
    foreach ($page_slugs as $slug) {
      $model = $models_by_slug[$slug] ?? null;
      if (!$model || empty($model['sections'])) continue;

      $export_sections = [];
      foreach ($model['sections'] as $s) {
        $ai_id   = !empty($s['ai_wireframe_id'])    ? (int) $s['ai_wireframe_id']    : 0;
        $tmpl_id = !empty($s['bricks_template_id']) ? (int) $s['bricks_template_id'] : 0;

        $patch_raw  = $ai_id ? (string) get_post_meta($ai_id, '_aisb_design_patch', true) : '';
        $patch_data = ($patch_raw !== '') ? json_decode($patch_raw, true) : [];
        if (!is_array($patch_data)) $patch_data = [];

        // Bricks-elementen ophalen — zelfde volgorde als AISB_Wireframes_AI gebruikt.
        $post_id_for_content = $ai_id ?: $tmpl_id;
        $bricks_elements     = [];
        if ($post_id_for_content) {
          foreach (['_bricks_page_content_2', '_bricks_data', '_bricks_page_header_2', '_bricks_page_footer_2'] as $meta_key) {
            $raw = get_post_meta($post_id_for_content, $meta_key, true);
            if (is_array($raw) && !empty($raw)) {
              $bricks_elements = $raw;
              break;
            }
            if (is_string($raw) && $raw !== '') {
              $decoded = json_decode($raw, true);
              if (is_array($decoded) && !empty($decoded)) {
                $bricks_elements = $decoded;
                break;
              }
            }
          }
        }

        // Replace local logo URLs with inline data URIs so the Figma plugin
        // can display the logo without access to the local WordPress install.
        $this->_sanitize_local_logo_urls($bricks_elements);

        // Resolve Bricks global class settings into each element so the Figma
        // plugin sees all effective styles directly on the element.
        $this->_resolve_global_classes($bricks_elements, $class_map);

        // Pas expliciete property inheritance toe (Figma heeft geen DOM CSS cascade)
        $this->_apply_inherited_styles($bricks_elements);

        $texts  = [];
        $images = [];
        $this->_extract_figma_content($bricks_elements, $texts, $images);

        // Count actual Bricks image elements — used by Figma plugin to assign style_guide images.
        $image_count = $this->_count_bricks_image_elements($bricks_elements);

        $export_sections[] = [
          'uuid'               => $s['uuid'] ?? '',
          'type'               => $s['type'] ?? 'generic',
          'layout_key'         => $s['layout_key'] ?? '',
          'bricks_template_id' => $tmpl_id,
          'ai_wireframe_id'    => $ai_id,
          'patch'              => $patch_data,
          'image_count'        => $image_count,
          // bg_index (0 = even/licht, 1 = oneven/donker) vanuit het opgeslagen
          // wireframe-model. De JS-export overschrijft dit met de live iframe-waarde.
          'bg_index'           => isset($s['bg_index']) ? (int) $s['bg_index'] : null,
          // Volledige Bricks-elementenstructuur zoals die in de iframe gerenderd
          // wordt. Dit is wat de Figma-plugin nodig heeft om het echte design op
          // te bouwen i.p.v. enkel de patches.
          'bricks_elements'    => $bricks_elements,
          'content'            => [
            'texts'  => $texts,
            'images' => $images,
          ],
        ];
      }

      $export_pages[] = [
        'slug'     => $slug,
        'title'    => $model['page']['title'] ?? ucfirst(str_replace('-', ' ', $slug)),
        'sections' => $export_sections,
      ];
    }

    wp_send_json_success(['export' => $this->_build_figma_export($project_id, $style_guide, $export_pages)]);
  }

  private function _build_figma_export(int $project_id, array $style_guide, array $pages): array {
    $global_classes = get_option('bricks_global_classes', []);
    if (!is_array($global_classes)) $global_classes = [];

    // Bricks Theme Styles ophalen
    $theme_styles_query = get_posts([
      'post_type'      => 'bricks_theme_style',
      'post_status'    => 'publish',
      'posts_per_page' => -1
    ]);
    $theme_styles = [];
    foreach ($theme_styles_query as $ts) {
      $settings = get_post_meta($ts->ID, '_bricks_theme_style_settings', true);
      if (empty($settings) || !is_array($settings)) {
        // Fallback or sometimes settings are in post_content if old format, but usually post_meta
        $content = json_decode($ts->post_content, true);
        if (is_array($content)) $settings = $content;
      }
      if (is_array($settings)) {
        $theme_styles[] = [
          'id'       => $ts->ID,
          'title'    => $ts->post_title,
          'settings' => $settings
        ];
      }
    }

    // Build color map from Bricks globals and the AISB style guide so
    // var(--xxx) references become self-contained hex values in the export.
    $color_map = $this->_build_color_map($style_guide);

    // Convert local logo URL to an inline data URI so the Figma plugin can
    // display it without needing access to the local WordPress install.
    if (!empty($style_guide['logoUrl'])) {
      $style_guide['logoUrl'] = $this->_url_to_data_uri($style_guide['logoUrl']);
    }
    if (!empty($style_guide['uploadedImages']) && is_array($style_guide['uploadedImages'])) {
      foreach ($style_guide['uploadedImages'] as &$img) {
        if (!empty($img['thumb'])) $img['thumb'] = $this->_url_to_data_uri($img['thumb']);
        if (!empty($img['full']))  $img['full']  = $this->_url_to_data_uri($img['full']);
      }
      unset($img);
    }

    // Resolve color vars in global_classes settings
    foreach ($global_classes as &$gc) {
      if (!empty($gc['settings']) && is_array($gc['settings'])) {
        $this->_resolve_color_vars($gc['settings'], $color_map);
      }
    }
    unset($gc);

    // Resolve color vars in all page data, including bricks_elements and patches.
    foreach ($pages as &$page) {
      if (is_array($page)) $this->_resolve_color_vars($page, $color_map);
    }
    unset($page);

    // Resolve in Theme Styles
    $this->_resolve_color_vars($theme_styles, $color_map);

    return [
      'version'        => '1.1',
      'exported_at'    => gmdate('c'),
      'project_id'     => $project_id,
      'project_name'   => get_the_title($project_id),
      'style_guide'    => $style_guide,
      'global_classes' => $global_classes,
      'theme_styles'   => $theme_styles,
      'pages'          => $pages,
    ];
  }

  /**
   * Build a map of Bricks color IDs and CSS var names to hex values.
   * Bricks global colors are optional in this workflow, so the saved AISB
   * style guide is also used as a fallback source.
   */
  private function _build_color_map(array $style_guide = []): array {
    $map = [];

    $global_colors = get_option('bricks_global_colors', []);
    if (is_array($global_colors)) {
      foreach ($global_colors as $color) {
        if (!is_array($color)) continue;

        $hex = $this->_extract_color_hex($color);
        if (!$hex) continue;

        foreach (['id', 'slug', 'name', 'label'] as $key) {
          if (!empty($color[$key]) && is_string($color[$key])) {
            $this->_register_color_token($map, $color[$key], $hex);
            if ($key === 'id') {
              // Bricks uses id for variables: var(--bricks-color-{id})
              $this->_register_color_token($map, 'bricks-color-' . $color['id'], $hex);
            }
          }
        }
      }
    }

    if (!empty($style_guide['colours']) && is_array($style_guide['colours'])) {
      $fallback_slugs = ['primary', 'secondary', 'accent', 'dark', 'light', 'neutral'];

      foreach ($style_guide['colours'] as $index => $color) {
        if (!is_array($color) || empty($color['hex'])) continue;

        $hex = $this->_normalise_hex_color((string) $color['hex']);
        if (!$hex) continue;

        if (!empty($color['name']) && is_string($color['name'])) {
          $this->_register_color_token($map, $color['name'], $hex);
        }

        if (isset($fallback_slugs[$index])) {
          $this->_register_color_token($map, $fallback_slugs[$index], $hex);
        }
      }
    }

    if (!isset($map['--base'])) {
      if (isset($map['--dark'])) {
        $this->_register_color_token($map, 'base', $map['--dark']);
      } elseif (isset($map['--neutral'])) {
        $this->_register_color_token($map, 'base', $map['--neutral']);
      }
    }

    if (!isset($map['--white'])) $this->_register_color_token($map, 'white', '#ffffff');
    if (!isset($map['--black'])) $this->_register_color_token($map, 'black', '#000000');

    return $map;
  }

  private function _extract_color_hex(array $color): string {
    foreach (['hex', 'value', 'raw', 'color'] as $key) {
      if (!empty($color[$key]) && is_string($color[$key])) {
        $hex = $this->_normalise_hex_color($color[$key]);
        if ($hex) return $hex;
      }
    }

    foreach ($color as $value) {
      if (is_array($value)) {
        $hex = $this->_extract_color_hex($value);
        if ($hex) return $hex;
      }
    }

    return '';
  }

  private function _register_color_token(array &$map, string $token, string $hex): void {
    $hex = $this->_normalise_hex_color($hex);
    if (!$hex) return;

    $token = strtolower(trim($token));
    if ($token === '') return;

    $map[$token] = $hex;

    $slug = preg_replace('/^acss_import_/', '', $token);
    $slug = preg_replace('/^--/', '', (string) $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', (string) $slug);
    $slug = trim((string) $slug, '-');
    if ($slug === '') return;

    $map['--' . $slug] = $hex;
    $map['acss_import_' . $slug] = $hex;

    $this->_register_color_variants($map, $slug, $hex);
  }

  private function _register_color_variants(array &$map, string $slug, string $hex): void {
    if (preg_match('/(?:^|-)(?:trans-\d+|\d+)$/', $slug)) return;

    foreach ([5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 95] as $percent) {
      $transparent_hex = $this->_hex_with_alpha($hex, $percent);
      $map['--' . $slug . '-' . $percent] = $map['--' . $slug . '-' . $percent] ?? $transparent_hex;
      $map['--' . $slug . '-trans-' . $percent] = $map['--' . $slug . '-trans-' . $percent] ?? $transparent_hex;
      $map['acss_import_' . $slug . '-' . $percent] = $map['acss_import_' . $slug . '-' . $percent] ?? $transparent_hex;
      $map['acss_import_' . $slug . '-trans-' . $percent] = $map['acss_import_' . $slug . '-trans-' . $percent] ?? $transparent_hex;
    }

    $light_variants = [
      'ultra-light' => 0.92,
      'light'       => 0.72,
    ];

    foreach ($light_variants as $suffix => $amount) {
      $variant_hex = $this->_mix_hex_colors($hex, '#ffffff', $amount);
      $map['--' . $slug . '-' . $suffix] = $map['--' . $slug . '-' . $suffix] ?? $variant_hex;
      $map['acss_import_' . $slug . '-' . $suffix] = $map['acss_import_' . $slug . '-' . $suffix] ?? $variant_hex;
    }
  }

  private function _normalise_hex_color(string $color): string {
    $color = trim($color);

    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color, $match)) {
      $hex = strtolower($match[1]);

      if (strlen($hex) === 3 || strlen($hex) === 4) {
        $expanded = '';
        foreach (str_split($hex) as $char) {
          $expanded .= $char . $char;
        }
        $hex = $expanded;
      }

      return '#' . $hex;
    }

    return '';
  }

  private function _hex_with_alpha(string $hex, int $percent): string {
    $hex = $this->_normalise_hex_color($hex);
    if (!$hex) return '';

    $base_hex = substr($hex, 0, 7);
    $alpha = max(0, min(255, (int) round(255 * ($percent / 100))));

    return $base_hex . str_pad(dechex($alpha), 2, '0', STR_PAD_LEFT);
  }

  private function _mix_hex_colors(string $from_hex, string $to_hex, float $amount): string {
    $from_rgb = $this->_hex_to_rgb($from_hex);
    $to_rgb = $this->_hex_to_rgb($to_hex);
    if (!$from_rgb || !$to_rgb) return '';

    $mixed = [];
    for ($index = 0; $index < 3; $index++) {
      $mixed[$index] = (int) round($from_rgb[$index] + (($to_rgb[$index] - $from_rgb[$index]) * $amount));
    }

    return sprintf('#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2]);
  }

  private function _hex_to_rgb(string $hex): array {
    $hex = $this->_normalise_hex_color($hex);
    if (!$hex) return [];

    $base_hex = substr($hex, 1, 6);

    return [
      hexdec(substr($base_hex, 0, 2)),
      hexdec(substr($base_hex, 2, 2)),
      hexdec(substr($base_hex, 4, 2)),
    ];
  }

  /**
   * Recursively walk a Bricks settings array and resolve color-reference objects
   * from { raw: "var(--xxx)", id: "...", name: "..." } to { raw: "#rrggbb", ... }.
   */
  private function _resolve_color_vars(array &$settings, array $color_map): void {
    foreach ($settings as &$val) {
      if (is_array($val)) {
        if (isset($val['raw']) && is_string($val['raw']) && strpos($val['raw'], 'var(') === 0) {
          $resolved = null;
          // Try Bricks color ID first.
          if (!empty($val['id'])) {
            $id = strtolower((string) $val['id']);
            if (isset($color_map[$id])) {
              $resolved = $color_map[$id];
            }
          }
          // Fall back: extract the CSS var name, e.g. --neutral-trans-80.
          if (!$resolved) {
            preg_match('/var\(\s*(--[^,)\s]+)/', $val['raw'], $matches);
            if (!empty($matches[1])) {
              $css_var = strtolower($matches[1]);
              if (isset($color_map[$css_var])) {
                $resolved = $color_map[$css_var];
              }
            }
          }
          if ($resolved) {
            // Bewaar de originele variabele in "variable" zodat de Figma plugin
            // zijn eigen Variable Mapper of Figma Variables kan gebruiken,
            // zoals gevraagd in de debugging-feedback (behouden van var referenties).
            $val['variable'] = $val['raw'];
            $val['raw'] = $resolved;
          }
        }
        $this->_resolve_color_vars($val, $color_map);
      }
    }
    unset($val);
  }

  /**
   * Recursively walk Bricks elements and resolve color vars in their settings.
   */
  private function _resolve_color_vars_in_elements(array &$elements, array $color_map): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;
      if (!empty($el['settings']) && is_array($el['settings'])) {
        $this->_resolve_color_vars($el['settings'], $color_map);
      }
      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_resolve_color_vars_in_elements($el['children'], $color_map);
      }
    }
    unset($el);
  }

  /**
   * Recursief overerving toepassen voor tekstkleur/uitlijning (Figma heeft geen cascade).
   * Hierdoor hebben child-text blokken expliciete styling gebaseerd op hun containers.
   */
  private function _apply_inherited_styles(array &$elements, array $inherited = []): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;

      // Huidige settings (inclusief global classes) combineren met inherited
      $settings = $el['settings'] ?? [];
      
      // Wat kunnen we overerven?
      $current_inherit = $inherited;

      // Color uit _typography of _typography:hover (simpel gehouden)
      if (isset($settings['_typography']['color'])) {
        $current_inherit['color'] = $settings['_typography']['color'];
      } elseif (isset($settings['_typography']['color']['hex'])) {
        $current_inherit['color'] = $settings['_typography']['color']['hex'];
      } elseif (isset($settings['_typography']['color']['raw'])) {
        $current_inherit['color'] = $settings['_typography']['color']['raw'];
      }

      // Text align
      if (isset($settings['_typography']['text-align'])) {
        $current_inherit['text-align'] = $settings['_typography']['text-align'];
      }

      // Als dit een text-node is en hij heeft GEEN eigen color, dan vullen we aan uit inherit
      $is_text_element = in_array($el['name'] ?? '', ['text', 'heading', 'text-basic', 'button', 'icon', 'rich-text'], true);
      if ($is_text_element) {
        if (!isset($settings['_typography'])) {
          $settings['_typography'] = [];
        }
        if (!isset($settings['_typography']['color']) && isset($current_inherit['color'])) {
          $settings['_typography']['color'] = is_array($current_inherit['color']) ? $current_inherit['color'] : ['raw' => $current_inherit['color']];
        }
        if (!isset($settings['_typography']['text-align']) && isset($current_inherit['text-align'])) {
          $settings['_typography']['text-align'] = $current_inherit['text-align'];
        }
      }

      $el['settings'] = $settings;

      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_apply_inherited_styles($el['children'], $current_inherit);
      }
    }
    unset($el);
  }

  /**
   * Recursively merge Bricks global class settings into each element's settings.
   * Elements' own inline settings take priority over class settings.
   * This means the Figma plugin sees all effective styles directly on the element.
   */
  private function _resolve_global_classes(array &$elements, array $class_map): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;

      $class_ids = $el['settings']['_cssGlobalClasses'] ?? [];
      if (!empty($class_ids) && is_array($class_ids)) {
        $merged = [];
        foreach ($class_ids as $cid) {
          if (!empty($class_map[$cid]['settings']) && is_array($class_map[$cid]['settings'])) {
            // Later classes win over earlier classes
            $merged = array_merge($merged, $class_map[$cid]['settings']);
          }
        }
        if (!empty($merged)) {
          // Element's own inline settings override class settings
          $el['settings'] = array_merge($merged, $el['settings']);
        }
      }

      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_resolve_global_classes($el['children'], $class_map);
      }
    }
    unset($el);
  }

  /**
   * Convert a local WordPress URL to a base64 data URI so it is accessible
   * outside the local dev environment (e.g. inside a Figma plugin).
   * External URLs are returned unchanged.
   */
  private function _url_to_data_uri(string $url): string {
    if (empty($url)) return $url;

    $home = untrailingslashit(home_url());
    if (strpos($url, $home) !== 0) {
      return $url; // External URL — leave as-is.
    }

    $relative = substr($url, strlen($home));
    $path     = untrailingslashit(ABSPATH) . $relative;
    $path     = wp_normalize_path($path);

    if (!is_file($path) || !is_readable($path)) {
      return $url;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    $content = @file_get_contents($path);
    if ($content === false) return $url;

    $mime = mime_content_type($path) ?: 'application/octet-stream';
    return 'data:' . $mime . ';base64,' . base64_encode($content);
  }

  /**
   * Recursively replace local logo URLs inside Bricks elements with data URIs.
   */
  private function _sanitize_local_logo_urls(array &$elements): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;
      if (($el['name'] ?? '') === 'logo' && isset($el['settings']['logo']['url'])) {
        $el['settings']['logo']['url'] = $this->_url_to_data_uri($el['settings']['logo']['url']);
      }
      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_sanitize_local_logo_urls($el['children']);
      }
    }
    unset($el);
  }

  /**
   * Recursively count Bricks 'image' elements — matches the Figma-plugin traversal order.
   * Includes both 'image' elements and 'image-gallery' element items.
   */
  private function _count_bricks_image_elements(array $elements): int {
    $count = 0;
    foreach ($elements as $el) {
      if (!is_array($el)) continue;
      $name = $el['name'] ?? '';
      if ($name === 'image') {
        $count++;
      } elseif ($name === 'image-gallery') {
        $items = $el['settings']['items']['images'] ?? [];
        if (is_array($items)) {
          $count += count($items);
        }
      }
      if (!empty($el['children']) && is_array($el['children'])) {
        $count += $this->_count_bricks_image_elements($el['children']);
      }
    }
    return $count;
  }

  /**
   * Recursief tekst- en afbeeldingsinhoud uit Bricks-elementen halen.
   */
  private function _extract_figma_content(array $elements, array &$texts, array &$images): void {
    foreach ($elements as $el) {
      if (!is_array($el)) continue;
      $name     = $el['name'] ?? '';
      $settings = $el['settings'] ?? [];

      if (in_array($name, ['text', 'heading', 'text-basic'], true)) {
        $text = $settings['text'] ?? $settings['content'] ?? '';
        if ($text) $texts[] = wp_strip_all_tags((string) $text);
      } elseif ($name === 'image') {
        $src = $settings['image']['url'] ?? $settings['src'] ?? '';
        if ($src) $images[] = (string) $src;
      } elseif ($name === 'button') {
        $text = $settings['text'] ?? $settings['label'] ?? '';
        if ($text) $texts[] = wp_strip_all_tags((string) $text);
      }

      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_extract_figma_content($el['children'], $texts, $images);
      }
    }
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

    wp_enqueue_script(
      'aisb-design-figma-export',
      AISB_PLUGIN_URL . 'assets/js/design/figma-export.js',
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
   * Render the Step 4 panel HTML inside the builder shortcode.
   */
  public static function render_design_html(int $project_id): void {
    // Embed the saved guide directly in HTML so design.js has it immediately,
    // without needing an extra AJAX round-trip. Because Save & Design awaits
    // the server save before navigating, the DB always has the latest data.

    if(!$project_id) {
      ?>
      <div class="aisb-design-wrap">
        <a href="http://ai-sitemap-generators.local/?aisb_step=2" style="padding: 1rem; background-color: #5398DB; color: white; text-decoration: none; border-radius: 25px; ">Please select a project to design in step 2.</a>
      </div>
    <?php
    } else {
    $guide_raw = $project_id ? (string) get_post_meta($project_id, 'aisb_style_guide', true) : '';
    // Convert logoUrl to inline data URI so the design canvas can display
    // the logo without relying on the local WordPress URL being accessible.
    if ($guide_raw) {
      $guide_arr = json_decode($guide_raw, true);
      if (is_array($guide_arr) && !empty($guide_arr['logoUrl'])
          && strpos($guide_arr['logoUrl'], 'data:') !== 0) {
        $guide_arr['logoUrl'] = $this->_url_to_data_uri($guide_arr['logoUrl']);
        $guide_raw = wp_json_encode($guide_arr, JSON_UNESCAPED_SLASHES);
      }
    }    ?>
    <div class="aisb-design-wrap" data-design
         data-design-project="<?php echo esc_attr($project_id); ?>"
         data-design-guide="<?php echo esc_attr($guide_raw ?: '{}'); ?>">
      <div class="aisb-design-toolbar">
        <span class="aisb-design-toolbar-title">Design Canvas</span>
        <button id="aisb-design-save-btn" class="aisb-design-save-btn" type="button" title="Alle wijzigingen opslaan">&#128190; Opslaan</button>
        <div class="aisb-figma-export-wrap" id="aisb-figma-export-wrap">
          <button class="aisb-design-save-btn aisb-design-figma-btn" type="button">&#128196; Export to Figma</button>
          <div class="aisb-figma-export-dropdown">
            <div class="aisb-figma-export-dropdown-inner">
              <button id="aisb-design-figma-copy-btn" type="button"> Copy JSON</button>
              <button id="aisb-design-figma-download-btn" type="button"> Download JSON</button>
            </div>
          </div>
        </div>
      </div>
      <div class="aisb-design-canvas" data-design-canvas></div>
      <p class="aisb-design-hint">Scroll to pan · Ctrl+scroll to zoom · Double-click to fit all</p>
    </div>
    <?php

      }
  }
}
