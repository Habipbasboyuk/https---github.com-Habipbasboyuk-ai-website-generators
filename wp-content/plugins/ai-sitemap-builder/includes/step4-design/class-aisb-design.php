<?php

if (!defined('ABSPATH')) exit;

/**
 * Stap 4: designpreview.
 *
 * Toont volledige wireframepagina's met stijlgids-overrides, laat secties
 * vervangen of herschikken en bouwt de Figma-exportpayload.
 */
class AISB_Design {

  /**
   * Registreert assets en AJAX-acties voor de designstap.
   */
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
        if ($type === 'img') {
          $src = $op['src'] ?? '';
          // Bricks kan src opslaan als array { url, id } — normaliseer naar string.
          if (is_array($src)) {
            $src = (string) ($src['url'] ?? $src['src'] ?? $src['full'] ?? '');
          }
          $src = (string) $src;
          if (strpos($src, 'data:image') === 0) {
            $entry['src'] = $src; // Sta base64/data URI toe zonder dat esc_url_raw het stript
          } else {
            $entry['src'] = esc_url_raw($src);
          }
        }
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

    $color_map = $this->_build_color_map($style_guide);

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

        // Saniteer bestaande img-patches waarbij src per ongeluk een array is
        // (door Bricks { url, id } object-formaat dat eerder niet genormaliseerd werd).
        foreach ($patch_data as &$op) {
          if (isset($op['type']) && $op['type'] === 'img' && isset($op['src']) && is_array($op['src'])) {
            $op['src'] = (string) ($op['src']['url'] ?? $op['src']['src'] ?? $op['src']['full'] ?? '');
          }
        }
        unset($op);

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

        // Resolve color variables before compact content extraction so text
        // color metadata is self-contained in content.text_styles.
        $this->_resolve_color_vars($bricks_elements, $color_map);

        // Pas expliciete property inheritance toe (Figma heeft geen DOM CSS cascade)
        $this->_apply_inherited_styles($bricks_elements);

        // Normaliseer Bricks radius objects naar _borderRadius zodat de JSON
        // direct bruikbaar is voor Figma en de bestaande importer.
        $this->_normalise_border_radius_settings($bricks_elements);

        // Bewaar de structuur ná style-resoluties (kleuren, global classes,
        // border-radius) maar vóór Figma-specifieke structurele wijzigingen
        // (accordion expand, dropdown expand, FAQ flatten). Dit is de versie
        // die bij publish naar Bricks wordt gebruikt: styles kloppen, en de
        // accordion/dropdown blijft als echte interactieve component.
        $bricks_elements_bricks = $bricks_elements;

        // Zorg dat accordion/FAQ-items uitgeklapt worden geëxporteerd zodat ze
        // in Figma zichtbaar en bewerkbaar zijn.
        $this->_expand_accordion_items($bricks_elements);

        // Zorg dat nav-nested dropdowns uitgeklapt worden geëxporteerd.
        $this->_expand_dropdown_items($bricks_elements);

        // Zorg dat form-elementen zichtbaar worden in Figma.
        $this->_expand_form_elements($bricks_elements);

        $texts          = [];
        $images         = [];
        $text_styles    = [];
        $element_styles = [];
        $border_radii   = [];
        $this->_extract_figma_content($bricks_elements, $texts, $images, $text_styles, $element_styles, $border_radii);
        $this->_append_patch_border_radius_content($patch_data, $element_styles, $border_radii);

