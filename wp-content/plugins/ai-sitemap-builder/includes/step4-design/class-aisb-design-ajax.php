<?php

if (!defined('ABSPATH')) exit;

/**
 * AJAX-handlers voor stap 4: sectie-volgorde, templates en design-patches.
 */
class AISB_Design_Ajax {

  /**
   * AJAX: Wijzig de volgorde van secties binnen een pagina.
   */
  public function ajax_reorder_sections(): void {
    $this->require_login();
    $this->check_nonce();

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

    $this->assert_project_ownership($project_id);

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
   */
  public function ajax_list_templates(): void {
    $this->require_login();
    $this->check_nonce();

    $type   = isset($_POST['type'])   ? sanitize_key(wp_unslash($_POST['type']))           : '';
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search']))  : '';

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
    if ($search) $args['s'] = $search;
    $posts = get_posts($args);

    $out = [];
    foreach ($posts as $post) {
      $id    = (int) $post->ID;
      $title = (string) $post->post_title;
      if (strpos($title, '[AI]') === 0) continue;

      $tags_raw  = get_the_terms($id, 'template_tag');
      $tags      = (!empty($tags_raw) && !is_wp_error($tags_raw)) ? wp_list_pluck($tags_raw, 'slug') : [];
      $ttype     = (string)(get_post_meta($id, '_bricks_template_type', true) ?: '');
      $type_keys = array_map('strtolower', $tags);
      if (empty($type_keys) && $ttype !== '') $type_keys = [strtolower($ttype)];

      if ($type && !in_array($type, $type_keys, true)) continue;

      $out[] = ['id' => $id, 'title' => $title, 'tags' => $type_keys, 'ttype' => $ttype];
    }

    wp_send_json_success(['templates' => $out]);
  }

  /**
   * AJAX: Vervang een sectie in het wireframe model door een specifiek Bricks-template
   * en vul het daarna via AI zodat het eigen tekst krijgt.
   */
  public function ajax_design_replace_section(): void {
    $this->require_login();
    $this->check_nonce();

    set_time_limit(180);
    ignore_user_abort(true);

    $project_id         = isset($_POST['project_id'])         ? (int) $_POST['project_id']                                : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int) $_POST['sitemap_version_id']                        : 0;
    $page_slug          = isset($_POST['page_slug'])          ? sanitize_title(wp_unslash($_POST['page_slug']))           : '';
    $uuid               = isset($_POST['uuid'])               ? sanitize_text_field(wp_unslash($_POST['uuid']))            : '';
    $bricks_template_id = isset($_POST['bricks_template_id']) ? (int) $_POST['bricks_template_id']                        : 0;

    if (!$project_id || !$sitemap_version_id || !$page_slug || !$uuid || !$bricks_template_id) {
      wp_send_json_error(['message' => 'Missing params'], 400);
    }

    $this->assert_project_ownership($project_id);

    error_log('[AISB] ajax_design_replace_section: project=' . $project_id . ' uuid=' . $uuid . ' tpl=' . $bricks_template_id . ' page=' . $page_slug);

    global $wpdb;
    $table = $wpdb->prefix . 'aisb_wireframes';
    $row   = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug=%s",
      $project_id, $sitemap_version_id, $page_slug
    ), ARRAY_A);
    if (!$row) wp_send_json_error(['message' => 'Wireframe row not found'], 404);

    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) wp_send_json_error(['message' => 'Invalid wireframe model'], 500);

    $tpl_post = get_post($bricks_template_id);
    if (!$tpl_post || $tpl_post->post_type !== 'bricks_template') {
      wp_send_json_error(['message' => 'Bricks template not found'], 404);
    }
    $tags_raw = get_the_terms($bricks_template_id, 'template_tag');
    $tags     = (!empty($tags_raw) && !is_wp_error($tags_raw)) ? wp_list_pluck($tags_raw, 'slug') : [];
    $ttype    = (string)(get_post_meta($bricks_template_id, '_bricks_template_type', true) ?: '');

    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s) || ($s['uuid'] ?? '') !== $uuid) continue;

      $old_ai = isset($s['ai_wireframe_id']) ? (int) $s['ai_wireframe_id'] : 0;
      if ($old_ai > 0) wp_delete_post($old_ai, true);

      $model['sections'][$i]['bricks_template_id']    = $bricks_template_id;
      $model['sections'][$i]['bricks_template_title'] = $tpl_post->post_title;
      $model['sections'][$i]['bricks_template_ttype'] = $ttype;
      $model['sections'][$i]['layout_key']            = 'bricks_' . $bricks_template_id;
      $model['sections'][$i]['match_tags']            = implode(', ', $tags);
      $model['sections'][$i]['preview_schema']        = null;
      $model['sections'][$i]['ai_wireframe_id']       = null;
      break;
    }

    $wpdb->update($table,
      ['model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES), 'compiled_bricks_json' => null, 'updated_at' => current_time('mysql')],
      ['project_id' => $project_id, 'sitemap_version_id' => $sitemap_version_id, 'page_slug' => $page_slug],
      ['%s', '%s', '%s'], ['%d', '%d', '%s']
    );

    $ai    = new AISB_Wireframes_AI();
    $model = $ai->populate_bricks_content_with_ai($model, $project_id, $sitemap_version_id, $page_slug, [$uuid]);

    $wpdb->update($table,
      ['model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES), 'updated_at' => current_time('mysql')],
      ['project_id' => $project_id, 'sitemap_version_id' => $sitemap_version_id, 'page_slug' => $page_slug],
      ['%s', '%s'], ['%d', '%d', '%s']
    );

    $new_ai_wireframe_id = 0;
    foreach (($model['sections'] ?? []) as $s) {
      if (!is_array($s) || ($s['uuid'] ?? '') !== $uuid) continue;
      $new_ai_wireframe_id = (int)($s['ai_wireframe_id'] ?? 0);
      break;
    }

    wp_send_json_success([
      'ai_wireframe_id'    => $new_ai_wireframe_id,
      'bricks_template_id' => $bricks_template_id,
    ]);
  }

  /**
   * AJAX: Sla design-patches op voor een of meerdere ai_wireframe posts.
   */
  public function ajax_save_design_patch(): void {
    $this->require_login();
    $this->check_nonce();

    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    if (!$project_id) wp_send_json_error(['message' => 'Missing project_id'], 400);

    $this->assert_project_ownership($project_id);

    $patches_raw = isset($_POST['patches']) ? wp_unslash($_POST['patches']) : '[]';
    $patches     = json_decode($patches_raw, true);
    if (!is_array($patches)) wp_send_json_error(['message' => 'Invalid patches JSON'], 400);

    $saved = 0;
    foreach ($patches as $item) {
      $post_id = isset($item['post_id']) ? (int) $item['post_id'] : 0;
      $patch   = isset($item['patch']) && is_array($item['patch']) ? $item['patch'] : [];
      if (!$post_id) continue;

      $p = get_post($post_id);
      if (!$p || $p->post_type !== 'ai_wireframe') continue;

      $clean = [];
      foreach ($patch as $op) {
        if (!isset($op['type'])) continue;
        $type  = sanitize_key($op['type']);
        $entry = ['type' => $type];
        if (isset($op['selector'])) $entry['selector'] = sanitize_text_field($op['selector']);
        if ($type === 'text') $entry['text'] = wp_kses_post($op['text'] ?? '');
        if ($type === 'css') {
          $entry['prop']  = sanitize_key($op['prop'] ?? '');
          $entry['value'] = sanitize_text_field($op['value'] ?? '');
          // Behoud de 'cascade' marker zodat applyPatch na refresh de bg-kleur ook
          // over nested divs verspreidt.
          if (isset($op['cascade'])) $entry['cascade'] = sanitize_key($op['cascade']);
        }
        if ($type === 'img') {
          $src = $op['src'] ?? '';
          if (is_array($src)) $src = (string)($src['url'] ?? $src['src'] ?? $src['full'] ?? '');
          $src = (string)$src;
          $entry['src'] = (strpos($src, 'data:image') === 0) ? $src : esc_url_raw($src);
        }
        if ($type === 'mirror') $entry['mirrored'] = (bool)($op['mirrored'] ?? false);
        $clean[] = $entry;
      }

      update_post_meta($post_id, '_aisb_design_patch', wp_json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      $saved++;
    }

    wp_send_json_success(['saved' => $saved]);
  }

  /**
   * AJAX: Voeg een nieuwe sectie toe na een bestaande sectie (op basis van uuid).
   */
  public function ajax_insert_section(): void {
    $this->require_login();
    $this->check_nonce();

    set_time_limit(180);
    ignore_user_abort(true);

    $project_id         = isset($_POST['project_id'])         ? (int) $_POST['project_id']                                : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int) $_POST['sitemap_version_id']                        : 0;
    $page_slug          = isset($_POST['page_slug'])          ? sanitize_title(wp_unslash($_POST['page_slug']))           : '';
    $after_uuid         = isset($_POST['after_uuid'])         ? sanitize_text_field(wp_unslash($_POST['after_uuid']))     : '';
    $bricks_template_id = isset($_POST['bricks_template_id']) ? (int) $_POST['bricks_template_id']                        : 0;

    if (!$project_id || !$sitemap_version_id || !$page_slug || !$bricks_template_id) {
      wp_send_json_error(['message' => 'Missing params'], 400);
    }

    $this->assert_project_ownership($project_id);

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

    global $wpdb;
    $table = $wpdb->prefix . 'aisb_wireframes';
    $row   = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug=%s",
      $project_id, $sitemap_version_id, $page_slug
    ), ARRAY_A);
    if (!$row) wp_send_json_error(['message' => 'Wireframe not found'], 404);

    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) wp_send_json_error(['message' => 'Invalid model'], 500);

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

    $sections  = $model['sections'] ?? [];
    $insert_at = count($sections);
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

    $wpdb->update($table,
      ['model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES), 'compiled_bricks_json' => null, 'updated_at' => current_time('mysql')],
      ['project_id' => $project_id, 'sitemap_version_id' => $sitemap_version_id, 'page_slug' => $page_slug],
      ['%s', '%s', '%s'], ['%d', '%d', '%s']
    );

    $ai    = new AISB_Wireframes_AI();
    $model = $ai->populate_bricks_content_with_ai($model, $project_id, $sitemap_version_id, $page_slug, [$new_uuid]);

    $wpdb->update($table,
      ['model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES), 'updated_at' => current_time('mysql')],
      ['project_id' => $project_id, 'sitemap_version_id' => $sitemap_version_id, 'page_slug' => $page_slug],
      ['%s', '%s'], ['%d', '%d', '%s']
    );

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

  /* ------------------- Auth helpers ------------------- */

  private function require_login(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
  }

  private function check_nonce(): void {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'aisb_sg_nonce')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }
  }

  private function assert_project_ownership(int $project_id): void {
    $project = get_post($project_id);
    if (!$project || $project->post_type !== 'aisb_project' || (int)$project->post_author !== (int)get_current_user_id()) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }
  }
}
