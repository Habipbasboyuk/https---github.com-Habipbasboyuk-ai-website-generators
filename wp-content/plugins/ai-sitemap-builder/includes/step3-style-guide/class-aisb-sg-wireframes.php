<?php

if (!defined('ABSPATH')) exit;

/**
 * AJAX-handler voor wireframe-secties en bijbehorende analyse-helpers.
 */
class AISB_SG_Wireframes {

  /**
   * AJAX: Haal wireframe-secties op voor de live preview.
   * Gebruikt project_id → aisb_latest_sitemap_id → aisb_wireframes tabel.
   */
  public function ajax_get_wireframe_sections(): void {
    $this->require_login();
    $this->check_nonce();

    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    $sitemap_id = (int) get_post_meta($project_id, 'aisb_latest_sitemap_id', true);
    if (!$sitemap_id) {
      wp_send_json_success(['sections' => []]);
    }

    $sitemap_json = get_post_meta($sitemap_id, 'aisb_sitemap_json', true);
    $sitemap_data = $sitemap_json ? json_decode((string)$sitemap_json, true) : [];

    $page_slugs   = [];
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

    $result_pages = [];
    $total_media  = 0;
    foreach ($page_slugs as $slug) {
      $model = $models_by_slug[$slug] ?? null;
      if (!$model || empty($model['sections'])) continue;
      $sections = [];
      foreach ($model['sections'] as $s) {
        $ai_id   = !empty($s['ai_wireframe_id'])    ? (int) $s['ai_wireframe_id']    : 0;
        $tmpl_id = !empty($s['bricks_template_id']) ? (int) $s['bricks_template_id'] : 0;

        $post_id_for_schema = $ai_id ?: $tmpl_id;
        $media_count = 0;
        if ($post_id_for_schema) {
          $bricks_elements = [];
          foreach (['_bricks_page_content_2', '_bricks_data', '_bricks_page_header_2', '_bricks_page_footer_2'] as $meta_key) {
            $raw = get_post_meta($post_id_for_schema, $meta_key, true);
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
          if (!empty($bricks_elements)) {
            $media_count = $this->count_bricks_image_elements($bricks_elements);
          } else {
            $schema = $this->extract_content_schema($post_id_for_schema, $s['type'] ?? 'generic');
            if ($schema && !empty($schema['elements'])) {
              foreach ($schema['elements'] as $el) {
                if (($el['tag'] ?? '') === 'media') $media_count++;
              }
            }
          }
        }
        // Hard cap per section: real sections rarely need more than 16 unique stock photos
        $media_count = min($media_count, 16);
        $total_media += $media_count;

        $patch_raw  = $ai_id ? (string) get_post_meta($ai_id, '_aisb_design_patch', true) : '';
        $patch_data = ($patch_raw !== '') ? json_decode($patch_raw, true) : [];
        if (!is_array($patch_data)) $patch_data = [];

        $sections[] = [
          'type'               => $s['type'] ?? 'generic',
          'uuid'               => $s['uuid'] ?? '',
          'ai_wireframe_id'    => $ai_id,
          'bricks_template_id' => $tmpl_id,
          'layout_key'         => $s['layout_key'] ?? '',
          'media_count'        => $media_count,
          'patch'              => $patch_data,
          'bg_index'           => isset($s['bg_index']) ? (int) $s['bg_index'] : null,
        ];
      }
      $result_pages[] = [
        'slug'               => $slug,
        'title'              => $model['page']['title'] ?? ucfirst(str_replace('-', ' ', $slug)),
        'sitemap_version_id' => $sitemap_id,
        'sections'           => $sections,
      ];
    }

    wp_send_json_success(['pages' => $result_pages, 'total_media' => $total_media]);
  }

  /* ------------------- Analyse-helpers ------------------- */

  /**
   * Recursively count Bricks 'image' elements in an elements array.
   * Includes both 'image' elements and 'image-gallery' element items.
   */
  private function count_bricks_image_elements(array $elements): int {
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
        $count += $this->count_bricks_image_elements($el['children']);
      }
    }
    return $count;
  }

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

      if (in_array($name, ['heading', 'post-title'], true) || !empty($s['tag']) && in_array($s['tag'] ?? '', ['h1','h2','h3','h4'])) {
        $txt = $this->first_text($s, ['text', 'title', 'heading', 'content']);
        if ($txt) {
          $tag_val = $s['tag'] ?? ($section_type === 'hero' ? 'h1' : 'h2');
          $schema_elements[] = ['tag' => $tag_val, 'text' => $txt];
        }
        continue;
      }

      if (in_array($name, ['text', 'text-basic', 'rich-text', 'post-excerpt', 'post-content'], true)) {
        $txt = $this->first_text($s, ['text', 'content', 'description']);
        if ($txt) {
          $txt = wp_strip_all_tags($txt);
          if (mb_strlen($txt) > 200) $txt = mb_substr($txt, 0, 200) . '…';
          $schema_elements[] = ['tag' => 'p', 'text' => $txt];
        }
        continue;
      }

      if (in_array($name, ['button', 'icon-button'], true)) {
        $txt = $this->first_text($s, ['text', 'label', 'buttonText', 'link_text']);
        if ($txt) {
          $schema_elements[] = ['tag' => 'button', 'text' => $txt];
        }
        continue;
      }

      if (in_array($name, ['image', 'video', 'photo', 'media', 'post-image', 'featured-image'], true)) {
        $schema_elements[] = ['tag' => 'media', 'text' => ucfirst($name)];
        continue;
      }
      if (in_array($name, ['image-gallery', 'image-slider', 'image-carousel'], true)) {
        $stored    = !empty($s['images']) && is_array($s['images']) ? count($s['images']) : 1;
        $img_count = min(max(1, $stored), 6);
        for ($gi = 0; $gi < $img_count; $gi++) {
          $schema_elements[] = ['tag' => 'media', 'text' => 'Gallery image'];
        }
        continue;
      }

      $txt = $this->first_text($s, $text_keys);
      if ($txt && mb_strlen(wp_strip_all_tags($txt)) > 5) {
        $clean = wp_strip_all_tags($txt);
        if (mb_strlen($clean) > 200) $clean = mb_substr($clean, 0, 200) . '…';
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

  private function first_text(array $settings, array $keys): string {
    foreach ($keys as $k) {
      if (!empty($settings[$k]) && is_string($settings[$k])) {
        return trim($settings[$k]);
      }
    }
    return '';
  }

  /* ------------------- Auth helpers ------------------- */

  private function require_login(): void {
    if (!is_user_logged_in()) {
      wp_send_json_error(['message' => 'Not logged in'], 401);
    }
  }

  private function check_nonce(): void {
    $nonce   = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $ok_sg   = $nonce && wp_verify_nonce($nonce, 'aisb_sg_nonce');
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
}