        if (($s['type'] ?? '') === 'faq') {
          $static_faq = $this->_flatten_faq_elements_for_figma($bricks_elements, $text_styles);
          if ($static_faq !== null) {
            $bricks_elements = $static_faq['bricks_elements'];
            $texts           = $static_faq['texts'];
            $text_styles     = $static_faq['text_styles'];
            $element_styles  = [];
            $border_radii    = [];
            $images          = [];
          }
        }

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
          'bricks_elements'        => $bricks_elements,
          // Originele structuur vóór Figma-modificaties — gebruikt bij publish
          // naar Bricks zodat accordion-elementen (FAQ) intact blijven.
          'bricks_elements_bricks' => $bricks_elements_bricks,
          'content'            => [
            'texts'       => $texts,
            'text_styles' => $text_styles,
            'text_colors' => array_map(static function ($style) {
              return is_array($style) ? (string)($style['color'] ?? '') : '';
            }, $text_styles),
            'element_styles' => $element_styles,
            'border_radii'   => $border_radii,
            'images'         => $images,
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

    // Convert Bricks _background objects to settings.background CSS strings.
    // Must run after color var resolution so var() references are already hex values.
    foreach ($pages as &$page) {
      if (!empty($page['sections']) && is_array($page['sections'])) {
        foreach ($page['sections'] as &$sec) {
          if (!empty($sec['bricks_elements']) && is_array($sec['bricks_elements'])) {
            $this->_resolve_backgrounds_to_css($sec['bricks_elements']);
          }
        }
        unset($sec);
      }
    }
    unset($page);

    // Resolve in Theme Styles
    $this->_resolve_color_vars($theme_styles, $color_map);

    // Bricks global variables / color palette drive every `var(--xxx)`
    // reference used by the global classes and theme styles. They live in their
    // own options and are NOT part of the page/class payload, so without them
    // the clone falls back to the framework's default colours (e.g. the wrong
    // button / background / accent colours). Ship them verbatim so the clone
    // resolves the exact same variables as the source site.
    $global_variables = get_option('bricks_global_variables', []);
    if (!is_array($global_variables)) $global_variables = [];
    $global_variables_categories = get_option('bricks_global_variables_categories', []);
    if (!is_array($global_variables_categories)) $global_variables_categories = [];
    $color_palette = get_option('bricks_color_palette', []);
    if (!is_array($color_palette)) $color_palette = [];

    return [
      'version'        => '1.1',
      'exported_at'    => gmdate('c'),
      'project_id'     => $project_id,
      'project_name'   => get_the_title($project_id),
      'style_guide'    => $style_guide,
      'global_classes' => $global_classes,
      'theme_styles'   => $theme_styles,
      'global_variables' => $global_variables,
      'global_variables_categories' => $global_variables_categories,
      'color_palette'  => $color_palette,
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
          $fallback = $fallback_slugs[$index];
          // Positional fallbacks are only a backstop for legacy style guides.
          // Never let them overwrite an explicitly named semantic color such as
          // "Dark" or "Light", otherwise later palette entries (for example a
          // complementary accent) can remap var(--dark) to the wrong color.
          if (!isset($map[$fallback]) && !isset($map['--' . $fallback])) {
            $this->_register_color_token($map, $fallback, $hex);
          }
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
   * Convert Bricks _background settings to a CSS background string (settings.background)
   * so the Figma plugin can read it directly. Bricks stores backgrounds as nested objects;
   * the Figma plugin expects a plain CSS value.
   *
   * Supported cases:
   *   _background.color.raw  → solid color (hex or resolved var)
   *   _background.image.gradient → linear/radial gradient CSS
   */
  private function _resolve_backgrounds_to_css(array &$elements): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;

      $settings = $el['settings'] ?? [];
      if (isset($settings['_background']) && is_array($settings['_background']) && !isset($settings['background'])) {
        $bg = $settings['_background'];
        $css = null;

        // Solid color
        if (!empty($bg['color']) && is_array($bg['color'])) {
          $raw = $bg['color']['raw'] ?? $bg['color']['hex'] ?? '';
          if (is_string($raw) && $raw !== '' && strpos($raw, 'var(') !== 0) {
            $css = $raw;
          }
        }

        // Gradient (Bricks stores gradient as a CSS string inside _background.image.gradient)
        if ($css === null && !empty($bg['image']['gradient']) && is_string($bg['image']['gradient'])) {
          $css = $bg['image']['gradient'];
        }

        if ($css !== null) {
          $el['settings']['background'] = $css;
        }
      }

      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_resolve_backgrounds_to_css($el['children']);
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
   * Zet alle Bricks accordion-items op 'open' zodat FAQ-secties volledig
   * uitgeklapt worden geëxporteerd naar Figma.
   *
   * Bricks accordion-nested: items zijn directe child-blocks. Open state wordt
   * bepaald door de `brx-open` CSS class op het item-block én door
   * settings['expandItem'] / settings['expandFirstItem'] op de container.
   *
   * Bricks accordion (legacy): items zijn sub-arrays in settings.items[].
   */
  private function _expand_accordion_items(array &$elements, ?string $parent_name = null): void {
    if ($parent_name === null && $this->_is_flat_bricks_element_list($elements)) {
      $this->_expand_flat_accordion_items($elements);
      return;
    }

    foreach ($elements as $i => &$el) {
      if (!is_array($el)) continue;

      $name = $el['name'] ?? '';

      // ── Bricks accordion-nested container ──────────────────────────────────
      if ($name === 'accordion-nested') {
        // Rename to "block" so Brixies treats it as a plain container
        $el['name'] = 'block';
        $name = 'block';
        $this->_append_css_class($el, 'aisb-figma-accordion');
      }

      // ── Direct child block van accordion-nested: zet display flex ──────────
      if ($parent_name === 'accordion-nested' && $name === 'block') {
        if (!isset($el['settings'])) $el['settings'] = [];
        $el['settings']['_display'] = 'flex';
        $el['settings']['display'] = 'flex';
        $el['settings']['_direction'] = 'column';
        $el['settings']['flexDirection'] = 'column';
      }

      // ── Bricks legacy accordion: items als sub-array in settings ───────────
      if ($name === 'accordion' && !empty($el['settings']['items']) && is_array($el['settings']['items'])) {
        // Rename so Brixies doesn't apply its collapsed rendering
        $el['name'] = 'block';
        $name = 'block';
        $this->_append_css_class($el, 'aisb-figma-accordion');
        foreach ($el['settings']['items'] as &$item) {
          if (is_array($item)) {
            $item['open'] = true;
          }
        }
        unset($item);
      }

      // ── Recurse into children ───────────────────────────────────────────────
      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_expand_accordion_items($el['children'], $name);
      }
    }
    unset($el);
  }

  private function _is_flat_bricks_element_list(array $elements): bool {
    foreach ($elements as $el) {
      if (!is_array($el)) continue;
      if (empty($el['children']) || !is_array($el['children'])) continue;
      foreach ($el['children'] as $child) {
        if (is_scalar($child)) return true;
      }
    }

    return false;
  }

  private function _expand_flat_accordion_items(array &$elements): void {
    $index_by_id = [];
    $children_by_parent = [];

    foreach ($elements as $index => $el) {
      if (!is_array($el)) continue;

      $id = (string)($el['id'] ?? '');
      if ($id !== '') $index_by_id[$id] = $index;

      $parent = (string)($el['parent'] ?? '');
      if ($parent !== '' && $parent !== '0') {
        $children_by_parent[$parent][] = $id;
      }
    }

    foreach ($elements as $index => &$el) {
      if (!is_array($el)) continue;

      $name = $el['name'] ?? '';

      if ($name === 'accordion-nested') {
        // Rename to "block" so Brixies/Figma plugin treats it as a plain
        // container instead of applying its built-in collapsed accordion rendering.
        $el['name'] = 'block';
        $this->_append_css_class($el, 'aisb-figma-accordion');

        if (!isset($el['settings']) || !is_array($el['settings'])) $el['settings'] = [];

        $accordion_id = (string)($el['id'] ?? '');
        $item_ids = $this->_accordion_direct_child_ids($el, $accordion_id, $children_by_parent);

        foreach ($item_ids as $item_id) {
          if ($item_id === '' || !isset($index_by_id[$item_id])) continue;

          $item_index = $index_by_id[$item_id];
          if (!isset($elements[$item_index]['settings']) || !is_array($elements[$item_index]['settings'])) {
            $elements[$item_index]['settings'] = [];
          }
          $elements[$item_index]['settings']['_display'] = 'flex';
          $elements[$item_index]['settings']['display'] = 'flex';
          $elements[$item_index]['settings']['_direction'] = 'column';
          $elements[$item_index]['settings']['flexDirection'] = 'column';

          $descendant_ids = $this->_flat_descendant_ids($item_id, $children_by_parent);
          foreach ($descendant_ids as $descendant_id) {
            if (!isset($index_by_id[$descendant_id])) continue;

            $descendant_index = $index_by_id[$descendant_id];
            $this->_expand_accordion_descendant($elements[$descendant_index]);
          }
        }
      }

      if ($name === 'accordion' && !empty($el['settings']['items']) && is_array($el['settings']['items'])) {
        // Rename so Brixies doesn't apply its collapsed rendering
        $el['name'] = 'block';
        $this->_append_css_class($el, 'aisb-figma-accordion');
        foreach ($el['settings']['items'] as &$item) {
          if (is_array($item)) $item['open'] = true;
        }
        unset($item);
      }
    }
    unset($el);
  }

  private function _accordion_direct_child_ids(array $accordion, string $accordion_id, array $children_by_parent): array {
    $ids = [];

    if (!empty($accordion['children']) && is_array($accordion['children'])) {
      foreach ($accordion['children'] as $child_id) {
        if (is_scalar($child_id)) $ids[] = (string)$child_id;
      }
    }

    if ($accordion_id !== '' && !empty($children_by_parent[$accordion_id])) {
      foreach ($children_by_parent[$accordion_id] as $child_id) {
        $ids[] = (string)$child_id;
      }
    }

    $ids = array_values(array_unique(array_filter($ids, static function ($id) {
      return $id !== '';
    })));

    return $ids;
  }

  private function _flat_descendant_ids(string $parent_id, array $children_by_parent): array {
    $result = [];
    $stack = $children_by_parent[$parent_id] ?? [];

    while ($stack) {
      $id = (string)array_shift($stack);
      if ($id === '' || in_array($id, $result, true)) continue;

      $result[] = $id;
      if (!empty($children_by_parent[$id])) {
        foreach ($children_by_parent[$id] as $child_id) {
          $stack[] = (string)$child_id;
        }
      }
    }

    return $result;
  }

  private function _expand_accordion_descendant(array &$el): void {
    if (!isset($el['settings']) || !is_array($el['settings'])) $el['settings'] = [];

    $settings = &$el['settings'];
    $class_string = $this->_settings_class_string($settings);

    $is_content_wrapper = strpos($class_string, 'accordion-content-wrapper') !== false;
    // Fallback: Bricks stores collapsed state in _hidden — expand any hidden
    // descendant inside an accordion even if the class name differs.
    $is_hidden_block = !$is_content_wrapper && !empty($settings['_hidden']) && is_array($settings['_hidden']);

    if ($is_content_wrapper || $is_hidden_block) {
      if ($is_content_wrapper) {
        // Remove the class so Brixies/Figma plugin doesn't apply its display:none CSS rule
        $this->_remove_css_class($el, 'accordion-content-wrapper');
      }
      $this->_append_css_class($el, 'aisb-figma-expanded-content');

      $settings['_display'] = 'flex';
      $settings['display'] = 'flex';
      $settings['_direction'] = 'column';
      $settings['flexDirection'] = 'column';
      $settings['_visibility'] = 'visible';
      $settings['visibility'] = 'visible';
      $settings['_opacity'] = '1';
      $settings['opacity'] = '1';
      $settings['_height'] = 'auto';
      $settings['height'] = 'auto';
      $settings['_overflow'] = 'visible';
      $settings['overflow'] = 'visible';
      $settings['ariaHidden'] = 'false';
      $settings['_cssCustom'] = trim((string)($settings['_cssCustom'] ?? '') . "\n&{display:flex!important;flex-direction:column!important;height:auto!important;opacity:1!important;visibility:visible!important;overflow:visible!important;}");

      unset($settings['_hidden']);

      $this->_remove_attribute($settings, 'aria-hidden');
      $this->_set_attribute($settings, 'aria-hidden', 'false');
    }

    if (($settings['customTag'] ?? '') === 'button' || $this->_has_attribute($settings, 'aria-expanded')) {
      $this->_set_attribute($settings, 'aria-expanded', 'true');
      $settings['ariaExpanded'] = 'true';
    }

    unset($settings);
  }

  private function _flatten_faq_elements_for_figma(array $elements, array $existing_text_styles): ?array {
    [$by_id, $children_by_parent] = $this->_flat_element_lookup($elements);
    $pairs = $this->_extract_static_faq_pairs($elements, $by_id, $children_by_parent);
    if (!$pairs) return null;

    $intro = $this->_extract_static_faq_intro($elements, $pairs, $by_id);
    $style_cursor = [
      'styles' => array_values(array_map(function ($style) {
        $style = is_array($style) ? $style : [];
        $style['text'] = $this->_clean_figma_text($style['text'] ?? '');
        return $style;
      }, $existing_text_styles)),
      'used' => [],
    ];

    $root = [];
    foreach ($elements as $el) {
      if (is_array($el) && ($el['name'] ?? '') === 'section' && (empty($el['parent']) || (string)$el['parent'] === '0')) {
        $root = $el;
        break;
      }
    }
    if (!$root && isset($elements[0]) && is_array($elements[0])) $root = $elements[0];
    if (!$root) $root = ['id' => 'aisb_faq_section', 'settings' => []];

    $root_id = (string)($root['id'] ?? 'aisb_faq_section');
    if ($root_id === '') $root_id = 'aisb_faq_section';

    $root['id'] = $root_id;
    $root['name'] = 'section';
    $root['parent'] = 0;
    $root['children'] = ['aisb_faq_container_' . $root_id];
    if (!isset($root['settings']) || !is_array($root['settings'])) $root['settings'] = [];
    unset($root['settings']['_cssGlobalClasses']);

    $container_id = 'aisb_faq_container_' . $root_id;
    $left_id = 'aisb_faq_left_' . $root_id;
    $right_id = 'aisb_faq_list_' . $root_id;

    $new_elements = [$root];
    $text_styles = [];
    $texts = [];

    $new_elements[] = [
      'id' => $container_id,
      'name' => 'container',
      'parent' => $root_id,
      'children' => [$left_id, $right_id],
      'settings' => [
        '_display' => 'grid',
        '_gridTemplateColumns' => 'repeat(2, minmax(0, 1fr))',
        '_gridGap' => 'var(--space-m)',
        '_gridTemplateColumns:tablet_portrait' => 'repeat(1, minmax(0, 1fr))',
        '_padding' => ['top' => '3rem', 'bottom' => '3rem', 'left' => '', 'right' => ''],
      ],
    ];

    $left_children = [];
    foreach ($intro as $idx => $_item) {
      $left_children[] = 'aisb_faq_intro_' . $root_id . '_' . $idx;
    }

    $new_elements[] = [
      'id' => $left_id,
      'name' => 'block',
      'parent' => $container_id,
      'children' => $left_children,
      'settings' => [
        '_display' => 'flex',
        '_direction' => 'column',
        '_rowGap' => 'var(--space-s)',
        '_padding' => ['top' => '3rem', 'bottom' => '3rem', 'left' => '', 'right' => ''],
      ],
      'label' => 'FAQ Intro',
    ];

    foreach ($intro as $idx => $item) {
      $id = $left_children[$idx];
      $tag = $idx === 0 ? 'h2' : 'p';
      $new_elements[] = $this->_make_static_faq_text_element($id, $left_id, $item['text'], $item['el'], $tag);
      $style = $this->_next_static_faq_text_style($style_cursor, $item['text'], $item['el'], $idx === 0 ? 'heading' : 'body');
      $texts[] = $item['text'];
      $text_styles[] = $style;
    }

    $right_children = [];
    foreach ($pairs as $idx => $_pair) {
      $right_children[] = 'aisb_faq_card_' . $root_id . '_' . $idx;
    }

    $new_elements[] = [
      'id' => $right_id,
      'name' => 'block',
      'parent' => $container_id,
      'children' => $right_children,
      'settings' => [
        '_display' => 'flex',
        '_direction' => 'column',
        '_rowGap' => 'var(--space-m)',
        '_padding' => ['top' => '3rem', 'bottom' => '3rem', 'left' => '', 'right' => ''],
      ],
      'label' => 'FAQ List',
    ];

    foreach ($pairs as $idx => $pair) {
      $card_id = $right_children[$idx];
      $question_id = 'aisb_faq_q_' . $root_id . '_' . $idx;
      $answer_id = 'aisb_faq_a_' . $root_id . '_' . $idx;

      $new_elements[] = [
        'id' => $card_id,
        'name' => 'block',
        'parent' => $right_id,
        'children' => [$question_id, $answer_id],
        'settings' => [
          '_display' => 'flex',
          '_direction' => 'column',
          '_rowGap' => 'var(--space-xs)',
          '_padding' => ['top' => 'var(--space-s)', 'bottom' => 'var(--space-s)', 'left' => '0', 'right' => '0'],
          '_border' => [
            'width' => ['bottom' => '1px'],
            'style' => 'solid',
            'color' => ['raw' => '#08264533'],
          ],
        ],
        'label' => 'FAQ Item',
      ];

      $new_elements[] = $this->_make_static_faq_text_element($question_id, $card_id, $pair['question'], $pair['question_el'], 'h3');
      $new_elements[] = $this->_make_static_faq_text_element($answer_id, $card_id, $pair['answer'], $pair['answer_el'], 'p');
      $texts[] = $pair['question'];
      $text_styles[] = $this->_next_static_faq_text_style($style_cursor, $pair['question'], $pair['question_el'], 'question');
      $texts[] = $pair['answer'];
      $text_styles[] = $this->_next_static_faq_text_style($style_cursor, $pair['answer'], $pair['answer_el'], 'answer');
    }

    return [
      'bricks_elements' => $new_elements,
      'texts' => $texts,
      'text_styles' => $text_styles,
    ];
  }

  private function _flat_element_lookup(array $elements): array {
    $by_id = [];
    $children_by_parent = [];
    foreach ($elements as $el) {
      if (!is_array($el)) continue;
      $id = (string)($el['id'] ?? '');
      if ($id !== '') $by_id[$id] = $el;
      $parent = (string)($el['parent'] ?? '');
      if ($parent !== '' && $parent !== '0') $children_by_parent[$parent][] = $id;
    }
    return [$by_id, $children_by_parent];
  }

  private function _extract_static_faq_pairs(array $elements, array $by_id, array $children_by_parent): array {
    $pairs = [];
    $seen = [];
    foreach ($elements as $wrapper) {
      if (!$this->_is_static_faq_answer_wrapper($wrapper)) continue;
      $wrapper_id = (string)($wrapper['id'] ?? '');
      if ($wrapper_id !== '' && isset($seen[$wrapper_id])) continue;
      if ($wrapper_id !== '') $seen[$wrapper_id] = true;

      $answer_ids = array_merge([$wrapper_id], $this->_flat_descendant_ids($wrapper_id, $children_by_parent));
      $answer_texts = [];
      $answer_el = $wrapper;
      foreach ($answer_ids as $id) {
        if (empty($by_id[$id]) || !$this->_is_static_text_like_element($by_id[$id])) continue;
        $answer_texts[] = $this->_clean_figma_text($by_id[$id]['settings']['text'] ?? '');
        $answer_el = $by_id[$id];
      }

      $parent_id = (string)($wrapper['parent'] ?? '');
      $question = '';
      $question_el = [];
      if ($parent_id !== '') {
        $answer_id_map = array_fill_keys($answer_ids, true);
        foreach ($this->_flat_descendant_ids($parent_id, $children_by_parent) as $candidate_id) {
          if (isset($answer_id_map[$candidate_id]) || empty($by_id[$candidate_id])) continue;
          if (!$this->_is_static_faq_question_element($by_id[$candidate_id])) continue;
          $question_el = $by_id[$candidate_id];
          $question = $this->_clean_figma_text($question_el['settings']['text'] ?? '');
          break;
        }
      }

      $answer_texts = array_values(array_unique(array_filter($answer_texts)));
      $answer = trim(implode("\n", $answer_texts));
      if ($question === '' || $answer === '') continue;

      $pairs[] = [
        'question' => $question,
        'answer' => $answer,
        'question_el' => $question_el,
        'answer_el' => $answer_el,
        'source_ids' => array_merge([$parent_id, $wrapper_id], $answer_ids),
      ];
    }
    return $pairs;
  }

  private function _extract_static_faq_intro(array $elements, array $pairs, array $by_id): array {
    $used = [];
    foreach ($pairs as $pair) {
      foreach (($pair['source_ids'] ?? []) as $id) if ($id !== '') $used[$id] = true;
      if (!empty($pair['question_el']['id'])) $used[(string)$pair['question_el']['id']] = true;
      if (!empty($pair['answer_el']['id'])) $used[(string)$pair['answer_el']['id']] = true;
    }

    $intro = [];
    foreach ($elements as $el) {
      if (!$this->_is_static_text_like_element($el)) continue;
      $id = (string)($el['id'] ?? '');
      if (isset($used[$id]) || $this->_is_descendant_of_used_static($el, $used, $by_id)) continue;
      $text = $this->_clean_figma_text($el['settings']['text'] ?? '');
      if ($text === '') continue;
      $duplicate = false;
      foreach ($intro as $item) {
        if ($item['text'] === $text && ($item['el']['name'] ?? '') === ($el['name'] ?? '')) {
          $duplicate = true;
          break;
        }
      }
      if (!$duplicate) $intro[] = ['text' => $text, 'el' => $el];
      if (count($intro) >= 4) break;
    }
    return $intro;
  }

  private function _is_descendant_of_used_static(array $el, array $used, array $by_id): bool {
    $parent = (string)($el['parent'] ?? '');
    while ($parent !== '' && $parent !== '0') {
      if (isset($used[$parent])) return true;
      $parent = isset($by_id[$parent]) ? (string)($by_id[$parent]['parent'] ?? '') : '';
    }
    return false;
  }

  private function _make_static_faq_text_element(string $id, string $parent, string $text, array $source_el, string $tag): array {
    $settings = isset($source_el['settings']) && is_array($source_el['settings']) ? $source_el['settings'] : [];
    unset($settings['_cssGlobalClasses'], $settings['_attributes'], $settings['_hidden']);
    $settings['text'] = $text;
    $settings['tag'] = $tag;
    $settings['_display'] = 'block';
    $settings['display'] = 'block';
    $settings['_visibility'] = 'visible';
    $settings['visibility'] = 'visible';
    $settings['_opacity'] = '1';
    $settings['opacity'] = '1';
    $settings['_overflow'] = 'visible';
    $settings['overflow'] = 'visible';
    $settings['_cssCustom'] = trim((string)($settings['_cssCustom'] ?? '') . "\n&{display:block!important;opacity:1!important;visibility:visible!important;overflow:visible!important;}");

    return [
      'id' => $id,
      'name' => preg_match('/^h[1-6]$/', $tag) ? 'heading' : 'text-basic',
      'parent' => $parent,
      'children' => [],
      'settings' => $settings,
    ];
  }

  private function _next_static_faq_text_style(array &$cursor, string $text, array $source_el, string $role): array {
    $clean = $this->_clean_figma_text($text);
    foreach ($cursor['styles'] as $idx => $style) {
      if (!empty($cursor['used'][$idx])) continue;
      if (($style['text'] ?? '') !== $clean) continue;
      $cursor['used'][$idx] = true;
      $style['text'] = $clean;
      return $style;
    }

    $typography = isset($source_el['settings']['_typography']) && is_array($source_el['settings']['_typography'])
      ? $source_el['settings']['_typography']
      : [];
    $is_heading = in_array($role, ['heading', 'question'], true);
    $tag = strtolower((string)($source_el['settings']['tag'] ?? ''));
    $heading_sizes = [
      'h1' => '64px',
      'h2' => '48px',
      'h3' => '36px',
      'h4' => '28px',
      'h5' => '22px',
      'h6' => '18px',
    ];
    $default_font_size = $is_heading ? ($heading_sizes[$tag] ?? '48px') : '18px';

    return [
      'text' => $clean,
      'color' => $this->_raw_color($typography['color'] ?? null) ?: '#082645',
      'fontSize' => (string)($typography['font-size'] ?? $default_font_size),
      'fontFamily' => (string)($typography['font-family'] ?? $typography['fontFamily'] ?? ''),
      'fontWeight' => (string)($typography['font-weight'] ?? ($is_heading ? '700' : '400')),
      'lineHeight' => (string)($typography['line-height'] ?? ($is_heading ? '1.12' : '1.6')),
      'textAlign' => 'start',
    ];
  }

  private function _raw_color($value): string {
    if (is_string($value)) return $value;
    if (is_array($value)) return (string)($value['raw'] ?? ($value['hex'] ?? ''));
    return '';
  }

  private function _is_static_faq_answer_wrapper($el): bool {
    if (!is_array($el)) return false;
    $label = strtolower((string)($el['label'] ?? ''));
    $settings = isset($el['settings']) && is_array($el['settings']) ? $el['settings'] : [];
    return $label === 'answer wrapper'
      || $this->_has_attribute_value($settings, 'itemprop', 'acceptedAnswer')
      || $this->_has_attribute_value($settings, 'itemtype', 'https://schema.org/Answer');
  }

  private function _is_static_faq_question_element($el): bool {
    if (!$this->_is_static_text_like_element($el)) return false;
    $label = strtolower((string)($el['label'] ?? ''));
    $settings = isset($el['settings']) && is_array($el['settings']) ? $el['settings'] : [];
    return $label === 'question' || $this->_has_attribute_value($settings, 'itemprop', 'name');
  }

  private function _is_static_text_like_element($el): bool {
    if (!is_array($el) || empty($el['settings']) || !is_array($el['settings'])) return false;
    $name = (string)($el['name'] ?? '');
    if (!in_array($name, ['heading', 'text', 'text-basic', 'button'], true)) return false;
    return $this->_clean_figma_text($el['settings']['text'] ?? '') !== '';
  }

  private function _clean_figma_text($value): string {
    $text = (string)$value;
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<\/p\s*>/i', "\n", $text);
    $text = wp_strip_all_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+\n/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
  }

  private function _settings_class_string(array $settings): string {
    $hidden = isset($settings['_hidden']) && is_array($settings['_hidden'])
      ? $settings['_hidden']
      : [];

    $parts = [
      (string)($settings['_cssClasses'] ?? ''),
      (string)($settings['cssClasses'] ?? ''),
      (string)($settings['class'] ?? ''),
      (string)($settings['_class'] ?? ''),
      (string)($hidden['_cssClasses'] ?? ''),
      (string)($hidden['cssClasses'] ?? ''),
    ];

    if (!empty($settings['_attributes']) && is_array($settings['_attributes'])) {
      foreach ($settings['_attributes'] as $attr) {
        if (!is_array($attr) || ($attr['name'] ?? '') !== 'class') continue;
        $parts[] = (string)($attr['value'] ?? '');
      }
    }

    return implode(' ', array_filter($parts, static function ($part) {
      return trim($part) !== '';
    }));
  }

  private function _append_css_class(array &$el, string $class): void {
    if (!isset($el['settings']) || !is_array($el['settings'])) $el['settings'] = [];

    $existing = trim((string)($el['settings']['_cssClasses'] ?? ''));
    $classes = $existing === '' ? [] : preg_split('/\s+/', $existing);
    if (!in_array($class, $classes, true)) $classes[] = $class;
    $el['settings']['_cssClasses'] = trim(implode(' ', $classes));
  }

  private function _remove_css_class(array &$el, string $class): void {
    if (!isset($el['settings']) || !is_array($el['settings'])) return;

    foreach (['_cssClasses', 'cssClasses', '_class', 'class'] as $key) {
      if (empty($el['settings'][$key])) continue;
      $parts = preg_split('/\s+/', (string)$el['settings'][$key]);
      $parts = array_values(array_filter($parts, static function ($c) use ($class) {
        return $c !== '' && $c !== $class;
      }));
      $el['settings'][$key] = implode(' ', $parts);
    }

    // Also strip from _hidden sub-object
    if (!empty($el['settings']['_hidden']) && is_array($el['settings']['_hidden'])) {
      foreach (['_cssClasses', 'cssClasses'] as $key) {
        if (empty($el['settings']['_hidden'][$key])) continue;
        $parts = preg_split('/\s+/', (string)$el['settings']['_hidden'][$key]);
        $parts = array_values(array_filter($parts, static function ($c) use ($class) {
          return $c !== '' && $c !== $class;
        }));
        $el['settings']['_hidden'][$key] = implode(' ', $parts);
      }
    }
  }

  private function _has_attribute(array $settings, string $name): bool {
    if (empty($settings['_attributes']) || !is_array($settings['_attributes'])) return false;

    foreach ($settings['_attributes'] as $attr) {
      if (is_array($attr) && ($attr['name'] ?? '') === $name) return true;
    }

    return false;
  }

  private function _has_attribute_value(array $settings, string $name, string $value): bool {
    if (empty($settings['_attributes']) || !is_array($settings['_attributes'])) return false;

    foreach ($settings['_attributes'] as $attr) {
      if (!is_array($attr)) continue;
      if (($attr['name'] ?? '') === $name && (string)($attr['value'] ?? '') === $value) return true;
    }

    return false;
  }

  private function _set_attribute(array &$settings, string $name, string $value): void {
    if (!isset($settings['_attributes']) || !is_array($settings['_attributes'])) {
      $settings['_attributes'] = [];
    }

    foreach ($settings['_attributes'] as &$attr) {
      if (!is_array($attr) || ($attr['name'] ?? '') !== $name) continue;
      $attr['value'] = $value;
      unset($attr);
      return;
    }
    unset($attr);

    $settings['_attributes'][] = [
      'id'    => 'aisb_' . substr(md5($name . $value), 0, 8),
      'name'  => $name,
      'value' => $value,
    ];
  }

  private function _remove_attribute(array &$settings, string $name): void {
    if (empty($settings['_attributes']) || !is_array($settings['_attributes'])) return;

    $settings['_attributes'] = array_values(array_filter($settings['_attributes'], static function ($attr) use ($name) {
      return !(is_array($attr) && ($attr['name'] ?? '') === $name);
    }));
  }

  // ── Dropdown / nav-nested expand ─────────────────────────────────────────

  private function _expand_dropdown_items(array &$elements): void {
    if ($this->_is_flat_bricks_element_list($elements)) {
      $this->_expand_flat_dropdown_items($elements);
      return;
    }

    foreach ($elements as &$el) {
      if (!is_array($el)) continue;

      $name = $el['name'] ?? '';
      if (!isset($el['settings']) || !is_array($el['settings'])) $el['settings'] = [];

      if ($name === 'nav-nested') {
        $el['name'] = 'block';
        $this->_append_css_class($el, 'aisb-figma-nav-nested');
      }

      if ($name === 'dropdown') {
        $el['name'] = 'block';
        $this->_append_css_class($el, 'aisb-figma-dropdown');
      }

      $class_string = $this->_settings_class_string($el['settings']);
      if (
        strpos($class_string, 'brx-dropdown-content') !== false ||
        strpos($class_string, 'brx-nav-nested-items') !== false
      ) {
        $this->_expand_dropdown_content($el);
      }

      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_expand_dropdown_items($el['children']);
      }
    }
    unset($el);
  }

  private function _expand_flat_dropdown_items(array &$elements): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;

      $name = $el['name'] ?? '';
      if (!isset($el['settings']) || !is_array($el['settings'])) $el['settings'] = [];

      if ($name === 'nav-nested') {
        $el['name'] = 'block';
        $this->_append_css_class($el, 'aisb-figma-nav-nested');
      }

      if ($name === 'dropdown') {
        $el['name'] = 'block';
        $this->_append_css_class($el, 'aisb-figma-dropdown');
      }

      $class_string = $this->_settings_class_string($el['settings']);
      if (
        strpos($class_string, 'brx-dropdown-content') !== false ||
        strpos($class_string, 'brx-nav-nested-items') !== false
      ) {
        $this->_expand_dropdown_content($el);
      }
    }
    unset($el);
  }

  private function _expand_dropdown_content(array &$el): void {
    if (!isset($el['settings']) || !is_array($el['settings'])) $el['settings'] = [];
    $settings = &$el['settings'];

    // Remove classes that trigger Bricks default display:none CSS in Figma plugin
    $this->_remove_css_class($el, 'brx-dropdown-content');
    $this->_remove_css_class($el, 'brx-nav-nested-items');
    $this->_append_css_class($el, 'aisb-figma-expanded-content');
    unset($settings['_hidden']);

    $settings['_display']    = 'block';
    $settings['display']     = 'block';
    $settings['_visibility'] = 'visible';
    $settings['visibility']  = 'visible';
    $settings['_opacity']    = '1';
    $settings['opacity']     = '1';
    $settings['_overflow']   = 'visible';
    $settings['overflow']    = 'visible';
    $settings['ariaHidden']  = 'false';
    $settings['_cssCustom']  = trim((string)($settings['_cssCustom'] ?? '') .
      "\n&{display:block!important;opacity:1!important;visibility:visible!important;overflow:visible!important;}");

    $this->_remove_attribute($settings, 'aria-hidden');
    $this->_set_attribute($settings, 'aria-hidden', 'false');

    unset($settings);
  }

  // ── Form expand ───────────────────────────────────────────────────────────

  private function _expand_form_elements(array &$elements): void {
    if ($this->_is_flat_bricks_element_list($elements)) {
      $this->_expand_flat_form_elements($elements);
      return;
    }
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;
      if (($el['name'] ?? '') === 'form') {
        $el['name'] = 'block';
        $this->_append_css_class($el, 'aisb-figma-form');
        $synth = $this->_form_fields_to_elements($el['settings']['fields'] ?? [], '');
        if (!empty($synth)) {
          // In nested format children are element objects, not IDs
          $el['children'] = array_merge((array)($el['children'] ?? []), $synth);
        }
      }
      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_expand_form_elements($el['children']);
      }
    }
    unset($el);
  }

  private function _expand_flat_form_elements(array &$elements): void {
    $new_elements = [];
    foreach ($elements as &$el) {
      if (!is_array($el) || ($el['name'] ?? '') !== 'form') continue;
      $el['name'] = 'block';
      $this->_append_css_class($el, 'aisb-figma-form');
      $form_id = (string)($el['id'] ?? '');
      if (!isset($el['children']) || !is_array($el['children'])) $el['children'] = [];
      foreach ($this->_form_fields_to_elements($el['settings']['fields'] ?? [], $form_id) as $synth) {
        $new_elements[]   = $synth;
        $el['children'][] = (string)$synth['id']; // keep parent→children in sync for Brixies DFS
      }
    }
    unset($el);
    foreach ($new_elements as $synth_el) {
      $elements[] = $synth_el;
    }
  }

  private function _form_fields_to_elements(array $fields, string $parent_id): array {
    $result = [];
    foreach ($fields as $idx => $field) {
      if (!is_array($field)) continue;
      $type        = $field['type'] ?? 'text';
      $label       = trim((string)($field['label'] ?? ''));       // explicit label only
      $placeholder = trim((string)($field['placeholder'] ?? ''));
      $synth_id    = 'aisb_f_' . ($field['id'] ?? $idx);

      if ($type === 'submit') {
        // Submit → button: Brixies reads settings.text directly, no text_styles position consumed.
        $btn_text = $label ?: $placeholder ?: ($field['value'] ?? 'Verzenden');
        $entry = ['id' => $synth_id, 'name' => 'button', 'settings' => ['text' => $btn_text], 'children' => []];
      } elseif ($label !== '') {
        // Field with explicit label → text: Bricks renders <label> in DOM so DOM text leaf exists.
        $entry = ['id' => $synth_id, 'name' => 'text', 'settings' => ['text' => $label], 'children' => []];
      } elseif ($placeholder !== '') {
        // Placeholder-only field → button: no <label> in DOM so we must not consume a text_styles position.
        $entry = ['id' => $synth_id, 'name' => 'button', 'settings' => ['text' => $placeholder], 'children' => []];
      } else {
        continue;
      }
      if ($parent_id !== '') $entry['parent'] = $parent_id;
      $result[] = $entry;
    }
    return $result;
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
  private function _extract_figma_content(array $elements, array &$texts, array &$images, array &$text_styles, array &$element_styles, array &$border_radii): void {
    foreach ($elements as $el) {
      if (!is_array($el)) continue;
      $name     = $el['name'] ?? '';
      $settings = $el['settings'] ?? [];
      $radius_map = $this->_extract_border_radius_map($settings);

      if (!empty($radius_map)) {
        $style_entry = [
          'id'    => (string)($el['id'] ?? ''),
          'name'  => (string)$name,
          'label' => (string)($el['label'] ?? ''),
        ];
        foreach ($radius_map as $prop => $value) {
          $style_entry[$prop] = $value;
        }
        $element_styles[] = $style_entry;

        foreach ($radius_map as $prop => $value) {
          if ($prop === '_borderRadius') continue;
          $border_radii[] = [
            'id'    => (string)($el['id'] ?? ''),
            'name'  => (string)$name,
            'label' => (string)($el['label'] ?? ''),
            'prop'  => (string)$prop,
            'value' => (string)$value,
          ];
        }
      }

      if (in_array($name, ['text', 'heading', 'text-basic'], true)) {
        $text = $settings['text'] ?? $settings['content'] ?? '';
        $this->_append_figma_text_content($text, $settings, $texts, $text_styles);
      } elseif ($name === 'image') {
        $src = $settings['image']['url'] ?? $settings['src'] ?? '';
        if ($src) $images[] = (string) $src;
      } elseif ($name === 'button') {
        $text = $settings['text'] ?? $settings['label'] ?? '';
        $this->_append_figma_text_content($text, $settings, $texts, $text_styles);
      }

      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_extract_figma_content($el['children'], $texts, $images, $text_styles, $element_styles, $border_radii);
      }
    }
  }

  private function _append_figma_text_content($text, array $settings, array &$texts, array &$text_styles): void {
    if ($text === null || $text === '') return;

    $clean_text = wp_strip_all_tags((string) $text);
    if ($clean_text === '') return;

    $texts[] = $clean_text;

    $style = $this->_extract_figma_text_style($settings);
    $style['text'] = $clean_text;
    $text_styles[] = $style;
  }

  private function _extract_figma_text_style(array $settings): array {
    $typography = (!empty($settings['_typography']) && is_array($settings['_typography']))
      ? $settings['_typography']
      : [];

    $style = [];

    $color = $this->_extract_style_color($typography['color'] ?? ($settings['color'] ?? null));
    if ($color !== '') $style['color'] = $color;

    $radius_map = $this->_extract_border_radius_map($settings);
    if (!empty($radius_map['borderRadius'])) {
      $style['borderRadius'] = $radius_map['borderRadius'];
      $style['_borderRadius'] = $radius_map['borderRadius'];
    }

    $map = [
      'font-size'   => 'fontSize',
      'font-family' => 'fontFamily',
      'font-weight' => 'fontWeight',
      'line-height' => 'lineHeight',
      'text-align'  => 'textAlign',
    ];

    foreach ($map as $source_key => $export_key) {
      if (isset($typography[$source_key]) && $typography[$source_key] !== '') {
        $style[$export_key] = (string) $typography[$source_key];
      }
    }

    return $style;
  }

  private function _extract_style_color($color): string {
    if (is_string($color)) return $color;

    if (is_array($color)) {
      foreach (['raw', 'hex', 'color', 'value'] as $key) {
        if (!empty($color[$key]) && is_string($color[$key])) {
          return $color[$key];
        }
      }
    }

    return '';
  }

  private function _normalise_border_radius_settings(array &$elements): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;

      if (!empty($el['settings']) && is_array($el['settings'])) {
        $radius_map = $this->_extract_border_radius_map($el['settings']);
        if (!empty($radius_map['borderRadius'])) {
          $el['settings']['_borderRadius'] = $radius_map['borderRadius'];
          $el['settings']['borderRadius'] = $radius_map['borderRadius'];
        }
      }

      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_normalise_border_radius_settings($el['children']);
      }
    }
    unset($el);
  }

  private function _append_patch_border_radius_content(array $patch_data, array &$element_styles, array &$border_radii): void {
    foreach ($patch_data as $op) {
      if (!is_array($op)) continue;
      if (($op['type'] ?? '') !== 'css' || ($op['prop'] ?? '') !== 'border-radius') continue;

      $value = $this->_normalise_border_radius_value($op['value'] ?? '');
      if ($value === '') continue;

      $selector = (string)($op['selector'] ?? '');
      $element_styles[] = [
        'selector'      => $selector,
        'source'        => 'patch',
        'borderRadius'  => $value,
        '_borderRadius' => $value,
      ];
      $border_radii[] = [
        'selector' => $selector,
        'source'   => 'patch',
        'prop'     => 'borderRadius',
        'value'    => $value,
      ];
    }
  }

  private function _extract_border_radius_map(array $settings): array {
    $radii = [];
    $direct_radius = '';

    foreach (['_borderRadius', 'borderRadius', 'border-radius'] as $key) {
      if (array_key_exists($key, $settings)) {
        $direct_radius = $this->_normalise_border_radius_value($settings[$key]);
        if ($direct_radius !== '') break;
      }
    }

    if ($direct_radius === '' && isset($settings['_border']['radius'])) {
      $direct_radius = $this->_normalise_border_radius_value($settings['_border']['radius']);
    }

    if ($direct_radius !== '') {
      $radii['borderRadius'] = $direct_radius;
      $radii['_borderRadius'] = $direct_radius;
    }

    foreach ($settings as $key => $value) {
      if (!is_array($value) || $key === '_border') continue;
      if (!array_key_exists('radius', $value)) continue;

      $radius = $this->_normalise_border_radius_value($value['radius']);
      if ($radius === '') continue;

      $prop = $this->_border_radius_prop_name((string)$key);
      if ($prop === '_borderRadius') continue;
      $radii[$prop] = $radius;
    }

    return $radii;
  }

  private function _border_radius_prop_name(string $key): string {
    $key = trim($key);
    if ($key === '' || $key === 'border') return 'borderRadius';

    $parts = preg_split('/[^a-zA-Z0-9]+/', $key);
    $parts = array_values(array_filter($parts, static function ($part) {
      return $part !== '';
    }));
    if (empty($parts)) return 'borderRadius';

    $camel = array_shift($parts);
    foreach ($parts as $part) {
      $camel .= ucfirst($part);
    }

    return $camel . 'Radius';
  }

  private function _normalise_border_radius_value($value): string {
    if (is_int($value) || is_float($value)) {
      $number = is_int($value)
        ? (string)$value
        : rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
      return $number . 'px';
    }

    if (is_string($value)) {
      $value = trim($value);
      if ($value === '') return '';
      return is_numeric($value) ? $value . 'px' : $value;
    }

    if (!is_array($value)) return '';

    foreach (['raw', 'value', 'css'] as $key) {
      if (array_key_exists($key, $value)) {
        $normalised = $this->_normalise_border_radius_value($value[$key]);
        if ($normalised !== '') return $normalised;
      }
    }

    $ordered_keys = ['top', 'right', 'bottom', 'left'];
    $values = [];
    foreach ($ordered_keys as $key) {
      $values[] = array_key_exists($key, $value)
        ? $this->_normalise_border_radius_value($value[$key])
        : '';
    }

    if (implode('', $values) === '') {
      $corner_keys = ['topLeft', 'topRight', 'bottomRight', 'bottomLeft'];
      $values = [];
      foreach ($corner_keys as $key) {
        $values[] = array_key_exists($key, $value)
          ? $this->_normalise_border_radius_value($value[$key])
          : '';
      }
    }

    if (implode('', $values) === '') return '';

    $fallback = '';
    foreach ($values as $radius) {
      if ($radius !== '') {
        $fallback = $radius;
        break;
      }
    }
    if ($fallback === '') return '';

    $values = array_map(static function ($radius) use ($fallback) {
      return $radius !== '' ? $radius : $fallback;
    }, $values);

    if ($values[0] === $values[1] && $values[0] === $values[2] && $values[0] === $values[3]) {
      return $values[0];
    }
    if ($values[0] === $values[2] && $values[1] === $values[3]) {
      return $values[0] . ' ' . $values[1];
    }
    if ($values[1] === $values[3]) {
      return $values[0] . ' ' . $values[1] . ' ' . $values[2];
    }

    return implode(' ', $values);
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
    $self = new self();
    $guide_raw = $project_id ? (string) get_post_meta($project_id, 'aisb_style_guide', true) : '';
    // Convert logoUrl to inline data URI so the design canvas can display
    // the logo without relying on the local WordPress URL being accessible.
    if ($guide_raw) {
      $guide_arr = json_decode($guide_raw, true);
      if (is_array($guide_arr) && !empty($guide_arr['logoUrl'])
          && strpos($guide_arr['logoUrl'], 'data:') !== 0) {
        $guide_arr['logoUrl'] = $self->_url_to_data_uri($guide_arr['logoUrl']);
        $guide_raw = wp_json_encode($guide_arr, JSON_UNESCAPED_SLASHES);
      }
    }    ?>
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
}
